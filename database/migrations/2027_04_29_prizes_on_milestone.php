<?php
/**
 * Prizes that depend on the milestone (owner, 31 Oct 2026). The terms say the prizes may not be
 * affordable if the edition does not reach its target; the prizes were shown everywhere as
 * certain. `prizes_on_milestone` says, per edition, that they depend on it — ON by default, so
 * every edition states the condition its terms already make until an operator says otherwise.
 * EditionPrizes::condition().
 *
 * VARCHAR, never ENUM. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
if ($schema->hasTable('gates_award_cycles') && !$schema->hasColumn('gates_award_cycles', 'prizes_on_milestone')) {
    DB::statement('ALTER TABLE gates_award_cycles ADD COLUMN prizes_on_milestone ' . ($sqlite ? 'INTEGER NOT NULL DEFAULT 1' : 'TINYINT(1) NOT NULL DEFAULT 1'));
    echo "  + gates_award_cycles.prizes_on_milestone\n";
}
