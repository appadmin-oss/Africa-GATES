<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * AWARD TERMS, VERSIONED — AND WHO ACCEPTED WHICH VERSION (Phase 5, §8.3, GAPS §3.2).
 *
 * ── WHAT THERE WAS ───────────────────────────────────────────────────────────
 *
 * One MEDIUMTEXT, `gates_award_programmes.terms`, overwritten in place on every save. So
 * the page could not say which version a voter agreed to, when the current words took
 * effect, or what changed — and the ballot's "By voting you agree to the programme terms"
 * recorded nothing at all (GAPS §3.2). A term that changes after somebody relied on it is
 * exactly when "which words did they see?" gets asked.
 *
 * ── TWO TABLES ───────────────────────────────────────────────────────────────
 *
 *   gates_award_terms             one row per published VERSION: the text, its effective
 *                                 time and a changelog line. Never edited, never deleted —
 *                                 an accepted version is a record somebody relied on
 *                                 (CLAUDE.md, "retired, never deleted").
 *   gates_award_terms_acceptance  one row per (version, person, act): a vote or a
 *                                 nomination, by email HASH — never the address — so the
 *                                 record answers "did they accept" without becoming a second
 *                                 copy of the voter list.
 *
 * `kind` is VARCHAR, not ENUM: a value outside an ENUM is `Data truncated` on MySQL, and a
 * third kind of act (a sponsor) is likelier than not (CLAUDE.md).
 *
 * ── THE BACKFILL ─────────────────────────────────────────────────────────────
 *
 * Every programme whose `terms` column holds text gets version 1, effective from the moment
 * this runs (the honest answer — nobody recorded when the old text took effect), with a
 * changelog that says it is the version carried over. Only for programmes with no version
 * yet, so it runs once per programme however often the file is applied. The old column
 * stays: the admin form still writes it and `AwardTerms::publish()` turns a changed text
 * into a new version, so nothing reads it on the public side any more.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_award_terms')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_award_terms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programme_id INTEGER NOT NULL,
            version INTEGER NOT NULL,
            body TEXT NOT NULL,
            changelog TEXT NULL,
            effective_at TEXT NOT NULL,
            created_at TEXT NULL,
            created_by INTEGER NULL
        )" : "
        CREATE TABLE gates_award_terms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            programme_id INT UNSIGNED NOT NULL,
            version SMALLINT UNSIGNED NOT NULL,
            body MEDIUMTEXT NOT NULL,
            changelog VARCHAR(500) NULL DEFAULT NULL,
            effective_at DATETIME NOT NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            created_by INT UNSIGNED NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_award_terms created\n";
} else {
    echo "  = gates_award_terms already present\n";
}

if (!DB::schema()->hasTable('gates_award_terms_acceptance')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_award_terms_acceptance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            terms_id INTEGER NOT NULL,
            programme_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            email_hash TEXT NOT NULL,
            subject_id INTEGER NULL,
            accepted_at TEXT NOT NULL
        )" : "
        CREATE TABLE gates_award_terms_acceptance (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            terms_id BIGINT UNSIGNED NOT NULL,
            programme_id INT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            email_hash CHAR(64) NOT NULL,
            subject_id BIGINT UNSIGNED NULL DEFAULT NULL,
            accepted_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_award_terms_acceptance created\n";
} else {
    echo "  = gates_award_terms_acceptance already present\n";
}

echo '  ' . SchemaIndex::ensure('gates_award_terms', 'uq_terms_version', ['programme_id', 'version'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_award_terms', 'idx_terms_effective', ['programme_id', 'effective_at']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_award_terms_acceptance', 'uq_terms_accept',
                               ['terms_id', 'email_hash', 'kind', 'subject_id'], true) . "\n";

$now = date('Y-m-d H:i:s');
$carried = 0;
if (DB::schema()->hasColumn('gates_award_programmes', 'terms')) {
    foreach (DB::table('gates_award_programmes')->whereNotNull('terms')->get(['id', 'terms']) as $p) {
        if (trim((string) $p->terms) === '') continue;
        if (DB::table('gates_award_terms')->where('programme_id', (int) $p->id)->exists()) continue;
        DB::table('gates_award_terms')->insert([
            'programme_id' => (int) $p->id, 'version' => 1, 'body' => (string) $p->terms,
            'changelog' => 'The terms as they stood when versioning began.',
            'effective_at' => $now, 'created_at' => $now,
        ]);
        $carried++;
    }
}
echo "  carried over {$carried} programme(s)\naward terms OK\n";
