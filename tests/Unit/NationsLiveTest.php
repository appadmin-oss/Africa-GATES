<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\NationsLive;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "Live in Nigeria" was a sentence somebody typed, in four places, and it went stale
 * upward: the day a second nation had somebody standing in a live award, every one of them
 * understated the platform and nobody was going to edit a meta description over it.
 *
 * The claim is counted from the awards now. What this file holds is what makes the count
 * defensible — that it means a nominee standing in a LIVE award, that the sandbox cannot
 * reach it, and that the sentence it produces is one a person would actually write.
 */
final class NationsLiveTest extends TestCase
{
    private int $liveProgramme = 0;
    private int $liveCategory  = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Nothing pre-existing may decide these assertions.
        DB::table('gates_nominees')->delete();
        DB::table('gates_award_programmes')->update(['is_active' => 0]);

        $this->liveProgramme = $this->programme('gates-live', 'Live programme', 1);
        $this->liveCategory  = $this->category($this->liveProgramme);
    }

    private function programme(string $slug, string $title, int $active): int
    {
        return (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug, 'title' => $title, 'is_active' => $active, 'sort_order' => 1,
        ]);
    }

    private function category(int $programmeId): int
    {
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $programmeId, 'year' => 2026, 'status' => 'voting',
        ]);

        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycle, 'slug' => 'cat' . $cycle, 'title' => 'Category', 'sort_order' => 1,
        ]);
    }

    private function nominee(string $name, string $cc, string $status = 'approved', ?int $cat = null): void
    {
        DB::table('gates_nominees')->insert([
            'category_id' => $cat ?? $this->liveCategory, 'name' => $name,
            'status' => $status, 'country_code' => $cc,
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
    }

    // ══ the sentence ═════════════════════════════════════════════════════════

    public function test_one_nation_is_named_rather_than_counted(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG');

        $this->assertSame(1, NationsLive::count());
        // "live in 1 nation" is worse copy than the hardcoded line it replaces. At one, the
        // honest and warmer thing is the country's name — which is what the page said.
        $this->assertSame('Nigeria', NationsLive::phrase());
    }

    public function test_two_nations_are_both_named(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG');
        $this->nominee('Kwabena Mensah', 'GH');

        $this->assertSame(2, NationsLive::count());
        // Alphabetical by NAME, not by code: ordering by code would print "Ghana and
        // Nigeria" one day and something that looks arbitrary the next.
        $this->assertSame('Ghana and Nigeria', NationsLive::phrase());
    }

    public function test_three_or_more_are_counted(): void
    {
        foreach (['NG' => 'A', 'GH' => 'B', 'KE' => 'C'] as $cc => $n) $this->nominee($n, $cc);

        $this->assertSame(3, NationsLive::count());
        $this->assertSame('3 nations', NationsLive::phrase());
    }

    /**
     * Nothing live yet says what the page always said.
     *
     * A footer reading "live in 0 nations" on the morning a database is slow, or on a fresh
     * deployment, is worse than one that is a day stale.
     */
    public function test_with_nothing_live_the_phrase_falls_back(): void
    {
        $this->assertSame(0, NationsLive::count());
        $this->assertSame('Nigeria', NationsLive::phrase());
        $this->assertSame('Kenya', NationsLive::phrase('Kenya'));
    }

    // ══ what does and does not count ══════════════════════════════════════════

    /**
     * THE SANDBOX CANNOT REACH IT, AND NOT BECAUSE A FILTER REMEMBERS TO EXCLUDE IT.
     *
     * `DemoSeeder` contains the demo in its own programme with `is_active = 0`, precisely so
     * public readers exclude it by reaching only for live programmes. A `country_code` or
     * name-prefix filter here would have been the "wherever it matters" rule that gets
     * missed once — and the miss would be the platform claiming a nation off a rehearsal.
     */
    public function test_a_nominee_in_an_inactive_programme_is_not_a_live_nation(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG');

        $demo = $this->category($this->programme('demo-sandbox', 'DEMO — Sandbox', 0));
        $this->nominee('DEMO — Kwabena Mensah', 'GH', 'approved', $demo);

        $this->assertSame(['NG'], NationsLive::codes(),
            'the sandbox put a nation on the front page');
    }

    /** A nomination nobody has approved is not a nation this platform is running in. */
    public function test_an_unapproved_nominee_is_not_a_live_nation(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG');
        $this->nominee('Someone Pending', 'GH', 'pending');

        $this->assertSame(['NG'], NationsLive::codes());
    }

    /** A winner is still a live nation — the award ending does not end the presence. */
    public function test_a_winner_still_counts(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG', 'winner');

        $this->assertSame(['NG'], NationsLive::codes());
    }

    /** A blank country cannot become a nation, and cannot become an empty label either. */
    public function test_a_missing_country_is_not_a_nation(): void
    {
        $this->nominee('Adaeze Nwankwo', 'NG');
        $this->nominee('No Country', '');

        $this->assertSame(['NG'], NationsLive::codes());
    }

    // ══ and something reads it ═══════════════════════════════════════════════

    /**
     * A resolver with no caller is this codebase's most expensive bug, and this one exists
     * ONLY to replace typed copy. If the templates go back to saying "Nigeria", the count is
     * right and the site still lies.
     */
    public function test_the_copy_actually_asks_for_it(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['templates/layout/gates.twig', 'templates/layout/footer.twig'] as $f) {
            $src = (string) file_get_contents($root . '/' . $f);
            $this->assertStringContainsString('nations_live()', $src,
                $f . ' states which nations GATES is live in without asking');
            $this->assertStringNotContainsString('live in Nigeria', $src,
                $f . ' still has the nation typed into it');
        }

        // COMMENTS STRIPPED, and the first run of this scan is why: the note explaining
        // the fix quotes the sentence it removed, so the test reported the fix as the
        // fault. That is the fourth time a scanner in this repository has been fooled by
        // the comment describing the bug it was written to find.
        $guide = (string) preg_replace(
            ['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
            (string) file_get_contents($root . '/src/Services/GuideService.php'));

        $this->assertStringNotContainsString('live in Nigeria, building toward 54', $guide,
            'the one place that explains the platform to people still has it typed in');
        $this->assertStringContainsString('NationsLive::phrase()', $guide,
            'the guide states which nations GATES is live in without asking');
    }
}
