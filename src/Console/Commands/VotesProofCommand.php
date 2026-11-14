<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\VoteProof;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * "Is there any proof to show them?"
 *
 * ── WHAT THIS IS FOR ─────────────────────────────────────────────────────────
 *
 * Supporters were told the unminted-vote incident was resolved and asked for
 * proof, which is the correct response to being told something by a platform that
 * had just been publicly wrong. This produces the number behind that claim.
 *
 * ── IT IS BUILT TO BE ABLE TO SAY NO ─────────────────────────────────────────
 *
 * That is the entire design constraint. A report that can only confirm good news
 * is not evidence, it is marketing, and running it would be a way of feeling
 * better rather than knowing something. So:
 *
 *   • it counts VOTE ROWS, not the `votes_used` counter — reading the counter asks
 *     the system whether it thinks it did the work, reading the rows asks whether
 *     the work is there, and those can disagree;
 *   • a claim with no rows behind it gets its own loud category, because it is a
 *     mint that died half-way and nothing else on the platform detects it;
 *   • the exit code is non-zero when anything is outstanding, so this can sit in
 *     cron and shout rather than being something somebody remembers to check;
 *   • `clean` is true only when every failure bucket is zero. 99.8% delivered is
 *     not "sorted" to the 0.2%, and they are the ones who will be writing to you.
 *
 * ── HOW TO USE THE OUTPUT PUBLICLY ───────────────────────────────────────────
 *
 * If it comes back clean, the sentence you can honestly publish is in the summary,
 * and every supporter can verify their own order at /vote/verify — which is the
 * part that makes it proof rather than another assertion. Point them there instead
 * of asking them to believe an aggregate about other people's orders.
 */
#[AsCommand(name: 'votes:proof', description: 'Prove — or disprove — that every paid vote was delivered.')]
final class VotesProofCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED,
                         'Only orders from the last N days. Omit for all time.')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable, for a dashboard or a cron.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $days = $input->getOption('days') !== null ? max(1, (int) $input->getOption('days')) : null;

        if (!VoteProof::ready()) {
            $io->error('The schema cannot answer this yet — run db:migrate first.');
            return Command::FAILURE;
        }

        $r = VoteProof::tally($days);
        if (empty($r['ok'])) {
            $io->error((string) ($r['error'] ?? 'Could not build the report.'));
            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $r['clean'] ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title('Paid vote delivery — ' . $r['window']);
        $io->text('Generated ' . $r['generated'] . '. Counted from the VOTE ROWS, not the order counter.');
        $io->newLine();

        $b = $r['orders'];
        $io->table(
            ['Outcome', 'Orders', 'What it means'],
            [
                ['delivered',           (string) $b['delivered'],           'paid, votes on the tally, counter agrees'],
                ['awaiting_delivery',   (string) $b['awaiting_delivery'],   'PAID, NO VOTES — still owed'],
                ['claimed_but_missing', (string) $b['claimed_but_missing'], 'counter says minted, no rows — broken mint'],
                ['partial',             (string) $b['partial'],             'some rows, fewer than claimed'],
                ['refunded',            (string) $b['refunded'],            'money returned, votes correctly absent'],
                ['pending',             (string) $b['pending'],             'not confirmed yet — not a fault'],
                ['not_paid',            (string) $b['not_paid'],            'failed or abandoned — not a fault'],
            ]
        );

        $io->text(sprintf('Votes delivered: <info>%s</info>', number_format($r['votes']['delivered'])));

        if ($r['clean']) {
            $io->success($r['say']);
            $io->note('Supporters can verify their own order at /vote/verify with their AFG- reference. '
                . 'Point them there rather than asking them to trust a total about other people\'s orders.');
            return Command::SUCCESS;
        }

        // Loud, specific, and with real references so the operator can spot-check
        // this report rather than trusting it.
        $io->newLine();
        $io->error($r['say']);
        $io->text('₦' . number_format($r['naira_owed']) . ' taken for '
            . number_format($r['votes']['owed']) . ' vote(s) that are not on any tally.');
        $io->newLine();
        $io->text('<comment>Spot-check these:</comment>');
        foreach ($r['examples'] as $e) {
            $io->text(sprintf('  %-26s %-20s ordered %-5d delivered %-5d ₦%s',
                $e['ref'], $e['state'], $e['ordered'], $e['delivered'], number_format($e['naira'])));
        }
        $io->newLine();
        $io->text('Next: <info>php bin/console votes:remint</info> (dry run), then --commit.');
        $io->text('Anything it reports as CONFIRMED_TOO_LATE is outside the delivery window and refundable.');

        // Non-zero so cron surfaces it instead of swallowing it.
        return Command::FAILURE;
    }
}
