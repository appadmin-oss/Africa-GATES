<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

/**
 * WHAT A MAIL ERROR MEANS, AND WHAT TO DO ABOUT IT — IN ONE PLACE.
 *
 * `gates_mail_log.error` holds whatever PHPMailer said, which is a sentence for a
 * developer: "SMTP Error: Could not authenticate." does not tell an operator that the
 * Brevo SMTP key is a different thing from the Brevo password, and "SMTP connect()
 * failed" does not tell them their host blocks outbound mail ports. Every screen that
 * showed the raw string asked the reader to know that.
 *
 * So the string is classified HERE, once, and every reader — the health screen, the
 * alert, the automatic diagnosis — shows the same cause and the same fix. Two copies of
 * this mapping would drift the first time somebody improved one sentence.
 *
 * ── SYSTEM VERSUS MESSAGE ────────────────────────────────────────────────────────
 *
 * A rejected RECIPIENT is a fact about one address, not about the platform: somebody
 * typed `gmial.com`. Counting those towards an outage would page a human every time a
 * stranger mistypes their address, which teaches the human to ignore the page. `isSystem()`
 * is the line, and `MailHealth` counts only what is true of every message.
 *
 * Classified by matching the message, which is the one place this codebase accepts
 * doing so: these strings are a third party's, there is no error code to key on, and an
 * unrecognised one falls through to `unknown` with the raw text shown beside it — so a
 * reworded message degrades to "here is what the server said", never to a wrong cause.
 */
final class MailFailure
{
    public const CONFIG    = 'config';
    public const DNS       = 'dns';
    public const CONNECT   = 'connect';
    public const TLS       = 'tls';
    public const AUTH      = 'auth';
    public const SENDER    = 'sender';
    public const QUOTA     = 'quota';
    public const RECIPIENT = 'recipient';
    public const UNKNOWN   = 'unknown';

    /**
     * cause => [title, fix]. The fix is written for the operator who will read it, on a
     * host with no shell, so every one names a screen or a person rather than a command.
     */
    private const CAUSES = [
        self::CONFIG => ['Email is not set up',
            'Enter the SMTP username and password from your mail provider in Settings → Email & sender.'],
        self::DNS => ['The mail server’s name does not resolve',
            'Check the SMTP host in Settings → Email & sender for a typo. For Brevo it is smtp-relay.brevo.com.'],
        self::CONNECT => ['The mail server cannot be reached from this host',
            'Most often the web host blocks outbound mail ports. Try port 2525 or 587 in Settings → Email & sender; if every port fails, ask the host to allow outbound SMTP to your provider.'],
        self::TLS => ['The encrypted connection failed',
            'The port and the encryption do not match. Port 465 needs “SMTPS”, 587 and 2525 need “STARTTLS” — leave Encryption on “Automatic” unless your provider says otherwise.'],
        self::AUTH => ['The mail provider rejected the login',
            'Re-enter the SMTP username and password. For Brevo the password is an SMTP KEY from Settings → SMTP & API in your Brevo account — not your Brevo login password — and the username is the login shown on that same page.'],
        self::SENDER => ['The provider refused our From address',
            'The From address must be a sender verified with your provider (in Brevo: Senders, Domains & Dedicated IPs). Verify it there, or change From address in Settings → Email & sender to one that is.'],
        self::QUOTA => ['The provider is limiting how much we send',
            'The account has hit its sending limit or has been paused. Check the provider dashboard for a daily cap, a suspended account or a billing notice.'],
        self::RECIPIENT => ['One recipient’s address was refused',
            'This is about a single address, not the platform. Nothing to fix here unless it keeps happening for real addresses.'],
        self::UNKNOWN => ['Email failed for a reason we do not recognise',
            'The server’s own words are shown below. Run the diagnosis from Settings → Email health to see which step fails.'],
    ];

    /** Patterns, checked in order — the first match wins, so the specific comes first. */
    private const PATTERNS = [
        self::CONFIG    => '~not configured|smtp credentials are not~i',
        self::DNS       => '~getaddrinfo|php_network_getaddresses|name or service not known|no such host|could not resolve~i',
        // Never a bare `ssl`: a timeout on 465 reads "Unable to connect to ssl://host:465
        // (Connection timed out)", and calling that a TLS fault sends somebody to the
        // encryption setting when the port is blocked. These phrases only occur once a
        // connection exists and the handshake itself went wrong.
        self::TLS       => '~starttls|stream_socket_enable_crypto|certificate|handshake|ssl operation failed|wrong version number~i',
        self::AUTH      => '~authenticat|\b535\b|\b534\b|username and password|invalid login|auth~i',
        self::QUOTA     => '~quota|limit exceeded|too many|rate limit|\b421\b|\b452\b|suspended|blocked account~i',
        self::SENDER    => '~sender|from address|not verified|unverified|mail from|\b553\b~i',
        self::RECIPIENT => '~recipient|rcpt to|invalid address|mailbox unavailable|user unknown|no such user|\b550 5\.1\.1\b~i',
        self::CONNECT   => '~connect\(\) failed|connection (refused|timed out|reset)|timed out|could not connect|network is unreachable|failed to connect~i',
    ];

    public static function classify(?string $error): string
    {
        $e = trim((string) $error);
        if ($e === '') return self::UNKNOWN;

        foreach (self::PATTERNS as $cause => $re) {
            if (preg_match($re, $e)) return $cause;
        }
        return self::UNKNOWN;
    }

    /** True of every message — what an outage is made of. A recipient is one person. */
    public static function isSystem(string $cause): bool
    {
        return $cause !== self::RECIPIENT;
    }

    public static function title(string $cause): string
    {
        return self::CAUSES[$cause][0] ?? self::CAUSES[self::UNKNOWN][0];
    }

    public static function fix(string $cause): string
    {
        return self::CAUSES[$cause][1] ?? self::CAUSES[self::UNKNOWN][1];
    }

    /** @return list<string> every cause, for the test that holds each one to a fix */
    public static function causes(): array
    {
        return array_keys(self::CAUSES);
    }
}
