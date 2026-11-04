<?php
declare(strict_types=1);

use AfricaGates\Support\SeedRunner;

/**
 * APPLY ANY SEED THIS DATABASE HAS NOT SEEN, AT THE ONE MOMENT AN OPERATOR IS LOOKING.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A MIGRATION RUNS SEEDS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no shell on production. `database/seeds/*.php` are run from a command line in
 * every other codebase and from nothing at all in this one, so the October handoff's own
 * step — "then on prod MySQL" — had no door to come through. Migrations DO have one: an
 * operator opens a URL and `MigrateCommand` runs the files it has not recorded.
 *
 * So this file is that door. It is not the only one — `Maintenance`'s `seeds` task sweeps
 * the same list hourly, because a seed waiting on an award that does not exist yet must
 * land when the operator finishes rather than never.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT CANNOT THROW, AND THAT IS NOT CAUTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `MigrateCommand` aborts the run on a throw and does NOT record the file. So a migration
 * that throws re-runs on the next deploy, and because the guards inside the files above it
 * are now true, every migration dated after it never applies — silently. This repo shipped
 * that three times in one week over a `CREATE INDEX IF NOT EXISTS`.
 *
 * A seed refusing because an award has not been created is the ORDINARY case here, not an
 * error, so `SeedRunner::run()` returns a status rather than throwing and this prints it.
 * A seed that genuinely failed is printed as `failed` and still does not throw: one broken
 * seed must not take the schema of everything dated after it with it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT IS DATED LAST ON PURPOSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Migrations run in FILENAME order, and a seed writes rows into tables the migrations
 * above it create. Anything dated after this one that a seed depends on would be applied
 * after the seed had already looked for it.
 */

echo "  · seeds\n";

foreach (SeedRunner::names() as $name) {
    if (SeedRunner::done($name)) {
        echo "    = {$name} already applied\n";
        continue;
    }

    $r = SeedRunner::run($name);

    echo match ($r['status']) {
        'done'    => "    + {$name} applied\n",
        'waiting' => "    ~ {$name} is waiting: {$r['note']}\n"
                   . "      (it will be retried hourly, or now via /__cron/run?task=seeds)\n",
        default   => "    ! {$name} {$r['status']}: {$r['note']}\n",
    };
}
