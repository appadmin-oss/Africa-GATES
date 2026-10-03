<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\AwardService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A programme's categories lead with the hottest race.
 *
 * `sort_order` is an admin's filing order — stable, and for that reason useless
 * on a page people open to find out what is happening. The runaway race and the
 * category nobody has voted in sat wherever someone typed them months ago.
 *
 * The fallback matters as much as the ordering: with no votes anywhere there is
 * nothing to rank by, so the page must look exactly as it did before rather than
 * shuffling into an order derived from a column of zeroes.
 */
final class ProgrammeCategoryOrderTest extends TestCase
{
    private string $slug = 'heat-test';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_nominees')->delete();
        DB::table('gates_award_categories')->delete();
        DB::table('gates_award_cycles')->delete();
        DB::table('gates_award_programmes')->where('slug', $this->slug)->delete();
    }

    /** @param array<string,int[]> $spec category title => nominee vote counts */
    private function seed(array $spec): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $this->slug, 'title' => 'Heat Test', 'is_active' => 1, 'sort_order' => 1,
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => (int) date('Y'), 'status' => 'voting',
        ]);

        $order = 0;
        foreach ($spec as $title => $votes) {
            $catId = (int) DB::table('gates_award_categories')->insertGetId([
                'cycle_id' => $cid, 'slug' => strtolower(str_replace(' ', '-', $title)),
                'title' => $title, 'sort_order' => $order++,
            ]);
            foreach ($votes as $i => $v) {
                DB::table('gates_nominees')->insert([
                    'category_id' => $catId, 'name' => $title . ' nominee ' . $i,
                    'vote_count' => $v, 'status' => 'approved',
                    'nominated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /** @return list<string> */
    private function titles(): array
    {
        $p = (new AwardService())->getProgrammeBySlug($this->slug);
        return array_column($p['categories'], 'title');
    }

    public function test_the_category_with_the_strongest_leader_comes_first(): void
    {
        // Filing order is Alpha, Beta, Gamma; the race says otherwise.
        $this->seed(['Alpha' => [3, 1], 'Beta' => [90, 4], 'Gamma' => [40, 39]]);
        $this->assertSame(['Beta', 'Gamma', 'Alpha'], $this->titles());
    }

    /**
     * It is the LEADER that ranks a category, not the total.
     *
     * A category of ten nominees on nine votes each has 90 votes and no story;
     * one nominee on 50 is the race people came to read about.
     */
    public function test_ranking_is_by_the_leader_not_the_category_total(): void
    {
        $this->seed(['Spread' => [9, 9, 9, 9, 9, 9, 9, 9, 9, 9], 'Runaway' => [50]]);
        $this->assertSame(['Runaway', 'Spread'], $this->titles());
    }

    public function test_with_no_votes_the_admin_order_is_kept(): void
    {
        $this->seed(['Alpha' => [0, 0], 'Beta' => [0], 'Gamma' => [0, 0, 0]]);
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $this->titles(),
            'a column of zeroes must not shuffle the page');
    }

    public function test_categories_with_no_votes_sink_below_those_with_votes(): void
    {
        $this->seed(['Empty' => [], 'Quiet' => [0, 0], 'Live' => [7]]);
        $this->assertSame('Live', $this->titles()[0]);
    }

    /** A nominee on zero votes is not "leading" — there is no race yet to lead. */
    public function test_a_zero_vote_category_reports_no_leader(): void
    {
        $this->seed(['Quiet' => [0, 0], 'Live' => [7]]);
        $cats = array_column((new AwardService())->getProgrammeBySlug($this->slug)['categories'], null, 'title');

        $this->assertNull($cats['Quiet']['leader']);
        $this->assertNotNull($cats['Live']['leader']);
        $this->assertSame(7, $cats['Live']['leader']['votes']);
    }

    public function test_counts_and_totals_are_per_category(): void
    {
        $this->seed(['Alpha' => [5, 3, 2], 'Beta' => [10]]);
        $cats = array_column((new AwardService())->getProgrammeBySlug($this->slug)['categories'], null, 'title');

        $this->assertSame(3, $cats['Alpha']['nominee_count']);
        $this->assertSame(10, $cats['Alpha']['total_votes']);
        $this->assertSame(1, $cats['Beta']['nominee_count']);
        $this->assertSame(10, $cats['Beta']['total_votes']);
    }

    /** An unapproved nominee is not on the board, so it cannot lead a category. */
    public function test_only_approved_nominees_count(): void
    {
        $this->seed(['Alpha' => [5], 'Beta' => [1]]);
        $cat = (int) DB::table('gates_award_categories')->where('title', 'Beta')->value('id');
        DB::table('gates_nominees')->insert([
            'category_id' => $cat, 'name' => 'Pending superstar', 'vote_count' => 999,
            'status' => 'pending', 'nominated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(['Alpha', 'Beta'], $this->titles());
    }
}
