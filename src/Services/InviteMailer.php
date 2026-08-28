<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The invitation itself: what a guest of honour receives.
 *
 * ── WHY THIS RENDERS THROUGH THE CAMPAIGN SKELETON ───────────────────────────
 *
 * `templates/emails/campaign.twig` already carries the twelve properties that decide
 * whether mail survives a real inbox — the fluid-hybrid wrapper, the MSO conditionals,
 * the VML button, styled alt text, presentation roles, a hidden preheader, no data: URIs
 * — and {@see \Tests\Unit\EmailInboxCompatTest} holds every one of them. A hand-written
 * invitation template would be a thirteenth skeleton to keep correct, and the first thing
 * to break would be Outlook, silently, on the one mail nobody sends twice.
 *
 * So this builds BLOCKS and lets that skeleton render them. The invitation is a block
 * list, not a template.
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

        $html = EmailCampaign::render(
            self::subject($invite, $event, $spec),
            self::preheader($invite, $event, $spec),
            self::blocks($invite, $event, $spec, $tier),
            self::vars($invite, $event, $base)
        );
        $plain = EmailCampaign::plainOf(
            self::blocks($invite, $event, $spec, $tier),
            self::vars($invite, $event, $base)
        );

        $r = $mailer->sendBranded(
            $email,
            self::subject($invite, $event, $spec),
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
        $spec = InviteAudience::spec((string) $invite->audience);

        return EmailCampaign::render(
            self::subject($invite, $event, $spec),
            self::preheader($invite, $event, $spec),
            self::blocks($invite, $event, $spec, EventInvites::lowestTier((int) $event->id)),
            self::vars($invite, $event, rtrim(SiteUrl::base(), '/'))
        );
    }

    // ══ the words ════════════════════════════════════════════════════════════

    /** @param array<string,mixed> $spec */
    public static function subject(object $invite, object $event, array $spec): string
    {
        // Their own name in the subject, because this is not a campaign — it is an
        // invitation to one person, and the inbox has to read that way at a glance.
        return trim((string) $invite->name) . ', you are invited to ' . trim((string) $event->title);
    }

    /** @param array<string,mixed> $spec */
    private static function preheader(object $invite, object $event, array $spec): string
    {
        return 'As a guest of honour — with ' . (int) $invite->guest_quota
             . ' discounted seats for the people you bring.';
    }

    /**
     * The invitation, as blocks.
     *
     * @param array<string,mixed> $spec
     * @return list<array<string,mixed>>
     */
    private static function blocks(object $invite, object $event, array $spec, ?object $tier): array
    {
        $when  = DisplayTime::showZoned((string) $event->event_date, 'l j F Y \a\t H:i');
        $where = trim(implode(', ', array_filter([
            trim((string) ($event->venue ?? '')),
            trim((string) ($event->location ?? '')),
        ])));
        $pct   = InviteAudience::discountPercent();
        $quota = (int) $invite->guest_quota;

        $from = $tier !== null
            ? ' Seats start at ₦' . number_format((int) $tier->price_naira)
              . ' (' . trim((string) $tier->name) . ').'
            : '';

        return [
            ['type' => 'hero',
             'headline'   => 'You are',
             'accent'     => 'invited',
             'standfirst' => 'It is our privilege to invite you to ' . trim((string) $event->title)
                           . ' as a guest of honour.'],

            ['type' => 'paragraph',
             'text' => trim((string) $invite->name) . ', we want the hall packed '
                     . $spec['witness'] . ' — and we would like the people who know that '
                     . 'work best to be in the room to see it recognised.'],

            ['type' => 'heading', 'text' => 'The evening'],

            ['type' => 'paragraph',
             'text' => $when . ($where !== '' ? '. ' . $where : '')
                     . '. Your own entry is arranged and needs no ticket.'],

            ['type' => 'button',
             'label'           => 'Open your pass',
             'link'            => 'id_url',
             'secondary_label' => 'See the event',
             'secondary_link'  => 'events_url'],

            ['type' => 'callout',
             'text' => 'Your pass is a live page, not a file. The code on it changes every '
                     . InvitePass::STEP_SECONDS . ' seconds, so open it on your phone at the '
                     . 'door rather than printing it or sending on a screenshot.'],

            ['type' => 'heading', 'text' => 'Bring your people'],

            ['type' => 'ask',
             'title'      => $quota . ' seats at ' . $pct . '% off',
             'text'       => 'Your reference is ' . trim((string) $invite->reference)
                           . ', and it is also the code your guests use. It takes ' . $pct
                           . '% off for up to ' . $quota . ' of them.' . $from
                           . ' Share it freely with the people you want in the room.',
             'link_label' => 'Send them to the tickets',
             'link'       => 'events_url'],

            ['type' => 'signoff',
             'text'       => 'We would be honoured to have you with us. The formal invitation '
                           . 'is attached, for your records.',
             'salutation' => 'With respect,',
             'signature'  => 'Africa GATES'],
        ];
    }

    /** @return array<string,string> */
    private static function vars(object $invite, object $event, string $base): array
    {
        return [
            'site_url'        => $base,
            'events_url'      => $base . '/events/' . rawurlencode((string) $event->slug),
            'id_url'          => EventInvites::idUrl((string) $invite->reference, $base),
            'vote_url'        => $base . '/vote',
            'unsubscribe_url' => EmailOptOut::url($base, (string) $invite->email),
            'postal_address'  => (string) \AfricaGates\Support\Env::get(
                'MAIL_POSTAL_ADDRESS', 'Afrovanguard, Lagos, Nigeria'
            ),
            'first_name'      => self::firstName((string) $invite->name),
            'category_name'   => (string) $invite->audience,
            'closes_human'    => DisplayTime::showZoned((string) $event->event_date),
        ];
    }

    private static function firstName(string $name): string
    {
        $bits = preg_split('/\s+/u', trim($name)) ?: [];

        return (string) ($bits[0] ?? '');
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
