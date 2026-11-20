<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What the shop has done: the "today" numbers that drive action, and sales over a date range.
 *
 * ── THE FOUR NUMBERS ────────────────────────────────────────────────────────
 *
 * Research for a small volunteer team (SHOP-MANAGEMENT-PLAN §1): orders to prepare; orders
 * ready or shipped and not yet collected or delivered; options low or out of stock; and sales
 * today and this week, NET of what the payment provider kept and of refunds. Anything else on
 * the dashboard is a link, not a number.
 *
 * ── MONEY, AND WHICH FIGURE IS WHICH ────────────────────────────────────────
 *
 *  · gross   — what was charged on orders PAID in the range (`paid_at`), online and in person;
 *  · refunds — refunds SETTLED in the range (`processed_at`), whenever the order was paid —
 *              money goes back on the day it goes back;
 *  · fees    — the gateway's fee as it reported it (`fee_naira`), or, where it did not, an
 *              ESTIMATE from the platform's fee schedule (GatewayFee), counted separately so a
 *              report can say how much of the fee line is a guess. A till sale has no gateway
 *              fee (a card terminal's charge is the terminal provider's, outside this platform);
 *  · net     — gross − refunds − fees.
 *
 * Dates are compared as plain 'Y-m-d H:i:s' strings on both drivers (CLAUDE.md, the stack).
 * Order lines are JSON, so the per-product report reads them in PHP — in chunks, never one query
 * per order — over at most MAX_DAYS.
 */
final class ShopReports
{
    public const MAX_DAYS = 366;
    public const DEFAULT_DAYS = 30;

    /** @return array{0:string,1:string} a clamped [from, to] pair of Y-m-d */
    public static function range(string $from, string $to): array
    {
        $ok = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $t = $ok($to) ? Carbon::parse($to) : Carbon::now();
        $f = $ok($from) ? Carbon::parse($from) : $t->copy()->subDays(self::DEFAULT_DAYS - 1);
        if ($f->greaterThan($t)) [$f, $t] = [$t, $f];
        if ($f->diffInDays($t) > self::MAX_DAYS - 1) $f = $t->copy()->subDays(self::MAX_DAYS - 1);
        return [$f->toDateString(), $t->toDateString()];
    }

    /**
     * Money over a range.
     *
     * @return array{orders:int,gross:int,refunds:int,fees:int,fees_estimated:int,net:int,online:int,in_person:int}
     */
    public static function money(string $from, string $to): array
    {
        $a = $from . ' 00:00:00';
        $b = $to . ' 23:59:59';
        $out = ['orders' => 0, 'gross' => 0, 'refunds' => 0, 'fees' => 0, 'fees_estimated' => 0, 'net' => 0,
                'online' => 0, 'in_person' => 0];
        $hasFee = SchemaHas::column('gates_orders', 'fee_naira');
        $hasCh  = SchemaHas::column('gates_orders', 'channel');

        $cols = ['id', 'subtotal_naira', 'provider'];
        if ($hasFee) $cols[] = 'fee_naira';
        if ($hasCh)  $cols[] = 'channel';
        DB::table('gates_orders')->whereIn('status', ['paid', 'refunded'])
            ->whereNotNull('paid_at')->where('paid_at', '>=', $a)->where('paid_at', '<=', $b)
            ->orderBy('id')->select($cols)->chunk(500, static function ($rows) use (&$out, $hasFee, $hasCh): void {
                foreach ($rows as $r) {
                    $charged = (int) $r->subtotal_naira;
                    $pos = $hasCh && (string) ($r->channel ?? '') === 'pos';
                    $out['orders']++;
                    $out['gross'] += $charged;
                    $out[$pos ? 'in_person' : 'online'] += $charged;
                    if ($pos) continue;
                    if ($hasFee && $r->fee_naira !== null) {
                        $out['fees'] += (int) $r->fee_naira;
                    } else {
                        $est = GatewayFee::fee($charged);
                        $out['fees'] += $est;
                        $out['fees_estimated'] += $est;
                    }
                }
            });

        if (SchemaHas::table('gates_shop_refunds')) {
            $out['refunds'] = (int) DB::table('gates_shop_refunds')->where('status', ShopRefunds::ST_PROCESSED)
                ->where('processed_at', '>=', $a)->where('processed_at', '<=', $b)->sum('amount_naira');
        }
        $out['net'] = $out['gross'] - $out['refunds'] - $out['fees'];
        return $out;
    }

    /**
     * Units and takings per product and option over a range, best sellers first.
     *
     * Units are what was sold less what came back to stock on a refund; takings are the line
     * totals as charged (before an order-level discount, which is not split across lines).
     *
     * @return list<array{product:string,option:string,units:int,returned:int,takings:int,orders:int}>
     */
    public static function products(string $from, string $to): array
    {
        $rows = [];
        DB::table('gates_orders')->whereIn('status', ['paid', 'refunded'])
            ->whereNotNull('paid_at')->where('paid_at', '>=', $from . ' 00:00:00')->where('paid_at', '<=', $to . ' 23:59:59')
            ->orderBy('id')->select(['id', 'items_json'])->chunk(500, static function ($orders) use (&$rows): void {
                foreach ($orders as $o) {
                    foreach (json_decode((string) $o->items_json, true) ?: [] as $l) {
                        $key = (string) ($l['slug'] ?? $l['name'] ?? '?') . '|' . (int) ($l['variant_id'] ?? 0);
                        $rows[$key] ??= ['product' => (string) ($l['name'] ?? $l['slug'] ?? '?'),
                                         'option' => (string) ($l['variant'] ?? ''), 'units' => 0, 'returned' => 0,
                                         'takings' => 0, 'orders' => 0];
                        $rows[$key]['units']    += (int) ($l['qty'] ?? 0);
                        $rows[$key]['returned'] += (int) ($l['restocked'] ?? 0);
                        $rows[$key]['takings']  += (int) ($l['line_total'] ?? 0);
                        $rows[$key]['orders']++;
                    }
                }
            });
        $rows = array_values($rows);
        usort($rows, static fn ($x, $y) => [$y['takings'], $y['units'], $x['product']] <=> [$x['takings'], $x['units'], $y['product']]);
        return $rows;
    }

    /**
     * The dashboard's numbers, for now.
     *
     * @return array<string,mixed>
     */
    public static function today(): array
    {
        $hasFul = SchemaHas::column('gates_orders', 'fulfilment');
        $prep = $waiting = $short = 0;
        if ($hasFul) {
            $prep = (int) DB::table('gates_orders')->where('status', 'paid')->where(static function ($w): void {
                $w->whereNull('fulfilment')->orWhereIn('fulfilment', ['unfulfilled', 'packed']);
            })->count();
            $waiting = (int) DB::table('gates_orders')->where('status', 'paid')->whereIn('fulfilment', ['ready', 'shipped'])->count();
        }
        if (SchemaHas::column('gates_orders', 'stock_short')) {
            $short = (int) DB::table('gates_orders')->where('status', 'paid')->where('stock_short', 1)->count();
        }
        $low = SchemaHas::table('gates_shop_stock_moves') ? ShopStock::low() : [];
        $now = Carbon::now();
        $week = $now->copy()->startOfWeek()->toDateString();       // Monday
        $refundsWaiting = SchemaHas::table('gates_shop_refunds')
            ? (int) DB::table('gates_shop_refunds')->where('status', ShopRefunds::ST_PENDING)->count() : 0;

        return [
            'to_prepare'  => $prep,
            'not_handed'  => $waiting,
            'short'       => $short,
            'out'         => count(array_filter($low, static fn ($r) => $r['state'] === 'out')),
            'low'         => count(array_filter($low, static fn ($r) => $r['state'] === 'low')),
            'low_rows'    => array_slice($low, 0, 8),
            'pending_pay' => (int) DB::table('gates_orders')->where('status', 'pending')->count(),
            'refunds_waiting' => $refundsWaiting,
            'today'       => self::money($now->toDateString(), $now->toDateString()),
            'week'        => self::money($week, $now->toDateString()),
            'week_from'   => $week,
        ];
    }

    /**
     * Every order paid in the range, one row each, for accounting.
     *
     * @return \Generator<int, list<scalar|null>>
     */
    public static function orderRows(string $from, string $to): \Generator
    {
        $hasFee = SchemaHas::column('gates_orders', 'fee_naira');
        $hasCh  = SchemaHas::column('gates_orders', 'channel');
        $q = DB::table('gates_orders')->whereIn('status', ['paid', 'refunded'])->whereNotNull('paid_at')
            ->where('paid_at', '>=', $from . ' 00:00:00')->where('paid_at', '<=', $to . ' 23:59:59')->orderBy('id');
        foreach ($q->lazy(500) as $o) {
            $pos = $hasCh && (string) ($o->channel ?? '') === 'pos';
            $real = $hasFee && ($o->fee_naira ?? null) !== null;
            $fee = $pos ? 0 : ($real ? (int) $o->fee_naira : GatewayFee::fee((int) $o->subtotal_naira));
            $refunded = (int) ($o->refunded_naira ?? 0);
            yield [
                (string) $o->reference, (string) $o->created_at, (string) $o->paid_at, (string) $o->status,
                $pos ? 'in person' : 'online', $pos ? (string) ($o->pos_method ?? '') : (string) ($o->provider ?? ''),
                (string) $o->name, (string) $o->email,
                (int) ($o->goods_naira ?? $o->subtotal_naira), (int) ($o->discount_naira ?? 0), (int) ($o->shipping_naira ?? 0),
                (int) $o->subtotal_naira, $refunded, $fee, $pos ? 'none' : ($real ? 'reported' : 'estimated'),
                (int) $o->subtotal_naira - $refunded - $fee, (string) ($o->fulfilment ?? ''),
            ];
        }
    }

    public const ORDER_COLUMNS = ['Reference', 'Placed', 'Paid', 'Status', 'Channel', 'Paid via', 'Buyer', 'Email',
        'Goods (NGN)', 'Discount (NGN)', 'Delivery (NGN)', 'Charged (NGN)', 'Refunded (NGN)', 'Fee (NGN)', 'Fee is',
        'Net (NGN)', 'Delivery state'];
}
