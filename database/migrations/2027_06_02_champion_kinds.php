<?php
/**
 * CHAMPION OF THE WEEK AND OF THE AWARD (Services\DailyChampion; owner, 17 Nov 2026: "become
 * the champion donor of the day, week and award", the prizes tied to the champions).
 *
 * The same sealed table, a `kind` beside the period key. The key itself already differs by kind
 * (`2026-11-16`, `2026-W47`, `award`), so the existing UNIQUE (cycle_id, day) holds one champion
 * per period without touching a constraint — a corrected constraint needs a repair migration on
 * every database that has rows, and this needs none. The column is what lets "one gift per
 * person per KIND" be asked without parsing keys.
 *
 * Additive, idempotent, driver-aware; indexes through SchemaIndex. NEVER exit/die.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_daily_champions')) {
    echo "  = gates_daily_champions missing; 2027_06_01 creates it\n";
    return;
}
if (!DB::schema()->hasColumn('gates_daily_champions', 'kind')) {
    DB::statement("ALTER TABLE gates_daily_champions ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'day'");
    echo "  + gates_daily_champions.kind\n";
} else {
    echo "  = gates_daily_champions.kind already present\n";
}
echo SchemaIndex::ensure('gates_daily_champions', 'idx_daily_champion_kind', ['cycle_id', 'kind', 'person_key']) . "\n";
echo "champion kinds OK\n";
