<?php
declare(strict_types=1);

/**
 * Eleven qualifiers, fired at once on eleven separate connections.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS NOT A PHPUNIT TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The handoff asks for "11 concurrent qualifiers → ranks 1–11, then status full".
 * `ChallengeServiceTest` has that case and it proves the ARITHMETIC: eleven
 * qualifications, eleven distinct places. What it cannot prove is the word
 * CONCURRENT. It runs them one after another in one process on one connection, so a
 * `qualify()` that read its count outside the transaction would still pass it.
 *
 * And the harness cannot be made to prove it either. `lockForUpdate()` compiles to
 * NOTHING on SQLite — the driver has no row locks — so the suite's own database
 * answers correctly for a reason that has nothing to do with this code.
 *
 * So the real check is this: fork eleven processes, each with its own MySQL
 * connection, each blocking on a start barrier, and let them go together. That is the
 * only arrangement in which `SELECT … FOR UPDATE` is doing anything, and the only one
 * that fails when it is removed.
 *
 *     scripts/mysql-parity.sh                     # start the server once
 *     php scripts/challenge-race-check.php        # then this
 *
 * IT FOUND A REAL FAULT ON ITS FIRST RUN, which is the reason it exists. The lock
 * was present and held, and nine of eleven qualifiers were still refused by the
 * UNIQUE on (challenge_id, standing):
 *
 *     places assigned: 2    distinct: 2    refused: 9    status: open
 *
 * MySQL's default isolation is REPEATABLE READ, where a transaction's read view is
 * fixed by its FIRST consistent read. `qualify()` read the entry row unlocked before
 * taking the lock, so the count afterwards answered from a snapshot older than the
 * lock, and every caller computed the same `taken`. Nothing about that is visible in
 * the query, and SQLite cannot show it. The repair is the lock order plus a locking
 * read on the count; after it, three consecutive runs each gave:
 *
 *     places assigned: 11   distinct: 11   refused: 0    status: full
 *
 * It exits non-zero on a failure, so it is usable from CI the day there is one.
 */

require __DIR__ . '/../vendor/autoload.php';

use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Support\ChallengeEnum as E;
use Illuminate\Database\Capsule\Manager as DB;

const RACERS = 11;
const CAP    = 11;

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl is not available; this check needs real processes.\n");
    exit(2);
}

$cfg = [
    'driver'    => 'mysql',
    'host'      => getenv('DB_HOST') ?: '127.0.0.1',
    'port'      => getenv('DB_PORT') ?: '3306',
    'database'  => getenv('DB_NAME') ?: 'africa_gates_test',
    'username'  => getenv('DB_USER') ?: 'gates',
    'password'  => getenv('DB_PASS') ?: 'gates_test_pw',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
];

$connect = static function () use ($cfg): void {
    $c = new DB();
    $c->addConnection($cfg);
    $c->setAsGlobal();
    $c->bootEloquent();
};

$connect();

try {
    DB::connection()->getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "no MySQL to talk to — run scripts/mysql-parity.sh first.\n  " . $e->getMessage() . "\n");
    exit(2);
}

// ── a challenge and eleven entries, all one nomination short of qualifying ──

$tag = 'race-' . bin2hex(random_bytes(4));

$challengeId = (int) DB::table('gates_challenges')->insertGetId([
    'slug' => $tag, 'title' => 'Race check', 'kicker' => 'Race check',
    'action' => E::ACTION_NOMINATE, 'target' => 1, 'mode' => E::MODE_FIRST, 'cap' => CAP,
    'prize_type' => E::PRIZE_CASH_EACH, 'prize_amount' => 1000, 'prize_currency' => '₦',
    'theme' => E::THEME_GREEN, 'status' => E::ST_OPEN, 'created_at' => date('Y-m-d H:i:s'),
]);

$programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
    'title' => 'Race check programme', 'slug' => $tag . '-prog', 'is_active' => 1,
]);
$cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
    'programme_id' => $programmeId, 'year' => 2026, 'status' => 'nominations',
]);

$entries = [];
for ($i = 0; $i < RACERS; $i++) {
    $userId = (int) DB::table('gates_users')->insertGetId([
        'name' => 'Racer ' . $i, 'email' => $tag . '-' . $i . '@example.test', 'status' => 'active',
    ]);
    $entryId = (int) DB::table('gates_challenge_entries')->insertGetId([
        'challenge_id' => $challengeId, 'user_id' => $userId,
        'phone_hash' => hash('sha256', $tag . $i), 'joined_at' => date('Y-m-d H:i:s'),
        'status' => E::E_ACTIVE, 'payout_status' => E::PAY_NONE,
        'verified' => 1, 'progress' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    DB::table('gates_nominations')->insert([
        'cycle_id' => $cycleId, 'nominee_name' => 'N' . $i,
        'nominator_name' => 'Racer ' . $i, 'nominator_email' => $tag . '-' . $i . '@example.test',
        'status' => 'verified', 'nominee_confirmed_at' => date('Y-m-d H:i:s'),
        'challenge_entry_id' => $entryId, 'nominee_identity_hash' => hash('sha256', $tag . 'n' . $i),
    ]);
    $entries[] = $entryId;
}

// ── the barrier ────────────────────────────────────────────────────────────
// A file every child polls, so they are all already connected and parsed when the
// starting gun fires. Without it the first fork wins by a comfortable margin and the
// check proves nothing at all.

$gate = sys_get_temp_dir() . '/' . $tag . '.go';

$pids = [];
foreach ($entries as $entryId) {
    $pid = pcntl_fork();

    if ($pid === -1) { fwrite(STDERR, "fork failed\n"); exit(2); }

    if ($pid === 0) {
        // A forked child inherits the parent's socket; it must open its own or the
        // two processes interleave packets on one connection and MySQL drops both.
        $connect();
        DB::connection()->getPdo();

        $spin = 0;
        while (!file_exists($gate) && $spin++ < 2_000_000) usleep(50);

        try {
            CS::qualify($entryId);
            exit(0);
        } catch (\Throwable $e) {
            // A refused write IS the belt-and-braces guarantee working: the UNIQUE on
            // (challenge_id, standing) turning a double-assignment into an error
            // rather than two people both told they came fourth. Reported, not hidden.
            fwrite(STDERR, '  child ' . $entryId . ' refused: '
                . substr(str_replace("\n", ' ', $e->getMessage()), 0, 120) . "\n");
            exit(1);
        }
    }

    $pids[] = $pid;
}

usleep(250_000);          // let every child reach the barrier
touch($gate);

$refused = 0;
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
    if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 1) $refused++;
}
@unlink($gate);

// ── what actually happened ─────────────────────────────────────────────────

$rows = DB::table('gates_challenge_entries')->where('challenge_id', $challengeId)
    ->whereNotNull('standing')->orderBy('standing')->pluck('standing')->all();

$standings = array_map('intval', $rows);
$unique    = array_values(array_unique($standings));
$status    = (string) DB::table('gates_challenges')->where('id', $challengeId)->value('status');

echo "\n  racers:            " . RACERS . " (cap " . CAP . ")\n";
echo "  places assigned:   " . count($standings) . "\n";
echo "  distinct places:   " . count($unique) . "\n";
echo "  places:            " . implode(',', $standings) . "\n";
echo "  writes refused:    {$refused}\n";
echo "  challenge status:  {$status}\n\n";

$ok = true;

if ($standings !== range(1, CAP)) {
    echo "  FAIL: places are not 1.." . CAP . " exactly once\n";
    $ok = false;
}
if (count($standings) > CAP) {
    echo "  FAIL: more places than the cap\n";
    $ok = false;
}
if ($status !== E::ST_FULL) {
    echo "  FAIL: the cap was reached and the challenge is '{$status}', not 'full'\n";
    $ok = false;
}

// Tidy up: this runs against the shared parity database, and a race check that leaves
// eleven users and a challenge behind makes the NEXT run's figures unreadable.
DB::table('gates_nominations')->where('cycle_id', $cycleId)->delete();
DB::table('gates_challenge_entries')->where('challenge_id', $challengeId)->delete();
DB::table('gates_challenge_events')->where('challenge_id', $challengeId)->delete();
DB::table('gates_challenges')->where('id', $challengeId)->delete();
DB::table('gates_users')->where('email', 'like', $tag . '-%')->delete();
DB::table('gates_award_cycles')->where('id', $cycleId)->delete();
DB::table('gates_award_programmes')->where('id', $programmeId)->delete();

echo $ok ? "  RACE CHECK GREEN\n\n" : "  RACE CHECK FAILED\n\n";
exit($ok ? 0 : 1);
