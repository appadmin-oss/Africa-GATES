<?php
/**
 * Email delivery audit — gates_mail_log (recipient masked). Makes "sign-in
 * links are not arriving" diagnosable from the admin console instead of
 * silent. Idempotent + driver-aware. NEVER exit/die here.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_mail_log')) {
    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::statement("CREATE TABLE gates_mail_log (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          to_masked TEXT NOT NULL,
          subject TEXT NOT NULL,
          category TEXT,
          status TEXT NOT NULL CHECK(status IN ('sent','failed','logged_dev')),
          error TEXT,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_mail_created ON gates_mail_log(created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_mail_status ON gates_mail_log(status)');
    } else {
        DB::statement("CREATE TABLE gates_mail_log (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          to_masked VARCHAR(120) NOT NULL,
          subject VARCHAR(200) NOT NULL,
          category VARCHAR(40) DEFAULT NULL,
          status ENUM('sent','failed','logged_dev') NOT NULL,
          error VARCHAR(300) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id), KEY idx_mail_created(created_at), KEY idx_mail_status(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "created gates_mail_log\n";
}
echo "mail log migration OK\n";
