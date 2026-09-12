<?php
/**
 * The ticket's artwork: keep the original, store the crop, serve a baked image.
 *
 * ── THE PROBLEM THIS FIXES ───────────────────────────────────────────────────
 *
 * `ticket_image` (2026_09_10) is a path an organiser types. Whatever is at that path is then
 * dropped into a 390×268 band on screen and a ~96mm panel on paper with `object-fit:cover`
 * and a hard-coded `object-position:50% 32%`. That number is a guess made once, against the
 * average of "event covers are portrait posters" — it is not this event's poster, and on the
 * ones where it is wrong it cuts a face in half. The comment in templates/pages/events/
 * ticket.twig has said so since the ticket was rebuilt, and named the fix: store a dedicated
 * crop per event instead of cropping an unknown image in CSS.
 *
 * ── WHY THREE COLUMNS AND NOT ONE ────────────────────────────────────────────
 *
 *   ticket_image      UNCHANGED IN MEANING: the path the ticket renders. It is now usually
 *                     written by the server (the baked crop) rather than typed, but nothing
 *                     downstream had to learn a new field, and an organiser who pastes an
 *                     external URL still gets exactly the old behaviour.
 *   ticket_image_src  the ORIGINAL upload, untouched but for the re-encode every upload gets.
 *                     Kept because a crop is a decision somebody will want to revisit, and
 *                     re-cropping a crop is how artwork turns to mush over three edits. It is
 *                     also the only way the editor can reopen showing the whole picture.
 *   ticket_image_edit the recipe as JSON — crop rectangle, rotation, flip, brightness,
 *                     contrast, greyscale. Stored rather than inferred, so reopening the
 *                     editor puts the handles back where they were left rather than resetting
 *                     to centre and quietly discarding the choice on the next save.
 *
 * The recipe is data, never instructions: TicketArtwork::recipe() re-validates every field on
 * read as well as on write, because a row can arrive from a restored backup or a direct SQL
 * edit, and the numbers in it are fed to an image library.
 *
 * ── NULL STILL MEANS UNCONFIGURED ────────────────────────────────────────────
 *
 * Same rule as the migration this extends. An event with no source and no recipe renders the
 * ticket it rendered yesterday, from `cover_image`, cropped in CSS. Nothing here changes any
 * existing ticket until somebody opens the editor and chooses something.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_site_events')) {
    echo "  ! gates_site_events missing — nothing to do\n";
    echo "ticket artwork OK\n";
    return;
}

foreach ([
    'ticket_image_src'  => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL',
    // TEXT and not VARCHAR(255): the recipe is a small JSON object today, and a column that
    // is exactly big enough for today's fields is the one that silently truncates the day a
    // field is added — and a truncated JSON recipe does not fail loudly, it decodes to NULL
    // and resets somebody's crop to the centre.
    'ticket_image_edit' => 'TEXT',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_site_events', $col)) {
        DB::statement("ALTER TABLE gates_site_events ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_site_events.{$col} added\n";
    } else {
        echo "  = gates_site_events.{$col} already present\n";
    }
}

echo "ticket artwork OK\n";
