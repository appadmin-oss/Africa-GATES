<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\{OtpService, ShopDiscount, ShopPricing, ShopShipping, StockAlert};
use AfricaGates\Support\{OptionalColumn, PromoCode};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Running the shop: orders that get shipped, codes, and what delivery costs.
 *
 * ── WHY ORDERS NEEDED A SCREEN OF THEIR OWN ──────────────────────────────────
 *
 * `gates_orders` was reachable only through the generic data browser, which can list a table
 * and cannot change one. So the shop could take money and had nowhere to record that the thing
 * had been packed, posted, or delivered — an order was 'paid' forever. Every "has it shipped
 * yet" arrived at a support inbox to be answered from memory, and the buyer's own tracking page
 * had nothing to show them because nothing anywhere could set it.
 *
 * ── AND WHY THE STOCK SHORTFALL IS THE FIRST THING ON IT ─────────────────────
 *
 * Checkout refuses an oversell, but it cannot hold stock across a trip to the gateway: two
 * buyers can pay for the last item inside one payment window. That order is paid and cannot be
 * filled, and it is the one thing on this screen that needs somebody today — so it sorts to the
 * top and says so, rather than looking like every other paid order until a customer complains.
 */
final class ShopController
{
    public const FULFILMENTS = [
        'unfulfilled' => 'Not started',
        'packed'      => 'Packed',
        'shipped'     => 'Shipped',
        'delivered'   => 'Delivered',
        'cancelled'   => 'Cancelled',
    ];

    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        private readonly ?OtpService $mailer = null,
    ) {}

    // ══ orders ═══════════════════════════════════════════════════════════════

    public function orders(Request $req, Response $res): Response
    {
        $q      = $req->getQueryParams();
        $status = (string) ($q['status'] ?? '');
        $ful    = (string) ($q['ful'] ?? '');
        $search = trim((string) ($q['q'] ?? ''));

        $hasFul = OptionalColumn::on('gates_orders', 'fulfilment');

        try {
            $rows = DB::table('gates_orders');
            if ($status !== '') $rows->where('status', $status);
            if ($ful !== '' && $hasFul) {
                // 'unfulfilled' has to include NULL: every order that predates this column is
                // unfulfilled in fact, and a filter that hid them would show an empty queue on
                // a shop with a backlog.
                $ful === 'unfulfilled'
                    ? $rows->where(static function ($w): void {
                          $w->whereNull('fulfilment')->orWhere('fulfilment', 'unfulfilled');
                      })
                    : $rows->where('fulfilment', $ful);
            }
            if ($search !== '') {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $rows->where(static function ($w) use ($like): void {
                    $w->where('reference', 'like', $like)
                      ->orWhere('email', 'like', $like)
                      ->orWhere('name', 'like', $like);
                });
            }
            // A shortfall first, then newest. Not a sort the operator picks: a paid order that
            // cannot be filled is the only row on this page with a clock on it.
            if (OptionalColumn::on('gates_orders', 'stock_short')) {
                $rows->orderByDesc(DB::raw('COALESCE(stock_short, 0)'));
            }
            $list = $rows->orderByDesc('id')->limit(300)->get()
                ->map(static function ($r): array {
                    $a = (array) $r;
                    $a['lines'] = json_decode((string) ($r->items_json ?? '[]'), true) ?: [];
                    $a['seats'] = array_sum(array_column($a['lines'], 'qty'));
                    return $a;
                })->all();
        } catch (\Throwable) { $list = []; }

        return $this->view->render($res, 'admin/shop/orders.twig', [
            'page_title'  => 'Shop orders — Admin',
            'admin_page'  => 'shop_orders',
            'rows'        => $list,
            'status'      => $status,
            'ful'         => $ful,
            'q'           => $search,
            'fulfilments' => self::FULFILMENTS,
            'summary'     => $this->summary(),
            'has_ful'     => $hasFul,
            // The most actionable list in the shop, and one nothing has ever been able to
            // show: a restock order written by the people who wanted to pay. It sits on the
            // orders screen because it is the same job — what to do next about stock.
            'demand'      => StockAlert::demand(20),
        ]);
    }

    /** Money and workload at a glance — the four numbers an operator opens this page for. */
    private function summary(): array
    {
        $out = ['paid' => 0, 'revenue' => 0, 'awaiting' => 0, 'short' => 0, 'pending' => 0];
        try {
            $out['paid']    = (int) DB::table('gates_orders')->where('status', 'paid')->count();
            $out['revenue'] = (int) DB::table('gates_orders')->where('status', 'paid')
                ->sum(DB::raw('COALESCE(subtotal_naira, 0)'));
            $out['pending'] = (int) DB::table('gates_orders')->where('status', 'pending')->count();
            if (OptionalColumn::on('gates_orders', 'fulfilment')) {
                $out['awaiting'] = (int) DB::table('gates_orders')->where('status', 'paid')
                    ->where(static function ($w): void {
                        $w->whereNull('fulfilment')->orWhereIn('fulfilment', ['unfulfilled', 'packed']);
                    })->count();
            }
            if (OptionalColumn::on('gates_orders', 'stock_short')) {
                $out['short'] = (int) DB::table('gates_orders')->where('stock_short', 1)
                    ->where('status', 'paid')->count();
            }
        } catch (\Throwable) {}
        return $out;
    }

    /**
     * Move an order along, and tell the buyer when it is worth telling them.
     *
     * ── ONLY 'SHIPPED' SENDS MAIL ────────────────────────────────────────────
     *
     * 'Packed' is an internal state; emailing somebody about it trains them to ignore the
     * emails that matter. 'Shipped' is the one they are waiting for, and it carries whatever
     * the operator typed as a tracking note — which is why the note is saved BEFORE the mail
     * is attempted rather than after: the buyer's own tracking page has to be right even if
     * the message never arrives.
     */
    public function fulfil(Request $req, Response $res): Response
    {
        $b    = (array) $req->getParsedBody();
        $id   = (int) ($b['id'] ?? 0);
        $to   = (string) ($b['fulfilment'] ?? '');
        $note = trim((string) ($b['tracking_note'] ?? ''));
        $back = '/admin/shop/orders';

        if (!isset(self::FULFILMENTS[$to])) {
            $_SESSION['flash_error'] = 'That is not a delivery state.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $order = DB::table('gates_orders')->where('id', $id)->first();
        if (!$order) {
            $_SESSION['flash_error'] = 'That order could not be found.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        try {
            DB::table('gates_orders')->where('id', $id)
                ->update(OptionalColumn::filter('gates_orders', [
                    'fulfilment'    => $to,
                    'tracking_note' => $note !== '' ? mb_substr($note, 0, 500) : null,
                    'fulfilled_at'  => in_array($to, ['shipped', 'delivered'], true)
                        ? Carbon::now()->toDateTimeString() : null,
                    // Clearing the flag is part of resolving it: an operator who has restocked
                    // and shipped should not keep seeing the order at the top of the queue.
                    'stock_short'   => $to === 'shipped' || $to === 'delivered' ? 0 : ($order->stock_short ?? null),
                ], ['fulfilment', 'tracking_note', 'fulfilled_at', 'stock_short']));
        } catch (\Throwable $e) {
            error_log('[shop] could not update fulfilment for order ' . $id . ': ' . $e->getMessage());
            $_SESSION['flash_error'] = 'That could not be saved just now.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'order.fulfil', 'order', $id);
        $_SESSION['flash_ok'] = 'Order ' . (string) $order->reference . ' marked '
            . strtolower(self::FULFILMENTS[$to]) . '.';

        if ($to === 'shipped') $this->tellShipped($order, $note);

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** Best-effort. The state is in the database whether or not the message got through. */
    private function tellShipped(object $order, string $note): void
    {
        if ($this->mailer === null) return;

        $base = rtrim((string) \AfricaGates\Support\Env::get('APP_URL', ''), '/');
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $link = $base . '/shop/order/' . rawurlencode((string) $order->reference);

        $html = '<p>Hello <strong>' . $e((string) $order->name) . '</strong>,</p>'
              . '<p>Your order <strong>' . $e((string) $order->reference) . '</strong> has left us '
              . 'and is on its way.</p>'
              . ($note !== '' ? '<p>' . $e($note) . '</p>' : '')
              . '<p style="text-align:center;margin:22px 0"><a href="' . $link . '"'
              . ' style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;'
              . 'border-radius:999px;font-weight:600;text-decoration:none">Track this order →</a></p>';

        $plain = 'Hello ' . (string) $order->name . ",\n\n"
               . 'Your order ' . (string) $order->reference . " has left us and is on its way.\n"
               . ($note !== '' ? "\n" . $note . "\n" : '')
               . "\n" . $link . "\n\n— Africa GATES";

        try {
            $this->mailer->sendBranded((string) $order->email,
                'Your order is on its way — ' . (string) $order->reference, $html, $plain, 'Shop');
        } catch (\Throwable) {}
    }

    // ══ discount codes ═══════════════════════════════════════════════════════

    public function codes(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'admin/shop/codes.twig', [
            'page_title' => 'Shop discount codes — Admin',
            'admin_page' => 'products',
            'codes'      => ShopDiscount::all(),
            'products'   => $this->productChoices(),
            'categories' => ProductsController::CATEGORIES,
            'missing'    => OptionalColumn::missing('gates_orders', ['discount_code']),
            'ready'      => DB::schema()->hasTable('gates_shop_codes'),
        ]);
    }

    /** @return list<array{id:int,name:string,price:int}> */
    private function productChoices(): array
    {
        try {
            return DB::table('gates_products')->where('is_active', 1)
                ->orderBy('name')->get()
                ->map(static fn ($p): array => ['id' => (int) $p->id, 'name' => (string) $p->name,
                                                'price' => (int) $p->price_naira])->all();
        } catch (\Throwable) { return []; }
    }

    public function saveCode(Request $req, Response $res): Response
    {
        $b    = (array) $req->getParsedBody();
        $back = '/admin/shop/codes';

        // Folded to A–Z0–9 and a dash: a code is read off a poster and typed by hand, and a
        // space or a curly apostrophe inside one is a support ticket waiting to happen.
        $code = (string) preg_replace('/[^A-Z0-9\-]+/', '', PromoCode::normalise((string) ($b['code'] ?? '')));
        if ($code === '') {
            $_SESSION['flash_error'] = 'A code needs some letters.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $kind      = (string) ($b['kind'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $amount    = max(0, (int) ($b['amount'] ?? 0));
        if ($kind === 'percent' && $amount > 100) $amount = 100;
        $freeShip  = !empty($b['free_shipping']);

        // A code that takes nothing off AND does not pay for delivery is not a discount.
        if ($amount === 0 && !$freeShip) {
            $_SESSION['flash_error'] = 'Set an amount, or tick free delivery — otherwise the code does nothing.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $productIds = array_values(array_filter(array_map('intval', (array) ($b['product_ids'] ?? []))));
        $categories = array_values(array_intersect(ProductsController::CATEGORIES,
                                                   (array) ($b['categories'] ?? [])));

        $row = [
            'code'          => mb_substr($code, 0, 40),
            'label'         => mb_substr(trim((string) ($b['label'] ?? '')), 0, 120) ?: null,
            'kind'          => $kind,
            'amount'        => $amount,
            'product_ids'   => $productIds !== [] ? json_encode($productIds) : null,
            'categories'    => $categories !== [] ? json_encode($categories) : null,
            'min_spend_naira' => trim((string) ($b['min_spend_naira'] ?? '')) !== ''
                ? max(0, (int) $b['min_spend_naira']) : null,
            'free_shipping' => $freeShip ? 1 : 0,
            'max_uses'      => trim((string) ($b['max_uses'] ?? '')) !== '' ? max(1, (int) $b['max_uses']) : null,
            'max_per_email' => max(1, (int) ($b['max_per_email'] ?? 1)),
            'starts_at'     => self::stamp($b['starts_at'] ?? ''),
            'ends_at'       => self::stamp($b['ends_at'] ?? ''),
            'is_active'     => !empty($b['is_active']) ? 1 : 0,
            'updated_at'    => Carbon::now()->toDateTimeString(),
        ];

        $id = (int) ($b['code_id'] ?? 0);
        try {
            if ($id > 0 && DB::table('gates_shop_codes')->where('id', $id)->exists()) {
                // `used_count` is deliberately absent: it is the record of what has happened,
                // not a setting, and letting a save reset it would hand an exhausted code its
                // whole allowance back.
                DB::table('gates_shop_codes')->where('id', $id)->update($row);
                $_SESSION['flash_ok'] = 'Code ' . $code . ' updated.';
            } else {
                $row['created_at'] = $row['updated_at'];
                $id = (int) DB::table('gates_shop_codes')->insertGetId($row);
                $_SESSION['flash_ok'] = 'Code ' . $code . ' created.';
            }
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'shop.code.save', 'shop_code', $id);
        } catch (\Throwable $e) {
            error_log('[shop] could not save code ' . $code . ': ' . $e->getMessage());
            $_SESSION['flash_error'] = 'That code could not be saved — there may already be one '
                                     . 'with those letters.';
        }

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Retire a code.
     *
     * Deactivated rather than deleted once anything has been bought against it: the orders
     * carry the letters, and a receipt naming a code that exists nowhere is a number nobody
     * can explain six months later at a reconciliation.
     */
    public function deleteCode(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $back = '/admin/shop/codes';

        $row = DB::table('gates_shop_codes')->where('id', $id)->first();
        if (!$row) {
            $_SESSION['flash_error'] = 'That code could not be found.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $used = 0;
        try {
            $used = (int) DB::table('gates_orders')
                ->whereRaw('UPPER(discount_code) = ?', [PromoCode::normalise((string) $row->code)])
                ->count();
        } catch (\Throwable) {}

        if ($used > 0) {
            DB::table('gates_shop_codes')->where('id', $id)->update(['is_active' => 0]);
            $_SESSION['flash_ok'] = (string) $row->code . ' has been switched off. It is kept because '
                . $used . ' order(s) were placed with it, and their receipts name it.';
        } else {
            DB::table('gates_shop_codes')->where('id', $id)->delete();
            $_SESSION['flash_ok'] = (string) $row->code . ' deleted.';
        }
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'shop.code.delete', 'shop_code', $id);

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    // ══ delivery ═════════════════════════════════════════════════════════════

    /**
     * What delivery costs, per region.
     *
     * On its own screen rather than buried in Settings, because it is a rate card an operator
     * revisits when a courier changes their prices — and because the free-delivery threshold
     * beside it is a marketing decision, not a configuration flag.
     */
    public function shipping(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'admin/shop/shipping.twig', [
            'page_title' => 'Delivery charges — Admin',
            'admin_page' => 'products',
            'regions'    => ShopPricing::regions(),
            'rates'      => ShopShipping::rates(),
            'free_over'  => ShopShipping::freeOver(),
            // The other half of the price story, shown here so the two are read together: a
            // multiplier and a delivery charge both change what somebody pays by region.
            'multipliers'=> ShopPricing::multipliers(),
        ]);
    }

    public function saveShipping(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $rates = [];
        foreach (ShopPricing::regions() as $region) {
            $raw = trim((string) ($b['rate'][$region] ?? ''));
            // Blank means "no charge here", stored as 0 rather than omitted — an absent region
            // and a free one look the same to the reader and should read the same way back.
            $rates[$region] = $raw === '' ? 0 : max(0, min(1_000_000, (int) $raw));
        }

        $over = trim((string) ($b['free_over'] ?? ''));

        try {
            $this->setting(ShopShipping::RATES_KEY, json_encode($rates));
            $this->setting(ShopShipping::THRESHOLD_KEY, $over === '' ? '0' : (string) max(0, (int) $over));
            $_SESSION['flash_ok'] = 'Delivery charges saved.';
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'shop.shipping.save', 'setting', 0);
        } catch (\Throwable $e) {
            error_log('[shop] could not save shipping rates: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Those charges could not be saved.';
        }

        return $res->withHeader('Location', '/admin/shop/shipping')->withStatus(302);
    }

    private function setting(string $key, string $value): void
    {
        $exists = DB::table('gates_settings')->where('key_name', $key)->exists();
        $exists
            ? DB::table('gates_settings')->where('key_name', $key)->update(['value' => $value])
            : DB::table('gates_settings')->insert(['key_name' => $key, 'value' => $value]);
    }

    /** A datetime-local value from a form, or null. Never a half-parsed guess. */
    private static function stamp(mixed $raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') return null;
        try { return Carbon::parse(str_replace('T', ' ', $s))->toDateTimeString(); }
        catch (\Throwable) { return null; }
    }
}
