<?php
declare(strict_types=1);
/**
 * A THROWAWAY DATABASE FOR THE VOLUNTEER ACADEMY'S SCREENSHOTS (scripts/academy-shots.mjs).
 *
 *     php scripts/academy-seed.php /tmp/academy.sqlite   → prints JSON: ids and session ids
 *
 * Builds SQLite the way tests/TestCase.php does (the three sqlite-* schema files, then every
 * dated migration) and seeds invented people — no real person's data — in an active award and an
 * event tonight: a volunteer with the nominee outreach role, a lead, nominees, logged contacts,
 * hours in review. It writes two PHP session files into var/sessions so the browser can open the
 * workspace as the volunteer and as the lead without a sign-in step; the shot script deletes them.
 * Never point it at a real database: it refuses a path that already exists.
 */
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\{Hours, Outreach, Volunteers, Tasks};
use Illuminate\Database\Capsule\Manager as DB;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$path = $argv[1] ?? '';
if ($path === '' || file_exists($path)) { fwrite(STDERR, "Give a path that does not exist yet.\n"); exit(1); }
touch($path);
$cap = new DB();
$cap->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false]);
$cap->setAsGlobal();
$cap->bootEloquent();
$pdo = DB::connection()->getPdo();
foreach (['sqlite-schema.sql', 'sqlite-admin-schema.sql', 'sqlite-community-schema.sql'] as $f) $pdo->exec((string) file_get_contents($root . '/database/' . $f));
$pdo->exec('PRAGMA foreign_keys = OFF');
ob_start();
for ($i = 0; $i < 40 && \AfricaGates\Services\MigrationRunner::status()['pending']; $i++) \AfricaGates\Services\MigrationRunner::run(1000);
ob_end_clean();

$now = date('Y-m-d H:i:s');
$user = static fn (string $name) => (int) DB::table('gates_users')->insertGetId(['name' => $name,
    'email' => strtolower(str_replace(' ', '.', $name)) . '@academy.invalid', 'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => $now]);
$vol = static function (int $uid, string $dob) {
    $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199',
        'area' => 'Ikeja', 'dob' => $dob, 'interests' => ['drive', 'door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
    Volunteers::approve((int) $r['id'], 1);
    return Volunteers::of($uid);
};
$amaka = $user('Amaka Okonkwo');
$tunde = $user('Tunde Bakare');
$v = $vol($amaka, '1996-03-14');
$lead = $vol($tunde, '1988-07-02');

$prog = (int) DB::table('gates_award_programmes')->insertGetId(['title' => 'Lagos Youth Awards', 'slug' => 'lagos-youth', 'is_active' => 1]);
$cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $prog, 'year' => 2026, 'edition_label' => '2026', 'status' => 'voting',
    'nominations_open' => date('Y-m-d', strtotime('-40 days')), 'nominations_close' => date('Y-m-d', strtotime('-10 days')),
    'voting_open' => date('Y-m-d', strtotime('-5 days')), 'voting_close' => date('Y-m-d', strtotime('+20 days'))]);
$cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $cycle, 'title' => 'Young Innovator', 'slug' => 'young-innovator']);
$noms = [];
foreach (['Chiamaka Eze', 'Ibrahim Musa', 'Funmilayo Adebayo', 'Emeka Nwachukwu', 'Zainab Lawal', 'Segun Ogunleye'] as $n) {
    $noms[$n] = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat, 'name' => $n, 'status' => 'approved']);
    if (\AfricaGates\Services\NomineeDetails::ready()) DB::table('gates_nominee_details')->insert(['nominee_id' => $noms[$n], 'phones' => json_encode(['+234 803 000 ' . random_int(1000, 9999)]),
        'emails' => json_encode([strtolower(str_replace(' ', '.', $n)) . '@academy.invalid']), 'created_at' => $now, 'updated_at' => $now]);
}
$event = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'lagos-night', 'title' => 'Lagos Youth Awards night', 'event_date' => date('Y-m-d 18:00:00'),
    'venue' => 'Eko Hotel, Victoria Island', 'status' => 'published', 'created_at' => $now]);
$until = date('Y-m-d', strtotime('+30 days'));
foreach ([[$amaka, Outreach::CAPABILITY, 'cycle', $cycle], [$amaka, 'vol.door', 'event', $event], [$tunde, 'vol.team_lead', 'cycle', $cycle], [$tunde, 'vol.team_lead', 'event', $event]] as [$u, $c, $k, $id]) Grants::grant('member', $u, $c, $k, $id, $until, 1);
Outreach::reset();
Outreach::agree($v, $cycle);
Outreach::log($v, $noms['Ibrahim Musa'], ['channel' => 'call', 'outcome' => 'reached', 'note' => 'Will add his photo tonight.']);
Outreach::log($v, $noms['Zainab Lawal'], ['channel' => 'whatsapp', 'outcome' => 'left_message', 'note' => '']);
DB::table('gates_vol_outreach')->where('nominee_id', $noms['Zainab Lawal'])->update(['follow_up_on' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
Tasks::create(['title' => 'Call nominees who have no photo yet', 'checked_by' => 'lead', 'from_home' => '1', 'est_minutes' => '45', 'scope_kind' => 'cycle', 'scope_id' => (string) $cycle], 1);
Hours::log($v, date('Y-m-d', strtotime('-1 day')), 'Called 6 nominees for the Lagos edition and updated their profiles', 2.5, 'Tunde Bakare', '');

$sess = [];
foreach (['volunteer' => $amaka, 'lead' => $tunde] as $k => $uid) {
    $id = 'academy' . bin2hex(random_bytes(12));
    @mkdir($root . '/var/sessions', 0700, true);
    file_put_contents($root . '/var/sessions/sess_' . $id, 'user_id|i:' . $uid . ';csrf_token|s:64:"' . bin2hex(random_bytes(32)) . '";');
    $sess[$k] = $id;
}
echo json_encode(['cycle' => $cycle, 'event' => $event, 'nominee' => $noms['Chiamaka Eze'], 'session' => $sess]), "\n";
