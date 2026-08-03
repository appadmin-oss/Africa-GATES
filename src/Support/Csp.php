<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * The per-request CSP nonce, and the host allowlists the policy is built from.
 *
 * WHY A NONCE AT ALL. The previous policy was `script-src 'self' 'unsafe-inline'
 * 'unsafe-eval' https:`. Read that carefully: `'unsafe-inline'` permits any injected
 * `<script>` to run, and `https:` permits a script from ANY https host on the
 * internet. Together they mean the CSP provided no meaningful protection against
 * script injection at all — on a platform that takes card payments and runs a public
 * ballot. The other directives (`object-src 'none'`, `base-uri`, `form-action`,
 * `frame-ancestors`) were doing real work; `script-src` was decoration.
 *
 * A nonce fixes the inline half properly: the browser runs an inline `<script>` only
 * if it carries this request's random value, which an injected tag cannot know.
 *
 * ONE HOLDER, READ BY BOTH SIDES. The header and the templates must present the same
 * value or every script on the page dies. Generating it in two places is the obvious
 * way to get that wrong, so it is memoised here and read by both
 * {@see \AfricaGates\Middleware\SecurityHeadersMiddleware} and the `csp_nonce` Twig
 * global. PHP is request-scoped, so one static per process IS one per request.
 *
 * WHAT IS DELIBERATELY STILL PERMITTED, and why:
 *
 *  • `'unsafe-eval'` — Alpine 3 compiles its expressions (`x-data`, `@click`, `x-show`)
 *    with `new Function`. Removing it means either Alpine's CSP build, which supports
 *    a restricted expression syntax this codebase's templates do not use, or hand
 *    rewriting every directive. That is a real project, not a tightening, and doing
 *    it badly would break the nav, the cart and the ballot. Noted as the next step
 *    rather than pretended away.
 *  • `style-src-attr 'unsafe-inline'` — 1,120 `style=` attributes across 95 templates,
 *    and **55 of them interpolate Twig values** (`style="background:{{ tone.code }}"`).
 *    Those are data-driven: a computed colour, bar width or animation delay cannot
 *    become a static class, and CSP has no per-attribute nonce. So this one is
 *    structurally required, not merely inconvenient — see the note on the directive
 *    split below.
 *
 * WHAT CHANGED BESIDES THE NONCE: the blanket `https:` is gone from script-src,
 * style-src, connect-src, font-src and media-src, replaced by the hosts the templates
 * actually reference. `img-src` keeps `https:` on purpose — nominee photos and
 * partner logos legitimately come from arbitrary hosts, and a blocked image is a
 * cosmetic failure where a blocked script is a broken page.
 */
final class Csp
{
    private static ?string $nonce = null;

    /** This request's nonce. Generated once, base64 as the CSP spec expects. */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /** Payment gateways. Needed in form-action AND frame-src — see the middleware. */
    public const PAY_HOSTS = 'https://*.paystack.com https://*.paystack.co '
        . 'https://*.flutterwave.com https://flutterwave.com';

    /**
     * Hosts that serve executable script. Every one of these is a supply-chain
     * dependency: a compromise there is a compromise here, and `unpkg`/`jsdelivr`
     * serve whatever the named package currently resolves to. Listing them
     * explicitly at least makes the exposure visible and countable, which
     * `https:` did not.
     */
    public const SCRIPT_HOSTS = 'https://cdn.jsdelivr.net https://unpkg.com '
        . 'https://code.jquery.com https://cdn.plyr.io '
        . 'https://challenges.cloudflare.com '
        . 'https://pagead2.googlesyndication.com https://googleads.g.doubleclick.net '
        . 'https://tpc.googlesyndication.com';

    public const STYLE_HOSTS = 'https://cdn.jsdelivr.net https://unpkg.com '
        . 'https://cdn.plyr.io https://fonts.googleapis.com';

    public const FONT_HOSTS = 'https://fonts.gstatic.com https://fonts.googleapis.com';

    /**
     * XHR/fetch/WebSocket targets. Tight on purpose: this is the exfiltration path.
     *
     * The three script CDNs are here for ONE reason — SOURCE MAPS. Every vendor
     * bundle we load ends with a `//# sourceMappingURL=` comment, and when a
     * developer opens the console the browser fetches that `.map` through the
     * connect-src channel. Reported from production as four CSP violations on
     * every page load: leaflet, swiper, splide and plyr.
     *
     * They are the same origins already trusted in SCRIPT_HOSTS, so this grants no
     * new capability — the code from those hosts is already executing. What it buys
     * is a console that shows real problems instead of four permanent red lines
     * that train everyone to ignore it. The payment and Turnstile hosts remain the
     * only places that can receive DATA, because nothing on those CDNs is ever a
     * fetch target from our own code.
     */
    public const CONNECT_HOSTS = 'https://challenges.cloudflare.com ' . self::PAY_HOSTS
        . ' https://cdn.jsdelivr.net https://unpkg.com https://cdn.plyr.io';

    public const MEDIA_HOSTS = 'https://r2.vidzflow.com https://cdn.plyr.io';

    public const FRAME_HOSTS = 'https://challenges.cloudflare.com '
        . 'https://www.youtube.com https://www.youtube-nocookie.com '
        . 'https://my.spline.design https://prod.spline.design '
        . 'https://googleads.g.doubleclick.net https://tpc.googlesyndication.com '
        . 'https://www.google.com ' . self::PAY_HOSTS;

    /** The assembled policy for this request. */
    public static function policy(): string
    {
        $nonce = self::nonce();

        return "default-src 'self'; "
            // 'unsafe-inline' is GONE. Note that a browser seeing a nonce ignores
            // 'unsafe-inline' for scripts anyway, so keeping it would have been
            // misleading rather than a safety net: once nonces are in play, an
            // un-nonced inline script is blocked either way.
            . "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' " . self::SCRIPT_HOSTS . '; '
            // ── The style directives, split on purpose ──────────────────────
            //
            // THE TRAP: a nonce anywhere in `style-src` makes browsers ignore
            // `'unsafe-inline'` for that directive — and `style-src` governs BOTH
            // <style> elements and `style=` attributes. Adding a nonce to it would
            // therefore have killed all 1,120 inline style attributes site-wide. The
            // obvious change is the wrong one.
            //
            // CSP3 splits the directive, which is what makes this safe. The 42
            // <style> blocks are nonce-protected via style-src-elem with NO
            // 'unsafe-inline'; the attributes keep working via style-src-attr. That
            // protects the vector that actually matters — a full <style> block can
            // overlay the page, fake UI, and exfiltrate values through attribute
            // selectors with background-image URLs, none of which a single
            // `style=` on one element can do.
            //
            // `style-src` is kept WITHOUT a nonce purely as the fallback for browsers
            // that do not implement the split. It must stay nonce-free or those
            // browsers hit exactly the trap described above.
            . "style-src 'self' 'unsafe-inline' " . self::STYLE_HOSTS . '; '
            . "style-src-elem 'self' 'nonce-{$nonce}' " . self::STYLE_HOSTS . '; '
            . "style-src-attr 'unsafe-inline'; "
            // Images stay open: nominee photos and partner logos come from arbitrary
            // hosts, and a blocked image is cosmetic where a blocked script is fatal.
            . "img-src 'self' data: blob: https:; "
            . "font-src 'self' data: " . self::FONT_HOSTS . '; '
            . "connect-src 'self' " . self::CONNECT_HOSTS . '; '
            . "media-src 'self' " . self::MEDIA_HOSTS . '; '
            . 'frame-src ' . self::FRAME_HOSTS . '; '
            . "object-src 'none'; base-uri 'self'; "
            . "form-action 'self' " . self::PAY_HOSTS . '; '
            . "frame-ancestors 'self'";
    }

    /**
     * The same policy WITHOUT the nonce — for .htaccess, where a nonce cannot exist.
     *
     * ── WHY THIS IS NEEDED, WHICH IS NOT WHAT THIS REPO PREVIOUSLY BELIEVED ──────
     *
     * Production serves `script-src 'self' 'unsafe-inline' 'unsafe-eval'` with no host
     * list and `form-action 'self'` with no gateways. This codebase recorded that as a
     * stale deployment — DocumentRoot, opcache, a proxy — and DoctorCommand still names
     * those three causes.
     *
     * It is none of them. That header is injected by the HOST, account-wide. The identical
     * policy, to the directive, was found being served on afrovanguard.org.ng — the same
     * cPanel account — on responses for a STATIC homepage that runs no PHP at all, with no
     * such header anywhere in that project's files. A security suite on the server adds it
     * to every response for the account, which is why planting a syntax error in
     * `Csp::policy()` on the server changed nothing: PHP's header was being replaced (or
     * duplicated, which is worse — multiple CSP headers are enforced as their
     * INTERSECTION, so the injected one wins on every directive it narrows) regardless of
     * what PHP emitted.
     *
     * That also explains every symptom at once, which the stale-deploy theory never did:
     * the blocked CDN scripts, the blocked stylesheets, and paid votes refused against a
     * same-origin URL.
     *
     * ── THE REMEDY, AND ITS ONE REAL COST ───────────────────────────────────────
     *
     * `Header always unset` removes the injected header — but it removes PHP's too, since
     * mod_headers runs after the content handler. So the docroot .htaccess must unset and
     * then set a complete policy itself, and a file on disk cannot carry a per-request
     * nonce. Hence this variant: identical host allowlists, `'unsafe-inline'` in place of
     * the nonce.
     *
     * The cost is smaller than it reads. Production has NO nonce protection today — the
     * injected policy is `'unsafe-inline'` already — so this strictly improves on what is
     * live: it restores the host allowlists and the gateway `form-action`. It is a
     * downgrade only against the policy in `policy()`, which the host has never let reach
     * a browser.
     *
     * REVERT IT once the host stops injecting: delete the two lines from public/.htaccess
     * and `policy()` — nonce and all — applies again, unchanged. `app:doctor` reports
     * which one a visitor is actually getting.
     *
     * Kept here, beside `policy()`, so the allowlists cannot drift apart; asserted against
     * the .htaccess text by CspStaticFallbackTest.
     */
    public static function staticPolicy(): string
    {
        return "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval' " . self::SCRIPT_HOSTS . '; '
            . "style-src 'self' 'unsafe-inline' " . self::STYLE_HOSTS . '; '
            . "img-src 'self' data: blob: https:; "
            . "font-src 'self' data: " . self::FONT_HOSTS . '; '
            . "connect-src 'self' " . self::CONNECT_HOSTS . '; '
            . "media-src 'self' " . self::MEDIA_HOSTS . '; '
            . 'frame-src ' . self::FRAME_HOSTS . '; '
            . "object-src 'none'; base-uri 'self'; "
            . "form-action 'self' " . self::PAY_HOSTS . '; '
            . "frame-ancestors 'self'";
    }
}
