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
        // An operator on the phone to a buyer is being read a number off the buyer's screen,
        // and that screen is Paystack's receipt, not ours. Resolve first so the reference
        // this whole comparison hangs off is the one our row is keyed by.
        $reference = PaymentLookup::canonical(trim($reference), $this->payments);
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
                    'detail' => 'Neither we nor the gateway recognise this reference. The gateway\'s own '
                              . 'transaction id works here as well as ours, so check the characters — or '
                              . 'search by the buyer\'s email address instead.'];
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
     * Does the gateway agree that this order was paid, and for the right amount?
     *
     * ── WHY MANUAL MINTING MUST ASK, AND THE LIVE PATH MUST NOT ──────────────
     *
     * The live callback and webhook verify server-to-server BEFORE they set
     * `confirmed`, so by the time mint() runs the check has already happened and
     * repeating it would add a network round trip to the buyer's own request —
     * plus a new way to fail them, since a gateway blip would then block votes for
     * a payment that genuinely landed.
     *
     * Every OTHER way votes get minted acts on the stored flag instead, minutes or
     * weeks later: `votes:remint`, an operator repairing a batch, anything that
     * sweeps "confirmed with votes_used = 0". Those paths trust a column, and a
     * column can be wrong — set by a bad reconciler run, a hand-edit, a restore, or
     * a repair that verified against the wrong provider. Minting votes for money
     * that was never actually taken is the mirror image of the bug we have been
     * chasing, and it is worse, because it silently inflates a result rather than
     * failing loudly.
     *
     * So: the live path verifies once, at the right moment. Manual minting verifies
     * again, at its own moment, because its evidence is older than it is.
     *
     * @return array{ok:bool, reason:string, provider:?string, amount:int}
     */
    public function gatewayAgrees(object $order): array
    {
        $ref = trim((string) ($order->payment_ref ?? ''));
        if ($ref === '') return ['ok' => false, 'reason' => 'the order carries no payment reference', 'provider' => null, 'amount' => 0];

        $enabled = $this->enabledProviders();
        if (!$enabled) {
            return ['ok' => false, 'reason' => 'no payment gateway is configured, so nothing can be confirmed against it',
                    'provider' => null, 'amount' => 0];
        }

        $p = $this->payments ?? new PaymentService();
        $stored = strtolower(trim((string) ($order->provider ?? '')));
        $try = $stored !== '' && in_array($stored, $enabled, true)
            ? array_merge([$stored], array_diff($enabled, [$stored]))
            : $enabled;

        foreach ($try as $provider) {
            try { $v = $p->verify($provider, $ref); } catch (\Throwable) { continue; }
            if (empty($v['ok'])) continue;
            if ((string) ($v['status'] ?? '') !== 'success') {
                return ['ok' => false, 'reason' => 'the gateway reports this payment as "' . (string) ($v['status'] ?? '?') . '"',
                        'provider' => $provider, 'amount' => (int) ($v['amount'] ?? 0)];
            }
            // Underpayment is refused for the same reason the live confirm refuses
            // it: a gateway reporting success for less than we asked has not paid
            // THIS order. Overpayment is allowed through, as it is there.
            $paid = (int) ($v['amount'] ?? 0);
            if ($paid < (int) $order->amount_naira) {
                return ['ok' => false, 'provider' => $provider, 'amount' => $paid,
                        'reason' => 'the gateway shows ₦' . number_format($paid) . ' against an order for ₦'
                                  . number_format((int) $order->amount_naira)];
            }
            return ['ok' => true, 'reason' => '', 'provider' => $provider, 'amount' => $paid];
        }
        return ['ok' => false, 'reason' => 'no gateway recognises this reference', 'provider' => null, 'amount' => 0];
    }

    /**
     * Ask the gateway which stuck orders were really charged.
     *
     * Our own database is precisely the thing that cannot answer this: it says
     * pending BECAUSE we never found out.
     *
     * ── UNDERPAYMENT IS ITS OWN ANSWER, NOT A YES ────────────────────────────
     *
     * This asked only whether the gateway said "success" and treated that as
     * charged. {@see gatewayAgrees()}, which guards the OTHER route to the same
     * outcome, additionally refuses an amount below what the order asked for — and
     * its docblock explains exactly why: minting votes for money that was never
     * taken silently inflates a result instead of failing loudly.
     *
     * Both routes end in `mint()`, and mint issues `bonus_votes` off our own row
     * without ever looking at what was actually paid. So a success for a fraction
     * of the order confirmed here delivered the FULL quantity — the guarded path
     * refused it and this one waved it through.
     *
     * They are now reported separately rather than merged into `charged` or
     * silently counted `clean`. An underpaid order is a real thing that happened to
     * a real person and needs a human, not a bucket it disappears into.
     *
     * @param array<int,object> $stuck
     * @return array{charged: array<int,array{order:object, provider:string, amount:int}>,
     *               underpaid: array<int,array{order:object, provider:string, amount:int}>,
     *               clean:int}
     */
    public function askGateway(array $stuck, int $limit = 200): array
    {
        $charged = [];
        $underpaid = [];
        $clean = 0;
        $enabled = $this->enabledProviders();
        if (!$enabled) return ['charged' => [], 'underpaid' => [], 'clean' => 0];

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

            if ($hit === null)                                    { $clean++; continue; }
            if ($hit['amount'] < (int) $o->amount_naira)          { $underpaid[] = $hit; continue; }
            $charged[] = $hit;
        }
        return ['charged' => $charged, 'underpaid' => $underpaid, 'clean' => $clean];
    }

    /**
     * Deliver votes that are owed — but only where the gateway still agrees.
     *
     * The "confirmed, no votes" bucket is money we hold for votes we did not give.
     * Some of those can still be minted (the mint refused for a reason that has
     * since gone away); the rest belong to the refund sweep. Either way this asks
     * Paystack first, because the confirmed flag is the only evidence this path
     * has and it is older than the decision being made on it.
     *
     * @param array<int,object> $owed
     * @return array{minted:int, votes:int, refused:array<int,string>}
     */
    public function deliverOwed(array $owed, int $limit = 100): array
    {
        $minted = 0; $votes = 0; $refused = [];

        foreach (array_slice(array_values($owed), 0, max(1, $limit)) as $o) {
            $agree = $this->gatewayAgrees($o);
            if (!$agree['ok']) {
                $refused[] = (string) $o->payment_ref . ' — ' . $agree['reason'];
                continue;
            }
            $r = PaidVoteService::mint((int) $o->id);
            if (!empty($r['ok'])) {
                $minted++;
                $votes += (int) ($r['minted'] ?? $o->bonus_votes);
            } else {
                $refused[] = (string) $o->payment_ref . ' — ' . (string) ($r['code'] ?? 'refused');
            }
        }
        return ['minted' => $minted, 'votes' => $votes, 'refused' => $refused];
    }

    /**
     * Confirm the ones the gateway says were paid, then run the normal delivery.
     *
     * Nothing is decided quietly here: each repaired order becomes either votes or,
     * failing that, an order the refund sweep can finally see. The one thing that
     * must not continue is the third state, where it is neither.
     *
     * ── IT ASKS AGAIN, AT THE MOMENT IT ACTS ─────────────────────────────────
     *
     * The verification that produced this list happened in an earlier request and
     * was carried here through the session. That is right for the DECISION — the
     * operator should repair the list they actually looked at — but it is the wrong
     * evidence to confirm money on. Between the two clicks the operator may have
     * gone for lunch, and in that window a payment can be refunded or reversed at
     * the gateway.
     *
     * So the stash decides WHICH orders, and the gateway decides WHETHER, right
     * now, one call per order. {@see gatewayAgrees()} is the same check the manual
     * mint path uses, which also closes the underpayment hole this method had: it
     * confirmed on "success" alone and mint() then issued the full stored quantity
     * without ever looking at what was paid.
     *
     * A refusal is reported, never swallowed. An order that cannot be confirmed is
     * a person who is still owed an answer.
     *
     * @param array<int,array{order:object, provider:string, amount:int}> $charged
     * @return array{fixed:int, refused:array<int,string>, errors:array<int,string>}
     */
    public function repair(array $charged): array
    {
        $fixed = 0;
        $refused = [];
        $errors = [];

        foreach ($charged as $c) {
            $o = $c['order'];
            try {
                $agree = $this->gatewayAgrees($o);
                if (!$agree['ok']) {
                    $refused[] = (string) $o->payment_ref . ' — ' . $agree['reason'];
                    continue;
                }

                $changed = DB::table('gates_donations')->where('id', $o->id)->where('status', 'pending')
                    ->update(OptionalColumn::filter('gates_donations', [
                        'status'       => 'confirmed',
                        // The provider the gateway just answered on, not the one the
                        // stash remembered — they are the same in every ordinary case
                        // and this one is the one that was actually checked.
                        'provider'     => $agree['provider'] ?? $c['provider'],
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
        return ['fixed' => $fixed, 'refused' => $refused, 'errors' => $errors];
    }
}
