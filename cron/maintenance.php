<?php
/**
 * Africa GATES — Single automation hub (CLI entry).
 *
 * A thin wrapper over {@see AfricaGates\Support\Maintenance}, which holds the
 * actual orchestration and is shared with the token-gated webcron endpoint
 * (/__cron/run). Hooked from a single cron entry (every 15 min, or hourly):
 *
 *   * /15 * * * *  /usr/bin/php /path/to/cron/maintenance.php
 *
 * Manual run:  php cron/maintenance.php [task]
 *   tasks: cycles | cpi | cache | queue | otp | magic | collusion | digest | all
 *          (default: auto — selects work by the clock)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

// Every process must agree on what time it is: the award-cycle phase is
// computed from date windows against the clock, so a CLI/web SAPI timezone
// disagreement would make cron and web requests disagree about whether voting
// is open — permanently and silently. Runs after .env so APP_TIMEZONE applies.
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$capsule = new DB();
$capsule->addConnection(require $root . '/config/database.php');
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Single-instance guard — a run that overruns its window must not overlap the
// next tick (double reminders, double job-drains, SQLite write contention).
if (!\AfricaGates\Support\CronGuard::acquire('maintenance', $root . '/var/data')) {
    fwrite(STDERR, "[maintenance] another run is still in progress — exiting.\n");
    exit(0);
}

// DI container so the queue worker + reminder sender can resolve services.
$builder = new \DI\ContainerBuilder();
$builder->addDefinitions(require $root . '/config/container.php');
$container = $builder->build();

$task = $argv[1] ?? 'auto';
(new \AfricaGates\Support\Maintenance($container, true))->run($task);
