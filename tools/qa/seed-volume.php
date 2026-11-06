<?php
declare(strict_types=1);
/**
 * Seed a continental-scale dataset.
 *
 * Small fixtures hide N+1 queries completely — three nominees cost three extra
 * queries and nobody notices; thirty thousand cost thirty thousand and the page dies.
 * This platform is aimed at the whole continent, so it has to be measured at a size
 * where the difference shows.
 *
 *   php tools/qa/seed-volume.php [scale]     scale 1 ≈ 20k nominees, 200k votes
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$scale     = max(1, (int) ($argv[1] ?? 1));
$PROGS     = 8;
$CATS_EACH = 6;
$NOMINEES  = 20000 * $scale;
$VOTES     = 200000 * $scale;
$PROFILES  = 10000 * $scale;
$NOMS      = 5000 * $scale;

$t0 = microtime(true);
$pdo = DB::connection()->getPdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('SET unique_checks = 0');

echo "seeding scale={$scale}\n";

// Programmes and cycles across every phase, so no page is trivially empty.
$phases = ['nominations', 'voting', 'voting', 'judging', 'results', 'voting', 'shortlisting', 'upcoming'];
DB::table('gates_award_programmes')->truncate();
DB::table('gates_award_cycles')->truncate();
DB::table('gates_award_categories')->truncate();
for ($p = 1; $p <= $PROGS; $p++) {
    DB::table('gates_award_programmes')->insert([
        'id' => $p, 'slug' => 'prog-' . $p, 'title' => 'Programme ' . $p,
        'is_active' => 1, 'sort_order' => $p, 'description' => 'A continental programme.',
    ]);
    DB::table('gates_award_cycles')->insert([
        'id' => $p, 'programme_id' => $p, 'year' => 2026, 'status' => $phases[$p - 1],
        'nominations_open'  => '2026-01-01 00:00:00',
        'nominations_close' => $phases[$p - 1] === 'nominations' ? '2026-12-01 00:00:00' : '2026-05-01 00:00:00',
        'voting_open'       => '2026-06-01 00:00:00',
        'voting_close'      => in_array($phases[$p - 1], ['voting'], true) ? '2026-12-01 00:00:00' : '2026-07-01 00:00:00',
        'results_date'      => '2026-07-15 00:00:00',
    ]);
    for ($k = 1; $k <= $CATS_EACH; $k++) {
        DB::table('gates_award_categories')->insert([
            'cycle_id' => $p, 'slug' => 'cat-' . $p . '-' . $k, 'title' => 'Category ' . $k, 'sort_order' => $k,
        ]);
    }
}
$catIds = DB::table('gates_award_categories')->pluck('id')->all();
echo "  programmes/cycles/categories: " . count($catIds) . " categories\n";

$chunk = function (string $table, array $rows) {
    foreach (array_chunk($rows, 2000) as $batch) DB::table($table)->insert($batch);
};

// Nominees, spread across categories with realistic vote skew.
DB::table('gates_nominees')->truncate();
$rows = [];
for ($i = 1; $i <= $NOMINEES; $i++) {
    $organic = (int) max(0, round(abs(sin($i)) * 400));
    $rows[] = [
        'id' => $i, 'category_id' => $catIds[$i % count($catIds)],
        'name' => 'Nominee ' . $i . ' Surname',
        'status' => $i % 11 === 0 ? 'pending' : 'approved',
        'vote_count' => $organic + ($i % 7 === 0 ? 250 : 0),
        'organic_vote_count' => $organic,
        'country_code' => ['NG','GH','KE','ZA','TZ','UG','EG','MA'][$i % 8],
        'nominated_at' => '2026-03-01 00:00:00',
    ];
    if (count($rows) >= 2000) { DB::table('gates_nominees')->insert($rows); $rows = []; }
}
if ($rows) DB::table('gates_nominees')->insert($rows);
echo "  nominees: {$NOMINEES}\n";

// Votes — the biggest table, and the one every tally reads.
DB::table('gates_votes')->truncate();
$rows = [];
for ($i = 1; $i <= $VOTES; $i++) {
    $nom = ($i % $NOMINEES) + 1;
    $rows[] = [
        'nominee_id' => $nom, 'category_id' => $catIds[$nom % count($catIds)],
        'voter_email_hash' => hash('sha256', 'voter' . $i),
        'vote_type' => $i % 97 === 0 ? 'paid' : 'standard',
        'weight' => $i % 97 === 0 ? 25 : 1,
        'voted_at' => '2026-06-' . str_pad((string) (($i % 27) + 1), 2, '0', STR_PAD_LEFT) . ' 12:00:00',
    ];
    if (count($rows) >= 2000) { DB::table('gates_votes')->insert($rows); $rows = []; }
}
if ($rows) DB::table('gates_votes')->insert($rows);
echo "  votes: {$VOTES}\n";

// Public profiles — the registry and leaderboard read these.
DB::table('gates_profiles')->truncate();
$rows = [];
for ($i = 1; $i <= $PROFILES; $i++) {
    $rows[] = [
        'id' => $i, 'slug' => 'profile-' . $i, 'display_name' => 'Person ' . $i,
        'email' => 'p' . $i . '@example.com',
        'category' => ['Music','Film','Tech','Fashion','Sport','Literature'][$i % 6],
        'country_code' => ['NG','GH','KE','ZA','TZ','UG','EG','MA'][$i % 8],
        'region' => ['west','east','north','south','central'][$i % 5],
        'status' => 'approved',
        'verification_tier' => $i % 13 === 0 ? 'verified' : 'none',
        'cpi_score' => round(abs(cos($i)) * 100, 2),
    ];
    if (count($rows) >= 2000) { DB::table('gates_profiles')->insert($rows); $rows = []; }
}
if ($rows) DB::table('gates_profiles')->insert($rows);
echo "  profiles: {$PROFILES}\n";

// Nominations awaiting review — the admin desk reads these.
DB::table('gates_nominations')->truncate();
$rows = [];
for ($i = 1; $i <= $NOMS; $i++) {
    $rows[] = [
        'cycle_id' => ($i % $PROGS) + 1,
        'nominee_name' => 'Candidate ' . $i . ' Surname',
        'nominee_email' => 'cand' . $i . '@example.com',
        'country_code' => 'NG', 'nominee_state' => 'Lagos', 'nominee_lga' => 'Ikeja',
        'reason' => 'A detailed case for candidate ' . $i . ' running to a realistic length so the review desk renders what it would in production, including impact figures and references.',
        'nominator_name' => 'Nominator ' . $i, 'nominator_email' => 'nom' . $i . '@example.com',
        'status' => $i % 3 === 0 ? 'approved' : 'pending',
        'created_at' => '2026-04-01 00:00:00',
    ];
    if (count($rows) >= 2000) { DB::table('gates_nominations')->insert($rows); $rows = []; }
}
if ($rows) DB::table('gates_nominations')->insert($rows);
echo "  nominations: {$NOMS}\n";

DB::table('gates_cache')->truncate();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
printf("done in %.1fs\n", microtime(true) - $t0);
