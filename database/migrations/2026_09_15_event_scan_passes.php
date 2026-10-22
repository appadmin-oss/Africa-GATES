<?php
/**
 * Door passes: a time-boxed, revocable link that lets somebody scan tickets without an admin
 * account.
 *
 * ── WHY NOT JUST GIVE THE DOOR STAFF A LOGIN ────────────────────────────────
 *
 * Because of who actually works a door. It is volunteers, a venue's own staff, somebody's
 * cousin with a phone — people who exist for four hours and must not end up holding an admin
 * account on a platform that runs an awards cycle and moves money. Issuing real accounts for a
 * gala means either creating and deleting a dozen of them by hand, or leaving them behind.
 *
 * So the capability is scoped to the smallest useful thing: check tickets in, for ONE event,
 * between two timestamps. It cannot read the attendee list, cannot see money, cannot reach
 * any other event, and stops working by itself when the event is over. Nobody has to remember
 * to take it away — which is the part that never happens.
 *
 * ── ONLY THE HASH IS STORED ─────────────────────────────────────────────────
 *
 * Same doctrine as `gates_ticket_links`: a dump of this table yields nothing usable. The token
 * is shown to the admin once, at creation, because that is the only moment it exists in a form
 * anybody can copy.
 *
 * ── AND WHY THE WINDOW IS TWO COLUMNS, NOT A DURATION ───────────────────────
 *
 * A door opens at a wall-clock time and closes at one. Storing "valid for 6 hours" would mean
 * the window moves if the pass is created early, which is exactly when a careful organiser
 * creates it. Both ends are absolute, both are editable, and `opens_at` may be NULL for a pass
 * that should work immediately — an organiser standing at the door with a queue already
 * forming should not have to fill in a start time.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_event_scan_passes')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_event_scan_passes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            label TEXT NULL,
            opens_at TEXT NULL,
            closes_at TEXT NOT NULL,
            created_by INTEGER NULL,
            revoked_at TEXT NULL,
            last_used_at TEXT NULL,
            scans INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_event_scan_passes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id BIGINT UNSIGNED NOT NULL,
            -- SHA-256 only. See the note above.
            token_hash CHAR(64) NOT NULL,
            -- 'Main gate', 'VIP entrance'. Shown on the scanning page so somebody holding two
            -- links knows which door they are on, and in the audit trail afterwards.
            label VARCHAR(60) NULL,
            -- NULL means 'works now'. An organiser at the door with a queue forming should not
            -- have to type a start time.
            opens_at TIMESTAMP NULL,
            -- NOT NULL, deliberately: a door pass with no end is an admin account with extra
            -- steps, and the whole point is that it expires without anybody remembering.
            closes_at TIMESTAMP NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            revoked_at TIMESTAMP NULL,
            last_used_at TIMESTAMP NULL,
            scans INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_scanpass_token (token_hash),
            KEY idx_scanpass_event (event_id, closes_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_event_scan_passes created\n";
} else {
    echo "  = gates_event_scan_passes already present\n";
}

if ($sqlite) {
    foreach ([
        'uq_scanpass_token' => 'CREATE UNIQUE INDEX IF NOT EXISTS uq_scanpass_token ON gates_event_scan_passes (token_hash)',
        'idx_scanpass_event' => 'CREATE INDEX IF NOT EXISTS idx_scanpass_event ON gates_event_scan_passes (event_id, closes_at)',
    ] as $name => $sql) {
        try { DB::statement($sql); echo "  = {$name} ensured\n"; }
        catch (\Throwable $e) { echo "  ! {$name}: " . $e->getMessage() . "\n"; }
    }
}

// Who admitted somebody, when a door pass rather than an admin did it. `checked_in_by` holds
// an admin id and cannot answer this; without it the attendee list says a volunteer's scan was
// nobody's, which is the one question asked after a disputed entry.
if (DB::schema()->hasTable('gates_event_registrations')
    && !DB::schema()->hasColumn('gates_event_registrations', 'checked_in_via')) {
    DB::statement('ALTER TABLE gates_event_registrations ADD COLUMN checked_in_via '
        . ($sqlite ? 'TEXT' : 'VARCHAR(60) NULL') . ' DEFAULT NULL');
    echo "  + gates_event_registrations.checked_in_via added\n";
} else {
    echo "  = gates_event_registrations.checked_in_via already present\n";
}

echo "event scan passes OK\n";
