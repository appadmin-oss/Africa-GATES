<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "NOT THE SAME PERSON" — REMEMBERED.
 *
 * The duplicate scan is advisory and a human decides, but it had no memory of the decision.
 * Two genuinely different people who share a name (common, on a continent where many given
 * names and surnames recur) came back at the top of every scan, at 97%, until somebody merged
 * them to make the suggestion go away — which is the one outcome the scan must never cause.
 *
 * One row per PAIR an admin has looked at and said no to. `pair_key` is the two ids sorted and
 * joined ("12-40"), UNIQUE, so saying no twice is one row. Never deleted by the scan: a later
 * nominee added to the same group is a new pair and is still suggested. Indexes through
 * SchemaIndex — never `CREATE INDEX IF NOT EXISTS`.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_merge_dismissals')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_merge_dismissals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pair_key TEXT NOT NULL,
            nominee_a INTEGER NOT NULL,
            nominee_b INTEGER NOT NULL,
            reason TEXT NULL,
            dismissed_by INTEGER NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_merge_dismissals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pair_key VARCHAR(48) NOT NULL,
            nominee_a BIGINT UNSIGNED NOT NULL,
            nominee_b BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(500) NULL,
            dismissed_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_merge_dismissals created\n";
} else {
    echo "  = gates_merge_dismissals already present\n";
}

echo '  ' . SchemaIndex::ensure('gates_merge_dismissals', 'uq_mdis_pair', ['pair_key'], true) . "\n";

echo "merge dismissals OK\n";
