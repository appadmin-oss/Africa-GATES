<?php
/**
 * Nominee profile claiming — the tables.
 *
 * Design: docs/NOMINEE-CLAIMING-PLAN.md §7 and docs/CLAIM-FAIRNESS-AND-FRAUD.md.
 *
 * ── THE INVARIANT THIS SCHEMA ENFORCES ───────────────────────────────────────
 *
 * "Two people own this page" must be IMPOSSIBLE, not unlikely. A claimed page
 * carries someone's name, their words, and eventually their money, so a race
 * between two simultaneous claims cannot be left to application code that checks
 * before it writes.
 *
 * MySQL has no partial unique index, so the portable way to say "at most one ACTIVE
 * claim per nominee" is a nullable column that is UNIQUE and only populated while
 * the claim is active:
 *
 *     active_nominee_id = nominee_id  while status = 'active'
 *     active_nominee_id = NULL        otherwise
 *
 * NULLs do not collide in a unique index, so any number of pending, held, rejected
 * or revoked claims may coexist on one nominee — which is required, because §7 of
 * the fairness doc says a rejected claim must never lock the page against the real
 * person trying again. Only the ACTIVE one is constrained, and the database is what
 * refuses the second.
 *
 * ── WHY THE CLAIMANT'S DEVICE IS STORED ──────────────────────────────────────
 *
 * `gates_nominations` already records the NOMINATOR's `ip_hash` and `device_fp`.
 * Storing the CLAIMANT's makes the commonest attack in the threat model —
 * a nominator who typed their own address and then claims the page — a
 * deterministic comparison of two rows rather than a judgement call. It is free:
 * both values are already computed on every request.
 *
 * Hashes, never raw. An IP is personal data under the NDPA and there is no purpose
 * here that the hash does not serve.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$ts     = $sqlite ? 'TEXT' : 'TIMESTAMP NULL';

if (!DB::schema()->hasTable('gates_nominee_claims')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_nominee_claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nominee_id INTEGER NOT NULL,
            nomination_id INTEGER NULL,
            profile_id INTEGER NULL,
            user_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            method TEXT NOT NULL DEFAULT 'otp',
            channel TEXT NULL,
            channel_hint TEXT NULL,
            represented INTEGER NOT NULL DEFAULT 0,
            independence TEXT NULL,
            hold_reason TEXT NULL,
            device_fp TEXT NULL,
            ip_hash TEXT NULL,
            active_nominee_id INTEGER NULL UNIQUE,
            claimed_at TEXT NULL,
            activated_at TEXT NULL,
            revoked_at TEXT NULL,
            revoked_reason TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_nominee_claims (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nominee_id BIGINT UNSIGNED NOT NULL,
            -- WHICH nomination was claimed against. Independence is a property of a
            -- specific row's nominator, so the check is not reproducible without it.
            nomination_id BIGINT UNSIGNED NULL,
            profile_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            -- held is NOT a refusal: it is 'we need one more thing', and the wording
            -- everywhere downstream depends on that being a distinct state.
            status ENUM('pending','active','held','rejected','revoked') NOT NULL DEFAULT 'pending',
            method ENUM('otp','assisted','admin') NOT NULL DEFAULT 'otp',
            channel ENUM('email','phone') NULL,
            -- The MASKED destination shown back to the claimant (a***@gmail.com).
            -- Masked in the column too, so a leak of this table does not hand over a
            -- list of nominee contact details.
            channel_hint VARCHAR(120) NULL,
            represented TINYINT(1) NOT NULL DEFAULT 0,
            -- The independence verdict as JSON: which signals were compared and which
            -- matched. Kept so a held claim can be explained to the person it held.
            independence TEXT NULL,
            hold_reason VARCHAR(250) NULL,
            device_fp VARCHAR(64) NULL,
            ip_hash VARCHAR(64) NULL,
            -- nominee_id while active, NULL otherwise. See the note above.
            active_nominee_id BIGINT UNSIGNED NULL,
            claimed_at TIMESTAMP NULL DEFAULT NULL,
            activated_at TIMESTAMP NULL DEFAULT NULL,
            revoked_at TIMESTAMP NULL DEFAULT NULL,
            revoked_reason VARCHAR(250) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_active_claim (active_nominee_id),
            KEY idx_claim_nominee (nominee_id, status),
            KEY idx_claim_queue (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_nominee_claims created\n";
} else {
    echo "  = gates_nominee_claims already present\n";
}

/*
 * Two additions to gates_nominations, both mirroring precedents already on the
 * platform rather than inventing vocabulary.
 */
foreach ([
    // Attribution opt-in, exactly like gates_votes.show_name. Default 0: a
    // nominator who said nothing about being named is not named. The published
    // form is first name + last initial + state, never contact details.
    'show_nominator' => $sqlite ? 'INTEGER DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0',
    // Moderation, like gates_comments.status. A nomination reason is a written
    // character assessment of a NAMED third party, so it is the highest blast
    // radius text on the platform and cannot publish unreviewed.
    'reason_status'  => $sqlite ? "TEXT DEFAULT 'pending'"
                                : "ENUM('pending','approved','hidden') NOT NULL DEFAULT 'pending'",
] as $col => $type) {
    if (DB::schema()->hasColumn('gates_nominations', $col)) {
        echo "  = gates_nominations.{$col} already present\n";
        continue;
    }
    DB::statement("ALTER TABLE gates_nominations ADD COLUMN {$col} {$type}");
    echo "  + gates_nominations.{$col} added\n";
}

/*
 * Existing APPROVED nominations keep their reasons hidden until a moderator looks.
 *
 * The alternative — approving them all because the nomination itself was approved —
 * conflates two different decisions. Approving a nomination says "this person
 * belongs on the ballot"; it was never a judgement on whether the free-text reason
 * is publishable about a named individual. Nobody has read these with publication
 * in mind, so they start at pending and a person clears them.
 */
echo "nominee claims OK\n";
