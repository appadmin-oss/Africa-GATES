<?php
/**
 * Store the gateway's OWN identifiers, so a supporter can be found by the number they
 * actually have.
 *
 * ── THE PROBLEM, IN THE WORDS OF THE OLD ERROR MESSAGE ───────────────────────
 *
 * VoteProof used to answer an unmatched lookup with:
 *
 *     "No payment with that reference is on record. If you paid inside a bank or wallet
 *      app, that app shows its own different number — ours begins with AFG-."
 *
 * That is an apology for a missing feature. `payment_ref` holds the reference WE minted
 * and hand to the gateway. Paystack's receipt, its dashboard and its own notification
 * email show its transaction id and its own reference; a bank app shows a narration and
 * an RRN. None of those matched anything on this platform, on any surface — not the
 * verify page, not the support assistant, not admin triage — so the commonest thing a
 * confused supporter can paste was the one thing guaranteed to fail.
 *
 * ── WHY STORE THEM RATHER THAN ALWAYS ASK THE GATEWAY ────────────────────────
 *
 * {@see \AfricaGates\Services\PaymentLookup} can ask Paystack directly, and does when a
 * local lookup misses. But a live call per lookup is a 15-second timeout on a support
 * page, it burns API quota on repeat questions about the same order, and it fails
 * completely when the gateway is the thing that is down — which is exactly when people
 * are asking. Captured once at confirmation, every later lookup is an indexed local hit.
 *
 * ── AND WHY THIS IS NOT A UNIQUE INDEX ───────────────────────────────────────
 *
 * Tempting, and wrong. A gateway transaction id is unique per gateway, not across them,
 * and a re-attempted charge on some providers reuses a reference. A unique constraint
 * would turn a gateway's own retry into a failed confirmation on our side, which is the
 * worst possible place to discover the assumption was wrong.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// gates_orders carries the shop's payments; gates_donations carries paid votes,
// donations and tickets. Both are things a supporter writes to us about.
foreach (['gates_donations', 'gates_orders'] as $table) {
    if (!DB::schema()->hasTable($table)) {
        echo "  = {$table} not present — skipped\n";
        continue;
    }
    foreach ([
        'gateway_txn_id' => $sqlite ? 'TEXT' : 'VARCHAR(64) NULL',
        'gateway_ref'    => $sqlite ? 'TEXT' : 'VARCHAR(80) NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn($table, $col)) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + {$table}.{$col} added\n";
        } else {
            echo "  = {$table}.{$col} already present\n";
        }
    }

    // Non-unique, deliberately — see the note above.
    echo \AfricaGates\Support\SchemaIndex::ensure($table, 'idx_' . substr($table, 6, 4) . '_gwtxn', ['gateway_txn_id']) . "\n";
    echo \AfricaGates\Support\SchemaIndex::ensure($table, 'idx_' . substr($table, 6, 4) . '_gwref', ['gateway_ref']) . "\n";
}

echo "gateway reference OK\n";
