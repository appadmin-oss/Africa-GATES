<?php
/**
 * Widen the event's link columns to TEXT on MySQL, so a long pasted link no longer 500s the
 * event form.
 *
 * 4 Nov 2026: adding a Google Maps link to an event answered 500. `map_embed` was
 * VARCHAR(500) on MySQL, and a place link copied from the Maps address bar is routinely
 * longer — its `data=` and `g_ep` parameters alone run to hundreds of characters. Strict mode
 * refuses the UPDATE with "Data too long", the save has no catch, and the operator sees a
 * server error. SQLite declares these TEXT and has no width anyway, so dev and the whole suite
 * were green (CLAUDE.md, the MySQL/SQLite list). The other pasted links on the same form —
 * the details page, the early-bird link and the cover image — had the same 500 limit.
 *
 * A REPAIR, not a corrected definition: the columns exist on every production database, so
 * fixing the CREATE in an older migration would change nothing there. Only a column still
 * narrower than TEXT is altered; re-running finds nothing to do. MySQL only. The value is
 * still bounded where it is written (EventsFront::mapLink()). NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

if (DB::connection()->getDriverName() === 'sqlite' || !DB::schema()->hasTable('gates_site_events')) {
    echo "  · event link columns: nothing to widen on this driver\n";
    return;
}

$widened = 0;
foreach (['map_embed', 'rsvp_url', 'early_bird_url', 'cover_image'] as $col) {
    $type = DB::selectOne(
        'SELECT DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['gates_site_events', $col]
    );
    if ($type === null || strtolower((string) $type->t) !== 'varchar') continue;
    DB::statement("ALTER TABLE gates_site_events MODIFY `$col` TEXT NULL");
    $widened++;
    echo "  ~ gates_site_events.$col: VARCHAR → TEXT\n";
}
echo "  · event link columns: $widened widened\n";
