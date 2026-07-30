<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use AfricaGates\Services\CloudinaryService;

/**
 * Stored image path → the URL a page or a renderer should actually request.
 *
 * ── WHY A PRESET NAME AND NOT A TRANSFORMATION STRING ────────────────────────
 *
 * Forty-odd templates render an image path. If each one spelled out its own
 * `c_fill,g_faces:auto,w_400,…`, the site would have forty subtly different crop
 * rules, and the one that matters most — how a nominee's face is framed — would be
 * decided by whoever last edited that particular partial. The presets below are the
 * whole vocabulary, and each is named for the job it does rather than for its
 * parameters.
 *
 * ── THE FACE GRAVITY IS THE POINT ────────────────────────────────────────────
 *
 * Every portrait preset uses `g_faces:auto`, which anchors the crop on the detected
 * face(s) and falls back to Cloudinary's saliency detection when it finds none. That
 * is the fix for the flier cutting people's heads off: a supplied portrait is usually
 * a full-body or waist-up shot with the face in the upper third, and a centre crop to
 * a 4:3 box — which is what the flier's photo panel is — reliably lands on the chest.
 * The face is the entire persuasive content of a share graphic, so cropping it away
 * makes the graphic worthless while still looking like it worked.
 *
 * `f_auto` and `q_auto` are on every preset except `flier`: they let Cloudinary serve
 * AVIF/WebP to browsers that advertise support, which on the mobile-first, expensive-
 * data audience this platform actually has is the single largest page-weight win
 * available. `flier` pins `f_jpg` instead, because that derivative is fetched by GD
 * over HTTP with no `Accept` header, and by crawlers whose format support is unknown.
 *
 * ── AND IT MUST BE SAFE ON A LOCAL PATH ──────────────────────────────────────
 *
 * A migration is never instantaneous and never total: a database will hold Cloudinary
 * URLs and `/uploads/...` paths side by side for as long as it takes, and some rows
 * (an operator's hand-typed cover image URL) will never be Cloudinary at all. So every
 * function here returns a non-Cloudinary value untouched. That property is what lets
 * templates call it unconditionally.
 */
final class Media
{
    /**
     * The transformation vocabulary.
     *
     * `c_fill` crops to fill the box (never letterboxes, never stretches); `dpr_auto`
     * serves a 2× asset to a retina phone without doubling it for everyone else.
     */
    private const PRESETS = [
        // Small round/square avatars: nominee lists, comment authors, story rails.
        'avatar'   => 'c_fill,g_faces:auto,w_128,h_128,f_auto,q_auto,dpr_auto',
        // Card thumbnails — leaderboard rows, "others in this category".
        'thumb'    => 'c_fill,g_faces:auto,w_320,h_320,f_auto,q_auto,dpr_auto',
        // The big portrait on a nominee or profile page.
        'portrait' => 'c_fill,g_faces:auto,w_800,h_1000,f_auto,q_auto,dpr_auto',
        // Wide covers: shop items, blog heroes, event banners. No face gravity —
        // g_auto picks the salient region, which for a product or a venue is right
        // where a face crop would be wrong.
        'cover'    => 'c_fill,g_auto,w_1200,h_675,f_auto,q_auto',
        // Full-width but unclipped, for anything whose aspect must survive.
        'wide'     => 'c_limit,w_1600,f_auto,q_auto',
        // The flier's photo panel. Pinned format and exact geometry — see the class
        // note, and FlierService::W / PHOTO_H which these must match.
        'flier'    => 'c_fill,g_faces:auto,w_1080,h_820,f_jpg,q_auto:good',
        // The og:image itself, when a raw photo (not the rendered flier) is used.
        'og'       => 'c_fill,g_faces:auto,w_1200,h_630,f_jpg,q_auto:good',
        // The portrait column of the 1200×630 link-preview card. A TALL crop, not a
        // scaled-down 'og' — the card puts the face in a 480×630 panel beside the text
        // rather than behind it, so asking for the landscape derivative would hand the
        // renderer a wide image to crop again and undo the face anchoring. Geometry
        // pinned to FlierService::OG_PHOTO_W / OG_H by test.
        'og_photo' => 'c_fill,g_faces:auto,w_480,h_630,f_jpg,q_auto:good',
    ];

    /**
     * Delivery URL for a stored image path.
     *
     * Returns null for an empty value so a template can test it in one place, and
     * returns the input verbatim for anything that is not a Cloudinary URL.
     */
    public static function url(?string $stored, string $preset = 'thumb'): ?string
    {
        $p = trim((string) $stored);
        if ($p === '') return null;
        if (!CloudinaryService::isRemote($p)) return $p;

        return CloudinaryService::transformed($p, self::PRESETS[$preset] ?? self::PRESETS['thumb']);
    }

    /**
     * Absolute URL, for the places where a relative path is silently ignored:
     * `og:image`, an email body, a JSON API payload, and the flier renderer's own
     * HTTP fetch. A local `/uploads/...` path is prefixed with APP_URL; a Cloudinary
     * URL is already absolute and only gets its transformation.
     */
    public static function absolute(?string $stored, string $preset = 'thumb', ?string $base = null): ?string
    {
        $url = self::url($stored, $preset);
        if ($url === null) return null;
        if (preg_match('~^https?://~i', $url) === 1) return $url;

        $root = rtrim($base ?? ((string) Env::get('APP_URL', '')), '/') ?: 'https://afg.afrovanguard.org.ng';
        return $root . '/' . ltrim($url, '/');
    }

    /** The preset names, for the Twig error message and the unit test. */
    public static function presets(): array
    {
        return array_keys(self::PRESETS);
    }
}
