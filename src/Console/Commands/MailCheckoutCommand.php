<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\CheckoutMailer;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drive checkout email by hand: recover abandoned carts, and send receipts owed.
 *
 * The scheduled path is {@see \AfricaGates\Support\Maintenance} (every tick). This
 * command exists for the three things a schedule cannot do:
 *
 *   --dry-run    See exactly who WOULD be emailed before anything leaves the server.
 *                This is the one send on the platform that goes to people who did
 *                not complete an action, so it should be looked at once before it is
 *                trusted.
 *   --receipts   Send the receipts owed to already-confirmed paid-vote orders. Every
 *                paid vote taken before this feature shipped has a confirmed row and
 *                no receipt, and those buyers have never been told anything.
 *   --status     The same figures `app:doctor` reports, without the rest of it.
 *
 *   php bin/console mail:checkout --dry-run
 *   php bin/console mail:checkout
 *   php bin/console mail:checkout --receipts --limit 50
 *   php bin/console mail:checkout --status
 */
#[AsCommand(name: 'mail:checkout', description: 'Send abandoned-checkout recovery mail and any paid-vote receipts still owed.')]
final class MailCheckoutCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report who would be emailed; send nothing.');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max orders per run.', (string) CheckoutMailer::BATCH);
        $this->addOption('receipts', null, InputOption::VALUE_NONE, 'Also send receipts owed on confirmed paid-vote orders (backfill).');
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Report mail health and what is outstanding, then exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $lim = max(1, (int) $input->getOption('limit'));

        if ($input->getOption('status')) {
            $io->title('Checkout email');
            $s = CheckoutMailer::status();
            $io->table([], array_map(
                static fn ($k, $v) => [$k, (string) $v],
                array_keys($s),
                array_values($s)
            ));
            return ($s['smtp_configured'] ?? '') === 'yes' ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title(($dry ? '[dry-run] ' : '') . 'Checkout email');

        // ── Abandoned-checkout recovery ─────────────────────────────────────
        $r = CheckoutMailer::sweepAbandoned($lim, $dry);
        $io->writeln(sprintf(
            '  abandoned: %d considered, %d %s, %d skipped%s',
            $r['considered'], $r['sent'], $dry ? 'would be mailed' : 'mailed', $r['skipped'],
            $r['reasons'] ? ' — ' . json_encode($r['reasons']) : ''
        ));

        // ── Receipt backfill ────────────────────────────────────────────────
        if ($input->getOption('receipts')) {
            $owed = DB::table('gates_donations')
                ->where('tier', 'paid-vote')->where('status', 'confirmed')
                ->whereNull('receipt_sent_at')->whereNull('refunded_at')
                ->orderBy('id')->limit($lim)->pluck('id')->all();

            if ($dry) {
                $io->writeln('  receipts: ' . count($owed) . ' owed (not sent — dry run)');
            } else {
                $sent = 0; $failed = [];
                foreach ($owed as $id) {
                    $res = CheckoutMailer::receipt((int) $id);
                    if (!empty($res['sent'])) { $sent++; continue; }
                    $failed[(string) ($res['reason'] ?? 'unknown')] = ($failed[(string) ($res['reason'] ?? 'unknown')] ?? 0) + 1;
                }
                $io->writeln('  receipts: ' . $sent . ' of ' . count($owed) . ' sent'
                    . ($failed ? ' — ' . json_encode($failed) : ''));
            }
        }

        $io->success(($dry ? 'Nothing was sent (dry run).' : 'Done.'));
        return Command::SUCCESS;
    }
}
