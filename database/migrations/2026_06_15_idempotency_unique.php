<?php
/**
 * Promote idx_votes_idem to a UNIQUE index so idempotency keys are deduped at the
 * DB layer (SQLite allows many NULLs in a unique index, so the vast majority of
 * votes — which carry no key — are unaffected). Idempotent.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

// BOTH halves of this were broken on MySQL, in different ways. `DROP INDEX IF
// EXISTS name` is SQLite-only — MySQL needs `DROP INDEX name ON table` — and
// `CREATE UNIQUE INDEX IF NOT EXISTS` is a 1064. Wrapped in one try/catch, the
// first failure skipped the second, so on MySQL the index was neither dropped nor
// recreated as unique: it stayed non-unique, silently, behind a printed warning.
//
// makeUnique() does the pair properly and reports each half. A genuine failure —
// existing rows that violate the constraint — still surfaces as `!`, which is the
// one case that needs a human: duplicates must be resolved before the index can
// exist at all.
foreach (SchemaIndex::makeUnique('gates_votes', 'idx_votes_idem', ['idempotency_key']) as $line) {
    echo $line . "\n";
}
echo "idempotency unique OK\n";
