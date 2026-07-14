<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\AwardService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Nominee email is compulsory, and the admin-toggleable eligibility rule counts
 * distinct nominator locations (country + state) for a nominee within a cycle.
 */
class NominationEligibilityTest extends TestCase
{
    private AwardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AwardService();
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'nominations']);
    }

    private function nom(array $over): int
    {
        return $this->svc->submitNomination(array_merge([
            'programme_id' => 1, 'nominee_name' => 'Ada Obi', 'nominee_email' => 'ada@x.io', 'reason' => 'Great work',
            'country_code' => 'NG', 'nominator_name' => 'A Nominator', 'nominator_email' => 'n@x.io',
            'nominator_country' => 'NG', 'nominator_state' => 'Lagos',
        ], $over), '1.2.3.4');
    }

    public function test_nominee_email_is_compulsory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->nom(['nominee_email' => '']);
    }

    public function test_invalid_nominee_email_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->nom(['nominee_email' => 'not-an-email']);
    }

    public function test_disabled_rule_is_always_eligible(): void
    {
        $this->nom(['nominator_state' => 'Lagos']);
        $e = $this->svc->nomineeEligibility('Ada Obi', 1);
        $this->assertFalse($e['enabled']);
        $this->assertTrue($e['eligible']);
        $this->assertSame(1, $e['distinct_locations']);
    }

    public function test_enabled_rule_counts_distinct_locations(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'nomination_eligibility_enabled'], ['value' => '1']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'nomination_min_locations'], ['value' => '3']);

        $this->nom(['nominator_state' => 'Lagos']);
        $this->nom(['nominator_state' => 'Abuja']);
        $e = $this->svc->nomineeEligibility('Ada Obi', 1);
        $this->assertTrue($e['enabled']);
        $this->assertSame(3, $e['min']);
        $this->assertSame(2, $e['distinct_locations']);
        $this->assertFalse($e['eligible']);                       // 2 < 3

        $this->nom(['nominator_state' => 'Kano']);
        $e = $this->svc->nomineeEligibility('Ada Obi', 1);
        $this->assertSame(3, $e['distinct_locations']);
        $this->assertTrue($e['eligible']);                        // 3 >= 3

        // Same location again doesn't increase the distinct count.
        $this->nom(['nominator_state' => 'Lagos']);
        $this->assertSame(3, $this->svc->nomineeEligibility('Ada Obi', 1)['distinct_locations']);
    }
}
