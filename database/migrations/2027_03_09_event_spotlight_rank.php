<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHICH EVENTS LEAD THE EVENTS PAGE (EVENTS-INDEX §2, handoff 5 Oct 2026).
 *
 * The index opens on a four-slide spotlight, and an operator chooses the four: 1–4 is a
 * slide's position, NULL is not featured. With nothing ranked the page falls back to the
 * next upcoming events (EventsFront::spotlight()).
 *
 * TINYINT UNSIGNED, which caps at 255 on MySQL and at nothing on SQLite (CLAUDE.md). The
 * value is 1–4 and PHP refuses anything else before it reaches the column, so the cap is
 * never the guard — it is only the width.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_site_events')) {
    if (!DB::schema()->hasColumn('gates_site_events', 'spotlight_rank')) {
        DB::statement('ALTER TABLE gates_site_events ADD COLUMN spotlight_rank ' . ($sqlite ? 'INTEGER' : 'TINYINT UNSIGNED') . ' NULL DEFAULT NULL');
        echo "  + gates_site_events.spotlight_rank added\n";
    } else {
        echo "  = gates_site_events.spotlight_rank already present\n";
    }
}

echo "event spotlight rank OK\n";
