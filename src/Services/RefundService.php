<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Giving money back without being asked.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE CASE THIS AUTOMATES, AND WHY IT IS THE ONLY SAFE ONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "Was a refund earned?" is usually a judgement — did the service disappoint,
 * was the charge authorised, is this person telling the truth. None of that can
 * be decided by software and none of it is decided here.
 *
 * But ONE case is not a judgement at all. It is arithmetic:
 *
 *     the payment CONFIRMED · the order was for votes · `votes_used = 0`
 *     · the cycle those votes were for has CLOSED
 *
 * That is a payment for which the platform delivered nothing and now never can.
 * {@see PaidVoteService::mint()} refuses to push weighted votes into a closed
 * tally — correctly — and leaves the row at `votes_used = 0` precisely so this
 * population is queryable. The money is not ours. There is no version of the
 * facts under which it is ours, no interpretation to get wrong, and nothing a
 * person adds by confirming it a week later except the week.
 *
 * So this refunds it, and refunds nothing else. A duplicate charge, a chargeback,
 * a change of mind, a nominee disqualified after the fact, "I didn't mean to buy
 * that many" — every one of those needs a human, and every one of them is
 * excluded by the rule below rather than by a promise to be careful.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT STOPS THIS PAYING SOMEBODY TWICE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Money out is the one duplicate that cannot be taken back, so the guards are
 * structural rather than procedural:
 *
 *  1. THE CLAIM. `refund_requested_at` is stamped in a conditional UPDATE that
 *     only matches rows where it is still null. Exactly one worker can ever be
 *     mid-refund on a row, and it is stamped BEFORE the gateway is called — so a
 *     crash between the two leaves a row that looks refunded rather than one that
 *     gets refunded again. Erring towards a stuck row is right: a stuck row is
 *     visible in the admin queue and fixable; a double payment is neither.
 *
 *  2. `pending` IS NOT A FAILURE. Both gateways queue refunds and settle later.
 *     A caller that retried on anything other than success would retry a refund
 *     already on its way. The claim is never released on `pending`.
 *
 *  3. AN UNKNOWN OUTCOME KEEPS THE CLAIM. If the gateway cannot be reached we do
 *     not know whether it accepted the request, so the row stays claimed and a
 *     person is told. Only a definite refusal releases it.
 *
 *  4. CEILINGS. Per order and per day, both in cash. A bug that mistakes the
 *     population — a schema change, a cycle wrongly marked closed — stops after
 *     the daily ceiling rather than emptying the account overnight.
 *
 *  5. A GRACE WINDOW. Nothing is refunded within the first hour of confirming. A
 *     mint that is merely LATE (a webhook arriving before the browser callback,
 *     a cycle about to be extended by an admin) is not a mint that failed.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE MODEL CANNOT REACH ANY OF IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no support tool that calls this. The assistant can tell somebody a
 * refund is on its way — it reads the state — and that is all. A language model
 * with a refund button is a language model that can be talked into pressing it.
 */
final class RefundService
{
    /**
     * Do not refund a payment that confirmed within this window. It may still mint.
     *
     * Stays a flat hour. The other race — against a LATE mint of an order placed
     * before the cycle closed — is handled per order in {@see terminallyUnminted()},
     * because it depends on that cycle's close time rather than on the order's age.
     * Widening this constant instead would also have delayed the orders that can
     * never mint, which are the ones somebody is waiting on a refund for.
     *
     * ── AND IT NOW MEASURES WHAT THIS SENTENCE SAYS ──────────────────────────
     *
     * It said "confirmed" and measured `created_at`, which is when the buyer
     * STARTED checkout — the only timestamp the schema had. Those are different
     * questions and they diverge exactly where it matters: an order created at
     * 23:00 and confirmed at 23:59 got one minute of grace, not sixty. There is a
     * `confirmed_at` column now, and {@see graceCutoff()} is compared against it.
     */
    private const GRACE_MINUTES = 60;

    /** Refuse to auto-refund a single order larger than this. Overridable; see {@see maxOrderNaira()}. */
    private const MAX_ORDER_NAIRA = 200_000;

    /** Total the automatic path may return in one day. Overridable; see {@see maxDailyNaira()}. */
    private const MAX_DAILY_NAIRA = 1_000_000;

    /** Hard stops the admin settings cannot exceed. A dial is not a blank cheque. */
    private const CEILING_ORDER_CAP = 2_000_000;
    private const CEILING_DAILY_CAP = 20_000_000;

    /** Orders considered per sweep. */
    private const PER_SWEEP = 20;

    /**
     * How long to wait after each refusal before trying that order again.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     *
     * A definite refusal releases the claim, because it is the one outcome where we
     * know for certain no money moved — so trying again is safe. "Safe to retry"
     * was implemented as "retried on the very next sweep", and maintenance ticks
     * every fourteen minutes. One refused refund therefore became about a hundred
     * gateway calls and a hundred identical admin emails a day, every one of them
     * saying the refund would NOT be retried automatically.
     *
     * Retrying is still right: the commonest refusal on these gateways is an
     * insufficient merchant settlement balance, which clears on its own once the
     * day's takings settle. What was wrong was the pace.
     *
     * An hour, then six, then a day. Four attempts spanning thirty-one hours, and
     * the span is the point rather than the count: Nigerian card settlement is T+1,
     * so a retry schedule that finishes inside a working day would give up before
     * the single most likely cause had a chance to clear.
     *
     * @var list<int> hours to wait after attempt 1, 2, 3… ; running out means stop
     */
    private const RETRY_AFTER_HOURS = [1, 6, 24];

    /** Attempts before the automatic path gives up. Derived, so the two cannot drift. */
    private const MAX_REFUND_ATTEMPTS = 4;   // count(RETRY_AFTER_HOURS) + 1

    public function __construct(
        private readonly ?PaymentService $payments = null,
        private readonly ?OtpService $mailer = null,
    ) {}

    /**
     * Is the automatic path switched on?
     *
     * An admin setting over an env default, and it is checked on every sweep
     * rather than at boot so turning it off during an incident takes effect on
     * the next tick instead of the next deploy.
     */
    public static function autoEnabled(): bool
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'auto_refund_unminted')->value('value');
            if ($v !== null && trim((string) $v) !== '') return (string) $v === '1';
        } catch (\Throwable) {}
        return (string) Env::get('AUTO_REFUND_UNMINTED', '1') === '1';
    }

    /** True once the schema can record a refund safely. Without it, nothing runs. */
    public static function ready(): bool
    {
        return OptionalColumn::on('gates_donations', 'refund_requested_at')
            && OptionalColumn::on('gates_donations', 'refund_state');
    }

    /**
     * The ceilings, as admin settings over the constants above.
     *
     * ── WHY THESE BECAME DIALS ───────────────────────────────────────────────
     *
     * They are the blast radius: if the population rule is ever wrong, they are
     * what stops the account emptying overnight. That argues for them being hard
     * to change, and they were — a deploy. But the moment they actually bind is an
     * incident, at night, when a genuine backlog of owed refunds is being held
     * behind a number somebody chose months earlier, and a deploy is the slowest
     * tool in the building.
     *
     * So: settable, checked on every sweep like {@see autoEnabled()}, and bounded.
     * A ceiling that can be raised without limit is not a ceiling.
     */
    public static function maxOrderNaira(): int
    {
        return self::dial('refund_max_order_naira', self::MAX_ORDER_NAIRA, self::CEILING_ORDER_CAP);
    }

    public static function maxDailyNaira(): int
    {
        return self::dial('refund_max_daily_naira', self::MAX_DAILY_NAIRA, self::CEILING_DAILY_CAP);
    }

    /** One setting, read defensively: an unreadable or silly value falls back to the constant. */
    private static function dial(string $key, int $default, int $cap): int
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            if ($v === null || trim((string) $v) === '') return $default;
            $n = (int) trim((string) $v);
            // A zero or negative ceiling would read as "refund nothing", which is
            // indistinguishable from the feature being broken. Treat it as unset.
            return $n > 0 ? min($n, $cap) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Refund everything that is unambiguously owed. Returns how many were sent.
     *
     * Runs from maintenance, AFTER reconciliation — an order that is about to be
     * confirmed and minted must not be refunded a second before it succeeds.
     */
    public function sweep(int $limit = self::PER_SWEEP): int
    {
        if (!self::autoEnabled() || $this->payments === null) return 0;
        if (!self::ready()) {
            error_log('[refund] schema not migrated — automatic refunds are OFF. Run db:migrate.');
            return 0;
        }

        $spentToday = $this->spentToday();
        $ceiling    = self::maxDailyNaira();
        $done = 0;

        foreach ($this->owed($limit) as $row) {
            if ($spentToday + (int) $row->amount_naira > $ceiling) {
                // Loud, and it stops the sweep rather than skipping to a smaller
                // one. Hitting this ceiling means either a very bad day or a bug,
                // and both want a person looking before any more money moves.
                error_log('[refund] DAILY CEILING reached (₦' . number_format($spentToday)
                    . '). Remaining refunds are waiting for a human.');
                // ONCE. The sweep runs every fourteen minutes, so alerting from
                // inside it sent the same paragraph a hundred times before
                // midnight — which is how a real alert becomes a mail filter.
                if ($this->claimDailyAlert()) {
                    $this->alert('Automatic refunds paused — daily ceiling reached',
                        "The automatic refund path has returned ₦" . number_format($spentToday) . " today and has stopped.\n\n"
                        . "Either this is a genuinely bad day, or something has gone wrong with the rule that decides "
                        . "what is owed. Nothing further will be refunded automatically until tomorrow. Check "
                        . "/admin/finance before raising the ceiling.\n\n"
                        . "Today's ceiling is ₦" . number_format($ceiling) . " (setting: refund_max_daily_naira).");
                }
                break;
            }

            if ($this->refundOne($row)) {
                $spentToday += (int) $row->amount_naira;
                $done++;
            }
        }
        return $done;
    }

    /**
     * The orders the platform owes money on.
     *
     * Every clause is doing work; none is defensive padding:
     *   status/tier/votes_used   the "paid, delivered nothing" signal itself
     *   refunded_at IS NULL      not already given back by hand
     *   refund_requested_at NULL not already claimed by a previous sweep
     *   refund_state NOT manual  not already parked for a person to handle
     *   refund_retry_after       not inside a backoff window after a refusal
     *   created_at              cheap bound so this never walks all of history
     * The cycle-closed test is NOT here, because it needs the nominee's category
     * and is answered per row in {@see terminallyUnminted()}.
     *
     * `created_at` remains the SQL bound even though the grace is really about
     * confirmation, and that is correct rather than lazy: a payment cannot confirm
     * before its checkout started, so `created_at < cutoff` is a strict superset of
     * `confirmed_at < cutoff`. It narrows the scan on an indexed column and the
     * exact test is then made per row, where the fallback for an unmigrated
     * database can live.
     *
     * @return list<object>
     */
    private function owed(int $limit): array
    {
        $cutoff  = self::graceCutoff();
        $now     = date('Y-m-d H:i:s');
        $maxSize = self::maxOrderNaira();

        try {
            $q = DB::table('gates_donations')
                ->where('status', 'confirmed')
                ->where('tier', 'paid-vote')
                ->where('votes_used', 0)
                ->whereNull('refunded_at')
                ->whereNull('refund_requested_at')
                ->where('created_at', '<', $cutoff)
                ->where('amount_naira', '>', 0);

            // Parked for a person, or waiting out a backoff. Both are states this
            // sweep put the row into, so both have to be states it respects.
            $q->where(function ($w) {
                $w->whereNull('refund_state')->orWhereNotIn('refund_state', ['manual', 'exhausted']);
            });
            if (OptionalColumn::on('gates_donations', 'refund_retry_after')) {
                $q->where(function ($w) use ($now) {
                    $w->whereNull('refund_retry_after')->orWhere('refund_retry_after', '<=', $now);
                });
            }

            $rows = $q->orderBy('id')->limit($limit * 3)->get();
        } catch (\Throwable $e) {
            error_log('[refund] could not read the queue: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            if (count($out) >= $limit) break;
            // The grace, on the clock this class has always claimed to use.
            $confirmed = (string) ($r->confirmed_at ?? '') !== '' ? (string) $r->confirmed_at : (string) $r->created_at;
            if ($confirmed >= $cutoff) continue;

            if ((int) $r->amount_naira > $maxSize) {
                // PARKED, not skipped. It used to be written to error_log on every
                // one of the day's hundred sweeps and surfaced nowhere a person
                // looks — so "left for a human" meant left for a human who was
                // never told. Stamping the state takes it out of this queue, puts
                // it on the finance page beside every other refund state, and
                // makes the alert fire exactly once.
                $this->park($r, 'over the per-order ceiling of ₦' . number_format($maxSize));
                continue;
            }
            if ($this->terminallyUnminted($r)) $out[] = $r;
        }
        return $out;
    }

    /** Nothing confirmed after this moment is refundable yet. */
    private static function graceCutoff(): string
    {
        return date('Y-m-d H:i:s', time() - self::GRACE_MINUTES * 60);
    }

    /**
     * Hand one order to a person, once, and stop the sweep reconsidering it.
     *
     * `refund_requested_at` is deliberately NOT stamped: nothing has been asked of
     * any gateway, so the claim must stay free for whoever handles this by hand.
     * The state is what removes it from the queue.
     */
    private function park(object $don, string $why): void
    {
        try {
            $claimed = DB::table('gates_donations')->where('id', $don->id)
                ->where(function ($w) { $w->whereNull('refund_state')->orWhere('refund_state', '!=', 'manual'); })
                ->update(['refund_state' => 'manual', 'refund_reason' => mb_substr($why, 0, 250)]);
            if ($claimed === 0) return;   // already parked; do not alert twice
        } catch (\Throwable $e) {
            error_log('[refund] could not park ' . $don->payment_ref . ': ' . $e->getMessage());
            return;
        }

        error_log('[refund] order ' . $don->payment_ref . ' parked for a human — ' . $why);
        $this->alert("A refund is owed and needs a person — {$don->payment_ref}",
            "₦" . number_format((int) $don->amount_naira) . " was taken for votes that were never counted, "
            . "and the automatic path will not return it: {$why}.\n\n"
            . "Reference: {$don->payment_ref}\n\n"
            . "It is genuinely owed. Refund it in the gateway dashboard, or raise the ceiling deliberately "
            . "(setting: refund_max_order_naira) and let the next sweep take it.");
    }

    /**
     * True the first time today the daily ceiling is hit.
     *
     * A date written into settings, not a counter: the question is "has anybody
     * been told about today", and the answer has to survive a restart and be the
     * same for every worker.
     */
    private function claimDailyAlert(): bool
    {
        $today = date('Y-m-d');
        try {
            $seen = (string) (DB::table('gates_settings')->where('key_name', 'refund_ceiling_alerted_on')->value('value') ?? '');
            if ($seen === $today) return false;
            DB::table('gates_settings')->updateOrInsert(
                ['key_name' => 'refund_ceiling_alerted_on'], ['value' => $today]
            );
            return true;
        } catch (\Throwable) {
            // Unknown means we cannot promise we have already told anybody, and an
            // unsent ceiling alert is worse than a duplicate one.
            return true;
        }
    }

    /**
     * Can this order still mint, ever?
     *
     * The whole automatic case rests on this being a NO that cannot become a yes.
     * A cycle that is merely between phases, or a nominee awaiting approval, is
     * not terminal — an admin can reopen either, and refunding into that is
     * taking a decision away from them.
     *
     * Answered by asking the mint path's own question rather than re-deriving it.
     * Two implementations of "is voting open" is exactly how a refund starts
     * disagreeing with a checkout.
     */
    private function terminallyUnminted(object $don): bool
    {
        $nomineeId = (int) ($don->intent_nominee_id ?? 0);
        if ($nomineeId < 1) return false;   // nothing to deliver against; a person decides

        try {
            $catId = (int) (DB::table('gates_nominees')->where('id', $nomineeId)->value('category_id') ?? 0);
            if ($catId < 1) return false;
            // The same call mint() makes. If it ever says yes again, this order
            // mints instead of being refunded, which is the better outcome.
            if (PaidVoteService::votingOpenFor($catId)) return false;

            // ── AND MINT MUST HAVE RUN OUT OF ROAD ───────────────────────────
            //
            // mint() no longer judges the phase on the webhook's clock; it judges
            // on the ORDER'S, and will still deliver a payment that was started
            // before the close for several hours afterwards. Refunding inside
            // that window races it — the supporter gets their money back at 01:00
            // and their votes at 03:00, which is worse than either outcome alone.
            //
            // So: refund only once no mint can succeed for this cycle. Measured
            // from the CLOSE, not from the order, because that is what mint()
            // measures — and an order placed AFTER the close was never going to
            // mint anyway, so this leaves the hopeless cases as fast as before.
            $close = BallotGuard::votingCloseFor($catId);
            if ($close !== null) {
                $mintWindowEnds = $close->copy()->addHours(PaidVoteService::lateMintGraceHours());
                if (Carbon::now()->lt($mintWindowEnds)
                    && Carbon::parse((string) $don->created_at)->lt($close)) {
                    return false;   // still deliverable — votes beat a refund
                }
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[refund] could not test the cycle for ' . $don->payment_ref . ': ' . $e->getMessage());
            return false;   // unknown is never a reason to move money
        }
    }

    /**
     * Refund one order. True only when the gateway accepted it.
     *
     * The order of operations is the safety property, and it is the same one the
     * ticket path uses for a different reason: claim first, act second, record
     * third. A crash anywhere leaves a state that is visible and conservative.
     */
    private function refundOne(object $don): bool
    {
        $ref  = (string) $don->payment_ref;
        $now  = date('Y-m-d H:i:s');

        // ── 1. claim ─────────────────────────────────────────────────────────
        $claimed = DB::table('gates_donations')
            ->where('id', $don->id)
            ->whereNull('refund_requested_at')
            ->update(['refund_requested_at' => $now, 'refund_state' => 'requested']);
        if ($claimed === 0) return false;   // another worker has it

        // ── 2. ask the gateway ───────────────────────────────────────────────
        $provider = $this->providerFor($don);
        if ($provider === null) {
            $this->settle($don->id, 'failed', null, 'no gateway recognised this reference');
            error_log('[refund] no gateway recognised ' . $ref . ' — left for a human.');
            return false;
        }

        $r = $this->payments->refund($provider, $ref, (int) $don->amount_naira);

        // ── 3. record ────────────────────────────────────────────────────────
        if ($r['status'] === 'failed') return $this->backOff($don, (string) $r['message']);

        $settled = $r['status'] === 'refunded';
        $this->settle($don->id, $settled ? 'refunded' : 'pending', $r['provider_ref'],
            'voting closed before the payment confirmed; no votes were minted');

        $this->tellTheBuyer($don, $settled);
        try {
            WebhookService::dispatch('payment.refunded', [
                'reference' => $ref, 'amount_naira' => (int) $don->amount_naira,
                'settled'   => $settled, 'automatic' => true, 'at' => date('c'),
            ]);
        } catch (\Throwable) {}

        return true;
    }

    /**
     * The gateway said no. Release the claim, and wait before asking again.
     *
     * ── RELEASING THE CLAIM IS RIGHT; RELEASING IT INTO A LOOP WAS NOT ───────
     *
     * A definite refusal is the one outcome where we know for certain no money
     * moved, so the claim comes off and a later attempt is safe. That much was
     * always correct. What was missing is that `owed()` re-selects an unclaimed row
     * immediately, and maintenance ticks every fourteen minutes — so one refusal
     * meant roughly a hundred gateway calls and a hundred identical admin emails a
     * day, each of them stating the refund would not be retried automatically.
     *
     * Retrying is worth keeping. The commonest refusal on both gateways here is an
     * insufficient merchant settlement balance, which clears by itself once the
     * day's takings settle — so the order that failed at 09:00 very often succeeds
     * at 15:00 with nobody doing anything.
     *
     * The pace is now 1h → 6h → 24h, and then it stops and says so. A person is
     * told on the FIRST refusal (so a broken key or a revoked permission surfaces
     * within the hour) and on the LAST (so nothing is quietly abandoned), and not
     * in between.
     *
     * @return false always — no money moved, so this is not a completed refund.
     */
    private function backOff(object $don, string $why): bool
    {
        $ref      = (string) $don->payment_ref;
        $amount   = '₦' . number_format((int) $don->amount_naira);
        $attempts = (int) ($don->refund_attempts ?? 0) + 1;
        $waitHrs  = self::RETRY_AFTER_HOURS[$attempts - 1] ?? null;   // null = out of road

        $patch = [
            'refund_requested_at' => null,
            // 'failed' while there is another attempt coming, 'exhausted' when
            // there is not. Different words because they need different answers
            // from support: one is "it is still being tried", the other is "a
            // person has it". `owed()` skips only the second.
            'refund_state'        => $waitHrs === null ? 'exhausted' : 'failed',
            'refund_reason'       => mb_substr('gateway refused: ' . $why, 0, 250),
        ];
        if ($waitHrs !== null) {
            $patch['refund_retry_after'] = Carbon::now()->addHours($waitHrs)->toDateTimeString();
        }
        $patch['refund_attempts'] = $attempts;

        try {
            DB::table('gates_donations')->where('id', $don->id)
                ->update(OptionalColumn::filter('gates_donations', $patch, ['refund_retry_after', 'refund_attempts']));
        } catch (\Throwable $e) {
            error_log('[refund] could not record the refusal for ' . $ref . ': ' . $e->getMessage());
        }

        error_log('[refund] ' . $ref . ' refused (attempt ' . $attempts . '): ' . $why);

        // FIRST and LAST only. The ones in between are the same sentence with a
        // different timestamp, and a hundred of those is how an alert stops being
        // read at all.
        if ($attempts === 1) {
            $this->alert("Automatic refund refused — {$ref}",
                "The gateway refused an automatic refund for {$ref} ({$amount}).\n\n"
                . "Reason: {$why}\n\n"
                . "This order is confirmed with no votes minted, so the refund is still owed. It will be "
                . "retried in " . $waitHrs . "h, and " . (self::MAX_REFUND_ATTEMPTS - 1) . " times in all over "
                . "the next " . array_sum(self::RETRY_AFTER_HOURS) . " hours. If the reason is an insufficient "
                . "settlement balance it will very likely clear by itself once the day's takings settle; if it "
                . "is a key or a permission, it will not, and that is the window you have to fix it in.");
        } elseif ($waitHrs === null) {
            $this->alert("Automatic refund GAVE UP — {$ref}",
                "After " . $attempts . " attempts over " . array_sum(self::RETRY_AFTER_HOURS)
                . " hours, the gateway is still refusing to refund {$ref} ({$amount}).\n\n"
                . "Last reason: {$why}\n\n"
                . "Nothing further will be tried automatically. The money is genuinely owed — please refund it "
                . "in the gateway dashboard. The order stays visible on /admin/finance as `exhausted`.");
        }

        return false;
    }

    /** Write the outcome. `refunded_at` is stamped only when the money is truly back. */
    private function settle(int $id, string $state, ?string $providerRef, string $reason): void
    {
        try {
            $patch = ['refund_state' => $state, 'refund_reason' => mb_substr($reason, 0, 250)];
            if ($providerRef !== null) $patch['refund_ref'] = mb_substr($providerRef, 0, 120);
            if ($state === 'refunded') $patch['refunded_at'] = date('Y-m-d H:i:s');
            // An order that succeeded on its second or third try must not keep a
            // stale backoff stamp: it reads as "waiting to be retried" on a row
            // whose money is already on its way back.
            $patch['refund_retry_after'] = null;
            DB::table('gates_donations')->where('id', $id)
                ->update(OptionalColumn::filter('gates_donations', $patch, ['refund_retry_after']));
        } catch (\Throwable $e) {
            error_log('[refund] could not record outcome for donation ' . $id . ': ' . $e->getMessage());
        }
    }

    /**
     * Which gateway took this money?
     *
     * The order RECORDS it now, which is the answer, and this is the one caller
     * where being wrong sends money to the wrong place. It was guesswork: a
     * `paystack_` reference prefix our own references have never carried (they are
     * `AFG-PVOTE-…`), so in practice every refund fell through to asking each
     * gateway in turn and taking the first that recognised it.
     *
     * The fallback is kept for orders taken before the column existed, and it is
     * still a real verification rather than a guess — but a recorded provider is
     * never second-guessed. Only when the recorded gateway has since been switched
     * off in the environment do we look further, because a refund that cannot be
     * sent at all is worse than one sent through the surviving gateway and refused.
     */
    private function providerFor(object $don): ?string
    {
        $enabled  = $this->payments->enabledProviderIds();
        $recorded = strtolower(trim((string) ($don->provider ?? '')));
        if ($recorded !== '' && in_array($recorded, $enabled, true)) return $recorded;

        $reference = (string) $don->payment_ref;
        foreach ($enabled as $p) {
            if (str_starts_with(strtolower($reference), $p . '_')) return $p;
        }
        foreach ($enabled as $p) {
            $v = $this->payments->verify($p, $reference);
            if (($v['ok'] ?? false) && ($v['status'] ?? '') === 'success') return $p;
        }
        return null;
    }

    /** What has already gone out today, so the ceiling means something. */
    private function spentToday(): int
    {
        try {
            return (int) (DB::table('gates_donations')
                ->whereIn('refund_state', ['refunded', 'pending'])
                ->where('refund_requested_at', '>=', date('Y-m-d 00:00:00'))
                ->sum('amount_naira') ?? 0);
        } catch (\Throwable) {
            // Unknown spend means an unenforceable ceiling. Report the maximum so
            // the sweep stops rather than running unbounded.
            return self::MAX_DAILY_NAIRA;
        }
    }

    /**
     * Tell the buyer, in the words somebody would actually want to read.
     *
     * A refund nobody was told about looks, from the buyer's side, exactly like
     * the money still being missing — which is the complaint this whole path
     * exists to prevent, arriving a second time.
     */
    private function tellTheBuyer(object $don, bool $settled): void
    {
        $to = strtolower(trim((string) ($don->donor_email ?? '')));
        if ($this->mailer === null || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

        $amount = '₦' . number_format((int) $don->amount_naira);
        $name   = trim((string) ($don->donor_name ?? '')) ?: 'there';
        $text   = "Hi {$name},\n\n"
            . "Your payment of {$amount} went through, but voting in that category had already closed by the "
            . "time it reached us — so no votes were added. We do not keep money for votes we did not count.\n\n"
            . ($settled
                ? "{$amount} has been refunded to the card or account you paid with."
                : "{$amount} is on its way back to the card or account you paid with. Banks usually take "
                . "5–10 working days to show it.")
            . "\n\nNothing is needed from you. Reference: " . (string) $don->payment_ref . "\n\n"
            . "If you would rather have the votes than the money and the category reopens, reply to this "
            . "email and we will sort it out.";

        try {
            $this->mailer->sendBranded($to, 'Your Africa GATES payment has been refunded',
                nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')), $text, 'Refund');
        } catch (\Throwable $e) {
            error_log('[refund] could not email the buyer for ' . $don->payment_ref . ': ' . $e->getMessage());
        }
    }

    private function alert(string $subject, string $body): void
    {
        try { Notifier::adminAlert($this->mailer, $subject, $body); } catch (\Throwable) {}
    }

    // ── read-only, for the assistant and the admin console ───────────────────

    /**
     * Where a refund stands, by reference. Discloses no payer detail.
     *
     * This is all the support assistant is ever given. It can say "a refund is on
     * its way" and it cannot cause one.
     *
     * @return array{found:bool, state?:string, settled?:bool, say:string}
     */
    public static function statusFor(string $reference): array
    {
        $ref = trim($reference);
        if ($ref === '') return ['found' => false, 'say' => 'A payment reference is needed.'];

        try {
            $d = DB::table('gates_donations')->where('payment_ref', $ref)->first();
        } catch (\Throwable) {
            return ['found' => false, 'say' => 'I could not check that just now.'];
        }
        if (!$d) return ['found' => false, 'say' => 'No payment with that reference is on record.'];

        $state = self::ready() ? (string) ($d->refund_state ?? '') : '';
        if (($d->refunded_at ?? null) !== null || $state === 'refunded') {
            return ['found' => true, 'state' => 'refunded', 'settled' => true,
                    'say' => 'That payment has been refunded in full. Banks usually take 5–10 working days to show it.'];
        }
        if ($state === 'requested' || $state === 'pending') {
            return ['found' => true, 'state' => 'pending', 'settled' => false,
                    'say' => 'A refund for that payment is already on its way — no votes could be added, so the '
                           . 'money is going back. Banks usually take 5–10 working days to show it.'];
        }
        // 'failed' now means "refused, and being tried again" — the sweep backs off
        // 1h, 6h, 24h rather than looping. Telling somebody it has failed when it
        // is still coming is the kind of answer that produces a second complaint.
        if ($state === 'failed') {
            return ['found' => true, 'state' => 'retrying', 'settled' => false,
                    'say' => 'A refund for that payment was attempted and the bank refused it the first time — '
                           . 'usually a temporary thing. It is being retried automatically over the next day, '
                           . 'and the team has been told. The money is owed and it is not forgotten.'];
        }
        // Terminal, and both need a person — so neither may be described as being
        // in progress. `exhausted` is the automatic path giving up; `manual` is it
        // declining to start, because the order is over the per-order ceiling.
        if ($state === 'exhausted' || $state === 'manual') {
            return ['found' => true, 'state' => $state, 'settled' => false,
                    'say' => 'A refund is owed on that payment and it is being handled by a person rather than '
                           . 'automatically. The team already has it — it does not need to be reported again. '
                           . 'If it has been more than a few working days, ask and someone will chase it.'];
        }
        return ['found' => true, 'state' => 'none', 'settled' => false,
                'say' => 'No refund has been started for that payment.'];
    }
}
