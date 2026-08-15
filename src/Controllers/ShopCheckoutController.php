<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Views\Twig;
use AfricaGates\Services\{PaymentService, OtpService, Notifier, RateLimitService, WebhookService,
                         ShopPricing, ShopCatalogue, ShopDiscount, ShopShipping, PointsService};
use AfricaGates\Support\OptionalColumn;
use AfricaGates\Admin\Controllers\ProductsController;

/**
 * Shop checkout — same security model as PaymentController, applied to orders:
 *   1. The cart total is recomputed SERVER-SIDE from gates_products ({@see priceCart}).
 *      Client-sent prices/names are ignored; a tampered cart can never set the price.
 *   2. A PENDING gates_orders row is written before leaving for the gateway.
 *   3. Confirmation requires verify()=success AND a verified amount that is at least the
 *      order's subtotal, in naira, then an idempotent pending→paid transition (stock is only
 *      decremented, and the receipt only sent, by the single winning transition).
 *
 * Step 3 lives in {@see \AfricaGates\Services\ShopOrderService} rather than here, because the
 * gateway webhook and the reconciliation sweep have to reach exactly the same code — see that
 * file for what happened while they could not.
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

    /**
     * Absolute site base. Via SiteUrl, which falls back to the REQUEST when APP_URL is
     * unset — this used to return '' and every gateway callback URL built from it was
     * relative, which a payment provider cannot redirect a browser to. See SiteUrl.
     */
    private function base(?Request $req = null): string
    {
        return \AfricaGates\Support\SiteUrl::base($req);
    }
    private function redirect(Response $res, string $url): Response { return $res->withHeader('Location', $url)->withStatus(302); }

    /**
     * Authoritative cart pricing — the ONLY place a shop total is decided.
     *
     * ── WHAT THE CART KEY IS, AND WHY IT CHANGED ─────────────────────────────
     *
     * It used to be the product slug. That cannot hold a size: a shirt in M and the same shirt
     * in XL are one cart line under a slug, so a buyer could only ever order one variant of
     * anything, and the second choice silently overwrote the first. The key is now
     * `slug|variantId` — still a plain string the browser can build, still resolved entirely
     * server-side. A bare slug is still accepted, because a product with no variants has
     * nothing to disambiguate and because a cart saved in somebody's localStorage before this
     * shipped must not become unreadable.
     *
     * ── WHAT IS REFUSED HERE ─────────────────────────────────────────────────
     *
     * Unknown or inactive slugs are dropped. A variant that does not belong to its product is
     * dropped, since that pairing arrives from a form. And quantities are clamped to what is
     * actually in stock, which nothing did before: `stock` existed and no code read it, so a
     * sold-out item could be added, paid for, and confirmed — and the confirmation floored the
     * number at zero and said nothing about it.
     *
     * @param array<string,array{qty?:mixed}> $clientItems cart keyed by slug or slug|variantId
     * @return array{lines:array<int,array<string,mixed>>, subtotal:int, count:int,
     *                region:string, adjusted:list<string>}
     */
    public static function priceCart(array $clientItems, string $region = ''): array
    {
        $mults = ShopPricing::multipliers();          // region => multiplier (empty when none set)
        $lines = []; $subtotal = 0; $adjusted = [];

        foreach ($clientItems as $key => $row) {
            $qty = (int) (is_array($row) ? ($row['qty'] ?? 0) : 0);
            if ($qty < 1) continue;
            $qty = min(20, $qty);

            // `slug|variantId`. The variant id is taken from the KEY rather than from the
            // line body, so a cart cannot carry one slug with another product's variant.
            [$slug, $variantId] = self::splitKey((string) $key);
            if ($slug === '') continue;

            $p = DB::table('gates_products')->where('slug', $slug)->where('is_active', 1)->first();
            if (!$p) continue;

            // The variant is verified AGAINST this product — see ShopCatalogue::pick().
            $chosen = ShopCatalogue::pick($p, $variantId);
            if (!$chosen['ok']) { $adjusted[] = (string) $p->name . ': ' . $chosen['message']; continue; }

            // Stock. NULL is untracked and unlimited, which is a legitimate answer.
            $left = ShopCatalogue::available($p, (int) $chosen['variant_id']);
            if ($left !== null) {
                if ($left < 1) {
                    $adjusted[] = self::describe($p, $chosen) . ' is sold out.';
                    continue;
                }
                if ($qty > $left) {
                    // Trimmed rather than refused: somebody who wanted five and can have two
                    // usually wants the two, and being told so is better than an empty cart.
                    $adjusted[] = 'Only ' . $left . ' of ' . self::describe($p, $chosen) . ' left — '
                                . 'your order was reduced to ' . $left . '.';
                    $qty = $left;
                }
            }

            // Location-based pricing: the charged price is the base scaled by the delivery
            // region's multiplier (unset / 1.0 → base). Server-authoritative.
            $base  = (int) $chosen['price'];
            $price = ShopPricing::adjust($base, $region, $mults);

            $lines[] = [
                'key'  => self::key($slug, (int) $chosen['variant_id']),
                'slug' => $slug,
                'product_id' => (int) $p->id,
                'variant_id' => (int) $chosen['variant_id'],
                'variant'    => (string) $chosen['label'],
                'name' => (string) $p->name,
                'category' => (string) ($p->category ?? ''),
                'price_naira' => $price, 'base_naira' => $base,
                'qty' => $qty, 'line_total' => $price * $qty,
                'ships_free' => (int) ($p->ships_free ?? 0) === 1,
                'delivery_regions' => !empty($p->delivery_regions) ? (json_decode((string)$p->delivery_regions, true) ?: []) : [],
            ];
            $subtotal += $price * $qty;
        }

        return ['lines' => $lines, 'subtotal' => $subtotal,
                'count' => (int) array_sum(array_column($lines, 'qty')),
                'region' => $region,
                // What we silently changed about their cart. Returned rather than swallowed:
                // a total that does not match what somebody last saw needs a sentence.
                'adjusted' => $adjusted];
    }

    /** `slug|variantId` → [slug, id]. A bare slug is a product with nothing to choose. */
    private static function splitKey(string $key): array
    {
        if (!str_contains($key, '|')) return [trim($key), 0];
        [$slug, $vid] = explode('|', $key, 2);
        return [trim($slug), max(0, (int) $vid)];
    }

    /** The canonical cart key, so the browser and the server agree on one spelling. */
    public static function key(string $slug, int $variantId): string
    {
        return $variantId > 0 ? $slug . '|' . $variantId : $slug;
    }

    /** 'The Heritage Tee (XL)' — what a message about stock has to name. */
    private static function describe(object $product, array $chosen): string
    {
        $label = trim((string) ($chosen['label'] ?? ''));
        return (string) $product->name . ($label !== '' ? ' (' . $label . ')' : '');
    }

    /**
     * Everything a basket costs: goods, delivery, discount, and what is charged.
     *
     * Kept beside {@see priceCart()} rather than in a service because it is arithmetic over
     * that function's output and nothing else — but it is the ONLY place the charged figure is
     * assembled, so both the preview endpoint and the real checkout call this and cannot
     * disagree about what somebody owes.
     *
     * @param array{lines:array,subtotal:int} $priced
     * @return array{goods:int, shipping:int, discount:int, charged:int, code:string,
     *                code_id:int, note:string, shipping_why:string, free_over:?int, short_by:int}
     */
    public static function totals(array $priced, string $region, string $discountCode = '',
                                  string $email = ''): array
    {
        $goods = (int) $priced['subtotal'];
        $lines = $priced['lines'];

        $off = 0; $codeId = 0; $code = ''; $note = ''; $freeShip = false;
        if (trim($discountCode) !== '' && $lines !== []) {
            $d = ShopDiscount::apply($discountCode, $lines, $email);
            if ($d['ok']) {
                $off      = (int) $d['off'];
                $codeId   = (int) $d['id'];
                $code     = (string) $d['code'];
                $freeShip = (bool) ($d['free_shipping'] ?? false);
            }
            $note = (string) $d['message'];
        }

        // Delivery is quoted on the goods total AFTER the discount, because a free-shipping
        // threshold that ignored a discount would promise free delivery on an order that no
        // longer reaches it — and the buyer would be right to be annoyed.
        $ship = ShopShipping::quote($lines, $region, max(0, $goods - $off));
        $shipping = $freeShip ? 0 : (int) $ship['naira'];

        return [
            'goods' => $goods, 'shipping' => $shipping, 'discount' => $off,
            // What goes to the gateway, and what confirmation checks the verified amount
            // against. Never negative: a discount larger than the goods is clamped inside
            // PromoCode, so this can only reach zero.
            'charged' => max(0, $goods - $off + $shipping),
            'code' => $code, 'code_id' => $codeId, 'note' => $note,
            'shipping_why' => $freeShip && (int) $ship['naira'] > 0
                ? 'Free delivery with your code.'
                : (string) $ship['why'],
            'free_over' => $ship['free_over'], 'short_by' => (int) $ship['short_by'],
        ];
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

        $bail = fn(string $why) => $this->redirect($res, $this->base($req) . '/shop?checkout=' . urlencode($why));

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
        if ($priced['count'] < 1 || $priced['subtotal'] < 1) {
            // An empty cart after pricing is usually a SOLD-OUT cart, not an empty one, and
            // "your cart is empty" would be a lie somebody could not act on.
            return $bail($priced['adjusted'] !== [] ? 'gone' : 'empty');
        }

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

        // Delivery and any code, priced here and nowhere else. What the browser was shown is
        // a preview; this is the number that reaches the gateway.
        $t = self::totals($priced, $region, trim((string) ($b['discount'] ?? '')), $email);
        if ($t['charged'] < 1) return $bail('empty');

        $reference = 'AFG-SHP-' . bin2hex(random_bytes(6));
        try {
            DB::table('gates_orders')->insert(OptionalColumn::filter('gates_orders', [
                'reference'      => $reference,
                'email'          => $email,
                'name'           => $name,
                'phone'          => $phone !== '' ? $phone : null,
                'address'        => 'Region: ' . $region . "\n" . $address,
                'items_json'     => json_encode($priced['lines'], JSON_UNESCAPED_UNICODE),
                // `subtotal_naira` is the CHARGED figure and always has been: it is what goes
                // to the gateway and what confirmation checks the verified amount against.
                // The breakdown sits beside it rather than replacing it, because renaming this
                // column would mean touching that parity check.
                'subtotal_naira' => $t['charged'],
                'goods_naira'    => $t['goods'],
                'shipping_naira' => $t['shipping'],
                'discount_naira' => $t['discount'],
                'discount_code'  => $t['code'] !== '' ? $t['code'] : null,
                'status'         => 'pending',
                'fulfilment'     => 'unfulfilled',
                'provider'       => $provider,
                'ip_hash'        => $ip ? hash('sha256', $ip) : null,
                'created_at'     => Carbon::now()->toDateTimeString(),
            ], ['goods_naira', 'shipping_naira', 'discount_naira', 'discount_code', 'fulfilment']));
        } catch (\Throwable $e) {
            $this->log?->error('[shop] could not persist order', ['err' => $e->getMessage()]);
            return $bail('error');
        }

        // Counted now that an order exists against it, not when somebody typed it into the
        // preview box — a code counted on a look would exhaust itself on window shoppers.
        if ($t['code_id'] > 0) ShopDiscount::countUse($t['code_id']);

        $callbackUrl = $this->base($req) . '/shop/callback?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);
        $init = $this->payments->initialize($provider, $t['charged'], $email, $reference, $callbackUrl, [
            'reference' => $reference, 'purpose' => 'shop', 'items' => $priced['count'],
        ]);

        if (!$init['ok'] || empty($init['checkout_url'])) {
            // The provider's OWN message. It was discarded, leaving an operator with a
            // generic chip and no way to know the gateway said "Invalid key".
            $this->log?->error('[shop] gateway would not start a transaction', [
                'ref' => $reference, 'provider' => $provider, 'reason' => (string) ($init['message'] ?? ''),
            ]);
            DB::table('gates_orders')->where('reference', $reference)->where('status', 'pending')->update(['status' => 'failed']);
            // The use goes back with the order. Otherwise a code limited to fifty is exhausted
            // by fifty gateway misconfigurations and the promotion ends before it started.
            if ($t['code'] !== '') ShopDiscount::releaseUse($t['code']);
            return $bail('start');
        }
        // NOT a 302 straight to the gateway: that redirect is part of a form
        // submission, so `form-action` governs it and a policy without the gateway
        // hosts blocks the POST in the browser before any PHP runs. See GatewayHandoff.
        return $this->redirect($res, \AfricaGates\Services\GatewayHandoff::remember(
            $reference, (string) $init['checkout_url'], $this->base($req) . '/shop/redirect', $provider
        ));
    }

    /** GET /shop/callback — browser return; re-verified server-to-server. */
    public function callback(Request $req, Response $res): Response
    {
        $q         = $req->getQueryParams();
        $reference = trim((string)($q['ref'] ?? $q['reference'] ?? $q['tx_ref'] ?? ''));
        $provider  = strtolower(trim((string)($q['provider'] ?? '')));
        if ($reference === '' || !$this->payments->isKnownProvider($provider)) {
            return $this->redirect($res, $this->base($req) . '/shop?checkout=error');
        }
        $order = DB::table('gates_orders')->where('reference', $reference)->first();
        if (!$order) return $this->redirect($res, $this->base($req) . '/shop?checkout=error');

        $result = $this->confirmByReference($provider, $reference, $order);
        if ($result === 'confirmed' || $result === 'already') {
            return $this->redirect($res, $this->base($req) . '/shop/success?ref=' . urlencode($reference));
        }
        // A mismatch is not a failure and must not be worded as one: the gateway says money
        // moved, and it is a figure a person has to look at. Telling somebody who has been
        // debited that their payment failed is how a support ticket becomes a chargeback.
        if ($result === 'mismatch') {
            return $this->redirect($res, $this->base($req) . '/shop?checkout=mismatch&ref=' . urlencode($reference));
        }
        return $this->redirect($res, $this->base($req) . '/shop?checkout=failed');
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

    /**
     * GET /shop/order/{ref} — a buyer's own order, reachable with the reference alone.
     *
     * ── WHY NO LOGIN ─────────────────────────────────────────────────────────
     *
     * The same doctrine as an event ticket, a claim link and the questionnaire: a shop buyer
     * has no account, and requiring one to see the order they just paid for would put a
     * registration between somebody and their own receipt. The reference is a twelve-hex
     * secret this platform generated, it is in their emailed receipt, and the page is noindex.
     *
     * ── AND WHY IT MATTERS MORE THAN THE SUCCESS PAGE ────────────────────────
     *
     * /shop/success only exists in the tab that came back from the gateway. Close it and the
     * order was unreachable — there was no page anywhere that could answer "did it go through"
     * or "has it shipped", so every such question arrived at a support inbox that had to look
     * it up by hand. This page answers both, and updates as the order is fulfilled.
     */
    public function order(Request $req, Response $res, array $args): Response
    {
        $ref = trim((string) ($args['ref'] ?? ''));
        $order = $ref !== '' ? DB::table('gates_orders')->where('reference', $ref)->first() : null;

        // No hint about whether the reference is unknown or merely not ours: the difference is
        // a way to test references.
        $view = $this->view->render(
            $res->withStatus($order ? 200 : 404),
            'pages/shop/order.twig',
            [
                'page_title' => $order ? 'Your order — Africa GATES' : 'Order not found',
                'gates_page' => 'shop',
                'has_hero'   => false,
                'order'      => $order ? (array) $order : null,
                'items'      => $order ? (json_decode((string) $order->items_json, true) ?: []) : [],
                'support_email' => Notifier::supportEmail(),
            ]
        );
        return $view->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Shared, idempotent confirmation.
     *
     * ── THIS USED TO BE THE IMPLEMENTATION, AND NOW IT IS A CALL ─────────────
     *
     * The logic moved to {@see \AfricaGates\Services\ShopOrderService}, for a reason that is
     * worth keeping in view here: while it lived on this controller, only a browser arriving
     * at `/shop/callback` could reach it. The gateway webhook could not, so it grew a second
     * copy inside the reconciler — one that never touched variant stock, never counted the
     * sale and never awarded the buyer's points. Two implementations of "this order is paid"
     * is precisely the drift this codebase keeps finding.
     *
     * Three callers now share one: this callback, `/pay/webhook`, and the cron sweep.
     */
    private function confirmByReference(string $provider, string $reference, object $order): string
    {
        $r = \AfricaGates\Services\ShopOrderService::confirm(
            $reference, $provider, $this->payments, $this->log
        );
        return (string) $r['state'];
    }

    /**
     * POST /shop/quote — price a basket before anybody commits to anything.
     *
     * ── WHY THIS EXISTS AS WELL AS THE PRICING INSIDE checkout() ─────────────
     *
     * Delivery and a discount code both change what somebody owes, and a buyer who cannot see
     * either until the gateway's page has to complete a purchase to find out. That is where a
     * checkout gets abandoned.
     *
     * It is a PREVIEW. {@see totals()} is the same function the real checkout calls, so the
     * two cannot disagree — and nothing here creates a row, counts a code use, or touches
     * stock, because a code exhausted by window shoppers would be a denial of service on the
     * seller's own promotion.
     */
    public function quote(Request $req, Response $res): Response
    {
        $b      = (array) $req->getParsedBody();
        $region = trim((string) ($b['region'] ?? ''));
        if (!in_array($region, ProductsController::REGIONS, true)) {
            $region = ShopPricing::currentRegion($req);
        }

        $cart = json_decode((string) ($b['cart'] ?? '[]'), true);
        $priced = self::priceCart(is_array($cart) ? $cart : [], $region);
        $t = self::totals($priced, $region, trim((string) ($b['discount'] ?? '')),
                          trim((string) ($b['email'] ?? '')));

        $res->getBody()->write((string) json_encode([
            'success'  => true,
            'goods'    => $t['goods'],
            'shipping' => $t['shipping'],
            'discount' => $t['discount'],
            'charged'  => $t['charged'],
            'code'     => $t['code'],
            'applied'  => $t['code'] !== '',
            'note'     => $t['note'],
            'shipping_why' => $t['shipping_why'],
            'free_over'    => $t['free_over'],
            'short_by'     => $t['short_by'],
            // What we changed about their cart while pricing it — a sold-out line dropped, a
            // quantity trimmed. Silence here is how a total stops matching the last screen.
            'adjusted' => $priced['adjusted'],
            'count'    => $priced['count'],
        ]));
        return $res->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /shop/redirect — the same-origin hop to the gateway.
     *
     * See {@see \AfricaGates\Services\GatewayHandoff}: a 302 from a form POST straight to
     * a gateway host is governed by `form-action`, and a policy without the gateways blocks
     * the submission in the browser before any PHP runs.
     */
    public function handoff(Request $req, Response $res): Response
    {
        $reference = \AfricaGates\Services\GatewayHandoff::reference($req);
        $url = \AfricaGates\Services\GatewayHandoff::take($reference);
        if ($url === null) {
            return $this->redirect($res, $this->base($req) . '/shop?checkout=start');
        }
        return \AfricaGates\Services\GatewayHandoff::page(
            $res, $url, \AfricaGates\Services\GatewayHandoff::providerLabel(), $reference
        );
    }
}
