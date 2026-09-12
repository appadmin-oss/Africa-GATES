<?php
/**
 * The zone an event actually happens in.
 *
 * ── WHY A COLUMN AND NOT THE PLATFORM SETTING ───────────────────────────────
 *
 * `display_timezone` holds ONE zone for the whole platform, and that is right for a
 * deadline: a cycle closes at a moment, announced once, and every nominee on the continent
 * is measured against the same instant.
 *
 * An event is not a deadline. It is a room, in a city, at a wall-clock time, and the only
 * time that matters is the one on the clock in that room. A platform that calls itself
 * continental cannot print a Nairobi gala's start in Lagos hours because that is where its
 * settings screen happens to point — the guest reads "19:00", arrives at 19:00, and is an
 * hour late to a ceremony held for them.
 *
 * ── NULL IS THE MIGRATION ───────────────────────────────────────────────────
 *
 * Nullable, and {@see \AfricaGates\Support\EventTime::zone()} falls back to the platform's
 * display zone. So every event that already exists reads exactly as it did today, and no
 * backfill is needed — which matters because a backfill would have to GUESS, and guessing
 * a timezone onto a past event rewrites what its tickets said.
 *
 * Storage does not move: everything stays UTC. This is a second display edge, not a second
 * convention. See Clock.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_site_events')
    && !DB::schema()->hasColumn('gates_site_events', 'timezone')) {
    // 64 is the widest IANA identifier with room to spare; the value is validated against
    // the real tz database on write, never stored as typed.
    DB::statement('ALTER TABLE gates_site_events ADD COLUMN timezone '
        . ($sqlite ? 'TEXT' : 'VARCHAR(64) NULL') . ' DEFAULT NULL');
    echo "  + gates_site_events.timezone added\n";
} else {
    echo "  = gates_site_events.timezone already present or table absent\n";
}

echo "event timezone OK\n";
