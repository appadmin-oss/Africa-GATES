<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\PaidVoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deliver votes that were paid for and never arrived.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * mint() used to judge the voting phase on the WEBHOOK'S clock. Somebody paid at
 * 23:58 while the ballot was open, the confirmation landed at 00:02, and the mint
 * was refused — money taken, no votes, and a refund days later that nobody asked
 * for. It now judges on the ORDER'S clock, which is the fix; this command is what
 * repairs the people it already happened to.
 *
 * PaymentReconciler mints only orders it has JUST confirmed, so it will never
 * revisit this population. That is the gap.
 *
 * ── SAFE TO RUN, AND SAFE TO RUN TWICE ───────────────────────────────────────
 *
 * Every decision is mint()'s own, unchanged: it re-checks the phase on the
 * order's timestamp, the grace window, the refund state and the storage cap, and
 * the idempotency claim means a second run cannot double-credit anybody. This
 * command only chooses WHICH orders to offer it.
 *
 * Dry-run by default. Nothing moves without --commit.
 *
 * ── THE GRACE WINDOW IS THE DIAL ─────────────────────────────────────────────
 *
 * Orders whose cycle closed longer ago than `paid_vote_grace_hours` (default 6)
 * are reported as out of reach rather than minted, because a tally whose winner
 * has been announced must not move. To rescue an older backlog, raise the setting
 * DELIBERATELY, run this, and put it back — the audit trail then shows a decision
 * somebody took rather than a window that was always open.
 */
#[AsCommand(name: 'votes:remint', description: 'Retry minting paid votes that were confirmed but never delivered.')]
final class VotesRemintCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('commit', null, InputOption::VALUE_NONE, 'Actually mint (otherwise dry-run).')
             ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum orders to consider.', '500')
             ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'One payment reference, instead of the whole queue.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $commit = (bool) $input->getOption('commit');
        $limit  = max(1, min(5000, (int) $input->getOption('limit')));
        $ref    = trim((string) ($input->getOption('ref') ?? ''));

        // The population is exactly the "paid but never minted" signal the rest of
        // the platform already queries: confirmed money, a vote order, no votes.
        $q = DB::table('gates_donations')
            ->where('status', 'confirmed')
            ->where('tier', 'paid-vote')
            ->where('votes_used', 0)
            ->whereNotNull('intent_nominee_id');

        // Refunded orders are excluded HERE as well as inside mint(). Belt and
        // braces is right when the failure is paying for the same thing twice.
        foreach (['refunded_at', 'refund_requested_at'] as $col) {
            if (\AfricaGates\Support\OptionalColumn::on('gates_donations', $col)) $q->whereNull($col);
        }
        if ($ref !== '') $q->where('payment_ref', $ref);

        $rows = $q->orderBy('id')->limit($limit)->get();
        if ($rows->isEmpty()) {
            $io->success('Nothing is waiting — every confirmed vote order has its votes.');
            return Command::SUCCESS;
        }

        $io->title(($commit ? 'Re-minting' : 'DRY RUN — would re-mint') . ' ' . count($rows) . ' order(s)');
        $io->text('Grace window: ' . PaidVoteService::lateMintGraceHours() . 'h after a cycle closes.');
        $io->newLine();

        $minted = 0; $votes = 0; $blocked = [];
        $triage = new \AfricaGates\Services\PaymentTriage();

        foreach ($rows as $d) {
            if (!$commit) {
                // A dry run must not mint, so it reports the population and the
                // dial rather than pretending to predict mint()'s answer.
                $io->text(sprintf('  %-26s %6d vote(s)  %s',
                    (string) $d->payment_ref, (int) $d->bonus_votes, (string) $d->created_at));
                continue;
            }

            // ── THE GATEWAY HAS TO AGREE, EVERY TIME THIS PATH RUNS ──────
            //
            // The population above is "our column says confirmed". The live
            // callback and webhook earned that column by verifying server to
            // server; this command inherits it, possibly weeks later, and a
            // column can be wrong — a bad reconciler run, a hand-edit, a restore,
            // a repair that checked the wrong provider. Minting votes for money
            // that was never taken is the mirror image of the bug this platform
            // has been chasing, and it is worse, because it inflates a result
            // quietly instead of failing loudly.
            //
            // So the flag is not enough here. Ask Paystack.
            $agree = $triage->gatewayAgrees($d);
            if (!$agree['ok']) {
                $blocked['GATEWAY_DISAGREES'] = ($blocked['GATEWAY_DISAGREES'] ?? 0) + 1;
                $io->text('  <comment>·</comment> ' . $d->payment_ref . ' — NOT MINTED: ' . $agree['reason']);
                continue;
            }

            $r = PaidVoteService::mint((int) $d->id);
            if (!empty($r['ok'])) {
                $minted++;
                $votes += (int) ($r['minted'] ?? $d->bonus_votes);
                $io->text('  <info>✓</info> ' . $d->payment_ref . ' — ' . (int) $d->bonus_votes . ' vote(s) delivered');
            } else {
                $code = (string) ($r['code'] ?? 'FAILED');
                $blocked[$code] = ($blocked[$code] ?? 0) + 1;
                $io->text('  <comment>·</comment> ' . $d->payment_ref . ' — ' . $code);
            }
        }

        $io->newLine();
        if (!$commit) {
            $io->warning('Dry run. Re-run with --commit to deliver these votes.');
            return Command::SUCCESS;
        }

        $io->success($minted . ' order(s) delivered, ' . number_format($votes) . ' vote(s) added.');

        if ($blocked) {
            // Named, not swallowed. CONFIRMED_TOO_LATE means the grace window is
            // the reason and somebody has a decision to take; ALREADY_REFUNDED
            // means the money went back and there is nothing to do.
            foreach ($blocked as $code => $n) $io->text('  ' . $n . ' × ' . $code);
            if (isset($blocked['CONFIRMED_TOO_LATE'])) {
                $io->note('CONFIRMED_TOO_LATE orders are outside the grace window. Raising '
                    . 'paid_vote_grace_hours would bring them into reach — do that deliberately, '
                    . 'and put it back afterwards.');
            }
        }

        return Command::SUCCESS;
    }
}
