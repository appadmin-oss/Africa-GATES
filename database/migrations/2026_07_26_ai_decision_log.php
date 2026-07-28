<?php
/**
 * gates_ai_decisions — what the AI suggested vs what the human decided.
 *
 * gates_ai_calls records that a call happened. This records whether it was any
 * use, which is the only thing that justifies keeping an advisory AI: without it
 * there is no way to answer "is this helping the reviewer or just decorating the
 * page?", and no accountability trail for a decision a person made with a machine
 * score in front of them.
 *
 * Also extends the AI call log with a retention default, since both tables now
 * accumulate per-decision rows.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_ai_decisions')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_ai_decisions (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             capability TEXT NOT NULL,
             subject_type TEXT NOT NULL,
             subject_id INTEGER NOT NULL,
             suggested TEXT,
             decided TEXT NOT NULL,
             agreed INTEGER,
             actor_id INTEGER,
             note TEXT,
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
           )"
        : "CREATE TABLE gates_ai_decisions (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
             capability VARCHAR(60) NOT NULL,
             subject_type VARCHAR(40) NOT NULL,
             subject_id BIGINT UNSIGNED NOT NULL,
             suggested VARCHAR(120) DEFAULT NULL,
             decided VARCHAR(120) NOT NULL,
             -- NULL when there was no suggestion to agree with, so those rows
             -- are excluded from the rate rather than counted as disagreement.
             agreed TINYINT(1) DEFAULT NULL,
             actor_id BIGINT UNSIGNED DEFAULT NULL,
             note VARCHAR(300) DEFAULT NULL,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id),
             KEY idx_aidec_cap_day (capability, created_at),
             KEY idx_aidec_subject (subject_type, subject_id)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($sqlite) {
        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_aidec_cap_day ON gates_ai_decisions(capability, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_aidec_subject ON gates_ai_decisions(subject_type, subject_id)',
        ] as $ddl) {
            try { DB::statement($ddl); } catch (\Throwable $e) {}
        }
    }
    echo "created gates_ai_decisions\n";
} else {
    echo "  = gates_ai_decisions already present\n";
}

echo "ai decision log migration OK\n";
