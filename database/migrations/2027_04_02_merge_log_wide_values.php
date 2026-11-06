<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE MERGE JOURNAL MUST HOLD THE VALUE IT PROMISES TO PUT BACK.
 *
 * `old_val` on both merge journals (`gates_merge_log`, `gates_profile_merge_log`) is the
 * value a merge overwrote, kept so `unmerge()` can restore it. It was `VARCHAR(64)` on
 * MySQL and `TEXT` on SQLite, which was enough while a merge only rewrote ids. Since the
 * merge can take a name, tagline, organisation or STORY from the other entry
 * (`MergeService::TAKEABLE`), the overwritten value is prose: on strict MySQL a 300-character
 * story is "Data too long" and the whole merge rolls back with a database error the preview
 * never predicted; on a host that relaxes `sql_mode` it is cut to 64 characters, and an
 * unmerge then writes the cut text back over the original, for good. The suite saw neither,
 * because SQLite's column is TEXT.
 *
 * A REPAIR, not a corrected definition: the tables exist on every production database with
 * rows in them. MODIFY is idempotent and asked of information_schema first, so the file is
 * safe to re-run. SQLite needs nothing.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */

if (DB::connection()->getDriverName() !== 'sqlite') {
    foreach (['gates_merge_log', 'gates_profile_merge_log'] as $t) {
        if (!DB::schema()->hasTable($t)) { echo "  = {$t} absent\n"; continue; }
        $row  = DB::selectOne(
            'SELECT DATA_TYPE AS t FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$t, 'old_val']
        );
        $type = strtolower((string) ($row->t ?? ''));
        if ($type === 'text' || $type === 'mediumtext' || $type === 'longtext') {
            echo "  = {$t}.old_val already {$type}\n";
            continue;
        }
        DB::statement("ALTER TABLE {$t} MODIFY old_val TEXT NULL");
        echo "  + {$t}.old_val widened to TEXT\n";
    }
}

echo "merge log wide values OK\n";
