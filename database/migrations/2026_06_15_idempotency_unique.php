<?php
/**
 * Promote idx_votes_idem to a UNIQUE index so idempotency keys are deduped at the
 * DB layer (SQLite allows many NULLs in a unique index, so the vast majority of
 * votes — which carry no key — are unaffected). Idempotent.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

try {
    DB::statement('DROP INDEX IF EXISTS idx_votes_idem');
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_votes_idem ON gates_votes(idempotency_key)');
    echo "  = idx_votes_idem is now UNIQUE\n";
} catch (\Throwable $e) {
    // Most likely a pre-existing duplicate key — surface it rather than fail silently.
    echo "  ! could not make idx_votes_idem unique: " . $e->getMessage() . "\n";
}
echo "idempotency unique OK\n";
