<?php
/**
 * The volunteer's award places (consoles v7 §6.2; CONSOLES-V7-REVIEW.md slice V2, G5 and G6).
 *
 *   gates_vol_nom_checks     a volunteer's check of one nomination: "looks complete", or a flag
 *                            with a reason. A RECOMMENDATION with their name — a moderator still
 *                            decides — and one per volunteer per nomination, never edited.
 *   gates_vol_drive_links    a voter-drive volunteer's own link for one award edition. Unlike a
 *                            voter's VoteRecruit link it names no nominee: a volunteer brings
 *                            voters, never votes for a named person.
 *   gates_vol_drive_credits  one row per PERSON (the ledger's voter hash) whose counted vote came
 *                            through a link, so a person voting twice counts once.
 *
 * VARCHAR, never ENUM. Indexes through SchemaIndex. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$ref = $sqlite ? 'INTEGER NOT NULL' : 'INT UNSIGNED NOT NULL';
$str = static fn (int $n, bool $null = true) => ($sqlite ? 'TEXT' : "VARCHAR($n)") . ($null ? ' NULL' : ' NOT NULL');
$dt  = $sqlite ? 'TEXT NOT NULL' : 'DATETIME NOT NULL';
$end = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_vol_nom_checks')) {
    DB::statement("CREATE TABLE gates_vol_nom_checks (id $id, volunteer_id $ref, nomination_id $ref, cycle_id $ref,
        rec {$str(16, false)}, reason {$str(32)}, note {$str(500)}, created_at $dt)$end");
    echo "  + gates_vol_nom_checks\n";
}
SchemaIndex::ensure('gates_vol_nom_checks', 'uq_volcheck_once', ['volunteer_id', 'nomination_id'], true);
SchemaIndex::ensure('gates_vol_nom_checks', 'idx_volcheck_nom', ['nomination_id']);

if (!$schema->hasTable('gates_vol_drive_links')) {
    DB::statement("CREATE TABLE gates_vol_drive_links (id $id, volunteer_id $ref, cycle_id $ref, code {$str(16, false)}, created_at $dt)$end");
    echo "  + gates_vol_drive_links\n";
}
SchemaIndex::ensure('gates_vol_drive_links', 'uq_drive_code', ['code'], true);
SchemaIndex::ensure('gates_vol_drive_links', 'uq_drive_owner', ['volunteer_id', 'cycle_id'], true);

if (!$schema->hasTable('gates_vol_drive_credits')) {
    DB::statement("CREATE TABLE gates_vol_drive_credits (id $id, link_id $ref, voter_hash {$str(64, false)}, created_at $dt)$end");
    echo "  + gates_vol_drive_credits\n";
}
SchemaIndex::ensure('gates_vol_drive_credits', 'uq_drive_person', ['link_id', 'voter_hash'], true);
