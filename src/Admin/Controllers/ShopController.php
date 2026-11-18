<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Support\ColumnRange;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\{OtpService, PaymentService, ShopDiscount, ShopMail, ShopOrderLog, ShopOrderService,
                            ShopPickup, ShopPricing, ShopRefunds, ShopShipping, StockAlert};
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
        // A collection order's two states (phase 4, ShopPickup): ready, then handed over.
        'ready'       => 'Ready to collect',
        'collected'   => 'Collected',
        'cancelled'   => 'Cancelled',
    ];

    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        private readonly ?OtpService $mailer = null,
        // Refunds and cancelling a pending order both ask the gateway. Optional so the
        // order-list tests can build the controller bare; every action that needs it says so.
        private readonly ?PaymentService $payments = null,
    ) {}

    // ══ orders ═══════════════════════════════════════════════════════════════

    /** Orders on one page of the list. */
    public const PAGE_SIZE = 50;
    /** At most this many orders in one bulk action or one print. Named in the refusal. */
    public const BULK_MAX = 200;

    /**
     * The list: filters, a date range, paging, and a TRUE total for the filter.
     *
     * It used to stop at 300 rows with no paging and no dates, so on a busy shop the oldest
     * paid-but-unshipped orders simply fell off the bottom of the only screen that showed them.
     * Every filter is applied in SQL above the page (never to the drawn page), the total is the
     * filter's, and the stock shortfall still sorts to the top.
     */
    public function orders(Request $req, Response $res): Response
    {
        $q      = $req->getQueryParams();
        $f      = self::filters($q);
        $hasFul = OptionalColumn::on('gates_orders', 'fulfilment');
        $page   = max(1, (int) ($q['page'] ?? 1));
        $error  = null;
        $total  = 0;
        $list   = [];

        try {
            $total = (int) $this->filtered($f)->count();
            $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
            $page  = min($page, $pages);
            $rows  = $this->filtered($f);
            // A shortfall first, then newest. Not a sort the operator picks: a paid order that
            // cannot be filled is the only row on this page with a clock on it.
            if (OptionalColumn::on('gates_orders', 'stock_short')) {
                $rows->orderByDesc(DB::raw('COALESCE(stock_short, 0)'));
            }
            $list = $rows->orderByDesc('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)->get()
                ->map(static function ($r): array {
                    $a = (array) $r;
                    $a['lines'] = json_decode((string) ($r->items_json ?? '[]'), true) ?: [];
                    $a['seats'] = array_sum(array_column($a['lines'], 'qty'));
                    return $a;
                })->all();
        } catch (\Throwable $e) {
            // An honest error with a retry, never a silent empty table — that is the bug that
            // hides an outage.
            error_log('[shop] orders list: ' . $e->getMessage());
            $error = 'The orders could not be read just now.';
            $pages = 1;
        }

        return $this->view->render($res, 'admin/shop/orders.twig', [
            'page_title'  => 'Shop orders — Admin',
            'admin_page'  => 'shop_orders',
            'rows'        => $list,
            'status'      => $f['status'],
            'ful'         => $f['ful'],
            'q'           => $f['q'],
            'from'        => $f['from'],
            'to'          => $f['to'],
            'page'        => $page,
            'pages'       => $pages,
            'total'       => $total,
            'error'       => $error,
            'query'       => array_filter(['q' => $f['q'], 'status' => $f['status'], 'ful' => $f['ful'],
                                           'from' => $f['from'], 'to' => $f['to']], static fn ($v) => $v !== ''),
            'fulfilments' => self::FULFILMENTS,
            'moves'       => self::BULK_MOVES,
            'bulk_max'    => self::BULK_MAX,
            'summary'     => $this->summary(),
            'has_ful'     => $hasFul,
            // The most actionable list in the shop, and one nothing has ever been able to
            // show: a restock order written by the people who wanted to pay. It sits on the
            // orders screen because it is the same job — what to do next about stock.
            'demand'      => StockAlert::demand(20),
        ]);
    }

    /**
     * The list's filters, read once and allow-listed: an unknown value falls back to "any"
     * rather than erroring, so a stale bookmark shows the list, not a failure.
     *
     * @return array{status:string,ful:string,q:string,from:string,to:string}
     */
    private static function filters(array $q): array
    {
        $date = static fn ($v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        $status = (string) ($q['status'] ?? '');
        $ful    = (string) ($q['ful'] ?? '');
        return [
            'status' => in_array($status, ['paid', 'pending', 'failed', 'refunded', 'cancelled'], true) ? $status : '',
            'ful'    => isset(self::FULFILMENTS[$ful]) ? $ful : '',
            'q'      => mb_substr(trim((string) ($q['q'] ?? '')), 0, 120),
            'from'   => $date($q['from'] ?? ''),
            'to'     => $date($q['to'] ?? ''),
        ];
    }

    /** @param array{status:string,ful:string,q:string,from:string,to:string} $f */
    private function filtered(array $f): \Illuminate\Database\Query\Builder
    {
        $rows = DB::table('gates_orders');
        if ($f['status'] !== '') $rows->where('status', $f['status']);
        if ($f['ful'] !== '' && OptionalColumn::on('gates_orders', 'fulfilment')) {
            // 'unfulfilled' has to include NULL: every order that predates this column is
            // unfulfilled in fact, and a filter that hid them would show an empty queue on
            // a shop with a backlog.
            $f['ful'] === 'unfulfilled'
                ? $rows->where(static function ($w): void {
                      $w->whereNull('fulfilment')->orWhere('fulfilment', 'unfulfilled');
                  })
                : $rows->where('fulfilment', $f['ful']);
        }
        if ($f['q'] !== '') {
            // Through Support\Like: a backslash escape with no ESCAPE clause is MySQL's
            // default and SQLite has none, so a search for a reference containing `_`
            // matched on production and returned nothing in dev.
            $like = \AfricaGates\Support\Like::contains($f['q']);
            $rows->where(static function ($w) use ($like): void {
                $w->whereRaw(\AfricaGates\Support\Like::clause('reference'), [$like])
                  ->orWhereRaw(\AfricaGates\Support\Like::clause('email'), [$like])
                  ->orWhereRaw(\AfricaGates\Support\Like::clause('name'), [$like]);
            });
        }
        // Whole days, as plain 'Y-m-d H:i:s' bounds: a `T`-separated or zoned value compares
        // wrong on SQLite and is refused on strict MySQL (CLAUDE.md, the stack).
        if ($f['from'] !== '') $rows->where('created_at', '>=', $f['from'] . ' 00:00:00');
        if ($f['to'] !== '')   $rows->where('created_at', '<=', $f['to'] . ' 23:59:59');
        return $rows;
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

    /** The delivery states the list and the bulk bar can move an order to. Not "cancelled". */
    public const BULK_MOVES = ['packed' => 'Packed', 'shipped' => 'Shipped', 'delivered' => 'Delivered',
                               'ready' => 'Ready to collect'];

    /**
     * Move an order along, and tell the buyer when it is worth telling them.
     *
     * ── WHICH MOVES SEND MAIL ────────────────────────────────────────────────
     *
     * 'Packed' is an internal state; emailing somebody about it trains them to ignore the
     * emails that matter. 'Shipped' is the one they are waiting for, and it carries whatever
     * the operator typed as a tracking note — which is why the note is saved BEFORE the mail
     * is attempted rather than after: the buyer's own tracking page has to be right even if
     * the message never arrives. 'Delivered' closes the loop (it was silent until the shop
     * upgrade, so a buyer whose parcel went to a neighbour had no message to answer).
     */
    public function fulfil(Request $req, Response $res): Response
    {
        $b    = (array) $req->getParsedBody();
        $id   = (int) ($b['id'] ?? 0);
        $to   = (string) ($b['fulfilment'] ?? '');
        $note = trim((string) ($b['tracking_note'] ?? ''));
        $back = ($b['back'] ?? '') === 'order' ? '/admin/shop/orders/' . $id : '/admin/shop/orders';

        if (!isset(self::FULFILMENTS[$to])) {
            $_SESSION['flash_error'] = 'That is not a delivery state.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        // ── "CANCELLED" IS NOT A LABEL ───────────────────────────────────────
        //
        // It used to be one more option in this list, and choosing it moved neither money
        // nor stock: the buyer kept paying for a shirt still counted as sold. Cancelling goes
        // through the order's own page now, which refunds what was paid (ShopRefunds::cancel).
        if ($to === 'cancelled') {
            $_SESSION['flash_error'] = 'Cancel an order from its own page — that refunds the buyer and returns the stock.';
            return $res->withHeader('Location', '/admin/shop/orders/' . $id)->withStatus(302);
        }

        // "Collected" needs the buyer's code or a reason — a person at a table, on the order's
        // own page (ShopPickup::collect), never a dropdown.
        if ($to === 'collected') {
            $_SESSION['flash_error'] = 'Hand a collection over from the order\'s own page — it asks for the buyer\'s code.';
            return $res->withHeader('Location', '/admin/shop/orders/' . $id)->withStatus(302);
        }
        $admin = (int) ($_SESSION['admin_id'] ?? 0);
        $v = $this->applyFulfilment($id, $to, $note, $admin, true);
        if ($v['verdict'] === 'refused') {
            $_SESSION['flash_error'] = $v['why'];
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $this->audit->record($admin, 'order.fulfil', 'order', $id);
        $_SESSION['flash_ok'] = 'Order ' . $v['ref'] . ' marked ' . strtolower(self::FULFILMENTS[$to]) . '.';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * The single-row move, which the list, the order page and the bulk bar all use — one
     * implementation, so a move and its bulk equivalent cannot drift into doing different things.
     *
     * @return array{verdict:'applied'|'unchanged'|'refused', ref:string, why:string}
     */
    private function applyFulfilment(int $id, string $to, string $note, int $admin, bool $writeNote): array
    {
        $order = DB::table('gates_orders')->where('id', $id)->first();
        if (!$order) return ['verdict' => 'refused', 'ref' => '#' . $id, 'why' => 'That order could not be found.'];
        $ref = (string) $order->reference;
        if ($to === 'collected') {
            return ['verdict' => 'refused', 'ref' => $ref, 'why' => $ref . ' — a collection is handed over from its own page, with the code.'];
        }
        if ($to === 'ready') {
            if ((string) ($order->fulfilment ?? '') === 'ready') return ['verdict' => 'unchanged', 'ref' => $ref, 'why' => ''];
            $r = ShopPickup::markReady($id, $admin, $this->mailer);
            return ['verdict' => $r['ok'] ? 'applied' : 'refused', 'ref' => $ref, 'why' => $r['message']];
        }
        if ((string) $order->status !== 'paid') {
            return ['verdict' => 'refused', 'ref' => $ref, 'why' => $ref . ' is ' . (string) $order->status . ', not paid — only a paid order is sent.'];
        }
        if ((string) ($order->fulfilment ?? '') === 'cancelled') {
            return ['verdict' => 'refused', 'ref' => $ref, 'why' => $ref . ' was cancelled.'];
        }
        $same = (string) ($order->fulfilment ?: 'unfulfilled') === $to;
        if ($same && (!$writeNote || $note === (string) ($order->tracking_note ?? ''))) {
            return ['verdict' => 'unchanged', 'ref' => $ref, 'why' => ''];
        }

        try {
            $row = [
                'fulfilment'   => $to,
                'fulfilled_at' => in_array($to, ['shipped', 'delivered', 'collected'], true)
                    ? ($order->fulfilled_at ?? null) ?: Carbon::now()->toDateTimeString() : null,
                // Clearing the flag is part of resolving it: an operator who has restocked
                // and shipped should not keep seeing the order at the top of the queue.
                'stock_short'  => in_array($to, ['shipped', 'delivered', 'collected'], true) ? 0 : ($order->stock_short ?? null),
            ];
            if ($writeNote) $row['tracking_note'] = $note !== '' ? mb_substr($note, 0, 500) : null;
            DB::table('gates_orders')->where('id', $id)
                ->update(OptionalColumn::filter('gates_orders', $row, ['fulfilment', 'tracking_note', 'fulfilled_at', 'stock_short']));
        } catch (\Throwable $e) {
            error_log('[shop] could not update fulfilment for order ' . $id . ': ' . $e->getMessage());
            return ['verdict' => 'refused', 'ref' => $ref, 'why' => 'That could not be saved just now.'];
        }

        ShopOrderLog::add($id, 'status', 'Marked ' . strtolower(self::FULFILMENTS[$to])
            . ($note !== '' ? ' — ' . mb_substr($note, 0, 200) : '') . '.', $admin);
        if (!$same) {
            if ($to === 'shipped') $this->tellShipped($order, $note);
            if ($to === 'delivered') ShopMail::send($this->mailer, $id, 'delivered');
        }
        return ['verdict' => $same ? 'unchanged' : 'applied', 'ref' => $ref, 'why' => ''];
    }

    /**
     * One bulk op, several declared actions: move the ticked orders, or print them.
     *
     * The rules of every bulk action in this console: capped (BULK_MAX, named in the refusal),
     * the whole payload validated before any write, a per-row verdict that wins over a top-level
     * "ok" (12 of 15 moved is reported as partial), `unchanged` reported apart from applied, and
     * ONE audit event for the run with its counts and refusals.
     */
    public function bulk(Request $req, Response $res): Response
    {
        $b      = (array) $req->getParsedBody();
        $action = (string) ($b['action'] ?? '');
        $ids    = array_values(array_unique(array_filter(array_map('intval', (array) ($b['ids'] ?? [])), static fn (int $i): bool => $i > 0)));
        $back   = '/admin/shop/orders' . (($b['back_query'] ?? '') !== '' ? '?' . (string) preg_replace('/[^A-Za-z0-9=&%_.\-]/', '', (string) $b['back_query']) : '');

        if ($ids === []) {
            $_SESSION['flash_error'] = 'Tick at least one order first.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        if (count($ids) > self::BULK_MAX) {
            $_SESSION['flash_error'] = 'At most ' . self::BULK_MAX . ' orders at once; ' . count($ids) . ' were ticked. Nothing was changed.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        if ($action === 'pick' || $action === 'slips') {
            return $res->withHeader('Location', '/admin/shop/orders/print?kind=' . $action . '&ids=' . implode(',', $ids))->withStatus(302);
        }
        if (!isset(self::BULK_MOVES[$action])) {
            $_SESSION['flash_error'] = 'Pick what to do with the ticked orders.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $admin = (int) ($_SESSION['admin_id'] ?? 0);
        $done = []; $same = []; $refused = [];
        foreach ($ids as $id) {
            $v = $this->applyFulfilment($id, $action, '', $admin, false);
            match ($v['verdict']) {
                'applied'   => $done[] = $v['ref'],
                'unchanged' => $same[] = $v['ref'],
                default     => $refused[] = $v['why'],
            };
        }
        $this->audit->record($admin, 'order.fulfil.bulk', 'order', null, [
            'action' => $action, 'asked' => count($ids), 'applied' => count($done),
            'unchanged' => count($same), 'refused' => $refused,
        ]);

        $msg = count($done) . ' marked ' . strtolower(self::BULK_MOVES[$action])
             . ($same !== [] ? ', ' . count($same) . ' already were' : '') . '.';
        if ($refused !== []) {
            $_SESSION['flash_error'] = $msg . ' ' . count($refused) . ' refused: ' . implode(' ', array_slice($refused, 0, 5))
                . (count($refused) > 5 ? ' …and ' . (count($refused) - 5) . ' more.' : '');
        } else {
            $_SESSION['flash_ok'] = $msg;
        }
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * The pick list (totals per product and option, to walk the shelf once) and packing slips
     * (one page per order), for the ticked orders — or, with none named, every paid order not
     * yet sent. A page of its own, made to be printed: the console's chrome is not on it.
     */
    public function printable(Request $req, Response $res): Response
    {
        $q    = $req->getQueryParams();
        $kind = ($q['kind'] ?? '') === 'slips' ? 'slips' : 'pick';
        $ids  = array_slice(array_values(array_unique(array_filter(array_map('intval',
                    explode(',', (string) ($q['ids'] ?? ''))), static fn (int $i): bool => $i > 0))), 0, self::BULK_MAX);

        $rows = DB::table('gates_orders');
        if ($ids !== []) {
            $rows->whereIn('id', $ids);
        } else {
            $rows->where('status', 'paid')->where(static function ($w): void {
                $w->whereNull('fulfilment')->orWhereIn('fulfilment', ['unfulfilled', 'packed']);
            });
        }
        $orders = $rows->orderBy('id')->limit(self::BULK_MAX)->get()->map(static function ($r): array {
            $a = (array) $r;
            $a['lines'] = json_decode((string) ($r->items_json ?? '[]'), true) ?: [];
            return $a;
        })->all();

        // The pick list: one row per product × option, summed, with the orders it is for.
        $pick = [];
        $vids = [];
        foreach ($orders as $o) {
            foreach ($o['lines'] as $l) {
                $key = (string) ($l['slug'] ?? '') . '|' . (int) ($l['variant_id'] ?? 0);
                $pick[$key] ??= ['name' => (string) ($l['name'] ?? $l['slug'] ?? '?'), 'option' => (string) ($l['variant'] ?? ''),
                                 'variant_id' => (int) ($l['variant_id'] ?? 0), 'qty' => 0, 'orders' => [], 'sku' => ''];
                $pick[$key]['qty'] += (int) ($l['qty'] ?? 0);
                $pick[$key]['orders'][] = (string) $o['reference'];
                if ((int) ($l['variant_id'] ?? 0) > 0) $vids[] = (int) $l['variant_id'];
            }
        }
        if ($vids !== []) {
            // One query for every SKU on the list, never one per line.
            $skus = DB::table('gates_product_variants')->whereIn('id', array_unique($vids))->pluck('sku', 'id')->all();
            foreach ($pick as &$p) $p['sku'] = (string) ($skus[$p['variant_id']] ?? '');
            unset($p);
        }
        uasort($pick, static fn ($a, $b) => [$a['name'], $a['option']] <=> [$b['name'], $b['option']]);

        return $this->view->render($res, 'admin/shop/print.twig', [
            'page_title' => ($kind === 'pick' ? 'Pick list' : 'Packing slips') . ' — Admin',
            'kind'       => $kind,
            'orders'     => $orders,
            'pick'       => array_values($pick),
            'named'      => $ids !== [],
            'printed_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** A staff note on the order's history. */
    public function note(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $body = trim((string) (((array) $req->getParsedBody())['note'] ?? ''));
        if (!DB::table('gates_orders')->where('id', $id)->exists()) {
            $_SESSION['flash_error'] = 'That order could not be found.';
            return $res->withHeader('Location', '/admin/shop/orders')->withStatus(302);
        }
        if ($body === '') {
            $_SESSION['flash_error'] = 'Write the note first.';
        } elseif (mb_strlen($body) > ShopOrderLog::NOTE_MAX) {
            $_SESSION['flash_error'] = 'A note can be at most ' . ShopOrderLog::NOTE_MAX . ' characters.';
        } else {
            ShopOrderLog::add($id, 'note', $body, (int) ($_SESSION['admin_id'] ?? 0));
            $_SESSION['flash_ok'] = 'Note added.';
        }
        return $res->withHeader('Location', '/admin/shop/orders/' . $id . '#soHistory')->withStatus(302);
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

    // ══ one order ════════════════════════════════════════════════════════════

    /**
     * One order, everything about it: what was bought, what was paid and refunded, where it is,
     * and what has happened to it — and the two things that move money, refund and cancel.
     */
    public function order(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $o  = DB::table('gates_orders')->where('id', $id)->first();
        if (!$o) {
            $_SESSION['flash_error'] = 'That order could not be found.';
            return $res->withHeader('Location', '/admin/shop/orders')->withStatus(302);
        }
        $a = (array) $o;
        $a['lines'] = json_decode((string) ($o->items_json ?? '[]'), true) ?: [];

        return $this->view->render($res, 'admin/shop/order.twig', $this->orderContext($o, $a));
    }

    /** @return array<string,mixed> */
    private function orderContext(object $o, array $a): array
    {
        $refundable = ShopRefunds::ready() ? ShopRefunds::refundable($o) : 0;
        return [
            'page_title'  => 'Order ' . (string) $o->reference . ' — Admin',
            'admin_page'  => 'shop_orders',
            'o'           => $a,
            'fulfilments' => self::FULFILMENTS,
            'refunds'     => ShopRefunds::forOrder((int) $o->id),
            'refundable'  => $refundable,
            'refunds_ready' => ShopRefunds::ready(),
            'has_left'    => ShopOrderService::hasLeft($o),
            'history'     => ShopOrderLog::for((int) $o->id),
            'note_max'    => ShopOrderLog::NOTE_MAX,
            'pickup'      => ShopPickup::isCollection($o) ? (array) (ShopPickup::point((int) $o->pickup_point_id) ?? []) : null,
            'override_min'=> ShopPickup::OVERRIDE_MIN,
        ];
    }

    /**
     * Refund some or all of a paid order — through the gateway, or recorded as returned by hand.
     *
     * The amount is blank for "everything left". The units to return are per line and default
     * to none: a partial refund is as often a goodwill gesture as a return, and a shirt that
     * did not come back must not be counted as on the shelf.
     */
    public function refund(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $b    = (array) $req->getParsedBody();
        $back = '/admin/shop/orders/' . $id;
        $raw  = trim((string) ($b['amount'] ?? ''));
        $amount = $raw === '' ? null : (int) preg_replace('/[^0-9]/', '', $raw);
        $restock = [];
        foreach ((array) ($b['restock'] ?? []) as $i => $q) {
            if ((int) $q > 0) $restock[(int) $i] = (int) $q;
        }
        // The confirm dialog's reason (`_reason`, admin.js agConfirm) is the refund's reason.
        $reason = mb_substr(trim((string) (($b['reason'] ?? '') ?: ($b['_reason'] ?? ''))), 0, 300);
        $admin  = (int) ($_SESSION['admin_id'] ?? 0);

        if (($b['method'] ?? 'gateway') === 'manual') {
            $r = ShopRefunds::recordManual($id, $amount, $restock, $reason, $admin);
        } elseif ($this->payments === null) {
            $r = ['ok' => false, 'message' => 'The payment gateway is not available here.'];
        } else {
            $r = ShopRefunds::request($id, $amount, $restock, $reason, $admin, $this->payments);
        }

        if ($r['ok']) {
            $this->audit->record($admin, 'order.refund', 'order', $id,
                ['amount' => $amount, 'method' => (string) ($b['method'] ?? 'gateway')]);
            $_SESSION['flash_ok'] = $r['message'];
        } else {
            $_SESSION['flash_error'] = $r['message'];
        }
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** Call an order off: a pending one is cancelled, a paid one is refunded and cancelled. */
    public function cancel(Request $req, Response $res, array $args): Response
    {
        $id     = (int) ($args['id'] ?? 0);
        $b      = (array) $req->getParsedBody();
        $admin  = (int) ($_SESSION['admin_id'] ?? 0);
        $reason = mb_substr(trim((string) (($b['reason'] ?? '') ?: ($b['_reason'] ?? ''))), 0, 300);

        $r = $this->payments === null
            ? ['ok' => false, 'message' => 'The payment gateway is not available here.']
            : ShopRefunds::cancel($id, $reason, $admin, $this->payments,
                                  ($b['method'] ?? 'gateway') === 'manual', !empty($b['restock']), $this->mailer);

        if ($r['ok']) {
            $this->audit->record($admin, 'order.cancel', 'order', $id);
            $_SESSION['flash_ok'] = $r['message'];
        } else {
            $_SESSION['flash_error'] = $r['message'];
        }
        return $res->withHeader('Location', '/admin/shop/orders/' . $id)->withStatus(302);
    }

    // ══ collection ═══════════════════════════════════════════════════════════

    /** Ready to collect: emails the buyer where, when and their code. */
    public function ready(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $r  = ShopPickup::markReady($id, (int) ($_SESSION['admin_id'] ?? 0), $this->mailer);
        if ($r['ok']) $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'order.ready', 'order', $id);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        return $res->withHeader('Location', '/admin/shop/orders/' . $id)->withStatus(302);
    }

    /** Handed over: the buyer's code, or a reason recorded with the staff member's name. */
    public function collect(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $b  = (array) $req->getParsedBody();
        $admin = (int) ($_SESSION['admin_id'] ?? 0);
        $r  = ShopPickup::collect($id, (string) ($b['code'] ?? ''), (string) ($b['override'] ?? ''), $admin, $this->mailer);
        if ($r['ok']) {
            $this->audit->record($admin, 'order.collect', 'order', $id,
                ['override' => trim((string) ($b['code'] ?? '')) === '']);
        }
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        return $res->withHeader('Location', '/admin/shop/orders/' . $id)->withStatus(302);
    }

    /** The collection points: where buyers can pick an order up instead of having it delivered. */
    public function pickupPoints(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'admin/shop/pickup.twig', [
            'page_title' => 'Collection points — Admin',
            'admin_page' => 'shop-pickup',
            'points'     => ShopPickup::points(),
            'ready'      => ShopPickup::ready(),
        ]);
    }

    public function savePickupPoint(Request $req, Response $res): Response
    {
        $b    = (array) $req->getParsedBody();
        $back = '/admin/shop/pickup';
        if (!ShopPickup::ready()) {
            $_SESSION['flash_error'] = 'Collection points need the latest database update — run /__setup/migrate.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $name = mb_substr(trim((string) ($b['name'] ?? '')), 0, 120);
        if ($name === '') {
            $_SESSION['flash_error'] = 'A collection point needs a name buyers will recognise.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $now = Carbon::now()->toDateTimeString();
        $row = [
            'name'       => $name,
            'address'    => mb_substr(trim((string) ($b['address'] ?? '')), 0, 300) ?: null,
            'hours'      => mb_substr(trim((string) ($b['hours'] ?? '')), 0, 200) ?: null,
            'is_active'  => !empty($b['is_active']) ? 1 : 0,
            'sort_order' => ColumnRange::clamp((int) ($b['sort_order'] ?? 0), ColumnRange::INT, 0),
            'updated_at' => $now,
        ];
        $id = (int) ($b['point_id'] ?? 0);
        if ($id > 0 && DB::table('gates_shop_pickup_points')->where('id', $id)->exists()) {
            // Never deleted: orders name their point, and an order whose point vanished could not
            // say where it is waiting. Switch it off instead.
            DB::table('gates_shop_pickup_points')->where('id', $id)->update($row);
        } else {
            $id = (int) DB::table('gates_shop_pickup_points')->insertGetId($row + ['created_at' => $now]);
        }
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'shop.pickup.save', 'setting', $id);
        $_SESSION['flash_ok'] = $name . ' saved.';
        return $res->withHeader('Location', $back)->withStatus(302);
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
            // Clamped to the COLUMN, not to an invented product ceiling. Both were
            // lower-bounded only, and SQLite stores whatever it is handed — so an
            // operator typing a long number saved cleanly in dev and every test, and
            // failed in production alone with "Out of range value for column".
            'max_uses'      => trim((string) ($b['max_uses'] ?? '')) !== ''
                ? ColumnRange::clamp((int) $b['max_uses'], ColumnRange::INT_UNSIGNED, 1) : null,
            'max_per_email' => ColumnRange::clamp((int) ($b['max_per_email'] ?? 1),
                                                  ColumnRange::SMALLINT_UNSIGNED, 1),
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
