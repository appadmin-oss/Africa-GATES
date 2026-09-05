<?php
/**
 * When a checkout stops being payable.
 *
 * ── THE HOLE THIS CLOSES ─────────────────────────────────────────────────────
 *
 * A pending checkout was live for a flat `PaymentService::IN_FLIGHT_MINUTES` (120)
 * from creation, and nothing in that arithmetic knew about the ballot. An order
 * started twenty minutes before voting closed therefore stayed "in flight" for an
 * hour and forty minutes AFTER the bell — and every reader believed it. The
 * reconciler kept asking the gateway about it. The abandoned-cart mailer treated it
 * as a live cart worth nudging. A payment landing in that stretch was confirmed:
 * money taken for votes that could no longer be delivered.
 *
 * That is where the refunds come from. Every "voting closed before the payment
 * confirmed" refund this platform has issued began as a checkout the ballot had
 * already finished with, which nothing had told.
 *
 * `checkout_expires_at` is min(created_at + in-flight, voting close), computed once
 * when the order is created and then read rather than re-derived. One recorded fact
 * instead of four callers each doing their own sum against a global constant — which
 * is how they came to disagree.
 *
 * ── WHY IT IS RECORDED AND NOT COMPUTED ON READ ──────────────────────────────
 *
 * The close time is editable. An admin extending a cycle after somebody has started
 * a checkout must not retroactively change whether that checkout was alive at the
 * moment money arrived, because that determines whether the order gets votes or a
 * refund. A stored deadline is the same answer on every read, months later, for
 * anyone reconstructing a decision.
 *
 * NULL is meaningful: an open-ended cycle with no published close, or an order taken
 * before this column existed. Every reader must treat NULL as "no deadline recorded"
 * and fall back to the old global window rather than as "expired" — a migration that
 * silently voided every in-flight checkout would be the worse bug.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasColumn('gates_donations', 'checkout_expires_at')) {
    echo "  = gates_donations.checkout_expires_at already present\n";
} else {
    DB::statement('ALTER TABLE gates_donations ADD COLUMN checkout_expires_at '
        . ($sqlite ? 'TEXT' : 'TIMESTAMP NULL') . ' DEFAULT NULL');
    echo "  + gates_donations.checkout_expires_at added\n";
}

/*
 * Deliberately NOT backfilled.
 *
 * Historical orders were taken under the old flat window, and inventing a deadline
 * for them now would rewrite the basis on which they were confirmed or refused.
 * NULL is the honest value: nobody recorded one. The readers all fall back to the
 * global window for those rows, which is exactly what they were decided under.
 */

echo "checkout deadline OK\n";
