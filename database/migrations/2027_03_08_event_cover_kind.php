<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHAT KIND OF EVENT THIS IS, FOR ITS DEFAULT COVER (DEFAULT-GRAPHICS §4, handoff 5 Oct 2026).
 *
 * An event with no uploaded image draws a default cover whose tone and pattern come from
 * its KIND — an awards ceremony, a webinar, a fundraiser — and the organiser picks the kind
 * from a list in the admin. They never pick a colour.
 *
 * VARCHAR(24), NOT an ENUM. An ENUM is the trap CLAUDE.md opens with: SQLite ignores it, so
 * a kind added to the list later is accepted in dev and `Data truncated` on production — and
 * a constraint, once shipped, needs a repair migration to change. The list lives in PHP
 * (`Support\CoverKind::KINDS`), which validates on save and again on read, so a stored value
 * that is no longer a kind resolves to the default rather than to nothing.
 *
 * NULL means nobody chose, and it is kept distinct from "somebody chose community": it
 * resolves to `ceremony` for an event tied to an award and `community` otherwise.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_site_events')) {
    if (!DB::schema()->hasColumn('gates_site_events', 'cover_kind')) {
        DB::statement('ALTER TABLE gates_site_events ADD COLUMN cover_kind ' . ($sqlite ? 'TEXT' : 'VARCHAR(24)') . ' NULL DEFAULT NULL');
        echo "  + gates_site_events.cover_kind added\n";
    } else {
        echo "  = gates_site_events.cover_kind already present\n";
    }
}

echo "event cover kind OK\n";
