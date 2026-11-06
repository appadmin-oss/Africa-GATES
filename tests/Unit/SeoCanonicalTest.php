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

    public function test_the_globals_the_layout_reads_are_registered(): void
    {
        $container = (string) file_get_contents(__DIR__ . '/../../config/container.php');

        $this->assertStringContainsString("'canonical_path'", $container);
        $this->assertStringContainsString("'robots_auto'", $container);
    }

    // ── Favicon ─────────────────────────────────────────────────────────────

    public function test_every_icon_the_layout_declares_exists_on_disk(): void
    {
        $public = __DIR__ . '/../../public';

        foreach ([
            '/favicon.ico',
            '/favicon.svg',
            '/apple-touch-icon.png',
            '/site.webmanifest',
            '/assets/icons/favicon-192.png',
            '/assets/icons/favicon-512.png',
            // The previous set stays on disk: a home-screen install keeps the manifest
            // it was installed with, and that one names these.
            '/assets/img/icon-192.png',
            '/assets/img/icon-512.png',
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
