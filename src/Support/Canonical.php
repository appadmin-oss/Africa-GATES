<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * The canonical path for the current request, and whether it should be indexed.
 *
 * ── THE BUG THIS IS A FIX FOR ────────────────────────────────────────────────
 *
 * The layout built its canonical from the request PATH alone:
 *
 *     canonical_url|default(site_url ~ request_path)
 *
 * That is right for most of this site and wrong in two ways that both cost real
 * traffic.
 *
 * **Pagination collapsed.** `/registry?page=4` declared `/registry` as its
 * canonical. Those are not duplicates — page 4 holds eighteen profiles that appear
 * nowhere on page 1 — and a canonical saying otherwise tells Google the page it
 * just fetched is a copy of one it already has, so the profiles linked only from
 * there stop being crawled through it. Google's own guidance is that a paginated
 * page canonicalises to ITSELF.
 *
 * **Referral links became canonical.** The referral feature hands out
 * `?ref=AGXXXX` links, and people share them — that is the entire point. Each one
 * self-canonicalised to a distinct URL, so a single page could accumulate as many
 * indexable variants as there are referrers, splitting its own ranking signals
 * between them and putting somebody's referral code in a search result.
 *
 * ── THE THREE CLASSES OF PARAMETER ───────────────────────────────────────────
 *
 * 1. {@see self::INDEXABLE} — this parameter makes a genuinely different page.
 *    Kept, so the page self-canonicalises. Only `page`, and only when > 1:
 *    `?page=1` is the same page as no parameter at all.
 * 2. {@see self::SEARCH} — an internal search. Stripped from the canonical AND
 *    `noindex, follow`: Google asks explicitly that site search results stay out
 *    of the index, and an unbounded query space is a crawl trap.
 * 3. Everything else — facets (`cat`, `sort`, `country`), tracking (`utm_*`,
 *    `ref`, `fbclid`) and one-offs. Stripped from the canonical, but NOT
 *    noindexed. Canonicalisation is the right strength of signal for a
 *    near-duplicate facet; `noindex` on a filtered listing that somebody has
 *    linked to throws away a page that was already earning.
 */
final class Canonical
{
    /** Parameters that identify a distinct, indexable page. */
    private const INDEXABLE = ['page'];

    /** Parameters that make the page an internal search result. */
    private const SEARCH = ['q', 'search', 'query'];

    /** The site-wide default when a page is fine to index. */
    public const INDEX = 'index, follow, max-image-preview:large';

    /** Indexed pages should not be search-results pages; those still get crawled. */
    public const NO_INDEX = 'noindex, follow';

    /**
     * Path (plus any indexable query) for the current request URI.
     *
     * Takes the raw `REQUEST_URI` because that is what the Twig globals already
     * read, and it keeps this callable from a template global with no request
     * object to hand.
     */
    public static function path(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $keep = self::keep($requestUri);

        return $keep === [] ? $path : $path . '?' . http_build_query($keep);
    }

    /** The `robots` value for the current request. */
    public static function robots(string $requestUri): string
    {
        $q = self::query($requestUri);
        foreach (self::SEARCH as $k) {
            if (isset($q[$k]) && trim((string) $q[$k]) !== '') return self::NO_INDEX;
        }
        return self::INDEX;
    }

    /** @return array<string,string> the indexable parameters, in a stable order */
    private static function keep(string $requestUri): array
    {
        $q = self::query($requestUri);
        $out = [];
        foreach (self::INDEXABLE as $k) {
            $v = trim((string) ($q[$k] ?? ''));
            // `page=1` and `page=0` and `page=x` all mean the first page, which is the
            // bare path. Emitting `?page=1` there would create a second URL for it and
            // reintroduce exactly the duplicate this class exists to remove.
            if ($k === 'page' && (!ctype_digit($v) || (int) $v <= 1)) continue;
            if ($v !== '') $out[$k] = $v;
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function query(string $requestUri): array
    {
        $raw = parse_url($requestUri, PHP_URL_QUERY);
        if (!is_string($raw) || $raw === '') return [];
        $out = [];
        parse_str($raw, $out);
        // Only scalars. `?q[]=a&q[]=b` parses to an array, and every caller here
        // casts to string — which on an array is a warning plus "Array".
        return array_map(
            static fn($v) => is_scalar($v) ? (string) $v : '',
            array_filter($out, static fn($v) => is_scalar($v))
        );
    }
}
