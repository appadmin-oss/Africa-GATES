<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "They were charged and got nothing." Where, exactly, did it stop?
 *
 * ── THE BUCKET NOTHING WAS WATCHING ──────────────────────────────────────────
 *
 * Every repair path in this platform begins at `status = 'confirmed'`:
 *
 *   PaidVoteService::mint()    refuses at "Order is not confirmed"
 *   RefundService::sweep()     ->where('status', 'confirmed')
 *   CheckoutMailer::receipt()  only sends for a confirmed order
 *
 * So an order whose card was charged at the gateway but whose row never flipped —
 * the callback never came back, the webhook never arrived or was rejected, the
 * verification call could not reach the gateway — is invisible to all three at
 * once. No votes, no refund, no receipt, no ticket, and no mention in any report
 * the platform produces. It behaves exactly as if the person had never tried to
 * pay, and the only party who knows otherwise is their bank.
 *
 * The whole repair apparatus was built around "paid but unminted", meaning
 * confirmed with votes_used = 0. The likelier and worse case is "pending but
 * actually paid", and it had no sweeper at all.
 *
 * ── WHY THIS IS A SERVICE AND NOT A COMMAND ──────────────────────────────────
 *
 * Because the person who needs it may have no shell. This platform is deployed on
 * shared hosting where SSH is not a given — the codebase already carries a webcron
 * endpoint and a token-gated asset builder for exactly that reason. A diagnostic
 * that only exists as a CLI command is a diagnostic that operator cannot run, and
 * the whole point of this one is that it is the only place the missing money is
 * visible. So the logic lives here and both the admin screen and the console
 * command are thin callers, the same arrangement PaymentReconciler already uses.
 */
final class PaymentTriage
{
    /** An order still inside this many seconds of being created may just be at the gateway. */
    private const IN_FLIGHT_SECONDS = 3600;

    public function __construct(private readonly ?PaymentService $payments = null) {}

    /**
     * Every paid-vote order, grouped by where it actually stopped.
     *
     * @return array{buckets: array<string, array<int,object>>, counts: array<string,int>, naira: array<string,int>}
     */
    public static function buckets(?int $days = null): array
    {
        $q = DB::table('gates_donations')->where('tier', 'paid-vote');
        if ($days !== null) $q->where('created_at', '>=', date('Y-m-d H:i:s', time() - $days * 86400));

        $b = ['delivered' => [], 'refunded' => [], 'refund_owed' => [],
              'stuck_pending' => [], 'in_flight' => [], 'failed' => []];
        $now = time();

        foreach ($q->orderByDesc('id')->get() as $o) {
            $status = (string) ($o->status ?? '');

            if ($status === 'failed') { $b['failed'][] = $o; continue; }

            if ($status === 'pending') {
                // Inside its own checkout window the buyer may simply be on the
                // gateway's page right now. Past it, nothing will ever look at this
                // row again unless somebody asks the gateway about it.
                $expires = $o->checkout_expires_at ?? null;
                $fresh = $expires !== null
                    ? strtotime((string) $expires) > $now
                    : strtotime((string) $o->created_at) > $now - self::IN_FLIGHT_SECONDS;
                $fresh ? $b['in_flight'][] = $o : $b['stuck_pending'][] = $o;
                continue;
            }
            if ($status !== 'confirmed') continue;

            if ((int) ($o->votes_used ?? 0) > 0)              { $b['delivered'][] = $o; continue; }
            if (($o->refunded_at ?? null) !== null
                || ($o->refund_requested_at ?? null) !== null) { $b['refunded'][] = $o; continue; }
            $b['refund_owed'][] = $o;
        }

        return [
            'buckets' => $b,
            'counts'  => array_map('count', $b),
            'naira'   => array_map(
                static fn (array $rows): int => (int) array_sum(array_map(static fn ($r) => (int) $r->amount_naira, $rows)),
                $b),
        ];
    }

    /**
     * The switches that make every repair path a silent no-op.
     *
     * None of these are visible in the code and any one of them being wrong stops
     * everything while nothing appears to fail. They belong beside the numbers
     * rather than in a runbook nobody opens during an incident.
     *
     * @return array{auto_refund:bool, last_cron:?string, cron_stale:bool, paystack:bool, flutterwave:bool}
     */
    public static function health(): array
    {
        $last = null;
        try {
            $v = DB::table('gates_cron_log')->where('job_name', 'maintenance')->max('ran_at');
            $last = $v !== null ? (string) $v : null;
        } catch (\Throwable) {}

        return [
            'auto_refund' => RefundService::autoEnabled(),
            'last_cron'   => $last,
            'cron_stale'  => $last === null || strtotime($last) < time() - 3600,
            'paystack'    => trim((string) Env::get('PAYSTACK_SECRET_KEY', '')) !== '',
            'flutterwave' => trim((string) Env::get('FLUTTERWAVE_SECRET_KEY', '')) !== '',
        ];
    }

    /** Which gateways this installation can actually talk to. */
    public function enabledProviders(): array
    {
        $out = [];
        $p = $this->payments ?? new PaymentService();
        foreach (['paystack', 'flutterwave'] as $name) {
            try { if ($p->isEnabled($name)) $out[] = $name; } catch (\Throwable) {}
        }
        return $out;
    }

    /**
     * ONE REFERENCE, BOTH STORIES, SIDE BY SIDE.
     *
     * Our row says what we noticed. The gateway says what happened to the money.
     * The entire class of bug in front of us is the gap between those two
     * sentences, and until now there was nowhere to read them together — finance
     * screens show our version, the gateway dashboard shows theirs, and nobody was
     * comparing line by line.
     *
     * @return array{found:bool, ours:?object, gateway:?array, verdict:string, detail:string}
     */
    public function lookup(string $reference): array
    {
        $reference = trim($reference);
        $ours = $reference === '' ? null
              : DB::table('gates_donations')->where('payment_ref', $reference)->first();

        $gateway = null;
        $p = $this->payments ?? new PaymentService();
        $stored = strtolower(trim((string) ($ours->provider ?? '')));
        $order  = $stored !== '' ? array_merge([$stored], array_diff($this->enabledProviders(), [$stored]))
                                 : $this->enabledProviders();

        foreach ($order as $provider) {
            try { $v = $p->verify($provider, $reference); } catch (\Throwable) { continue; }
            if (!empty($v['ok'])) { $gateway = $v + ['provider' => $provider]; break; }
        }

        // ── Say what it MEANS, not just what the fields are ──────────────────
        if ($ours === null && $gateway === null) {
            return ['found' => false, 'ours' => null, 'gateway' => null, 'verdict' => 'unknown',
                    'detail' => 'Neither we nor the gateway recognise this reference. Check the characters — '
                              . 'buyers often quote the gateway\'s own transaction id rather than ours.'];
        }
        if ($ours === null) {
            return ['found' => true, 'ours' => null, 'gateway' => $gateway, 'verdict' => 'orphan',
                    'detail' => 'The gateway knows this payment and WE HAVE NO ORDER FOR IT. Money was taken '
                              . 'against a reference this platform never recorded, so nothing here can repair '
                              . 'it automatically — it needs a manual refund at the gateway.'];
        }
        $status = (string) $ours->status;
        $paid   = $gateway !== null && (string) ($gateway['status'] ?? '') === 'success';

        if ($status === 'pending' && $paid) {
            return ['found' => true, 'ours' => $ours, 'gateway' => $gateway, 'verdict' => 'charged_unnoticed',
                    'detail' => 'THIS IS THE BUG. The gateway took the money and our order is still pending, so '
                              . 'nothing has looked at it since: no votes, no refund, no receipt. Repairing it '
                              . 'confirms the order and puts it back on the normal path.'];
        }
        if ($status === 'pending' && $gateway !== null && !$paid) {
            return ['found' => true, 'ours' => $ours, 'gateway' => $gateway, 'verdict' => 'abandoned',
                    'detail' => 'The buyer started a checkout and the gateway has no successful payment. They '
                              . 'were not charged; nothing is owed.'];
        }
        if ($status === 'pending') {
            return ['found' => true, 'ours' => $ours, 'gateway' => null, 'verdict' => 'unverifiable',
                    'detail' => 'Our order is pending and the gateway could not be reached or does not recognise '
                              . 'the reference. If no provider is configured below, that is the reason — and it '
                              . 'is also why the order was never confirmed in the first place.'];
        }
        if ($status === 'confirmed' && (int) $ours->votes_used > 0) {
            return ['found' => true, 'ours' => $ours, 'gateway' => $gateway, 'verdict' => 'delivered',
                    'detail' => 'Confirmed and the votes are on the tally. If the buyer says otherwise, the '
                              . 'question is what they are looking at, not whether it happened.'];
        }
        if ($status === 'confirmed' && (($ours->refunded_at ?? null) !== null || ($ours->refund_requested_at ?? null) !== null)) {
            return ['found' => true, 'ours' => $ours, 'gateway' => $gateway, 'verdict' => 'refunded',
                    'detail' => 'Confirmed, no votes were minted, and the money has been sent back or is on its way.'];
        }
        if ($status === 'confirmed') {
            return ['found' => true, 'ours' => $ours, 'gateway' => $gateway, 'verdict' => 'refund_owed',
                    'detail' => 'Confirmed but nothing was minted and nothing has been refunded. The automatic '
                              . 'sweep can see this one — if it has not acted, check that refunds are switched '
                              . 'on and that maintenance is actually running.'];
        }
        return ['found' => true, 'ours' => $ours, 'gateway' => $gateway, 'verdict' => (string) $status,
                'detail' => 'Order status is "' . $status . '".'];
    }

    /**
     * Ask the gateway which stuck orders were really charged.
     *
     * Our own database is precisely the thing that cannot answer this: it says
     * pending BECAUSE we never found out.
     *
     * @param array<int,object> $stuck
     * @return array{charged: array<int,array{order:object, provider:string, amount:int}>, clean:int}
     */
    public function askGateway(array $stuck, int $limit = 200): array
    {
        $charged = [];
        $clean = 0;
        $enabled = $this->enabledProviders();
        if (!$enabled) return ['charged' => [], 'clean' => 0];

        $p = $this->payments ?? new PaymentService();

        foreach (array_slice(array_values($stuck), 0, max(1, $limit)) as $o) {
            $stored = strtolower(trim((string) ($o->provider ?? '')));
            $order = $stored !== '' && in_array($stored, $enabled, true)
                ? array_merge([$stored], array_diff($enabled, [$stored]))
                : $enabled;

            $hit = null;
            foreach ($order as $provider) {
                try { $v = $p->verify($provider, (string) $o->payment_ref); } catch (\Throwable) { continue; }
                if (!empty($v['ok']) && (string) ($v['status'] ?? '') === 'success') {
                    $hit = ['order' => $o, 'provider' => $provider, 'amount' => (int) ($v['amount'] ?? 0)];
                    break;
                }
            }
            $hit === null ? $clean++ : $charged[] = $hit;
        }
        return ['charged' => $charged, 'clean' => $clean];
    }

    /**
     * Confirm the ones the gateway says were paid, then run the normal delivery.
     *
     * Nothing is decided quietly here: each repaired order becomes either votes or,
     * failing that, an order the refund sweep can finally see. The one thing that
     * must not continue is the third state, where it is neither.
     *
     * @param array<int,array{order:object, provider:string, amount:int}> $charged
     * @return array{fixed:int, errors:array<int,string>}
     */
    public function repair(array $charged): array
    {
        $fixed = 0;
        $errors = [];

        foreach ($charged as $c) {
            $o = $c['order'];
            try {
                $changed = DB::table('gates_donations')->where('id', $o->id)->where('status', 'pending')
                    ->update(OptionalColumn::filter('gates_donations', [
                        'status'       => 'confirmed',
                        'provider'     => $c['provider'],
                        'confirmed_at' => date('Y-m-d H:i:s'),
                    ], ['confirmed_at', 'provider']));
                if ($changed === 0) continue;   // somebody else got there first
                $fixed++;

                // Exactly what the live callback and webhook do. Both idempotent; a
                // mint that refuses leaves votes_used = 0, which the refund sweep
                // can now finally see because the row is confirmed.
                PaidVoteService::mint((int) $o->id);
                CheckoutMailer::receipt((int) $o->id);
            } catch (\Throwable $e) {
                $errors[] = (string) $o->payment_ref . ': ' . $e->getMessage();
            }
        }
        return ['fixed' => $fixed, 'errors' => $errors];
    }
}
