<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Services\GoogleMeetService;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Mail through the platform's own Google Apps Script — the road when Google SMTP fails.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ROAD EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The platform sends through Google's SMTP. When that fails — a refused App Password, an
 * outbound port the host has closed, an account Google has paused for SMTP — people stop
 * getting their sign-in codes, and on a host with no shell there is nothing quick an
 * operator can do about it. The Apps Script web app this platform already deploys for the
 * calendar and Meet is reached over HTTPS, signed in as a Google account, and can send
 * with MailApp. So the same deployment, behind the same secret, carries the mail.
 *
 * ── WHAT IT CARRIES, AND WHAT IT DOES NOT ────────────────────────────────────────
 *
 * MailApp allows about 100 recipients a day on a gmail.com account (1,500 on Workspace).
 * That is plenty for the mail somebody is WAITING for — a sign-in code, a receipt, a
 * confirmation — and nowhere near a newsletter. So `OtpService` offers this road to
 * one-to-one mail only; an announcement that cannot go by SMTP waits for SMTP rather
 * than spending the allowance a sign-in code needs.
 *
 * It sends the message `OtpService` already built: the same subject, both bodies, the
 * From name, the Reply-To and the attachments. MailApp cannot carry custom headers, which
 * is one more reason announcements (List-Unsubscribe) never take this road.
 *
 * The URL and secret are `GoogleMeetService::gasUrl()`/`gasSecret()` — one resolver for
 * one value, so the calendar and the mail can never disagree about whether Apps Script
 * is configured.
 */
final class AppsScriptMail
{
    /** @var \Closure(string,array<string,mixed>,int):array{status:int,body:string,error:string} */
    private \Closure $http;

    public function __construct(
        private readonly string $url,
        private readonly string $secret,
        ?\Closure $http = null,
    ) {
        $this->http = $http ?? \Closure::fromCallable([self::class, 'curl']);
    }

    /** From the platform's own settings. */
    public static function boot(): self
    {
        return new self(GoogleMeetService::gasUrl(), GoogleMeetService::gasSecret());
    }

    /** Is there an Apps Script to send through at all? Both halves are required. */
    public static function configured(): bool
    {
        return trim(GoogleMeetService::gasUrl()) !== '' && trim(GoogleMeetService::gasSecret()) !== '';
    }

    /** Send a built message. Throws, with a sentence an operator can act on. */
    public function send(PHPMailer $m): void
    {
        $r = $this->call('mail.send', self::payload($m), 25);
        if (!$r['ok']) throw new MailException('Apps Script: ' . $r['message']);
    }

    /**
     * Is the script deployed with mail, and how much may it still send today? A READ.
     *
     * @return array{ok:bool, detail:string, remaining:?int}
     */
    public function check(): array
    {
        $r = $this->call('mail.quota', [], 12);
        if (!$r['ok']) return ['ok' => false, 'detail' => $r['message'], 'remaining' => null];
        $left = isset($r['remaining']) ? (int) $r['remaining'] : null;
        $who  = trim((string) ($r['account'] ?? ''));
        return ['ok' => $left === null || $left > 0,
                'detail' => 'The Apps Script answered' . ($who !== '' ? ' as ' . $who : '')
                    . ($left !== null ? '; it may send to ' . $left . ' more recipient' . ($left === 1 ? '' : 's') . ' today' : '') . '.',
                'remaining' => $left];
    }

    /**
     * What the script's mailSend() reads, from a built message.
     *
     * @return array<string,mixed>
     */
    public static function payload(PHPMailer $m): array
    {
        $to = array_values($m->getToAddresses());
        $html = BuiltMessage::isHtml($m);
        $reply = array_values($m->getReplyToAddresses());
        $files = array_map(static fn (array $f): array => ['name' => $f['name'], 'mime' => $f['mime'],
            'content' => base64_encode($f['content'])], BuiltMessage::attachments($m));
        return [
            'to'       => (string) ($to[0][0] ?? ''),
            'subject'  => $m->Subject,
            'html'     => $html ? $m->Body : '',
            'text'     => $html ? (string) $m->AltBody : $m->Body,
            'name'     => $m->FromName,
            'reply_to' => (string) ($reply[0][0] ?? $m->From),
            'attachments' => $files,
        ];
    }

    /** @return array{ok:bool, message:string}&array<string,mixed> */
    private function call(string $action, array $data, int $timeout): array
    {
        if (trim($this->url) === '') {
            return ['ok' => false, 'message' => 'no Apps Script URL is set (Settings → Google Calendar and Meet)'];
        }
        if (trim($this->secret) === '') {
            return ['ok' => false, 'message' => 'no Apps Script secret is set (Settings → Google Calendar and Meet) — the script refuses mail without one'];
        }
        $r = ($this->http)($this->url, ['action' => $action, 'token' => $this->secret, 'data' => $data, 'source' => 'web'], $timeout);
        if ($r['error'] !== '' || $r['status'] < 200 || $r['status'] >= 400) {
            return ['ok' => false, 'message' => 'could not reach it (' . ($r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['status']) . ')'];
        }
        $j = json_decode($r['body'], true);
        if (!is_array($j)) {
            return ['ok' => false, 'message' => 'it did not answer JSON — usually an old deployment: open the script, '
                . 'paste the latest version, then Deploy → Manage deployments → edit → New version'];
        }
        $ok = (bool) ($j['ok'] ?? $j['success'] ?? false);
        $msg = (string) ($j['message'] ?? '');
        if (!$ok && str_starts_with($msg, 'Unknown action')) {
            $msg = 'the deployed script is older than the mail action — paste the latest config/AfricaGATES_AppScript.gs and deploy a New version';
        }
        return ['ok' => $ok, 'message' => $msg] + $j;
    }

    /** @return array{status:int, body:string, error:string} */
    private static function curl(string $url, array $payload, int $timeout): array
    {
        if (!function_exists('curl_init')) return ['status' => 0, 'body' => '', 'error' => 'PHP has no curl extension'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            // Apps Script answers a POST with a 302 to script.googleusercontent.com; the
            // body is at the far end of it.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $err  = $body === false ? (string) curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => is_string($body) ? $body : '', 'error' => $err];
    }
}
