<?php
/**
 * The rest of what an organiser needs: a waitlist, discount codes, and a real agenda.
 *
 * ── WHY A WAITLIST IS NOT OPTIONAL ONCE A TIER CAN SELL OUT ──────────────────
 *
 * The moment a tier has a limit, somebody arrives after it is reached — and until now the
 * only thing the page could say to them was "fully booked", which throws away the most
 * motivated person in the room. They wanted to come enough to arrive on a sold-out page.
 *
 * A waitlist is also the only honest answer to the thing that always happens next: seats come
 * back. A card is declined, a hold expires, three people cancel the week before. Without a
 * list, those seats are quietly re-sold to whoever happens to be looking, which is a lottery
 * dressed as a queue.
 *
 * So a waitlist entry is a real registration with `status = 'waitlisted'` — same table, same
 * ticket machinery — and {@see \AfricaGates\Services\EventWaitlist} promotes in the order
 * people joined.
 *
 * ── WHY DISCOUNT CODES ARE THEIR OWN TABLE ───────────────────────────────────
 *
 * A tier already has an `access_code` that HIDES it. That is a different thing from a code
 * that reduces a price, and conflating them would mean every discount needed its own hidden
 * tier — so an organiser offering 20% to alumni would end up maintaining a parallel set of
 * tiers that drift out of step with the real ones on price, capacity and sale window.
 *
 * A code carries its own limits, because every one of them is a way an unbounded discount
 * becomes a story: how many times in total, how many times per person, which tiers it applies
 * to, and when it stops working.
 *
 * ── AND THE AGENDA ───────────────────────────────────────────────────────────
 *
 * `gates_site_events.schedule` is a JSON blob of {time,title,body} the page prints. Fine for
 * a run of show, useless for anything that needs to be counted, filtered or attached to —
 * which is what a session becomes as soon as an event has more than one room. Sessions are
 * rows so they can be ordered, filtered by track, and eventually capped; the blob stays
 * readable as a fallback so nothing breaks between upload and migrate.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1 · discount codes ───────────────────────────────────────────────────────
if (!DB::schema()->hasTable('gates_event_codes')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_event_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NULL,
            code TEXT NOT NULL,
            label TEXT NULL,
            kind TEXT NOT NULL DEFAULT 'percent',
            amount INTEGER NOT NULL DEFAULT 0,
            tier_ids TEXT NULL,
            max_uses INTEGER NULL,
            max_per_email INTEGER NOT NULL DEFAULT 1,
            used_count INTEGER NOT NULL DEFAULT 0,
            starts_at TEXT NULL,
            ends_at TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_event_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            -- NULL = usable on any event. An organisation-wide staff code should not have to
            -- be recreated for every event of the year.
            event_id BIGINT UNSIGNED NULL,
            code VARCHAR(40) NOT NULL,
            label VARCHAR(120) NULL,
            -- 'percent' or 'fixed'. Two kinds because organisers think in both, and deriving
            -- one from the other loses the intent: '20% off' and '₦5,000 off' are different
            -- promises when the price changes.
            kind VARCHAR(10) NOT NULL DEFAULT 'percent',
            amount INT UNSIGNED NOT NULL DEFAULT 0,
            -- JSON list of tier ids, or NULL for every tier. A discount that silently applied
            -- to the ₦380,000 table when it was meant for student tickets is an expensive
            -- kind of generous.
            tier_ids TEXT NULL,
            max_uses INT UNSIGNED NULL,
            max_per_email SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            starts_at TIMESTAMP NULL DEFAULT NULL,
            ends_at TIMESTAMP NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            -- One code per event, in the database: two rows with the same code would apply
            -- unpredictably and the cheaper one would be the bug nobody could reproduce.
            UNIQUE KEY uq_code_event (code, event_id),
            KEY idx_code_active (code, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_event_codes created\n";
} else {
    echo "  = gates_event_codes already present\n";
}

if ($sqlite) {
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_code_event ON gates_event_codes (code, event_id)");
    DB::statement("CREATE INDEX IF NOT EXISTS idx_code_active ON gates_event_codes (code, is_active)");
}

// ── 2 · sessions (the agenda) ────────────────────────────────────────────────
if (!DB::schema()->hasTable('gates_event_sessions')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_event_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT NULL,
            starts_at TEXT NULL,
            ends_at TEXT NULL,
            room TEXT NULL,
            track TEXT NULL,
            speakers TEXT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_published INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_event_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            starts_at TIMESTAMP NULL DEFAULT NULL,
            ends_at TIMESTAMP NULL DEFAULT NULL,
            room VARCHAR(120) NULL,
            -- A track is what makes a two-room day readable. Free text rather than a lookup
            -- table: an organiser naming their own tracks should not need a second screen.
            track VARCHAR(80) NULL,
            speakers VARCHAR(500) NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_session_event (event_id, is_published, starts_at, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_event_sessions created\n";
} else {
    echo "  = gates_event_sessions already present\n";
}

if ($sqlite) {
    DB::statement("CREATE INDEX IF NOT EXISTS idx_session_event ON gates_event_sessions (event_id, is_published, starts_at, sort_order)");
}

// ── 3 · what a registration remembers about its discount and its place ───────
if (DB::schema()->hasTable('gates_event_registrations')) {
    foreach ([
        // What was used and what it took off, kept on the ROW rather than recomputed. A code
        // can be edited or deleted after somebody has bought against it, and a receipt that
        // silently restated history would make the money stop adding up.
        'discount_code'  => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL',
        'discount_naira' => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
        // Position in the queue when they joined it, and when they were offered a seat. The
        // offer expires, or one no-reply holds a seat nobody else can have.
        'waitlist_at'    => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        'offered_at'     => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        'offer_expires_at' => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_event_registrations', $col)) {
            DB::statement("ALTER TABLE gates_event_registrations ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_event_registrations.{$col} added\n";
        } else {
            echo "  = gates_event_registrations.{$col} already present\n";
        }
    }
    if ($sqlite) {
        DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_waitlist ON gates_event_registrations (event_id, status, waitlist_at)");
    } else {
        try { DB::statement("CREATE INDEX idx_reg_waitlist ON gates_event_registrations (event_id, status, waitlist_at)"); }
        catch (\Throwable) {}
    }
}

// ── 4 · per-event settings an organiser actually changes ─────────────────────
if (DB::schema()->hasTable('gates_site_events')) {
    foreach ([
        // Off by default. A waitlist an organiser has not thought about is a promise they did
        // not make, and the worst version of this feature is a queue nobody ever works.
        'waitlist_open'   => $sqlite ? 'INTEGER' : 'TINYINT(1) NULL',
        // Whether registration is closed early, independently of the event date. Every
        // organiser wants a cutoff for catering.
        'sales_close_at'  => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        // What an attendee is told after paying, and what the confirmation email adds. Joining
        // links, parking, dress code — the things a support inbox otherwise answers one at a time.
        'attendee_note'   => $sqlite ? 'TEXT' : 'TEXT NULL',
        // Refunds: the policy, in the organiser's own words, shown BEFORE anybody pays.
        'refund_policy'   => $sqlite ? 'TEXT' : 'VARCHAR(1000) NULL',
        // A contact for the event itself, so a question about parking does not go to the
        // platform's support desk.
        'organiser_email' => $sqlite ? 'TEXT' : 'VARCHAR(190) NULL',
        'organiser_phone' => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_site_events', $col)) {
            DB::statement("ALTER TABLE gates_site_events ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_site_events.{$col} added\n";
        } else {
            echo "  = gates_site_events.{$col} already present\n";
        }
    }
}

echo "event extras OK\n";
