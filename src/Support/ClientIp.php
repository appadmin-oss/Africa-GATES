<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The one way to ask "who is this request from", for rate-limiting purposes.
 *
 * WHY THIS EXISTS. Four call sites answered that question four different ways.
 * Two ({@see \AfricaGates\Controllers\ApiController}, {@see \AfricaGates\Controllers\ActivityController})
 * consulted `TRUST_PROXY` and read `X-Forwarded-For`. The three that take MONEY —
 * paid votes, donations, shop/payment init — read `REMOTE_ADDR` and nothing else.
 *
 * That difference is the whole bug behind "That is a lot of vote purchases from this
 * network" appearing on a supporter's FIRST attempt. This site is served through
 * Cloudflare, so `REMOTE_ADDR` is a Cloudflare edge address: every visitor on earth
 * hashed to the same fingerprint and shared ONE bucket of ten checkout starts per
 * hour. The eleventh supporter of the hour — anywhere, on any device — was told their
 * network was suspicious and turned away from a page whose entire purpose is to take
 * their payment.
 *
 * ── THE TWO DIMENSIONS, AND WHY ONE IS NOT ENOUGH ────────────────────────────
 *
 * `network()` is the IP, resolved correctly. It is still not a person: Nigerian
 * mobile carriers run large-scale NAT, so thousands of real MTN/Glo/Airtel
 * subscribers legitimately share one address. An IP bucket tight enough to stop a
 * script is therefore always tight enough to block a stadium of genuine supporters,
 * whatever number you pick. It can only ever be a wide backstop.
 *
 * `browser()` is a first-party random token in the session. It is per-tab-jar, it
 * cannot be shared by NAT, and — unlike the IP — a supporter cannot inherit someone
 * else's consumed quota through it. It is trivially resettable (clear cookies), which
 * is exactly why it is paired with the network backstop rather than trusted alone.
 *
 * Together they give the property the money paths need: a tight, fair per-buyer limit
 * and a loose limit on scripted volume, with no configuration in which one honest
 * supporter is refused because of another honest supporter's traffic.
 */
final class ClientIp
{
    /** Session key holding this browser's throttle token. */
    private const BROWSER_KEY = 'afg_throttle_id';

    /**
     * The client's IP address, or '' when it cannot be established.
     *
     * Proxy headers are consulted ONLY when `TRUST_PROXY` is set, because they are
     * client-supplied strings: on a directly-exposed host, trusting them lets any
     * caller mint a fresh identity per request and defeat every limit here.
     *
     * `CF-Connecting-IP` is preferred over `X-Forwarded-For` when both are present.
     * Cloudflare sets it to the single true client address, whereas XFF is an
     * append-only list whose left-hand entries the client may have written itself.
     */
    public static function from(Request $req): string
    {
        if (Env::bool('TRUST_PROXY')) {
            foreach (['CF-Connecting-IP', 'True-Client-IP'] as $h) {
                $ip = self::valid(self::header($req, $h));
                if ($ip !== '') return $ip;
            }
            // Left-most VALID entry: the original client, as every proxy in the chain
            // appends. Skipping unparseable entries rather than taking position zero
            // blindly matters because the left-hand end of XFF is the part a client can
            // write — a garbage first entry must not resolve the whole request to ''.
            foreach (explode(',', self::header($req, 'X-Forwarded-For')) as $part) {
                $ip = self::valid($part);
                if ($ip !== '') return $ip;
            }
        }

        return self::valid((string) ($req->getServerParams()['REMOTE_ADDR'] ?? ''));
    }

    /**
     * A request header, from the PSR-7 message OR from the raw server params.
     *
     * Both, because they disagree depending on how the request was constructed.
     * `ServerRequestFactory::createFromGlobals()` copies `$_SERVER` into the header bag,
     * so either source works in production — but a request built in a test (or by
     * middleware that rewrites a header) has the value ONLY on the PSR-7 message, and a
     * SAPI that presents an unusual header shape may have it only in `$_SERVER`. Reading
     * one and not the other is how this resolution silently stops working in exactly one
     * of the two environments, which is the failure mode the whole class exists to end.
     */
    private static function header(Request $req, string $name): string
    {
        $v = trim($req->getHeaderLine($name));
        if ($v !== '') return $v;
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return trim((string) ($req->getServerParams()[$key] ?? ''));
    }

    /**
     * A stable, non-reversible per-browser token.
     *
     * Stored in the session, so it survives the whole visit and the round trip out to
     * a payment gateway and back. Every route that reaches this has an active session
     * already — CsrfMiddleware requires one to validate the token on the POST.
     *
     * Keyed on `$_SESSION` being an array rather than on `session_status()`, which is
     * how the rest of this codebase reads session state (`$_SESSION['user_id']`,
     * `$_SESSION['admin_id']`). The distinction is not cosmetic: `session_status()` is a
     * property of the SAPI, so gating on it made this silently untestable and, worse,
     * made the browser bucket collapse into the IP bucket in any context where a session
     * had not been started yet — which is a different (and much tighter) policy than the
     * one the caller believes it configured.
     *
     * With no session at all the fallback is the IP, so the caller always gets *some*
     * key rather than an empty one that would collide every anonymous request into a
     * single bucket. The network backstop then carries the whole policy, which errs
     * toward allowing — the correct direction for a payment path.
     */
    public static function browser(Request $req): string
    {
        if (isset($_SESSION) && is_array($_SESSION)) {
            if (!isset($_SESSION[self::BROWSER_KEY]) || !is_string($_SESSION[self::BROWSER_KEY]) || $_SESSION[self::BROWSER_KEY] === '') {
                $_SESSION[self::BROWSER_KEY] = bin2hex(random_bytes(16));
            }
            return (string) $_SESSION[self::BROWSER_KEY];
        }
        $ip = self::from($req);
        return $ip !== '' ? 'ip:' . $ip : 'anon';
    }

    /**
     * Namespaced fingerprint for {@see \AfricaGates\Services\RateLimitService}, whose
     * `fingerprint` column is 64 characters — exactly one hex SHA-256.
     */
    public static function fingerprint(string $identity, string $scope): string
    {
        return hash('sha256', $identity . '|' . $scope);
    }

    /** The address if it parses as one, '' otherwise. Never returns a hostname. */
    private static function valid(string $raw): string
    {
        $ip = trim($raw);
        return ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) ? $ip : '';
    }
}
