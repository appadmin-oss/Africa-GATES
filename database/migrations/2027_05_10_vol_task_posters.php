<?php
/**
 * Who posted a volunteer task, when it was an event's lead and not staff (owner, 5 Nov 2026:
 * "the event lead should be the one who does this").
 *
 * `gates_vol_tasks.created_by` is an ADMIN id. A lead is a member account holding an
 * `event.lead` grant, never staff (routes.php, the /lead group), so writing their member id
 * into created_by would name whichever admin happens to share the number. Two columns instead:
 * the member who posted it and the name they posted under, kept even if their grant ends, so a
 * volunteer's record can still say who set the work.
 *
 * Idempotent; additive only; nullable, so every staff task already on the table is untouched.
 * NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_vol_tasks')) {
    echo "  · vol task posters: no task table yet\n";
    return;
}
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$added = 0;
foreach ([
    'posted_by_member' => $sqlite ? 'INTEGER NULL' : 'INT UNSIGNED NULL',
    'posted_by_name'   => $sqlite ? 'TEXT NULL' : 'VARCHAR(120) NULL',
] as $col => $type) {
    if (DB::schema()->hasColumn('gates_vol_tasks', $col)) continue;
    DB::statement("ALTER TABLE gates_vol_tasks ADD COLUMN $col $type");
    $added++;
}
echo "  · vol task posters: $added column(s) added\n";
