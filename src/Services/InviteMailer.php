<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Services\Mail\Emails;
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
 * `emails/invite.html.twig` is its own copy and its own hierarchy, but it is a FRAGMENT:
 * the doctype, the letterhead, the hero band, the footer and the hidden preheader come
 * from {@see OtpService::brandWrap()}, like every other transactional message here. It
 * was briefly a whole document, which meant the recipient got two mastheads and two
 * footers nested — see the note at the top of the template.
 *
 * The twelve inbox properties are the difference between a design and a design that
 * ARRIVES, and {@see \Tests\Unit\InviteInboxCompatTest} holds every one of them against
 * the RENDERED message — so they are held for the shell, and therefore for all of it.
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
     * The height the shell reserves for the hero band, in the 600px card.
     *
     * Declared because a blocked remote image with no height collapses the band it is the
     * only thing in — Outlook desktop blocks by default — and the reader gets a hairline
     * where the picture was. 600×260 is a 2.3:1 band.
     */
    private const HERO_H = 260;

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
        $m = self::compose($invite, $event);

        $r = self::deliver($mailer, $email, $m);

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

    /**
     * The whole message, composed once.
     *
     * Extracted because there are two send paths now — the real run and a test to the
     * operator's own address — and two copies of "build the subject, the HTML, the plain
     * text and the attachments" is two things to keep in step. This codebase has the
     * scar: the invitation's own "why" sentence was assembled in the Twig template AND in
     * the plain-text builder, and fixing one left the other sending the mangled version.
     *
     * Emails::compose() over invite.html.twig / invite.txt.twig, plus the attachments.
     *
     * @return array<string,mixed>
     */
    private static function compose(object $invite, object $event): array
    {
        $spec = InviteAudience::spec((string) $invite->audience);
        $tier = EventInvites::lowestTier((int) $event->id);
        $attachments = self::attachments($invite, $event, $tier);
        $view = self::view($invite, $event, $spec, $tier, rtrim(SiteUrl::base(), '/'));
        // The sentence about the letter is only true when the letter rendered.
        $view['letter_attached'] = (bool) array_filter($attachments,
            static fn(array $a): bool => $a['mime'] === 'application/pdf');

        return Emails::compose('invite', $view) + ['attachments' => $attachments];
    }

    /**
     * Send one invitation to an address of the operator's choosing, changing nothing.
     *
     * ── WHY THIS IS NOT `send()` WITH A DIFFERENT ADDRESS ────────────────────
     *
     * `send()` does three things a test must not: it refuses an address the run has
     * already written to, it records the attempt in the campaign log, and it stamps
     * `sent_at` on the row. A test that did any of those would either refuse the second
     * press, or quietly take a real invitee out of the run — the operator checks their
     * own letter and a nominee never receives one.
     *
     * The message itself is composed by the same {@see compose()} the real send uses, so
     * what arrives is not a rendering of the invitation: it IS the invitation, letter and
     * artwork attached.
     *
     * Opt-out is still honoured. A suppression is a suppression even when the address is
     * your own, and an operator who has unsubscribed needs to know that rather than to
     * wonder why nothing arrived.
     *
     * @return array{ok:bool, error:string}
     */
    public static function sendTest(object $invite, object $event, string $to, ?OtpService $mailer = null): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That is not an email address.'];
        }
        if (EmailOptOut::suppressed($to)) {
            return ['ok' => false, 'error' => 'That address has opted out of email from us, '
                                            . 'so nothing can be sent to it.'];
        }

        $mailer ??= OtpService::boot();
        $m = self::compose($invite, $event);

        $r = self::deliver($mailer, $to, $m);

        return ['ok' => (bool) ($r['success'] ?? false), 'error' => (string) ($r['error'] ?? '')];
    }

    /** The rendered HTML, for the admin's preview. Sends nothing. */
    public static function preview(object $invite, object $event, ?OtpService $mailer = null): string
    {
        $m = self::compose($invite, $event);

        // WRAPPED, because the recipient's copy is wrapped. This used to return the body
        // alone, so the operator read one document and the inbox received another — which
        // is how the invitation came to be a whole second HTML document nested inside the
        // house shell without anybody seeing it. A preview that is not the message is a
        // preview of nothing.
        return ($mailer ?? OtpService::boot())->brandWrap(
            $m['subject'], $m['html'], $m['category'], $m['hero'],
            '', $m['preheader'], self::HERO_H, $m['why']
        );
    }

    /**
     * One send, for both paths. The unsubscribe link is the RECIPIENT'S — an operator's test
     * carries their own — and travels because this is an announcement (invite.html.twig).
     *
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    private static function deliver(OtpService $mailer, string $to, array $m): array
    {
        return $mailer->sendBranded($to, $m['subject'], $m['html'], $m['text'], $m['category'], $m['hero'],
            $m['transactional'] ? '' : EmailOptOut::url(rtrim(SiteUrl::base(), '/'), $to),
            $m['attachments'], $m['preheader'], self::HERO_H, $m['why']);
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
            // The preheader's short form: the venue alone, or the town where there is none.
            'where_short' => trim((string) ($event->venue ?? '')) ?: trim((string) ($event->location ?? '')),
            'cover_url'   => self::coverUrl($event, $base),
            'reference' => trim((string) $invite->reference),
            'quota'     => $quota,
            'discount'  => $pct,
            // Where guests' seats start: the cheapest PAID tier, named, so the discount has a
            // figure to come off.
            'tier_from' => $tier !== null
                ? '₦' . number_format((int) $tier->price_naira) . ' (' . trim((string) $tier->name) . ')'
                : '',

            'id_url'          => EventInvites::idUrl((string) $invite->reference, $base),
            'events_url'      => $base . '/events/' . rawurlencode((string) $event->slug),
            'unsubscribe_url' => EmailOptOut::url($base, (string) $invite->email),
            'postal_address'  => \AfricaGates\Services\Mail\MailConfig::postal(),
        ];
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
