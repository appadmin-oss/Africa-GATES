<?php
/**
 * Which road carried each message (Services\Mail\RoadCapacity): `gates_mail_log.via`.
 *
 * Each road — the SMTP account, Brevo's API, Google Apps Script, the host — has its own daily
 * allowance, and the platform could not say how much of any of them it had used, so a spent
 * road went on being tried first. VARCHAR, NULL for every row from before (counted as SMTP's,
 * which is what they were). Idempotent; index through SchemaIndex. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

if (DB::schema()->hasTable('gates_mail_log') && !SchemaHas::column('gates_mail_log', 'via')) {
    $sqlite = DB::connection()->getDriverName() === 'sqlite';
    DB::statement('ALTER TABLE gates_mail_log ADD COLUMN via ' . ($sqlite ? 'TEXT' : 'VARCHAR(16)') . ' NULL');
    echo "  + gates_mail_log.via\n";
}
SchemaHas::forget();
echo '  ' . SchemaIndex::ensure('gates_mail_log', 'idx_mail_log_via', ['status', 'created_at', 'via']) . "\n";
