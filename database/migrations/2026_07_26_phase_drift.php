<?php
/**
 * gates_phase_drift — the shadow-mode ledger for the computed cycle phase.
 *
 * One row every time BallotGuard finds the computed phase and the stored
 * `gates_award_cycles.status` column disagreeing about whether a vote or a
 * nomination may proceed. This is how a mis-configured live cycle is surfaced
 * to an operator BEFORE strict enforcement starts refusing real traffic.
 *
 * Also seeds `phase_enforcement` = 'strict' so the setting is visible in the
 * admin without a code deploy (set it to 'shadow' to observe without refusing).
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_phase_drift')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_phase_drift (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             cycle_id INTEGER NOT NULL,
             action TEXT NOT NULL DEFAULT 'vote' CHECK(action IN ('vote','nominate')),
             computed_phase TEXT NOT NULL,
             stored_status TEXT NOT NULL,
             would_allow INTEGER NOT NULL DEFAULT 0,
             phase_allows INTEGER NOT NULL DEFAULT 0,
             mode TEXT NOT NULL DEFAULT 'strict',
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
           )"
        : "CREATE TABLE gates_phase_drift (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
             cycle_id BIGINT UNSIGNED NOT NULL,
             action ENUM('vote','nominate') NOT NULL DEFAULT 'vote',
             computed_phase VARCHAR(20) NOT NULL,
             stored_status VARCHAR(20) NOT NULL,
             would_allow TINYINT(1) NOT NULL DEFAULT 0,
             phase_allows TINYINT(1) NOT NULL DEFAULT 0,
             mode VARCHAR(10) NOT NULL DEFAULT 'strict',
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id),
             KEY idx_drift_cycle (cycle_id, created_at)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($sqlite) {
        try { DB::statement('CREATE INDEX IF NOT EXISTS idx_drift_cycle ON gates_phase_drift(cycle_id, created_at)'); }
        catch (\Throwable $e) {}
    }
    echo "created gates_phase_drift\n";
} else {
    echo "  = gates_phase_drift already present\n";
}

// Seed the enforcement switch so it is discoverable in admin Settings.
if ($schema->hasTable('gates_settings')) {
    try {
        $exists = DB::table('gates_settings')->where('key_name', 'phase_enforcement')->exists();
        if (!$exists) {
            DB::table('gates_settings')->insert([
                'key_name' => 'phase_enforcement',
                'value'    => 'strict',
            ]);
            echo "  + phase_enforcement = strict seeded\n";
        } else {
            echo "  = phase_enforcement already set\n";
        }
    } catch (\Throwable $e) {
        echo '  ! phase_enforcement seed skipped: ' . $e->getMessage() . "\n";
    }
}

echo "phase drift migration OK\n";
