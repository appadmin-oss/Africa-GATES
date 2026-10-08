<?php
declare(strict_types=1);
/**
 * Count the queries and time each public page costs, with the query log ON.
 *
 * This is how an N+1 announces itself. A page that issues one query per nominee looks
 * fine against a three-row fixture and collapses at twenty thousand — and no unit test
 * will ever see it, because unit tests assert results, not query counts.
 *
 *   php tools/qa/query-profile.php
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$builder = new \DI\ContainerBuilder();
$builder->addDefinitions(require __DIR__ . '/../../config/container.php');
$container = $builder->build();

$pages = [
    ['/',           \AfricaGates\Controllers\HomeController::class,        'index'],
    ['/awards',     \AfricaGates\Controllers\AwardsController::class,      'index'],
    ['/registry',   \AfricaGates\Controllers\RegistryController::class,    'index'],
    ['/leaderboard',\AfricaGates\Controllers\LeaderboardController::class, 'index'],
    ['/vote',       \AfricaGates\Controllers\VoteController::class,        'index'],
    ['/nominate',   \AfricaGates\Controllers\NominationController::class,  'form'],
    ['/pulse',      \AfricaGates\Controllers\PulseController::class,       'index'],
];

DB::table('gates_cache')->delete();

printf("%-14s %7s %9s %10s  %s\n", 'PAGE', 'QUERIES', 'TIME', 'SLOWEST', 'VERDICT');
$bad = 0;
// WARM=1 measures the steady state a real visitor meets; the default measures a
// cold start. Both matter and they differ a lot — reporting only one is misleading.
$warm = (bool) getenv('WARM');
foreach ($pages as [$path, $class, $method]) {
    if (!$warm) DB::table('gates_cache')->delete();
    if ($warm) {
        // Prime it exactly as the first visitor would, then measure the second.
        try {
            $ctrl0 = $container->get($class);
            $r0 = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', $path);
            $ctrl0->$method($r0, new \Slim\Psr7\Response());
        } catch (\Throwable) {}
    }
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $t0 = microtime(true);
    try {
        $ctrl = $container->get($class);
        $req  = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', $path);
        $res  = $ctrl->$method($req, new \Slim\Psr7\Response());
        $bytes = strlen((string) $res->getBody());
    } catch (\Throwable $e) {
        printf("%-14s %7s %9s %10s  ERROR %s\n", $path, '-', '-', '-', substr($e->getMessage(), 0, 60));
        $bad++;
        continue;
    }
    $ms  = (microtime(true) - $t0) * 1000;
    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    $n = count($log);
    usort($log, fn ($a, $b) => $b['time'] <=> $a['time']);
    $slowest = $log[0]['time'] ?? 0;

    // A page whose query count scales with rows is the defect being hunted. Anything
    // past ~40 on a page that renders a bounded list is a strong N+1 signal.
    $verdict = $n > 100 ? 'N+1 LIKELY' : ($n > 40 ? 'review' : 'ok');
    if ($n > 40 || $ms > 2000) $bad++;
    printf("%-14s %7d %7.0fms %8.1fms  %s (%d KB)\n", $path, $n, $ms, $slowest, $verdict, (int) round($bytes / 1024));

    if ($n > 40) {
        // Show the repeated shape, which is what identifies the loop.
        $shapes = [];
        foreach (DB::connection()->getQueryLog() ?: $log as $q) {
            $k = preg_replace('/\d+/', '?', $q['query']);
            $shapes[$k] = ($shapes[$k] ?? 0) + 1;
        }
        arsort($shapes);
        foreach (array_slice($shapes, 0, 3, true) as $q => $count) {
            if ($count > 3) printf("      %4dx  %s\n", $count, substr($q, 0, 100));
        }
    }
}
echo $bad === 0 ? "\nAll pages within budget.\n" : "\n{$bad} page(s) need attention.\n";
