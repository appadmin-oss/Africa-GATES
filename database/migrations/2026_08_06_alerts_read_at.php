<?php
/**
 * How far each member has read their alerts.
 *
 * ── WHY THIS IS THE ONLY THING ALERTS STORE ──────────────────────────────────
 *
 * Alerts are derived on read from the tables that already record the events —
 * cheers, comments, reposts, bookmarks, follows, milestones. Nothing is written
 * when somebody reacts to your post, so there is nothing to keep in step when
 * they un-react, and no backfill for the years of posts that already exist.
 * See AfricaGates\Services\AlertService for the full argument.
 *
 * What cannot be derived is how far YOU have read. That is one timestamp per
 * member, and it is this column.
 *
 * NULL means "never opened", which correctly reads as "everything is unread"
 * rather than "nothing is".
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_users') && !DB::schema()->hasColumn('gates_users', 'alerts_read_at')) {
    DB::statement('ALTER TABLE gates_users ADD COLUMN alerts_read_at '
        . ($sqlite ? 'TEXT' : 'DATETIME') . ' NULL DEFAULT NULL');
    echo "  + gates_users.alerts_read_at added\n";
} else {
    echo "  = gates_users.alerts_read_at already present\n";
}

echo "alerts read watermark OK\n";
