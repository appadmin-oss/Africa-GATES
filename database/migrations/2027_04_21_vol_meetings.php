<?php
/**
 * Meetings between volunteers (owner, 29 Oct 2026: "add the meetings feature of the
 * Afrovanguard site for volunteers"; ported from its lib/Meetings.php, simplified).
 *
 *   gates_vol_meetings         one meeting a volunteer organised: when, how long, whether it
 *                              repeats, online (a Meet link) or in person (a place), an agenda,
 *                              and the notes written afterwards. Cancelled, never deleted, so an
 *                              invitee who opens an old link is told it was called off.
 *   gates_vol_meeting_people   who was invited, and their answer (invited, yes, no).
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
$num = static fn (int $d) => ($sqlite ? 'INTEGER' : 'SMALLINT UNSIGNED') . " NOT NULL DEFAULT $d";
$str = static fn (int $n, bool $null = true) => ($sqlite ? 'TEXT' : "VARCHAR($n)") . ($null ? ' NULL' : ' NOT NULL');
$txt = $sqlite ? 'TEXT NULL' : 'TEXT NULL';
$dt  = static fn (bool $null = false) => ($sqlite ? 'TEXT' : 'DATETIME') . ($null ? ' NULL' : ' NOT NULL');
$eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_vol_meetings')) {
    DB::statement("CREATE TABLE gates_vol_meetings (id $id, organiser_id {$ref()}, title {$str(160, false)}, starts_at {$dt()}, minutes {$num(30)},
        repeats {$str(16, false)}, times {$num(1)}, mode {$str(16, false)}, location {$str(200)}, meet_url {$str(300)}, event_id {$str(300)},
        agenda $txt, notes $txt, notes_at {$dt(true)}, status {$str(16, false)}, cancelled_at {$dt(true)}, created_at {$dt()}, updated_at {$dt()})$eng");
    echo "  + gates_vol_meetings\n";
}
if (!$schema->hasTable('gates_vol_meeting_people')) {
    DB::statement("CREATE TABLE gates_vol_meeting_people (id $id, meeting_id {$ref()}, volunteer_id {$ref()}, answer {$str(16, false)},
        answered_at {$dt(true)}, created_at {$dt()})$eng");
    echo "  + gates_vol_meeting_people\n";
}
SchemaIndex::ensure('gates_vol_meetings', 'idx_volmeet_org', ['organiser_id', 'starts_at']);
SchemaIndex::ensure('gates_vol_meeting_people', 'uq_volmeet_person', ['meeting_id', 'volunteer_id'], true);
SchemaIndex::ensure('gates_vol_meeting_people', 'idx_volmeet_vol', ['volunteer_id']);
