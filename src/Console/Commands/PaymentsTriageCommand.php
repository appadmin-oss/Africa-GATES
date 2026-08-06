<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\PaymentService;
use AfricaGates\Services\RefundService;
use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * "They were charged and got nothing." Where, exactly, did it stop?
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * Every other tool here answers a question about orders the platform KNOWS were
 * paid. `votes:proof` counts delivery against confirmed orders. RefundService
 * sweeps confirmed orders that minted nothing. The finance screens total confirmed
 * money. All of them start from `status = 'confirmed'`.
 *
 * The complaint that will not go away starts one step earlier than that.
 *
 * A buyer's card is charged at the gateway and our row never flips to confirmed —
 * the callback never came back, the webhook never arrived or was rejected, the
 * verification call could not reach the gateway. From that moment the order is
 * invisible to the entire repair apparatus:
 *
 *   • PaidVoteService::mint() refuses at "Order is not confirmed"
 *   • RefundService::sweep() filters on where('status','confirmed')
 *   • CheckoutMailer sends a receipt only for a confirmed order
 *
 * No votes, no refund, no email, no ticket, nothing in any report. The platform
 * behaves exactly as it would if the person had never tried to pay, and the only
 * party who knows otherwise is their bank. That is the worst state this system can
 * put somebody in, and it is the one state nothing was watching.
 *
 * ── WHAT THIS DOES ───────────────────────────────────────────────────────────
 *
 * Buckets every paid-vote order by where it actually stopped, and — with
 * --verify — ASKS THE GATEWAY about the stuck ones, because our own database is
 * precisely the thing that cannot answer "did the money leave their account".
 *
 * It also reports the two switches that would explain total silence: whether
 * automatic refunds are on, and when maintenance last ran. Neither is visible in
 * the code, both are one line to check, and either one being wrong makes every
 * repair path in the platform a no-op while the code stays innocent.
 *
 * Read-only unless you pass --fix, which confirms the orders the gateway says were
 * genuinely paid, putting them back on the normal path where mint, receipt and
 * refund can all see them again.
 */
#[AsCommand(name: 'payments:triage', description: 'Where did each paid-vote order stop? Finds charges the platform never noticed.')]
final class PaymentsTriageCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Only orders from the last N days. Omit for all time.')
             ->addOption('verify', null, InputOption::VALUE_NONE, 'Ask the gateway about stuck orders. Makes network calls.')
             ->addOption('fix', null, InputOption::VALUE_NONE, 'Confirm the ones the gateway says were paid. Implies --verify.')
             ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Cap gateway lookups.', '200')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $days   = $input->getOption('days') !== null ? max(1, (int) $input->getOption('days')) : null;
        $fix    = (bool) $input->getOption('fix');
        $verify = $fix || (bool) $input->getOption('verify');

        $q = DB::table('gates_donations')->where('tier', 'paid-vote');
        if ($days !== null) $q->where('created_at', '>=', date('Y-m-d H:i:s', time() - $days * 86400));
        $orders = $q->orderBy('id')->get();

        if ($orders->isEmpty()) {
            $io->note('No paid-vote orders in range.');
            return Command::SUCCESS;
        }

        // ── Bucket by where each order actually stopped ──────────────────────
        $b = ['delivered' => [], 'refunded' => [], 'refund_owed' => [], 'stuck_pending' => [], 'failed' => [], 'in_flight' => []];
        $now = time();

        foreach ($orders as $o) {
            $status = (string) ($o->status ?? '');
            $used   = (int) ($o->votes_used ?? 0);

            if ($status === 'failed')  { $b['failed'][] = $o; continue; }
            if ($status === 'pending') {
                // Still inside its own checkout window: the buyer may simply be on the
                // gateway's page right now. Not a fault yet.
                $expires = $o->checkout_expires_at ?? null;
                $fresh = $expires !== null
                    ? strtotime((string) $expires) > $now
                    : (strtotime((string) $o->created_at) > $now - 3600);
                $fresh ? $b['in_flight'][] = $o : $b['stuck_pending'][] = $o;
                continue;
            }
            if ($status !== 'confirmed') continue;

            if ($used > 0)                                  { $b['delivered'][] = $o; continue; }
            if (($o->refunded_at ?? null) !== null
                || ($o->refund_requested_at ?? null) !== null) { $b['refunded'][] = $o; continue; }
            $b['refund_owed'][] = $o;
        }

        $sum = static fn (array $rows): int => (int) array_sum(array_map(static fn ($r) => (int) $r->amount_naira, $rows));

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'counts' => array_map('count', $b),
                'naira'  => array_map($sum, $b),
                'auto_refund_on' => RefundService::autoEnabled(),
                'last_maintenance' => self::lastCron(),
            ], JSON_PRETTY_PRINT));
            return $b['stuck_pending'] || $b['refund_owed'] ? Command::FAILURE : Command::SUCCESS;
        }

        $io->title('Paid-vote orders' . ($days ? " — last {$days} day(s)" : ''));
        $io->table(['Where it stopped', 'Orders', 'Naira'], [
            ['Delivered — votes on the tally',        count($b['delivered']),     number_format($sum($b['delivered']))],
            ['Refunded / refund in flight',           count($b['refunded']),      number_format($sum($b['refunded']))],
            ['CONFIRMED, no votes, no refund',        count($b['refund_owed']),   number_format($sum($b['refund_owed']))],
            ['STUCK PENDING — may be charged',        count($b['stuck_pending']), number_format($sum($b['stuck_pending']))],
            ['Still in checkout (normal)',            count($b['in_flight']),     number_format($sum($b['in_flight']))],
            ['Failed to start (never charged)',       count($b['failed']),        number_format($sum($b['failed']))],
        ]);

        // ── The two switches that make everything a silent no-op ─────────────
        $io->section('Is the repair machinery even running?');
        $autoRefund = RefundService::autoEnabled();
        $lastCron   = self::lastCron();
        $io->definitionList(
            ['Automatic refunds' => $autoRefund ? 'ON' : 'OFF — nothing is being refunded, by configuration'],
            ['Maintenance last ran' => $lastCron ?? 'NEVER — no cron has ever completed'],
            ['Paystack key'    => Env::get('PAYSTACK_SECRET_KEY', '') !== '' ? 'set' : 'MISSING'],
            ['Flutterwave key' => Env::get('FLUTTERWAVE_SECRET_KEY', '') !== '' ? 'set' : 'MISSING'],
        );

        if (!$autoRefund) {
            $io->warning('Automatic refunds are switched off. Every order in the "confirmed, no votes, no refund" '
                       . 'row above is money the platform is holding for votes it did not deliver, and nothing '
                       . 'will send it back on its own.');
        }
        if ($lastCron === null || strtotime($lastCron) < $now - 3600) {
            $io->error("Maintenance has not completed in the last hour (last: " . ($lastCron ?? 'never') . ").\n\n"
                . "Reconciliation, automatic refunds, receipts for abandoned checkouts and ticket answers ALL run\n"
                . "from that tick. If it is not running, none of them are — and the code will look perfectly\n"
                . "healthy the whole time, because nothing is failing. Nothing is happening.");
        }

        // ── The bucket nothing else watches ──────────────────────────────────
        if (!$b['stuck_pending']) {
            $io->success('No stuck-pending orders. Charges the platform never noticed are not the problem here.');
        } else {
            $io->section(count($b['stuck_pending']) . ' order(s) stuck at pending');
            $io->text('These are invisible to mint() AND to the refund sweep, which both start from '
                    . '"confirmed". If the money left the buyer\'s account, they have no votes, no refund and '
                    . 'no receipt — and no report other than this one mentions them.');

            if (!$verify) {
                $io->note('Run again with --verify to ask the gateway which of these were actually charged. '
                        . 'That is the only way to know; our own database is exactly the thing that does not.');
            }
        }

        if ($verify && $b['stuck_pending']) {
            return $this->askTheGateway($io, $b['stuck_pending'], (int) $input->getOption('limit'), $fix);
        }

        return ($b['stuck_pending'] || $b['refund_owed']) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The only authority on whether somebody was charged.
     *
     * Our row says pending precisely because we never found out. Asking the gateway
     * is not a nicety here — it is the entire question.
     */
    private function askTheGateway(SymfonyStyle $io, array $stuck, int $limit, bool $fix): int
    {
        $payments = new PaymentService();
        $enabled  = [];
        foreach (['paystack', 'flutterwave'] as $p) {
            if ($payments->isEnabled($p)) $enabled[] = $p;
        }
        if (!$enabled) {
            $io->error('No payment provider is configured in this environment, so the gateway cannot be asked. '
                     . 'Set PAYSTACK_SECRET_KEY / FLUTTERWAVE_SECRET_KEY and run this again. Until then every '
                     . 'stuck order stays stuck, because verification is what unsticks them.');
            return Command::FAILURE;
        }

        $charged = [];
        $clean   = 0;
        $unknown = 0;

        foreach (array_slice($stuck, 0, max(1, $limit)) as $o) {
            $stored = strtolower(trim((string) ($o->provider ?? '')));
            $try = $stored !== '' && in_array($stored, $enabled, true)
                ? array_merge([$stored], array_diff($enabled, [$stored]))
                : $enabled;

            $hit = null;
            foreach ($try as $provider) {
                try { $v = $payments->verify($provider, (string) $o->payment_ref); }
                catch (\Throwable) { continue; }
                if (!empty($v['ok']) && (string) ($v['status'] ?? '') === 'success') {
                    $hit = ['provider' => $provider, 'amount' => (int) ($v['amount'] ?? 0)];
                    break;
                }
            }

            if ($hit === null) { $clean++; continue; }
            $charged[] = [$o, $hit];
        }

        if (!$charged) {
            $io->success($clean . ' stuck order(s) checked — the gateway has no successful payment for any of '
                       . 'them. Those buyers were not charged; the checkouts were simply abandoned.');
            return Command::SUCCESS;
        }

        $total = array_sum(array_map(static fn ($c) => (int) $c[0]->amount_naira, $charged));
        $io->error(count($charged) . ' order(s) WERE CHARGED and the platform never noticed. ₦'
                 . number_format($total) . ' taken, no votes, no refund.');
        $io->table(['Reference', 'Provider', 'Ours (₦)', 'Gateway (₦)', 'When'], array_map(
            static fn ($c) => [
                (string) $c[0]->payment_ref, $c[1]['provider'],
                number_format((int) $c[0]->amount_naira), number_format($c[1]['amount']),
                (string) $c[0]->created_at,
            ], array_slice($charged, 0, 40)));

        if (!$fix) {
            $io->note('Run again with --fix to confirm these orders. That puts them back on the normal path: '
                    . 'mint credits the votes where it still can, and the refund sweep can see the rest. '
                    . 'Nothing here is decided silently — every one becomes either votes or money back.');
            return Command::FAILURE;
        }

        $fixed = 0;
        foreach ($charged as [$o, $hit]) {
            try {
                $changed = DB::table('gates_donations')->where('id', $o->id)->where('status', 'pending')
                    ->update(\AfricaGates\Support\OptionalColumn::filter('gates_donations', [
                        'status'       => 'confirmed',
                        'provider'     => $hit['provider'],
                        'confirmed_at' => date('Y-m-d H:i:s'),
                    ], ['confirmed_at', 'provider']));
                if ($changed === 0) continue;
                $fixed++;
                // Same delivery the live paths perform. Idempotent; a refusal leaves
                // votes_used = 0, which the refund sweep now CAN see.
                \AfricaGates\Services\PaidVoteService::mint((int) $o->id);
                \AfricaGates\Services\CheckoutMailer::receipt((int) $o->id);
            } catch (\Throwable $e) {
                $io->warning('Could not repair ' . (string) $o->payment_ref . ': ' . $e->getMessage());
            }
        }

        $io->success($fixed . ' order(s) confirmed and put back on the normal path.');
        $io->text('Check `votes:proof` for what minted, and the refund queue for what could not. Then find out '
                . 'why the confirmation never arrived in the first place — this command repairs the damage, '
                . 'not the cause.');
        return Command::SUCCESS;
    }

    private static function lastCron(): ?string
    {
        try {
            $v = DB::table('gates_cron_log')->where('job_name', 'maintenance')->max('ran_at');
            return $v !== null ? (string) $v : null;
        } catch (\Throwable) { return null; }
    }
}
