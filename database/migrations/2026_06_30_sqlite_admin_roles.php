<?php
/**
 * SQLite-only: rebuild gates_admins so the role CHECK includes 'moderator'.
 * SQLite can't ALTER a CHECK constraint, and the 2026_06_28 role migration only
 * altered the MySQL ENUM — so an existing SQLite DB created before the moderator
 * role rejects it. Idempotent: no-op on MySQL or when the CHECK already allows it.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

// MUST `return` (never exit): include()d in a loop by MigrationRunner / db:migrate.
if (DB::connection()->getDriverName() !== 'sqlite') { echo "not sqlite — skip\n"; return; }

$ddl = (string) (DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='gates_admins'")->sql ?? '');
if ($ddl === '' || str_contains($ddl, "'moderator'")) { echo "gates_admins absent or already allows moderator — skip\n"; return; }

$pdo = DB::connection()->getPdo();
$pdo->exec('PRAGMA foreign_keys=OFF');
try {
    $pdo->exec("CREATE TABLE gates_admins_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT,
        name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'editor' CHECK(role IN ('superadmin','admin','editor','moderator','judge','viewer')),
        avatar_path TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        last_login_at TEXT,
        last_login_ip TEXT,
        failed_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $cols = ['id','email','password_hash','name','role','avatar_path','is_active','last_login_at','last_login_ip','failed_attempts','locked_until','created_at','updated_at'];
    $copy = array_values(array_intersect($cols, DB::schema()->getColumnListing('gates_admins')));
    $list = implode(', ', $copy);
    $pdo->exec("INSERT INTO gates_admins_new ($list) SELECT $list FROM gates_admins");
    $pdo->exec('DROP TABLE gates_admins');
    $pdo->exec('ALTER TABLE gates_admins_new RENAME TO gates_admins');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admins_role ON gates_admins(role)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admins_active ON gates_admins(is_active)');
    echo "rebuilt gates_admins with moderator-inclusive role CHECK\n";
} catch (\Throwable $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
}
$pdo->exec('PRAGMA foreign_keys=ON');
echo "sqlite-admin-roles migration OK\n";
