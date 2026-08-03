<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Ask the gateway what really happened, and make the database agree.
 *
 * ── WHAT RECONCILIATION IS FOR HERE ──────────────────────────────────────────
 *
 * A checkout is only recorded as paid when the buyer's browser comes back to the
 * callback URL. That last hop is the least reliable part of the whole flow: a phone
 * loses signal on a Lagos bus, the tab is closed on the gateway's "success" screen, a
 * bank transfer settles ten minutes after the buyer has given up waiting. The money
 * moved; the row still says `pending`.
 *
 * From the row alone an abandoned cart and a completed-but-uncredited payment are
 * indistinguishable, and guessing in either direction is wrong — assume paid and the
 * platform credits votes nobody bought, assume abandoned and a supporter is out of
 * pocket with nothing to show. So neither is assumed: the GATEWAY is asked, because it
 * is the only party that actually knows.
 *
 * ── WHY THIS IS A SERVICE AND NOT A COMMAND ──────────────────────────────────
 *
 * This logic used to live entirely inside {@see \AfricaGates\Console\Commands\PaymentReconcileCommand},
 * which meant it could only be run by someone with SSH. This platform deploys to
 * shared cPanel hosting where that is often nobody — the same constraint that produced
 * `/__setup/migrate` and `/__setup/checkout`. An admin watching money sit in a
 * "pending" column could see the problem on the Finance page and had no way to act on
 * it.
 *
 * It is a service so the console command and the admin screen run THE SAME CODE. Two
 * implementations of "decide whether this payment really happened" is the last place
 * in this codebase that should be allowed to drift.
 *
 * ── CHECK, THEN APPLY ────────────────────────────────────────────────────────
 *
 * {@see run()} takes `$apply`. False asks the gateway and reports; true also writes.
 * The read-only pass is the default on the web because an operator should see "these
 * four are genuinely paid, this one disagrees on amount" BEFORE anything moves — and
 * because the same button that fixes four orders would otherwise be the button that
 * confirms a mismatched one nobody looked at.
 *
 * ── THE RULES, AND WHY EACH ONE REFUSES ──────────────────────────────────────
 *
 *   • amount mismatch → NEVER auto-confirmed. A verified amount below what was
 *     ordered is either a partial payment or a tampered reference, and both need a
 *     person. Reported as `mismatch` and left alone.
 *   • gateway still pending → left alone. It may yet complete.
 *   • verify failed (network, unknown reference) → left alone and retried next run.
 *     A transient API failure must never be read as "did not pay".
 *   • gateway says failed → the row is marked failed, which is safe and stops it
 *     appearing in the queue forever.
 *   • gateway says success and amounts agree → confirmed, and for a paid-vote order
 *     the votes are MINTED and the receipt sent. Both are idempotent.
 *
 * Every write is guarded by `where(status, 'pending')`, so two concurrent runs — a
 * cron and an admin pressing the button — cannot both fulfil the same order.
 *
 * ── THE QUEUE HAS TO STAY SHORT, OR IT STOPS WORKING ─────────────────────────
 *
 * A pending row leaves this queue by being confirmed or failed. An ABANDONED
 * checkout is neither — Paystack reports it `abandoned`, which verify() maps to
 * `pending`, so it was asked about again on every sweep forever. Most checkouts on
 * a voting night are abandoned, so the backlog only grew.
 *
 * That was not just wasted API calls. The sweep read `ORDER BY id LIMIT 200` —
 * OLDEST FIRST — so once the abandoned backlog passed the limit, every row beyond
 * it was permanently out of reach, including the payments that genuinely
 * succeeded. The safety net silently stopped catching anything, and it stopped at
 * the busy end first: the more money came in, the less of it was reconciled.
 *
 * Two changes, and both are needed:
 *   NEWEST FIRST     a payment made in the last hour is the one somebody is
 *                    waiting on. It is now always in the first page.
 *   AN AGE CEILING   a checkout still pending after {@see EXPIRE_AFTER_DAYS} is
 *                    asked once more and then marked failed with `expired_at`
 *                    stamped, so it leaves the queue instead of crowding it.
 */
final class PaymentReconciler
{
    /** Outcomes, in the order an operator cares about them. */
    public const ACTIONS = ['confirmed', 'mismatch', 'failed', 'unverifiable', 'pending', 'expired'];

    /**
     * A checkout still pending after this long is treated as abandoned.
     *
     * Three days, not three hours. A Nigerian bank transfer can settle the next
     * morning, and a gateway's own reversal window is longer than its checkout
     * window — so the ceiling has to sit well past any honest late settlement. The
     * row is still VERIFIED one last time before it is expired, so a payment that
     * did arrive on day three is confirmed rather than written off.
     *
     * It also must not undercut {@see CheckoutMailer::WINDOW_HOURS}: that sweep
     * finds abandoned carts by `status = 'pending'`, so expiring a row before the
     * mailer has finished with it would silently stop the "you left this behind"
     * email. Two constants, one queue — {@see expiryCutoff()} takes the later of
     * them so lowering this one can never quietly delete a feature.
     */
    private const EXPIRE_AFTER_DAYS = 3;

    /** The moment before which a still-pending checkout is treated as dead. */
    private static function expiryCutoff(): Carbon
    {
        $mine    = Carbon::now()->subDays(self::EXPIRE_AFTER_DAYS);
        $mailers = Carbon::now()->subHours(CheckoutMailer::WINDOW_HOURS);
        return $mine->lt($mailers) ? $mine : $mailers;
    }

    public function __construct(
        private readonly PaymentService $payments = new PaymentService(),
        private readonly ?\AfricaGates\Services\OtpService $mailer = null,
    ) {}

    /**
     * Re-verify stale pending rows.
     *
     * @param bool $apply false = ask and report only; true = also write.
     * @param int  $minutes only rows older than this, so a live callback is not raced.
     * @param int  $limit   per table, per run.
     *
     * @return array{
     *   applied:bool, checked:int, confirmed:int, failed:int, mismatch:int,
     *   unverifiable:int, pending:int, expired:int, naira:int,
     *   items:list<array{kind:string,ref:string,who:string,ours:string,gateway:string,
     *                    amount_ours:int,amount_gateway:int,action:string,note:string}>
     * }
     */
    public function run(bool $apply = false, int $minutes = 15, int $limit = 200): array
    {
        $cutoff = Carbon::now()->subMinutes(max(0, $minutes))->toDateTimeString();
        $limit  = max(1, min(1000, $limit));

        $items = array_merge(
            $this->orders($cutoff, $limit, $apply),
            $this->donations($cutoff, $limit, $apply),
        );

        $tally = array_fill_keys(self::ACTIONS, 0);
        $naira = 0;
        foreach ($items as $i) {
            $tally[$i['action']] = ($tally[$i['action']] ?? 0) + 1;
            if ($i['action'] === 'confirmed') $naira += $i['amount_ours'];
        }

        return [
            'applied'      => $apply,
            'checked'      => count($items),
            'confirmed'    => $tally['confirmed'],
            'failed'       => $tally['failed'],
            'mismatch'     => $tally['mismatch'],
            'unverifiable' => $tally['unverifiable'],
            'pending'      => $tally['pending'],
            'expired'      => $tally['expired'],
            'naira'        => $naira,
            'items'        => $items,
        ];
    }

    /** Shop orders. `gates_orders` stores its provider, so verify against that one. */
    private function orders(string $cutoff, int $limit, bool $apply): array
    {
        $out = [];
        try {
            // Newest first, for the same reason as donations(): the abandoned
            // basket from last month must never push today's paid order off the page.
            $rows = DB::table('gates_orders')->where('status', 'pending')
                ->where('created_at', '<', $cutoff)->orderByDesc('id')->limit($limit)->get();
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as $o) {
            $row = [
                'kind' => 'order', 'ref' => (string) $o->reference, 'who' => (string) $o->name,
                'ours' => 'pending', 'gateway' => '—',
                'amount_ours' => (int) $o->subtotal_naira, 'amount_gateway' => 0,
                'action' => 'unverifiable', 'note' => '',
            ];

            $provider = strtolower((string) ($o->provider ?? ''));
            if (!$this->payments->isKnownProvider($provider) || !$this->payments->isEnabled($provider)) {
                // Can't verify: the gateway that took this money is no longer configured.
                // A human has to decide, so it is surfaced rather than silently skipped.
                $row['note'] = $provider === '' ? 'no provider recorded' : "provider '{$provider}' not configured";
                $out[] = $row;
                continue;
            }

            $v = $this->payments->verify($provider, (string) $o->reference);
            if (!$v['ok']) {
                $row['note'] = 'gateway did not answer: ' . (string) ($v['message'] ?? 'unknown');
                $out[] = $row;
                continue;
            }

            $row['gateway']        = (string) ($v['status'] ?? 'pending');
            $row['amount_gateway'] = (int) ($v['amount'] ?? 0);

            if ($row['gateway'] === 'failed') {
                $row['action'] = 'failed';
                $row['note']   = 'gateway reports the payment failed';
                if ($apply) {
                    DB::table('gates_orders')->where('reference', $o->reference)
                        ->where('status', 'pending')->update(['status' => 'failed']);
                }
                $out[] = $row;
                continue;
            }
            if ($row['gateway'] !== 'success') {
                $row['action'] = 'pending';
                $row['note']   = 'still open at the gateway';
                $out[] = $row;
                continue;
            }
            // Amount parity is load-bearing: never confirm an order for less than its
            // subtotal. A short payment is a person's problem, not a cron's. Paying
            // MORE than the subtotal is not a short payment and is not held back —
            // that only ever left a paying customer with nothing.
            if ($row['amount_gateway'] < $row['amount_ours'] || !$this->currencyAgrees($v)) {
                $row['action'] = 'mismatch';
                $row['note']   = $this->currencyAgrees($v)
                    ? 'paid ₦' . number_format($row['amount_gateway'])
                      . ' against ₦' . number_format($row['amount_ours']) . ' ordered'
                    : 'paid in ' . ((string) ($v['currency'] ?? '?')) . ', not NGN';
                $out[] = $row;
                continue;
            }

            $row['action'] = 'confirmed';
            $row['note']   = $apply ? 'confirmed and fulfilled' : 'would be confirmed';
            if ($row['amount_gateway'] > $row['amount_ours']) {
                $row['note'] .= ' (overpaid by ₦'
                    . number_format($row['amount_gateway'] - $row['amount_ours']) . ')';
            }
            if ($apply) {
                // Idempotent: only the single winning pending→paid writer fulfils.
                $changed = DB::table('gates_orders')->where('reference', $o->reference)
                    ->where('status', 'pending')
                    ->update(['status' => 'paid', 'paid_at' => Carbon::now()->toDateTimeString(),
                              'provider_ref' => (string) $o->reference]);
                if ($changed > 0) {
                    $this->fulfilOrder($o);
                } else {
                    $row['note'] = 'already confirmed by another run';
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Donations, vote packs and tickets.
     *
     * `gates_donations` carries no provider, so every enabled gateway is asked — only
     * the one that issued the reference recognises it.
     */
    /**
     * Fix ONE payment, on demand, for the person who made it.
     *
     * ── WHY THIS EXISTS SEPARATELY FROM run() ────────────────────────────────
     *
     * `run()` is a sweep over pending orders older than N minutes, and it only
     * helps if something schedules it. On shared cPanel hosting cron is a checkbox
     * somebody has to have ticked, and the failure it covers — a webhook that
     * never arrives — is silent at both ends. So the gap between "I paid" and
     * "someone runs the sweep" is unbounded, and for its whole length the buyer
     * has votes they paid for and no way to get them.
     *
     * That gap is the reported incident. Paying inside a wallet app (OPay, bank
     * apps) very often means the browser never returns to us, so the callback
     * never fires and the WEBHOOK is the only crediting path left. If the webhook
     * URL or signing secret is wrong in the gateway dashboard, every such payment
     * stalls silently — which is exactly what "my votes are not reflecting" looks
     * like from outside, and why it hit more than one buyer at once.
     *
     * Nothing here trusts the user. The GATEWAY is re-queried and its answer is the
     * only thing that can confirm anything, exactly as in the sweep. The reference
     * is scoped to the caller's own email so nobody can poke at another's payment.
     *
     * @param string|null $email Payment must belong to this address. Null = staff.
     * @return array{ok:bool, code:string, message:string, minted?:int, status?:string}
     */
    public function reclaim(string $reference, ?string $email = null): array
    {
        $ref = trim($reference);
        if ($ref === '' || mb_strlen($ref) > 120) {
            return ['ok' => false, 'code' => 'BAD_REF', 'message' => 'That does not look like a payment reference.'];
        }

        try {
            $q = DB::table('gates_donations')->where('payment_ref', $ref);
            if ($email !== null && trim($email) !== '') {
                $q->whereRaw('LOWER(donor_email) = ?', [mb_strtolower(trim($email))]);
            }
            $d = $q->first();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'UNAVAILABLE', 'message' => 'I could not look that up just now.'];
        }

        if (!$d) {
            // The SAME answer for "no such reference" and "not yours". Splitting
            // them would make this an oracle for whether a reference exists.
            return ['ok' => false, 'code' => 'NOT_FOUND',
                    'message' => 'No payment with that reference is on this account. '
                               . 'It may have been made with a different email address.'];
        }

        if ((string) ($d->status ?? '') === 'refunded' || ($d->refunded_at ?? null) !== null) {
            return ['ok' => false, 'code' => 'REFUNDED', 'status' => 'refunded',
                    'message' => 'That payment was refunded, so its votes were removed.'];
        }

        if ((string) ($d->status ?? '') === 'confirmed') {
            // Confirmed but never minted IS the "paid, no votes" case, and it is
            // repairable — mint() is idempotent, so retrying is always safe.
            if ((string) ($d->tier ?? '') === 'paid-vote' && (int) ($d->votes_used ?? 0) === 0) {
                $note = $this->afterConfirm($d);
                $used = (int) (DB::table('gates_donations')->where('id', $d->id)->value('votes_used') ?? 0);
                return $used > 0
                    ? ['ok' => true, 'code' => 'MINTED', 'minted' => $used, 'status' => 'confirmed',
                       'message' => 'Found it — your payment was confirmed and ' . $used . ' vote(s) have now been added.']
                    : ['ok' => false, 'code' => 'MINT_REFUSED', 'status' => 'confirmed',
                       'message' => 'Your payment is confirmed but the votes could not be added: ' . $note
                                  . '. This order is refundable — the team has been notified.'];
            }
            return ['ok' => true, 'code' => 'ALREADY', 'status' => 'confirmed',
                    'minted' => (int) ($d->votes_used ?? 0),
                    'message' => 'That payment is already confirmed and its votes were added.'];
        }

        // Not confirmed on our side — pending, or written off as failed/expired.
        // Ask the gateway that took it; only when we did not record one is every
        // enabled gateway tried in turn.
        foreach ($this->providersFor($d, $this->payments->enabledProviderIds()) as $provider) {
            $v = $this->payments->verify($provider, $ref);
            if (!($v['ok'] ?? false) || ($v['status'] ?? '') !== 'success') continue;

            // The same amount check the live path makes. A gateway saying "paid"
            // for LESS than the order is not authorisation to credit it.
            if ((int) ($v['amount'] ?? 0) < (int) $d->amount_naira || !$this->currencyAgrees($v)) {
                return ['ok' => false, 'code' => 'MISMATCH', 'status' => 'pending',
                        'message' => 'The gateway shows a different amount for that reference, so I have not '
                                   . 'credited anything. The team will look at it.'];
            }

            // 'failed' is included deliberately. A checkout the sweep expired after
            // three days, or one a transient gateway error marked failed, is exactly
            // the row a buyer comes to support holding a bank debit alert for — and
            // the gateway has just said it was paid. Still a conditional UPDATE, so
            // only one writer wins and a confirmed row is never touched.
            $changed = DB::table('gates_donations')->where('payment_ref', $ref)
                ->whereIn('status', ['pending', 'failed'])->update($this->confirmPatch());
            if ($changed === 0) {
                return ['ok' => true, 'code' => 'ALREADY', 'status' => 'confirmed',
                        'message' => 'That payment was confirmed a moment ago — your votes are on their way.'];
            }

            $this->afterConfirm($d);
            $used = (int) (DB::table('gates_donations')->where('id', $d->id)->value('votes_used') ?? 0);

            return ['ok' => true, 'code' => 'CONFIRMED', 'status' => 'confirmed', 'minted' => $used,
                    'message' => $used > 0
                        ? 'Found it. Your payment went through but our record had not caught up — '
                        . $used . ' vote(s) have now been added and your receipt is on its way.'
                        : 'Your payment is now confirmed and your receipt is on its way.'];
        }

        return ['ok' => false, 'code' => 'NOT_PAID', 'status' => 'pending',
                'message' => 'The gateway does not show that payment as successful yet. If your bank has '
                           . 'debited you it can take a few minutes — try again shortly, and if it '
                           . 'persists the team will chase it.'];
    }

    private function donations(string $cutoff, int $limit, bool $apply): array
    {
        $providers = $this->payments->enabledProviderIds();
        if (!$providers) return [];

        // NEWEST FIRST. The row somebody is refreshing their inbox over was made in
        // the last few minutes; the row from six weeks ago is an abandoned cart. The
        // old ascending order meant the abandoned carts were reconciled first and,
        // once there were more of them than the limit, they were reconciled ONLY.
        try {
            $rows = DB::table('gates_donations')->where('status', 'pending')
                ->where('created_at', '<', $cutoff)->orderByDesc('id')->limit($limit)->get();
        } catch (\Throwable) {
            return [];
        }

        $deadline = self::expiryCutoff()->toDateTimeString();

        $out = [];
        foreach ($rows as $d) {
            $row = [
                'kind' => $this->kindOf((string) ($d->tier ?? '')),
                'ref'  => (string) $d->payment_ref,
                'who'  => (string) ($d->donor_name ?: 'Supporter'),
                'ours' => 'pending', 'gateway' => '—',
                'amount_ours' => (int) $d->amount_naira, 'amount_gateway' => 0,
                'action' => 'unverifiable', 'note' => 'no gateway recognised this reference',
            ];

            $stale = (string) ($d->created_at ?? '') !== '' && (string) $d->created_at < $deadline;

            // The recorded gateway, when we have it. Only when we do not — orders
            // taken before the column existed — is every gateway asked in turn.
            // That loop is what made a 200-row sweep 400 HTTPS calls long.
            $ask = $this->providersFor($d, $providers);

            foreach ($ask as $provider) {
                $v = $this->payments->verify($provider, (string) $d->payment_ref);
                if (!$v['ok'] || ($v['status'] ?? '') !== 'success') {
                    continue;   // not this gateway, or not paid yet — try the next
                }

                $row['gateway']        = 'success';
                $row['amount_gateway'] = (int) ($v['amount'] ?? 0);
                $stale = false;      // it PAID. Age is no longer the interesting fact.

                // Short of the price is never confirmed by a cron. Over it is: the
                // buyer has paid at least what was asked and refusing them leaves
                // money with us and nothing delivered. Same rule as the live path.
                if ($row['amount_gateway'] < $row['amount_ours']) {
                    $row['action'] = 'mismatch';
                    $row['note']   = 'paid ₦' . number_format($row['amount_gateway'])
                                   . ' against ₦' . number_format($row['amount_ours']) . ' expected';
                    break;
                }
                if (!$this->currencyAgrees($v)) {
                    $row['action'] = 'mismatch';
                    $row['note']   = 'paid in ' . ((string) ($v['currency'] ?? '?')) . ', not NGN';
                    break;
                }

                $row['action'] = 'confirmed';
                $row['note']   = $apply ? 'confirmed' : 'would be confirmed';
                if ($row['amount_gateway'] > $row['amount_ours']) {
                    $row['note'] .= ' (overpaid by ₦'
                        . number_format($row['amount_gateway'] - $row['amount_ours']) . ')';
                }
                if ($apply) {
                    $changed = DB::table('gates_donations')->where('payment_ref', $d->payment_ref)
                        ->where('status', 'pending')->update($this->confirmPatch());
                    if ($changed > 0) {
                        $row['note'] = $this->afterConfirm($d);
                    } else {
                        $row['note'] = 'already confirmed by another run';
                    }
                }
                break;   // a provider recognised this reference — done with this row
            }

            // Asked, and still nobody says it was paid, and it is older than the
            // ceiling. Tombstone it so the queue can move on. Note the ORDER: this
            // runs after the gateways have had their say, so a genuinely late
            // settlement on day three is confirmed rather than written off.
            if ($stale && $row['action'] === 'unverifiable') {
                $row['action'] = 'expired';
                $row['note']   = 'abandoned — no gateway has seen it since '
                               . self::expiryCutoff()->format('Y-m-d H:i');
                if ($apply) $this->expire($d);
            }

            $out[] = $row;
        }
        return $out;
    }

    /**
     * Which gateway to ask about this row, best first.
     *
     * The stored provider is authoritative and makes this one call. It is not
     * treated as the ONLY answer, though: a row whose recorded gateway has since
     * been switched off in the environment would otherwise become permanently
     * unverifiable, which is the abandoned-queue problem all over again.
     *
     * @param list<string> $enabled
     * @return list<string>
     */
    private function providersFor(object $d, array $enabled): array
    {
        $stored = strtolower(trim((string) ($d->provider ?? '')));
        if ($stored === '' || !in_array($stored, $enabled, true)) return $enabled;
        return array_merge([$stored], array_values(array_diff($enabled, [$stored])));
    }

    /**
     * The pending→confirmed patch, identical to the one the live checkout paths
     * write. `confirmed_at` is the moment money arrived — the refund grace window
     * documents itself as measuring exactly that and, before this column, had only
     * "when checkout started" to measure instead.
     *
     * A reconciled order is the case where the difference is largest: the buyer
     * paid hours or days before the sweep noticed, so its `created_at` says almost
     * nothing about when the money landed.
     */
    private function confirmPatch(): array
    {
        return OptionalColumn::filter('gates_donations', [
            'status'       => 'confirmed',
            'confirmed_at' => Carbon::now()->toDateTimeString(),
        ], ['confirmed_at']);
    }

    /**
     * Everything is priced and charged in naira. A gateway reporting success in
     * another currency has not paid this order, whatever the number says — ₦5,000
     * and $5,000 are the same integer and three orders of magnitude apart.
     */
    private function currencyAgrees(array $v): bool
    {
        $c = strtoupper(trim((string) ($v['currency'] ?? '')));
        return $c === '' || $c === 'NGN';   // '' = a gateway that does not report one
    }

    /**
     * Mark an abandoned checkout dead so it stops crowding the queue.
     *
     * `status = failed` because that is the vocabulary the finance pages, the
     * abandoned-cart mailer and every other reader already understand. `expired_at`
     * alongside it records that TIME decided this, not a gateway — so months later
     * "we gave up after three days" is still distinguishable from "the bank said no",
     * and a support conversation about an old reference gets the honest answer.
     */
    private function expire(object $d): void
    {
        try {
            DB::table('gates_donations')
                ->where('payment_ref', $d->payment_ref)
                ->where('status', 'pending')
                ->update(OptionalColumn::filter('gates_donations', [
                    'status'     => 'failed',
                    'expired_at' => Carbon::now()->toDateTimeString(),
                ], ['expired_at']));
        } catch (\Throwable $e) {
            error_log('[reconcile] could not expire ' . $d->payment_ref . ': ' . $e->getMessage());
        }
    }

    /**
     * A confirmed paid-vote order MUST still mint and still receipt.
     *
     * This is the backstop that exists FOR dropped callbacks, so it is the one place
     * that must not stop at flipping a status: an order left 'confirmed' with
     * votes_used = 0 is money taken with no votes on the nominee, and it is
     * indistinguishable from the deliberate "voting closed before the payment
     * confirmed" refusal the same column encodes.
     *
     * Both calls are idempotent and neither is allowed to throw — a receipt failure
     * must never undo a confirmation.
     */
    private function afterConfirm(object $d): string
    {
        $note = 'confirmed';
        if ((string) ($d->tier ?? '') === 'paid-vote' && !empty($d->intent_nominee_id)) {
            try {
                $m = PaidVoteService::mint((int) $d->id);
                $note = !empty($m['ok'])
                    ? 'confirmed, ' . (int) ($m['minted'] ?? 0) . ' vote(s) minted'
                    : 'confirmed but votes NOT minted (' . (string) ($m['message'] ?? 'unknown') . ') — refundable';
            } catch (\Throwable $e) {
                $note = 'confirmed but mint failed: ' . $e->getMessage();
            }
        }
        try { CheckoutMailer::receipt((int) $d->id); } catch (\Throwable) {}
        return $note;
    }

    /** Label a donation row by what it actually was, for the operator's table. */
    private function kindOf(string $tier): string
    {
        return match (\AfricaGates\Admin\Services\FinanceService::sourceForTier($tier)) {
            'paid-vote' => 'paid vote',
            'shop'      => 'shop',
            'event'     => 'ticket',
            'donation'  => 'donation',
            default     => 'payment',
        };
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
            if ($slug === '' || $qty < 1) continue;
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
        Notifier::adminAlert($this->mailer, 'Shop order reconciled to paid',
            "Order {$order->reference} was confirmed by reconciliation after a missed callback.\n"
            . "By:    {$order->name} <{$order->email}>\nTotal: {$total}");
    }

    /**
     * Record the run. Reconciliation without a trail is not reconciliation — the
     * question "who confirmed this order, when, and what did the gateway say" has to
     * be answerable months later, by someone who was not there.
     *
     * Never throws: a logging failure must not undo money that has just been
     * correctly credited.
     */
    public static function log(array $result, string $actor): void
    {
        try {
            DB::table('gates_reconciliation_runs')->insert(OptionalColumn::filter('gates_reconciliation_runs', [
                'ran_at'       => Carbon::now()->toDateTimeString(),
                'actor'        => mb_substr($actor, 0, 120),
                'mode'         => $result['applied'] ? 'apply' : 'check',
                'checked'      => (int) $result['checked'],
                'confirmed'    => (int) $result['confirmed'],
                'failed'       => (int) $result['failed'],
                'mismatch'     => (int) $result['mismatch'],
                'unverifiable' => (int) $result['unverifiable'],
                'naira'        => (int) $result['naira'],
                // Capped: a 200-row run would otherwise put a very large blob in a
                // table that is read on every page load of the Finance tab.
                'detail_json'  => json_encode(array_slice($result['items'], 0, 60)),
            ], ['detail_json']));
        } catch (\Throwable) {}
    }

    /** @return list<object> the most recent runs, newest first. */
    public static function history(int $limit = 20): array
    {
        try {
            return DB::table('gates_reconciliation_runs')->orderByDesc('id')
                ->limit(max(1, min(100, $limit)))->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
