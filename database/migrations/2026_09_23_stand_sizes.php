<?php
/**
 * Stand sizes, and the venue they have to fit inside.
 *
 * ── A SIZE IS A PUBLISHED TERM, SO IT LIVES WITH THE STAND TYPE ──────────────
 *
 * A vendor who applied for "3m × 3m" and arrives to find 2m × 2m has been sold something
 * else. So `width_cm` and `depth_cm` sit on `gates_stand_types` beside the price and the
 * quota, and they are locked by the same one-way door: {@see StandCall::open()} snapshots
 * them into the call's criteria, and {@see StandType::save()} refuses while the call is open.
 *
 * Centimetres as integers, not metres as floats. A stand is 2.5m or 1.8m often enough that
 * whole metres are not sufficient, and floating-point area arithmetic across a hundred
 * pitches accumulates exactly the sort of error that reads as "we are 4m² over" when the plan
 * is fine. Integers make the sum exact and the comparison honest.
 *
 * ── THE VENUE IS NOT A PUBLISHED TERM, AND THAT DISTINCTION IS THE POINT ─────
 *
 * The hall's measurements go on `gates_stand_calls` but are deliberately EXEMPT from the
 * lock, through their own writer. The lock exists to stop the RULES changing once you know
 * who applied — the criteria, the quotas, the prices, the closing date. How wide the hall
 * actually is, is not a rule; it is a fact about the world that somebody may measure more
 * carefully next week. Refusing to record a better measurement would not protect any
 * applicant. It would only mean the floor plan on the screen stays wrong.
 *
 * `aisle_pct` is the share of the floor that cannot hold a pitch — circulation, fire lanes,
 * the space in front of a servery. It defaults to 35, which is on the generous side on
 * purpose: a plan that quietly assumes 100% of a hall is sellable is the plan that produces a
 * market with no room to walk, and the cost of being wrong in that direction is a fire
 * officer closing the event.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$pdo    = DB::connection()->getPdo();

/** Add a column only if it is missing, so a re-run is a no-op on both engines. */
$add = static function (string $table, string $column, string $sqliteType, string $mysqlType)
    use ($sqlite, $pdo): void {
    try {
        if (DB::schema()->hasColumn($table, $column)) return;
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} " . ($sqlite ? $sqliteType : $mysqlType));
    } catch (\Throwable $e) {
        echo "*** could not add {$table}.{$column}: {$e->getMessage()}\n";
    }
};

// ── the pitch itself ─────────────────────────────────────────────────────────
//
// 300 × 300 is the default because a 3m gazebo is what the overwhelming majority of market
// pitches actually are. A default of zero would be worse than useless: every existing stand
// type would report an area of nothing, the floor plan would say the hall is empty, and the
// first person to read it would believe it.
$add('gates_stand_types', 'width_cm', 'INTEGER NOT NULL DEFAULT 300', 'INT UNSIGNED NOT NULL DEFAULT 300');
$add('gates_stand_types', 'depth_cm', 'INTEGER NOT NULL DEFAULT 300', 'INT UNSIGNED NOT NULL DEFAULT 300');

// ── the hall it has to fit inside ────────────────────────────────────────────
//
// Nullable: an organiser who has not measured the venue yet should get a screen that says so,
// not one that silently plans against a hall of zero metres.
$add('gates_stand_calls', 'floor_width_cm', 'INTEGER', 'INT UNSIGNED DEFAULT NULL');
$add('gates_stand_calls', 'floor_depth_cm', 'INTEGER', 'INT UNSIGNED DEFAULT NULL');
$add('gates_stand_calls', 'aisle_pct',      'INTEGER NOT NULL DEFAULT 35', 'TINYINT UNSIGNED NOT NULL DEFAULT 35');
$add('gates_stand_calls', 'floor_note',     'TEXT',   'TEXT');

foreach ([
    ['gates_stand_types', 'width_cm'], ['gates_stand_types', 'depth_cm'],
    ['gates_stand_calls', 'floor_width_cm'], ['gates_stand_calls', 'floor_depth_cm'],
    ['gates_stand_calls', 'aisle_pct'], ['gates_stand_calls', 'floor_note'],
] as [$t, $c]) {
    echo DB::schema()->hasColumn($t, $c) ? "$t.$c OK\n" : "*** $t.$c FAILED ***\n";
}
