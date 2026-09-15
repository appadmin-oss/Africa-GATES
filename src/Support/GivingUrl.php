<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * EVERY URL IN THE GIVING FLOW, SPELLED ONCE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE NOUNS FOR ONE THING, AND ONE OF THEM MEANT SOMETHING ELSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The donation surface accumulated three names. `/donate` was the original and is what
 * twenty-two files still linked to. `/gift` was added later and declared "the canonical
 * path from here" in the route file's own comment — and then reached six files, so the
 * rename was announced and never finished: both paths answered **200** with identical
 * content, which is two canonical URLs for one page, split search ranking, and receipts
 * and emails that disagree about where the giving page lives.
 *
 * And `giving` was already taken as a noun *inside* the flow: `/donate/giving/{token}` is
 * one donor's standing monthly gift and the button that stops it. So the word meant "the
 * donation page" in one place and "your recurring gift" in another.
 *
 * `/giving` is the canonical surface now, `/giving/manage/{token}` is the standing gift,
 * and every older path 301s. The redirects are permanent rather than tidy-up: the manage
 * link is printed in receipts that have already been sent, and a donor who cannot easily
 * stop is not a supporter — they are a dispute waiting for a quiet month.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A CLASS AND NOT A CONSTANT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The path was hand-spelled in eight files — the controller, the sitemap, the JSON-LD, the
 * receipt mailer, the campaign service, the recurring-gift service, the search index and
 * the activity feed. That is how it came to be three nouns: nothing forced a rename to
 * reach every one of them, and the ones it missed kept working, which is what made the
 * miss invisible.
 *
 * `RESERVED` is the other half. A partner organisation's appeal lives at `/giving/{slug}`,
 * so a fixed word registered first — `manage`, `success`, `callback` — permanently shadows
 * any organisation whose name slugs to it. Slim serves the first match, so the fixed route
 * wins and the partner simply has no page, with nothing anywhere to say why. The same list
 * drives the route pattern's lookahead and {@see \AfricaGates\Services\PartnerOrg}'s slug
 * minting, so the two cannot disagree about which words are taken.
 */
final class GivingUrl
{
    /** The one place this path is spelled. */
    public const BASE = '/giving';

    /**
     * Words a partner slug may not be, because a fixed route already owns them.
     *
     * `giving` and `donate` are in here too, and not because a route uses them: an appeal
     * at `/giving/giving` is a URL nobody can read aloud, and `/giving/donate` invites
     * somebody to think it is the old path still working.
     */
    public const RESERVED = [
        'apply', 'manage', 'redirect', 'callback', 'success',
        'giving', 'gift', 'donate', 'stop',
    ];

    public static function page(): string  { return self::BASE; }
    public static function apply(): string { return self::BASE . '/apply'; }
    public static function redirect(): string { return self::BASE . '/redirect'; }

    /** A partner organisation's appeal, or one campaign inside it. */
    public static function org(string $slug, ?string $campaign = null): string
    {
        $u = self::BASE . '/' . rawurlencode($slug);
        return $campaign !== null && $campaign !== ''
            ? $u . '/' . rawurlencode($campaign)
            : $u;
    }

    /**
     * One standing gift, and the button that stops it.
     *
     * Public by design — holding the link is the authorisation. The token identifies ONE
     * gift, so a forwarded receipt cannot list somebody's others.
     */
    public static function manage(string $token): string
    {
        return self::BASE . '/manage/' . rawurlencode($token);
    }

    public static function stop(string $token): string
    {
        return self::manage($token) . '/stop';
    }

    public static function success(string $reference): string
    {
        return self::BASE . '/success?ref=' . urlencode($reference);
    }

    public static function callback(string $provider, string $reference): string
    {
        return self::BASE . '/callback?provider=' . urlencode($provider)
             . '&ref=' . urlencode($reference);
    }

    /** The giving page with a refusal to explain — see DonationController::GIVE_REASONS. */
    public static function refused(string $reason): string
    {
        return self::BASE . '?give=' . urlencode($reason);
    }

    /** Is this slug one a fixed route already owns? */
    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::RESERVED, true);
    }
}
