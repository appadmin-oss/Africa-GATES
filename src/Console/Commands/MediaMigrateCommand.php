<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\CloudinaryService;
use AfricaGates\Services\MediaMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `media:cloudinary` — move locally-stored images to Cloudinary and repoint the DB.
 *
 * Loops {@see MediaMigrationService::run()} until nothing is pending, so an operator
 * with shell access runs one command and walks away; the batching inside the service
 * exists for the WEB trigger, which cannot hold a request open that long. Same service
 * either way, so the two callers cannot drift.
 *
 * `--dry-run` first is the documented habit: it prints every file it would upload and
 * every referenced file that is missing on disk, and writes nothing at all.
 */
final class MediaMigrateCommand extends Command
{
    /** Safety rail on the loop. At 25 rows a batch this is 100,000 images. */
    private const MAX_BATCHES = 4000;

    protected function configure(): void
    {
        $this->setName('media:cloudinary')
            ->setDescription('Upload locally-stored images to Cloudinary and repoint every referencing row')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen; upload and write nothing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many rows (default: no limit)', '0')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Restrict to one table, e.g. gates_nominees', '')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Print what is pending and exit');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $svc    = new MediaMigrationService();
        $dryRun = (bool) $in->getOption('dry-run');
        $limit  = max(0, (int) $in->getOption('limit'));
        $table  = trim((string) $in->getOption('table'));

        $status = $svc->status();
        $out->writeln('<info>Cloudinary</info>: ' . ($status['configured']
            ? 'configured (cloud "' . CloudinaryService::cloudName() . '", folder "' . CloudinaryService::rootFolder() . '")'
            : '<comment>NOT configured</comment> — set CLOUDINARY_URL or the three CLOUDINARY_* names'));
        $out->writeln('Local images still referenced: <info>' . $status['total'] . '</info>');
        foreach ($status['by_target'] as $target => $n) {
            $out->writeln(sprintf('  %-42s %d', $target, $n));
        }
        if ($status['migrated'] + $status['missing'] + $status['failed'] > 0) {
            $out->writeln(sprintf('Ledger: %d migrated, %d missing on disk, %d failed',
                $status['migrated'], $status['missing'], $status['failed']));
        }

        if ((bool) $in->getOption('status')) return Command::SUCCESS;
        if ($status['total'] === 0) {
            $out->writeln('<info>Nothing to do.</info>');
            return Command::SUCCESS;
        }
        if (!$status['configured'] && !$dryRun) {
            $out->writeln('<error>Refusing to run without credentials.</error> Add them, or pass --dry-run to preview.');
            return Command::FAILURE;
        }

        $out->writeln($dryRun ? "\n<comment>DRY RUN — nothing will be uploaded or written.</comment>\n" : '');

        $totals = ['migrated' => 0, 'missing' => 0, 'failed' => 0, 'skipped' => 0];
        $rows = 0;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $take = $limit > 0 ? min(MediaMigrationService::BATCH, $limit - $rows) : MediaMigrationService::BATCH;
            if ($take < 1) break;

            $r = $svc->run($dryRun, $take, $table);
            foreach (array_keys($totals) as $k) $totals[$k] += (int) ($r[$k] ?? 0);
            $rows += (int) $r['done'];

            foreach ($r['lines'] as $line) {
                // The per-batch summary line is noise when looping; the real total is printed once at the end.
                if (str_starts_with($line, 'Migrated ') || str_starts_with($line, 'Dry run:')) continue;
                $out->writeln('  ' . $line);
            }
            if (!$r['ok']) {
                $out->writeln('<error>' . implode(' ', $r['lines']) . '</error>');
                return Command::FAILURE;
            }
            // Nothing moved and nothing left: done. Nothing moved but rows remain means
            // every remaining row is un-actionable (missing/failed), so stop rather than
            // spin — the counts below say which.
            if ($r['done'] === 0) break;
            if ($dryRun && $r['pending'] === 0) break;
        }

        $out->writeln('');
        $out->writeln(sprintf('<info>%s</info> %d migrated · %d missing on disk · %d failed · %d skipped',
            $dryRun ? 'Would migrate:' : 'Done:', $totals['migrated'], $totals['missing'], $totals['failed'], $totals['skipped']));
        $remaining = $svc->status()['total'];
        if ($remaining > 0) {
            $out->writeln('<comment>' . $remaining . ' row(s) still reference local files.</comment>'
                . ($totals['missing'] > 0 ? ' Files recorded as missing were left untouched on purpose — check gates_media_migrations.' : ''));
        }
        $out->writeln('Local files were NOT deleted.');

        return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
