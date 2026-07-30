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
     */
    public const GRACE_MINUTES = 45;

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
            $settings = [];
            try {
                $settings = DB::table('gates_settings')->pluck('value', 'key_name')->all();
            } catch (\Throwable) {}
            $pick = static fn (string $key, string $env, string $dft): string =>
                trim((string) ($settings[$key] ?? '')) ?: (string) Env::get($env, $dft);

            self::$transport = new OtpService([
                'host'         => Env::get('SMTP_HOST', 'smtp-relay.brevo.com'),
                'port'         => Env::int('SMTP_PORT', 587),
                'username'     => Env::get('SMTP_USER', ''),
                'password'     => Env::get('SMTP_PASS', ''),
                'from_address' => $pick('mail_from_address', 'MAIL_FROM_ADDRESS', 'noreply@afrovanguard.org.ng'),
                'from_name'    => $pick('mail_from_name', 'MAIL_FROM_NAME', 'Africa GATES'),
                'reply_to'     => $pick('mail_reply_to', 'MAIL_REPLY_TO', ''),
            ]);
        } catch (\Throwable) {
            self::$transport = null;
        }
        return self::$transport;
    }

    /* ══════════════════════════════════════════════════════════════════════
       RECEIPT — sent once, when a paid-vote order confirms
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
    public static function receipt(int $donationId): array
    {
        try {
            $don = DB::table('gates_donations')->where('id', $donationId)->first();
            if (!$don)                                       return ['sent' => false, 'reason' => 'not_found'];
            if ((string) ($don->tier ?? '') !== 'paid-vote') return ['sent' => false, 'reason' => 'not_paid_vote'];
            if ((string) ($don->status ?? '') !== 'confirmed') return ['sent' => false, 'reason' => 'not_confirmed'];
            if (!empty($don->refunded_at))                   return ['sent' => false, 'reason' => 'refunded'];

            $to = strtolower(trim((string) ($don->donor_email ?? '')));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL))     return ['sent' => false, 'reason' => 'no_email'];

            $mailer = self::transport();
            if ($mailer === null)                            return ['sent' => false, 'reason' => 'no_transport'];

            // Claim before sending: the callback and the webhook can both be here at
            // once, and one payment must not produce two receipts.
            if (!self::claim($donationId, 'receipt_sent_at')) return ['sent' => false, 'reason' => 'already_sent'];

            $minted = (int) ($don->votes_used ?? 0) > 0;
            $mail   = $minted ? self::mintedBody($don) : self::unmintedBody($don);

            $r = $mailer->sendBranded($to, $mail['subject'], $mail['html'], $mail['text'], 'Paid votes', $mail['hero'] ?? '');
            if (empty($r['success'])) {
                // Nothing reached a mail server. Give the claim back so a later
                // confirm, reconcile or resend can try again — an unsent receipt for a
                // payment that DID complete is the worse of the two failures.
                self::release($donationId, 'receipt_sent_at');
                return ['sent' => false, 'reason' => 'send_failed', 'kind' => $minted ? 'minted' : 'unminted'];
            }
            return ['sent' => true, 'kind' => $minted ? 'minted' : 'unminted'];
        } catch (\Throwable $e) {
            error_log('[CheckoutMailer] receipt failed: ' . $e->getMessage());
            return ['sent' => false, 'reason' => 'error'];
        }
    }

    /** "Your votes are in" — the ordinary, happy receipt. */
    private static function mintedBody(object $don): array
    {
        $e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $qty   = (int) $don->bonus_votes;
        $votes = number_format($qty);
        $total = '₦' . number_format((int) $don->amount_naira);
        $ref   = (string) ($don->payment_ref ?? '');
        $nom   = self::nominee((int) ($don->intent_nominee_id ?? 0));
        $name  = $nom['name'] !== '' ? $nom['name'] : 'your nominee';
        $ballot = $nom['url'];

        $html = '<p style="margin:0 0 14px;font-size:15px;color:#374151">Hi <strong>' . $e($don->donor_name) . '</strong>,</p>'
            . '<p style="margin:0 0 6px;font-size:15px;color:#374151">Your payment is confirmed and your votes are '
            . 'already counted in the public tally.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;background:#f0fdf4;'
            . 'border-left:4px solid #22c55e;border-radius:0 10px 10px 0;padding:16px 20px">'
            . '<tr><td style="font-size:15px;color:#166534;line-height:1.8">'
            . 'Votes added: <strong style="color:#14532d">' . $votes . '</strong><br>'
            . 'For: <strong>' . $e($name) . '</strong>'
            . ($nom['category'] !== '' ? '<br>Category: <strong>' . $e($nom['category']) . '</strong>' : '')
            . '<br>Amount paid: <strong>' . $total . '</strong>'
            . '</td></tr></table>'
            . '<p style="margin:0 0 18px;font-size:15px;color:#374151">The fastest way to add to this is to send the ballot '
            . 'to people who would back ' . $e($name) . ' too — every share is worth more than the last vote you bought.</p>'
            . '<p style="text-align:center;margin:24px 0"><a href="' . $e($ballot) . '" '
            . 'style="display:inline-block;padding:13px 30px;background:#10292C;color:#fff;border-radius:999px;'
            . 'font-weight:700;text-decoration:none;font-size:15px">See the live standing &rarr;</a></p>'
            . '<p style="margin:0;font-size:12.5px;color:#9ca3af;font-family:monospace">Receipt ' . $e($ref) . '</p>'
            . '<p style="margin:10px 0 0;font-size:13px;color:#9ca3af">Purchased votes carry weight in the public tally. '
            . 'The jury score and the organic community signal that feed the Cultural Power Index are separate and are '
            . 'never moved by money — keep this receipt for your records.</p>';

        $text = "Hi {$don->donor_name},\n\nYour payment is confirmed and {$votes} vote(s) for {$name} are now in the "
            . "public tally.\n\nAmount paid: {$total}\nReceipt: {$ref}\n\nSee the live standing: {$ballot}\n\n"
            . "— Africa GATES";

        return [
            // "for your nominee" is a fine fallback mid-sentence and clumsy in a subject
            // line — and the fallback fires exactly when a nominee has since been deleted
            // or merged away, which is when an old order is most likely to be receipted.
            'subject' => $nom['name'] === ''
                ? ($qty === 1 ? 'Your vote is confirmed' : "Your {$votes} votes are confirmed")
                : ($qty === 1 ? "Your vote for {$name} is confirmed" : "Your {$votes} votes for {$name} are confirmed"),
            'html'    => $html,
            'text'    => $text,
            'hero'    => SiteUrl::base() . '/assets/img/illustrations/illo-ballot-countdown.jpg',
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
        $e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $votes = number_format((int) $don->bonus_votes);
        $total = '₦' . number_format((int) $don->amount_naira);
        $ref   = (string) ($don->payment_ref ?? '');
        $nom   = self::nominee((int) ($don->intent_nominee_id ?? 0));
        $name  = $nom['name'] !== '' ? $nom['name'] : 'your nominee';
        $help  = SiteUrl::base() . '/help';

        $html = '<p style="margin:0 0 14px;font-size:15px;color:#374151">Hi <strong>' . $e($don->donor_name) . '</strong>,</p>'
            . '<p style="margin:0 0 6px;font-size:15px;color:#374151">We received your payment of <strong>' . $total
            . '</strong> &mdash; but the ' . $votes . ' vote(s) for <strong>' . $e($name) . '</strong> could <strong>not</strong> '
            . 'be added, so we owe you a refund.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;background:#fffbeb;'
            . 'border-left:4px solid #f59e0b;border-radius:0 10px 10px 0;padding:16px 20px">'
            . '<tr><td style="font-size:14.5px;color:#92400e;line-height:1.8">'
            . 'The most common cause is timing: voting closed for this category between starting the payment and the '
            . 'gateway confirming it. We do not add votes after a category closes, for any amount &mdash; that rule is '
            . 'what makes every published result defensible.<br><br>'
            . 'Amount paid: <strong>' . $total . '</strong><br>Reference: <strong style="font-family:monospace">'
            . $e($ref) . '</strong>'
            . '</td></tr></table>'
            . '<p style="margin:0 0 18px;font-size:15px;color:#374151">Reply to this email with that reference and we will '
            . 'process the refund. Nothing further is needed from you &mdash; this order is already flagged on our side.</p>'
            . '<p style="margin:0;font-size:13px;color:#9ca3af">More on how voting windows work: '
            . '<a href="' . $e($help) . '" style="color:#10785a">' . $e($help) . '</a></p>';

        $text = "Hi {$don->donor_name},\n\nWe received your payment of {$total}, but the {$votes} vote(s) for {$name} "
            . "could NOT be added — most often because voting closed for the category between your payment starting and "
            . "the gateway confirming it.\n\nReference: {$ref}\n\nReply to this email with that reference and we will "
            . "process your refund.\n\n— Africa GATES";

        return [
            'subject' => 'Payment received — but your votes could not be added',
            'html'    => $html,
            'text'    => $text,
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
            $newest = $now->copy()->subMinutes(self::GRACE_MINUTES)->toDateTimeString();
            $oldest = $now->copy()->subHours(self::WINDOW_HOURS)->toDateTimeString();

            $rows = DB::table('gates_donations')
                ->where('status', 'pending')
                ->whereNull('abandoned_mail_at')
                ->where('created_at', '<=', $newest)
                ->where('created_at', '>=', $oldest)
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
            $cta     = SiteUrl::base() . '/donate';
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
            $out['abandoned_awaiting_mail'] = (int) DB::table('gates_donations')
                ->where('status', 'pending')->whereNull('abandoned_mail_at')
                ->where('created_at', '<=', Carbon::now()->subMinutes(self::GRACE_MINUTES)->toDateTimeString())
                ->where('created_at', '>=', Carbon::now()->subHours(self::WINDOW_HOURS)->toDateTimeString())
                ->count();
        } catch (\Throwable) {
            $out['receipts_owed'] = 'unknown (run the migrations)';
        }
        return $out;
    }
}
