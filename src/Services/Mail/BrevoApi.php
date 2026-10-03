<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Brevo's transactional API — the same provider as the SMTP relay, over HTTPS.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A SECOND ROAD TO THE SAME PROVIDER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * SMTP needs an outbound connection to port 587, 465 or 2525, and on a shared host that
 * is the connection most likely to be refused or throttled. Port 443 is the one every
 * other integration here already uses — Paystack, the AI providers, Cloudinary — so a
 * message handed over this way leaves by a road this host is known to allow.
 *
 * It sends the message `OtpService` already BUILT. The subject, both bodies, the reply-to,
 * the attachments and every header (`List-Unsubscribe`, `Feedback-ID`, our Message-ID)
 * are read off the same PHPMailer object the SMTP road would have sent, so a message is
 * one message whichever road it takes — not a second template that drifts from the first.
 *
 * ── THE KEY IS NOT THE SMTP KEY ──────────────────────────────────────────────
 *
 * Brevo issues both from the same "SMTP & API" page and they look alike. An SMTP key here
 * answers 401, which `check()` reports in those words rather than as "unauthorised".
 */
final class BrevoApi
{
    public const SEND_URL    = 'https://api.brevo.com/v3/smtp/email';
    public const ACCOUNT_URL = 'https://api.brevo.com/v3/account';

    /** @var \Closure(string,string,array<string,string>,?string):array{status:int,body:string,error:string} */
    private \Closure $http;

    /**
     * @param \Closure|null $http (method, url, headers, body) → status/body/error. Injected by
     *        the suite; production uses curl.
     */
    public function __construct(private readonly string $key, ?\Closure $http = null)
    {
        $this->http = $http ?? \Closure::fromCallable([self::class, 'curl']);
    }

    /** Send a built message. Throws, with a sentence `MailFailure` can classify. */
    public function send(PHPMailer $m): void
    {
        $r = ($this->http)('POST', self::SEND_URL, $this->headers(),
            (string) json_encode(self::payload($m), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($r['error'] !== '') {
            throw new MailException('Brevo API: could not connect — ' . $r['error']);
        }
        if ($r['status'] >= 200 && $r['status'] < 300) return;
        throw new MailException('Brevo API: ' . self::explain($r['status'], $r['body']));
    }

    /**
     * Is the key good? A READ — the account endpoint — never a send.
     *
     * @return array{ok:bool, detail:string}
     */
    public function check(): array
    {
        $r = ($this->http)('GET', self::ACCOUNT_URL, $this->headers(), null);
        if ($r['error'] !== '') return ['ok' => false, 'detail' => 'Could not connect to Brevo over HTTPS — ' . $r['error']];
        if ($r['status'] === 200) {
            $a = json_decode($r['body'], true);
            $who = is_array($a) ? trim((string) ($a['companyName'] ?? '') ?: (string) ($a['email'] ?? '')) : '';
            return ['ok' => true, 'detail' => 'Brevo accepted the key' . ($who !== '' ? ' for ' . $who : '') . '.'];
        }
        return ['ok' => false, 'detail' => self::explain($r['status'], $r['body'])];
    }

    /**
     * The JSON Brevo wants, from a built message.
     *
     * @return array<string,mixed>
     */
    public static function payload(PHPMailer $m): array
    {
        $to = [];
        foreach ($m->getToAddresses() as [$addr, $name]) {
            $to[] = array_filter(['email' => (string) $addr, 'name' => (string) $name], 'strlen');
        }
        $p = [
            'sender'  => array_filter(['email' => $m->From, 'name' => $m->FromName], 'strlen'),
            'to'      => $to,
            'subject' => $m->Subject,
        ];
        if (str_contains(strtolower((string) $m->ContentType), 'html')) {
            $p['htmlContent'] = $m->Body;
            if (trim((string) $m->AltBody) !== '') $p['textContent'] = $m->AltBody;
        } else {
            $p['textContent'] = $m->Body;
        }
        $reply = array_values($m->getReplyToAddresses());
        if ($reply !== []) $p['replyTo'] = array_filter(['email' => (string) $reply[0][0], 'name' => (string) ($reply[0][1] ?? '')], 'strlen');

        $headers = [];
        foreach ($m->getCustomHeaders() as [$name, $value]) $headers[(string) $name] = (string) $value;
        if (trim((string) $m->MessageID) !== '') $headers['Message-Id'] = (string) $m->MessageID;
        if ($headers !== []) $p['headers'] = $headers;

        $files = [];
        foreach ($m->getAttachments() as $a) {
            // [0] path or string, [1] filename, [2] name, [5] is-string. Only string
            // attachments are built here (OtpService attaches by value, never by path).
            $content = !empty($a[5]) ? (string) $a[0] : (is_file((string) $a[0]) ? (string) file_get_contents((string) $a[0]) : '');
            if ($content === '') continue;
            $files[] = ['name' => (string) ($a[2] ?: $a[1]), 'content' => base64_encode($content)];
        }
        if ($files !== []) $p['attachment'] = $files;

        return $p;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['api-key' => $this->key, 'accept' => 'application/json', 'content-type' => 'application/json'];
    }

    /** Brevo's answer, as a sentence an operator can act on. */
    private static function explain(int $status, string $body): string
    {
        $j = json_decode($body, true);
        $said = is_array($j) ? trim((string) ($j['message'] ?? '')) : '';
        return match (true) {
            $status === 401 => 'the API key was not accepted (401 Unauthorized) — it must be the API key, not the SMTP key'
                               . ($said !== '' ? ': ' . $said : ''),
            $status === 403 => 'the account refused this request (403)' . ($said !== '' ? ': ' . $said : '')
                               . ' — check that the account is active and this server’s IP is not blocked in Brevo’s authorised IPs',
            $status === 400 => 'the message was refused (400)' . ($said !== '' ? ': ' . $said : ''),
            $status === 429 => 'too many requests — rate limit (429)',
            default         => 'answered ' . $status . ($said !== '' ? ': ' . $said : ''),
        };
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:string}
     */
    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        if (!function_exists('curl_init')) return ['status' => 0, 'body' => '', 'error' => 'PHP has no curl extension on this server'];
        $h = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) $lines[] = $k . ': ' . $v;
        curl_setopt_array($h, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($body !== null) curl_setopt($h, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($h);
        $err = $out === false ? (string) curl_error($h) : '';
        $status = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
        curl_close($h);
        return ['status' => $status, 'body' => is_string($out) ? $out : '', 'error' => $err];
    }
}
