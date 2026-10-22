<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * THE SITE'S SEARCH FIELD, AND THE TWO CLAIMS PRINTED BESIDE IT — now Discover's.
 *
 * Destroyed and rewritten in Phase 4. The band this file guarded ("Who are you looking
 * for?", `partials/find-band.twig`) was destroyed with the old pages, and §8.23 makes
 * Discover's own field the find band: "the search field is Discover's own (the site
 * find-band); there is no second field". Every rule the old file held that still has a
 * subject is re-asserted here against that field (inventory: _partials.md, find-band).
 *
 * ── WHY A SEARCH FIELD NEEDS A TEST AT ALL ─────────────────────────────────
 *
 * Because it makes two statements about the platform, and this repository's most
 * expensive documented failures are exactly that: a true sentence outliving the rule it
 * described (`/cookies` said "We set one cookie" while three were set). So the coverage
 * sentence is GENERATED from `ActivityFeedService::SOURCES` and this file asserts the
 * generation, not the wording; and "Nothing unannounced is searchable" — the platform's
 * central promise — must stay printed beside the evidence for it.
 */
final class FindBandTest extends TestCase
{
    private function page(): string
    {
        return ChromeRender::html('/discover');
    }

    /** The coverage sentence as a reader sees it, tags removed. */
    private function sentence(): string
    {
        $this->assertMatchesRegularExpression('~<p class="dv__note" id="dvNote">(.*?)</p>~s', $this->page());
        preg_match('~<p class="dv__note" id="dvNote">(.*?)</p>~s', $this->page(), $m);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($m[1]))));
    }

    public function test_the_field_posts_to_discover_and_is_the_only_one(): void
    {
        $html = $this->page();
        $this->assertMatchesRegularExpression('~<form class="dv__form" method="get" action="/discover" role="search"~', $html);
        $main = substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));
        $this->assertSame(1, preg_match_all('~<input[^>]*type="search"~', $main), 'one search field on the surface, never a second');
    }

    public function test_the_field_carries_a_real_label_and_the_sentence_describes_it(): void
    {
        $html = $this->page();
        $this->assertMatchesRegularExpression('~<label class="dv__field" for="dvQ">~', $html);
        $this->assertMatchesRegularExpression('~id="dvQ"[^>]*aria-describedby="dvNote"~', $html,
            'the coverage sentence is the field\'s description, so a screen reader hears it on focus');
    }

    public function test_every_named_source_appears_in_the_sentence(): void
    {
        $nouns = ActivityFeedService::nouns();
        $this->assertNotEmpty($nouns);
        foreach ($nouns as $n) $this->assertStringContainsString($n, $this->sentence());
    }

    public function test_a_source_with_no_public_noun_is_still_covered_by_the_sentence(): void
    {
        $unnamed = array_filter(ActivityFeedService::SOURCES, static fn (array $s): bool => $s['noun'] === null);
        $this->assertNotEmpty($unnamed, 'this test is vacuous if every source is named');
        $this->assertStringContainsString('and everything else published here.', $this->sentence(),
            'sources are searched that the sentence neither names nor admits to');
    }

    public function test_the_sentence_is_not_typed_into_the_template(): void
    {
        $src = ChromeRender::source('templates/pages/discover.twig');
        $this->assertStringContainsString('search_covers()', $src, 'the field must generate its coverage sentence');
        foreach (ActivityFeedService::nouns() as $n) {
            $this->assertStringNotContainsString($n, $src, "'$n' is typed into the template");
        }
    }

    public function test_the_promise_is_printed(): void
    {
        $this->assertStringContainsString('Nothing unannounced is searchable.', $this->sentence());
    }

    public function test_the_promise_has_a_test_behind_it(): void
    {
        // A claim this load-bearing may not rest on a gate somebody might refactor out.
        // Named by file rather than by re-testing the behaviour here: two tests with
        // their own idea of what "announced" means is how they come to disagree.
        $this->assertFileExists(dirname(__DIR__) . '/Unit/UnannouncedResultTest.php',
            'the field promises nothing unannounced is searchable and nothing proves it');
    }

    public function test_the_live_search_hooks_survive_and_are_bound(): void
    {
        $html = $this->page();
        foreach (['data-dv-form', 'data-dv-q'] as $hook) {
            $this->assertStringContainsString($hook, $html, "the search field lost the hook $hook");
        }
        $js = ChromeRender::code('public/assets/js/discover.js');
        $this->assertStringContainsString("root.querySelector('[data-dv-q]')", $js);
        $this->assertStringContainsString("root.querySelector('[data-dv-form]')", $js);
    }
}
