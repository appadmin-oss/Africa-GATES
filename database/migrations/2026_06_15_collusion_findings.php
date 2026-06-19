<?php
/** Add gates_collusion_findings (cluster-level collusion review queue).
 *  Idempotent + driver-aware (SQLite + MySQL). */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_collusion_findings')) {
    $ddl = $sqlite
        ? "CREATE TABLE gates_collusion_findings (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             kind TEXT NOT NULL CHECK(kind IN ('shared_device','shared_ip','timing_burst')),
             category_id INTEGER, nominee_id INTEGER NOT NULL,
             shared_key TEXT NOT NULL, vote_count INTEGER NOT NULL DEFAULT 0,
             distinct_voters INTEGER NOT NULL DEFAULT 0, risk_score INTEGER NOT NULL DEFAULT 0,
             explanation TEXT,
             status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','reviewed','dismissed','actioned')),
             first_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
             last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE(kind, nominee_id, shared_key)
           )"
        : "CREATE TABLE gates_collusion_findings (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
             kind ENUM('shared_device','shared_ip','timing_burst') NOT NULL,
             category_id BIGINT UNSIGNED DEFAULT NULL, nominee_id BIGINT UNSIGNED NOT NULL,
             shared_key VARCHAR(120) NOT NULL, vote_count INT UNSIGNED NOT NULL DEFAULT 0,
             distinct_voters INT UNSIGNED NOT NULL DEFAULT 0, risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
             explanation VARCHAR(255) DEFAULT NULL,
             status ENUM('open','reviewed','dismissed','actioned') NOT NULL DEFAULT 'open',
             first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_collusion (kind, nominee_id, shared_key),
             KEY idx_collusion_status (status), KEY idx_collusion_nominee (nominee_id)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    DB::connection()->getPdo()->exec($ddl);
    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_collusion_status  ON gates_collusion_findings(status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_collusion_nominee ON gates_collusion_findings(nominee_id)');
    }
    echo "  + gates_collusion_findings created\n";
} else {
    echo "  = gates_collusion_findings already present\n";
}
echo "collusion findings OK\n";
