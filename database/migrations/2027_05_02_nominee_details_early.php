<?php
/**
 * A nominee's own page from the moment they are nominated, not only once approved (owner, 2 Nov
 * 2026: ask the nominee "to complete any essential information … add more evidence … or add an
 * evidence if none", while it can still decide the review).
 *
 * Before approval there is no gates_nominees row, so a page that exists then is keyed on the
 * NOMINATION, and approval attaches it to the nominee it creates (NomineeDetails::invite):
 *
 *   gates_nominee_details.nominee_id   NOT NULL → NULL. NULL means "not approved yet"; the
 *                                      UNIQUE on it still holds one row per nominee (both
 *                                      engines allow many NULLs under a UNIQUE).
 *   gates_nominee_files.nominee_id     NOT NULL → NULL, for the same reason.
 *   gates_nominee_files.nomination_id  new: which nomination a file was added to before there
 *                                      was a nominee, so approval can move it across.
 *
 * A REPAIR, not a corrected definition: 2027_04_25 has already built both tables NOT NULL on
 * every database that ran it, and only a statement that changes the existing column reaches
 * those. MySQL takes MODIFY; SQLite cannot change a column's nullability, so the table is
 * rebuilt (copy, drop, rename) and its indexes made again. Each step is guarded on the column
 * actually still being NOT NULL, so a second run does nothing. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

/** Is this column still NOT NULL? */
$notNull = static function (string $table, string $col) use ($sqlite): bool {
    if ($sqlite) {
        foreach (DB::select("PRAGMA table_info($table)") as $c) {
            if ((string) $c->name === $col) return (int) $c->notnull === 1;
        }
        return false;
    }
    $r = DB::selectOne('SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col]);
    return $r !== null && strtoupper((string) $r->n) === 'NO';
};

/** SQLite: rebuild $table with $col nullable, keeping every column and row. */
$rebuild = static function (string $table, string $col): void {
    $cols = DB::select("PRAGMA table_info($table)");
    $defs = [];
    $names = [];
    foreach ($cols as $c) {
        $n = (string) $c->name;
        $names[] = '"' . $n . '"';
        if ((int) $c->pk === 1) { $defs[] = "\"$n\" INTEGER PRIMARY KEY AUTOINCREMENT"; continue; }
        $type = (string) $c->type ?: 'TEXT';
        $null = ($n === $col || (int) $c->notnull === 0) ? ' NULL' : ' NOT NULL';
        $def = $c->dflt_value !== null ? ' DEFAULT ' . $c->dflt_value : '';
        $defs[] = "\"$n\" $type$null$def";
    }
    $tmp = $table . '_rebuild';
    DB::statement("DROP TABLE IF EXISTS $tmp");
    DB::statement("CREATE TABLE $tmp (" . implode(', ', $defs) . ')');
    $list = implode(', ', $names);
    DB::statement("INSERT INTO $tmp ($list) SELECT $list FROM $table");
    DB::statement("DROP TABLE $table");
    DB::statement("ALTER TABLE $tmp RENAME TO $table");
};

if ($schema->hasTable('gates_nominee_files') && !$schema->hasColumn('gates_nominee_files', 'nomination_id')) {
    DB::statement('ALTER TABLE gates_nominee_files ADD COLUMN nomination_id ' . ($sqlite ? 'INTEGER NULL' : 'INT UNSIGNED NULL'));
    echo "  + gates_nominee_files.nomination_id\n";
}

foreach (['gates_nominee_details', 'gates_nominee_files'] as $t) {
    if (!$schema->hasTable($t) || !$notNull($t, 'nominee_id')) continue;
    if ($sqlite) {
        $rebuild($t, 'nominee_id');
    } else {
        DB::statement("ALTER TABLE $t MODIFY nominee_id INT UNSIGNED NULL");
    }
    echo "  ~ $t.nominee_id may be empty until approval\n";
}

if ($schema->hasTable('gates_nominee_details')) {
    SchemaIndex::ensure('gates_nominee_details', 'uq_nomdet_nominee', ['nominee_id'], true);
    SchemaIndex::ensure('gates_nominee_details', 'idx_nomdet_user', ['user_id']);
    SchemaIndex::ensure('gates_nominee_details', 'idx_nomdet_nomination', ['nomination_id']);
}
if ($schema->hasTable('gates_nominee_files')) {
    SchemaIndex::ensure('gates_nominee_files', 'idx_nomfile_nominee', ['nominee_id', 'kind']);
    SchemaIndex::ensure('gates_nominee_files', 'idx_nomfile_nomination', ['nomination_id', 'kind']);
}
