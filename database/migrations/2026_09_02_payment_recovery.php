<?php
/**
 * One column so a written-off payment can be asked about again — once, properly.
 *
 * ── THE HOLE THIS CLOSES ─────────────────────────────────────────────────────
 *
 * Every sweeper on this platform is `where('status', 'pending')`. The reconciler,
 * the triage repair, the refund sweep: all of them. And the reconciler itself writes
 * `status = 'failed'` to a pending row that nobody could verify for three days, so it
 * can leave the queue instead of crowding it — which was the right fix for the queue
 * and made that row unreachable by every one of those sweepers, permanently.
 *
 * The three-day write-off is a GUESS, not a verdict. It is taken precisely when the
 * gateway could not be reached, and the reasons a gateway cannot be reached are
 * usually systemic rather than per-row: no key configured, a key that has been
 * rotated, a provider switched off in the environment, an outbound firewall. Under
 * any of those, EVERY payment in the window is written off — including the ones that
 * genuinely succeeded — and once the key is fixed nothing goes back to look.
 *
 * Which is exactly the reported symptom: the gateway ledger, which walks Paystack's
 * own list, can see the disagreement; the triage screen and the reconciler, which walk
 * ours, cannot; and the votes somebody paid for are never minted.
 *
 * ── WHY A NEW COLUMN AND NOT expired_at ──────────────────────────────────────
 *
 * `expired_at` records when we gave up. This records when we last got a real ANSWER
 * out of the gateway about a row we had given up on, and the two are different facts:
 * the second only exists to stop the recovery pass re-asking the same abandoned
 * checkout forever.
 *
 * It is stamped ONLY when the gateway actually answered — not when it was merely
 * asked. That distinction is what makes the pass converge instead of burning its one
 * chance during the very outage that caused the problem: a row that could not be
 * reached is left unstamped and tried again next sweep, and a row that got a verdict
 * is never asked a third time.
 *
 * And not `gateway_checked_at` either, which belongs to the refund-evidence flow
 * ({@see \AfricaGates\Services\RefundDecision}) and means "what we told the customer
 * we found". Two purposes sharing one column is how a refund audit trail gets
 * overwritten by a housekeeping sweep.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

foreach (['gates_donations', 'gates_orders'] as $table) {
    if (!DB::schema()->hasTable($table)) {
        echo "  = {$table} not present — skipped\n";
        continue;
    }
    if (!DB::schema()->hasColumn($table, 'recovery_checked_at')) {
        DB::statement("ALTER TABLE {$table} ADD COLUMN recovery_checked_at "
            . ($sqlite ? 'TEXT' : 'TIMESTAMP NULL') . ' DEFAULT NULL');
        echo "  + {$table}.recovery_checked_at added\n";
    } else {
        echo "  = {$table}.recovery_checked_at already present\n";
    }
}

echo "payment recovery OK\n";
