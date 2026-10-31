<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Services\EmailOptOut;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What the mail provider tells us AFTER a message has gone.
 *
 * ── WHY THIS IS NEEDED AT ALL ────────────────────────────────────────────────
 *
 * Most bounces never reach the sender as an SMTP refusal. A relay like Brevo accepts the
 * message with a 250 and only then tries the recipient's server, so the platform's log
 * says "sent" and the bounce happens an hour later somewhere it cannot see. The same goes
 * for every "report spam" press. Without this the suppression list would only ever learn
 * about the minority of bad addresses that are refused in-line.
 *
 * ── ONE URL, FOUR PROVIDERS' SHAPES ──────────────────────────────────────────
 *
 * Brevo, SendGrid, Mailgun and Postmark each post their own JSON. They are read into one
 * vocabulary — bounce, complaint, unsubscribe — and anything else (a soft bounce, a
 * deferral, a delivery, an open) is ignored on purpose: a temporary failure is not
 * evidence that a mailbox is gone, and suppressing on one deletes real people during a
 * receiving server's bad afternoon.
 *
 * ── AUTHENTICATED BY A SECRET IN THE PATH ───────────────────────────────────
 *
 * The four providers do not share a signature scheme, but every one of them can be given
 * a URL. The token is random, compared with `hash_equals`, regenerable from
 * /admin/settings/mail, and an empty token refuses everything — so a deployment that has
 * never set one up cannot be fed suppressions by a stranger.
 */
final class MailEvents
{
    public const TOKEN_KEY  = 'mail_events_token';
    public const MAX_EVENTS = 500;

    public const BOUNCE      = Suppression::BOUNCE;
    public const COMPLAINT   = Suppression::COMPLAINT;
    public const UNSUBSCRIBE = 'unsubscribe';

    public static function token(): string
    {
        try {
            return trim((string) (DB::table('gates_settings')->where('key_name', self::TOKEN_KEY)->value('value') ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /** The token, minted the first time an operator opens the page that shows it. */
    public static function ensureToken(): string
    {
        $t = self::token();
        return $t !== '' ? $t : self::rotate();
    }

    public static function rotate(): string
    {
        $t = bin2hex(random_bytes(16));
        DB::table('gates_settings')->updateOrInsert(['key_name' => self::TOKEN_KEY], ['value' => $t]);
        return $t;
    }

    public static function accepts(string $presented): bool
    {
        $t = self::token();
        return $t !== '' && strlen($presented) === strlen($t) && hash_equals($t, $presented);
    }

    public static function url(string $site): string
    {
        return rtrim($site, '/') . '/hooks/mail-events/' . self::ensureToken();
    }

    /**
     * Read a provider's payload into events.
     *
     * @param mixed $payload decoded JSON — one object, or a list of them (SendGrid batches)
     * @return list<array{email:string, kind:string, detail:string, source:string}>
     */
    public static function parse(mixed $payload): array
    {
        if (!is_array($payload)) return [];
        $items = array_is_list($payload) ? $payload : [$payload];

        $out = [];
        foreach (array_slice($items, 0, self::MAX_EVENTS) as $e) {
            if (!is_array($e)) continue;
            $ev = self::one($e);
            if ($ev !== null) $out[] = $ev;
        }
        return $out;
    }

    /** @param array<string,mixed> $e @return array{email:string, kind:string, detail:string, source:string}|null */
    private static function one(array $e): ?array
    {
        // Mailgun: { "event-data": { "event": "failed", "severity": "permanent", "recipient": … } }
        if (isset($e['event-data']) && is_array($e['event-data'])) {
            $d = $e['event-data'];
            $kind = match ((string) ($d['event'] ?? '')) {
                'failed'       => ((string) ($d['severity'] ?? '')) === 'permanent' ? self::BOUNCE : null,
                'complained'   => self::COMPLAINT,
                'unsubscribed' => self::UNSUBSCRIBE,
                default        => null,
            };
            $detail = (string) ($d['delivery-status']['message'] ?? $d['reason'] ?? '');
            return self::make((string) ($d['recipient'] ?? ''), $kind, $detail, 'mailgun');
        }

        // Postmark: { "RecordType": "Bounce", "Type": "HardBounce", "Email": … }
        if (isset($e['RecordType'])) {
            $kind = match ((string) $e['RecordType']) {
                'Bounce'         => in_array((string) ($e['Type'] ?? ''), ['HardBounce', 'BadEmailAddress'], true) ? self::BOUNCE : null,
                'SpamComplaint'  => self::COMPLAINT,
                'SubscriptionChange' => !empty($e['SuppressSending']) ? self::UNSUBSCRIBE : null,
                default          => null,
            };
            return self::make((string) ($e['Email'] ?? $e['Recipient'] ?? ''), $kind,
                (string) ($e['Description'] ?? $e['Details'] ?? ''), 'postmark');
        }

        // Brevo: { "event": "hard_bounce", "email": … }. SendGrid: { "event": "bounce", "type": "bounce", "email": … }
        $event = strtolower((string) ($e['event'] ?? ''));
        $kind = match ($event) {
            'hard_bounce', 'invalid_email', 'blocked' => self::BOUNCE,        // Brevo
            'spam', 'complaint'                       => self::COMPLAINT,     // Brevo
            'unsubscribed'                            => self::UNSUBSCRIBE,   // Brevo
            // SendGrid: a "blocked" bounce is the receiving server refusing for now.
            'bounce'      => strtolower((string) ($e['type'] ?? 'bounce')) === 'blocked' ? null : self::BOUNCE,
            'spamreport'  => self::COMPLAINT,
            'unsubscribe', 'group_unsubscribe' => self::UNSUBSCRIBE,
            default => null,
        };
        $source = isset($e['sg_event_id']) || in_array($event, ['bounce', 'spamreport', 'group_unsubscribe', 'unsubscribe'], true)
            ? 'sendgrid' : 'brevo';
        return self::make((string) ($e['email'] ?? ''), $kind,
            (string) ($e['reason'] ?? $e['response'] ?? ''), $source);
    }

    /** @return array{email:string, kind:string, detail:string, source:string}|null */
    private static function make(string $email, ?string $kind, string $detail, string $source): ?array
    {
        $email = EmailOptOut::normalise($email);
        if ($kind === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        return ['email' => $email, 'kind' => $kind, 'detail' => mb_substr($detail, 0, 300), 'source' => $source];
    }

    /**
     * Apply events. A bounce or complaint goes on the suppression list; a provider-side
     * unsubscribe goes on the opt-out list, because it IS the person's choice — made in the
     * provider's own footer or a mail client's unsubscribe button rather than in ours.
     *
     * @param list<array{email:string, kind:string, detail:string, source:string}> $events
     * @return array{bounce:int, complaint:int, unsubscribe:int}
     */
    public static function apply(array $events): array
    {
        $n = [self::BOUNCE => 0, self::COMPLAINT => 0, self::UNSUBSCRIBE => 0];
        foreach ($events as $e) {
            if ($e['kind'] === self::UNSUBSCRIBE) {
                EmailOptOut::record($e['email'], 'provider-' . $e['source']);
            } else {
                Suppression::record($e['email'], $e['kind'], $e['source'], $e['detail']);
            }
            $n[$e['kind']]++;
        }
        return $n;
    }
}
