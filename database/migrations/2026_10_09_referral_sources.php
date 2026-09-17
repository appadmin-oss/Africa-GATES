<?php
/**
 * Referral credits stop being event-only.
 *
 * ── WHAT IS BEING EXTENDED, AND WHAT IS DELIBERATELY NOT ─────────────────────
 *
 * A member's referral link earned commission on ONE thing: somebody buying a ticket to an
 * event. The platform sells several other things, and the obvious next step — "pay
 * commission on everything" — would be a serious mistake in two specific places. Both are
 * refused in code, not left to configuration, and the reasoning is here because a future
 * reader will otherwise assume they were simply forgotten:
 *
 *  · PAID VOTES EARN NOTHING. Commission on vote purchases is a standing offer to pay
 *    people for bringing in money that moves an award result. Every other integrity control
 *    on this platform — the fraud scoring, the collusion scan, the organic-vote column, the
 *    shortlist's organic-only switch — exists to keep purchased support distinguishable from
 *    real support. Paying a member a percentage of it would put the platform on the other
 *    side of its own defences.
 *
 *  · DONATIONS EARN NOTHING. A percentage taken out of a charitable donation, paid to
 *    whoever forwarded the link, is not what the donor believed they were funding. In
 *    several jurisdictions it is also a regulated activity with registration requirements
 *    this organisation does not hold.
 *
 * What IS added: shop orders and vendor stand fees. Both are ordinary retail — somebody
 * bought a thing at a stated price — and a share of that is a normal affiliate arrangement
 * a buyer would not be surprised by.
 *
 * ── WHY A POLYMORPHIC KEY AND NOT FOUR COLUMNS ───────────────────────────────
 *
 * `registration_id` was UNIQUE, and that uniqueness IS the idempotency guarantee: a
 * gateway that confirms the same payment twice cannot pay commission twice. Adding
 * `order_id`, `application_id` and so on would need one unique index each and a NULL in
 * three of them per row, and "exactly one of these four is set" is not a constraint any
 * engine here can express.
 *
 * `(source_type, source_id)` expresses it exactly, in one index, and the guarantee survives
 * intact. `registration_id` stays and is backfilled from it so nothing already written is
 * lost and older reports keep working.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_referral_credits')) {
    echo "  = gates_referral_credits not present yet — nothing to extend\n";
    return;
}

// ── 1 · the two new columns ─────────────────────────────────────────────────
if (!DB::schema()->hasColumn('gates_referral_credits', 'source_type')) {
    DB::statement($sqlite
        ? "ALTER TABLE gates_referral_credits ADD COLUMN source_type TEXT NOT NULL DEFAULT 'registration'"
        : "ALTER TABLE gates_referral_credits ADD COLUMN source_type VARCHAR(24) NOT NULL DEFAULT 'registration'");
    echo "  + gates_referral_credits.source_type added\n";
}

if (!DB::schema()->hasColumn('gates_referral_credits', 'source_id')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_referral_credits ADD COLUMN source_id INTEGER NOT NULL DEFAULT 0'
        : 'ALTER TABLE gates_referral_credits ADD COLUMN source_id BIGINT UNSIGNED NOT NULL DEFAULT 0');
    echo "  + gates_referral_credits.source_id added\n";

    // Backfill. Every row that exists is a registration by definition — there was no other
    // way to earn one — so this is exact rather than a guess.
    DB::statement('UPDATE gates_referral_credits SET source_id = registration_id WHERE source_id = 0');
    echo "  = existing credits backfilled as registrations\n";
}

// ── 2 · the idempotency guarantee, moved onto the pair ──────────────────────
//
// Created BEFORE the old index is dropped, so there is never a moment with no unique
// constraint on this table at all. A confirmation arriving in that window would pay a
// member twice, and the whole point of the column is that this cannot happen.
try {
    DB::statement($sqlite
        ? 'CREATE UNIQUE INDEX IF NOT EXISTS uq_credit_source ON gates_referral_credits (source_type, source_id)'
        : 'ALTER TABLE gates_referral_credits ADD UNIQUE KEY uq_credit_source (source_type, source_id)');
    echo "  + unique (source_type, source_id)\n";
} catch (\Throwable $e) {
    // Already there. Re-running a migration must be quiet, not noisy.
    echo "  = unique (source_type, source_id) already present\n";
}

// ── 3 · and the old one goes, on BOTH drivers ──────────────────────────────
//
// It has to. `registration_id` is NOT NULL on SQLite, so a shop order has no registration
// to name and writes 0 — and a UNIQUE index on that column would then reject the SECOND
// shop order the platform ever sold, as a duplicate of the first. The failure would be
// silent (credit() swallows a duplicate-key on purpose, because a raced confirmation is
// the expected path) so nobody would be paid and nobody would be told.
//
// Dropped only after the replacement above exists, so there is never a window with no
// unique constraint on this table: a confirmation arriving in that window would pay a
// member twice, which is the exact thing the column is for.
try {
    DB::statement($sqlite
        ? 'DROP INDEX IF EXISTS uq_credit_once'
        : 'ALTER TABLE gates_referral_credits DROP INDEX uq_credit_once');
    echo "  - old unique (registration_id) dropped; the pair replaces it\n";
} catch (\Throwable) {
    echo "  = old unique (registration_id) already gone\n";
}
