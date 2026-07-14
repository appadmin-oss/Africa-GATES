<?php
/**
 * Scope the vote idempotency UNIQUE index per-voter.
 *
 * The original index was UNIQUE(idempotency_key) alone — a shared or guessable
 * key let one voter (or an attacker replaying someone else's key) deny another
 * voter's ballot. The replay logic in VoteService is already per-voter, so the
 * constraint should match: UNIQUE(voter_email_hash, idempotency_key). Multiple
 * NULL keys stay allowed on both engines (key-less and bonus votes). Idempotent.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$driver = DB::connection()->getDriverName();

try {
    if ($driver === 'sqlite') {
        DB::statement('DROP INDEX IF EXISTS idx_votes_idem');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_votes_idem ON gates_votes(voter_email_hash, idempotency_key)');
    } else { // mysql / mariadb
        foreach (['uq_votes_idem', 'idx_votes_idem'] as $old) {
            try { DB::statement("ALTER TABLE gates_votes DROP INDEX {$old}"); } catch (\Throwable $e) {}
        }
        DB::statement('ALTER TABLE gates_votes ADD UNIQUE KEY uq_votes_idem (voter_email_hash, idempotency_key)');
    }
    echo "  = vote idempotency index is now per-voter (voter_email_hash, idempotency_key)\n";
} catch (\Throwable $e) {
    echo "  ! could not rescope idempotency index: " . $e->getMessage() . "\n";
}
echo "idempotency per-voter OK\n";
