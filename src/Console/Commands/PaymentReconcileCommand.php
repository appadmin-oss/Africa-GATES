<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\{PaymentService, OtpService, PaymentReconciler};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backstop for dropped browser callbacks.
 *
 * The live confirm path is the gateway callback, plus — for the gates_donations
 * table only — the signature-verified /pay/webhook. Shop orders live in their own
 * gates_orders table that no webhook reconciles, so a buyer who closes the tab
 * before the callback lands can leave a genuinely-paid order stuck at 'pending'.
 *
 * This cron re-verifies stale PENDING rows server-to-server and confirms only the
 * genuinely-paid ones, with the SAME guarantees as the live path: a payment is
 * confirmed only when verify() says 'success' AND the verified naira amount equals
 * what we charged, and the pending→paid/confirmed transition is an idempotent
 * `WHERE status='pending'` UPDATE — so it can never double-credit or double-fulfil
 * and is safe to run alongside a late callback.
 *
 * It deliberately does NOT touch the security-audited controllers. The only
 * fulfilment logic it mirrors (the bound two-query stock decrement) is duplicated
 * on purpose so the reviewed confirm path stays byte-for-byte unchanged.
 *
 * ── THIS COMMAND IS NOW A THIN WRAPPER ───────────────────────────────────────
 *
 * The deciding is {@see PaymentReconciler}'s, because the admin console can run the
 * same sweep from a browser — this platform ships to shared hosting where an
 * operator frequently has no SSH, which is the whole reason /__setup/migrate and
 * /__setup/checkout exist. Two implementations of "did this payment really happen"
 * is the last thing in this codebase that should be allowed to drift, so there is
 * one, and this prints it.
 *
 * Schedule every few minutes, e.g.:  bin/console payments:reconcile
 */
#[AsCommand(name: 'payments:reconcile', description: 'Re-verify stale pending orders/donations and confirm the genuinely-paid ones.')]
final class PaymentReconcileCommand extends Command
{
    private PaymentService $payments;
    private ?OtpService $mailer;

    public function __construct(?PaymentService $payments = null, ?OtpService $mailer = null)
    {
        parent::__construct();
        $this->payments = $payments ?? new PaymentService();
        $this->mailer   = $mailer;
    }

    protected function configure(): void
    {
        $this->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Only reconcile rows older than N minutes (avoids racing the live callback).', '15');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows per table per run.', '200');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');

        $r = (new PaymentReconciler($this->payments, $this->mailer))->run(
            !$dry,
            max(0, (int) $input->getOption('minutes')),
            max(1, (int) $input->getOption('limit')),
        );

        foreach ($r['items'] as $it) {
            $mark = match ($it['action']) {
                'confirmed'    => $dry ? '  [dry] would confirm' : '  ✓ confirmed',
                // Named separately from `confirmed` because it is a different event with a
                // different story: this is a payment the platform had already WRITTEN OFF,
                // recovered because the gateway was finally reachable. Somebody should be
                // able to see that happen rather than reading it as an ordinary confirm.
                'recovered'    => $dry ? '  [dry] WAS PAID — would recover' : '  ✓ RECOVERED (was written off)',
                'failed'       => '  · marked failed',
                'mismatch'     => '  ! AMOUNT MISMATCH',
                'unverifiable' => '  ? could not verify',
                // Not a failure — a checkout nobody ever completed, finally allowed
                // to leave the queue. Named rather than silent, because "we gave up
                // after three days" is a decision an operator should see being taken.
                'expired'      => '  ✗ expired (abandoned checkout)',
                default        => '  · still pending',
            };
            $io->writeln(sprintf('%s %s %s — %s', $mark, $it['kind'], $it['ref'], $it['note']));
        }

        // Logged for the CLI too, not just the admin button. A cron that quietly
        // confirms someone's payment at 03:00 is exactly the event that has to be
        // explainable later, and it is the one nobody is watching.
        PaymentReconciler::log($r, 'cron');

        $io->success(sprintf(
            '%schecked %d · confirmed %d · recovered %d (₦%s together) · failed %d · mismatch %d '
            . '· unverifiable %d · expired %d',
            $dry ? '[dry-run] ' : '',
            $r['checked'], $r['confirmed'], $r['recovered'] ?? 0, number_format($r['naira']),
            $r['failed'], $r['mismatch'], $r['unverifiable'], $r['expired'] ?? 0
        ));

        // A mismatch is not a crash, but it is not success either — it is money that
        // needs a person. A non-zero exit makes cron surface it instead of swallowing it.
        return $r['mismatch'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
