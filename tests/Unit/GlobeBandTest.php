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
    // The band rebuilt in Phase 4 (WeAreAfrica.dc.html); the old partial, sheet and script
    // were destroyed (inventory _partials.md, _stylesheets.md, _scripts.md).
    private const TWIG     = __DIR__ . '/../../templates/partials/we-are-africa.twig';
    private const HOME     = __DIR__ . '/../../templates/pages/home.twig';
    private const DOC      = __DIR__ . '/../../docs/GLOBE-BAND.md';
    private const CSS      = __DIR__ . '/../../public/assets/css/components/home.css';
    private const JS       = __DIR__ . '/../../public/assets/js/we-are-africa.js';

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
        // Still competing, so the plain dot — see the marker-grammar test below.
        $this->assertFalse($out[0]['decided']);
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
     * THE RING IS RARE, AND THE TEST IS WHAT KEEPS IT RARE.
     *
     * The design carries four plain dots and two ringed markers; the ring is what the eye
     * lands on. The first cut here mapped it onto "this nation has any votes" — true of
     * nearly every nation the moment an award opens — so the band rendered five rings and
     * one dot, the hierarchy exactly inverted, and it read as noise rather than as a map
     * with a point of interest. A highlight almost everything qualifies for is a
     * background.
     *
     * So the ring means an award has been DECIDED here, which is rare by nature.
     */
    public function test_only_a_nation_with_a_decided_award_gets_the_ringed_marker(): void
    {
        $this->nominee('Busy but undecided', 'ZM', 9_000);
        $this->nominee('Also standing',      'GH', 4_000);
        $this->nominee('Crowned',            'NG', 12, 'winner');

        $by = [];
        foreach (GlobeBand::countries() as $c) $by[$c['code']] = $c['decided'];

        $this->assertSame(['ZM' => false, 'GH' => false, 'NG' => true],
            ['ZM' => $by['ZM'], 'GH' => $by['GH'], 'NG' => $by['NG']]);
        // The busiest nation by a wide margin is NOT the ringed one — votes decide the
        // ORDER of the markers and nothing about their shape.
        $this->assertSame('ZM', GlobeBand::countries()[0]['code']);
    }

    /** A runner-up is not a decided award: the category still has a standing to publish. */
    public function test_a_runner_up_alone_does_not_ring_a_nation(): void
    {
        $this->nominee('Second place', 'KE', 500, 'runner_up');

        $this->assertFalse(GlobeBand::countries()[0]['decided']);
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
     * THE DEVELOPER GUIDE DESCRIBES THE RING THAT SHIPPED, NOT THE ONE THAT WAS REJECTED.
     *
     * The sweep below it reads the SCRIPT for the retired city model. Nothing read the
     * guide, and the guide is what somebody rebuilding this component works from — so it
     * went on saying the ringed marker meant "a nation whose nominees have recorded
     * ballots" for as long as it liked. That is the first cut, and {@see GlobeBand}'s own
     * docblock explains at length why it was thrown away: recorded votes are true of
     * nearly every nation the moment an award opens, so the ring fired almost everywhere
     * and the band came out five rings to one dot, the hierarchy exactly inverted.
     *
     * A component whose guide documents the mapping it was built to reject is one reader
     * away from having it back. `GlobeBand` decides the ring on `decided` — a crowned
     * winner — so that is what the guide has to say, and this is what holds it there.
     */
    public function test_the_guide_describes_the_ring_that_shipped(): void
    {
        $doc = (string) file_get_contents(self::DOC);

        $this->assertStringNotContainsString('recorded ballots', $doc,
            'the guide is describing the retired "any votes" ring — see GlobeBand::countries()');

        // And says the live one, so the assertion above is not satisfied by silence.
        $this->assertMatchesRegularExpression('~ring|outlined marker~i', $doc,
            'the guide still has to explain the two marker shapes');
        $this->assertStringContainsString('decided', $doc,
            'the ring means an award has been decided there');
    }

    // ══ and it is actually on the page ═══════════════════════════════════════

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
        $this->assertStringContainsString('GlobeBand::note()', $php);
        $this->assertStringContainsString("'globe_countries'", $php);
        $this->assertStringContainsString("'globe_note'", $php);
    }

    // ══ the rebuilt band (Phase 4): its rules, re-asserted from the destroyed guards ══

    /** Code with comments stripped: a browser never runs a comment. */
    private static function code(string $path): string
    {
        $src = (string) file_get_contents($path);
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);

        return (string) preg_replace('~(?<![:"\'])//[^\n]*~', '', $src);
    }

    /**
     * A COMPONENT WITH NO INCLUDE IS §18 AGAIN — every piece complete, nothing serving it.
     * And the markers on the page are exactly GlobeBand's.
     */
    public function test_the_band_is_on_the_homepage_with_its_markers_and_its_script(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG', 40, 'winner');
        $this->nominee('Achieng Otieno', 'KE', 12);
        \AfricaGates\Services\HomeFront::forget();
        DB::table('gates_cache')->delete();
        $html = \Tests\Support\ChromeRender::html('/');

        $this->assertStringContainsString('we-are-africa.js', $html);
        $this->assertStringContainsString('components/home.css', $html);
        preg_match_all('~data-geo="([^"]+)"~', $html, $m);
        $this->assertSame(array_column(GlobeBand::countries(), 'geo'), $m[1]);
        // The decided nation wears the ring; the other does not.
        $this->assertMatchesRegularExpression('~class="waa__m waa__m--won"[^>]*data-geo="Nigeria"~', $html);
        $this->assertMatchesRegularExpression('~class="waa__m"[^>]*data-geo="Kenya"~', $html);
        // The note is GlobeBand's, not the DC's invented "just joined" ticker.
        $this->assertStringContainsString(htmlspecialchars(GlobeBand::note(), ENT_QUOTES), $html);
    }

    public function test_every_mapped_country_is_in_the_scripts_africa_set(): void
    {
        $js = (string) file_get_contents(self::JS);
        $this->assertSame(1, preg_match('~var AFRICA = new Set\(\[(.*?)\]\)~s', $js, $m), 'the script has no AFRICA set');
        preg_match_all('~"((?:[^"\\\\]|\\\\.)*)"~', $m[1], $names);
        foreach (GlobeBand::GEOMETRY as $code => $geo) {
            $this->assertContains($geo, $names[1], "$code ($geo) is not in the script's Africa set — no outline, no polygon to click");
        }
    }

    /** THE FAKE SET CANNOT COME BACK, AND IT IS THE KIND THAT WOULD. */
    public function test_the_script_carries_no_invented_figures_and_no_routes(): void
    {
        $js = self::code(self::JS);
        foreach (['FALLBACK', 'CITIES', 'PHOTOS', 'just joined', 'verify_seconds', 'ballots', 'unsplash', 'setInterval'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $js, "the script carries the retired \"$bad\"");
        }
        // Markers come from the server's buttons, never from a list in the script.
        $this->assertStringContainsString("querySelectorAll('.waa__m')", $js);
        $this->assertStringContainsString('dataset.geo', $js);
    }

    /** A press on a marker must never be captured by the drag: only the canvas captures. */
    public function test_a_marker_press_starts_no_drag(): void
    {
        $js = self::code(self::JS);
        $this->assertSame(1, preg_match_all('~addEventListener\(\'pointerdown\'~', $js));
        $this->assertStringContainsString("canvas.addEventListener('pointerdown'", $js);
        $this->assertStringContainsString('canvas.setPointerCapture', $js);
        $this->assertStringNotContainsString('band.setPointerCapture', $js);
    }

    /** WCAG 2.5.7 and 2.4.7: turnable without a drag; a focused far-side marker is brought round. */
    public function test_the_globe_can_be_turned_without_a_drag_and_focus_brings_a_marker_round(): void
    {
        $js = self::code(self::JS);
        foreach (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'] as $k) $this->assertStringContainsString($k, $js);
        $this->assertMatchesRegularExpression("~addEventListener\\('focus', function \\(\\) \\{ if \\(b\\._far~", $js);
        $twig = (string) file_get_contents(self::TWIG);
        $this->assertMatchesRegularExpression('~<canvas[^>]*tabindex="0"~', $twig, 'the globe cannot take focus, so the arrow keys reach nothing');
        $this->assertMatchesRegularExpression('~<button type="button" class="waa__m~', $twig, 'a marker is not a real button');
    }

    /** Motion is never forced: reduced motion snaps rather than refusing to turn. */
    public function test_motion_is_not_forced_on_anybody(): void
    {
        $js = self::code(self::JS);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $js);
        $this->assertMatchesRegularExpression('~if \\(reduced\\(\\)\\) \\{ S\\.lam \\+= dLam~', $js);
    }

    /** The stage must leave the vertical axis to the browser, or a finger cannot scroll past it. */
    public function test_the_stage_does_not_cancel_the_page_scroll(): void
    {
        $css = self::code(self::CSS);
        $this->assertMatchesRegularExpression('~\\.waa__globe\\{[^}]*touch-action:pan-y~', $css);
        $this->assertDoesNotMatchRegularExpression('~touch-action:\\s*none~', $css);
    }

    /** One outline: the selected country's. Fifty-four strokes are a diagram, not a map. */
    public function test_only_the_selected_country_is_outlined(): void
    {
        $js = self::code(self::JS);
        $this->assertSame(1, substr_count($js, 'colours.pickLine'), 'the pick colour is drawn somewhere besides the selection');
        $this->assertStringContainsString('var sel = S.pick && S.byName[S.pick.dataset.geo]', $js);
        $this->assertStringContainsString('isPointInPath', $js, 'a click on the land is hit-tested against the drawn country');
        // Colour is the palette's: no colour literal in the script either.
        $this->assertDoesNotMatchRegularExpression('~#[0-9a-f]{3,8}\\b|rgba?\\(\\s*\\d~i', $js);
    }

    /** The card has only the rows the platform can fill. */
    public function test_the_country_card_has_no_row_the_platform_cannot_fill(): void
    {
        $twig = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents(self::TWIG));
        preg_match_all('~<dt\\b~', $twig, $rows);
        $this->assertCount(2, $rows[0], 'the card has a row beyond nominees standing and votes cast');
        foreach (['Verification node', 'Median verification', 'latency'] as $retired) {
            $this->assertStringNotContainsStringIgnoringCase($retired, $twig);
        }
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
