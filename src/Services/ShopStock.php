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
}
