<?php
/**
 * Contacts a volunteer makes (owner, 29 Oct 2026: "the Africa GATES volunteers log contacts
 * that they make", for the CACENTRE CRM).
 *
 *   gates_vol_contacts   somebody a volunteer met who agreed to hear from Africa GATES: their
 *                        name, an email or a phone, what they are interested in, and where the
 *                        volunteer met them. Pushed to CACENTRE as an enquiry (CrmAudiencePush);
 *                        the volunteer's note stays here. `forgotten_at` is "they asked not to be
 *                        contacted", sent on as a stop; a row is never deleted, so the stop can
 *                        still reach the CRM.
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
$dt  = static fn (bool $null = false) => ($sqlite ? 'TEXT' : 'DATETIME') . ($null ? ' NULL' : ' NOT NULL');

if (!$schema->hasTable('gates_vol_contacts')) {
    DB::statement("CREATE TABLE gates_vol_contacts (id $id, volunteer_id {$ref()}, name {$str(160, false)}, email {$str(160)}, phone {$str(40)},
        interest {$str(24, false)}, scope_kind {$str(16)}, scope_id {$ref(true)}, place_text {$str(160)}, note {$str(300)},
        consent_at {$dt()}, forgotten_at {$dt(true)}, created_at {$dt()}, updated_at {$dt()})"
        . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    echo "  + gates_vol_contacts\n";
}
SchemaIndex::ensure('gates_vol_contacts', 'idx_volcontact_vol', ['volunteer_id', 'created_at']);
SchemaIndex::ensure('gates_vol_contacts', 'idx_volcontact_upd', ['updated_at', 'id']);
