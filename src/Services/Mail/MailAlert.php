<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Services\Notifier;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\WebhookService;
use AfricaGates\Support\Env;

/**
 * TELLING SOMEBODY THAT EMAIL IS DOWN — WITHOUT RELYING ON EMAIL.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE OBVIOUS ALERT DOES NOT ARRIVE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `Notifier::adminAlert()` is how this platform tells its operators anything, and it
 * sends through `OtpService` — the transport that has just failed. So an email outage
 * was the one fault nobody could ever be told about by the system: every alert it
 * raised was itself a failed send, recorded as one more row in the log of the outage.
 *
 * So an alert goes out on every channel there is, each independent of the others:
 *
 *   webhook  HTTPS to the operator's own automation (Make, Zapier, a Slack or chat
 *            hook, an SMS gateway). No SMTP anywhere on the path, and the event is
 *            documented in WebhookService::EVENTS as the one to wire to a phone.
 *   local    PHP's `mail()`, handed to the web host's own mail server. On shared
 *            hosting that often still works when outbound SMTP is blocked, which is
 *            the commonest outage of all — and it costs nothing to try.
 *   smtp     the normal transport, because a PARTIAL failure (one sender refused, a
 *            quota on one category) leaves it working for this message.
 *   console  the open incident itself, which every admin page draws as a banner.
 *            Always reached; it is the channel that needs no delivery at all.
 *
 * Which ones reached is recorded on the incident, so "were we told?" has an answer.
 *
 * Nothing personal is ever in an alert — no recipient, no message body. It says what
 * broke, why, and where to fix it.
 */
final class MailAlert
{
    public const WEBHOOK = 'webhook';
    public const LOCAL   = 'local';
    public const SMTP    = 'smtp';
    public const CONSOLE = 'console';

    /** @var callable(string,array):int */
    private $webhook;
    /** @var callable(string,string,string):bool */
    private $local;
    /** @var callable(string,string,string):bool */
    private $smtp;

    public function __construct(?callable $webhook = null, ?callable $local = null, ?callable $smtp = null)
    {
        // Each event dispatched by its literal name rather than through `$event`:
        // WebhookCatalogTest proves every advertised event has a real call site by
        // reading the source, and a variable name is a call site it cannot see.
        $this->webhook = $webhook ?? static fn (string $event, array $data): int => $event === 'mail.recovered'
            ? WebhookService::dispatch('mail.recovered', $data)
            : WebhookService::dispatch('mail.failing', $data);

        $this->local = $local ?? static function (string $to, string $subject, string $text): bool {
            if (!function_exists('mail')) return false;
            $from = MailConfig::load()->fromAddress;
            // RFC 2047 for the subject, because it carries an em dash; a raw UTF-8
            // subject through `mail()` arrives as mojibake in half the clients.
            return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, implode("\r\n", [
                'From: Africa GATES <' . $from . '>',
                'Content-Type: text/plain; charset=UTF-8',
                'X-Africa-Gates: mail-health',
            ]));
        };

        $this->smtp = $smtp ?? static fn (string $to, string $subject, string $text): bool
            => (bool) (OtpService::boot()->sendCustom($to, $subject, $text)['success'] ?? false);
    }

    /**
     * Email has stopped. Sent when an incident opens, and again (`$still`) while it is
     * unresolved, on the cadence `MailHealth::REALERT_HOURS` sets.
     *
     * @return list<string> the channels that reached somebody
     */
    public function failing(object $incident, array $report, bool $still = false): array
    {
        $since   = (string) $incident->opened_at;
        $subject = '[Africa GATES] ' . ($still ? 'Email is STILL not sending' : 'Email has stopped sending')
                 . ' — ' . $report['title'];

        $failed = '';
        foreach ($report['steps'] ?? [] as $s) {
            if ($s['state'] === MailDiagnosis::FAIL) { $failed = $s['label'] . ': ' . $s['detail']; break; }
        }

        $text = implode("\n", array_filter([
            'Email on Africa GATES has not been sending since ' . $since . ' UTC.',
            '',
            'Cause: ' . $report['title'],
            $failed !== '' ? 'Failing step: ' . $failed : null,
            (int) $incident->failures > 0 ? 'Failed sends counted: ' . (int) $incident->failures : null,
            '',
            'Fix: ' . $report['fix'],
            '',
            'The full diagnosis, and a button to run it again: ' . self::url(),
            '',
            'This was checked automatically. Nothing was sent to anybody while diagnosing.',
        ], static fn ($l) => $l !== null));

        return $this->send('mail.failing', [
            'state'     => $still ? 'still_failing' : 'failing',
            'since'     => $since,
            'cause'     => $report['cause'],
            'title'     => $report['title'],
            'fix'       => $report['fix'],
            'step'      => $failed,
            'failures'  => (int) $incident->failures,
            'admin_url' => self::url(),
        ], $subject, $text, smtpWorthTrying: $report['cause'] === MailFailure::SENDER
                                          || $report['cause'] === MailFailure::QUOTA
                                          || $report['cause'] === MailFailure::UNKNOWN);
    }

    /** @return list<string> */
    public function recovered(object $incident): array
    {
        $subject = '[Africa GATES] Email is sending again';
        $text = 'Email on Africa GATES is sending again. It had been failing since '
              . $incident->opened_at . ' UTC (' . MailFailure::title((string) $incident->cause) . ').'
              . "\n\nAnything that failed during the outage was not resent automatically — sign-in codes"
              . " expire anyway, and people will have asked again. Receipts and tickets can be resent"
              . " from their own screens.\n\nHistory: " . self::url();

        return $this->send('mail.recovered', [
            'state' => 'recovered', 'since' => (string) $incident->opened_at,
            'cause' => (string) $incident->cause, 'admin_url' => self::url(),
        ], $subject, $text, smtpWorthTrying: true);
    }

    /**
     * Every channel, each one fenced: a throw in one must never stop the next, because
     * the next may be the only one that works.
     *
     * `smtpWorthTrying` is false when the diagnosis already says the transport itself is
     * down (no login, port blocked, TLS) — an attempt then is a guaranteed failure that
     * writes one more row into the outage it is reporting.
     *
     * @return list<string>
     */
    private function send(string $event, array $data, string $subject, string $text, bool $smtpWorthTrying): array
    {
        $to      = Notifier::adminEmail();
        $reached = [self::CONSOLE];

        try { if (($this->webhook)($event, $data) > 0) $reached[] = self::WEBHOOK; }
        catch (\Throwable) {}

        try { if (($this->local)($to, $subject, $text)) $reached[] = self::LOCAL; }
        catch (\Throwable) {}

        if ($smtpWorthTrying) {
            try { if (($this->smtp)($to, $subject, $text)) $reached[] = self::SMTP; }
            catch (\Throwable) {}
        }

        return $reached;
    }

    /** Where the operator goes. Absolute, because it is read in an inbox and a chat. */
    public static function url(): string
    {
        return rtrim((string) Env::get('APP_URL', 'https://afg.afrovanguard.org.ng'), '/') . '/admin/settings/mail';
    }
}
