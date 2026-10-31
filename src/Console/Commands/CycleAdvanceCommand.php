<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Console\CronLog;
use AfricaGates\Services\CyclePhase;
use AfricaGates\Services\CyclePolicy;
use AfricaGates\Services\CycleMaterialiser;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Materialises each award cycle's cached `status` column from its date windows,
 * appends the transitions ledger, fires the phase webhook and promotes winners.
 *
 * This command used to BE the lifecycle engine — and was scheduled nowhere,
 * while a second, weaker implementation inside Maintenance ran every 15 minutes.
 * Both now delegate to the single {@see CycleMaterialiser}, so there is one
 * policy, one ledger and one winner-promotion path whichever entry point fires.
 *
 * Note this is no longer what closes voting. Since the phase became a computed
 * value ({@see CyclePolicy}) every request decides for itself, so voting closes
 * on schedule even if this never runs; only the cached column and the
 * announcements depend on it.
 *
 * Schedule hourly:  bin/console cycles:advance
 */
#[AsCommand(name: 'cycles:advance', description: 'Materialise award cycle statuses from their date windows.')]
class CycleAdvanceCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing.');
    }

    /**
     * The phase a cycle should be in, from its date windows.
     *
     * Kept as a thin delegate to {@see CyclePolicy::phaseFor()} because callers
     * and tests reference it. The old body walked the windows FORWARDS and let
     * each later branch overwrite the previous one, which is why a cycle whose
     * nominations had closed reported a target of 'judging' while its voting
     * window was still in the future — and, being a backward step from judging,
     * voting then never opened at all.
     */
    public static function statusFor(object $c, Carbon $now): string
    {
        return CyclePolicy::phaseFor($c, $now)->storedValue();
    }

    /** The computed phase, undiluted by the legacy column's missing values. */
    public static function phaseFor(object $c, Carbon $now): CyclePhase
    {
        return CyclePolicy::phaseFor($c, $now);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');

        $result = CronLog::run('cycles:advance', function () use ($io, $dry) {
            $engine = new CycleMaterialiser($dry);
            $r      = $engine->run();
            foreach ($engine->lines() as $line) {
                $io->writeln($line);
            }
            return $r;
        });

        $io->success(($dry ? '[dry-run] ' : '') . $result['message']);
        return Command::SUCCESS;
    }
}
