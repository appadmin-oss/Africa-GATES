<?php
/**
 * The arrivals log — and the reason it had to exist before a check-in could be reversed.
 *
 * ── THE FAULT THIS CLOSES ───────────────────────────────────────────────────
 *
 * `gates_event_registrations.checked_in_at` was written by the door and cleared by nothing,
 * anywhere. That is not merely a missing convenience: the column is terminal, and four other
 * things gate on it —
 *
 *   • the door refuses a second scan ("Already checked in")
 *   • TicketSelfService refuses a rename
 *   • TicketSelfService refuses a transfer
 *   • EventRefundPolicy refuses the REFUND
 *
 * So a steward's camera catching the ticket of the person behind in the queue permanently
 * marked that attendee admitted, turned them away at the door, stopped them handing the
 * ticket to somebody who could use it, and stopped them getting their money back. The only
 * remedy was an UPDATE by hand on a host with no shell.
 *
 * ── WHY A LOG TABLE AND NOT JUST A NULLABLE COLUMN ──────────────────────────
 *
 * Because "was this person admitted?" and "what happened at this door?" are different
 * questions and only the first fits in a column. Setting `checked_in_at` back to NULL erases
 * the fact that somebody was scanned in at 19:42 and un-scanned at 19:43 — which is precisely
 * the record an organiser needs when the attendee disputes it afterwards. The column stays as
 * the CURRENT state; this table is the history, and a reversal appends rather than deletes.
 *
 * It also gives `checked_in_via` and `checked_in_by` something to be checked against. Both
 * were written from day one and read by nothing — see `docs/CODEBASE-INDEX.md` §17 for why
 * that shape is the most expensive bug available on a host with no shell.
 *
 * ── GUESTS OF HONOUR ARE IN HERE TOO ────────────────────────────────────────
 *
 * `registration_id` and `invite_id` are both nullable and exactly one is set. A nominee
 * admitted on an invitation has no registration row by design — minting them a complimentary
 * ticket would have counted as a sale and stopped the hall selling — so a log keyed only to
 * registrations would omit every honoured guest from the record of who was in the room.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$driver = DB::connection()->getDriverName();
$sqlite = $driver === 'sqlite';

if (!DB::schema()->hasTable('gates_event_checkin_log')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_event_checkin_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            registration_id INTEGER NULL,
            invite_id INTEGER NULL,
            action TEXT NOT NULL DEFAULT 'admit',
            seats INTEGER NOT NULL DEFAULT 1,
            who TEXT NULL,
            via TEXT NULL,
            admin_id INTEGER NULL,
            reason TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_event_checkin_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            -- Exactly one of these is set. See the note above on why an invite needs its own
            -- column rather than a synthetic registration row.
            registration_id INT UNSIGNED NULL,
            invite_id BIGINT UNSIGNED NULL,
            action ENUM('admit','undo') NOT NULL DEFAULT 'admit',
            -- Seats as they were AT THE TIME. A ticket's quantity can change afterwards
            -- through a transfer, and a headcount computed from today's quantity would
            -- silently rewrite last night's door.
            seats SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            -- The name as it was read out at the door, denormalised deliberately: this is a
            -- record of an event, and a renamed ticket must not rewrite what happened.
            who VARCHAR(160) NULL,
            -- 'door: Main gate' or 'admin #3'. The same string `checked_in_via` holds.
            via VARCHAR(60) NULL,
            admin_id BIGINT UNSIGNED NULL,
            -- Why it was reversed. Required for an undo, null for an admission.
            reason VARCHAR(200) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_chklog_event (event_id, id),
            KEY idx_chklog_reg (registration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_event_checkin_log created\n";
} else {
    echo "  = gates_event_checkin_log already present\n";
}

if ($sqlite) {
    foreach ([
        'idx_chklog_event' => 'CREATE INDEX IF NOT EXISTS idx_chklog_event ON gates_event_checkin_log (event_id, id)',
        'idx_chklog_reg'   => 'CREATE INDEX IF NOT EXISTS idx_chklog_reg ON gates_event_checkin_log (registration_id)',
    ] as $name => $sql) {
        try { DB::statement($sql); echo "  = {$name} ensured\n"; }
        catch (\Throwable $e) { echo "  ! {$name}: " . $e->getMessage() . "\n"; }
    }
}

// Guests of honour need the same pair the ticket path has, for the same reason: without it
// the record of an evening says a volunteer's scan was nobody's.
foreach (['last_scan_via' => $sqlite ? 'TEXT' : 'VARCHAR(60) NULL'] as $col => $type) {
    if (DB::schema()->hasTable('gates_event_invites')
        && !DB::schema()->hasColumn('gates_event_invites', $col)) {
        DB::statement("ALTER TABLE gates_event_invites ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_event_invites.{$col} added\n";
    } else {
        echo "  = gates_event_invites.{$col} already present or table absent\n";
    }
}

echo "check-in log OK\n";
