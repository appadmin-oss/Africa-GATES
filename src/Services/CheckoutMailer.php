<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\NomineeUrl;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The two emails a checkout owes a buyer, and neither of them existed.
 *
 * ── WHAT WAS ACTUALLY HAPPENING ──────────────────────────────────────────────
 *
 * Reported as "emails are not being sent to voters". Both halves are true, for
 * different reasons, and neither is a mail-server problem:
 *
 *  1. A PAID vote sent nothing, ever. `PaidVoteService::mint()` bumps the tally and
 *     dispatches a webhook; `PaidVoteController::callback()` mints and redirects to a
 *     confirmation page. No receipt, no confirmation, no reference — and on a site
 *     running with free voting disabled, the OTP mail is refused at the boundary with
 *     a 403, so the paid path is the ONLY vote path and it was silent end to end.
 *     Someone who spends money on a nominee and receives nothing has no evidence the
 *     purchase happened, which is a support ticket at best and a chargeback at worst.
 *
 *  2. A buyer who reached the gateway and did not finish heard nothing either. The
 *     pending `gates_donations` row sat there until `cycles:audit` counted it. That
 *     row is a supporter who WANTED to vote and got interrupted — the highest-intent
 *     population on the platform, and the cheapest to recover.
 *
 * (The free OTP path does send a confirmation, from `ApiController::castVote()`. It
 * logged with a NULL category, so "are voters getting mail?" was unanswerable from
 * `gates_mail_log` even where mail was working. That now records 'Votes'.)
 *
 * ── SEND EXACTLY ONCE, AND MEAN IT ───────────────────────────────────────────
 *
 * Both emails have more than one caller racing to send them:
 *
 *   • the receipt — the browser callback and the signature-verified gateway webhook
 *     both confirm the same order, whichever lands first, and `payments:reconcile`
 *     is a third;
 *   • the recovery mail — the sweep re-selects the same rows on every maintenance
 *     tick until something records that they were mailed.
 *
 * So each is claimed by a guarded UPDATE on a NULL timestamp — the same single-
 * statement mechanism `votes_used` uses to mint an order's votes exactly once. The
 * claim is taken BEFORE the send, because a duplicate receipt is worse than a late
 * one; it is RELEASED when the transport reports failure, because a permanently
 * lost receipt is worse than either. {@see OtpService::sendBranded()} returns
 * success=false only when nothing was handed to a mail server.
 *
 * ── WHAT THE RECOVERY MAIL MUST NOT SAY ──────────────────────────────────────
 *
 * It must not say "you were not charged". A pending row means no SUCCESSFUL
 * verification was ever seen — not that no money moved. A dropped callback on a real
 * payment produces exactly the same row, which is what `payments:reconcile` exists to
 * resolve, so the sweep runs after it and the copy invites the buyer to quote the
 * reference if their bank disagrees.
 */
final class CheckoutMailer
{
    /**
     * How long after starting a checkout before a buyer is treated as gone.
     *
     * Long enough to be past the gateway itself: a card that needs a bank OTP, a
     * transfer that needs an app switch, a slow 3-D Secure step. Mailing "you didn't
     * finish" to somebody still typing their PIN is worse than not mailing at all.
     *
     * ── AND IT IS NOT THIS CLASS'S NUMBER TO PICK ────────────────────────────
     *
     * It was 45 minutes, chosen here, while the rest of the platform separately
     * assumed a payment could still be moving for two hours — which is what a
     * Nigerian bank transfer or a walk through a USSD menu actually takes. So this
     * class was nudging people mid-payment: exactly what the paragraph above says
     * it must not do, and nowhere did one place say what the tolerance was.
     *
     * {@see PaymentService::IN_FLIGHT_MINUTES} is now that place, read by
     * everything that declares a pending checkout dead, so the windows cannot
     * drift apart again. It is also better recovery practice on its own terms: an
     * hour or two is the ordinary cart-recovery delay and 45 minutes was
     * aggressive enough to reach people who had not finished deciding.
     */
    public const GRACE_MINUTES = PaymentService::IN_FLIGHT_MINUTES;

    /**
     * How far back the sweep will look.
     *
     * Two jobs. It keeps the recovery relevant — a nudge about a checkout from last
     * month is spam, not service. And it bounds the FIRST run: this ships to a
     * database that already holds every pending row ever written, and without a
     * window the first tick would try to mail all of them.
     */
    public const WINDOW_HOURS = 72;

    /** Rows per sweep. Bounded because each row is one blocking SMTP conversation. */
    public const BATCH = 40;

    /** Injected transport (container, or a fake in tests). Null means "boot one". */
    private static ?OtpService $transport = null;
    private static bool $booted = false;

    /**
     * Supply the transport. Called by the container so a web request reuses the
     * configured mailer, and by tests to capture sends without SMTP.
     */
    public static function using(?OtpService $mailer): void
    {
        self::$transport = $mailer;
        self::$booted    = $mailer !== null;
    }

    /**
     * The mailer, self-booting when nobody injected one.
     *
     * Deliberately not a constructor dependency. The two places a receipt must be
     * sent from are `PaidVoteController::callback()` and `PaymentController::confirm()`,
     * and neither controller has a mailer — threading one through both (plus the
     * container, plus the console) to reach a send is how this feature ends up
     * half-wired and silent in exactly one of the two paths. `SmsService::boot()`
     * is the same pattern for the same reason.
     */
    public static function transport(): ?OtpService
    {
        if (self::$booted) return self::$transport;
        self::$booted = true;
        try {
            // This built its own settings-aware $pick() and then used it for the
            // sender identity only, while host/user/password still came straight from
            // the environment — so a login pasted into Settings was ignored while the
            // from-name from the same form was applied. One resolver now.
            self::$transport = OtpService::boot();
        } catch (\Throwable) {
            self::$transport = null;
        }
        return self::$transport;
    }

    /* ══════════════════════════════════════════════════════════════════════
       RECEIPT — sent once, when a paid-vote order or a gift confirms
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Email the buyer of a CONFIRMED paid-vote order.
     *
     * TWO OUTCOMES, TOLD APART BY `votes_used`. That column is the mint claim flag, so
     * a confirmed order with `votes_used = 0` is the platform's existing, queryable
     * "paid but never minted — refund owed" signal (see {@see PaidVoteService::mint()},
     * which refuses to mint into a closed cycle, and `cycles:audit`, which reports the
     * same population). A receipt that congratulated that buyer on votes they do not
     * have would be the platform lying about a payment, so it gets its own honest
     * message with the reference they need to claim the refund. The confirmation page
     * already draws this exact distinction; the email now agrees with it.
     *
     * Best-effort by contract: never throws, so a mail problem can never unwind a
     * confirmation or a mint.
     *
     * @return array{sent:bool, reason?:string, kind?:string}
     */
    /**
     * Send the receipt AGAIN, on purpose, because somebody asked for it.
     *
     * {@see receipt()} claims `receipt_sent_at` so two concurrent confirmations
     * cannot produce two receipts — correct for the automatic path, and exactly
     * wrong here. "It never arrived" is the single most common support message
     * about a payment that worked, and the claim flag turns every attempt to
     * answer it into a silent `already_sent`. Spam filters eat mail, addresses
     * get typo'd, and a shared host's SMTP drops messages with a cheerful 250.
     *
     * Releasing the claim first is safe because the destination is not a choice:
     * `receipt()` reads the address off the ORDER. Whoever asks for a resend, the
     * mail goes to the buyer — so this can annoy that buyer, and can never be
     * used to send their receipt to anybody else.
     *
     * @return array{sent:bool, reason?:string, kind?:string}
     */
    public static function resend(int $donationId): array
    {
        self::release($donationId, 'receipt_sent_at');
        return self::receipt($donationId);
    }

    /** Queue name for a receipt that must not be sent inside a gateway webhook. */
    public const JOB_RECEIPT = 'checkout.receipt';

    /**
     * Send the receipt LATER, off the request that confirmed the payment.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A RECEIPT MUST NOT BE SENT INSIDE A PAYSTACK WEBHOOK
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Paystack allows roughly 30 seconds per webhook delivery attempt, and treats
     * a slower response as a failed delivery: live mode then retries every three
     * minutes for four attempts and hourly for up to 72 hours, and eventually
     * stops. Their docs are explicit that SMTP, PDF generation and third-party
     * calls belong on a queue rather than in the handler.
     *
     * Measured against this codebase, one webhook delivery could spend:
     *
     *     up to 15s   verifying the transaction with Paystack (PaymentService::TIMEOUT)
     *   + up to  8s   PER active outbound integration (WebhookService, 5s + 3s connect)
     *   + up to 12s   sending this receipt over SMTP (PHPMailer Timeout = 12)
     *
     * With no outbound integrations configured that is 27 seconds against a
     * 30-second budget. Adding a single one — which an admin can do from the
     * console, with no reason to connect it to payments — puts every payment
     * webhook over the limit. Nothing would look broken: our confirmation is
     * idempotent, so the retries would keep landing on an already-confirmed order
     * and doing nothing, while Paystack's dashboard filled with failed deliveries.
     *
     * ── WHY QUEUEING IS SAFE HERE SPECIFICALLY ───────────────────────────────
     *
     * {@see receipt()} claims before it sends and gives the claim back if nothing
     * reached a mail server, so running it twice sends one email and running it
     * late sends the right one. The dedupe key makes a second enqueue a no-op.
     *
     * ── AND WHY IT FALLS BACK TO SENDING NOW ─────────────────────────────────
     *
     * A queued receipt is only as good as the thing that drains the queue. If the
     * jobs table cannot be written to, sending inline is slower than we would like
     * but a receipt that arrives late is infinitely better than one that never
     * arrives — the buyer has paid either way.
     */
    public static function queueReceipt(int $donationId): void
    {
        if ($donationId < 1) return;
        try {
            (new QueueService())->push(self::JOB_RECEIPT, ['donation_id' => $donationId],
                                       0, 'receipt:' . $donationId);
            return;
        } catch (\Throwable $e) {
            error_log('[CheckoutMailer] could not queue receipt for #' . $donationId
                    . ', sending inline instead: ' . $e->getMessage());
        }
        self::receipt($donationId);
    }

    public static function receipt(int $donationId): array
    {
        try {
            $don = DB::table('gates_donations')->where('id', $donationId)->first();
            if (!$don)                                       return ['sent' => false, 'reason' => 'not_found'];
            $gift = self::isGift($don);
            if (!$gift && (string) ($don->tier ?? '') !== 'paid-vote') return ['sent' => false, 'reason' => 'not_receipted_here'];
            if ((string) ($don->status ?? '') !== 'confirmed') return ['sent' => false, 'reason' => 'not_confirmed'];
            if (!empty($don->refunded_at))                   return ['sent' => false, 'reason' => 'refunded'];

            $to = strtolower(trim((string) ($don->donor_email ?? '')));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL))     return ['sent' => false, 'reason' => 'no_email'];

            $mailer = self::transport();
            if ($mailer === null)                            return ['sent' => false, 'reason' => 'no_transport'];

            // Claim before sending: the callback and the webhook can both be here at
            // once, and one payment must not produce two receipts.
            if (!self::claim($donationId, 'receipt_sent_at')) return ['sent' => false, 'reason' => 'already_sent'];

            if ($gift) {
                $mail = self::giftBody($don);
                $kind = $mail['kind'];
            } else {
                $minted = (int) ($don->votes_used ?? 0) > 0;
                $mail   = $minted ? self::mintedBody($don) : self::unmintedBody($don);
                $kind   = $minted ? 'minted' : 'unminted';
            }

            // The handoff's bodies (Services\Mail\Emails): gift, vote, notminted.
            $r = \AfricaGates\Services\Mail\Emails::send($mailer, $to, $mail['key'], $mail['vars']);
            if (empty($r['success'])) {
                // Nothing reached a mail server. Give the claim back so a later
                // confirm, reconcile or resend can try again — an unsent receipt for a
                // payment that DID complete is the worse of the two failures.
                self::release($donationId, 'receipt_sent_at');
                return ['sent' => false, 'reason' => 'send_failed', 'kind' => $kind];
            }
            if ($gift) {
                // Inside the claim, so the office hears about a gift exactly as many times
                // as the donor does: once. It used to ride on the browser callback with the
                // receipt and was lost with it on every webhook-first confirmation.
                Notifier::adminAlert($mailer, 'New donation (confirmed)',
                    'Donor:  ' . (string) $don->donor_name . ' <' . $to . ">\nAmount: ₦"
                    . number_format((int) $don->amount_naira) . "\nRef:    " . (string) $don->payment_ref);
            }
            return ['sent' => true, 'kind' => $kind];
        } catch (\Throwable $e) {
            error_log('[CheckoutMailer] receipt failed: ' . $e->getMessage());
            return ['sent' => false, 'reason' => 'error'];
        }
    }

    /**
     * Does this row owe a GIFT receipt — the one {@see DonationController} used to send?
     *
     * `donation` is the tier `DonationController::start()` writes, for the fund and for a
     * partner organisation alike. A recurring instalment minted by
     * {@see RecurringGiving::chargeArrived()} is a gift too, and the older ones were
     * written with no tier at all, so the subscription link is what identifies them.
     */
    public static function isGift(object $don): bool
    {
        return (string) ($don->tier ?? '') === 'donation' || !empty($don->subscription_id);
    }

    /**
     * The receipt for a GIFT — and, for a standing gift, the link that stops it.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS MOVED HERE FROM THE DONATION CONTROLLER
     * ══════════════════════════════════════════════════════════════════════════
     *
     * It was sent by `DonationController::callback()`, and only when the callback was the
     * call that flipped the row. But an `AFG-GIVE-` reference is also confirmed by the
     * signature-verified webhook through `PaymentController::confirmByReference()`, whose
     * delivery step acted only on paid votes, and the reconcile sweep's call to
     * {@see receipt()} answered `not_paid_vote`. So whenever the webhook won the race —
     * which is the ordinary case for anybody who pays inside a banking app and never comes
     * back — the donor got no receipt and the office got no alert. And for a MONTHLY gift
     * that receipt is the only thing that ever carries {@see RecurringGiving::stopLink()}:
     * a donor who cannot find the off switch goes to their bank, and a chargeback costs
     * more than the gift was worth.
     *
     * Every confirming path now reaches {@see receipt()}, which claims `receipt_sent_at`
     * before sending, so whichever of {callback, webhook, reconcile} arrives first sends
     * it and the others send nothing.
     *
     * @return array{subject:string, html:string, text:string, kind:string}
     */
    private static function giftBody(object $don): array
    {
        $amount = '₦' . number_format((int) $don->amount_naira);
        $ref    = (string) ($don->payment_ref ?? '');
        // Said plainly and in the same breath as the amount, because the one thing a
        // monthly donor must not have to hunt for is the fact that it is monthly and where
        // the off switch is. The link is RecurringGiving::stopLink(), never built here.
        $stop   = RecurringGiving::stopLink($ref, SiteUrl::base());

        // Named, because a gift to somebody else's appeal thanked the donor for a gift "to
        // Africa GATES" — the one organisation it was not for.
        $org = null;
        if (!empty($don->recipient_org_id)) {
            try { $org = DB::table('gates_partner_orgs')->where('id', (int) $don->recipient_org_id)->first(['name', 'slug']); } catch (\Throwable) {}
        }
        $toName = $org && (string) $org->name !== '' ? (string) $org->name : 'Africa GATES';

        // The campaign's progress, drawn only above the low-data thresholds — the same rules
        // as the campaign page, so the email never prints "1% of the goal, 1 person".
        $progress = null;
        $campaignUrl = '';
        if (!empty($don->campaign_id) && $org) {
            try {
                $c = DB::table('gates_org_campaigns')->where('id', (int) $don->campaign_id)->first();
                if ($c) {
                    $campaignUrl = SiteUrl::base() . \AfricaGates\Support\GivingUrl::org((string) $org->slug, (string) $c->slug);
                    $p = OrgCampaign::progress((int) $c->id);
                    if ($p['target'] > 0 && \AfricaGates\Support\DisplayThreshold::met('campaign_pct_ring', $p['pct'])) {
                        $days = OrgCampaign::daysLeft($c);
                        $bits = [];
                        if ($days !== null && $days <= \AfricaGates\Support\DisplayThreshold::min('days_left_show')) $bits[] = $days . ($days === 1 ? ' day' : ' days') . ' left';
                        if (\AfricaGates\Support\DisplayThreshold::met('gifts_count', $p['count'])) $bits[] = number_format($p['count']) . ' gifts';
                        $progress = ['raised' => '₦' . number_format($p['raised']), 'goal' => '₦' . number_format($p['target']),
                                     'pct' => min(100, (int) $p['pct']), 'caption' => implode(' · ', $bits) ?: min(100, (int) $p['pct']) . '% of the goal'];
                    }
                }
            } catch (\Throwable) {}
        }

        return [
            'key'  => 'gift',
            'kind' => $stop === '' ? 'gift' : 'monthly',
            'vars' => [
                'first_name'   => DoorWelcome::firstName((string) $don->donor_name) ?: 'there',
                'amount'       => $amount,
                'to_name'      => $toName,
                'impact_line'  => $org ? 'With gratitude.' : 'Your gift funds child leadership programmes across the continent — mentorship, scholarships and grassroots education. With gratitude.',
                'reference'    => $ref,
                'stop_url'     => $stop,
                'progress'     => $progress,
                'campaign_url' => $campaignUrl,
            ],
        ];
    }

    /**
     * "Your votes are in" — the ordinary, happy receipt.
     *
     * What the votes DO is stated on it, because a receipt is the document a dispute is
     * argued from: they count toward the tally and the community half exactly as a free vote
     * does, and they never reach the judging half. (It once said they were "never moved by
     * money", which was not the rule — the worst place on the platform to say it.)
     */
    private static function mintedBody(object $don): array
    {
        $qty = (int) $don->bonus_votes;
        $nom = self::nominee((int) ($don->intent_nominee_id ?? 0));
        return [
            'key'  => 'vote',
            'vars' => [
                'first_name'         => DoorWelcome::firstName((string) $don->donor_name) ?: 'there',
                'nominee'            => $nom['name'],
                'nominee_first_name' => $nom['name'] !== '' ? (DoorWelcome::firstName($nom['name']) ?: $nom['name']) : '',
                'category'           => $nom['category'],
                'votes'              => $qty,
                'votes_label'        => number_format($qty),
                'amount'             => '₦' . number_format((int) $don->amount_naira),
                'reference'          => (string) ($don->payment_ref ?? ''),
                'page_url'           => $nom['url'],
            ],
        ];
    }

    /**
     * "We took the money and the votes did not land."
     *
     * The uncomfortable one, and the reason it exists: `mint()` deliberately refuses to
     * add votes when the cycle closed between payment and confirmation, leaving the
     * order refundable. Saying nothing would leave the buyer to discover it by counting
     * a tally.
     */
    private static function unmintedBody(object $don): array
    {
        $nom  = self::nominee((int) ($don->intent_nominee_id ?? 0));
        $base = SiteUrl::base();
        return [
            'key'  => 'notminted',
            'vars' => [
                'first_name'    => DoorWelcome::firstName((string) $don->donor_name) ?: 'there',
                'votes_label'   => number_format((int) $don->bonus_votes),
                'nominee'       => $nom['name'],
                'amount'        => '₦' . number_format((int) $don->amount_naira),
                'reference'     => (string) ($don->payment_ref ?? ''),
                // Whether the refund is coming on its own — the same switch the sweep reads.
                'auto_refund'   => RefundService::autoEnabled() && RefundService::ready(),
                'assistant_url' => $base . '/support/assistant',
                'ticket_url'    => $base . '/support/tickets',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       RECOVERY — one nudge to a buyer who did not finish
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Mail the buyers of stale PENDING orders. One email per order, once ever.
     *
     * ── EVERY FILTER HERE IS LOAD-BEARING ────────────────────────────────────
     *
     * This is the one send on the platform that goes to people who did NOT complete an
     * action, which makes it the one that can turn into spam or into an accusation.
     *
     *   • older than GRACE_MINUTES  — not still at the gateway
     *   • newer than WINDOW_HOURS   — relevant, and the first run is bounded
     *   • abandoned_mail_at IS NULL — claimed, so the next tick does not repeat it
     *   • status re-checked AT CLAIM TIME — closes the read→send gap in which the
     *     payment may have confirmed
     *   • no confirmed order from the same address in the window — a buyer whose
     *     first attempt failed and second succeeded leaves BOTH rows behind, and
     *     telling a paying customer they did not pay is worse than staying silent
     *
     * @return array{considered:int, sent:int, skipped:int, dry_run:bool, reasons:array<string,int>}
     */
    public static function sweepAbandoned(int $limit = self::BATCH, bool $dryRun = false): array
    {
        $out = ['considered' => 0, 'sent' => 0, 'skipped' => 0, 'dry_run' => $dryRun, 'reasons' => []];
        $bump = static function (string $why) use (&$out): void {
            $out['skipped']++;
            $out['reasons'][$why] = ($out['reasons'][$why] ?? 0) + 1;
        };

        try {
            $now    = Carbon::now();
            // GRACE_MINUTES is no longer applied here: whereCheckoutDead() owns the
            // "still at the gateway?" half, using the order's own deadline. The
            // constant stays public because other readers still name it.
            $oldest = $now->copy()->subHours(self::WINDOW_HOURS)->toDateTimeString();

            // "Not still at the gateway" is now asked of the ORDER'S OWN deadline
            // rather than of a flat two hours from creation. It matters most exactly
            // at the bell: a checkout started twenty minutes before voting closed used
            // to count as live for an hour and forty minutes afterwards, so the person
            // who walked away from it was nudged to go back and finish paying for
            // votes that could no longer be delivered. See
            // PaidVoteService::whereCheckoutDead().
            $q = DB::table('gates_donations')
                ->where('status', 'pending')
                ->whereNull('abandoned_mail_at')
                ->where('created_at', '>=', $oldest);

            $rows = PaidVoteService::whereCheckoutDead($q, $now)
                ->orderBy('id')
                ->limit(max(1, $limit))
                ->get();

            $out['considered'] = $rows->count();
            if ($rows->isEmpty()) return $out;

            $mailer = $dryRun ? null : self::transport();
            if (!$dryRun && $mailer === null) {
                $out['skipped'] = $out['considered'];
                $out['reasons']['no_transport'] = $out['considered'];
                return $out;
            }

            foreach ($rows as $don) {
                $to = strtolower(trim((string) ($don->donor_email ?? '')));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    // Nothing to send and nothing to retry — claim it so the sweep
                    // stops reconsidering it on every tick from now until the window
                    // moves past it.
                    if (!$dryRun) self::claim((int) $don->id, 'abandoned_mail_at');
                    $bump('no_email');
                    continue;
                }
                if (self::completedElsewhere($don, $oldest)) {
                    if (!$dryRun) self::claim((int) $don->id, 'abandoned_mail_at');
                    $bump('completed_elsewhere');
                    continue;
                }
                if ($dryRun) { $out['sent']++; continue; }

                // Claim re-checks status: between the SELECT above and here the
                // gateway webhook may have confirmed this very order.
                if (!self::claim((int) $don->id, 'abandoned_mail_at', 'pending')) {
                    $bump('confirmed_or_claimed');
                    continue;
                }

                $mail = self::abandonedBody($don);
                $r = $mailer->sendBranded($to, $mail['subject'], $mail['html'], $mail['text'], 'Checkout', $mail['hero'] ?? '');
                if (empty($r['success'])) {
                    // Unlike the receipt, this claim is NOT released. A recovery nudge
                    // is worth sending once and only once; retrying it against a
                    // broken transport is how one abandoned order becomes a nightly
                    // email to the same person. The failure is in gates_mail_log.
                    $bump('send_failed');
                    continue;
                }
                $out['sent']++;
            }
        } catch (\Throwable $e) {
            error_log('[CheckoutMailer] abandoned sweep failed: ' . $e->getMessage());
            $out['reasons']['error'] = ($out['reasons']['error'] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * Did this buyer complete a payment on another attempt?
     *
     * Each press of "pay" writes its own pending row, so a supporter who bounced off
     * the gateway once and succeeded on the second try leaves a pending row AND a
     * confirmed one. Matching on the email address rather than the reference is
     * deliberate: the references differ by construction, the person does not.
     */
    private static function completedElsewhere(object $don, string $since): bool
    {
        $email = strtolower(trim((string) ($don->donor_email ?? '')));
        if ($email === '') return false;
        try {
            return DB::table('gates_donations')
                ->where('status', 'confirmed')
                ->whereNull('refunded_at')
                ->whereRaw('LOWER(donor_email) = ?', [$email])
                ->where('created_at', '>=', $since)
                ->exists();
        } catch (\Throwable) {
            // Unresolvable: treat as completed and stay silent. The cost of a missed
            // nudge is one unrecovered sale; the cost of the other mistake is telling
            // a paying supporter they did not pay.
            return true;
        }
    }

    /** The recovery email. Names what they started, and asserts nothing about their bank. */
    private static function abandonedBody(object $don): array
    {
        $e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $total = '₦' . number_format((int) $don->amount_naira);
        $ref   = (string) ($don->payment_ref ?? '');
        $paid  = (string) ($don->tier ?? '') === 'paid-vote';

        if ($paid) {
            $qty    = max(1, (int) $don->bonus_votes);
            $votes  = number_format($qty);
            $nom    = self::nominee((int) ($don->intent_nominee_id ?? 0));
            $name   = $nom['name'] !== '' ? $nom['name'] : 'your nominee';
            $cta    = $nom['url'];
            $lead   = 'You started buying <strong>' . $votes . '</strong> vote(s) for <strong>' . $e($name)
                . '</strong> and the payment was never completed, so the votes have not been added.';
            // Same reason as the receipt's subject: name the nominee when we know who it
            // is, and say nothing rather than "your nominee" when we do not.
            $subject = $nom['name'] === ''
                ? ($qty === 1 ? 'Your Africa GATES vote is one tap away' : "Your {$votes} Africa GATES votes are one tap away")
                : ($qty === 1 ? "Your vote for {$name} is one tap away" : "Your {$votes} votes for {$name} are one tap away");
            $button  = 'Finish buying votes &rarr;';
            $textCta = "Finish it here: {$cta}";
            $detail  = 'Votes: <strong>' . $votes . '</strong><br>For: <strong>' . $e($name) . '</strong>'
                . ($nom['category'] !== '' ? '<br>Category: <strong>' . $e($nom['category']) . '</strong>' : '')
                . '<br>Amount: <strong>' . $total . '</strong>';
            $closing = 'Nothing is reserved &mdash; a close race can move while a checkout sits unfinished.';
        } else {
            $cta     = SiteUrl::base() . \AfricaGates\Support\GivingUrl::page();
            $lead    = 'You started a payment of <strong>' . $total . '</strong> to Africa GATES and it was never completed.';
            $subject = 'Your Africa GATES payment was not completed';
            $button  = 'Finish the payment &rarr;';
            $textCta = "Finish it here: {$cta}";
            $detail  = 'Amount: <strong>' . $total . '</strong>';
            $closing = 'It only takes a moment, and it goes straight to child leadership programmes across the continent.';
        }

        $html = '<p style="margin:0 0 14px;font-size:15px;color:#374151">Hi <strong>' . $e($don->donor_name) . '</strong>,</p>'
            . '<p style="margin:0 0 6px;font-size:15px;color:#374151">' . $lead . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;background:#f7f9f7;'
            . 'border-left:4px solid #7FC87C;border-radius:0 10px 10px 0;padding:16px 20px">'
            . '<tr><td style="font-size:15px;color:#10292C;line-height:1.8">' . $detail . '</td></tr></table>'
            . '<p style="text-align:center;margin:24px 0"><a href="' . $e($cta) . '" '
            . 'style="display:inline-block;padding:13px 30px;background:#10292C;color:#fff;border-radius:999px;'
            . 'font-weight:700;text-decoration:none;font-size:15px">' . $button . '</a></p>'
            . '<p style="margin:0 0 14px;font-size:14px;color:#6b7280">' . $closing . '</p>'
            // NEVER "you were not charged": a pending row means no successful
            // verification was seen, which a dropped callback on a real payment also
            // produces. Invite the correction instead of asserting the wrong thing.
            . '<p style="margin:0;font-size:13px;color:#9ca3af">If your bank shows a charge for this already, do not pay '
            . 'again &mdash; reply to this email quoting reference <strong style="font-family:monospace">' . $e($ref)
            . '</strong> and we will match it up and add your votes. This is the only reminder we will send about it.</p>';

        // Built from the SAME sentence rather than hand-written a second time: two copies
        // of one message drift, and the plain-text part is what a spam filter reads.
        // Entities are decoded because the escaping above would otherwise leave a literal
        // "&amp;" or "&mdash;" sitting in the text alternative.
        $text = "Hi {$don->donor_name},\n\n"
            . html_entity_decode(strip_tags($lead), ENT_QUOTES, 'UTF-8')
            . "\n\n{$textCta}\n\nIf your bank shows a charge for this already, do not pay again — reply quoting "
            . "reference {$ref} and we will match it up.\n\n— Africa GATES";

        return [
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text,
            'hero'    => $paid ? SiteUrl::base() . '/assets/img/illustrations/illo-ballot-countdown.jpg' : '',
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       CLAIMS + LOOKUPS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * Take the send claim for one order. True means THIS caller owns the send.
     *
     * A single conditional UPDATE, so concurrent callers resolve to exactly one
     * winner without a transaction or a lock — identical in shape to the guarded
     * `votes_used` flip that mints an order's votes once.
     */
    private static function claim(int $donationId, string $column, ?string $requireStatus = null): bool
    {
        try {
            $q = DB::table('gates_donations')->where('id', $donationId)->whereNull($column);
            if ($requireStatus !== null) $q->where('status', $requireStatus);
            return $q->update([$column => Carbon::now()->toDateTimeString()]) > 0;
        } catch (\Throwable $e) {
            error_log('[CheckoutMailer] claim on ' . $column . ' failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Give a claim back after a transport failure, so a retry can send. */
    private static function release(int $donationId, string $column): void
    {
        try {
            DB::table('gates_donations')->where('id', $donationId)->update([$column => null]);
        } catch (\Throwable) {}
    }

    /**
     * Name, category and ballot URL for a nominee id.
     *
     * @return array{name:string, category:string, url:string}
     */
    private static function nominee(int $nomineeId): array
    {
        $out = ['name' => '', 'category' => '', 'url' => SiteUrl::base() . '/vote'];
        if ($nomineeId < 1) return $out;
        try {
            $row = DB::table('gates_nominees as n')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('n.id', $nomineeId)
                ->select(['n.name', 'c.title as category'])
                ->first();
            if ($row) {
                $out['name']     = (string) ($row->name ?? '');
                $out['category'] = (string) ($row->category ?? '');
            }
            // One shared builder, so the link in an email is byte-identical to the
            // link on the page the buyer came from.
            $out['url'] = NomineeUrl::ballot($nomineeId);
        } catch (\Throwable) {}
        return $out;
    }

    /* ══════════════════════════════════════════════════════════════════════
       DIAGNOSIS
    ══════════════════════════════════════════════════════════════════════ */

    /**
     * What checkout email is doing right now — for `app:doctor`.
     *
     * Exists because "emails are not being sent" was, for weeks, a claim nobody could
     * check. An unconfigured SMTP_USER makes every send return failure and write to
     * `var/logs/outgoing-mail.log` instead; a wrong password makes every send fail with
     * a reason that only ever reached `gates_mail_log`. Both look identical from a
     * browser: nothing arrives.
     *
     * @return array<string,string|int>
     */
    public static function status(): array
    {
        $out = [];
        $mailer = self::transport();
        $out['smtp_configured'] = ($mailer !== null && $mailer->smtpConfigured()) ? 'yes' : 'NO';

        try {
            $since = Carbon::now()->subDay()->toDateTimeString();
            $out['mail_sent_24h']   = (int) DB::table('gates_mail_log')->where('status', 'sent')->where('created_at', '>=', $since)->count();
            $out['mail_failed_24h'] = (int) DB::table('gates_mail_log')->where('status', 'failed')->where('created_at', '>=', $since)->count();
            $last = DB::table('gates_mail_log')->where('status', 'sent')->max('created_at');
            $out['last_successful_send'] = $last !== null ? (string) $last : 'NEVER — no email has ever been delivered';
            $fail = DB::table('gates_mail_log')->where('status', 'failed')->orderByDesc('id')->first();
            if ($fail) {
                $out['last_failure']        = (string) ($fail->created_at ?? '');
                $out['last_failure_reason'] = mb_substr((string) ($fail->error ?? '(no reason recorded)'), 0, 160);
            }
        } catch (\Throwable $e) {
            $out['mail_log'] = 'unreadable (' . $e->getMessage() . ')';
        }

        try {
            $out['receipts_owed'] = (int) DB::table('gates_donations')
                ->where('tier', 'paid-vote')->where('status', 'confirmed')
                ->whereNull('receipt_sent_at')->whereNull('refunded_at')->count();
            // The SAME predicate the sender uses. A health count that answers a
            // different question than the job it reports on is worse than no count:
            // it reads as confirmation while describing a different set of rows.
            $out['abandoned_awaiting_mail'] = (int) PaidVoteService::whereCheckoutDead(
                DB::table('gates_donations')
                    ->where('status', 'pending')->whereNull('abandoned_mail_at'))
                ->where('created_at', '>=', Carbon::now()->subHours(self::WINDOW_HOURS)->toDateTimeString())
                ->count();
        } catch (\Throwable) {
            $out['receipts_owed'] = 'unknown (run the migrations)';
        }
        return $out;
    }
}
