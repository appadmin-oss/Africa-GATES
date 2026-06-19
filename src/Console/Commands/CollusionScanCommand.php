<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Console\CronLog;
use AfricaGates\Services\CollusionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nightly collusion scan — surfaces coordinated voting rings (shared device/IP,
 * timing bursts) into the gates_collusion_findings review queue. Advisory only;
 * never voids a vote. Schedule daily:  bin/console collusion:scan
 */
#[AsCommand(name: 'collusion:scan', description: 'Scan cast votes for coordinated voting rings.')]
class CollusionScanCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('category', null, InputOption::VALUE_REQUIRED, 'Limit the scan to one category id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $cat = $input->getOption('category');

        $result = CronLog::run('collusion:scan', function () use ($cat) {
            $r = (new CollusionService())->scan($cat !== null ? (int) $cat : null);
            return ['message' => "{$r['findings']} finding(s): " . json_encode($r['by_kind'])];
        });

        $io->success($result['message']);
        return Command::SUCCESS;
    }
}
