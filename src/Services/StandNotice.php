<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Telling a vendor what happened to their stand application.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DEFECT THIS FIXES, WHICH WAS THE WORST ONE IN THE SUBSYSTEM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A decision was written to `gates_stand_applications` and NOBODY WAS TOLD. Not on an
 * offer, not on a rejection, not on a waitlisting, and not when an offer expired. There
 * was no mail anywhere in {@see StandApplication} and no job for one.
 *
 * The consequence is not a missing nicety, it is the whole flow failing quietly:
 *
 *  · An offer starts a {@see StandApplication::OFFER_HOURS}-hour acceptance clock the
 *    moment an organiser presses Record. The vendor could only find out by logging in
 *    unprompted. When the clock ran out, `Maintenance::expireStandOffers()` released the
 *    pitch to the waiting list — silently again. So the most likely outcome of a
 *    successful application was losing the place without ever knowing it was offered.
 *
 *  · A rejection is refused server-side without a reason, on purpose, so that an
 *    applicant is owed an explanation. The reason was then stored and never sent.
 *
 * And the public application page promises, in these words: "You hear either way, with a
 * reason." So the platform stated a specific commitment and kept none of it, to small
 * businesses who had gathered certificates on the strength of it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * FOUR DESIGN DECISIONS WORTH THE READING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · QUEUED, NEVER SENT IN THE REQUEST. Deciding is one click on a table of two hundred
 *     rows. An SMTP connection inside that click makes the click slow, and an SMTP failure
 *     inside it would either lose the decision or record it and lie about the mail. There
 *     is no worker process on this host, so the maintenance tick is the worker — the same
 *     arrangement {@see QuestionnaireInvites} uses and for the same reason. The expiry
 *     sweep can generate dozens at once and must not run at SMTP speed.
 *
 * 2 · SUPPRESSION IS HONOURED FOR NEWS AND OVERRIDDEN FOR CONSEQUENCES. An opt-out is a
 *     request to stop being marketed at; it is not a request to forfeit a pitch. So a
 *     waiting-list notice respects it and an OFFER does not, because an unsent offer
 *     expires and costs the person the thing they applied for. A rejection also goes:
 *     somebody who is waiting to hear is entitled to know they can stop waiting. This is
 *     the one place on the platform that overrides suppression, and it is stated here
 *     rather than left as behaviour to be discovered.
 *
 * 3 · ONE MESSAGE PER APPLICATION PER OUTCOME. `gates_broadcast_log` is unique on
 *     (campaign, email_hash), and the campaign carries the DECISION as well as the
 *     application id. Pressing Record twice sends one mail; re-offering a pitch after an
 *     expiry legitimately sends another, because it is a different outcome.
 *
 * 4 · NO ACCEPTANCE RECEIPT. A vendor who accepts pressed the button and saw the screen
 *     change; a mail saying "you accepted" tells them what they already know. The fee and
 *     the invoice are on their dashboard, which the offer mail links to.
 */
final class StandNotice
{
    public const JOB_NOTICE = 'stand.notice';

    /** The four outcomes worth a message. `accepted` is deliberately not one — see above. */
    public const KINDS = [
        'offered'    => 'You have been offered a stand',
        'rejected'   => 'About your stand application',
        'waitlisted' => 'You are on the waiting list for a stand',
        'expired'    => 'Your stand offer has run out',
    ];

    // ═══════════════════════════════════════════════════════════════════════
    // QUEUEING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Queue the notice for one application.
     *
     * Takes the application id and NOT the assembled message, so the job re-reads the row
     * when it runs. Between the press and the tick a vendor may have accepted, or the
     * decision may have been changed again — and a queued copy of the old text would tell
     * them something that is no longer true. {@see deliver()} re-checks.
     *
     * Returns the job id, or 0 when one is already queued for this exact outcome.
     */
    public static function queue(int $appId, string $kind, ?QueueService $q = null): int
    {
        if ($appId < 1 || !isset(self::KINDS[$kind])) return 0;

        try {
            return ($q ?? new QueueService())->push(
                self::JOB_NOTICE,
                ['app_id' => $appId, 'kind' => $kind],
                0,
                // Per application AND per outcome. Keyed on the application alone, a vendor
                // offered a pitch, expired, and offered again would get one mail out of
                // three.
                self::JOB_NOTICE . ':' . $appId . ':' . $kind
            );
        } catch (\Throwable $e) {
            // A decision must never fail because a mail could not be QUEUED. Recorded so
            // the absence is visible rather than inferred from a vendor complaining.
            error_log('[stand-notice] could not queue ' . $kind . ' for application '
                    . $appId . ': ' . $e->getMessage());
            return 0;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DELIVERING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Send one queued notice.
     *
     * @param array<string,mixed> $p
     */
    public static function deliver(array $p, ?OtpService $mailer = null): bool
    {
        // A throw and not a false, for the reason QuestionnaireInvites documents: returning
        // false marks the job DONE, so a deployment with briefly broken SMTP would consume
        // every pending offer notice and log them as handled. Throwing puts the job back.
        if ($mailer === null) {
            throw new \RuntimeException('stand notice: no mailer available');
        }

        $appId = (int) ($p['app_id'] ?? 0);
        $kind  = (string) ($p['kind'] ?? '');
        if ($appId < 1 || !isset(self::KINDS[$kind])) return false;

        $ctx = self::context($appId);
        if ($ctx === null) return false;

        // ── HAS THE WORLD MOVED SINCE THIS WAS QUEUED? ──────────────────────
        //
        // The tick runs minutes later. An offer the vendor has already accepted must not
        // produce a mail with a countdown in it, and a decision changed twice must not send
        // the superseded one. The stored decision is the authority, not the payload.
        $now = (string) $ctx['app']->decision;
        $expected = $kind === 'expired' ? StandApplication::DECISION_WAITLIST : $kind;
        if ($now !== $expected) return false;

        $email = trim((string) ($ctx['org']->contact_email ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log('[stand-notice] application ' . $appId . ' has no usable email');
            return false;
        }

        // See design note 2. News respects the opt-out; consequences do not.
        if (in_array($kind, ['waitlisted', 'expired'], true) && EmailOptOut::suppressed($email)) {
            return false;
        }

        $campaign = 'stand:' . $appId . ':' . $kind;
        if (self::alreadySent($campaign, $email)) return true;

        $site = rtrim(SiteUrl::base(), '/');
        $vars = self::vars($ctx, $kind, $site, $email);

        $res = $mailer->sendRawHtml(
            $email,
            (string) $vars['subject'],
            self::html($vars),
            self::plain($vars),
            'stand',
            // Present even on the mails that override suppression: the header is how a
            // mailbox provider distinguishes a legitimate sender, and Gmail's and Yahoo's
            // bulk rules do not read intent. A message in Spam has not been sent — which is
            // the failure this whole class exists to end.
            EmailOptOut::url($site, $email)
        );

        $ok = (bool) ($res['success'] ?? false);
        if ($ok) self::log($campaign, $email);

        return $ok;
    }

    /**
     * The application, its vendor, its stand type and its event, in one read.
     *
     * @return array{app:object, org:object, type:?object, event:?object}|null
     */
    private static function context(int $appId): ?array
    {
        try {
            $app = DB::table('gates_stand_applications')->where('id', $appId)->first();
            if (!$app) return null;

            $org = DB::table('gates_partner_orgs')->where('id', $app->org_id)->first();
            if (!$org) return null;

            return [
                'app'   => $app,
                'org'   => $org,
                'type'  => DB::table('gates_stand_types')->where('id', $app->stand_type_id)->first(),
                'event' => DB::table('gates_site_events')->where('id', $app->event_id)->first(),
            ];
        } catch (\Throwable $e) {
            error_log('[stand-notice] could not read application ' . $appId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array{app:object, org:object, type:?object, event:?object} $ctx
     * @return array<string,mixed>
     */
    public static function vars(array $ctx, string $kind, string $site, string $email): array
    {
        $app   = $ctx['app'];
        $org   = $ctx['org'];
        $type  = $ctx['type'];
        $event = $ctx['event'];

        $naira = static fn (int $n): string => '₦' . number_format($n);

        // The contact name if there is one, otherwise the trading name — never the legal
        // name, which on an individual is somebody's full name off an ID document and reads
        // as a letter from a bank.
        $who   = trim((string) ($org->contact_name ?? '')) ?: trim((string) ($org->name ?? ''));
        $first = trim(explode(' ', $who)[0] ?? '');

        $price   = (int) ($type->price_naira ?? 0);
        $deposit = (int) ($type->deposit_naira ?? 0);

        return [
            'kind'        => $kind,
            'subject'     => self::KINDS[$kind] . ' — ' . (string) ($event->title ?? 'Africa GATES'),
            'eyebrow'     => 'Your stand application',
            'preheader'   => self::preheader($kind, (int) ($app->id ?? 0)),
            'first_name'  => $first,
            'stand_name'  => (string) ($type->name ?? 'a stand'),
            'stand_size'  => $type ? StandPreset::labelForType($type) : '',
            'event_title' => (string) ($event->title ?? 'the event'),
            'event_date'  => !empty($event->event_date)
                ? date('j F Y', strtotime((string) $event->event_date)) : '',
            'reason'      => trim((string) ($app->decision_reason ?? '')),
            'offer_hours' => StandApplication::OFFER_HOURS,
            'expires_at'  => !empty($app->offer_expires_at)
                ? date('j F Y, g:ia', strtotime((string) $app->offer_expires_at)) : '',
            // The dashboard, in both cases. The accept button lives there — a one-click
            // accept link in an email would be a state change from a GET, and a mail scanner
            // prefetching it would accept a pitch on somebody's behalf.
            'link'            => $site . '/org',
            'price'           => $price > 0 ? $naira($price) : '',
            'deposit'         => $deposit > 0 ? $naira($deposit) : '',
            'site_url'        => $site,
            'unsubscribe_url' => EmailOptOut::url($site, $email),
            'postal_address'  => (string) \AfricaGates\Support\Env::get(
                'MAIL_POSTAL_ADDRESS', 'Afrovanguard, Lagos, Nigeria'),
        ];
    }

    /**
     * The inbox preview line.
     *
     * Written per outcome rather than reusing the subject, because the two together are the
     * whole message for somebody scanning a phone — and on an offer the useful second line
     * is the clock, not a restatement of the headline.
     */
    private static function preheader(string $kind, int $appId): string
    {
        return match ($kind) {
            'offered'    => 'The pitch is held for you. Accept it within '
                            . StandApplication::OFFER_HOURS . ' hours or it goes to the waiting list.',
            'rejected'   => 'Not this time, and here is the actual reason.',
            'expired'    => 'The pitch has gone to the waiting list. Your application still stands.',
            default      => 'Every pitch of this type is held. If one comes free you are in line for it.',
        };
    }

    /** @param array<string,mixed> $vars */
    public static function html(array $vars): string
    {
        // A bare Twig environment: the template uses plain variables only, so it does not
        // need the app's extensions — and not depending on them keeps this renderable from
        // a console with no HTTP request in flight.
        static $twig = null;
        $twig ??= new Environment(
            new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'),
            ['autoescape' => 'html']
        );

        return $twig->render('emails/stand-decision.twig', $vars);
    }

    /**
     * The plain-text alternative, written rather than strip_tags'd.
     *
     * `strip_tags` on that template yields the CSS, the MSO conditionals and a wall of
     * collapsed whitespace. The text/plain part is what a text-only client renders and what
     * every spam filter scoring multipart balance actually reads.
     *
     * @param array<string,mixed> $vars
     */
    public static function plain(array $vars): string
    {
        $who   = $vars['first_name'] !== '' ? $vars['first_name'] : 'Hello';
        $what  = $vars['stand_name'] . ($vars['stand_size'] !== '' ? ' (' . $vars['stand_size'] . ')' : '');
        $where = $vars['event_title'] . ($vars['event_date'] !== '' ? ', ' . $vars['event_date'] : '');

        $body = match ($vars['kind']) {
            'offered' => "You have been offered a stand at {$where}.\n\n"
                . "The pitch is {$what}, and it is held for you until "
                . $vars['expires_at'] . " — " . $vars['offer_hours'] . " hours from when the offer "
                . "was made, which was one of the published terms of the call. It is not yours "
                . "until you accept it. If the time runs out the place goes to the next "
                . "applicant on the waiting list.\n\n"
                . "Accept it on your dashboard: " . $vars['link'] . "\n"
                . ($vars['price'] !== ''
                    ? "\nThe pitch fee is " . $vars['price']
                      . ($vars['deposit'] !== '' ? ', of which ' . $vars['deposit'] . ' is due on acceptance' : '')
                      . ". This is the price published with the call.\n"
                    : ''),
            'rejected' => "We are not able to offer you a pitch at {$where} this time.\n\n"
                . "You applied for {$what}. You are owed the actual reason rather than a form "
                . "of words, so here it is as the panel wrote it:\n\n"
                . '  ' . ($vars['reason'] !== '' ? $vars['reason'] : 'No reason was recorded.') . "\n\n"
                . "Your dashboard: " . $vars['link'] . "\n",
            'expired' => "The stand we offered you at {$where} was not accepted before its "
                . $vars['offer_hours'] . " hours were up, so the pitch has gone to the waiting "
                . "list.\n\nYour application has NOT been withdrawn. You are back on that list, "
                . "and if a place comes free the longest-waiting applicant is offered it "
                . "first.\n\nYour dashboard: " . $vars['link'] . "\n",
            default => "Every pitch of the type you applied for at {$where} is currently held, "
                . "so you are on the waiting list.\n\nYou have not been turned down. If a place "
                . "comes free the longest-waiting applicant on this list is offered it first, "
                . "and that may be you.\n\nYour dashboard: " . $vars['link'] . "\n",
        };

        return "Dear {$who},\n\n" . $body . "\n"
             . "We will never ask you to pay anybody privately for a stand. Every payment "
             . "goes through the platform and appears on your dashboard. If somebody asks you "
             . "for money to secure a pitch, it is not us.\n\n"
             . "— The Africa GATES Team\n"
             . $vars['site_url'] . "\n"
             . $vars['postal_address'] . "\n"
             . "Unsubscribe: " . $vars['unsubscribe_url'] . "\n";
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ONCE, AND ONCE ONLY
    // ═══════════════════════════════════════════════════════════════════════

    private static function alreadySent(string $campaign, string $email): bool
    {
        try {
            return DB::table('gates_broadcast_log')
                ->where('campaign', $campaign)
                ->where('email_hash', self::hash($email))
                ->exists();
        } catch (\Throwable) {
            // A missing table must not turn into a duplicate storm OR a silent block. False
            // means "send it": one extra mail is recoverable, an unsent offer is not.
            return false;
        }
    }

    private static function log(string $campaign, string $email): void
    {
        try {
            DB::table('gates_broadcast_log')->updateOrInsert(
                ['campaign' => $campaign, 'email_hash' => self::hash($email)],
                ['sent_at' => date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            error_log('[stand-notice] could not log ' . $campaign . ': ' . $e->getMessage());
        }
    }

    private static function hash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /** How many notices are still waiting, for the screen that decided them. */
    public static function pending(): int
    {
        try {
            return (int) DB::table('gates_jobs')
                ->where('type', self::JOB_NOTICE)
                ->where('status', 'pending')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
