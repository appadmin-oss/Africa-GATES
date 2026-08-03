<?php
declare(strict_types=1);
namespace AfricaGates\Middleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class SecurityHeadersMiddleware {
    /**
     * Defense-in-depth headers. The CSP itself lives in {@see \AfricaGates\Support\Csp},
     * which also serves the per-request nonce to Twig — one holder, because a header
     * and a template presenting different nonces would kill every script on the page.
     *
     * What changed and why is documented there. The short version: `script-src` used
     * to be `'unsafe-inline' 'unsafe-eval' https:`, which permits any injected script
     * and any script host on the internet, so it protected nothing. It is now nonce
     * based with an explicit host allowlist.
     *
     * form-action MUST include the payment gateways: POST /donate (and the shop
     * checkout) redirect to the gateway's HOSTED checkout URL, and browsers enforce
     * form-action against the redirect target of a form submission — so 'self' alone
     * silently blocks every donation/checkout that hands off to Paystack or
     * Flutterwave. The same hosts are allowed in frame-src for the gateways' inline
     * (iframe) modal mode.
     */
    /**
     * Headers that must match public/.htaccess exactly.
     *
     * Both are needed and neither is redundant: Apache serves everything under
     * public/ that exists on disk WITHOUT touching PHP (`RewriteCond
     * %{REQUEST_FILENAME} !-f`), so a CSS file, an SVG or an upload never passes
     * through this middleware — only .htaccess can protect those. PHP responses need
     * the middleware because .htaccess is not read at all on nginx or a built-in
     * server.
     *
     * They were maintained as two independent copies of the same four values with
     * nothing checking they agreed, and Apache's `Header always set` REPLACES, so on
     * the real deployment the middleware's values were silently discarded — the code
     * a developer reads was not the header a visitor gets. Declared here and asserted
     * against the .htaccess text by SecurityHeadersTest.
     */
    public const SHARED = [
        'X-Frame-Options'        => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        // Deliberately 0, not "1; mode=block". The legacy XSS auditor introduced its
        // own vulnerabilities and CSP supersedes it; 0 disables it explicitly rather
        // than leaving the browser default.
        'X-XSS-Protection'       => '0',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        // Adobe crossdomain.xml / Flash-era policy files. Nothing here serves one and
        // nothing should be trusted to.
        'X-Permitted-Cross-Domain-Policies' => 'none',
        /**
         * Every powerful feature this platform does not use, denied.
         *
         * `interest-cohort=()` is gone: FLoC was withdrawn in 2022 and the token is
         * unrecognised, so it was a directive against nothing. The rest are real —
         * and a denied feature cannot be requested by an injected script either,
         * which is the point of listing them rather than relying on a permission
         * prompt nobody reads.
         */
        'Permissions-Policy'     => 'accelerometer=(), autoplay=(), camera=(), '
            . 'display-capture=(), encrypted-media=(), fullscreen=(self), geolocation=(), '
            . 'gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), '
            /* picture-in-picture=(self): Plyr requests it for every video it mounts —
               Pulse posts, the nominee films — and with it denied the console logged
               a permissions-policy violation per player while the PiP button sat
               there dead. `self` and not `*`: our own pages may offer it, an
               embedded third party may not. */
            . 'picture-in-picture=(self), publickey-credentials-get=(), screen-wake-lock=(), '
            . 'usb=(), xr-spatial-tracking=()',
        /**
         * Isolate this browsing context from anything it opens or that opens it.
         *
         * `same-origin-allow-popups` rather than `same-origin` because the value has
         * to survive the payment flows. Those redirect in the same tab today, so
         * plain `same-origin` would work — but a future "open checkout in a new
         * window" would silently lose its `window.opener` handle and the popup could
         * not report back, which is the kind of breakage that gets diagnosed as a
         * gateway fault. Allowing popups still severs the reverse-tabnabbing path.
         */
        'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
    ];

    public function __invoke(Request $req, Handler $handler): Response {
        $res = $handler->handle($req);
        $res = $this->applyCachePolicy($res);

        foreach (self::SHARED as $name => $value) {
            $res = $res->withHeader($name, $value);
        }

        $res = $this->stripCookieFromPublicResponse($res);
        $res = $res->withHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');

        // A route that set its OWN CSP meant it, exactly as with Cache-Control above.
        // `withHeader` REPLACES, so the unconditional site policy was silently
        // discarding a deliberately tighter one — found on the flier's SVG endpoint,
        // which sends `default-src 'none'; … sandbox` because an SVG is a document the
        // browser executes and its text contains a public-submitted nominee name. The
        // site policy that replaced it permits `script-src 'self' 'nonce-…'`, so the
        // hardening was inert while reading as present.
        //
        // The site policy is still the default for everything that does not opt out,
        // and the nonce is only meaningful on HTML, which never sets its own.
        if ($res->hasHeader('Content-Security-Policy')) {
            return $res;
        }

        return $res->withHeader('Content-Security-Policy', \AfricaGates\Support\Csp::policy());
    }

    /**
     * A publicly-cacheable response must not carry a session cookie.
     *
     * `session_start()` runs unconditionally in the bootstrap, before routing, so every
     * response leaves with a `Set-Cookie: PHPSESSID=…` — including the flier's PNG, which
     * declares `Cache-Control: public, max-age=600` because it is fetched by WhatsApp,
     * Facebook and X as an `og:image` and re-fetched by every recipient.
     *
     * That combination is the bug. A shared cache holding a `public` response WITH a
     * `Set-Cookie` either refuses to cache it — losing the whole point of making an OG
     * image cacheable — or caches the header and hands ONE visitor's session cookie to
     * everyone who fetches the image afterwards. The second is session fixation by CDN,
     * and it needs no attacker.
     *
     * The cookie is simply not needed: these routes read no session. It is removed from
     * both places it can live — the PSR-7 response, and PHP's own header list, where
     * `session_start()` queued it. `header_remove()` is safe here because Slim has not
     * emitted anything yet.
     *
     * Scoped to `public` responses only. Every HTML page is `private` and keeps its
     * cookie; nothing about login or CSRF changes.
     */
    private function stripCookieFromPublicResponse(Response $res): Response
    {
        if (!str_contains(strtolower($res->getHeaderLine('Cache-Control')), 'public')) {
            return $res;
        }
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header_remove('Set-Cookie');
        }
        return $res->withoutHeader('Set-Cookie');
    }

    /**
     * One coherent caching policy per response — replacing three that disagreed.
     *
     * WHAT WAS BEING SENT, measured over HTTP on this branch:
     *
     *     Expires: Thu, 19 Nov 1981 08:52:00 GMT     ← PHP session cache limiter
     *     Pragma: no-cache                           ← PHP session cache limiter
     *     Cache-Control: private                     ← this middleware
     *
     * `Pragma` and a 1981 `Expires` say "never store this". `private` says "a browser
     * may store it". A response cannot mean both, and which one wins depends on how
     * old the intermediary is.
     *
     * How it got there is worth recording, because the earlier fix here made it
     * worse rather than merely incomplete. PHP's `session.cache_limiter` defaults to
     * `nocache`, so `session_start()` emitted all three of `Expires`, `Pragma` and
     * `Cache-Control: no-store, no-cache, must-revalidate` — which was CORRECT for a
     * page carrying a CSP nonce. Those go out through `header()`, invisible to a PSR-7
     * response, so `!$res->hasHeader('Cache-Control')` was true on every page and the
     * middleware set `private` unconditionally. Slim's emitter then REPLACED the
     * strong policy with the weaker one and left the two HTTP/1.0 relics orphaned.
     * The comment claimed to be "closing the remaining case" of a session-less page;
     * it was overwriting the policy on every other page.
     *
     * So PHP is told to emit nothing ({@see \AfricaGates\Support\Http::disableSessionCacheLimiter()})
     * and the policy is set in exactly one place.
     *
     * WHY `private, no-cache, must-revalidate` AND NOT `no-store`. `private` keeps the
     * page out of every shared cache, which is the requirement — a CDN or corporate
     * appliance holding a nonce-bearing page hands an attacker a nonce the page keeps
     * accepting. `no-cache` lets the browser keep its own copy but forces
     * revalidation, so a stale nonce is never reused. `no-store` would add nothing
     * against a shared cache and would disable the back/forward cache, which is a
     * real cost on a slow mobile connection — an instant back-navigation versus a
     * full round trip, for an audience where that round trip is the expensive part.
     *
     * WHAT THIS DOES NOT SOLVE. Because every HTML page carries a per-request nonce,
     * no HTML page can be cached at a CDN edge. At continental traffic that is the
     * largest single performance lever still unpulled, and it is a real trade-off
     * rather than an oversight: edge-cacheable HTML requires nonce-free pages, i.e.
     * moving the remaining inline scripts into files and switching `style-src-elem` to
     * hashes. Documented in docs/VOTING-NOMINATIONS-STATE-AUDIT.md rather than
     * pretended away here.
     */
    private function applyCachePolicy(Response $res): Response
    {
        $type = strtolower($res->getHeaderLine('Content-Type'));
        // An empty Content-Type counts as HTML: Slim has not always set it by the
        // time this middleware runs.
        $isHtml = $type === '' || str_contains($type, 'text/html');
        if (!$isHtml) {
            return $res;
        }
        // A route that set its own policy meant it. The JSON and setup routes send
        // `no-store`, MediaController sends far-future caching for an immutable
        // asset, and overriding either would be a regression dressed as a fix.
        if ($res->hasHeader('Cache-Control')) {
            return $res;
        }
        return $res->withHeader('Cache-Control', 'private, no-cache, must-revalidate');
    }
}
