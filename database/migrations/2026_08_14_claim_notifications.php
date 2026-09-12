<?php
/**
 * Telling the nominee, on every channel we hold, that somebody claimed their page.
 *
 * Second of the build order in docs/CLAIM-FAIRNESS-AND-FRAUD.md §9, and §5 argues it
 * is the control that matters MORE than the gate:
 *
 *   A thief who claims through an email they control still sets the victim's PHONE
 *   ringing. No gate stops somebody who knows their victim's address; what stops them
 *   is that the theft is loud and the money will not move.
 *
 * ── WHAT THIS MIGRATION IS FOR ───────────────────────────────────────────────
 *
 * §10 refuses to build "silent claiming". A refusal is only real if the code can tell
 * whether the fan-out happened, so the outcome is recorded on the claim rather than
 * left to the mail log:
 *
 *   notified_at — when the fan-out ran. NULL means it has not.
 *   notified    — JSON: every channel attempted, masked, with its outcome. Kept so
 *                 "we told her" can be checked months later, and so the published
 *                 numbers in §8 are counted from what happened rather than from what
 *                 was intended.
 *
 * The claim service treats a fan-out that reached NOBODY as grounds to HOLD rather
 * than activate — an unannounced claim is exactly the silent claiming §10 rules out —
 * and this column is what that decision reads.
 *
 * ── WHY A REFERENCE ──────────────────────────────────────────────────────────
 *
 * The notification has to end with something the recipient can act on, and §7.3
 * guarantees a human route with no account. "Write to us quoting AGC-XXXXXX" works
 * from an SMS, from a borrowed phone, and from someone who never had an account —
 * whereas a link with a row id in it works only for whoever can click.
 *
 * Derived from the row id and ALSO persisted, exactly as nomination references already
 * are ({@see \AfricaGates\Support\Reference}). Derived, so a support agent holding
 * nothing but a reference read down a phone line can checksum it and resolve the row
 * without a lookup; persisted, so the resolution stays an indexed column rather than a
 * computation, and so a reference already printed in somebody's SMS keeps working if
 * the derivation is ever changed. Unique, because two claims answering to one reference
 * would send an agent to the wrong page at the worst possible moment.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_nominee_claims')) {
    echo "  ! gates_nominee_claims missing — run 2026_08_12_nominee_claims first\n";
    return;
}

foreach ([
    'reference'   => $sqlite ? 'TEXT NULL'      : 'VARCHAR(24) NULL',
    'notified_at' => $sqlite ? 'TEXT NULL'      : 'TIMESTAMP NULL DEFAULT NULL',
    // JSON, not a child table: this is an append-once audit blob read by a human or
    // a counter, never joined or filtered on. A table would buy query shapes nobody
    // needs and cost a migration the shared host has to survive.
    'notified'    => $sqlite ? 'TEXT NULL'      : 'TEXT NULL',
] as $col => $type) {
    if (DB::schema()->hasColumn('gates_nominee_claims', $col)) {
        echo "  = gates_nominee_claims.{$col} already present\n";
        continue;
    }
    DB::statement("ALTER TABLE gates_nominee_claims ADD COLUMN {$col} {$type}");
    echo "  + gates_nominee_claims.{$col} added\n";
}

/*
 * Unique on the reference, and tolerant of a re-run.
 *
 * MySQL has no CREATE UNIQUE INDEX IF NOT EXISTS, so the duplicate-name error is the
 * signal that it is already there. Swallowing every error would hide a genuine
 * failure to create it, which for a UNIQUE index means the invariant is quietly not
 * enforced — so the message is echoed either way and only the outcome differs.
 */
try {
    DB::statement('CREATE UNIQUE INDEX uniq_claim_reference ON gates_nominee_claims (reference)');
    echo "  + uniq_claim_reference created\n";
} catch (\Throwable $e) {
    echo "  = uniq_claim_reference already present (" . substr($e->getMessage(), 0, 60) . ")\n";
}

echo "claim notifications OK\n";
