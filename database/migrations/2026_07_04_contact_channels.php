<?php
/**
 * Batch 3 · Task 1–2 — nomination contact channels + enterprise reference.
 *
 * 1) Backfills the gates_nominations columns that previously existed only via
 *    older migrations into any DB that missed them (nominee_org, nominee_phone,
 *    nominee_photo_path, nominator_age_range) — schema.sql is canonical again.
 * 2) Adds gates_nominations.reference (unique, nullable) for the AGN-YYYY-XXXXXX-C
 *    reference format. Legacy rows keep NULL; NOM-{id} stays resolvable in code.
 * 3) Creates gates_messages — the outbound SMS/WhatsApp delivery audit log
 *    (recipients stored hashed + masked, never raw).
 *
 * Idempotent + driver-aware (MySQL/MariaDB/SQLite). NEVER exit/die here.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if ($schema->hasTable('gates_nominations')) {
    $cols = [
        'nominee_org'         => $sqlite ? 'TEXT' : 'VARCHAR(200) DEFAULT NULL',
        'nominee_phone'       => $sqlite ? 'TEXT' : 'VARCHAR(40) DEFAULT NULL',
        'nominee_photo_path'  => $sqlite ? 'TEXT' : 'VARCHAR(400) DEFAULT NULL',
        'nominator_age_range' => $sqlite ? 'TEXT' : 'VARCHAR(20) DEFAULT NULL',
        'reference'           => $sqlite ? 'TEXT' : 'VARCHAR(24) DEFAULT NULL',
    ];
    foreach ($cols as $col => $type) {
        if (!$schema->hasColumn('gates_nominations', $col)) {
            DB::statement("ALTER TABLE gates_nominations ADD COLUMN {$col} {$type}");
            echo "added gates_nominations.{$col}\n";
        }
    }
    // Unique index on reference (multiple NULLs allowed on both drivers).
    try {
        if ($sqlite) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_nom_reference ON gates_nominations(reference)');
        } else {
            $idx = DB::select("SHOW INDEX FROM gates_nominations WHERE Key_name = 'uq_nom_reference'");
            if (!$idx) DB::statement('ALTER TABLE gates_nominations ADD UNIQUE KEY uq_nom_reference(reference)');
        }
    } catch (\Throwable $e) { echo 'reference index: ' . $e->getMessage() . "\n"; }
} else {
    echo "no gates_nominations — skip column adds\n";
}

if (!$schema->hasTable('gates_messages')) {
    if ($sqlite) {
        DB::statement("CREATE TABLE gates_messages (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          channel TEXT NOT NULL CHECK(channel IN ('sms','whatsapp')),
          to_hash TEXT NOT NULL,
          to_masked TEXT NOT NULL,
          template TEXT NOT NULL DEFAULT 'generic',
          status TEXT NOT NULL CHECK(status IN ('sent','failed','queued')),
          provider TEXT,
          provider_ref TEXT,
          error TEXT,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_messages_created ON gates_messages(created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_messages_status ON gates_messages(status)');
    } else {
        DB::statement("CREATE TABLE gates_messages (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          channel ENUM('sms','whatsapp') NOT NULL,
          to_hash VARCHAR(64) NOT NULL,
          to_masked VARCHAR(24) NOT NULL,
          template VARCHAR(60) NOT NULL DEFAULT 'generic',
          status ENUM('sent','failed','queued') NOT NULL,
          provider VARCHAR(20) DEFAULT NULL,
          provider_ref VARCHAR(80) DEFAULT NULL,
          error VARCHAR(300) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id), KEY idx_messages_created(created_at), KEY idx_messages_status(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "created gates_messages\n";
}

echo "contact channels migration OK\n";
