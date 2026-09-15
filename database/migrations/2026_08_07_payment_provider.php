<?php
/**
 * Record WHICH gateway took the money, and stop the pending queue growing forever.
 *
 * ── `provider` ───────────────────────────────────────────────────────────────
 *
 * `gates_orders` has stored its provider since the shop was built. `gates_donations`
 * — vote packs, tickets, child donations, every paid vote — never has, and three
 * separate places have been guessing ever since:
 *
 *   PaymentReconciler::donations()  asks EVERY enabled gateway whether it knows the
 *                                   reference. Two gateways × 200 rows is 400 HTTPS
 *                                   round trips per sweep, each with a 15s timeout.
 *   PaymentReconciler::reclaim()    same loop, on a live support request, while a
 *                                   buyer waits for the page.
 *   RefundService::providerFor()    guesses from a `paystack_` prefix that our own
 *                                   references have never carried, then falls back
 *                                   to the same loop — to decide where to send MONEY.
 *
 * The gateway is known at /pay/init and /vote/paid/start, a line before the row is
 * written. Storing it turns all three from a search into a lookup.
 *
 * ── `expired_at` ─────────────────────────────────────────────────────────────
 *
 * A pending row is only ever removed from the queue by being confirmed or failed.
 * An abandoned checkout is neither: Paystack reports it `abandoned`, which the
 * verifier maps to `pending`, so it is re-asked on every sweep for the rest of time.
 *
 * That is not merely wasteful. The sweep reads `ORDER BY id LIMIT 200` — oldest
 * first — so once the abandoned backlog passes the limit, the 201st row is never
 * reached again and a payment that really did succeed is never reconciled. The
 * queue silently stops working, and it stops working from the busy end.
 *
 * `expired_at` is the tombstone that lets a dead checkout leave. The status still
 * becomes 'failed' (the vocabulary the rest of the platform reads); this column
 * records that it was TIME that decided, not the gateway — so "we expired it after
 * three days" is distinguishable from "the bank declined it" months later.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

foreach ([
    'provider'   => $sqlite ? 'TEXT' : 'VARCHAR(24)',
    'expired_at' => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_donations', $col)) {
        DB::statement("ALTER TABLE gates_donations ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_donations.{$col} added\n";
    } else {
        echo "  = gates_donations.{$col} already present\n";
    }
}

// The reconciler now reads the queue newest-first and bounded by age, which is a
// (status, created_at) range scan. idx_donations_status alone leaves it sorting the
// whole pending set on every sweep.
try {
    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_donations_pending_age
                       ON gates_donations(status, created_at)');
    } else {
        $has = DB::select("SHOW INDEX FROM gates_donations WHERE Key_name = 'idx_donations_pending_age'");
        if (!$has) {
            DB::statement('CREATE INDEX idx_donations_pending_age ON gates_donations(status, created_at)');
        }
    }
    echo "  = idx_donations_pending_age ready\n";
} catch (\Throwable $e) {
    echo "  ! index skipped: " . $e->getMessage() . "\n";
}

echo "payment provider OK\n";
