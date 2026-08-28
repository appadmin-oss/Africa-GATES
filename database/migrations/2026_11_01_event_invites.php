<?php
/**
 * The award event an award programme leads to, and the guests of honour invited to it.
 *
 * ── WHY THE EVENT NEEDS PROGRAMMES, PLURAL ───────────────────────────────────
 *
 * `gates_site_events` has always been a standalone listing: a date, a venue, ticket
 * tiers. Nothing on it said which awards it was the ceremony FOR, so there was no way to
 * answer "the shortlist for this cycle is invited to which event" without an operator
 * retyping the answer into every message.
 *
 * A JOIN TABLE and not a `programme_id` column, because one ceremony honours several
 * programmes — that is the ordinary case here, not the exception. A single continental
 * gala night hands out the Principal, Incorruptible, Carol and Business awards in one
 * room, and a column would have forced an operator to either run four events for one
 * evening or leave three of the four shortlists uninvited. The first cut of this WAS a
 * column; it was wrong before it shipped.
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
 * `audience` is 'nominee' or 'judge' and nothing else. It began as three, with separate
 * values for principals and for child nominees, and that was a taxonomy invented out of
 * two example programmes: they are two of the awards that happen to exist, and a nominee
 * is a nominee whichever one they are shortlisted for. The split that survives is the real
 * one — a nominee comes off a shortlist, a judge off the panel.
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

// ── 1. the awards a ceremony is for ──────────────────────────────────────────
if (!DB::schema()->hasTable('gates_event_programmes')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_event_programmes (
             event_id INTEGER NOT NULL,
             programme_id INTEGER NOT NULL,
             PRIMARY KEY (event_id, programme_id)
           )"
        : "CREATE TABLE gates_event_programmes (
             event_id INT UNSIGNED NOT NULL,
             programme_id TINYINT UNSIGNED NOT NULL,
             PRIMARY KEY (event_id, programme_id),
             KEY idx_evprog_prog (programme_id),
             CONSTRAINT fk_evprog_event FOREIGN KEY (event_id)
               REFERENCES gates_site_events(id) ON DELETE CASCADE
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_evprog_prog ON gates_event_programmes (programme_id)');
    }
    echo "  + gates_event_programmes created\n";
} else {
    echo "  · gates_event_programmes already exists\n";
}

// An earlier cut of this migration added a single `programme_id` column before the
// many-to-many was understood. It never reached production, but a development database may
// carry it — so anything it holds is folded into the join table rather than lost. The
// column itself is left in place: dropping one on SQLite means rebuilding a table with
// twenty-odd columns, and an unread column is a smaller problem than a botched rebuild of
// the events table. Nothing reads it any more.
if (DB::schema()->hasTable('gates_site_events')
    && DB::schema()->hasColumn('gates_site_events', 'programme_id')) {
    $moved = 0;
    foreach (DB::table('gates_site_events')->whereNotNull('programme_id')
                ->get(['id', 'programme_id']) as $row) {
        DB::table('gates_event_programmes')->insertOrIgnore([
            'event_id'     => (int) $row->id,
            'programme_id' => (int) $row->programme_id,
        ]);
        $moved++;
    }
    if ($moved > 0) echo "  ~ folded $moved legacy programme_id link(s) into the join table\n";
}

// ── 2. the guests of honour ──────────────────────────────────────────────────
if (DB::schema()->hasTable('gates_event_invites')) {
    // A development database may carry the earlier three-value audience set. Rebuilt only
    // when the table is EMPTY: a CHECK constraint cannot be altered in place on SQLite, and
    // dropping rows that somebody has already sent invitations against would destroy the
    // one record of who was written to. If it has rows, the old constraint is left and this
    // says so rather than acting.
    $rows = (int) DB::table('gates_event_invites')->count();
    if ($rows === 0) {
        DB::statement('DROP TABLE gates_event_invites');
        echo "  ~ rebuilding empty gates_event_invites for the two-audience set\n";
    } else {
        echo "  · gates_event_invites already exists with $rows row(s) — left alone\n";
        return;
    }
}

DB::statement($sqlite
    ? "CREATE TABLE gates_event_invites (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         event_id INTEGER NOT NULL,
         cycle_id INTEGER NULL,
         -- 'nominee' | 'judge'. Not an ENUM here because SQLite ignores them; the CHECK
         -- is the same guarantee and it is honoured.
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
       )"
    : "CREATE TABLE gates_event_invites (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         event_id INT UNSIGNED NOT NULL,
         cycle_id BIGINT UNSIGNED NULL,
         audience ENUM('nominee','judge') NOT NULL,
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
