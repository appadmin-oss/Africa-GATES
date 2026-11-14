<?php
/**
 * What the gateway actually sent back.
 *
 * ── THE BUG THIS COLUMN EXISTS BECAUSE OF ────────────────────────────────────
 *
 * `RefundService::refundOne()` asked the gateway to refund `gates_donations.amount_naira`
 * — our own figure. Both Paystack and Flutterwave read a supplied amount as "refund
 * exactly this much", so every refund was really a PARTIAL refund that happened to
 * equal the full charge whenever our row agreed with the gateway.
 *
 * When it did not agree, the failure was silent and one-directional. Too high is
 * refused outright ("exceeds the transaction amount"), which is loud and gets parked
 * for a person. Too LOW succeeds: the gateway does exactly as asked, our row is
 * stamped `refunded`, the buyer is emailed a figure they did not receive, and nothing
 * anywhere records a discrepancy. Somebody is short and every screen says settled.
 *
 * So the instruction is now "return the whole transaction" and the gateway's own
 * figure is written down here.
 *
 * ── WHY A SEPARATE COLUMN AND NOT amount_naira ───────────────────────────────
 *
 * They are different facts and they are allowed to differ. `amount_naira` is what we
 * charged; this is what came back. Overwriting the first with the second would
 * destroy the only evidence that they ever disagreed — which is precisely the
 * condition worth being able to find later.
 *
 * NULL is meaningful and stays NULL: it means the gateway did not report a figure
 * (an "already refunded on an earlier attempt" reply carries none). A guess written
 * here would be indistinguishable from a fact, so nothing is guessed.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasColumn('gates_donations', 'refund_amount_naira')) {
    echo "  = gates_donations.refund_amount_naira already present\n";
} else {
    DB::statement('ALTER TABLE gates_donations ADD COLUMN refund_amount_naira '
        . ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ' DEFAULT NULL');
    echo "  + gates_donations.refund_amount_naira added\n";
}

/*
 * Deliberately NOT backfilled from amount_naira.
 *
 * Historical refunds were sent for our figure, so copying it here would look like
 * confirmation from the gateway when it is only a restatement of what we asked for.
 * An honest NULL says "nobody recorded this", which is true, and leaves the rows
 * where a discrepancy is possible visibly distinct from the rows where it is not.
 */

echo "refund amount OK\n";
