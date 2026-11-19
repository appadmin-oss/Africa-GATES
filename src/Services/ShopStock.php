<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Every change to a stock count, as a ledger entry with a reason and a person.
 *
 * Stock used to be a number that the product form overwrote, with one generic `product.update`
 * audit row to show for it. So "we had twelve large on Monday and four on Friday and sold six"
 * had no answer — two shirts left the building and nothing anywhere said whether they were
 * sold, given away, damaged or miscounted. Every path that changes a count now goes through
 * {@see move()} or {@see set()}, which change the number AND write one row of
 * `gates_shop_stock_moves` saying how much, why, who, and against which order.
 *
 * The reasons are a closed list ({@see REASONS}); the column is a VARCHAR, so this list is the
 * constraint (an ENUM value outside its set is `Data truncated` on MySQL and nothing on SQLite).
 *
 * A count of NULL means untracked, and stays untracked: nothing here turns "we don't count
 * these" into a number, because a 0 would read as sold out.
 */
final class ShopStock
{
    public const REASONS = [
        'sale'       => 'Sold online',
        'pos'        => 'Sold in person',
        'refund'     => 'Returned (refund)',
        'cancel'     => 'Returned (cancelled)',
        'received'   => 'Received',
        'damaged'    => 'Damaged',
        'giveaway'   => 'Given away',
        'recount'    => 'Stock-take',
        'correction' => 'Corrected in the product form',
    ];

    /** The reasons a person may pick on the adjust form; the others are written by the system. */
    public const MANUAL = ['received', 'damaged', 'giveaway', 'recount'];

    /** The setting holding the shop-wide low-stock threshold, used where a variant has none. */
    public const LOW_KEY = 'shop_low_stock_default';
    public const LOW_DEFAULT = 3;

    /**
     * Add (positive) or take (negative) units, and record it.
     *
     * Tracked counts only: an untracked product or variant returns false and writes nothing.
     * A negative move may take a count below zero only where `$allowNegative` (the in-person
     * till: a queue at a table must not stall on a miscount) — otherwise it floors at what is
     * there and records what was actually taken.
     */
    public static function move(string $slug, int $variantId, int $delta, string $reason, string $note = '',
                                ?int $orderId = null, ?int $adminId = null, bool $allowNegative = false): bool
    {
        if ($delta === 0 || !isset(self::REASONS[$reason])) return false;
        [$table, $productId, $vid] = self::target($slug, $variantId);
        if ($table === null) return false;

        $q = static fn () => DB::table($table)->where('id', $vid > 0 ? $vid : $productId);
        $have = $q()->value('stock');
        if ($have === null) return false;

        if ($delta > 0 || $allowNegative) {
            $q()->whereNotNull('stock')->increment('stock', $delta);
            $applied = $delta;
        } else {
            // Compare-and-set on the value just read, retried, so a concurrent sale is never
            // counted twice; what was actually taken is what is recorded.
            $applied = 0;
            for ($try = 0; $try < 3; $try++) {
                $left = $q()->value('stock');
                if ($left === null) return false;
                $take = min(-$delta, max(0, (int) $left));
                if ($take === 0) break;
                if ($q()->where('stock', (int) $left)->update(['stock' => (int) $left - $take]) > 0) {
                    $applied = -$take;
                    break;
                }
            }
            if ($applied === 0) return false;
        }

        self::record($productId, $vid, $applied, (int) $q()->value('stock'), $reason, $note, $orderId, $adminId);
        return true;
    }

    /**
     * Set a count to a number somebody counted, recording the difference.
     *
     * The stock-take and the product form both land here: the form posts a number, and the
     * difference between it and what the ledger thought is written as a `correction` (or a
     * `recount` from the stock-take screen) — so a typed-over number is an entry with a name
     * on it, not a silent rewrite.
     *
     * @return int|null the difference written, or null when nothing was tracked to set
     */
    public static function set(int $productId, int $variantId, ?int $counted, string $reason, string $note = '',
                               ?int $adminId = null): ?int
    {
        if (!isset(self::REASONS[$reason])) return null;
        $table = $variantId > 0 ? 'gates_product_variants' : 'gates_products';
        $id    = $variantId > 0 ? $variantId : $productId;
        $row   = DB::table($table)->where('id', $id)->first(['stock']);
        if (!$row) return null;

        $before = $row->stock === null ? null : (int) $row->stock;
        if ($counted === null) {
            // Switching tracking off is a setting, not a movement of goods.
            if ($before !== null) DB::table($table)->where('id', $id)->update(['stock' => null]);
            return null;
        }
        $counted = max(0, $counted);
        if ($before === $counted) return 0;

        DB::table($table)->where('id', $id)->update(['stock' => $counted]);
        // Turning tracking ON records the whole starting count, so the ledger adds up to the
        // number on the shelf from the first entry.
        $diff = $counted - ($before ?? 0);
        self::record($productId, $variantId, $diff, $counted, $reason,
                     $before === null ? trim('Started counting. ' . $note) : $note, null, $adminId);
        return $diff;
    }

    /** One ledger row. Never throws: the count has already changed and that is the truth. */
    public static function record(int $productId, int $variantId, int $delta, ?int $after, string $reason,
                                  string $note = '', ?int $orderId = null, ?int $adminId = null): void
    {
        if (!SchemaHas::table('gates_shop_stock_moves')) return;
        try {
            DB::table('gates_shop_stock_moves')->insert([
                'product_id' => $productId,
                'variant_id' => $variantId > 0 ? $variantId : null,
                'delta'      => $delta,
                'after_qty'  => $after,
                'reason'     => $reason,
                'note'       => trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null,
                'order_id'   => $orderId !== null && $orderId > 0 ? $orderId : null,
                'admin_id'   => $adminId !== null && $adminId > 0 ? $adminId : null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[shop] stock ledger row not written: ' . $e->getMessage());
        }
    }

    /**
     * Which row holds the count for a line: the variant when there is one, else the product.
     *
     * @return array{0:?string,1:int,2:int} table, product id, variant id
     */
    private static function target(string $slug, int $variantId): array
    {
        if ($variantId > 0) {
            $pid = (int) (DB::table('gates_product_variants')->where('id', $variantId)->value('product_id') ?? 0);
            return $pid > 0 ? ['gates_product_variants', $pid, $variantId] : [null, 0, 0];
        }
        $pid = (int) (DB::table('gates_products')->where('slug', $slug)->value('id') ?? 0);
        return $pid > 0 ? ['gates_products', $pid, 0] : [null, 0, 0];
    }

    /** Record a line of an order against whichever row holds its count (the sale's half). */
    public static function recordLine(string $slug, int $variantId, int $delta, string $reason, ?int $orderId, string $note = ''): void
    {
        if ($delta === 0) return;
        [$table, $productId, $vid] = self::target($slug, $variantId);
        if ($table === null) return;
        $after = DB::table($table)->where('id', $vid > 0 ? $vid : $productId)->value('stock');
        self::record($productId, $vid, $delta, $after === null ? null : (int) $after, $reason, $note, $orderId, null);
    }

    // ══ low stock ════════════════════════════════════════════════════════════

    /** The shop-wide level, where a variant or product sets none. One reader of the setting. */
    public static function lowLevel(): int
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', self::LOW_KEY)->value('value');
        } catch (\Throwable) {
            $v = null;
        }
        return $v !== null && is_numeric(trim((string) $v)) ? max(0, min(100000, (int) $v)) : self::LOW_DEFAULT;
    }

    public static function setLowLevel(int $level): void
    {
        $level = max(0, min(100000, $level));
        DB::table('gates_settings')->updateOrInsert(['key_name' => self::LOW_KEY], ['value' => (string) $level]);
    }

    /**
     * Every counted thing, one row each: a variant, or a product that has none. Active
     * products only unless asked — an archived product's shelf is not today's problem.
     *
     * Two queries, never one per product (a per-row query inside a list is the N+1 this codebase
     * has had to remove more than once).
     *
     * @return list<array{product_id:int,variant_id:int,name:string,option:string,sku:string,
     *                    stock:?int,low:int,own_low:?int,state:string,slug:string}>
     */
    public static function rows(bool $trackedOnly = false, string $search = ''): array
    {
        $default = self::lowLevel();
        $hasLow  = SchemaHas::column('gates_product_variants', 'low_stock');
        $products = DB::table('gates_products')->where('is_active', 1);
        if (SchemaHas::column('gates_products', 'archived_at')) $products->whereNull('archived_at');
        if ($search !== '') {
            $like = \AfricaGates\Support\Like::contains($search);
            $products->whereRaw(\AfricaGates\Support\Like::clause('name'), [$like]);
        }
        $products = $products->orderBy('name')->get()->keyBy('id');
        if ($products->isEmpty()) return [];

        $variants = SchemaHas::table('gates_product_variants')
            ? DB::table('gates_product_variants')->whereIn('product_id', $products->keys()->all())
                ->where('is_active', 1)->orderBy('product_id')->orderBy('sort_order')->get()->groupBy('product_id')
            : collect();

        $out = [];
        foreach ($products as $p) {
            $vs = $variants->get($p->id);
            if ($vs !== null && count($vs) > 0) {
                foreach ($vs as $v) {
                    $out[] = self::row($p, (int) $v->id, (string) $v->label
                        . (trim((string) ($v->label2 ?? '')) !== '' ? ' / ' . $v->label2 : ''),
                        (string) ($v->sku ?? ''), $v->stock, $hasLow ? $v->low_stock : null, $default);
                }
            } else {
                $out[] = self::row($p, 0, '', '', $p->stock, $hasLow ? ($p->low_stock ?? null) : null, $default);
            }
        }
        if ($trackedOnly) $out = array_values(array_filter($out, static fn (array $r): bool => $r['stock'] !== null));
        return $out;
    }

    /** @return array<string,mixed> */
    private static function row(object $p, int $vid, string $option, string $sku, mixed $stock, mixed $ownLow, int $default): array
    {
        $stock = $stock === null ? null : (int) $stock;
        $low   = $ownLow === null ? $default : (int) $ownLow;
        return [
            'product_id' => (int) $p->id, 'variant_id' => $vid, 'name' => (string) $p->name, 'slug' => (string) $p->slug,
            'option' => $option, 'sku' => $sku, 'stock' => $stock, 'low' => $low,
            'own_low' => $ownLow === null ? null : (int) $ownLow,
            // Untracked is never low: it is "we don't count these", not "we have none".
            'state' => $stock === null ? 'untracked' : ($stock <= 0 ? 'out' : ($stock <= $low ? 'low' : 'ok')),
        ];
    }

    /** @return list<array<string,mixed>> what is out or running low, out first */
    public static function low(): array
    {
        $rows = array_values(array_filter(self::rows(true), static fn (array $r): bool => in_array($r['state'], ['out', 'low'], true)));
        usort($rows, static fn (array $a, array $b): int => [$a['state'] === 'out' ? 0 : 1, $a['stock'], $a['name']]
                                                          <=> [$b['state'] === 'out' ? 0 : 1, $b['stock'], $b['name']]);
        return $rows;
    }

    /**
     * Set a variant's (or a variant-less product's) own level. Null hands it back to the default.
     */
    public static function setOwnLow(int $productId, int $variantId, ?int $level): void
    {
        $table = $variantId > 0 ? 'gates_product_variants' : 'gates_products';
        if (!SchemaHas::column($table, 'low_stock')) return;
        DB::table($table)->where('id', $variantId > 0 ? $variantId : $productId)
            ->update(['low_stock' => $level === null ? null : max(0, min(100000, $level))]);
    }

    // ══ history ══════════════════════════════════════════════════════════════

    /**
     * A product's ledger, newest first, with who, the option's name and the order reference.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $productId, int $limit = 300): array
    {
        if (!SchemaHas::table('gates_shop_stock_moves')) return [];
        return DB::table('gates_shop_stock_moves as m')
            ->leftJoin('gates_admins as a', 'a.id', '=', 'm.admin_id')
            ->leftJoin('gates_product_variants as v', 'v.id', '=', 'm.variant_id')
            ->leftJoin('gates_orders as o', 'o.id', '=', 'm.order_id')
            ->where('m.product_id', $productId)->orderByDesc('m.id')->limit($limit)
            ->get(['m.*', 'a.name as admin_name', 'v.label as variant_label', 'o.reference as order_ref'])
            ->map(static fn ($r): array => (array) $r + ['label' => self::REASONS[(string) $r->reason] ?? (string) $r->reason])
            ->all();
    }

    // ══ the daily digest ═════════════════════════════════════════════════════

    /** The setting that records the last day a digest was claimed (Y-m-d). */
    public const DIGEST_KEY = 'shop_stock_digest_day';
    /** Not before this hour: the digest is read with the morning's first look at the shop. */
    public const DIGEST_HOUR = 7;

    /**
     * Once a day, after DIGEST_HOUR, one email listing what is out of stock and running low.
     *
     * Run on every maintenance tick rather than in the 06:00 window: a webcron that misses that
     * quarter-hour would skip the day. The day is CLAIMED before anything is sent (a conditional
     * write on the setting), so two overlapping ticks send one email; a day with nothing low is
     * claimed and sends nothing — an email that says "all fine" every morning is one people stop
     * opening, which is when it stops working.
     *
     * @return int 1 when a digest went to the transport, else 0
     */
    public static function digest(?OtpService $mailer): int
    {
        $now = Carbon::now();
        if ($now->hour < self::DIGEST_HOUR || !SchemaHas::table('gates_shop_stock_moves')) return 0;
        $today = $now->toDateString();
        if (!self::claimDay($today)) return 0;

        $low = self::low();
        if ($low === []) return 0;

        $out  = array_filter($low, static fn (array $r): bool => $r['state'] === 'out');
        $line = static fn (array $r): string => '  ' . $r['name'] . ($r['option'] !== '' ? ' (' . $r['option'] . ')' : '')
            . ' — ' . (int) $r['stock'] . ' left' . ($r['state'] === 'low' ? ' (low at ' . $r['low'] . ')' : '');
        $body = count($out) . ' out of stock, ' . (count($low) - count($out)) . " running low.

"
            . implode("
", array_map($line, $low))
            . "

Stock-take and levels: " . rtrim(\AfricaGates\Support\SiteUrl::base(), '/') . '/admin/shop/stock';
        Notifier::adminAlert($mailer, 'Shop stock: ' . count($out) . ' out, ' . (count($low) - count($out)) . ' low', $body);
        return $mailer !== null ? 1 : 0;
    }

    private static function claimDay(string $today): bool
    {
        try {
            $n = DB::table('gates_settings')->where('key_name', self::DIGEST_KEY)
                ->where('value', '!=', $today)->update(['value' => $today]);
            if ($n > 0) return true;
            if (DB::table('gates_settings')->where('key_name', self::DIGEST_KEY)->exists()) return false;
            DB::table('gates_settings')->insert(['key_name' => self::DIGEST_KEY, 'value' => $today]);
            return true;
        } catch (\Throwable) {
            return false;                      // a concurrent insert won the day
        }
    }
}
