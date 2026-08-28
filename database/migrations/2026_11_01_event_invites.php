<?php
/**
 * The award event an award programme leads to, and the guests of honour invited to it.
 *
 * ── WHY THE EVENT NEEDS A PROGRAMME ──────────────────────────────────────────
 *
 * `gates_site_events` has always been a standalone listing: a date, a venue, ticket
 * tiers. Nothing on it said which awards cycle it was the ceremony FOR, so there was no
 * way to answer "the shortlist for this cycle is invited to which event" without an
 * operator retyping the answer into every message. `programme_id` is that link, and it
 * is nullable because most events are not ceremonies.
 *
 * ── WHY AN INVITE IS NOT A TICKET ────────────────────────────────────────────
 *
 * The obvious implementation is a complimentary ticket per invitee, and it is wrong
 * twice. `gates_event_registrations` carries UNIQUE(event_id, email), so a nominee who
 * had already bought a seat could not be invited; and every seat-accounting read —
 * `sold()`, `attendingForEvent()`, capacity, the tier ladder — would count guests of
 * honour as sales, so a hall mobilising for a thousand paying attendees would report
 * itself fuller than it was and stop selling.
 *
 * A guest of honour is not a sale. They get a row here, their own reference, and their
 * own door verdict. What they DO carry into the ticketing system is a discount code for
 * the people they bring, which is the only place the two systems need to meet.
 *
 * ── `id_secret`, AND WHY THE REFERENCE ALONE IS NOT ENOUGH ────────────────────
 *
 * The mobile ID shows a QR that rotates on a short window, so a screenshot passed round
 * a car park stops working. Rotation is only worth anything if the code cannot be
 * computed by somebody holding the reference — a reference that appears in an email, in
 * a letter, and on a screen. So each invite carries its own secret, the rotating code is
 * an HMAC under it, and the door verifies rather than looks up. No write per refresh.
 *
 * `guest_quota` is resolved onto the row when the invite is minted rather than read from
 * settings at send time. The letter tells somebody they may bring twenty-five people and
 * their code has to allow exactly that many — if an operator later edits the setting, the
 * promise already made in writing must not silently change under the person who received
 * it.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1. the ceremony's programme ──────────────────────────────────────────────
if (DB::schema()->hasTable('gates_site_events')
    && !DB::schema()->hasColumn('gates_site_events', 'programme_id')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_site_events ADD COLUMN programme_id INTEGER NULL'
        : 'ALTER TABLE gates_site_events ADD COLUMN programme_id TINYINT UNSIGNED NULL AFTER slug');
    echo "  + gates_site_events.programme_id\n";
} else {
    echo "  · gates_site_events.programme_id already present\n";
}

// ── 2. the guests of honour ──────────────────────────────────────────────────
if (DB::schema()->hasTable('gates_event_invites')) {
    echo "  · gates_event_invites already exists\n";
    return;
}

DB::statement($sqlite
    ? "CREATE TABLE gates_event_invites (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         event_id INTEGER NOT NULL,
         cycle_id INTEGER NULL,
         -- 'principal' | 'child' | 'judge'. Not an ENUM here because SQLite ignores
         -- them; the CHECK is the same guarantee and it is honoured.
         audience TEXT NOT NULL CHECK(audience IN ('principal','child','judge')),
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
       )"
    : "CREATE TABLE gates_event_invites (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         event_id INT UNSIGNED NOT NULL,
         cycle_id BIGINT UNSIGNED NULL,
         audience ENUM('principal','child','judge') NOT NULL,
         nominee_id BIGINT UNSIGNED NULL,
         judge_id BIGINT UNSIGNED NULL,
         name VARCHAR(160) NOT NULL,
         email VARCHAR(190) NOT NULL,
         reference VARCHAR(32) NOT NULL,
         id_secret VARCHAR(64) NOT NULL,
         discount_code VARCHAR(40) NULL,
         -- SMALLINT, not TINYINT. A quota is operator-set and TINYINT UNSIGNED stops at
         -- 255; a sort_order has already been clamped by that ceiling in this schema.
         guest_quota SMALLINT UNSIGNED NOT NULL DEFAULT 0,
         sent_at TIMESTAMP NULL DEFAULT NULL,
         opened_at TIMESTAMP NULL DEFAULT NULL,
         scans INT UNSIGNED NOT NULL DEFAULT 0,
         last_scan_at TIMESTAMP NULL DEFAULT NULL,
         created_at TIMESTAMP NOT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_invite_ref (reference),
         UNIQUE KEY uq_invite_person (event_id, email),
         KEY idx_invite_event (event_id, audience)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($sqlite) {
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_invite_person ON gates_event_invites (event_id, email)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_invite_event ON gates_event_invites (event_id, audience)');
}

echo "  + gates_event_invites created\n";
