<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * THREE THINGS THE EVENTS PAGE PROMISES AND NOTHING STORED (Phase 7, §8.10).
 *
 * The redesigned event page has a "Watch the livestream" action on a full or closed event,
 * "Watch the recording" on an ended one, and an "Access" section (step-free venue, live
 * captions, a quiet room). None of the three had a column, so none could be anything but a
 * placeholder — and REFERENCE §15 forbids shipping one.
 *
 *   livestream_url  where the event is broadcast. A LINK OUT, never an embed: an iframe on
 *                   page view sends the visitor's address to the host before they chose to
 *                   watch (the OrgBrand click-to-load rule), and a link needs no CSP change.
 *   recording_url   where it can be watched afterwards. Same rule.
 *   access_notes    one accessibility provision per line, in the organiser's words.
 *
 * Both URLs are re-validated on the way OUT (`EventsFront::link()`), not only on save: a
 * row survives the code that wrote it.
 *
 * VARCHAR(500) on MySQL, because a streaming platform's share link with its tracking
 * parameters is long and a silently truncated URL is a link to the wrong place. Added only
 * where absent — a fresh build from the corrected schema files already has them, and there
 * is no constraint here that could ever need a repair migration.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_site_events')) {
    foreach ([
        'livestream_url' => $sqlite ? 'TEXT' : 'VARCHAR(500)',
        'recording_url'  => $sqlite ? 'TEXT' : 'VARCHAR(500)',
        'access_notes'   => 'TEXT',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_site_events', $col)) {
            DB::statement("ALTER TABLE gates_site_events ADD COLUMN {$col} {$type} NULL DEFAULT NULL");
            echo "  + gates_site_events.{$col} added\n";
        } else {
            echo "  = gates_site_events.{$col} already present\n";
        }
    }
}

echo "event page links OK\n";
