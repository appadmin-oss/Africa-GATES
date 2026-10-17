<?php
declare(strict_types=1);

use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Support\NominationStatus as NS;
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * CHALLENGES: A TIME-BOXED CAMPAIGN THAT PAYS PEOPLE FOR REAL ACTIONS.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * EVERY CHALLENGE IS ONE ROW. NOTHING BELOW IS PER-CAMPAIGN CODE.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The launch campaign — the first 11 people to get 10 nominees verified each win
 * ₦6,000 inside Alimosho Awards 2026 — differs from "20 tickets drawn at random
 * among everyone who thanks 5 teachers" only by the values in these columns. If a
 * second campaign ever needs a migration, the shape here is wrong.
 *
 * ── THE ENUM WORDS LIVE IN PHP, NOT HERE ────────────────────────────────────
 *
 * Every list below is interpolated from `Support\ChallengeEnum`, so the column and
 * the code that writes it cannot drift. A value outside an ENUM is `Data truncated`
 * on MySQL — not an error anybody notices — while SQLite stores whatever it is
 * handed, so a sixth action added in PHP and not here would pass the whole suite and
 * land as an empty string on the only database that matters.
 * `ChallengeSchemaWordsTest` checks both directions.
 *
 * ── AND ONE ENUM IS WIDENED RATHER THAN REPLACED ────────────────────────────
 *
 * The handoff specifies `gates_nominations.status` as
 * `(draft,submitted,checking,verified,needs_details,rejected)`. The live column is
 * `('pending','approved','rejected')` and **176 places in `src/` read 'approved'**.
 * Replacing the set would make every one of them match zero rows, silently. So the
 * column becomes the UNION, the old rows are not rewritten, and
 * `Support\NominationStatus` is the single place that knows the two vocabularies
 * overlap. Recorded as deviation 1.
 *
 * ── WIDTHS ARE CHOSEN, NOT COPIED ───────────────────────────────────────────
 *
 * `cap`, `target` and `draw_count` are SMALLINT UNSIGNED: a TINYINT stops at 255 and
 * this codebase has already paid for that twice — a `sort_order` that clamped, and
 * thirty test files whose programme ids all became 255 and silently resolved to one
 * another. `prize_amount` is INT UNSIGNED because a pool prize in Naira passes
 * 65,535 at ₦65,536.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

/** `ENUM('a','b')` for MySQL, a plain VARCHAR for SQLite, from one PHP list. */
$enum = static function (array $values) use ($sqlite): string {
    if ($sqlite) return 'TEXT';
    return "ENUM('" . implode("','", $values) . "')";
};

// ── 1. the challenge ────────────────────────────────────────────────────────

if (!DB::schema()->hasTable('gates_challenges')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_challenges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL, title TEXT NOT NULL, kicker TEXT NOT NULL, summary TEXT NULL,
            action TEXT NOT NULL DEFAULT 'nominate',
            target INTEGER NOT NULL DEFAULT 1,
            mode TEXT NOT NULL DEFAULT 'first',
            cap INTEGER NULL, draw_count INTEGER NULL, draw_at TEXT NULL, draw_seed TEXT NULL,
            prize_type TEXT NOT NULL DEFAULT 'cash_each',
            prize_amount INTEGER NOT NULL DEFAULT 0,
            prize_currency TEXT NULL, prize_label TEXT NULL,
            theme TEXT NOT NULL DEFAULT 'green',
            art_url TEXT NULL, art_alt TEXT NULL, icon TEXT NULL, flag INTEGER NOT NULL DEFAULT 0,
            eligibility TEXT NULL, extra_rules TEXT NULL,
            starts_at TEXT NULL, ends_at TEXT NULL,
            timezone TEXT NOT NULL DEFAULT 'Africa/Lagos',
            terms_version TEXT NOT NULL DEFAULT '1.0',
            status TEXT NOT NULL DEFAULT 'draft', cancel_reason TEXT NULL,
            created_by INTEGER NULL, published_at TEXT NULL,
            created_at TEXT NULL, updated_at TEXT NULL
        )
    " : "
        CREATE TABLE gates_challenges (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(160) NOT NULL,
            title VARCHAR(200) NOT NULL,
            kicker VARCHAR(160) NOT NULL,
            summary TEXT NULL,
            action {$enum(E::ACTIONS)} NOT NULL DEFAULT 'nominate',
            target SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            mode {$enum(E::MODES)} NOT NULL DEFAULT 'first',
            -- SMALLINT, not TINYINT: a cap of 300 is an ordinary campaign and a
            -- TINYINT would store it as 255 with nothing to see.
            cap SMALLINT UNSIGNED NULL,
            draw_count SMALLINT UNSIGNED NULL,
            draw_at DATETIME NULL,
            draw_seed VARCHAR(64) NULL,
            prize_type {$enum(E::PRIZE_TYPES)} NOT NULL DEFAULT 'cash_each',
            -- INT: a shared pool in Naira passes a SMALLINT at 65,536.
            prize_amount INT UNSIGNED NOT NULL DEFAULT 0,
            prize_currency VARCHAR(8) NULL,
            prize_label VARCHAR(80) NULL,
            theme {$enum(E::THEMES)} NOT NULL DEFAULT 'green',
            art_url VARCHAR(400) NULL, art_alt VARCHAR(200) NULL,
            icon VARCHAR(400) NULL,
            flag TINYINT(1) NOT NULL DEFAULT 0,
            eligibility TEXT NULL,
            extra_rules TEXT NULL,
            starts_at DATETIME NULL, ends_at DATETIME NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Lagos',
            terms_version VARCHAR(16) NOT NULL DEFAULT '1.0',
            status {$enum(E::STATUSES)} NOT NULL DEFAULT 'draft',
            cancel_reason VARCHAR(300) NULL,
            created_by BIGINT UNSIGNED NULL,
            published_at DATETIME NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + gates_challenges created\n";
}

// `SchemaIndex::ensure()`, never a raw `CREATE INDEX IF NOT EXISTS` — that is SQLite
// syntax MySQL answers with a 1064, and `MigrateCommand` does not record a file that
// threw, so the guard above it is true on the next deploy and the index is never made.
SchemaIndex::ensure('gates_challenges', 'uq_challenge_slug', ['slug'], true);
SchemaIndex::ensure('gates_challenges', 'idx_challenge_status', ['status']);

// ── 2. what it runs inside ──────────────────────────────────────────────────

if (!DB::schema()->hasTable('gates_challenge_scopes')) {
    // An action counts ONLY inside a scope, so this table is the difference between
    // "nominate anybody" and "nominate for Alimosho 2026 in three categories".
    DB::statement($sqlite ? "
        CREATE TABLE gates_challenge_scopes (
            challenge_id INTEGER NOT NULL,
            scope_type TEXT NOT NULL,
            scope_id INTEGER NOT NULL,
            PRIMARY KEY (challenge_id, scope_type, scope_id)
        )
    " : "
        CREATE TABLE gates_challenge_scopes (
            challenge_id INT UNSIGNED NOT NULL,
            scope_type {$enum(E::SCOPE_TYPES)} NOT NULL,
            -- BIGINT because a category id is BIGINT, even though an event id is INT.
            -- The narrower of two joined keys is the one that silently truncates.
            scope_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (challenge_id, scope_type, scope_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + gates_challenge_scopes created\n";
}
SchemaIndex::ensure('gates_challenge_scopes', 'idx_scope_lookup', ['scope_type', 'scope_id']);

// ── 3. one person's attempt ─────────────────────────────────────────────────

if (!DB::schema()->hasTable('gates_challenge_entries')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_challenge_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            challenge_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            phone_hash TEXT NULL, joined_at TEXT NULL,
            progress INTEGER NOT NULL DEFAULT 0, verified INTEGER NOT NULL DEFAULT 0,
            checking INTEGER NOT NULL DEFAULT 0, needs_details INTEGER NOT NULL DEFAULT 0,
            qualified_at TEXT NULL, standing INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'active', disqualify_reason TEXT NULL,
            payout_status TEXT NOT NULL DEFAULT 'none',
            payout_ref TEXT NULL, payout_at TEXT NULL,
            created_at TEXT NULL, updated_at TEXT NULL
        )
    " : "
        CREATE TABLE gates_challenge_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            challenge_id INT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            -- The phone is hashed, never stored: one entry per PERSON is enforced on a
            -- number we must not keep beside a prize.
            phone_hash CHAR(64) NULL,
            joined_at DATETIME NULL,
            progress SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            verified SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            checking SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            needs_details SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            qualified_at DATETIME NULL,
            -- `standing`, never `rank`: RANK is reserved in MySQL 8 and bare-legal in
            -- SQLite, which is how a column name passes dev and fails production. The
            -- release seal hit this already and settled on the same word.
            standing SMALLINT UNSIGNED NULL,
            status {$enum(E::ENTRY_STATUSES)} NOT NULL DEFAULT 'active',
            disqualify_reason VARCHAR(300) NULL,
            payout_status {$enum(E::PAYOUT_STATUSES)} NOT NULL DEFAULT 'none',
            payout_ref VARCHAR(120) NULL, payout_at DATETIME NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + gates_challenge_entries created\n";
}
// One entry per person AND per phone — the two ways the same human arrives twice.
SchemaIndex::ensure('gates_challenge_entries', 'uq_entry_user', ['challenge_id', 'user_id'], true);
SchemaIndex::ensure('gates_challenge_entries', 'uq_entry_phone', ['challenge_id', 'phone_hash'], true);
SchemaIndex::ensure('gates_challenge_entries', 'idx_entry_rank', ['challenge_id', 'status', 'qualified_at']);

// ── 4. the audit trail ──────────────────────────────────────────────────────

if (!DB::schema()->hasTable('gates_challenge_events')) {
    // APPEND-ONLY. A disqualification, a payout and a draw are decisions somebody may
    // have to answer for months later, and `gates_audit_log` has already taught this
    // codebase that a record nothing can QUERY is not a record.
    DB::statement($sqlite ? "
        CREATE TABLE gates_challenge_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            challenge_id INTEGER NULL, entry_id INTEGER NULL,
            kind TEXT NOT NULL, ref_type TEXT NULL, ref_id INTEGER NULL,
            actor_id INTEGER NULL, meta TEXT NULL, created_at TEXT NULL
        )
    " : "
        CREATE TABLE gates_challenge_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            challenge_id INT UNSIGNED NULL,
            entry_id BIGINT UNSIGNED NULL,
            kind VARCHAR(60) NOT NULL,
            ref_type VARCHAR(40) NULL, ref_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NULL,
            meta TEXT NULL,
            created_at DATETIME NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + gates_challenge_events created\n";
}
SchemaIndex::ensure('gates_challenge_events', 'idx_chev_entry', ['entry_id', 'id']);
SchemaIndex::ensure('gates_challenge_events', 'idx_chev_challenge', ['challenge_id', 'kind']);

// ── 5. the promo rows the carousel reads ────────────────────────────────────

if (!DB::schema()->hasTable('gates_promos')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_promos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            placement TEXT NOT NULL, kicker TEXT NULL, title TEXT NOT NULL,
            sub TEXT NULL, cta TEXT NULL, href TEXT NULL,
            theme TEXT NOT NULL DEFAULT 'green', art_url TEXT NULL,
            challenge_id INTEGER NULL, priority INTEGER NOT NULL DEFAULT 0,
            audience TEXT NOT NULL DEFAULT 'all',
            starts_at TEXT NULL, ends_at TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL, updated_at TEXT NULL
        )
    " : "
        CREATE TABLE gates_promos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            placement {$enum(E::PLACEMENTS)} NOT NULL,
            kicker VARCHAR(120) NULL, title VARCHAR(200) NOT NULL,
            sub VARCHAR(300) NULL, cta VARCHAR(80) NULL, href VARCHAR(400) NULL,
            theme {$enum(E::THEMES)} NOT NULL DEFAULT 'green',
            art_url VARCHAR(400) NULL,
            challenge_id INT UNSIGNED NULL,
            priority SMALLINT NOT NULL DEFAULT 0,
            audience {$enum(E::AUDIENCES)} NOT NULL DEFAULT 'all',
            starts_at DATETIME NULL, ends_at DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + gates_promos created\n";
}
SchemaIndex::ensure('gates_promos', 'idx_promo_slot', ['placement', 'active', 'priority']);

// ── 6. what a nomination now carries ────────────────────────────────────────

if (DB::schema()->hasTable('gates_nominations')) {
    $add = static function (string $col, string $sqliteType, string $mysqlType) use ($sqlite): void {
        if (DB::schema()->hasColumn('gates_nominations', $col)) return;
        DB::statement("ALTER TABLE gates_nominations ADD COLUMN {$col} "
            . ($sqlite ? $sqliteType : $mysqlType));
        echo "  + gates_nominations.{$col}\n";
    };

    $add('challenge_entry_id', 'INTEGER NULL', 'BIGINT UNSIGNED NULL');
    // ONE HASH FOR ONE HUMAN. sha256 of the E.164 phone where there is one, else of
    // the normalised email — so "+234 803 123 4567" and "08031234567" are the same
    // person, and a nominator cannot reach the target by sending the same friend
    // through under two spellings.
    $add('nominee_identity_hash', 'TEXT NULL', 'CHAR(64) NULL');
    $add('nominee_confirm_token', 'TEXT NULL', 'CHAR(40) NULL');
    $add('nominee_confirmed_at', 'TEXT NULL', 'DATETIME NULL');
    $add('confirm_sends', 'INTEGER NOT NULL DEFAULT 0', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');

    SchemaIndex::ensure('gates_nominations', 'idx_nom_entry', ['challenge_entry_id']);
    SchemaIndex::ensure('gates_nominations', 'idx_nom_identity', ['nominee_identity_hash']);
    // The token is spent by a stranger following a link, so the lookup is on the hot
    // path of a public route and must not be a table scan.
    SchemaIndex::ensure('gates_nominations', 'uq_nom_confirm', ['nominee_confirm_token'], true);
}

// ── 7. the status vocabulary, WIDENED ───────────────────────────────────────

if (!$sqlite && DB::schema()->hasTable('gates_nominations')) {
    // MySQL only: SQLite has no ENUM, so its column already accepts every word.
    //
    // The union, in the live order. Reordering an ENUM rewrites every row's stored
    // index, so the three that exist stay exactly where they were and the new five
    // are appended. No row is rewritten: `approved` remains `approved`, which is what
    // 176 readers ask for.
    $cols = DB::select("SHOW COLUMNS FROM gates_nominations LIKE 'status'");
    $type = strtolower((string) ($cols[0]->Type ?? ''));

    if ($type !== '' && !str_contains($type, "'verified'")) {
        DB::statement("ALTER TABLE gates_nominations MODIFY COLUMN status "
            . "ENUM('" . implode("','", NS::ALL) . "') NOT NULL DEFAULT 'pending'");
        echo "  = gates_nominations.status widened to " . count(NS::ALL) . " values\n";
    } else {
        echo "  = gates_nominations.status already widened\n";
    }
}
