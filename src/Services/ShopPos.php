<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\{OptionalColumn, SchemaHas};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Selling in person — at an event table or the office — from the same stock as the website,
 * and accounting for the cash.
 *
 * ── ONE STOCK POOL, ONE ORDER TABLE ─────────────────────────────────────────
 *
 * A sale here is a `gates_orders` row with `channel = 'pos'`: paid on the spot, handed over on
 * the spot (`collected`), its units taken through the ledger with reason `pos`. So the online
 * shop cannot sell a shirt the stand already sold, a till sale can be refunded through the same
 * order page (outside the gateway — there was no gateway), and every report counts it once.
 *
 * ── A QUEUE AT A TABLE MUST NOT STALL ───────────────────────────────────────
 *
 * The website refuses to oversell; the till does not. If the count says none and a shirt is in
 * somebody's hand, the count is wrong, and refusing the sale makes the queue pay for it. The
 * sale goes through, the stock goes below zero, and the screen says which options did — the
 * stock-take is where the count gets fixed.
 *
 * ── THE DRAWER ──────────────────────────────────────────────────────────────
 *
 * A cash sale must name an open cash session. At close: expected = opening float + cash sales
 * in the session − cash handed back on those sales (refunds recorded outside the gateway), and
 * the counted figure is stored beside it with the difference — what somebody signed off, not a
 * figure recomputed later from rows that may since have changed.
 */
final class ShopPos
{
    public const METHODS = ['cash' => 'Cash', 'transfer' => 'Bank transfer', 'terminal' => 'Card terminal'];
    /** At most this many lines in one sale; a till is not a bulk import. */
    public const MAX_LINES = 20;
    public const PREFIX = 'AFG-SHP-POS-';

    public static function ready(): bool
    {
        return SchemaHas::table('gates_shop_cash_sessions') && SchemaHas::column('gates_orders', 'channel');
    }

    // ══ sessions ═════════════════════════════════════════════════════════════

    /** @return array{ok:bool,message:string,id?:int} */
    public static function open(string $label, int $float, int $adminId): array
    {
        $label = mb_substr(trim($label), 0, 120);
        if ($label === '') return ['ok' => false, 'message' => 'Name the session — the event or the day — so its cash can be found later.'];
        if ($float < 0 || $float > 10_000_000) return ['ok' => false, 'message' => 'The opening float must be between ₦0 and ₦10,000,000.'];
        $id = (int) DB::table('gates_shop_cash_sessions')->insertGetId([
            'label' => $label, 'float_naira' => $float, 'opened_by' => $adminId > 0 ? $adminId : null,
            'opened_at' => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'id' => $id, 'message' => '"' . $label . '" is open with a float of ₦' . number_format($float) . '.'];
    }

    /** @return list<array<string,mixed>> */
    public static function openSessions(): array
    {
        if (!self::ready()) return [];
        return DB::table('gates_shop_cash_sessions')->whereNull('closed_at')->orderByDesc('id')->get()
            ->map(static fn ($r): array => (array) $r)->all();
    }

    /** @return list<array<string,mixed>> the most recent sessions, open and closed */
    public static function recentSessions(int $limit = 20): array
    {
        if (!self::ready()) return [];
        return DB::table('gates_shop_cash_sessions')->orderByDesc('id')->limit($limit)->get()
            ->map(static fn ($r): array => (array) $r)->all();
    }

    /**
     * Everything a session took, by method, and what the drawer should hold.
     *
     * @return array{session:array<string,mixed>,sales:int,by_method:array<string,int>,cash_back:int,expected:int}|null
     */
    public static function summary(int $sessionId): ?array
    {
        if (!self::ready()) return null;
        $s = DB::table('gates_shop_cash_sessions')->where('id', $sessionId)->first();
        if (!$s) return null;

        // One grouped query, not one per order.
        $rows = DB::table('gates_orders')->where('cash_session_id', $sessionId)->whereIn('status', ['paid', 'refunded'])
            ->groupBy('pos_method')->selectRaw('pos_method, COUNT(*) AS n, SUM(subtotal_naira) AS total')->get();
        $by = array_fill_keys(array_keys(self::METHODS), 0);
        $n = 0;
        foreach ($rows as $r) {
            $by[(string) $r->pos_method] = (int) $r->total;
            $n += (int) $r->n;
        }
        // Cash handed back on this session's cash sales (a refund recorded outside the gateway).
        $back = 0;
        if (SchemaHas::table('gates_shop_refunds')) {
            $back = (int) DB::table('gates_shop_refunds as r')->join('gates_orders as o', 'o.id', '=', 'r.order_id')
                ->where('o.cash_session_id', $sessionId)->where('o.pos_method', 'cash')
                ->where('r.method', ShopRefunds::METHOD_MANUAL)->where('r.status', ShopRefunds::ST_PROCESSED)
                ->sum('r.amount_naira');
        }
        return ['session' => (array) $s, 'sales' => $n, 'by_method' => $by, 'cash_back' => $back,
                'expected' => (int) $s->float_naira + $by['cash'] - $back];
    }

    /** @return array{ok:bool,message:string} */
    public static function close(int $sessionId, int $counted, string $note, int $adminId): array
    {
        $sum = self::summary($sessionId);
        if ($sum === null) return ['ok' => false, 'message' => 'That cash session could not be found.'];
        if ($sum['session']['closed_at'] !== null) return ['ok' => false, 'message' => 'That session was already closed.'];
        if ($counted < 0) return ['ok' => false, 'message' => 'Type what is in the drawer, ₦0 or more.'];

        $diff = $counted - $sum['expected'];
        if ($diff !== 0 && mb_strlen(trim($note)) < 3) {
            return ['ok' => false, 'message' => 'The drawer is ₦' . number_format(abs($diff)) . ($diff < 0 ? ' short' : ' over')
                . ' — say what you know about it in the note before closing.'];
        }
        $n = DB::table('gates_shop_cash_sessions')->where('id', $sessionId)->whereNull('closed_at')->update([
            'closed_at' => Carbon::now()->toDateTimeString(), 'closed_by' => $adminId > 0 ? $adminId : null,
            'expected_naira' => $sum['expected'], 'counted_naira' => $counted, 'difference_naira' => $diff,
            'note' => mb_substr(trim($note), 0, 300) ?: null,
        ]);
        if ($n === 0) return ['ok' => false, 'message' => 'That session was closed a moment ago.'];
        if ($diff !== 0) {
            Notifier::adminAlert(null, 'Cash session closed ' . ($diff < 0 ? 'SHORT' : 'over'),
                'Session: ' . $sum['session']['label'] . "\nExpected: ₦" . number_format($sum['expected'])
                . "\nCounted:  ₦" . number_format($counted) . "\nNote: " . trim($note));
        }
        return ['ok' => true, 'message' => 'Closed. Expected ₦' . number_format($sum['expected']) . ', counted ₦'
            . number_format($counted) . ($diff === 0 ? ' — it balances.' : ', ' . ($diff < 0 ? 'short' : 'over') . ' by ₦' . number_format(abs($diff)) . '.')];
    }

    // ══ the sale ═════════════════════════════════════════════════════════════

    /**
     * One in-person sale. The whole basket is priced and checked before anything is written.
     *
     * @param list<array{product_id:int,variant_id:int,qty:int}> $basket
     * @return array{ok:bool,message:string,order_id?:int,reference?:string,below_zero?:list<string>}
     */
    public static function sell(array $basket, string $method, ?int $sessionId, string $buyerName, string $buyerEmail,
                                string $note, int $adminId): array
    {
        if (!self::ready()) return ['ok' => false, 'message' => 'In-person sales need the latest database update — run /__setup/migrate.'];
        if (!isset(self::METHODS[$method])) return ['ok' => false, 'message' => 'Say how it was paid.'];
        $basket = array_values(array_filter($basket, static fn ($l) => (int) ($l['qty'] ?? 0) > 0 && (int) ($l['product_id'] ?? 0) > 0));
        if ($basket === []) return ['ok' => false, 'message' => 'Add at least one item.'];
        if (count($basket) > self::MAX_LINES) return ['ok' => false, 'message' => 'At most ' . self::MAX_LINES . ' lines in one sale.'];
        $buyerEmail = strtolower(trim($buyerEmail));
        if ($buyerEmail !== '' && !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'That email address does not look right — leave it blank if the buyer gave none.'];

        $session = null;
        if ($sessionId !== null && $sessionId > 0) {
            $session = DB::table('gates_shop_cash_sessions')->where('id', $sessionId)->whereNull('closed_at')->first();
            if (!$session) return ['ok' => false, 'message' => 'That cash session is closed or does not exist.'];
        }
        if ($method === 'cash' && $session === null) {
            return ['ok' => false, 'message' => 'A cash sale goes into a drawer — open a cash session first, or choose it.'];
        }

        // Price every line from the catalogue (base price + the option's delta) before writing.
        $lines = [];
        $goods = 0;
        foreach ($basket as $b) {
            $qty = min(1000, (int) $b['qty']);
            $p = DB::table('gates_products')->where('id', (int) $b['product_id'])->first();
            if (!$p) return ['ok' => false, 'message' => 'One of the items no longer exists. Nothing was sold.'];
            $vid = (int) ($b['variant_id'] ?? 0);
            $v = null;
            if ($vid > 0) {
                $v = DB::table('gates_product_variants')->where('id', $vid)->where('product_id', (int) $p->id)->first();
                if (!$v) return ['ok' => false, 'message' => 'Pick a size or option for ' . $p->name . '. Nothing was sold.'];
            } elseif (SchemaHas::table('gates_product_variants')
                      && DB::table('gates_product_variants')->where('product_id', (int) $p->id)->where('is_active', 1)->exists()) {
                return ['ok' => false, 'message' => 'Pick a size or option for ' . $p->name . '. Nothing was sold.'];
            }
            $unit = max(0, (int) $p->price_naira + (int) ($v->price_delta_naira ?? 0));
            $lines[] = ['slug' => (string) $p->slug, 'name' => (string) $p->name, 'variant' => $v ? (string) $v->label : '',
                        'variant_id' => $vid, 'qty' => $qty, 'unit' => $unit, 'line_total' => $unit * $qty, 'drawn' => 0];
            $goods += $unit * $qty;
        }
        if ($goods < 1) return ['ok' => false, 'message' => 'That basket comes to ₦0. Nothing was sold.'];

        $ref = self::PREFIX . strtoupper(bin2hex(random_bytes(4)));
        $now = Carbon::now()->toDateTimeString();
        $below = [];
        $orderId = 0;
        DB::connection()->transaction(static function () use (&$lines, &$below, &$orderId, $ref, $now, $goods, $method,
                                                                $session, $buyerName, $buyerEmail, $note, $adminId): void {
            $orderId = (int) DB::table('gates_orders')->insertGetId(OptionalColumn::filter('gates_orders', [
                'reference' => $ref, 'email' => $buyerEmail, 'name' => mb_substr(trim($buyerName), 0, 160) ?: 'In-person sale',
                'address' => 'Sold in person' . ($session ? ' — ' . $session->label : ''),
                'items_json' => json_encode($lines, JSON_UNESCAPED_UNICODE),
                'subtotal_naira' => $goods, 'goods_naira' => $goods, 'shipping_naira' => 0, 'discount_naira' => 0,
                'status' => 'paid', 'paid_at' => $now, 'created_at' => $now,
                'fulfilment' => 'collected', 'fulfilled_at' => $now, 'collected_at' => $now,
                'collected_by' => $adminId > 0 ? $adminId : null,
                'channel' => 'pos', 'pos_method' => $method, 'cash_session_id' => $session ? (int) $session->id : null,
                'tracking_note' => trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            ], ['goods_naira', 'shipping_naira', 'discount_naira', 'fulfilment', 'fulfilled_at', 'collected_at',
                'collected_by', 'tracking_note']));

            foreach ($lines as $i => $l) {
                if (ShopStock::move($l['slug'], (int) $l['variant_id'], -$l['qty'], 'pos', $ref, $orderId, $adminId, true)) {
                    $lines[$i]['drawn'] = $l['qty'];
                    $table = (int) $l['variant_id'] > 0 ? 'gates_product_variants' : 'gates_products';
                    $left  = (int) DB::table($table)->where((int) $l['variant_id'] > 0 ? 'id' : 'slug',
                                 (int) $l['variant_id'] > 0 ? (int) $l['variant_id'] : $l['slug'])->value('stock');
                    if ($left < 0) $below[] = $l['name'] . ($l['variant'] !== '' ? ' (' . $l['variant'] . ')' : '') . ' is now ' . $left;
                }
            }
            DB::table('gates_orders')->where('id', $orderId)->update(['items_json' => json_encode($lines, JSON_UNESCAPED_UNICODE)]);
        });

        try { ShopCatalogue::countSales($lines); } catch (\Throwable) {}
        ShopOrderLog::add($orderId, 'pos', 'Sold in person — ' . self::METHODS[$method] . ', ₦' . number_format($goods)
            . ($session ? ' (' . $session->label . ')' : '') . '.', $adminId);

        return ['ok' => true, 'order_id' => $orderId, 'reference' => $ref, 'below_zero' => $below,
                'message' => 'Sold: ₦' . number_format($goods) . ' by ' . strtolower(self::METHODS[$method]) . ' — ' . $ref . '.'
                    . ($below !== [] ? ' The count went below zero for: ' . implode('; ', $below) . ' — recount it on the stock page.' : '')];
    }

    /** @return list<array<string,mixed>> what the till can sell: every active product and option */
    public static function catalogue(): array
    {
        $out = [];
        foreach (ShopStock::rows() as $r) {
            $out[] = ['key' => $r['product_id'] . ':' . $r['variant_id'],
                      'label' => $r['name'] . ($r['option'] !== '' ? ' — ' . $r['option'] : '')
                          . ($r['stock'] === null ? '' : ' (' . $r['stock'] . ' left)')];
        }
        return $out;
    }
}
