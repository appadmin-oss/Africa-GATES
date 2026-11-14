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

        $this->nom(['nominator_state' => 'Lagos', 'nominator_email' => 'a@x.io']);
        $this->nom(['nominator_state' => 'Abuja', 'nominator_email' => 'b@x.io']);
        $e = $this->svc->nomineeEligibility('Ada Obi', 1);
        $this->assertTrue($e['enabled']);
        $this->assertSame(3, $e['min']);
        $this->assertSame(2, $e['distinct_locations']);
        $this->assertFalse($e['eligible']);                       // 2 < 3

        $this->nom(['nominator_state' => 'Kano', 'nominator_email' => 'c@x.io']);
        $e = $this->svc->nomineeEligibility('Ada Obi', 1);
        $this->assertSame(3, $e['distinct_locations']);
        $this->assertTrue($e['eligible']);                        // 3 >= 3

        // Same location again doesn't increase the distinct count.
        $this->nom(['nominator_state' => 'Lagos']);
        $this->assertSame(3, $this->svc->nomineeEligibility('Ada Obi', 1)['distinct_locations']);
    }

    /**
     * The place is TYPED, so one person choosing three states is not three places. Each
     * nominator counts once, at the place on their first nomination, and a nominator is
     * an inbox: Gmail's dots and a "+tag" are the cheapest way round an exact match.
     */
    public function test_one_nominator_counts_once_however_many_places_they_type(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'nomination_eligibility_enabled'], ['value' => '1']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'nomination_min_locations'], ['value' => '3']);

        $this->nom(['nominator_state' => 'Lagos', 'nominator_email' => 'ada.obi.fan@gmail.com']);
        $this->nom(['nominator_state' => 'Abuja', 'nominator_email' => 'adaobifan+2@gmail.com']);
        $this->nom(['nominator_state' => 'Kano',  'nominator_email' => 'AdaObiFan@googlemail.com']);
        $e = $this->svc->nomineeEligibility('Ada Obi', 1);
        $this->assertSame(3, $e['total'], 'every nomination is still counted as a nomination');
        $this->assertSame(1, $e['nominators']);
        $this->assertSame(1, $e['distinct_locations'], 'the first place they typed');
        $this->assertFalse($e['eligible']);

        $this->nom(['nominator_state' => 'Kano',  'nominator_email' => 'second@x.io']);
        $this->nom(['nominator_state' => 'Enugu', 'nominator_email' => 'third@x.io']);
        $e = $this->svc->nomineeEligibility('Ada Obi', 1);
        $this->assertSame([3, 3], [$e['nominators'], $e['distinct_locations']]);
        $this->assertTrue($e['eligible'], 'three people from three places');
    }
}
