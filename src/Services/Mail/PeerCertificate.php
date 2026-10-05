<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

/**
 * WHOSE CERTIFICATE IS THE MAIL SERVER ACTUALLY PRESENTING?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS: AN ACCUSATION NOBODY MEASURED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `MailDiagnosis::tlsFix()` read any cert-shaped word in PHPMailer's error string and
 * told the operator, as a fact: "The server answering presented a certificate that is
 * not smtp.gmail.com's — the web host is intercepting outbound mail." Nothing in the
 * diagnosis had ever looked at a certificate. It was an inference from a substring,
 * printed in the voice of a measurement.
 *
 * Three different faults produce that same string, and only ONE of them is the host:
 *
 *   · `certificate verify failed (unable to get local issuer certificate)` — this
 *     server's CA bundle is missing or stale. Nothing is intercepting anything; our
 *     own trust store cannot verify a perfectly genuine certificate. This is the
 *     ordinary consequence of a PHP version change on a shared host, which is exactly
 *     the moment mail "suddenly stops working after an update".
 *   · `Peer certificate CN=... did not match expected CN=...` — somebody else is
 *     answering. That one IS the host.
 *   · an expired or out-of-order intermediate at the provider.
 *
 * Measured, the three were word-for-word identical on the screen. So the operator was
 * sent to their web host with an accusation in two cases out of three, and the host —
 * correctly — answered with boilerplate, because they had been told a conclusion
 * instead of an observation. CLAUDE.md's §19 shape, on the page whose entire job is
 * saying why mail is failing.
 *
 * ── SO IT LOOKS ──────────────────────────────────────────────────────────────────
 *
 * The handshake is performed a second time with verification DELIBERATELY OFF, for the
 * sole purpose of capturing what the other end presents. That is not a weakened
 * connection: nothing is sent down it, no credential crosses it, and it is closed the
 * moment the certificate has been read. Turning verification off is what lets us see
 * the certificate that verification REFUSED — which is the one fact worth having.
 *
 * It never decides anything on its own. It reports `names` and `matches`, and
 * `MailDiagnosis` says the sentence. A probe that cannot connect returns null, and the
 * advice falls back to quoting the server's own words rather than inventing a cause —
 * because "we could not look" and "we looked and it was wrong" are different findings.
 */
final class PeerCertificate
{
    /** Bounded: this runs inside a maintenance tick, after a handshake has already failed. */
    public const TIMEOUT = 6;

    /**
     * What the server on the other end presents, or null if we could not get that far.
     *
     * @return array{subject:string, issuer:string, names:list<string>, matches:bool,
     *                expired:bool, valid_to:string}|null
     */
    public static function of(MailConfig $c): ?array
    {
        $smtps = $c->security() === MailConfig::SECURE_SMTPS;
        $ctx   = stream_context_create(['ssl' => [
            // OFF ON PURPOSE. See the docblock: the certificate we need to read is the
            // one verification just rejected, and a verified handshake cannot show it.
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true,
            'SNI_enabled'       => true,
            'peer_name'         => $c->host,
        ]]);

        $stream = @stream_socket_client(
            ($smtps ? 'ssl://' : 'tcp://') . $c->host . ':' . $c->port,
            $no, $noStr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$stream) return null;
        stream_set_timeout($stream, self::TIMEOUT);

        try {
            if (!$smtps && !self::startTls($stream, $c->host)) return null;
            $opts = stream_context_get_options($stream);
            $cert = $opts['ssl']['peer_certificate'] ?? null;
            return $cert ? self::describe($cert, $c->host) : null;
        } finally {
            @fclose($stream);
        }
    }

    /**
     * The STARTTLS dance, by hand.
     *
     * PHPMailer's SMTP class would do this, but it hands back a boolean and keeps the
     * stream private — and the stream is the whole point here. Written out rather than
     * borrowed, because the alternative is reaching into another library's internals to
     * read a socket it considers its own.
     */
    private static function startTls($stream, string $host): bool
    {
        if (!self::expect($stream, '220')) return false;          // greeting
        fwrite($stream, 'EHLO ' . self::helo($host) . "\r\n");
        if (!self::expect($stream, '250')) return false;
        fwrite($stream, "STARTTLS\r\n");
        if (!self::expect($stream, '220')) return false;
        return (bool) @stream_socket_enable_crypto(
            $stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
        );
    }

    /** Read a (possibly multi-line) SMTP reply and check its code. */
    private static function expect($stream, string $code): bool
    {
        $last = '';
        while (($line = fgets($stream, 1024)) !== false) {
            $last = $line;
            // A continuation line is "250-EXT"; the final one is "250 EXT".
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return str_starts_with(trim($last), $code);
    }

    /** A HELO name that is a name: an IP or a blank would be refused by some servers. */
    private static function helo(string $host): string
    {
        $me = (string) ($_SERVER['SERVER_NAME'] ?? '');
        return $me !== '' && !filter_var($me, FILTER_VALIDATE_IP) ? $me : 'localhost';
    }

    /**
     * @param \OpenSSLCertificate|resource $cert
     * @return array{subject:string, issuer:string, names:list<string>, matches:bool,
     *                expired:bool, valid_to:string}
     */
    private static function describe($cert, string $host): array
    {
        $p = @openssl_x509_parse($cert) ?: [];

        $names = [];
        $cn = $p['subject']['CN'] ?? null;
        if (is_string($cn) && $cn !== '') $names[] = $cn;
        // The CN has been cosmetic for years; the Subject Alternative Names are what a
        // browser and OpenSSL actually match on, so a check reading CN alone calls a
        // correct certificate wrong whenever the provider stopped setting one.
        foreach (explode(',', (string) ($p['extensions']['subjectAltName'] ?? '')) as $alt) {
            $alt = trim($alt);
            if (str_starts_with($alt, 'DNS:')) $names[] = substr($alt, 4);
        }
        $names = array_values(array_unique(array_filter($names)));

        $to = (int) ($p['validTo_time_t'] ?? 0);

        return [
            'subject'  => (string) ($cn ?? ($names[0] ?? 'unknown')),
            'issuer'   => (string) ($p['issuer']['O'] ?? $p['issuer']['CN'] ?? 'unknown'),
            'names'    => $names,
            'matches'  => self::covers($names, $host),
            'expired'  => $to > 0 && $to < time(),
            'valid_to' => $to > 0 ? gmdate('Y-m-d', $to) : '',
        ];
    }

    /**
     * Does any presented name cover the host we asked for?
     *
     * A wildcard matches ONE label and never a bare dot-less name: `*.brevo.com` covers
     * `smtp-relay.brevo.com` and must not be read as covering `a.b.brevo.com`, or an
     * interception certificate for `*.qservers.net` would clear itself.
     */
    private static function covers(array $names, string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        foreach ($names as $n) {
            $n = strtolower(rtrim((string) $n, '.'));
            if ($n === $host) return true;
            if (str_starts_with($n, '*.')) {
                $suffix = substr($n, 1);               // ".brevo.com"
                if (str_ends_with($host, $suffix)
                    && !str_contains(substr($host, 0, -strlen($suffix)), '.')) return true;
            }
        }
        return false;
    }
}
