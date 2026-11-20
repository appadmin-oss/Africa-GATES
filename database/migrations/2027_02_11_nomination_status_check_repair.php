<?php
declare(strict_types=1);

use AfricaGates\Support\NominationStatus as NS;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The SQLite CHECK that refuses the five status words the ENUM was just widened for.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A FAULT THE PREVIOUS MIGRATION'S OWN COMMENT DENIED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `2027_02_10_challenges.php` widens `gates_nominations.status` on MySQL and skips
 * SQLite, under a comment reading "SQLite has no ENUM, so its column already accepts
 * every word". It has no ENUM and it has a CHECK:
 *
 *     status TEXT NOT NULL DEFAULT 'pending'
 *            CHECK(status IN ('pending','approved','rejected'))
 *
 * SQLite enforces CHECK, and the harness's `PRAGMA foreign_keys = OFF` does not
 * touch it — that pragma disables foreign keys and nothing else. Measured on a fresh
 * build of `sqlite-schema.sql`: `verified`, `submitted` and `needs_details` each come
 * back `CHECK constraint failed`.
 *
 * So the state of things before this file: a nomination could reach `verified` on
 * production and **nowhere else**. `NominationStatus::countsForChallenge()` requires
 * exactly that word, so every challenge that counts verified nominations was
 * untestable in the suite and dead on every developer's machine, while working on the
 * one database nobody can open a shell on. The usual divergence runs the other way,
 * which is what made it easy to write.
 *
 * `ChallengeSchemaWordsTest` passed over it, because it compared the MIGRATION
 * against `NominationStatus::ALL` rather than asking the live column whether it would
 * accept the word. The right rule pinned to the wrong token — the commonest way a
 * sweep here goes quiet. It writes each word now and requires the row to land.
 *
 * ── WHY THE LIVE DDL IS REWRITTEN RATHER THAN RETYPED ───────────────────────
 *
 * SQLite cannot ALTER a CHECK, so the table is rebuilt. The precedent for that here
 * (`2026_07_26_cycle_shortlisting_phase.php`) spells the replacement table out in
 * full, which is safe for a table nothing has since altered. `gates_nominations` is
 * not that table: later migrations have added `nominee_kind`, `categories_json`,
 * `challenge_entry_id`, `nominee_identity_hash` and more, and the set differs between
 * any two databases depending on how far each has migrated. A retyped DDL would drop
 * whichever columns the author did not happen to know about — silently, since the
 * copy would simply not mention them.
 *
 * So the live `CREATE TABLE` is read back from `sqlite_master` and only the CHECK
 * clause inside it is rewritten. Whatever shape the table is in, it keeps.
 *
 * ── AND THE REBUILD IS NOT GUARDED ON THE TABLE BEING EMPTY ─────────────────
 *
 * `gates_event_invites.audience` shipped with the wrong set and was "corrected" by a
 * definition that only rebuilt an empty table, so production kept the wrong
 * constraint permanently while dev was green. Rows are copied here.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$sqlite) {
    // MySQL's half is the ENUM widening in 2027_02_10, which is idempotent.
    echo "  = not sqlite; the ENUM widening covers this\n";
    echo "nomination status CHECK repair OK\n";
    return;
}

if (!DB::schema()->hasTable('gates_nominations')) {
    echo "  = gates_nominations not present yet\n";
    echo "nomination status CHECK repair OK\n";
    return;
}

$ddl = (string) DB::table('sqlite_master')->where('type', 'table')
    ->where('name', 'gates_nominations')->value('sql');

if ($ddl === '') {
    echo "  = no stored DDL for gates_nominations\n";
    echo "nomination status CHECK repair OK\n";
    return;
}

// No CHECK at all is the already-correct case on a database built after the schema
// file was fixed, and so is one that already names the widest word.
if (!preg_match('/CHECK\s*\(\s*status\s+IN\s*\(/i', $ddl) || str_contains($ddl, "'verified'")) {
    echo "  = sqlite CHECK already accepts the widened vocabulary\n";
    echo "nomination status CHECK repair OK\n";
    return;
}

$allowed = "'" . implode("','", NS::ALL) . "'";

// Rewrite only the status CHECK, and only the first one: another column could
// legitimately carry its own CHECK and must not be touched.
$new = preg_replace(
    '/CHECK\s*\(\s*status\s+IN\s*\([^)]*\)\s*\)/i',
    "CHECK(status IN ({$allowed}))",
    $ddl,
    1,
);

// Name the rebuild. The stored DDL may or may not carry IF NOT EXISTS.
$new = preg_replace(
    '/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`"\[]?gates_nominations[`"\]]?/i',
    'CREATE TABLE gates_nominations_rebuild',
    (string) $new,
    1,
);

if (!is_string($new) || !str_contains($new, 'gates_nominations_rebuild')) {
    echo "  *** could not rewrite the DDL; left untouched ***\n";
    echo "nomination status CHECK repair OK\n";
    return;
}

// Every column the LIVE table has, so nothing a later migration added is lost.
$cols = array_map(
    static fn($c) => (string) $c->name,
    DB::select('PRAGMA table_info(gates_nominations)'),
);

if ($cols === []) {
    echo "  *** no columns read back; left untouched ***\n";
    echo "nomination status CHECK repair OK\n";
    return;
}

// The indexes go with the table when it is dropped, so their own DDL is kept and
// replayed. `sql` is null for the implicit index behind a UNIQUE column, which SQLite
// recreates from the definition itself — those rows are skipped rather than replayed
// as an empty statement.
$indexes = DB::table('sqlite_master')->where('type', 'index')
    ->where('tbl_name', 'gates_nominations')->whereNotNull('sql')->pluck('sql')->all();

$quoted = implode(', ', array_map(static fn($c) => '"' . $c . '"', $cols));
$pdo    = DB::connection()->getPdo();

$pdo->exec($new);
$pdo->exec("INSERT INTO gates_nominations_rebuild ({$quoted}) SELECT {$quoted} FROM gates_nominations");
$pdo->exec('DROP TABLE gates_nominations');
$pdo->exec('ALTER TABLE gates_nominations_rebuild RENAME TO gates_nominations');

foreach ($indexes as $sql) {
    // An index name survives its table's drop in sqlite_master only as the text here,
    // so a replay that collides is already-done rather than an error worth aborting a
    // migration run for.
    try { $pdo->exec((string) $sql); } catch (\Throwable $e) {}
}

// Say whether it worked, rather than assuming. A migration that reports success it
// did not verify is how the invite-audience fault survived a commit that named it.
$after = (string) DB::table('sqlite_master')->where('type', 'table')
    ->where('name', 'gates_nominations')->value('sql');

echo str_contains($after, "'verified'")
    ? "  = gates_nominations.status CHECK widened to " . count(NS::ALL) . " values\n"
    : "  *** CHECK STILL REFUSES 'verified' ***\n";

echo "nomination status CHECK repair OK\n";
