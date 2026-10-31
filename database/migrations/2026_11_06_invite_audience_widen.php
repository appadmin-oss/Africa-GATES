<?php
/**
 * Let a nominee be minted on a database that already had invitations in it.
 *
 * ── THE BUG ──────────────────────────────────────────────────────────────────
 *
 * "Build the list" produced judges and only judges, every time, on production. It could
 * not be reproduced in dev or in the suite, and the screen reported a count that was
 * true — so the report read as "the nominee half is broken" with nothing to say why.
 *
 * The cause is in `2026_11_01_event_invites.php`, one commit of it ago. That migration
 * first shipped `audience ENUM('principal','child','judge')`, from a taxonomy invented
 * out of two example programmes. The next commit corrected it to
 * `ENUM('nominee','judge')` — but the migration only REBUILDS the table when it is
 * EMPTY, and returns untouched when it has rows, because dropping rows somebody has
 * already been written against would destroy the record of who was written to. That
 * guard is right. Its consequence was not noticed: a production database that had
 * minted even one invitation kept the three-value column forever.
 *
 * 'judge' is in BOTH sets. 'nominee' is in neither the old one nor anything the old
 * column will accept. So every judge minted and every nominee did not, on exactly the
 * split an operator sees:
 *
 *   • strict mode (this app's connection sets it) — the INSERT raises "Data truncated
 *     for column 'audience'", {@see \AfricaGates\Services\EventInvites::mint()} catches
 *     it and returns null, and the nominee is missing.
 *   • non-strict (a host that overrides sql_mode, which shared hosting does) — MySQL
 *     stores the ENUM error value '' instead. The row EXISTS and is invisible: every
 *     read is `where('audience', ...)`, so it is counted in no audience, listed under
 *     none, and sent nothing — while holding the (event_id, email) unique key that
 *     stops the person ever being minted again.
 *
 * Neither reaches dev. SQLite builds this table fresh with the corrected CHECK, and its
 * CHECK is honoured — so the suite asserts the right constraint against the right
 * schema and passes, which is the whole shape of the MySQL/SQLite divergence this
 * codebase keeps paying for.
 *
 * ── THE REPAIR ───────────────────────────────────────────────────────────────
 *
 * MySQL goes ENUM → VARCHAR → remap → ENUM rather than ALTERing one ENUM into another.
 * A direct MODIFY has to convert every existing value into the new set as it goes, and
 * the '' error values above are precisely the ones with nothing to convert to; through
 * VARCHAR every value, including '', is just a string that can be UPDATEd first.
 *
 * Everything that is not 'judge' becomes 'nominee', and that is a rewrite of history
 * only in the sense of undoing one: 'principal' and 'child' were both nominees under
 * the taxonomy that was withdrawn, and '' can only ever be a truncated 'nominee'
 * because 'judge' was always valid and never truncated. No row's meaning changes.
 *
 * SQLite rebuilds the table, because a CHECK cannot be altered in place — for the
 * development database that was carrying rows when 11_01 landed and got the same
 * three-value constraint left on it.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();

if (!$schema->hasTable('gates_event_invites')) {
    echo "gates_event_invites absent — skipped\n";
    echo "invite audience widen OK\n";
    return;
}

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── MySQL / MariaDB ──────────────────────────────────────────────────────────
if (!$sqlite) {
    $current = '';
    try {
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['gates_event_invites', 'audience']
        );
        $current = strtolower((string) ($row->t ?? ''));
    } catch (\Throwable $e) {
        echo '  *** could not read the audience column type: ' . $e->getMessage() . "\n";
    }

    // Read the type rather than assume it: on a database built from the corrected
    // migration there is nothing to widen, and this must be a no-op there.
    if (str_contains($current, "'nominee'")) {
        echo "  = audience already accepts 'nominee' ({$current})\n";
    } else {
        try {
            DB::statement('ALTER TABLE gates_event_invites MODIFY audience VARCHAR(16) NOT NULL');
            echo "  ~ audience relaxed to VARCHAR for the remap (was {$current})\n";
        } catch (\Throwable $e) {
            // Fatal for this migration only. Nothing is half-done: the column is either
            // still the old ENUM (the bug, unchanged) or the new one, never between.
            echo '  *** FAILED *** could not relax the audience column: ' . $e->getMessage() . "\n";
            echo "invite audience widen OK\n";
            return;
        }
    }

    // Runs even when the column was already correct: a database repaired by hand, or one
    // that took '' rows under a non-strict host before the ENUM was fixed, still carries
    // rows that belong to no audience and would fail the narrowing below.
    try {
        $fixed = DB::table('gates_event_invites')
            ->whereNotIn('audience', ['nominee', 'judge'])
            ->update(['audience' => 'nominee']);
        if ($fixed > 0) echo "  ~ {$fixed} invitation(s) restored to the nominee audience\n";
    } catch (\Throwable $e) {
        echo '  *** FAILED *** could not remap the old audience values: ' . $e->getMessage() . "\n";
        echo "invite audience widen OK\n";
        return;
    }

    if (!str_contains($current, "'nominee'")) {
        try {
            DB::statement(
                "ALTER TABLE gates_event_invites MODIFY audience ENUM('nominee','judge') NOT NULL"
            );
            echo "  + audience is ENUM('nominee','judge')\n";
        } catch (\Throwable $e) {
            // The column is left as VARCHAR(16), which ACCEPTS 'nominee' — so the bug is
            // fixed and only the constraint is missing. Reporting the narrowing failure
            // matters more than reverting a column that now works.
            echo '  *** the remap succeeded, the narrowing did not: ' . $e->getMessage() . "\n";
        }
    }

    echo "invite audience widen OK\n";
    return;
}

// ── SQLite ───────────────────────────────────────────────────────────────────
$ddl = (string) DB::table('sqlite_master')->where('type', 'table')
    ->where('name', 'gates_event_invites')->value('sql');

if ($ddl === '' || !str_contains($ddl, "'principal'")) {
    echo "  = sqlite CHECK already allows 'nominee'\n";
    echo "invite audience widen OK\n";
    return;
}

$pdo = DB::connection()->getPdo();

// RESTORED, not forced back on. The precedent this rebuild follows ends with
// `PRAGMA foreign_keys = ON`, which is right for a migration that only ever runs while a
// schema is being built — and wrong the moment one is required from a test, where the
// harness has deliberately turned enforcement OFF so unit seeds can stay minimal. Turning
// it on under a suite that assumes otherwise breaks whatever runs next, far from here.
$fkWasOn = false;
try {
    $fkWasOn = (bool) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
} catch (\Throwable $e) {
    // Old SQLite builds without the pragma: nothing to restore, nothing to break.
}

try {
    $pdo->exec('PRAGMA foreign_keys = OFF');

    $pdo->exec("CREATE TABLE gates_event_invites_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        cycle_id INTEGER NULL,
        audience TEXT NOT NULL CHECK(audience IN ('nominee','judge')),
        nominee_id INTEGER NULL,
        judge_id INTEGER NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        reference TEXT NOT NULL UNIQUE,
        id_secret TEXT NOT NULL,
        discount_code TEXT NULL,
        guest_quota INTEGER NOT NULL DEFAULT 0,
        sent_at TEXT NULL,
        opened_at TEXT NULL,
        scans INTEGER NOT NULL DEFAULT 0,
        last_scan_at TEXT NULL,
        created_at TEXT NOT NULL
    )");

    // Only the columns that actually exist, so the rebuild survives schema drift on the
    // live table — the pattern 2026_07_26_cycle_shortlisting_phase.php established.
    $declared = ['id', 'event_id', 'cycle_id', 'audience', 'nominee_id', 'judge_id',
                 'name', 'email', 'reference', 'id_secret', 'discount_code',
                 'guest_quota', 'sent_at', 'opened_at', 'scans', 'last_scan_at',
                 'created_at'];
    $present  = array_values(array_intersect($declared, $schema->getColumnListing('gates_event_invites')));

    // Remapped in the SELECT, not after the copy: the new CHECK would reject a
    // 'principal' row on the way in.
    $select = [];
    foreach ($present as $c) {
        $select[] = $c === 'audience'
            ? "CASE WHEN audience = 'judge' THEN 'judge' ELSE 'nominee' END"
            : $c;
    }

    $cols = implode(', ', $present);
    $pdo->exec('INSERT INTO gates_event_invites_new (' . $cols . ') SELECT '
             . implode(', ', $select) . ' FROM gates_event_invites');
    $pdo->exec('DROP TABLE gates_event_invites');
    $pdo->exec('ALTER TABLE gates_event_invites_new RENAME TO gates_event_invites');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_invite_person ON gates_event_invites (event_id, email)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invite_event ON gates_event_invites (event_id, audience)');

    echo "  + sqlite gates_event_invites rebuilt with 'nominee' in the CHECK\n";
} catch (\Throwable $e) {
    echo '  *** FAILED *** sqlite rebuild: ' . $e->getMessage() . "\n";
} finally {
    try { $pdo->exec('PRAGMA foreign_keys = ' . ($fkWasOn ? 'ON' : 'OFF')); } catch (\Throwable $e) {}
}

$after = (string) DB::table('sqlite_master')->where('type', 'table')
    ->where('name', 'gates_event_invites')->value('sql');
echo str_contains($after, "'nominee'") ? "  OK verified\n" : "  *** STILL MISSING ***\n";

echo "invite audience widen OK\n";
