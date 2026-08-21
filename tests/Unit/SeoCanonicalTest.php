<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Canonical;
use Tests\TestCase;

/**
 * Canonicals, robots, and the favicon — the three head-level SEO defects.
 *
 * All three had the same character: the page rendered perfectly, returned 200, and
 * told a crawler something false. Nothing in a browser shows it, which is why they
 * survived, and why they are worth pinning here.
 */
final class SeoCanonicalTest extends TestCase
{
    private const LAYOUT = __DIR__ . '/../../templates/layout/gates.twig';

    // ── Pagination ──────────────────────────────────────────────────────────

    /**
     * THE BUG: the layout built its canonical from the path alone, so
     * `/registry?page=4` declared `/registry` as canonical. Page 4 holds eighteen
     * profiles that appear nowhere on page 1 — a canonical saying they are the same
     * page tells Google to stop crawling through it, and the profiles linked only
     * from there go undiscovered.
     */
    public function test_a_paginated_page_canonicalises_to_itself(): void
    {
        $this->assertSame('/registry?page=4', Canonical::path('/registry?page=4'));
    }

    /** `?page=1` is the same page as no parameter, so it must not mint a second URL. */
    public function test_page_one_collapses_to_the_bare_path(): void
    {
        $this->assertSame('/registry', Canonical::path('/registry?page=1'));
        $this->assertSame('/registry', Canonical::path('/registry?page=0'));
        $this->assertSame('/registry', Canonical::path('/registry?page=abc'));
    }

    // ── Referral and tracking links ─────────────────────────────────────────

    /**
     * THE BUG WITH TEETH: the referral feature hands out `?ref=AGXXXX` links and
     * people share them — that is the point. Each self-canonicalised to a distinct
     * URL, so one page could accumulate as many indexable variants as it has
     * referrers, splitting its own ranking signals and putting somebody's referral
     * code into a search result.
     */
    public function test_a_referral_link_canonicalises_to_the_clean_page(): void
    {
        $this->assertSame('/vote/carol/101-amara', Canonical::path('/vote/carol/101-amara?ref=AGX7Q2'));
    }

    public function test_campaign_parameters_are_stripped(): void
    {
        $this->assertSame(
            '/leaderboard',
            Canonical::path('/leaderboard?utm_source=whatsapp&utm_campaign=finalhours&fbclid=x')
        );
    }

    /** A facet is a near-duplicate: canonicalise it away, but leave it indexable. */
    public function test_a_filter_is_canonicalised_away_but_not_deindexed(): void
    {
        $this->assertSame('/leaderboard', Canonical::path('/leaderboard?cat=music&sort=cpi'));
        $this->assertSame(Canonical::INDEX, Canonical::robots('/leaderboard?cat=music'));
    }

    // ── Internal search ─────────────────────────────────────────────────────

    /**
     * Google asks explicitly that site-search results stay out of the index, and an
     * unbounded query space is a crawl trap. `follow` stays on: the links out of a
     * results page (to the answers themselves) are worth crawling.
     */
    public function test_an_internal_search_result_is_noindex_follow(): void
    {
        $this->assertSame(Canonical::NO_INDEX, Canonical::robots('/help?q=debited'));
        $this->assertStringContainsString('follow', Canonical::NO_INDEX);
        $this->assertStringNotContainsString('nofollow', Canonical::NO_INDEX);
    }

    public function test_an_empty_search_box_is_still_the_indexable_page(): void
    {
        $this->assertSame(Canonical::INDEX, Canonical::robots('/help?q='));
        $this->assertSame('/help', Canonical::path('/help?q='));
    }

    /**
     * `?q[]=a&q[]=b` parses to an array, and every reader here casts to string —
     * which on an array is a warning plus the literal "Array".
     */
    public function test_an_array_shaped_parameter_does_not_warn(): void
    {
        $this->assertSame('/help', Canonical::path('/help?q[]=a&q[]=b'));
        $this->assertSame(Canonical::INDEX, Canonical::robots('/help?q[]=a'));
    }

    public function test_a_bare_path_is_unchanged(): void
    {
        $this->assertSame('/', Canonical::path('/'));
        $this->assertSame('/vote', Canonical::path('/vote'));
    }

    // ── The layout actually uses it ──────────────────────────────────────────

    /** A helper nothing calls is not a fix. */
    public function test_the_layout_builds_its_canonical_from_canonical_path(): void
    {
        $html = (string) file_get_contents(self::LAYOUT);

        $this->assertMatchesRegularExpression(
            '/set _canonical = canonical_url\|default\([^)]*canonical_path/',
            $html,
            'the canonical must come from canonical_path, not the raw request path'
        );
        $this->assertStringContainsString('robots_auto', $html, 'robots must fall back to the computed value');
    }

    public function test_the_globals_the_layout_reads_are_registered(): void
    {
        $container = (string) file_get_contents(__DIR__ . '/../../config/container.php');

        $this->assertStringContainsString("'canonical_path'", $container);
        $this->assertStringContainsString("'robots_auto'", $container);
    }

    // ── Favicon ─────────────────────────────────────────────────────────────

    /**
     * THE BUG: the favicon was an inline `data:image/svg+xml` of the letter G — the
     * placeholder the real artwork replaced. Google requires a favicon it can CRAWL,
     * meaning a URL it can fetch and re-fetch; a data URI has none, so every mobile
     * search result rendered with the generic globe.
     */
    public function test_the_favicon_is_a_crawlable_file_and_not_a_data_uri(): void
    {
        $html = (string) file_get_contents(self::LAYOUT);

        $this->assertMatchesRegularExpression('~rel="icon"[^>]*href="/favicon\.ico"~', $html);
        $this->assertDoesNotMatchRegularExpression(
            '~rel="icon"[^>]*href="data:~', $html,
            'a data: URI has no URL for a crawler to fetch'
        );
    }

    public function test_every_icon_the_layout_declares_exists_on_disk(): void
    {
        $public = __DIR__ . '/../../public';

        foreach ([
            '/favicon.ico',
            '/site.webmanifest',
            '/assets/img/icon-32.png',
            '/assets/img/icon-192.png',
            '/assets/img/icon-512.png',
            '/assets/img/apple-touch-icon.png',
        ] as $path) {
            $this->assertFileExists($public . $path);
        }

        // A real multi-size ICO, not a PNG that someone renamed: browsers show
        // nothing at all for a mislabelled icon rather than falling back.
        $head = (string) file_get_contents($public . '/favicon.ico', false, null, 0, 4);
        $this->assertSame("\x00\x00\x01\x00", $head, '/favicon.ico is not an ICO');
    }

    public function test_the_manifest_is_valid_json_and_its_icons_resolve(): void
    {
        $public = __DIR__ . '/../../public';
        $data   = json_decode((string) file_get_contents($public . '/site.webmanifest'), true);

        $this->assertIsArray($data, 'site.webmanifest must be valid JSON');
        $this->assertNotEmpty($data['icons'] ?? []);
        foreach ($data['icons'] as $icon) {
            $this->assertFileExists($public . $icon['src']);
        }
    }
}
