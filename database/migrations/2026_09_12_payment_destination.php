<?php
/**
 * Where each payment was routed to settle — its own table, keyed by reference.
 *
 * ── WHY A TABLE AND NOT THREE COLUMNS ON THREE TABLES ───────────────────────
 *
 * Ticket money, shop money and vote money are three kinds of money to whoever has to account for
 * them, and they are about to settle into three different Paystack subaccounts. The attribution
 * has to be recorded somewhere.
 *
 * The first attempt added columns to `gates_payments`. That table does not exist — payments live
 * in `gates_orders` (shop), `gates_event_registrations` (tickets) and `gates_votes` (votes),
 * three tables with three different shapes. Adding the same three columns to each would mean the
 * reconciler joining across all three to answer one question, and a fourth revenue stream later
 * needing a fourth migration. (Caught by running the migration, which reported the table missing
 * — the same class of mistake as the handoff calling a method that was never there.)
 *
 * So: one row per routed payment, keyed by the reference, which is the one identifier every
 * stream already shares and the one the gateway knows the payment by.
 *
 * ── AND WHY IT IS WRITTEN ONCE AND NEVER RECOMPUTED ─────────────────────────
 *
 * The obvious way to answer "which account did this order settle to" is to look up the setting
 * for its stream. That answer is wrong the first time somebody edits the setting: an order
 * settled to last quarter's subaccount would silently re-attribute itself to this quarter's, and
 * the platform's history would stop matching the bank's — which then makes the payment comparison
 * screen, built precisely because our records and Paystack's had drifted, compare against a
 * number that moves.
 *
 * Same doctrine as `amount_naira` and `discount_naira`: a figure describing a completed act is
 * stored, not derived. {@see \AfricaGates\Services\PaymentDestination}
 *
 * A MISSING ROW IS MEANINGFUL, not a gap: it says the payment settled to the main account, which
 * is true of every payment taken before this shipped and of every stream nobody has routed.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_payment_routes')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_payment_routes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference TEXT NOT NULL,
            revenue_stream TEXT NOT NULL,
            subaccount TEXT NOT NULL,
            fee_bearer TEXT NOT NULL DEFAULT 'account',
            amount_naira INTEGER NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_payment_routes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            -- The one identifier every revenue stream shares, and the one Paystack knows the
            -- payment by. UNIQUE, so a retried initialise updates rather than adding a second
            -- attribution for the same money — two rows would make a per-stream total double.
            reference VARCHAR(80) NOT NULL,
            -- 'events' | 'shop' | 'votes'. Derived from the reference prefix rather than passed
            -- down through five call sites, so it cannot disagree with the reference itself.
            revenue_stream VARCHAR(20) NOT NULL,
            -- The ACCT_… code actually sent to Paystack, not the one configured now.
            subaccount VARCHAR(60) NOT NULL,
            -- Who bore the transaction charge. Recorded because Paystack's default is that the
            -- MAIN account bears it, which is rarely what somebody splitting revenue intends and
            -- is invisible until a settlement arrives short.
            fee_bearer VARCHAR(20) NOT NULL DEFAULT 'account',
            -- What was being charged when it was routed. Not the authority on the amount — the
            -- order row is — but enough to total a stream without three joins.
            amount_naira INT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_route_reference (reference),
            KEY idx_route_stream (revenue_stream, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_payment_routes created\n";
} else {
    echo "  = gates_payment_routes already present\n";
}

// SQLite gets its indexes separately — the CREATE above cannot carry them.
if ($sqlite) {
    foreach ([
        'uq_route_reference' => 'CREATE UNIQUE INDEX IF NOT EXISTS uq_route_reference ON gates_payment_routes (reference)',
        'idx_route_stream'   => 'CREATE INDEX IF NOT EXISTS idx_route_stream ON gates_payment_routes (revenue_stream, created_at)',
    ] as $name => $sql) {
        try {
            DB::statement($sql);
            echo "  = {$name} ensured\n";
        } catch (\Throwable $e) {
            echo "  ! {$name}: " . $e->getMessage() . "\n";
        }
    }
}

echo "payment destination OK\n";
