<?php
/**
 * Nominee outreach (owner, 30 Oct 2026: Contacts "is to log their conversation with nominees in
 * the awards they contact. They should be able to get the contact info of nominees for the
 * awards and send them messages").
 *
 *   gates_vol_outreach   one conversation with one nominee by one volunteer: how (call, WhatsApp,
 *                        text, email, in person), how it went, a note, and when to try again.
 *                        An email sent from the workspace is a row too, with what was sent, so
 *                        the next volunteer reads exactly what the nominee was told.
 *                        `outcome = 'stop'` is the nominee asking not to be contacted; it holds
 *                        for every volunteer on the award.
 *
 *   gates_vol_outreach_terms   the volunteer's agreement to the rules on using a nominee's
 *                        details (only for Africa GATES and the award; no personal use, no
 *                        selling, no sharing), once per award and per version of the wording.
 *                        Nothing is shown until it exists, and it is the record if it is broken.
 *
 * The old `gates_vol_contacts` (people met at events) is left in place, unread: its rows went to
 * the CRM when they were logged, and a table with personal data in it is retired, not dropped.
 *
 * VARCHAR, never ENUM. Indexes through SchemaIndex. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$ref  = static fn (bool $null = false) => ($sqlite ? 'INTEGER' : 'INT UNSIGNED') . ($null ? ' NULL' : ' NOT NULL');
$str  = static fn (int $n, bool $null = true) => ($sqlite ? 'TEXT' : "VARCHAR($n)") . ($null ? ' NULL' : ' NOT NULL');
$text = $sqlite ? 'TEXT NULL' : 'TEXT NULL';
$day  = $sqlite ? 'TEXT NULL' : 'DATE NULL';
$dt   = $sqlite ? 'TEXT NOT NULL' : 'DATETIME NOT NULL';
$eng  = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_vol_outreach')) {
    DB::statement("CREATE TABLE gates_vol_outreach (id $id, volunteer_id {$ref()}, nominee_id {$ref()}, cycle_id {$ref()},
        channel {$str(16, false)}, outcome {$str(16, false)}, note $text, follow_up_on $day, subject {$str(190)}, body $text,
        created_at $dt)$eng");
    echo "  + gates_vol_outreach\n";
}
if (!$schema->hasTable('gates_vol_outreach_terms')) {
    DB::statement("CREATE TABLE gates_vol_outreach_terms (id $id, volunteer_id {$ref()}, cycle_id {$ref()}, version {$ref()}, agreed_at $dt)$eng");
    echo "  + gates_vol_outreach_terms\n";
}
SchemaIndex::ensure('gates_vol_outreach_terms', 'uq_volout_terms', ['volunteer_id', 'cycle_id', 'version'], true);
SchemaIndex::ensure('gates_vol_outreach', 'idx_volout_nominee', ['nominee_id', 'created_at']);
SchemaIndex::ensure('gates_vol_outreach', 'idx_volout_vol', ['volunteer_id', 'created_at']);
SchemaIndex::ensure('gates_vol_outreach', 'idx_volout_cycle', ['cycle_id', 'follow_up_on']);
