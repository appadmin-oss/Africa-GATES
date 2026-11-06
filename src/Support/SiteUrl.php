<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The site's absolute base URL — and it is never allowed to be empty.
 *
 * ── THE BUG THIS REPLACES ────────────────────────────────────────────────────
 *
 * Four controllers had their own private copy of:
 *
 *     private function base(): string { return rtrim((string) Env::get('APP_URL', ''), '/'); }
 *
 * With `APP_URL` unset or blank that returns `''`, and every URL built from it becomes
 * RELATIVE. On the checkout paths that is not a cosmetic problem, it is the payment
 * redirect not working:
 *
 *   • `$callbackUrl = $this->base() . '/vote/paid/callback?…'` is sent to Paystack or
 *     Flutterwave as the return URL. `/vote/paid/callback` is not a URL a gateway can
 *     redirect a browser to — it is rejected, or accepted and then unusable, so the
 *     buyer never comes back and the order stays PENDING with their money taken.
 *   • `Location: /vote?paid=error` on the bounce path is at least still same-origin, so
 *     the failure looks like "the gateway did nothing" rather than a configuration miss.
 *
 * Nothing logs it, nothing tests it, and the only symptom is that payments silently do
 * not complete. `APP_URL` is also the one setting most likely to be missing, because it
 * is the first line of `.env` and a deploy that copies `.env.example` gets the example
 * value rather than a blank — which is worse, since it points at somebody else's host.
 *
 * ── SO IT FALLS BACK TO THE REQUEST ──────────────────────────────────────────
 *
 * `APP_URL` stays authoritative when set: it is the only value that is correct behind a
 * proxy that terminates TLS, and it is what cron and the console must use because they
 * have no request at all. But when it is missing, deriving the origin from the request
 * the buyer just made is strictly better than emitting a relative URL — it is right in
 * every single-host deployment, which is this one.
 *
 * The scheme comes from `X-Forwarded-Proto` only when `TRUST_PROXY` says a proxy we
 * control sets it, for the same reason {@see ClientIp} treats forwarded headers that way:
 * on a directly-exposed host the client chooses that header, and a client-chosen scheme
 * on a payment callback is a downgrade an attacker can request.
 *
 * ── BUT ONLY FROM A HOST WE RECOGNISE ────────────────────────────────────────
 *
 * Validating the Host as a hostname stops it CORRUPTING a URL; it does not stop it being
 * somebody else's hostname. This base is what every account email is built from — the
 * password-reset link, the verification link, the sign-in link — and the person who
 * chooses the Host header on a reset request is whoever POSTs the victim's address to
 * `/account/forgot`. With APP_URL unset, `Host: evil.example` mailed the victim a genuine
 * reset token on a link to `https://evil.example/account/reset?token=…`, from our own
 * domain, with our own branding. One click hands the attacker the account.
 *
 * So a derived origin is used only when its host is one this deployment answers to: the
 * production host ({@see FALLBACK}), loopback (a development server — a link there can
 * only reach the clicker's own machine), and anything listed in `TRUSTED_HOSTS`
 * (comma-separated, host names without scheme). Anything else is treated exactly like a
 * malformed header and falls back. That is the least disruptive safe line: production
 * and dev behave as before with APP_URL unset, and a staging host that never set APP_URL
 * gets production links until it sets one — which `app:doctor` already tells it to do.
 */
final class SiteUrl
{
    /** Last-resort literal, used only when there is no APP_URL and no request. */
    public const FALLBACK = 'https://afg.afrovanguard.org.ng';

    /**
     * Absolute origin with no trailing slash, e.g. `https://afg.afrovanguard.org.ng`.
     *
     * Never returns an empty string — that is the whole point.
     */
    public static function base(?Request $req = null): string
    {
        $configured = rtrim(trim((string) Env::get('APP_URL', '')), '/');
        // A scheme is required: `afg.afrovanguard.org.ng` with no `https://` is not
        // something a gateway can redirect to either, and it is an easy thing to type.
        if ($configured !== '' && preg_match('~^https?://~i', $configured) === 1) {
            return $configured;
        }

        $derived = $req !== null ? self::fromRequest($req) : '';
        if ($derived !== '') {
            return $derived;
        }

        // A configured value missing only its scheme is still the operator's intent.
        if ($configured !== '') {
            return 'https://' . ltrim($configured, '/');
        }

        return self::FALLBACK;
    }

    /** True when APP_URL is set to a usable absolute URL. Read by `app:doctor`. */
    public static function isConfigured(): bool
    {
        $v = rtrim(trim((string) Env::get('APP_URL', '')), '/');
        return $v !== '' && preg_match('~^https?://~i', $v) === 1;
    }

    /**
     * The origin the current request arrived on, or '' when it cannot be established.
     *
     * The Host header is client-supplied, so it is validated as a hostname before use —
     * an unvalidated one lands in a payment callback URL, which is the worst possible
     * place for an attacker-chosen value.
     */
    private static function fromRequest(Request $req): string
    {
        $uri  = $req->getUri();
        $host = trim($req->getHeaderLine('Host')) !== '' ? trim($req->getHeaderLine('Host')) : $uri->getHost();
        if ($host === '') return '';

        // host[:port], hostname characters only. Rejects a header carrying a path, a
        // second host, CR/LF, or anything else that would corrupt the URL.
        if (preg_match('~^[A-Za-z0-9.\-]+(:\d{1,5})?$~', $host) !== 1) return '';
        if (!self::isTrustedHost($host)) return '';

        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'https';
        if (Env::bool('TRUST_PROXY')) {
            $fwd = strtolower(trim(explode(',', $req->getHeaderLine('X-Forwarded-Proto'))[0]));
            if ($fwd === 'https' || $fwd === 'http') $scheme = $fwd;
        }

        return $scheme . '://' . $host;
    }

    /**
     * May a link we MAIL point at this host? See the class docblock: a hostname-shaped
     * Host header is not therefore ours. The port is ignored — it does not change whose
     * machine the name resolves to.
     */
    public static function isTrustedHost(string $hostPort): bool
    {
        $host = strtolower(rtrim((string) preg_replace('~:\d{1,5}$~', '', trim($hostPort)), '.'));
        if ($host === '') return false;

        $trusted = [strtolower((string) parse_url(self::FALLBACK, PHP_URL_HOST)), 'localhost', '127.0.0.1'];
        foreach (explode(',', (string) Env::get('TRUSTED_HOSTS', '')) as $h) {
            $h = strtolower(rtrim(trim($h), '.'));
            if ($h !== '') $trusted[] = $h;
        }

        return in_array($host, $trusted, true);
    }
}
