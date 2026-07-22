<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Views\Twig;
use AfricaGates\Services\{PaymentService, OtpService, Notifier, RateLimitService, WebhookService, ShopPricing, PointsService};
use AfricaGates\Admin\Controllers\ProductsController;

/**
 * Shop checkout — same security model as PaymentController, applied to orders:
 *   1. The cart total is recomputed SERVER-SIDE from gates_products ({@see priceCart}).
 *      Client-sent prices/names are ignored; a tampered cart can never set the price.
 *   2. A PENDING gates_orders row is written before leaving for the gateway.
 *   3. Confirmation requires verify()=success AND the verified amount equalling the
 *      order's subtotal, then an idempotent pending→paid transition (stock is only
 *      decremented, and the receipt only sent, by the single winning transition).
 */
final class ShopCheckoutController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly Twig $view,
        private readonly ?OtpService $mailer = null,
        private readonly ?LoggerInterface $log = null,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    private function base(): string { return rtrim((string)($_ENV['APP_URL'] ?? ''), '/'); }
    private function redirect(Response $res, string $url): Response { return $res->withHeader('Location', $url)->withStatus(302); }

    /**
     * Authoritative cart pricing — the ONLY place a shop total is decided. Unknown
     * or inactive slugs are dropped; quantities are clamped 1..20.
     * Public so it can be unit-tested directly.
     *
     * @param array<string,array{qty?:mixed}> $clientItems  cart keyed by slug
     * @return array{lines:array<int,array<string,mixed>>,subtotal:int,count:int}
     */
    public static function priceCart(array $clientItems, string $region = ''): array
    {
        $mults = ShopPricing::multipliers();          // region => multiplier (empty when none set)
        $lines = []; $subtotal = 0;
        foreach ($clientItems as $slug => $row) {
            $slug = (string)$slug;
            $qty  = (int)(is_array($row) ? ($row['qty'] ?? 0) : 0);
            if ($qty < 1) continue;
            $qty = min(20, $qty);
            $p = DB::table('gates_products')->where('slug', $slug)->where('is_active', 1)->first();
            if (!$p) continue;
            $base  = (int)$p->price_naira;
            // Location-based pricing: the charged price is the base scaled by the
            // delivery region's multiplier (unset / 1.0 → base). Server-authoritative.
            $price = ShopPricing::adjust($base, $region, $mults);
            $lines[] = [
                'slug' => $slug, 'name' => $p->name, 'price_naira' => $price, 'base_naira' => $base,
                'qty' => $qty, 'line_total' => $price * $qty,
                'delivery_regions' => !empty($p->delivery_regions) ? (json_decode((string)$p->delivery_regions, true) ?: []) : [],
            ];
            $subtotal += $price * $qty;
        }
        return ['lines' => $lines, 'subtotal' => $subtotal, 'count' => (int)array_sum(array_column($lines, 'qty')), 'region' => $region];
    }

    /** POST /shop/checkout — first-party, CSRF-protected. */
    public function checkout(Request $req, Response $res): Response
    {
        $b        = (array)$req->getParsedBody();
        $provider = strtolower(trim((string)($b['provider'] ?? '')));
        $email    = strtolower(trim((string)($b['email'] ?? '')));
        $name     = trim((string)($b['name'] ?? ''));
        $phone    = trim((string)($b['phone'] ?? ''));
        $address  = trim((string)($b['address'] ?? ''));
        $region   = trim((string)($b['region'] ?? ''));
        $ip       = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');

        $bail = fn(string $why) => $this->redirect($res, $this->base() . '/shop?checkout=' . urlencode($why));

        // Abuse control: cap pending-order + gateway churn per IP (prices are
        // server-authoritative, so this limits noise/table-growth, not fraud).
        if ($this->rateLimit && $ip !== '' && !$this->rateLimit->check(hash('sha256', $ip . '|shop-checkout'), 'shop_checkout', 12, 3600)) {
            return $bail('busy');
        }

        $cart = json_decode((string)($b['cart'] ?? '[]'), true);
        if (!is_array($cart)) return $bail('empty');

        // Delivery region — required + recognised. Validated BEFORE pricing because
        // it now drives location-based pricing as well as the ship-to check. Checked
        // before the payment gateway so delivery issues surface first.
        // Server-authoritative — a tampered cart/region can never set the price.
        if ($region === '' || !in_array($region, ProductsController::REGIONS, true)) {
            return $bail('region');
        }
        $priced = self::priceCart($cart, $region);
        if ($priced['count'] < 1 || $priced['subtotal'] < 1) return $bail('empty');

        // A region-restricted product can't ship outside its delivery regions.
        foreach ($priced['lines'] as $l) {
            $allowed = $l['delivery_regions'] ?? [];
            if (!empty($allowed) && !in_array($region, $allowed, true)) {
                return $bail('noship');
            }
        }

        if (!$this->payments->isEnabled($provider)) return $bail('unavailable');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return $bail('email');
        if ($name === '' || $address === '') return $bail('details');

        $reference = 'AFG-SHP-' . bin2hex(random_bytes(6));
        try {
            DB::table('gates_orders')->insert([
                'reference'      => $reference,
                'email'          => $email,
                'name'           => $name,
                'phone'          => $phone !== '' ? $phone : null,
                'address'        => 'Region: ' . $region . "\n" . $address,
                'items_json'     => json_encode($priced['lines'], JSON_UNESCAPED_UNICODE),
                'subtotal_naira' => $priced['subtotal'],
                'status'         => 'pending',
                'provider'       => $provider,
                'ip_hash'        => $ip ? hash('sha256', $ip) : null,
                'created_at'     => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            $this->log?->error('[shop] could not persist order', ['err' => $e->getMessage()]);
            return $bail('error');
        }

        $callbackUrl = $this->base() . '/shop/callback?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);
        $init = $this->payments->initialize($provider, $priced['subtotal'], $email, $reference, $callbackUrl, [
            'reference' => $reference, 'purpose' => 'shop', 'items' => $priced['count'],
        ]);

        if (!$init['ok'] || empty($init['checkout_url'])) {
            DB::table('gates_orders')->where('reference', $reference)->where('status', 'pending')->update(['status' => 'failed']);
            return $bail('start');
        }
        return $this->redirect($res, $init['checkout_url']);
    }

    /** GET /shop/callback — browser return; re-verified server-to-server. */
    public function callback(Request $req, Response $res): Response
    {
        $q         = $req->getQueryParams();
        $reference = trim((string)($q['ref'] ?? $q['reference'] ?? $q['tx_ref'] ?? ''));
        $provider  = strtolower(trim((string)($q['provider'] ?? '')));
        if ($reference === '' || !$this->payments->isKnownProvider($provider)) {
            return $this->redirect($res, $this->base() . '/shop?checkout=error');
        }
        $order = DB::table('gates_orders')->where('reference', $reference)->first();
        if (!$order) return $this->redirect($res, $this->base() . '/shop?checkout=error');

        $result = $this->confirmByReference($provider, $reference, $order);
        if ($result === 'confirmed' || $result === 'already') {
            return $this->redirect($res, $this->base() . '/shop/success?ref=' . urlencode($reference));
        }
        return $this->redirect($res, $this->base() . '/shop?checkout=failed');
    }

    /** GET /shop/success — read-only confirmation. */
    public function success(Request $req, Response $res): Response
    {
        $reference = trim((string)($req->getQueryParams()['ref'] ?? ''));
        $order = $reference !== ''
            ? DB::table('gates_orders')->where('reference', $reference)->where('status', 'paid')->first()
            : null;
        return $this->view->render($res, 'pages/shop/success.twig', [
            'page_title'       => 'Order Confirmed — Africa GATES',
            'meta_description' => 'Thank you — your Africa GATES shop order is confirmed.',
            'gates_page'       => 'shop',
            'has_hero'         => false,
            'confirmed'        => $order !== null,
            'reference'        => $reference,
            'subtotal_naira'   => $order ? (int)$order->subtotal_naira : 0,
            'items'            => $order ? (json_decode((string)$order->items_json, true) ?: []) : [],
        ]);
    }

    /** Shared, idempotent confirmation (mirrors PaymentController). */
    private function confirmByReference(string $provider, string $reference, object $order): string
    {
        if (($order->status ?? '') === 'paid') return 'already';

        $v = $this->payments->verify($provider, $reference);
        if (!$v['ok'] || ($v['status'] ?? '') !== 'success') {
            if (($v['status'] ?? '') === 'failed') {
                DB::table('gates_orders')->where('reference', $reference)->where('status', 'pending')->update(['status' => 'failed']);
            }
            return 'failed';
        }
        if ((int)$v['amount'] !== (int)$order->subtotal_naira) {
            $this->log?->warning('[shop] amount mismatch — refusing', ['ref' => $reference]);
            return 'failed';
        }

        $changed = DB::table('gates_orders')->where('reference', $reference)->where('status', 'pending')
            ->update(['status' => 'paid', 'paid_at' => Carbon::now()->toDateTimeString(), 'provider_ref' => $reference]);

        if ($changed > 0) {
            $this->fulfil($order);
            return 'confirmed';
        }
        return 'already';
    }

    /** One-time side effects of a confirmed order: decrement tracked stock + receipts. */
    private function fulfil(object $order): void
    {
        $lines = json_decode((string)$order->items_json, true) ?: [];
        foreach ($lines as $l) {
            $slug = (string)($l['slug'] ?? ''); $qty = (int)($l['qty'] ?? 0);
            if ($slug === '' || $qty < 1) continue;
            // Decrement only tracked stock (NULL = untracked), never below zero.
            // Two bound queries instead of a string-built raw CASE: drop by qty where
            // there's enough, then floor the rest at zero. No request data — and now
            // no integer — is ever concatenated into SQL.
            DB::table('gates_products')->where('slug', $slug)->whereNotNull('stock')
                ->where('stock', '>=', $qty)->decrement('stock', $qty);
            DB::table('gates_products')->where('slug', $slug)->whereNotNull('stock')
                ->where('stock', '<', $qty)->update(['stock' => 0]);
        }

        $summary = implode("\n", array_map(
            fn($l) => '  ' . ($l['name'] ?? '?') . ' ×' . ($l['qty'] ?? 0) . ' — ₦' . number_format((int)($l['line_total'] ?? 0)),
            $lines
        ));
        $total = '₦' . number_format((int)$order->subtotal_naira);

        if ($this->mailer) {
            try {
                $this->mailer->sendBranded(
                    (string)$order->email,
                    'Your Africa GATES order is confirmed',
                    "<p>Thank you, " . htmlspecialchars((string)$order->name) . " — your order is confirmed and being prepared.</p>"
                    . "<p style=\"font-family:monospace\">Order " . htmlspecialchars((string)$order->reference) . "</p>"
                    . "<p>Total paid: <strong>{$total}</strong>. Every purchase funds child leadership programmes — thank you.</p>",
                    'Shop'
                );
            } catch (\Throwable $e) { /* receipt failure must not break confirmation */ }
        }
        Notifier::adminAlert($this->mailer, 'New shop order (paid)',
            "Order:   {$order->reference}\nBy:      {$order->name} <{$order->email}> · " . (string)($order->phone ?? '')
            . "\nShip to: " . (string)$order->address . "\nTotal:   {$total}\n\nItems:\n{$summary}");

        // Notify subscribed integrations / AI agents that an order was paid.
        WebhookService::dispatch('order.paid', [
            'reference'      => (string) $order->reference,
            'subtotal_naira' => (int) $order->subtotal_naira,
            'email'          => (string) $order->email,
            'items'          => $lines,
        ]);

        // Award voting points to the member who placed the order (matched by email;
        // idempotent per reference). No-op when points are disabled or no account matches.
        if (PointsService::enabled()) {
            $uid = (int) (DB::table('gates_users')->where('email', strtolower((string) $order->email))->where('status', 'active')->value('id') ?? 0);
            if ($uid > 0) {
                PointsService::earnFromPurchase($uid, (int) $order->subtotal_naira, 'shop_order', (string) $order->reference);
            }
        }
    }
}
