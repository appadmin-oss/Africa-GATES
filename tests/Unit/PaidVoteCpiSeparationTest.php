<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\NomineeScoringService;

/**
 * Integrity contract: purchased ("bonus") votes must NOT move the Cultural Power
 * Index or the cohort normalisation. CPI's community component is computed from
 * ORGANIC votes only (gates_nominees.organic_vote_count); vote_count is a display
 * total that includes the paid "supporter boost".
 */
class PaidVoteCpiSeparationTest extends TestCase
{
    private function seedCohort(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'),
            'status' => 'voting', 'voting_close' => Carbon::now()->addDays(7)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 10, 'cycle_id' => 1, 'slug' => 'cat-10', 'title' => 'Category',
        ]);
        // A: 10 organic, no bonus.  B: 2 organic + 100 paid (vote_count 102).
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 10, 'name' => 'A', 'country_code' => 'NG',
             'status' => 'approved', 'vote_count' => 10,  'organic_vote_count' => 10],
            ['id' => 2, 'category_id' => 10, 'name' => 'B', 'country_code' => 'NG',
             'status' => 'approved', 'vote_count' => 102, 'organic_vote_count' => 2],
        ]);
    }

    public function test_cpi_normalises_over_organic_votes_not_paid(): void
    {
        $this->seedCohort();

        $scores = (new NomineeScoringService())->scoreCategory(10);

        // No judges → community-only. cohortMax = 10 (organic), weight 0.45.
        // A: 0.45 * (10/10) * 1000 = 450.   B: 0.45 * (2/10) * 1000 = 90.
        $this->assertSame(450, $scores[1]['cpi_score']);
        $this->assertSame(90,  $scores[2]['cpi_score']);
    }

    public function test_paid_votes_cannot_overtake_organic_leader(): void
    {
        $this->seedCohort();

        $scores = (new NomineeScoringService())->scoreCategory(10);

        // Despite B's 100 paid votes (vote_count 102 vs A's 10), A's larger
        // ORGANIC base must keep it ahead on CPI.
        $this->assertGreaterThan($scores[2]['cpi_score'], $scores[1]['cpi_score']);
    }
}
