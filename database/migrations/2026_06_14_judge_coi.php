<?php
/** Add gates_judge_coi (per-judge, per-programme conflict-of-interest recusals).
 *  Server-side replacement for the old client-only sessionStorage COI bar.
 *  Idempotent. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$ddl = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_judge_coi (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         judge_id INTEGER NOT NULL,
         programme_id INTEGER NOT NULL,
         reason TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE(judge_id, programme_id)
       )"
    : "CREATE TABLE IF NOT EXISTS gates_judge_coi (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         judge_id BIGINT UNSIGNED NOT NULL,
         programme_id BIGINT UNSIGNED NOT NULL,
         reason VARCHAR(500) DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         UNIQUE KEY uq_judge_coi (judge_id, programme_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

DB::connection()->getPdo()->exec($ddl);
echo DB::schema()->hasTable('gates_judge_coi') ? "gates_judge_coi OK\n" : "*** FAILED ***\n";
