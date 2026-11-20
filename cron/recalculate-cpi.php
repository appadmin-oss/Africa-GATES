<?php
declare(strict_types=1);
/**
 * AFRICA GATES CPI CRON — Run every 6h
 * cPanel: 0 0,6,12,18 * * * /opt/cpanel/ea-php84/root/usr/bin/php /path/to/cron/recalculate-cpi.php
 *
 * NOTE: there must be exactly ONE CPI formula. The canonical implementation
 * lives in src/Console/Commands/CpiRecomputeCommand.php (45% community votes +
 * 55% expert-panel/judge scores, rolled up to profiles). This script used to
 * carry a second, judge-blind formula which silently diverged — it now simply
 * delegates to the console command so every entry point produces the same CPI.
 *
 * IN-PROCESS, never `exec('php …')`. It used to shell out to `php bin/console`, and a bare `php`
 * is the server's DEFAULT binary — on cPanel that is not the MultiPHP version the site and this
 * cron run on (7.4 on our host). So every run, however it was started, spawned a PHP 7.4 that
 * died at Composer's platform check, logged "requires a PHP version >= 8.3.0", and recomputed
 * nothing. Running the command here uses whatever PHP is running this file, as the webcron does.
 */
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();

use AfricaGates\Console\Commands\CpiRecomputeCommand;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

\AfricaGates\Support\Clock::boot();

$cap = new DB();
$cap->addConnection(require __DIR__ . '/../config/database.php');
$cap->setAsGlobal();
$cap->bootEloquent();

if (!\AfricaGates\Support\CronGuard::acquire('recalculate-cpi', __DIR__ . '/../var/data')) {
    fwrite(STDERR, "[cpi] another run is still in progress — exiting.\n");
    exit(0);
}

$t0  = microtime(true);
$log = fn(string $m) => print('[' . date('Y-m-d H:i:s') . '] ' . $m . PHP_EOL);
$log('CPI recompute (cpi:recompute, in process)…');

$buffer = new BufferedOutput();
try {
    $rc = (new CpiRecomputeCommand())->run(new ArrayInput([]), $buffer);
} catch (\Throwable $e) {
    $rc = 1;
    $buffer->writeln('error: ' . $e->getMessage());
}
foreach (preg_split('/\R/', trim($buffer->fetch())) ?: [] as $line) if ($line !== '') $log('  ' . $line);

// Refresh the caches that surface CPI.
DB::table('gates_cache')->where(fn($q) => $q
    ->where('cache_key', 'LIKE', '%leaderboard%')
    ->orWhere('cache_key', 'LIKE', '%lb:%')
    ->orWhere('cache_key', 'LIKE', '%dashboard%')
    ->orWhere('cache_key', 'LIKE', '%home:%'))->delete();

$ms = (int)round((microtime(true) - $t0) * 1000);
try {
    DB::table('gates_cron_log')->insert([
        'job_name'   => 'cpi',
        'status'     => $rc === 0 ? 'success' : 'error',
        'message'    => $rc === 0 ? 'Recomputed via console command' : ('console exit ' . $rc),
        'runtime_ms' => $ms,
        'ran_at'     => Carbon::now()->toDateTimeString(),
    ]);
} catch (\Throwable $e) {}
$log("Done in {$ms}ms (rc=$rc)");
