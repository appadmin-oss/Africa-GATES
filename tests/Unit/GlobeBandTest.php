<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GlobeBand;
use AfricaGates\Support\NationsLive;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE HOMEPAGE GLOBE, AND THE NUMBERS IT IS NOT ALLOWED TO INVENT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT ARRIVED, AND WHY IT COULD NOT SHIP AS DRAWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The band came as a design handoff whose script carried sixteen cities with figures:
 * Lagos on 41,280 ballots confirmed in 1.1 seconds, Nairobi on 33,940, arcs drawn
 * between them, and an annotation card stating a "median 1.3 seconds" as measured fact.
 *
 * None of it is measurable here. This platform has no verification nodes, records no
 * per-ballot latency, and has never had a column for either — so every one of those
 * numbers was unfalsifiable, on the page that introduces the platform, in the voice of
 * instrumentation. It is exactly the fault `StatsService` exists to have fixed ("1,247
 * profiles", "24 categories", "seven editions"), with better typography.
 *
 * So the band is driven from the one geographic fact this platform holds: WHERE THE
 * NOMINEES ARE. This file holds the two halves of that being true — that the counts are
 * counts, and that the fake set cannot come back.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE HALF THAT FAILS IN SILENCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A marker's position is the CENTROID of that country's own polygon, so nothing is typed
 * and a marker cannot drift from its outline. The price is that the join is by Natural
 * Earth's own NAME, and Natural Earth's names are not ISO's — it calls the DRC
 * "Dem. Rep. Congo". A name that does not match throws nothing, logs nothing and warns
 * nobody: the marker is absent from a band that still looks finished, and the nation
 * live on the platform is missing from the map of it.
 *
 * Nothing at runtime can catch that, which is why it is caught here, against the shipped
 * geometry file and against the script's own Africa set.
 */
final class GlobeBandTest extends TestCase
{
    private const GEO_FILE = __DIR__ . '/../../public/assets/geo/countries-110m.json';
    private const JS_FILE  = __DIR__ . '/../../public/assets/js/globe-band.js';
    private const TWIG     = __DIR__ . '/../../templates/partials/globe-band.twig';
    private const HOME     = __DIR__ . '/../../templates/pages/home.twig';

    private int $liveCategory = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_nominees')->delete();
        DB::table('gates_award_programmes')->update(['is_active' => 0]);
        GlobeBand::forget();

        $this->liveCategory = $this->category($this->programme('gates-live', 1));
    }

    private function programme(string $slug, int $active): int
    {
        return (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug, 'title' => 'Programme ' . $slug, 'is_active' => $active,
            'sort_order' => 1,
        ]);
    }

    private function category(int $programmeId): int
    {
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $programmeId, 'year' => 2026, 'status' => 'voting',
        ]);

        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycle, 'slug' => 'cat' . $cycle, 'title' => 'Category',
            'sort_order' => 1,
        ]);
    }

    private function nominee(string $name, string $cc, int $votes = 0,
                             string $status = 'approved', ?int $cat = null): int
    {
        GlobeBand::forget();

        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat ?? $this->liveCategory, 'name' => $name,
            'status' => $status, 'country_code' => $cc,
            'vote_count' => $votes, 'organic_vote_count' => $votes,
        ]);
    }

    /** @return list<string> every country name in the shipped geometry */
    private function geometryNames(): array
    {
        $json = json_decode((string) file_get_contents(self::GEO_FILE), true);
        $out  = [];
        foreach ($json['objects']['countries']['geometries'] ?? [] as $g) {
            $n = $g['properties']['name'] ?? null;
            if (is_string($n)) $out[] = $n;
        }

        return $out;
    }

    /** @return list<string> the AFRICA set the script paints and hit-tests against */
    private function scriptAfricaSet(): array
    {
        $js  = (string) file_get_contents(self::JS_FILE);
        $at  = strpos($js, 'var AFRICA = new Set([');
        $end = $at === false ? false : strpos($js, ']);', $at);
        $this->assertNotFalse($end, 'globe-band.js no longer declares an AFRICA set');

        $body = substr($js, $at, $end - $at);
        preg_match_all("~'((?:[^'\\\\]|\\\\.)*)'~", $body, $m);

        return array_map(static fn (string $s): string => str_replace("\\'", "'", $s), $m[1]);
    }

    // ══ the geometry join, which is the part nothing at runtime can check ════

    /**
     * EVERY MAPPED NAME IS IN THE FILE THE SCRIPT ACTUALLY LOADS.
     *
     * Not "in Natural Earth" in the abstract — in `public/assets/geo/countries-110m.json`,
     * the copy this repo serves. A geometry upgrade that renames a country is exactly the
     * change that would break a marker while every test about counting still passed.
     */
    public function test_every_mapped_country_exists_in_the_shipped_geometry(): void
    {
        $names = $this->geometryNames();
        $this->assertNotEmpty($names, 'the shipped geometry file has no country names');

        foreach (GlobeBand::GEOMETRY as $code => $geo) {
            $this->assertContains($geo, $names,
                "$code maps to '$geo', which is not a country in the shipped geometry — "
                . 'its marker would be silently absent');
        }
    }

    /**
     * A MAPPED NAME THE SCRIPT DOES NOT TREAT AS AFRICAN IS STILL INVISIBLE.
     *
     * The script draws and hit-tests only the features in its AFRICA set. A country
     * present in the geometry but absent from that set has no outline to brighten and no
     * polygon to click, so the marker would sit on a country the band does not draw.
     */
    public function test_every_mapped_country_is_in_the_scripts_africa_set(): void
    {
        $africa = $this->scriptAfricaSet();

        foreach (GlobeBand::GEOMETRY as $code => $geo) {
            $this->assertContains($geo, $africa,
                "$code maps to '$geo', which globe-band.js does not treat as African");
        }
    }

    /**
     * THE MAP COVERS EXACTLY THE COUNTRIES THE PLATFORM ACCEPTS, AND THE TEST IS THE
     * ONLY THING STOPPING THE TWO DRIFTING.
     *
     * `NationsLive::NAMES` is what the nomination form offers. Add a country there and
     * forget this map, and that country can stand in an award, appear in the footer's
     * sentence and be counted in "nations live" — while having no marker on the map of
     * where the platform is.
     */
    public function test_the_map_covers_exactly_the_countries_the_form_accepts(): void
    {
        $mapped   = array_keys(GlobeBand::GEOMETRY);
        $accepted = array_keys(NationsLive::NAMES);
        sort($mapped); sort($accepted);

        $this->assertSame($accepted, $mapped);
    }

    // ══ what a marker means ══════════════════════════════════════════════════

    public function test_a_marker_is_a_nation_with_a_nominee_standing_in_a_live_award(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG', 40);

        $out = GlobeBand::countries();
        $this->assertCount(1, $out);
        $this->assertSame('NG', $out[0]['code']);
        $this->assertSame('Nigeria', $out[0]['name']);
        $this->assertSame('Nigeria', $out[0]['geo']);
        $this->assertSame(1, $out[0]['nominees']);
        $this->assertSame(40, $out[0]['votes']);
        $this->assertTrue($out[0]['voted']);
    }

    /**
     * The display name and the geometry name are allowed to differ, and for the DRC they
     * must: the card says "DR Congo" because the rest of the site does, and the outline
     * it highlights is Natural Earth's "Dem. Rep. Congo".
     */
    public function test_the_reader_sees_the_sites_name_and_the_script_gets_natural_earths(): void
    {
        $this->nominee('Mwamba Kalonji', 'CD', 3);

        $out = GlobeBand::countries();
        $this->assertSame('DR Congo', $out[0]['name']);
        $this->assertSame('Dem. Rep. Congo', $out[0]['geo']);
        $this->assertNotSame($out[0]['name'], $out[0]['geo']);
    }

    public function test_a_nations_nominees_and_votes_are_both_summed(): void
    {
        $this->nominee('A', 'GH', 12);
        $this->nominee('B', 'GH', 30);
        $this->nominee('C', 'KE', 5);

        $out = [];
        foreach (GlobeBand::countries() as $c) $out[$c['code']] = $c;

        $this->assertSame(2,  $out['GH']['nominees']);
        $this->assertSame(42, $out['GH']['votes']);
        $this->assertSame(1,  $out['KE']['nominees']);
        $this->assertSame(5,  $out['KE']['votes']);
    }

    /**
     * The marker's SHAPE is a fact, not decoration: the outlined check marks a nation
     * whose nominees have recorded ballots, the plain dot a nation still waiting for its
     * first vote. The handoff used the two shapes for "verification hub" and "ballot
     * origin", neither of which this platform has.
     */
    public function test_a_nation_with_no_votes_yet_gets_the_plain_marker(): void
    {
        $this->nominee('Standing, unvoted', 'ZM', 0);

        $out = GlobeBand::countries();
        $this->assertCount(1, $out);
        $this->assertSame(0, $out[0]['votes']);
        $this->assertFalse($out[0]['voted']);
    }

    /** Busiest first, so the script appends it last and it stacks above a neighbour. */
    public function test_markers_are_ordered_by_votes(): void
    {
        $this->nominee('Small', 'GH', 5);
        $this->nominee('Big',   'NG', 500);
        $this->nominee('Mid',   'KE', 50);

        $this->assertSame(['NG', 'KE', 'GH'], array_column(GlobeBand::countries(), 'code'));
    }

    // ══ what must never reach it ═════════════════════════════════════════════

    /**
     * THE SANDBOX CANNOT REACH THE HOMEPAGE, AND NOT BY A FILTER SOMEBODY REMEMBERED.
     *
     * `DemoSeeder` puts the demo in its own programme with `is_active = 0` precisely so
     * public readers exclude it by reaching only for live programmes — the same guarantee
     * `NationsLive` relies on, reached by the same joins.
     */
    public function test_a_nominee_in_an_inactive_programme_gets_no_marker(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG', 10);
        $demo = $this->category($this->programme('demo-sandbox', 0));
        $this->nominee('DEMO — Rehearsal', 'KE', 9_999, 'approved', $demo);

        $this->assertSame(['NG'], array_column(GlobeBand::countries(), 'code'));
    }

    public function test_a_pending_nominee_is_not_standing_anywhere(): void
    {
        $this->nominee('Waiting on review', 'GH', 4, 'pending');

        $this->assertSame([], GlobeBand::countries());
    }

    /**
     * A merge tombstone is not a nominee standing anywhere, and its votes have already
     * been reassigned to the survivor — counting the row twice inflates the nation.
     */
    public function test_a_merge_tombstone_is_not_counted(): void
    {
        $keep   = $this->nominee('Adaeze Nwankwo', 'NG', 60);
        $merged = $this->nominee('Adaeze Nwankwo (dup)', 'NG', 60);
        DB::table('gates_nominees')->where('id', $merged)
            ->update(['merged_into' => $keep, 'merged_at' => '2026-01-01 00:00:00']);
        GlobeBand::forget();

        $out = GlobeBand::countries();
        $this->assertCount(1, $out);
        $this->assertSame(1,  $out[0]['nominees']);
        $this->assertSame(60, $out[0]['votes']);
    }

    // ══ the empty case, which is the one the fake data was hiding ════════════

    /**
     * NO MARKERS, AND THE BAND SAYS SO.
     *
     * This is the state the sixteen invented cities existed to paper over. A fresh
     * deployment, or one whose first award has not been approved yet, gets an empty globe
     * and a sentence explaining it — never a marker minted to fill the space.
     */
    public function test_with_nothing_live_there_are_no_markers_and_the_note_says_so(): void
    {
        $this->assertSame([], GlobeBand::countries());
        $this->assertStringContainsString('No award is open for entries yet', GlobeBand::note());
    }

    /**
     * A NATION THE MAP CANNOT PLACE IS STATED, NOT DROPPED QUIETLY.
     *
     * `GEOMETRY` covers every country the nomination form offers, so this needs a
     * `country_code` set by hand — and then the failure is a nation live on the platform,
     * absent from the map OF where the platform is live, with nothing anywhere to say so.
     * Counting it and printing nothing would make the omission twice as quiet, which is
     * why `unplaced()` is read by `note()` and not merely available.
     */
    public function test_a_nation_with_no_outline_is_counted_and_said_out_loud(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG', 10);
        // Tunisia is African, has a polygon, and is not a country the form offers — so it
        // is exactly the shape of row an operator can create and this map cannot draw.
        $this->nominee('Set by hand', 'TN', 4);

        $this->assertSame(['NG'], array_column(GlobeBand::countries(), 'code'));
        $this->assertSame(1, GlobeBand::unplaced());
        $this->assertStringContainsString('One more nation is standing in a live award and '
            . 'is not drawn here', GlobeBand::note());
    }

    public function test_nothing_is_said_when_every_live_nation_is_drawn(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG', 10);

        $this->assertSame(0, GlobeBand::unplaced());
        $this->assertStringNotContainsString('not drawn here', GlobeBand::note());
    }

    /**
     * THE NOTE STATES WHAT THE MARKERS ARE AND CLAIMS NO MEASUREMENT.
     *
     * The retired line spoke of a routing node nearest the voter and a median
     * confirmation time in seconds — a latency, a piece of infrastructure, and an air of
     * instrumentation, none of it recorded anywhere here. §19's lesson is that prose
     * outlives the rule it describes, so the prose is swept and not only the code.
     *
     * ── IT SWEEPS WHAT A READER SEES, NOT THE FILE ──────────────────────────
     *
     * Twig comments are stripped first. This repo has already shipped a sweep that its
     * own explanatory comment tripped, and the lesson recorded from it was to describe
     * the retired label rather than quote it — which is a rule about how to write
     * comments, enforced by making comments unwritable. Removing them from the subject
     * fixes the sweep instead of the prose: a `{# … #}` block reaches nobody, so it was
     * never in scope, and now the comment above the change may name what it removed.
     */
    public function test_the_note_claims_nothing_the_platform_cannot_count(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG', 10);

        $rendered = preg_replace('~\{#.*?#\}~s', '', (string) file_get_contents(self::TWIG));

        foreach ([GlobeBand::note(), (string) $rendered] as $subject) {
            foreach (['verification node', 'median', 'seconds', '1.3'] as $claim) {
                $this->assertStringNotContainsStringIgnoringCase($claim, $subject,
                    "the band still speaks of '$claim', which nothing here measures");
            }
        }
    }

    /**
     * THE FAKE SET CANNOT COME BACK, AND IT IS THE KIND THAT WOULD.
     *
     * Sixteen cities with plausible ballot counts read as data in a diff and render
     * beautifully. Each token below is a load-bearing piece of the retired model:
     * `ballots`/`verify_seconds` were the invented figures, `FALLBACK` the set itself,
     * `geoInterpolate` the arcs drawn between "nodes", and `hub` the node concept.
     */
    public function test_the_script_carries_no_invented_figures_and_no_routes(): void
    {
        $js = (string) file_get_contents(self::JS_FILE);

        foreach (['FALLBACK', 'verify_seconds', 'ballots', 'geoInterpolate', 'hub'] as $token) {
            $this->assertStringNotContainsString($token, $js,
                "globe-band.js still carries '$token' from the invented city model");
        }
        // And the honest source is wired: the stage's own attribute, nothing else.
        $this->assertStringContainsString('stage.dataset.countries', $js);
    }

    /**
     * A card row whose value can only be an em dash is worse than an absent row — it
     * reads as data that failed to load. Both retired rows named things this platform
     * does not record.
     */
    public function test_the_country_card_has_no_row_the_platform_cannot_fill(): void
    {
        $twig = (string) preg_replace('~\{#.*?#\}~s', '',
            (string) file_get_contents(self::TWIG));

        $this->assertStringNotContainsString('data-globe-verify', $twig);
        $this->assertStringNotContainsString('data-globe-node', $twig);
        $this->assertStringContainsString('data-globe-nominees', $twig);
        $this->assertStringContainsString('data-globe-votes', $twig);
    }

    // ══ and it is actually on the page ═══════════════════════════════════════

    /**
     * A COMPONENT WITH NO INCLUDE IS §18 AGAIN — every piece complete, nothing serving it.
     *
     * The band's three assets are asserted with it: `globe-band.js` no-ops without d3 and
     * topojson, so a missing vendor tag is not an error anywhere, just a band that never
     * paints. And the duplicate stat strip has to be GONE, not merely superseded: two
     * strips one scroll apart saying the same thing is what the handoff asked to resolve.
     */
    public function test_the_band_is_on_the_homepage_with_its_assets_and_the_duplicate_strip_is_gone(): void
    {
        $home = (string) file_get_contents(self::HOME);

        $this->assertStringContainsString("include 'partials/globe-band.twig'", $home);
        $this->assertStringContainsString('/assets/css/globe-band.css', $home);
        foreach ([
            '/assets/js/vendor/d3-7.9.0.min.js',
            '/assets/js/vendor/topojson-client-3.1.0.min.js',
            '/assets/js/globe-band.js',
        ] as $asset) {
            $this->assertStringContainsString($asset, $home,
                "the homepage does not load $asset");
        }

        // The strip's markup and its CSS both go; a dead rule is the next reader's puzzle.
        $this->assertStringNotContainsString('class="hm-stats"', $home);
        $this->assertStringNotContainsString('.hm-stat{', $home);
    }

    /**
     * THE CONTROLLER HANDS THE BAND ITS DATA, WHICH IS THE ONLY THING THAT MAKES IT REAL.
     *
     * Without `globe_countries` the template's own default is an empty list, so the band
     * renders as a globe with no markers and nothing anywhere reports a fault — the
     * failure looks like a platform with no nominees.
     */
    public function test_the_homepage_controller_resolves_the_bands_data(): void
    {
        $php = (string) file_get_contents(__DIR__ . '/../../src/Controllers/HomeController.php');

        $this->assertStringContainsString('GlobeBand::countries()', $php);
        $this->assertStringContainsString("'globe_countries'", $php);
        $this->assertStringContainsString("'globe_note'", $php);
        // The split and the criteria count are rules read per cycle, not literals.
        $this->assertStringContainsString("'community_pct'", $php);
        $this->assertStringContainsString("'jury_criteria'", $php);
    }

    /**
     * ONE RESOLVER FOR "HOW MANY NATIONS", AND THERE WERE TWO.
     *
     * `StatsService` counted distinct country codes over approved PROFILES while the
     * footer, the meta description and the JSON-LD all print `NationsLive::phrase()`,
     * which counts nations with a nominee in a live award. The homepage could print
     * "12 nations live" beside a footer reading "live in Nigeria", on one page load —
     * and the directory figure was both the larger one and the wrong one.
     */
    public function test_the_nations_live_figure_agrees_with_the_footers_sentence(): void
    {
        // A profile from a country with nobody standing in an award: the old count.
        DB::table('gates_profiles')->insert([
            'slug' => 'ke-profile', 'display_name' => 'Registered in Kenya',
            'email' => 'ke@example.test', 'country_code' => 'KE', 'region' => 'east',
            'status' => 'approved',
        ]);
        $this->nominee('Adaeze Nwankwo', 'NG', 10);

        $stats = (new \AfricaGates\Services\StatsService())->summary();

        $this->assertSame(NationsLive::count(), $stats['nations_live']);
        $this->assertSame(1, $stats['nations_live']);
        $this->assertSame('Nigeria', NationsLive::phrase());
    }
}
