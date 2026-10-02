<?php
declare(strict_types=1);

namespace AfricaGates\Services\Newsletter;

use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\Mail\Suppression;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\Accent;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Who the newsletter may reach — the one definition, for the sender, the admin counts and
 * the voting reminder alike.
 *
 * ── A RECIPIENT IS SOMEBODY WHO CONFIRMED, AND HAS NOT SINCE SAID STOP ───────
 *
 * Three facts decide it and all three are read here:
 *
 *   `gates_newsletter.confirmed_at`     they pressed the button in the confirmation mail;
 *   `gates_newsletter.unsubscribed_at`  the list's own stop (older rows, PrivacyPurge);
 *   `gates_email_optout`                the global stop every bulk mail's footer writes.
 *
 * Two readers of this rule is how one of them mails somebody the other has already
 * excluded, so nothing else builds a newsletter recipient query.
 *
 * ── THE CONFIRMATION LINK IS SIGNED, NOT STORED ──────────────────────────────
 *
 * Same construction as the unsubscribe link: the address travels in the URL and an HMAC
 * of it authenticates it, under a different purpose so neither token opens the other's
 * door. Nothing to look up, nothing to leak, and a guessed link cannot confirm somebody
 * else's address.
 */
final class NewsletterAudience
{
    private const PURPOSE = 'newsletter-confirm';

    /**
     * One confirmation mail per address per day, however often the form is posted.
     *
     * The form takes anybody's address, so without this it is a way to make this platform
     * mail a stranger on demand. A real person who lost the first message can ask again
     * tomorrow; somebody scripting the form gets one message per victim per day, which is
     * not worth scripting.
     */
    public const RESEND_HOURS = 24;

    /**
     * Rows that are not newsletter subscriptions at all.
     *
     * `gates_newsletter` also holds the "email me when the call opens" requests from an
     * unpublished stand call ({@see \AfricaGates\Services\StandCallNotice::source()}).
     * Those people asked about ONE event. Sending them a newsletter confirmation — or, as
     * a legacy row, a "confirm to keep receiving it" — would sign them up for something
     * they never asked for, in our words rather than theirs.
     */
    private const NOT_A_SUBSCRIPTION = 'stands:';

    public static function isSubscription(?string $source): bool
    {
        return !str_starts_with((string) $source, self::NOT_A_SUBSCRIPTION);
    }

    // ══ joining ══════════════════════════════════════════════════════════════

    /**
     * Record a signup and, where it is warranted, send the confirmation.
     *
     * The response to the person is the same whatever happened — new, already confirmed,
     * throttled — because a form that answers differently is a membership lookup.
     *
     * @param callable(string $to, string $subject, string $html, string $plain): bool|null $send
     * @return string 'new' | 'pending' | 'confirmed' | 'invalid' — for the caller's log and tests
     */
    public static function join(string $email, string $source, ?string $ipHash, string $site, ?callable $send): string
    {
        $email = EmailOptOut::normalise($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'invalid';

        $hash = EmailOptOut::hash($email);
        $now  = Carbon::now()->toDateTimeString();
        $row  = DB::table('gates_newsletter')->where('email_hash', $hash)->first();

        if ($row === null) {
            try {
                DB::table('gates_newsletter')->insert([
                    'email_hash' => $hash, 'email' => $email, 'ip_hash' => $ipHash,
                    'source' => mb_substr($source !== '' ? $source : 'homepage', 0, 50),
                    'subscribed_at' => $now,
                ]);
            } catch (\Throwable) {
                // A concurrent submit of the same address won the UNIQUE key. Same person,
                // same outcome — read the row it wrote.
            }
            $row   = DB::table('gates_newsletter')->where('email_hash', $hash)->first();
            $state = 'new';
        } else {
            $state = 'pending';
        }

        // Somebody already confirmed and still subscribed has nothing to do, and must not be
        // mailed again by a stranger re-typing their address.
        if ($row && $row->confirmed_at !== null && $row->unsubscribed_at === null
            && !EmailOptOut::suppressed($email)) {
            return 'confirmed';
        }

        // A stand-call request is recorded and nothing more: its own notice goes out when
        // the call opens, and that is all this person asked for.
        if (!self::isSubscription($source)) return $state;

        $recent = $row && $row->confirm_sent_at !== null
            && Carbon::parse((string) $row->confirm_sent_at)->gt(Carbon::now()->subHours(self::RESEND_HOURS));
        if (!$recent && $send !== null && $site !== '') {
            self::askToConfirm($email, $site, $send, false);
        }
        return $state;
    }

    /**
     * Send the confirmation mail and stamp that it went.
     *
     * Stamped whether or not the transport accepted it. A rejected send is the mail-health
     * monitor's business; stamping only successes would let a broken transport turn the
     * once-only reconfirmation into a retry loop against the whole legacy list.
     *
     * @param callable(string, string, string, string): bool $send
     */
    public static function askToConfirm(string $email, string $site, callable $send, bool $existing): bool
    {
        [$subject, $html, $plain] = self::confirmMail($email, $site, $existing);
        $ok = false;
        try {
            $ok = (bool) $send($email, $subject, $html, $plain);
        } catch (\Throwable) {
            $ok = false;
        }
        DB::table('gates_newsletter')->where('email_hash', EmailOptOut::hash($email))
            ->update(['confirm_sent_at' => Carbon::now()->toDateTimeString()]);
        return $ok;
    }

    /**
     * The words of the confirmation request.
     *
     * Two versions, and both are literally true. A new signup is told that nothing arrives
     * without confirming. A subscriber from before confirmation existed is told why they
     * are being asked, and — because their welcome promised that ignoring it meant never
     * hearing from us again — that ignoring this one means exactly that.
     *
     * @return array{0:string,1:string,2:string} subject, HTML body, plain text
     */
    public static function confirmMail(string $email, string $site, bool $existing): array
    {
        $url = self::confirmUrl($site, $email);
        $e   = htmlspecialchars($url, ENT_QUOTES);

        $lede = $existing
            ? 'You signed up for Africa GATES updates. We now send a regular newsletter — what is open for nominations, when voting closes, results as they are announced, and events — and only to people who confirm they want it.'
            : 'Somebody — we hope you — asked for the Africa GATES newsletter at this address. It covers what is open for nominations, when voting closes, results as they are announced, and events.';
        $after = $existing
            ? 'If you would rather not, ignore this email. We will not ask again, and you will not receive the newsletter.'
            : 'If it was not you, ignore this email. Nothing is sent to an address that has not been confirmed.';

        // Colours from the ramp, as values — see Newsletter::palette() for why mail carries
        // literals at all.
        [$ink, $ink2, $soft] = [Accent::neutral('ink'), Accent::neutral('ink-2'), Accent::neutral('ink-soft')];
        $go = Accent::fill(Accent::ACTION);
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">Confirm your subscription</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . htmlspecialchars($lede, ENT_QUOTES) . '</p>'
              . '<p style="text-align:center;margin:24px 0"><a href="' . $e . '" style="display:inline-block;padding:13px 28px;background:' . $go . ';color:' . Accent::SURFACE . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">Yes, send me the newsletter</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . $soft . '">' . htmlspecialchars($after, ENT_QUOTES) . '</p>';

        $plain = "Confirm your subscription\n\n{$lede}\n\nConfirm here: {$url}\n\n{$after}";

        return ['Confirm your Africa GATES newsletter subscription', $html, $plain];
    }

    /**
     * The confirmation request's transport: the branded shell, because this is one message
     * to one person who asked for it, not a campaign — so no list headers either.
     *
     * @return callable(string, string, string, string): bool
     */
    public static function transport(OtpService $mailer): callable
    {
        return static fn(string $to, string $subject, string $html, string $plain): bool
            => (bool) ($mailer->sendBranded($to, $subject, $html, $plain, 'Newsletter')['success'] ?? false);
    }

    public static function confirmUrl(string $site, string $email): string
    {
        return rtrim($site, '/') . '/email/confirm?e=' . EmailOptOut::encode($email)
             . '&t=' . self::token($email);
    }

    public static function token(string $email): string
    {
        return EmailOptOut::sign($email, self::PURPOSE);
    }

    /** The address a confirmation link authenticates, or null. */
    public static function verify(string $encoded, string $token): ?string
    {
        $email = EmailOptOut::decode($encoded);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        $token = strtolower(preg_replace('/[^a-f0-9]/i', '', $token) ?? '');
        if (strlen($token) !== 32) return null;

        return hash_equals(self::token($email), $token) ? $email : null;
    }

    /**
     * Confirm an address.
     *
     * This also LIFTS a global opt-out for it, and that is deliberate: pressing a signed
     * link that only arrives in this inbox is a yes given more recently than whatever no
     * came before it. Without this, somebody who once unsubscribed could never come back —
     * the form would accept them, the mail would ask them, the button would answer them,
     * and the sender would go on excluding them for ever with nothing on any page to say why.
     */
    public static function confirm(string $email): bool
    {
        $email = EmailOptOut::normalise($email);
        $hash  = EmailOptOut::hash($email);
        $now   = Carbon::now()->toDateTimeString();

        $row = DB::table('gates_newsletter')->where('email_hash', $hash)->first();
        if ($row === null) {
            // A valid link for a row PrivacyPurge has since removed. The link is still
            // proof of the inbox, so the subscription is simply recreated, confirmed.
            DB::table('gates_newsletter')->insert([
                'email_hash' => $hash, 'email' => $email, 'source' => 'confirm-link',
                'subscribed_at' => $now, 'confirmed_at' => $now,
            ]);
        } else {
            DB::table('gates_newsletter')->where('email_hash', $hash)->update([
                'confirmed_at'    => $row->confirmed_at ?? $now,
                'unsubscribed_at' => null,
            ]);
        }
        DB::table('gates_email_optout')->where('email_hash', $hash)->delete();
        // And a bounce on record: a link that only arrives in this inbox has just been
        // pressed, which is newer and better evidence than whatever bounced before.
        Suppression::lift($email);
        return true;
    }

    // ══ who is on it ═════════════════════════════════════════════════════════

    /**
     * Everybody the newsletter may be sent to, oldest confirmation first.
     *
     * @return list<array{email:string, hash:string, since:string}>
     */
    public static function recipients(): array
    {
        // A choice to stop, or evidence that the mailbox is gone or the reader complained.
        // The transport would refuse both anyway; leaving them off the list is what keeps
        // the issue's count of who it reached honest.
        $suppressed = EmailOptOut::suppressedHashes() + Suppression::hashes();
        $out = [];
        foreach (DB::table('gates_newsletter')->whereNotNull('confirmed_at')->whereNull('unsubscribed_at')
                     ->orderBy('confirmed_at')->orderBy('id')
                     ->get(['email', 'email_hash', 'confirmed_at']) as $r) {
            if (isset($suppressed[(string) $r->email_hash])) continue;
            if (!filter_var((string) $r->email, FILTER_VALIDATE_EMAIL)) continue;
            $out[] = ['email' => (string) $r->email, 'hash' => (string) $r->email_hash,
                      'since' => (string) $r->confirmed_at];
        }
        return $out;
    }

    /**
     * Subscribers from before confirmation existed who have not yet been asked. Each is
     * asked once; see the migration for why once.
     *
     * @return list<string>
     */
    public static function unasked(int $limit): array
    {
        $suppressed = EmailOptOut::suppressedHashes();
        $out = [];
        foreach (DB::table('gates_newsletter')->whereNull('confirmed_at')->whereNull('confirm_sent_at')
                     ->whereNull('unsubscribed_at')->orderBy('id')
                     ->select(['email', 'email_hash', 'source'])->cursor() as $r) {
            if (!self::isSubscription($r->source)) continue;
            if (isset($suppressed[(string) $r->email_hash])) continue;
            if (!filter_var((string) $r->email, FILTER_VALIDATE_EMAIL)) continue;
            $out[] = (string) $r->email;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** @return array{confirmed:int, awaiting:int, unasked:int, stopped:int, total:int} */
    public static function counts(): array
    {
        $out = ['confirmed' => 0, 'awaiting' => 0, 'unasked' => 0, 'stopped' => 0, 'total' => 0];
        try {
            $suppressed = EmailOptOut::suppressedHashes() + Suppression::hashes();
            foreach (DB::table('gates_newsletter')
                         ->get(['email_hash', 'source', 'confirmed_at', 'confirm_sent_at', 'unsubscribed_at']) as $r) {
                if (!self::isSubscription($r->source) && $r->confirmed_at === null) continue;
                $out['total']++;
                if ($r->unsubscribed_at !== null || isset($suppressed[(string) $r->email_hash])) {
                    $out['stopped']++;
                } elseif ($r->confirmed_at !== null) {
                    $out['confirmed']++;
                } elseif ($r->confirm_sent_at !== null) {
                    $out['awaiting']++;
                } else {
                    $out['unasked']++;
                }
            }
        } catch (\Throwable) {
            // Before the migration: an empty list is the honest count.
        }
        return $out;
    }
}
