<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\BonusVoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reverse a refunded / charged-back donation — voids the purchased votes it
 * bought and rebuilds the affected nominees' counters. For refunds handled
 * outside the payment webhook (a manual gateway refund, a bank chargeback).
 * Dry-run by default; pass --commit to apply.
 */
#[AsCommand(name: 'payments:clawback', description: 'Void the purchased votes from a refunded/charged-back donation.')]
class PaymentClawbackCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('donation', InputArgument::REQUIRED, 'gates_donations.id or its payment_ref')
             ->addOption('commit', null, InputOption::VALUE_NONE, 'Apply the clawback (otherwise dry-run).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $arg = trim((string) $input->getArgument('donation'));

        $donation = ctype_digit($arg)
            ? DB::table('gates_donations')->where('id', (int) $arg)->first()
            : DB::table('gates_donations')->where('payment_ref', $arg)->first();
        if (!$donation) { $io->error("No donation matches '{$arg}'."); return Command::FAILURE; }

        $rows = DB::table('gates_votes')->where('donation_id', (int) $donation->id)->get(['nominee_id', 'weight']);
        $weight = (int) $rows->sum('weight');
        $io->writeln(sprintf('Donation #%d (%s) — %d purchased vote row(s), %d total weight.',
            (int) $donation->id, (string) ($donation->payment_ref ?? '—'), $rows->count(), $weight));
        if (!empty($donation->refunded_at)) $io->warning('Already marked refunded at ' . $donation->refunded_at . '.');

        if (!$input->getOption('commit')) {
            $io->note('Dry run — re-run with --commit to void these votes.');
            return Command::SUCCESS;
        }

        $r = BonusVoteService::clawbackDonation((int) $donation->id, null, 'console');
        if (!($r['ok'] ?? false)) { $io->error($r['error'] ?? 'Clawback failed.'); return Command::FAILURE; }
        $io->success(sprintf('Clawed back %d vote row(s) (%d weight) across %d nominee(s); counters rebuilt.',
            $r['cleared'], $r['weight'], count($r['nominees'])));
        return Command::SUCCESS;
    }
}
