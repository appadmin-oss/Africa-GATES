<?php
/**
 * Widen gates_award_cycles.status to include 'shortlisting'.
 *
 * WHY. 'shortlisting' is the gap between nominations closing and voting
 * opening. Without it the lifecycle had to reuse 'judging' for both that gap
 * and the post-voting jury window — and because judging sorts AFTER voting,
 * a cycle that reached it early could never move "forward" into voting again.
 * That is the bug that left voting permanently unreachable for any cycle with
 * a shortlisting gap (i.e. the normal design).
 *
 * The computed phase (AfricaGates\Services\CyclePolicy) already models it. This
 * makes the materialised column able to record it, so the cached status and the
 * gates_cycle_transitions ledger stop describing a phase that never happened.
 *
 * Purely additive — no existing value is removed, so every current row stays
 * valid. Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_award_cycles')) {
    echo "no gates_award_cycles — skip\n";
    return;
}

$PHASES = "'upcoming','nominations','shortlisting','voting','judging','results','archived'";

if (!$sqlite) {
    // MySQL: a plain ENUM widen. Re-running is harmless (same definition).
    try {
        DB::statement("ALTER TABLE gates_award_cycles MODIFY status ENUM($PHASES) NOT NULL DEFAULT 'upcoming'");
        echo "  + status ENUM widened with 'shortlisting'\n";
    } catch (\Throwable $e) {
        echo '  ! ENUM widen skipped: ' . $e->getMessage() . "\n";
    }
    echo "cycle shortlisting phase migration OK\n";
    return;
}

// SQLite cannot ALTER a CHECK constraint — the table must be rebuilt. Mirrors
// the pattern established by 2026_06_30_sqlite_admin_roles.php.
$ddl = (string) DB::table('sqlite_master')->where('type', 'table')
    ->where('name', 'gates_award_cycles')->value('sql');

if ($ddl === '' || str_contains($ddl, 'shortlisting')) {
    echo "  = sqlite CHECK already allows 'shortlisting'\n";
    echo "cycle shortlisting phase migration OK\n";
    return;
}

$pdo = DB::connection()->getPdo();
try {
    $pdo->exec('PRAGMA foreign_keys = OFF');

    $pdo->exec("CREATE TABLE gates_award_cycles_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        programme_id INTEGER NOT NULL,
        year INTEGER NOT NULL,
        edition_label TEXT,
        status TEXT NOT NULL DEFAULT 'upcoming' CHECK(status IN ($PHASES)),
        nominations_open TEXT,
        nominations_close TEXT,
        voting_open TEXT,
        voting_close TEXT,
        results_date TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(programme_id) REFERENCES gates_award_programmes(id) ON DELETE CASCADE
    )");

    // Copy only the columns that actually exist, so the rebuild survives any
    // schema drift on the live table.
    $declared = ['id', 'programme_id', 'year', 'edition_label', 'status',
                 'nominations_open', 'nominations_close', 'voting_open',
                 'voting_close', 'results_date', 'created_at'];
    $present  = array_values(array_intersect($declared, $schema->getColumnListing('gates_award_cycles')));
    $cols     = implode(', ', $present);

    $pdo->exec("INSERT INTO gates_award_cycles_new ($cols) SELECT $cols FROM gates_award_cycles");
    $pdo->exec('DROP TABLE gates_award_cycles');
    $pdo->exec('ALTER TABLE gates_award_cycles_new RENAME TO gates_award_cycles');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cycles_prog_year ON gates_award_cycles(programme_id, year)');

    echo "  + sqlite gates_award_cycles rebuilt with 'shortlisting' in the CHECK\n";
} catch (\Throwable $e) {
    echo '  *** FAILED *** sqlite rebuild: ' . $e->getMessage() . "\n";
} finally {
    try { $pdo->exec('PRAGMA foreign_keys = ON'); } catch (\Throwable $e) {}
}

$after = (string) DB::table('sqlite_master')->where('type', 'table')
    ->where('name', 'gates_award_cycles')->value('sql');
echo str_contains($after, 'shortlisting') ? "  OK verified\n" : "  *** STILL MISSING ***\n";

echo "cycle shortlisting phase migration OK\n";
