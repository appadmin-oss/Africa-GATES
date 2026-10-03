<?php
/**
 * Bookkeeping for refunds the platform issues itself.
 *
 * ── WHY `refunded_at` ALONE IS NOT ENOUGH ────────────────────────────────────
 *
 * That column answers "was this refunded", which was all that was needed while
 * every refund was somebody typing into the Paystack dashboard and then running
 * `payments:clawback`. An automatic refund is a different animal: it has a
 * moment where the gateway has been ASKED and has not yet answered, and both
 * gateways queue refunds and settle them hours later.
 *
 * With one column, that moment is indistinguishable from "not refunded" — so the
 * next sweep asks again, and the buyer is paid back twice. Money out is the one
 * kind of duplicate this codebase cannot take back.
 *
 * So:
 *   refund_requested_at   the CLAIM. Stamped before the gateway is called, in a
 *                         conditional UPDATE, so exactly one worker can ever be
 *                         mid-refund on a row. This is the idempotency gate.
 *   refund_state          requested → pending → refunded | failed. `pending` is
 *                         a real outcome, not an error: it means the gateway
 *                         accepted it and is settling.
 *   refund_ref            the gateway's own refund id, for reconciling by hand.
 *   refund_reason         why the platform decided this was owed. Written for a
 *                         human reading the row a month later, not for code.
 *   refunded_at           unchanged: stamped only when the money is actually back.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$text   = $sqlite ? 'TEXT' : 'VARCHAR(120)';
$stamp  = $sqlite ? 'TEXT' : 'TIMESTAMP NULL';

foreach ([
    'refund_state'        => $sqlite ? 'TEXT' : 'VARCHAR(16)',
    'refund_ref'          => $text,
    'refund_reason'       => $sqlite ? 'TEXT' : 'VARCHAR(255)',
    'refund_requested_at' => $stamp,
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_donations', $col)) {
        DB::statement("ALTER TABLE gates_donations ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_donations.{$col} added\n";
    } else {
        echo "  = gates_donations.{$col} already present\n";
    }
}

// The sweep asks "which confirmed paid-vote orders minted nothing and have not
// been refunded" on every maintenance tick. Without this it is a full scan of
// every payment ever taken, several times an hour, forever.
try {
    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_donation_refundable
                       ON gates_donations(status, tier, votes_used, refund_requested_at)');
    } else {
        $has = DB::select("SHOW INDEX FROM gates_donations WHERE Key_name = 'idx_donation_refundable'");
        if (!$has) {
            DB::statement('CREATE INDEX idx_donation_refundable
                           ON gates_donations(status, tier, votes_used, refund_requested_at)');
        }
    }
    echo "  = idx_donation_refundable ready\n";
} catch (\Throwable $e) {
    echo "  ! index skipped: " . $e->getMessage() . "\n";
}

echo "auto refunds OK\n";
