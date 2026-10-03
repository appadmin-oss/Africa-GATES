<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\AwardService;

/**
 * Guards the nomination writer: extended nominator_* fields must be persisted
 * when their columns exist. (Regression cover for the per-column guard that
 * replaced the single-probe `nominee_state` check, which silently broke every
 * nomination on a fresh MySQL schema missing the nominator_* columns.)
 */
class NominationSubmitTest extends TestCase
{
    public function test_submit_persists_nominator_location_fields(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'nominations',
        ]);

        $id = (new AwardService())->submitNomination([
            'programme_id'      => 1,
            'nominee_name'      => 'Jane Doe',
            'nominee_email'     => 'jane@x.io',
            'country_code'      => 'ng',
            'reason'            => 'Outstanding work across the community.',
            'nominator_name'    => 'John Roe',
            'nominator_email'   => 'JOHN@x.io',
            'nominator_country' => 'gh',
            'nominator_state'   => 'Greater Accra',
            'nominator_lga'     => 'Accra',
        ], '1.2.3.4');

        $row = DB::table('gates_nominations')->where('id', $id)->first();
        $this->assertSame('GH', $row->nominator_country);          // stored + upper-cased
        $this->assertSame('Greater Accra', $row->nominator_state);
        $this->assertSame('john@x.io', $row->nominator_email);      // core field still written
    }
}
