<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\{PaymentService, OtpService, Notifier};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
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
 * here on purpose so the reviewed confirm path stays byte-for-byte unchanged.
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
        $io      = new SymfonyStyle($input, $output);
        $minutes = max(0, (int) $input->getOption('minutes'));
        $limit   = max(1, (int) $input->getOption('limit'));
        $dry     = (bool) $input->getOption('dry-run');
        $cutoff  = Carbon::now()->subMinutes($minutes)->toDateTimeString();

        $orders    = $this->reconcileOrders($cutoff, $limit, $dry, $io);
        $donations = $this->reconcileDonations($cutoff, $limit, $dry, $io);

        $io->success(($dry ? '[dry-run] ' : '') . "reconciled {$orders} order(s) and {$donations} donation(s).");
        return Command::SUCCESS;
    }

    /** Shop orders: gates_orders stores the provider, so verify against it directly. */
    private function reconcileOrders(string $cutoff, int $limit, bool $dry, SymfonyStyle $io): int
    {
        $rows = DB::table('gates_orders')->where('status', 'pending')
            ->where('created_at', '<', $cutoff)->orderBy('id')->limit($limit)->get();
        $confirmed = 0;
        foreach ($rows as $o) {
            $provider = strtolower((string) ($o->provider ?? ''));
            if (!$this->payments->isKnownProvider($provider) || !$this->payments->isEnabled($provider)) {
                continue; // provider unknown/disabled — can't verify; leave for a human
            }
            $v = $this->payments->verify($provider, (string) $o->reference);
            if (!$v['ok']) {
                continue; // transient verify failure — retry next run
            }
            if (($v['status'] ?? '') === 'failed') {
                if (!$dry) {
                    DB::table('gates_orders')->where('reference', $o->reference)->where('status', 'pending')
                        ->update(['status' => 'failed']);
                }
                continue;
            }
            if (($v['status'] ?? '') !== 'success') {
                continue; // still pending at the gateway — leave it
            }
            // Amount parity is load-bearing: never confirm an order for less than its subtotal.
            if ((int) $v['amount'] !== (int) $o->subtotal_naira) {
                $io->writeln(sprintf('  ! order %s amount mismatch (expected %d, verified %d) — skipped',
                    $o->reference, (int) $o->subtotal_naira, (int) $v['amount']));
                continue;
            }
            if ($dry) {
                $io->writeln('  [dry] would confirm order ' . $o->reference);
                continue;
            }
            // Idempotent: only the single winning pending→paid writer fulfils.
            $changed = DB::table('gates_orders')->where('reference', $o->reference)->where('status', 'pending')
                ->update(['status' => 'paid', 'paid_at' => Carbon::now()->toDateTimeString(), 'provider_ref' => (string) $o->reference]);
            if ($changed > 0) {
                $this->fulfilOrder($o);
                $io->writeln('  ✓ confirmed order ' . $o->reference);
                $confirmed++;
            }
        }
        return $confirmed;
    }

    /**
     * gates_donations (vote packs, tickets, free-amount gifts) carries no stored
     * provider, so verify against each enabled gateway — only the one that issued
     * the reference recognises it. Status-only, matching the existing /pay/webhook
     * (bonus votes are redeemed separately; donation receipts are sent by the
     * callback path).
     */
    private function reconcileDonations(string $cutoff, int $limit, bool $dry, SymfonyStyle $io): int
    {
        $providers = $this->payments->enabledProviderIds();
        if (!$providers) {
            return 0;
        }
        $rows = DB::table('gates_donations')->where('status', 'pending')
            ->where('created_at', '<', $cutoff)->orderBy('id')->limit($limit)->get();
        $confirmed = 0;
        foreach ($rows as $d) {
            foreach ($providers as $provider) {
                $v = $this->payments->verify($provider, (string) $d->payment_ref);
                if (!$v['ok'] || ($v['status'] ?? '') !== 'success') {
                    continue; // not this gateway, or not paid yet — try the next
                }
                if ((int) $v['amount'] !== (int) $d->amount_naira) {
                    $io->writeln(sprintf('  ! donation %s amount mismatch — skipped', $d->payment_ref));
                    break;
                }
                if ($dry) {
                    $io->writeln('  [dry] would confirm donation ' . $d->payment_ref);
                    break;
                }
                $changed = DB::table('gates_donations')->where('payment_ref', $d->payment_ref)->where('status', 'pending')
                    ->update(['status' => 'confirmed']);
                if ($changed > 0) {
                    $io->writeln('  ✓ confirmed donation ' . $d->payment_ref);
                    $confirmed++;
                }
                break; // a provider recognised this reference — done with this row
            }
        }
        return $confirmed;
    }

    /**
     * One-time side effects of a confirmed order. Mirrors ShopCheckoutController::fulfil
     * INTENTIONALLY (kept separate so the audited confirm path is untouched): decrement
     * tracked stock with the same two bound queries (never a string-built CASE, never
     * below zero), then a best-effort receipt + operator alert. Only the single winning
     * pending→paid transition reaches here, so it runs exactly once per order.
     */
    private function fulfilOrder(object $order): void
    {
        $lines = json_decode((string) $order->items_json, true) ?: [];
        foreach ($lines as $l) {
            $slug = (string) ($l['slug'] ?? ''); $qty = (int) ($l['qty'] ?? 0);
            if ($slug === '' || $qty < 1) {
                continue;
            }
            DB::table('gates_products')->where('slug', $slug)->whereNotNull('stock')
                ->where('stock', '>=', $qty)->decrement('stock', $qty);
            DB::table('gates_products')->where('slug', $slug)->whereNotNull('stock')
                ->where('stock', '<', $qty)->update(['stock' => 0]);
        }

        $total = '₦' . number_format((int) $order->subtotal_naira);
        if ($this->mailer) {
            try {
                $this->mailer->sendBranded(
                    (string) $order->email,
                    'Your Africa GATES order is confirmed',
                    '<p>Thank you, ' . htmlspecialchars((string) $order->name) . ' — your payment is confirmed and your order is being prepared.</p>'
                    . '<p style="font-family:monospace">Order ' . htmlspecialchars((string) $order->reference) . '</p>'
                    . "<p>Total paid: <strong>{$total}</strong>. Every purchase funds child leadership programmes — thank you.</p>",
                    'Shop'
                );
            } catch (\Throwable $e) { /* a receipt failure must never undo a confirmation */ }
        }
        Notifier::adminAlert($this->mailer, 'Shop order reconciled to paid (cron)',
            "Order {$order->reference} was confirmed by payments:reconcile after a missed callback.\n"
            . "By:    {$order->name} <{$order->email}>\nTotal: {$total}");
    }
}
