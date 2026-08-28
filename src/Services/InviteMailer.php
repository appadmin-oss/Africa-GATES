<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The invitation itself: what a guest of honour receives.
 *
 * ── WHY THIS HAS ITS OWN TEMPLATE ────────────────────────────────────────────
 *
 * The first cut rendered through `emails/campaign.twig`'s block list, and that was wrong.
 * That skeleton is built for a BROADCAST — a countdown hero, numbered asks, a green CTA —
 * and an invitation to be honoured in public is not a broadcast. It is addressed to one
 * person, it has one thing to say, and reusing a newsletter chassis for it produced
 * exactly what it sounds like.
 *
 * `emails/invitation.twig` is its own design and inherits only the SCAFFOLDING from that
 * skeleton — the fluid-hybrid 560px wrapper, the MSO conditional tables, the VML button,
 * styled alt text, presentation roles, the hidden preheader, no data: URIs. Those twelve
 * properties are the difference between a design and a design that ARRIVES, and
 * {@see \Tests\Unit\InviteInboxCompatTest} holds every one of them for this file too.
 *
 * ── WHAT IS ATTACHED, AND WHY IT IS BY VALUE ─────────────────────────────────
 *
 * A formal letter as PDF, generated per recipient, plus the event's cover artwork if it
 * has one. {@see OtpService::sendBranded()} takes attachments by VALUE and deliberately
 * not by path — "everything this platform attaches is generated for the message, and
 * accepting a path would make it possible to attach a file somebody else's request
 * named". The cover image is the one attachment read from disk, so it is read here,
 * bounded, and passed as bytes.
 *
 * ── THE PASS IS A LINK, NEVER AN ATTACHMENT ──────────────────────────────────
 *
 * The rotating code cannot live in a file. See {@see InviteLetter} for the full reason.
 */
final class InviteMailer
{
    /** The send log key, per event, so a re-run cannot write to somebody twice. */
    public static function campaignKey(int $eventId): string
    {
        return 'invite:' . $eventId;
    }

    /** Cap on the cover artwork. Past this an invitation starts bouncing on size. */
    private const MAX_COVER_BYTES = 2_500_000;

    /**
     * Send one invitation.
     *
     * @return array{ok:bool, error:string, skipped:bool}
     */
    public static function send(object $invite, object $event, ?OtpService $mailer = null): array
    {
        $email = (string) $invite->email;
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'No usable address.', 'skipped' => false];
        }
        if (EmailOptOut::suppressed($email)) {
            return ['ok' => false, 'error' => 'This address has opted out.', 'skipped' => true];
        }
        if (self::alreadySent((int) $event->id, $email)) {
            return ['ok' => false, 'error' => 'Already sent.', 'skipped' => true];
        }

        $mailer ??= OtpService::boot();
        $spec     = InviteAudience::spec((string) $invite->audience);
        $tier     = EventInvites::lowestTier((int) $event->id);
        $base     = rtrim(SiteUrl::base(), '/');

        $view  = self::view($invite, $event, $spec, $tier, $base);
        $html  = self::html($view);
        $plain = self::plain($view);

        $r = $mailer->sendBranded(
            $email,
            (string) $view['subject'],
            $html,
            $plain,
            'invitation',
            '',
            EmailOptOut::url($base, $email),
            self::attachments($invite, $event, $tier)
        );

        $ok    = (bool) ($r['success'] ?? false);
        $error = (string) ($r['error'] ?? '');
        self::log((int) $event->id, $invite, $ok, $error);

        if ($ok) {
            try {
                DB::table('gates_event_invites')->where('id', $invite->id)
                    ->update(['sent_at' => Carbon::now()->toDateTimeString()]);
            } catch (\Throwable) {}
        }

        return ['ok' => $ok, 'error' => $error, 'skipped' => false];
    }

    /** The rendered HTML, for the admin's preview. Sends nothing. */
    public static function preview(object $invite, object $event): string
    {
        return self::html(self::view(
            $invite,
            $event,
            InviteAudience::spec((string) $invite->audience),
            EventInvites::lowestTier((int) $event->id),
            rtrim(SiteUrl::base(), '/')
        ));
    }






    /**
     * Everything the template renders, resolved once.
     *
     * One array rather than a call per field, so the HTML and the plain-text part cannot
     * end up describing different evenings — which is the failure mode of building each
     * of them separately from the same row.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private static function view(object $invite, object $event, array $spec, ?object $tier, string $base): array
    {
        $quota = (int) $invite->guest_quota;
        $pct   = InviteAudience::discountPercent();

        $where = trim(implode(', ', array_filter([
            trim((string) ($event->venue ?? '')),
            trim((string) ($event->location ?? '')),
        ])));

        return [
            // Their own name in the subject, because this is not a campaign — it is an
            // invitation to one person, and the inbox has to read that way at a glance.
            'subject'   => trim((string) $invite->name) . ', you are invited to ' . trim((string) $event->title),
            // The line an inbox shows beside the subject. It carries the ASK, because the
            // subject already carries the invitation and repeating it wastes the one
            // sentence somebody reads before deciding whether to open.
            'preheader' => 'As a guest of honour — with ' . $quota
                         . ' discounted seats for the people you bring.',

            'name'         => trim((string) $invite->name),
            // "Dear". Per audience, and it has to be HERE: the template opens the letter
            // with `{{ salutation }} {{ name }},` and a key the view does not supply
            // renders as empty — so the letter would greet somebody with a leading space
            // and no salutation at all, which is the kind of fault that only ever shows up
            // in a real inbox.
            'salutation'   => (string) $spec['salutation'],
            'audience_one' => (string) $spec['one'],
            'witness'      => (string) $spec['witness'],

            'event_title' => trim((string) $event->title),
            // ── THE DATE, IN THREE PARTS ─────────────────────────────────────
            //
            // It arrived as one pre-formatted string — "Saturday 12 December 2026 at
            // 18:00" — and was rendered as 15px body copy under a 10px label, which made
            // the single most important fact on an invitation the least prominent thing in
            // it. An invitation exists to say WHEN.
            //
            // Split so the template can set it as a typographic object rather than a
            // sentence: the weekday as a tracked micro-label, the date large, the time and
            // zone quiet beside the venue. `when` is kept whole for the plain-text part,
            // where there is no typography and a sentence is the right shape.
            'when'        => DisplayTime::showZoned((string) $event->event_date, 'l j F Y \a\t H:i'),
            'when_day'    => DisplayTime::show((string) $event->event_date, 'l'),
            'when_date'   => DisplayTime::show((string) $event->event_date, 'j F Y'),
            'when_time'   => DisplayTime::showZoned((string) $event->event_date, 'H:i'),
            'where'       => $where,
            'cover_url'   => self::coverUrl($event, $base),

            'reference' => trim((string) $invite->reference),
            'quota'     => $quota,
            'discount'  => $pct,
            // Prefixed here rather than in the template, so the sentence reads correctly
            // both with a tier and without one.
            'tier_line' => $tier !== null
                ? ', from ₦' . number_format((int) $tier->price_naira)
                  . ' (' . trim((string) $tier->name) . ') upwards'
                : '',

            'id_url'          => EventInvites::idUrl((string) $invite->reference, $base),
            'events_url'      => $base . '/events/' . rawurlencode((string) $event->slug),
            'unsubscribe_url' => EmailOptOut::url($base, (string) $invite->email),
            'postal_address'  => (string) \AfricaGates\Support\Env::get(
                'MAIL_POSTAL_ADDRESS', 'Afrovanguard, Lagos, Nigeria'
            ),
        ];
    }

    /** @param array<string,mixed> $view */
    private static function html(array $view): string
    {
        static $twig = null;
        $twig ??= new Environment(
            new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'),
            ['autoescape' => 'html']
        );

        return $twig->render('emails/invitation.twig', $view);
    }

    /**
     * The plain-text part, WRITTEN rather than stripped.
     *
     * `strip_tags()` over a table layout produces a column of orphaned words. This part is
     * what a plain-text client shows, what a screen reader may be handed, and what every
     * spam filter reads to decide whether the HTML half is worth trusting.
     *
     * @param array<string,mixed> $view
     */
    private static function plain(array $view): string
    {
        return implode("\n", [
            'AFRICA GATES — An Afrovanguard Initiative',
            '',
            'You are invited to ' . $view['event_title'] . ', as a guest of honour.',
            '',
            // Greeted, not merely named. A bare line carrying somebody's name reads as a
            // record header; this is a letter.
            $view['salutation'] . ' ' . $view['name'] . ',',
            '',
            // The witness sentence WHOLE, exactly as the HTML half renders it. It used to
            // be prefixed here too — "We want the hall packed " plus the operator's
            // fragment plus a trailing clause — and the fragment is now a complete
            // sentence, so a prefix here would send the plain-text reader "We want the
            // hall packed We want the hall packed to witness…". One sentence, one author:
            // that is the whole reason the default moved into InviteAudience.
            (string) $view['witness'],
            'We would like the people who know that work best to be in the room to see it',
            'recognised.',
            '',
            'WHEN   ' . $view['when'],
            'WHERE  ' . ($view['where'] !== '' ? $view['where'] : 'Venue to be confirmed'),
            '',
            'YOUR ENTRY',
            'Arranged already — no ticket needed for you. Your pass is a live page; open it on',
            'your phone at the door:',
            '  ' . $view['id_url'],
            '',
            'BRING YOUR PEOPLE',
            '  ' . $view['reference'] . '  —  ' . $view['discount'] . '% off, up to '
                . $view['quota'] . ' guests',
            'Share it with the people you want in the room. It takes ' . $view['discount']
                . '% off their tickets, for up to ' . $view['quota'] . ' of them'
                . $view['tier_line'] . '.',
            '  ' . $view['events_url'],
            '',
            'We would be honoured to have you with us. The formal invitation is attached, for',
            'your records.',
            '',
            'With respect,',
            'Africa GATES',
            '',
            '--',
            (string) $view['postal_address'],
            'Stop receiving email: ' . $view['unsubscribe_url'],
        ]);
    }

    /** The cover as an ABSOLUTE url, or '' — a relative src is a broken image in an inbox. */
    private static function coverUrl(object $event, string $base): string
    {
        $rel = trim((string) ($event->cover_image ?? ''));
        if ($rel === '') return '';
        if (preg_match('~^https?://~i', $rel) === 1) return $rel;

        return $base . '/' . ltrim($rel, '/');
    }

    // ══ attachments ══════════════════════════════════════════════════════════

    /** @return list<array{name:string, mime:string, body:string}> */
    private static function attachments(object $invite, object $event, ?object $tier): array
    {
        $out = [];

        // The letter. Generated per recipient, so it carries their name and reference.
        try {
            $out[] = [
                'name' => InviteLetter::fileName($invite),
                'mime' => 'application/pdf',
                'body' => InviteLetter::render($invite, $event, $tier),
            ];
        } catch (\Throwable) {
            // An invitation with no letter is still an invitation, and the email carries
            // every fact the letter does. Losing the whole send over a font path would be
            // the worse outcome.
        }

        $cover = self::cover($event);
        if ($cover !== null) $out[] = $cover;

        return $out;
    }

    /**
     * The event artwork, read from disk and bounded.
     *
     * Path-checked against the uploads root with realpath rather than trusted: the value
     * is an admin-entered column, and an attachment builder that resolves whatever string
     * it is handed is a file-disclosure primitive pointed at its own mail queue.
     *
     * @return array{name:string, mime:string, body:string}|null
     */
    private static function cover(object $event): ?array
    {
        $rel = trim((string) ($event->cover_image ?? ''));
        if ($rel === '' || str_contains($rel, "\0")) return null;
        if (preg_match('~^https?://~i', $rel) === 1) return null;   // hosted elsewhere: let the HTML link it

        $root = realpath(\dirname(__DIR__, 2) . '/public');
        $full = realpath(\dirname(__DIR__, 2) . '/public/' . ltrim($rel, '/'));
        if ($root === false || $full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!is_file($full) || filesize($full) > self::MAX_COVER_BYTES) return null;

        $mime = match (strtolower((string) pathinfo($full, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            default       => '',
        };
        if ($mime === '') return null;

        $bytes = @file_get_contents($full);
        if ($bytes === false || $bytes === '') return null;

        return ['name' => basename($full), 'mime' => $mime, 'body' => $bytes];
    }

    // ══ the log ══════════════════════════════════════════════════════════════

    private static function alreadySent(int $eventId, string $email): bool
    {
        try {
            return DB::table('gates_broadcast_log')
                ->where('campaign', self::campaignKey($eventId))
                ->where('email_hash', EmailOptOut::hash($email))
                ->where('status', 'sent')
                ->exists();
        } catch (\Throwable) {
            // Cannot prove it was NOT sent, so do not send. A duplicate invitation with a
            // second reference is worse than a missing one an operator can retry.
            return true;
        }
    }

    private static function log(int $eventId, object $invite, bool $ok, string $error): void
    {
        try {
            DB::table('gates_broadcast_log')->updateOrInsert(
                ['campaign'   => self::campaignKey($eventId),
                 'email_hash' => EmailOptOut::hash((string) $invite->email)],
                ['email'      => (string) $invite->email,
                 // NULL for a judge, not 0: the column is nullable and a 0 would be a
                 // foreign key to a nominee that does not exist.
                 'nominee_id' => ($invite->nominee_id ?? null) ? (int) $invite->nominee_id : null,
                 'status'     => $ok ? 'sent' : 'failed',
                 'error'      => $error === '' ? null : mb_substr($error, 0, 300),
                 'sent_at'    => Carbon::now()->toDateTimeString()]
            );
        } catch (\Throwable) {}
    }
}
