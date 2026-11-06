<?php
/**
 * The target-pool model of the community return (owner, 30 Oct 2026). Every approved nominee in
 * an edition has the same vote target; the edition's community-return share of every eligible
 * contribution goes into ONE pool; when voting closes, the nominees who reached the target share
 * the pool by their votes — one alone takes all of it — and if nobody reached it the pool goes to
 * the reserves. CommunityReturnPool.
 *
 *   gates_return_pools   the settlement of one edition's pool, written once: the target, how
 *                        many nominees were counted, the pool, what reached the winners and
 *                        what went to the reserves, who settled it and when. UNIQUE on the
 *                        cycle is the claim: a second press finds it and does nothing.
 *
 * VARCHAR, never ENUM. Indexes through SchemaIndex. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$int = $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'BIGINT NOT NULL DEFAULT 0';
$ref = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
$txt = $sqlite ? 'TEXT NULL' : 'VARCHAR(400) NULL';
$dt  = $sqlite ? 'TEXT NOT NULL' : 'DATETIME NOT NULL';
$eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_return_pools')) {
    DB::statement("CREATE TABLE gates_return_pools (id $id, cycle_id $ref NOT NULL, target_votes $int, nominees $int, pool_kobo $int,
        winners $int, paid_kobo $int, reserves_kobo $int, settled_by $ref NULL, settled_at $dt, note $txt)$eng");
    echo "  + gates_return_pools\n";
}
SchemaIndex::ensure('gates_return_pools', 'uq_return_pool_cycle', ['cycle_id'], true);
