<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\VoteIndexRepair;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-runnable index repair for gates_votes.
 *
 * The dated migration does this automatically on deploy, but a migration runs once —
 * the ledger sees to that. The UNIQUE per-voter idempotency constraint legitimately
 * cannot be created while duplicate rows exist, so without this command the sequence
 * would be: deploy, "1 duplicate group, resolve it", operator resolves it, and the
 * constraint is never created because the migration is marked done. That is the same
 * silent gap the repair exists to close.
 *
 *   bin/console db:repair-indexes
 *
 * Idempotent and safe to run at any time. Exits non-zero only when the uniqueness
 * constraint is still missing, so it can gate a deploy or be alerted on.
 */
#[AsCommand(name: 'db:repair-indexes', description: 'Ensure the gates_votes indexes earlier catch-up migrations failed to create.')]
class RepairIndexesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $r  = VoteIndexRepair::run();

        foreach ($r['lines'] as $line) {
            $io->writeln($line);
        }

        if ($r['complete']) {
            $io->success('gates_votes indexes are as intended.');
            return Command::SUCCESS;
        }

        $io->error(sprintf(
            'The per-voter idempotency constraint is still MISSING%s. Until it exists, a retried '
            . 'vote can be counted twice. Resolve the duplicates above, then run this again.',
            $r['duplicates'] > 0 ? sprintf(' (%d duplicate group(s) blocking it)', $r['duplicates']) : ''
        ));
        return Command::FAILURE;
    }
}
