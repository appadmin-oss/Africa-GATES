<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use Tests\TestCase;

/**
 * The search palette's scope chips, and the source that would otherwise fall between them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SWEEP AND NOT FOUR ASSERTIONS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REFERENCE §7.1 fixes the five chips — All, People, Awards, Events, Pages — and says
 * nothing about which of {@see ActivityFeedService::SOURCES} answers each one. That
 * mapping is exactly the shape this codebase keeps paying for: the next source added to
 * SOURCES would answer under "All" and under no chip at all, so it would be reachable
 * only by somebody who never touched one. Nothing would fail, nothing would look wrong,
 * and the symptom — "I searched for an organisation and it wasn't there" — names the
 * index rather than the chip.
 *
 * `SearchSourcesTest` already pins SOURCES against the methods behind them. This asks the
 * other direction, which is the direction that was missing: is every source REACHABLE?
 * That is `AdminNavTest`'s fourteen NAV → ROUTES tests and no ROUTES → NAV test, one
 * layer down.
 */
final class SearchScopeTest extends TestCase
{
    public function test_every_source_is_reachable_from_exactly_one_chip(): void
    {
        $seen = [];
        foreach (ActivityFeedService::SCOPES as $scope => $sources) {
            foreach ($sources as $s) {
                $this->assertArrayHasKey(
                    $s,
                    ActivityFeedService::SOURCES,
                    "the '$scope' chip asks for a source that does not exist: $s"
                );
                // `?? ''` because PHP builds every argument BEFORE the call, so this
                // message is interpolated on each of the ten PASSING iterations too —
                // where `$seen[$s]` is by definition absent. Without it the suite
                // emitted ten "Undefined array key" warnings from a test that passed,
                // which is noise in exactly the place a real warning would have to be
                // noticed. The empty string can never be printed: the only path that
                // shows this message is the one where the key is set.
                $this->assertArrayNotHasKey(
                    $s,
                    $seen,
                    "$s is in two chips ('" . ($seen[$s] ?? '') . "' and '$scope'), "
                    . 'so a result appears twice'
                );
                $seen[$s] = $scope;
            }
        }

        $missing = array_diff(array_keys(ActivityFeedService::SOURCES), array_keys($seen));

        $this->assertSame(
            [],
            array_values($missing),
            'these sources answer under All and under no chip, so narrowing the search hides them: '
            . implode(', ', $missing)
        );
    }

    public function test_the_chips_are_the_five_the_design_fixed(): void
    {
        // "All" is not a bucket — it is the absence of one, which is why scopeSources()
        // answers [] for it and for anything it does not recognise.
        $this->assertSame(
            ['people', 'awards', 'events', 'pages'],
            array_keys(ActivityFeedService::SCOPES)
        );
        $this->assertSame([], ActivityFeedService::scopeSources('all'));
        $this->assertSame([], ActivityFeedService::scopeSources(''));
        $this->assertSame([], ActivityFeedService::scopeSources(null));
    }

    public function test_an_unknown_scope_widens_rather_than_empties(): void
    {
        // It arrives in a query string. A stranger typing `?scope=../etc` gets a search
        // that ignores their chip, never one that returns nothing — a filter nobody can
        // see returning zero rows reads as the feature being broken.
        $this->assertSame([], ActivityFeedService::scopeSources('../etc'));
        $this->assertSame(
            ActivityFeedService::SCOPES['people'],
            ActivityFeedService::scopeSources('  People '),
            'the chip value is trimmed and case-folded, because it travels in a URL'
        );
    }

    public function test_a_chip_really_narrows_which_sources_run(): void
    {
        // The mapping being right is half of it; the other half is the parameter
        // actually reaching `collect()`. A `$scope` argument accepted and dropped is
        // this codebase's most expensive shape — a declared field with no reader —
        // and it renders as a chip that highlights and changes nothing.
        $feed = new ActivityFeedService();

        $all = $feed->search('lagos', 20, interpret: false);
        $one = $feed->search('lagos', 20, interpret: false, scope: 'events');

        $this->assertSame(
            count(ActivityFeedService::SOURCES),
            $all['sources'],
            'with no chip every source is asked'
        );
        $this->assertSame(
            count(ActivityFeedService::SCOPES['events']),
            $one['sources'],
            'the Events chip must ask one source, not all of them'
        );
    }

    public function test_the_palette_offers_a_chip_for_every_scope_and_no_others(): void
    {
        $twig = (string) file_get_contents(__DIR__ . '/../../templates/partials/site-search.twig');
        preg_match_all("/\\{k:'([a-z]*)', *label:'([A-Za-z]+)'\\}/", $twig, $m, PREG_SET_ORDER);

        $keys = array_map(static fn (array $r): string => $r[1], $m);

        // "All" is the empty key — the absence of a filter, not a fifth bucket.
        $this->assertSame('', $keys[0] ?? null, 'the first chip must be All');
        $this->assertSame(
            array_keys(ActivityFeedService::SCOPES),
            array_values(array_filter($keys)),
            'the chips and the scopes disagree: a chip with no bucket filters nothing and '
            . 'a bucket with no chip is a group of results nobody can ask for'
        );
    }

    public function test_the_chip_map_is_delivered_and_not_copied_into_the_javascript(): void
    {
        // The palette groups its results under the same headings the chips offer, and
        // it cannot know which kind belongs where without the map. A second copy in JS
        // is two lists that drift — visibly as a result filed under the wrong heading,
        // invisibly as one filed under none.
        $ctrl = (string) file_get_contents(__DIR__ . '/../../src/Controllers/ActivityController.php');
        $this->assertStringContainsString("ActivityFeedService::SCOPES", $ctrl,
            'the search endpoint no longer delivers the scope map');

        $js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/ag-search.js');
        foreach (ActivityFeedService::SOURCES as $kind => $_) {
            $this->assertStringNotContainsString(
                "'" . $kind . "'",
                $js,
                "ag-search.js names the source '$kind' itself; the mapping belongs on the server"
            );
        }
    }

    public function test_a_verified_organisation_is_findable_from_a_chip(): void
    {
        // An organisation is not a person, and the chip set has nowhere else to put one.
        // Dropping it would make a partner that went through CAC and SCUML vetting
        // unfindable from the palette, which is the worse of the two wrongs — so the
        // compromise is pinned here rather than left as a comment somebody tidies away.
        $this->assertContains('org', ActivityFeedService::SCOPES['people']);
    }
}
