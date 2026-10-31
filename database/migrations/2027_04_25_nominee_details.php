<?php
/**
 * A nominee's own details, kept on their account (owner, 30 Oct 2026: "when a nominee is accepted,
 * an email should be sent for them to create an account and complete their remaining info — more
 * evidence, their phones, emails, photographs. Once complete they can review it and update or add
 * to it. They cannot change the reason the nominator nominated them.")
 *
 *   gates_nominee_details   one row per nominee: the account that holds it, the nomination it
 *                           came from (whose reason is shown and never edited), their phones and
 *                           emails (JSON lists), a short bio in their own words, when they
 *                           accepted the programme's terms, and when the checklist was first
 *                           complete. Replaces the single-use nominee form, which could be filled
 *                           once and never looked at again.
 *   gates_nominee_files     photographs and evidence (a file or a link), each with a label.
 *                           Removed, never deleted, so a panel that saw a file can still find it.
 *
 * VARCHAR, never ENUM. Indexes through SchemaIndex. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$ref = static fn (bool $null = false) => ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ($null ? ' NULL' : ' NOT NULL');
$str = static fn (int $n, bool $null = true) => ($sqlite ? 'TEXT' : "VARCHAR($n)") . ($null ? ' NULL' : ' NOT NULL');
$txt = 'TEXT NULL';
$dt  = static fn (bool $null = false) => ($sqlite ? 'TEXT' : 'DATETIME') . ($null ? ' NULL' : ' NOT NULL');
$eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_nominee_details')) {
    DB::statement("CREATE TABLE gates_nominee_details (id $id, nominee_id {$ref()}, nomination_id {$ref(true)}, user_id {$ref(true)},
        phones $txt, emails $txt, bio $txt, terms_at {$dt(true)}, linked_at {$dt(true)}, completed_at {$dt(true)},
        created_at {$dt()}, updated_at {$dt()})$eng");
    echo "  + gates_nominee_details\n";
}
if (!$schema->hasTable('gates_nominee_files')) {
    DB::statement("CREATE TABLE gates_nominee_files (id $id, nominee_id {$ref()}, kind {$str(16, false)}, path {$str(255)}, url {$str(255)},
        label {$str(190)}, created_at {$dt()}, removed_at {$dt(true)})$eng");
    echo "  + gates_nominee_files\n";
}
SchemaIndex::ensure('gates_nominee_details', 'uq_nomdet_nominee', ['nominee_id'], true);
SchemaIndex::ensure('gates_nominee_details', 'idx_nomdet_user', ['user_id']);
SchemaIndex::ensure('gates_nominee_files', 'idx_nomfile_nominee', ['nominee_id', 'kind']);
