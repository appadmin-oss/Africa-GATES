<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The two things that make the standing safe when the row lock is not there.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * 1. A UNIQUE ON (challenge_id, standing)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `ChallengeService::qualify()` reads the taken count inside a `lockForUpdate()` on
 * the challenge row, which is correct on MySQL. It is correct on SQLite for a
 * different reason — that driver has no row locks at all and `lockForUpdate()`
 * compiles to nothing, so the guarantee there comes from writer serialisation.
 *
 * Two mechanisms, neither visible in the query, and the test that is supposed to
 * prove the ordering passes on the harness whatever the code does. So the constraint
 * is also written into the schema, where it holds on both drivers and holds against
 * a future caller that assigns a standing without going through `qualify()`:
 * a double-assignment becomes a refused write rather than two people both told they
 * came fourth, with a cash prize attached to the number.
 *
 * It is a partial guarantee by necessity — `standing` is NULL for every entry that
 * has not qualified, and NULL is distinct from NULL in a UNIQUE index on both
 * engines, so the many unqualified rows do not collide. That is exactly the shape
 * wanted here.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * 2. `reversed_at` ON A REFERRAL CREDIT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The challenge page publishes "Paid, not reserved. Refunded tickets are removed".
 * Nothing in this codebase reverses a referral credit: `refunded_at` exists on
 * donations and nowhere else, and `ReferralService::creditSale()` has no counterpart.
 * So the rule was printed and unenforceable — five tickets bought through your own
 * promotion, a prize collected, and five chargebacks afterwards.
 *
 * The column is STAMPED and the row is never deleted, which is this codebase's
 * settled rule for money that once cleared: a donation clawback leaves the row and
 * marks it, because the row is the record that the payment happened and rewriting it
 * destroys that fact.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1 ───────────────────────────────────────────────────────────────────────

if (DB::schema()->hasTable('gates_challenge_entries')) {
    // Never the raw `CREATE INDEX IF NOT EXISTS` — that is SQLite syntax and a 1064 on
    // MySQL, and `MigrateCommand` aborts the run without recording the file, so every
    // migration dated after it never applies.
    SchemaIndex::ensure('gates_challenge_entries', 'uq_entry_standing',
        ['challenge_id', 'standing'], true);
    echo "  = gates_challenge_entries.uq_entry_standing ensured\n";
}

// ── 2 ───────────────────────────────────────────────────────────────────────

if (DB::schema()->hasTable('gates_referral_credits')
    && !DB::schema()->hasColumn('gates_referral_credits', 'reversed_at')) {
    DB::statement('ALTER TABLE gates_referral_credits ADD COLUMN reversed_at '
        . ($sqlite ? 'TEXT NULL' : 'DATETIME NULL DEFAULT NULL'));
    echo "  + gates_referral_credits.reversed_at\n";
} else {
    echo "  = gates_referral_credits.reversed_at already present or table absent\n";
}

echo "challenge standing guard migration OK\n";
