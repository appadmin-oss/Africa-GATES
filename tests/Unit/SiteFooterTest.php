<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\LegalSeeder;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * THE SITE FOOTER — rebuilt in Phase 4, and the rules its destroyed predecessor carried.
 *
 * `layout/footer.twig` was destroyed with the old layout on 3 Oct 2026 and its inventory
 * (docs/handoff/inventory/_partials.md) marks it MUST RESTORE: it was the only every-page
 * link to the refund and vendor terms, the support desk, the philosophy, challenges and the
 * newsletter, the only front door to the organisation console a partner reaches cold, and
 * the only place the legal documents were linked from every page. Five guard methods were
 * destroyed with it (LegalCoverageTest ×2, NewsletterTest ×1, PublicIaTest ×1,
 * NationsLiveTest ×1) and one was edited (PublicResultsTest's browsing doors); each rule is
 * re-asserted here against the RENDERED footer, on a shell page that is not the homepage,
 * because "on every page" is the claim.
 *
 * Every assertion here was watched failing against a mutated footer before it was trusted
 * (docs/handoff/PHASE-4.md, "Home", tests).
 */
final class SiteFooterTest extends TestCase
{
    private const PARTIAL = 'templates/partials/site-footer.twig';

    /** The footer as a browser receives it, on a shell page other than the homepage. */
    private function footer(string $uri = '/_dev/ui'): string
    {
        $html = ChromeRender::html($uri);
        $this->assertSame(1, preg_match('~<footer class="ag-foot"[^>]*>.*?</footer>~s', $html, $m),
            "$uri rendered no site footer — the shell no longer mounts it");

        return $m[0];
    }

    /** @return list<string> every href in the footer */
    private function hrefs(string $footer): array
    {
        preg_match_all('~\bhref="([^"]+)"~', $footer, $m);

        return $m[1];
    }

    public function test_the_footer_is_on_every_shell_page_and_only_once(): void
    {
        $html = ChromeRender::html('/_dev/ui');
        $this->assertSame(1, substr_count($html, 'role="contentinfo"'),
            'a shell page must carry exactly one footer landmark');

        $shell = ChromeRender::source('templates/layout/shell.twig');
        $this->assertStringContainsString("include 'partials/site-footer.twig'", $shell);
        // Inside the scroller: the footer is reached by scrolling and pinned to nothing.
        $this->assertMatchesRegularExpression('~<main class="ag-main".*partials/site-footer\.twig.*</main>~s', $shell);
    }

    /**
     * A policy that is not linked is a policy people are told about by their bank — the four,
     * as literal hrefs, plus the vendor terms; and every one is a document we ship.
     */
    public function test_the_legal_documents_are_linked_and_each_is_one_we_ship(): void
    {
        $hrefs = $this->hrefs($this->footer());
        foreach (['/privacy', '/terms', '/refunds', '/vendor-terms'] as $path) {
            $this->assertContains($path, $hrefs, "the footer does not link $path");
        }
        // Cookies opens the choices — see the consent test below — and is still a link to
        // the policy's own page without script.
        $this->assertContains('/cookies#choices', $hrefs);

        $shipped = array_keys(LegalSeeder::documents());
        $linked = 0;
        foreach ($hrefs as $h) {
            $slug = trim((string) parse_url($h, PHP_URL_PATH), '/');
            if (!in_array($slug, ['terms', 'privacy', 'cookies', 'refunds', 'vendor-terms'], true)) continue;
            $linked++;
            $this->assertContains($slug, $shipped, "the footer links /$slug and no document is shipped for it");
        }
        $this->assertGreaterThanOrEqual(5, $linked, 'the footer links no legal document — the check above read nothing');
    }

    /**
     * THE WAY BACK TO THE COOKIE CHOICES (GAPS §8c item 10). Phase 2 left the hook for this
     * footer: without it there was no in-page way to change an answer at ≥600px, where the
     * Menu is not drawn.
     */
    public function test_cookies_reopens_the_consent_choices_in_place(): void
    {
        $f = $this->footer();
        $this->assertMatchesRegularExpression(
            '~<a href="/cookies#choices" data-ag-do="consent-open"[^>]*>Cookies</a>~', $f);
        // And something actually binds the hook (the shell loads consent.js).
        $this->assertStringContainsString('[data-ag-do="consent-open"]',
            ChromeRender::code('public/assets/js/consent.js'));
    }

    /** The destinations only the old footer linked from every page (its inventory). */
    public function test_every_destination_the_old_footer_owed_is_linked(): void
    {
        $hrefs = $this->hrefs($this->footer());
        foreach (['/newsletter', '/org', '/support', '/philosophy', '/challenges', '/results',
                  '/refunds', '/vendor-terms', '/integrity', '/status'] as $path) {
            $this->assertContains($path, $hrefs, "the footer no longer links $path");
        }
    }

    /** §8.23: Activity is Discover's Live tab now; the footer does not send anyone to it. */
    public function test_activity_is_gone_from_the_footer(): void
    {
        foreach ($this->hrefs($this->footer()) as $h) {
            $this->assertStringNotContainsString('/activity', $h);
        }
        $this->assertStringNotContainsStringIgnoringCase('activity search', $this->footer());
    }

    /**
     * "LIVE IN …" IS COUNTED, NEVER TYPED. It was typed into this footer once and was wrong
     * the day a second nation had a nominee standing in a live award.
     */
    public function test_the_live_in_sentence_is_computed_by_nations_live(): void
    {
        $src = ChromeRender::source(self::PARTIAL);
        $this->assertStringContainsString('nations_live()', $src,
            'the footer no longer asks NationsLive which nations are live');
        $this->assertDoesNotMatchRegularExpression('~live in (Nigeria|Ghana|Kenya|\d+ nations)~i', $src,
            'a nation is typed into the footer');

        $this->assertStringContainsString('live in Nigeria, building toward 54 nations', $this->footer(),
            'with nothing live the sentence falls back to the platform\'s home nation');
    }

    public function test_social_links_open_a_new_tab_and_say_so(): void
    {
        preg_match_all('~<a class="ag-foot__so"[^>]*>~', $this->footer(), $m);
        $this->assertCount(4, $m[0]);
        foreach ($m[0] as $a) {
            $this->assertStringContainsString('target="_blank"', $a);
            $this->assertStringContainsString('rel="noopener noreferrer"', $a);
            $this->assertStringContainsString('(opens in a new tab)', $a);
        }
    }

    /** No capitals (owner Q5): the DC's tracked uppercase titles are sentence case here. */
    public function test_no_footer_text_is_set_in_capitals(): void
    {
        $css = ChromeRender::code('public/assets/css/components/footer.css');
        $this->assertDoesNotMatchRegularExpression('~text-transform\s*:\s*uppercase~i', $css);
        $this->assertStringContainsString('>Participate<', $this->footer());
    }
}
