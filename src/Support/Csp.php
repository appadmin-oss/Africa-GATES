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
 *  • `style-src 'unsafe-inline'` — page CSS is deliberately inline in
 *    `{% block head_styles %}` (no build step) and hundreds of elements carry `style=`.
 *    Style injection is a far weaker primitive than script injection, and nonce-ing
 *    every style block would be a large change for a small gain.
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

    /** XHR/fetch/WebSocket targets. Tight on purpose: this is the exfiltration path. */
    public const CONNECT_HOSTS = 'https://challenges.cloudflare.com ' . self::PAY_HOSTS;

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
            . "style-src 'self' 'unsafe-inline' " . self::STYLE_HOSTS . '; '
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
}
