<?php
/**
 * The evidence behind a refund decision.
 *
 * ── WHY A DECISION IS NOT ENOUGH ON ITS OWN ──────────────────────────────────
 *
 * "We refunded this" and "we refused to refund this" are both defensible only if
 * the reason survives. Today neither does: `refund_reason` holds a sentence
 * somebody wrote, and the gateway's actual answer — the thing the decision rests
 * on — is thrown away the moment it is read.
 *
 * That matters in both directions.
 *
 * TOWARDS THE BUYER. Telling somebody "no completed payment reached us" is a claim
 * about their money, and it is the hardest sentence support has to write. It has
 * to be backed by something better than "the system said so an unknown time ago" —
 * the gateway's own verdict, with the moment it was asked, is what turns that into
 * a fact somebody can escalate against.
 *
 * TOWARDS US. A refund issued on a payment that never settled is money leaving for
 * nothing, and a platform that will do it once will be asked to do it a thousand
 * times. Recording that a gateway was asked and said no is what makes the refusal
 * repeatable rather than a judgement call somebody has to make again every time.
 *
 * ── AND "COULD NOT REACH THE GATEWAY" IS NOT "NEVER PAID" ────────────────────
 *
 * `gateway_verdict` carries `unreachable` as a distinct value for exactly this
 * reason. Collapsing an unanswered check into "no payment found" would produce a
 * confident refusal out of a network timeout, which is the single worst thing this
 * table could be used to justify.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

foreach ([
    // When the gateway was last asked about this reference.
    'gateway_checked_at' => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
    // success | failed | pending | unreachable — never conflated.
    'gateway_verdict'    => $sqlite ? 'TEXT' : 'VARCHAR(24)',
    // The raw shape of the answer: amount, currency, provider, provider's own ref.
    // JSON rather than columns because it is read by a person reconstructing a
    // decision, not queried in aggregate.
    'gateway_evidence'   => 'TEXT',
] as $col => $type) {
    if (DB::schema()->hasColumn('gates_donations', $col)) {
        echo "  = gates_donations.{$col} already present\n";
        continue;
    }
    DB::statement("ALTER TABLE gates_donations ADD COLUMN {$col} {$type} DEFAULT NULL");
    echo "  + gates_donations.{$col} added\n";
}

echo "refund evidence OK\n";
