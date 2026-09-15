<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHO PAID TO BE NAMED BESIDE AN AWARD, AND THE THINGS THAT MUST STAY TRUE OF THEM.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A TABLE AND NOT A SETTING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A sponsorship has a counterparty, a sum, a period and a programme. It is a commercial
 * relationship this platform has to be able to disclose years later — "who funded the 2026
 * Alimosho Incredible Principal Awards" is a question a journalist, a regulator or a losing
 * nominee may ask, and the answer has to come out of a row rather than out of somebody's
 * memory of a settings screen.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE MONEY COLUMN EXISTS WHEN NO SCREEN SHOWS IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `amount_naira` is recorded and NOT published. What a sponsor paid is commercially
 * confidential and publishing it would deter the sponsorships this exists to attract; what
 * cannot be confidential is THAT they paid, which is the disclosure. The figure is for the
 * finance screen and for answering the question honestly when it is asked properly.
 *
 * That is the opposite of a column with no reader — it has one, and it is deliberately not
 * the public page. Named here so the next sweep over unread columns does not delete it.
 *
 * ── `cycle_id` IS NULLABLE AND THE DISTINCTION IS THE WHOLE FEATURE ──────────
 *
 * NULL means the sponsor backs the PROGRAMME — every edition, until they stop. A cycle id
 * means they backed one edition. An announced edition's sponsor list must keep naming the
 * people who were on it, so a programme-wide sponsor who leaves next year must not vanish
 * from last year's page: {@see ProgrammeSponsor::forCycle()} resolves that, and the reason
 * it can is that the two cases are different rows rather than one row with a flag.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_programme_sponsors')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_programme_sponsors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programme_id INTEGER NOT NULL,
            -- NULL = the whole programme, every edition. An id = this edition only.
            cycle_id INTEGER NULL,
            name TEXT NOT NULL,
            -- 'headline' | 'supporting' | 'partner'. Rank, not a price.
            tier TEXT NOT NULL DEFAULT 'supporting',
            logo_path TEXT NULL,
            website TEXT NULL,
            blurb TEXT NULL,
            -- Recorded, never published. See the note above.
            amount_naira INTEGER NULL,
            -- 'draft' until somebody decides to publish. A sponsorship agreed in a meeting
            -- is not a sponsorship a page may announce.
            status TEXT NOT NULL DEFAULT 'draft',
            sort_order INTEGER NOT NULL DEFAULT 0,
            starts_at TEXT NULL,
            ends_at TEXT NULL,
            created_at TEXT NULL,
            created_by INTEGER NULL
        )" : "
        CREATE TABLE gates_programme_sponsors (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            programme_id BIGINT UNSIGNED NOT NULL,
            cycle_id BIGINT UNSIGNED NULL,
            name VARCHAR(160) NOT NULL,
            tier VARCHAR(16) NOT NULL DEFAULT 'supporting',
            logo_path VARCHAR(255) NULL,
            website VARCHAR(255) NULL,
            blurb VARCHAR(400) NULL,
            amount_naira BIGINT UNSIGNED NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            -- SMALLINT and not TINYINT: TINYINT UNSIGNED caps at 255 and a sort_order has
            -- been bitten by exactly that in this codebase already.
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            created_by BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "  + gates_programme_sponsors created\n";
} else {
    echo "  = gates_programme_sponsors already present\n";
}

// Through SchemaIndex, never the raw `CREATE INDEX IF NOT EXISTS` — that is SQLite syntax
// MySQL answers with a 1064, and a throw here aborts the run without recording the file,
// so the migration re-runs for ever against a table that now exists.
echo '  ' . SchemaIndex::ensure('gates_programme_sponsors', 'idx_spon_prog',
                                ['programme_id', 'status']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_programme_sponsors', 'idx_spon_cycle',
                                ['cycle_id', 'status']) . "\n";
