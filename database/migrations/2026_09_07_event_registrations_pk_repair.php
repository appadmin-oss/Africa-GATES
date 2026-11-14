<?php
/**
 * Give `gates_event_registrations` its primary key back, if something took it away.
 *
 * ── WHAT THIS REPAIRS ────────────────────────────────────────────────────────
 *
 * `2026_09_03_event_tickets.php` has to rebuild this table on SQLite, because SQLite cannot
 * drop a UNIQUE constraint and the old table carried `UNIQUE (event_id, email)` — which a
 * paid tier must not have, since somebody buying a second, different ticket is not an error.
 *
 * A rebuild done with `CREATE TABLE … AS SELECT` looks correct and is not: that form copies
 * VALUES and infers column types, so `id INTEGER PRIMARY KEY AUTOINCREMENT` comes out the
 * other side as a plain `id INT`. The shipped migration rebuilds from the table's own DDL
 * instead and refuses to proceed if it cannot, so a database migrated with the released code
 * is fine. This exists for one that was not.
 *
 * ── WHY IT IS WORTH A MIGRATION OF ITS OWN ───────────────────────────────────
 *
 * The failure is silent and total. With no primary key, SQLite writes `id = NULL` on every
 * insert while `insertGetId()` cheerfully returns the implicit rowid — so every subsequent
 * `where('id', …)` matches nothing, and NOTHING ERRORS. Check-in cannot find a ticket. A
 * waitlist cannot work out anybody's place. Confirming a payment updates zero rows and the
 * caller is told the registration was already confirmed. An organiser would experience this
 * as the entire ticketing feature quietly not working, with no message anywhere to explain it.
 *
 * Idempotent, and a no-op on a healthy table — including every MySQL deployment, where the
 * rebuild path never runs at all. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

// Written in the codebase's usual form — `$sqlite` as an explicit driver test — because the
// whole of this file is a SQLite-only repair, and SchemaIndexTest scans every migration for
// `CREATE INDEX IF NOT EXISTS` outside a recognisable sqlite branch. That syntax is valid
// SQLite and INVALID MySQL, where it silently does nothing.
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$sqlite) {
    // MySQL never takes the rebuild path in 2026_09_03_event_tickets.php at all: it can drop
    // a unique key with ALTER TABLE, so its primary key was never at risk.
    echo "  = not sqlite; nothing to repair\n";
    echo "registration key OK\n";
    return;
}

if (!DB::schema()->hasTable('gates_event_registrations')) {
    echo "  = gates_event_registrations not present yet\n";
    echo "registration key OK\n";
    return;
}

$ddl = (string) (DB::selectOne(
    "SELECT sql FROM sqlite_master WHERE type='table' AND name='gates_event_registrations'"
)->sql ?? '');

// A healthy table declares its key inline. Matched loosely on purpose — the point is whether
// `id` is the INTEGER PRIMARY KEY, not how the DDL happens to be spaced or quoted.
if ($ddl === '' || preg_match('/\bid\b[^,]*INTEGER\s+PRIMARY\s+KEY/i', $ddl) === 1) {
    echo "  = gates_event_registrations already has its primary key\n";
    echo "registration key OK\n";
    return;
}

echo "  ! gates_event_registrations has no integer primary key — rebuilding\n";

// Every column the damaged table actually has, so the copy neither invents nor drops one.
$cols = array_map(
    static fn ($c): string => (string) $c->name,
    DB::select("PRAGMA table_info('gates_event_registrations')")
);
if ($cols === [] || !in_array('id', $cols, true)) {
    echo "  ! could not read the columns; leaving the table alone\n";
    echo "registration key OK\n";
    return;
}

// Rebuilt from the DDL rather than from a hand-written CREATE, for the same reason the
// original migration does: this table has grown columns across several migrations and a
// hard-coded list here would silently drop whichever one was added last.
//
// Only the `id` declaration is rewritten. Anchored to the start of the column list so a
// column called `paid_id` cannot be caught by it.
$rebuilt = preg_replace(
    '/(CREATE\s+TABLE\s+"?gates_event_registrations"?\s*\(\s*)\bid\b[^,]*/is',
    '$1id INTEGER PRIMARY KEY AUTOINCREMENT',
    $ddl,
    1,
    $done
);
if (!$done || $rebuilt === null) {
    echo "  ! the id column could not be located in the DDL; leaving the table alone\n";
    echo "registration key OK\n";
    return;
}
$rebuilt = (string) preg_replace(
    '/CREATE\s+TABLE\s+"?gates_event_registrations"?/i',
    'CREATE TABLE gates_event_registrations_new',
    $rebuilt,
    1
);

$list  = implode(', ', $cols);
// The rows are renumbered from their rowid. Their ids were NULL, so there is nothing to
// preserve — and rowid is the value insertGetId() had already been handing back, which means
// anything that happened to store one still lines up.
$others = implode(', ', array_map(static fn (string $c): string => $c === 'id' ? 'rowid' : $c, $cols));

try {
    DB::statement('DROP TABLE IF EXISTS gates_event_registrations_new');
    DB::statement($rebuilt);
    DB::statement("INSERT INTO gates_event_registrations_new ({$list}) SELECT {$others} FROM gates_event_registrations");
    DB::statement('DROP TABLE gates_event_registrations');
    DB::statement('ALTER TABLE gates_event_registrations_new RENAME TO gates_event_registrations');

    // The indexes went with the old table.
    DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_event ON gates_event_registrations (event_id, status)");
    DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_tier ON gates_event_registrations (tier_id, status)");
    DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_ticket ON gates_event_registrations (ticket_code)");
    if (in_array('reference', $cols, true)) {
        DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_ref ON gates_event_registrations (reference)");
    }
    if (in_array('waitlist_at', $cols, true)) {
        DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_waitlist ON gates_event_registrations (event_id, status, waitlist_at)");
    }

    $n = (int) DB::table('gates_event_registrations')->count();
    echo "  + gates_event_registrations rebuilt with a real primary key ({$n} row(s) renumbered)\n";
} catch (\Throwable $e) {
    echo "  ! rebuild failed: " . $e->getMessage() . "\n";
}

echo "registration key OK\n";
