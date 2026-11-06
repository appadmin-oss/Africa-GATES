<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hand a buyer to the payment gateway WITHOUT the browser treating it as a form
 * submission — so `form-action` can never block a payment again.
 *
 * ── THE FAILURE THIS EXISTS TO REMOVE ────────────────────────────────────────
 *
 * Reported from production, repeatedly, and it is the last line of a browser console
 * otherwise full of blocked CDN resources:
 *
 *     Sending form data to 'https://afg.afrovanguard.org.ng/vote/paid/start' violates
 *     the following Content Security Policy directive: "form-action 'self'".
 *     The request has been blocked.
 *
 * Read what that means carefully. The POST **never leaves the browser**. Not a single
 * line of PHP runs: no pending order, no gateway call, nothing in any log, nothing in
 * `app.log`. The symptom is "it does not redirect to Paystack", and the server has no
 * evidence the buyer was ever there.
 *
 * The URL in the message is SAME-ORIGIN, which plainly satisfies `'self'` — which is
 * why it reads like a browser bug. It is not: Chrome applies `form-action` to the whole
 * redirect chain of a form submission, and `POST /vote/paid/start` answers `302
 * https://checkout.paystack.com/…`. The gateway host is what fails the check, and Chrome
 * attributes the violation to the URL the user submitted.
 *
 * ── WHY THE ALLOWLIST IS NOT ENOUGH ──────────────────────────────────────────
 *
 * `Csp::PAY_HOSTS` is in `form-action` and has been for some time. It fixes this only on
 * a deployment serving the current policy, and that has repeatedly not been the case
 * here. Which is the real problem: **a security header decided whether revenue worked.**
 * A stale CSP, a proxy-injected one, a security module on the host — any of them silently
 * turns off payments, with no server-side trace.
 *
 * ── THE FIX: TWO HOPS, NEITHER OF WHICH IS A FORM SUBMISSION ─────────────────
 *
 *   1. `POST /vote/paid/start` → `302` to a SAME-ORIGIN handoff URL. Still a form
 *      submission, still governed by `form-action` — and satisfied by `'self'`, which
 *      every policy this site has ever served includes, including the stale one.
 *   2. The handoff page performs a plain top-level NAVIGATION to the gateway. A
 *      navigation that is not a form submission is outside `form-action` entirely.
 *      (`navigate-to` would have governed it; no browser ships it.)
 *
 * So paid checkout now works under `form-action 'self'`. It stops depending on the CSP
 * being current, which is the same reasoning that moved Popper, Tippy and split-type off
 * their CDNs: remove the dependency rather than allowlist it.
 *
 * ── AND IT DEGRADES ─────────────────────────────────────────────────────────
 *
 * The page carries three independent ways to continue, because this is the step between
 * a buyer's intent and their money:
 *
 *   • `Refresh:` HTTP header — acted on before any HTML is parsed, no JavaScript.
 *   • `<meta http-equiv="refresh">` — same, for clients that ignore the header.
 *   • a real, visible, clickable link — which is also the entire no-JS path, and what a
 *     buyer uses when an extension or a slow network eats the automatic hop.
 *
 * Deliberately NOT a JavaScript redirect: it would need a nonce, and a nonce is exactly
 * the thing the stale policies have got wrong. `Refresh` and `meta refresh` are governed
 * by no CSP directive at all.
 */
final class GatewayHandoff
{
    /** Session key holding the pending handoff, keyed by payment reference. */
    private const KEY = 'gateway_handoff';

    /**
     * How long a stored handoff stays usable.
     *
     * Short: it exists only to survive one redirect. A stale entry would send a buyer to
     * a checkout page for an order they abandoned, and the checkout URL is a bearer
     * capability for that transaction — it should not sit in a session for an afternoon.
     */
    private const TTL = 900;

    /**
     * Remember where a buyer is going, and return the same-origin URL to send them to.
     *
     * The gateway URL is kept SERVER-SIDE rather than put in the query string. A
     * `?to=https://checkout.paystack.com/...` parameter is an open redirect the moment
     * anything about the validation is wrong, and it would also be logged by every proxy
     * in front of the site along with a URL that authorises a payment session.
     */
    public static function remember(
        string $reference,
        string $checkoutUrl,
        string $handoffPath,
        string $provider = ''
    ): string {
        if (isset($_SESSION) && is_array($_SESSION)) {
            $_SESSION[self::KEY] = [
                'ref'      => $reference,
                'url'      => $checkoutUrl,
                'provider' => $provider,
                'at'       => time(),
            ];
        }

        // ══ THE SEPARATOR, WHICH TOOK EVERY EVENT PAYMENT DOWN ══════════════════
        //
        // This line was `$handoffPath . '?ref=' . …`, unconditionally. Correct for four of
        // the five callers, because they pass a bare path — `/shop/redirect`,
        // `/pay/redirect`, `/donate/redirect`, `/vote/paid/redirect`.
        //
        // Events passes `/events/redirect?event=<slug>`, so that it can bounce a buyer back
        // to the event they were buying from rather than to the list. Appending `?ref=` to a
        // URL that already has a query string produced
        //
        //     /events/redirect?event=gala?ref=AFG-EVT-ABC123
        //
        // and PHP reads that as ONE parameter, `event` => `gala?ref=AFG-EVT-ABC123`. Both
        // halves then fail, which is why the symptom named neither:
        //
        //   · `ref` is absent, so reference() is '' and take('') refuses immediately —
        //     the stored checkout URL is never retrieved and the buyer never reaches
        //     the gateway.
        //   · the slug now contains a '?', so it fails the `^[a-z0-9-]+$` guard on the
        //     bounce path and even the fallback loses the event.
        //
        // The buyer lands on `/events?pay=restart`. Nothing throws, nothing is logged, no
        // 500 is recorded and the gateway is never called — so every diagnostic on the
        // platform reports a clean system while no ticket can be sold at all. It is also
        // invisible to a test that only asserts "not a 5xx", which is exactly what the
        // end-to-end test asserted.
        //
        // One caller with a query string was all it took. The separator is now chosen from
        // the path rather than assumed, so adding a sixth caller cannot reintroduce it.
        $sep = str_contains($handoffPath, '?') ? '&' : '?';

        return $handoffPath . $sep . 'ref=' . urlencode($reference);
    }

    /**
     * The stored checkout URL for this reference, or null.
     *
     * Read-and-clear, and the reference must MATCH: the handoff is single-use, so a
     * back-button return to it cannot silently re-send a buyer to a checkout session for
     * an order they already completed.
     *
     * Re-validated as an https URL on a known gateway host on the way out. It was put
     * there by this application one request ago, so this is not defence against an
     * attacker — it is defence against sending a buyer somewhere unexpected if any
     * upstream code is ever wrong about what a gateway returned.
     */
    public static function take(string $reference): ?string
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) return null;
        $h = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);

        // ── A LOST HAND-OFF IS A LOST SALE, AND IT USED TO BE SILENT ─────────
        //
        // Every `return null` below bounces a buyer away from the gateway without paying.
        // None of them threw, none of them logged, and the gateway was never called — so a
        // total inability to sell tickets showed up on no diagnostic anywhere. That is how
        // the `?event=gala?ref=…` bug survived: the symptom was a redirect, and a redirect
        // looks like the system working.
        //
        // The distinction that makes this loggable rather than noise: a handoff was STORED
        // and then could not be used. Somebody merely visiting `/shop/redirect` with no
        // handoff in their session is an ordinary stale tab, and says nothing.
        if (!is_array($h)) return null;                      // nothing stored — not a failure

        if ($reference === '') {
            self::noteLost('', 'the redirect carried no `ref` parameter, so the stored '
                . 'checkout URL could not be claimed');
            return null;
        }
        if ((string) ($h['ref'] ?? '') !== $reference) {
            self::noteLost($reference, 'the reference in the URL does not match the one '
                . 'stored in the session');
            return null;
        }
        if (time() - (int) ($h['at'] ?? 0) > self::TTL) {
            self::noteLost($reference, 'the stored handoff had expired (older than '
                . self::TTL . 's)');
            return null;
        }

        $url = (string) ($h['url'] ?? '');
        if (!self::isGatewayUrl($url)) {
            self::noteLost($reference, 'the stored checkout URL is not on a known gateway host');
            return null;
        }

        self::$provider = (string) ($h['provider'] ?? '');
        return $url;
    }

    /** Provider label from the handoff just taken. Never from a query parameter — the page
     *  tells the buyer whose payment form they are about to see, so it must not be
     *  attacker-settable text on a page that names a live payment reference. */
    public static function providerLabel(): string
    {
        $p = preg_replace('~[^A-Za-z ]~', '', self::$provider);
        $p = trim((string) $p);
        return $p !== '' ? ucfirst($p) : 'the payment page';
    }

    /** Set by take(), read by providerLabel(). Request-scoped, like everything in PHP. */
    private static string $provider = '';

    /**
     * Write down that a buyer was bounced instead of being sent to the gateway.
     *
     * Appended to `var/logs/error-detail.log` — the file {@see \AfricaGates\Handlers\ErrorHandler}
     * writes 500s to, and the one `/__setup/errors` reads. Deliberately the same file rather
     * than a new one: this failure is not an exception, so it would otherwise need its own
     * plumbing and its own page to be seen, and the whole lesson of this bug is that a
     * diagnostic nobody can reach is not a diagnostic.
     *
     * No database, no mailer, and it never throws. It is a last-resort note, and a last-resort
     * note that can fail is worse than none.
     */
    private static function noteLost(string $reference, string $why): void
    {
        try {
            $dir = dirname(__DIR__, 2) . '/var/logs';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents(
                $dir . '/error-detail.log',
                '[' . date('c') . '] HandoffLost: a buyer was sent back instead of to the '
                . 'payment page — ' . $why
                . ($reference !== '' ? ' (reference ' . $reference . ')' : '')
                . "\n(no exception: the gateway was never called, so nothing else records this)\n\n",
                FILE_APPEND
            );
        } catch (\Throwable) { /* a diagnostic must never be the thing that breaks */ }
    }

    /**
     * Is this an https URL on a host a gateway actually checks out on?
     *
     * Derived from the CSP's own `PAY_HOSTS`, so the two cannot drift: adding a provider
     * updates one list. Wildcards there (`https://*.paystack.com`) become a suffix match
     * on the registrable part.
     */
    public static function isGatewayUrl(string $url): bool
    {
        if ($url === '') return false;
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') return false;
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') return false;

        foreach (preg_split('/\s+/', \AfricaGates\Support\Csp::PAY_HOSTS) ?: [] as $pattern) {
            $pattern = strtolower(trim((string) preg_replace('~^https://~', '', trim($pattern))));
            if ($pattern === '') continue;
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);              // ".paystack.com"
                if (str_ends_with($host, $suffix) || $host === substr($suffix, 1)) return true;
                continue;
            }
            if ($host === $pattern) return true;
        }
        return false;
    }

    /**
     * The interstitial response. Redirects three ways; see the class note for why none of
     * them is JavaScript.
     */
    public static function page(Response $res, string $checkoutUrl, string $providerLabel, string $reference): Response
    {
        $e   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $url = $e($checkoutUrl);

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="refresh" content="0;url=' . $url . '">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Taking you to ' . $e($providerLabel) . '…</title>'
            // Inline <style> would need a nonce under style-src-elem, and a nonce is the
            // thing the stale policies get wrong — so this page is styled with attributes
            // only, which style-src-attr permits unconditionally.
            . '</head><body style="margin:0;background:#10292C;color:#E8F2EC;'
            . 'font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif">'
            . '<div style="max-width:30rem;margin:14vh auto;padding:0 1.5rem;text-align:center">'
            . '<p style="font-size:1.15rem;font-weight:600;margin:0 0 .4rem">Taking you to '
            . $e($providerLabel) . '…</p>'
            . '<p style="margin:0 0 1.6rem;color:#A9C7BD;font-size:.95rem">Your payment is handled '
            . 'entirely by ' . $e($providerLabel) . '. Do not close this tab.</p>'
            . '<p style="margin:0 0 1.8rem"><a href="' . $url . '" rel="noopener"'
            . ' style="display:inline-block;background:#7FC87C;color:#10292C;font-weight:700;'
            . 'text-decoration:none;padding:.85rem 1.6rem;border-radius:999px">Continue to '
            . $e($providerLabel) . ' &rarr;</a></p>'
            . '<p style="margin:0;color:#7FA295;font-size:.8rem">Reference '
            . $e($reference) . '</p></div></body></html>';

        $res->getBody()->write($html);

        return $res
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            // Acted on before the HTML is even parsed, and governed by no CSP directive.
            ->withHeader('Refresh', '0;url=' . $checkoutUrl)
            // Never cached: it is single-use and it names a live payment session.
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** The reference from a handoff request. */
    public static function reference(Request $req): string
    {
        return trim((string) ($req->getQueryParams()['ref'] ?? ''));
    }
}
