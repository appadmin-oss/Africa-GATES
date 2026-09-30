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
                $this->assertArrayNotHasKey(
                    $s,
                    $seen,
                    "$s is in two chips ('{$seen[$s]}' and '$scope'), so a result appears twice"
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

    public function test_a_verified_organisation_is_findable_from_a_chip(): void
    {
        // An organisation is not a person, and the chip set has nowhere else to put one.
        // Dropping it would make a partner that went through CAC and SCUML vetting
        // unfindable from the palette, which is the worse of the two wrongs — so the
        // compromise is pinned here rather than left as a comment somebody tidies away.
        $this->assertContains('org', ActivityFeedService::SCOPES['people']);
    }
}
