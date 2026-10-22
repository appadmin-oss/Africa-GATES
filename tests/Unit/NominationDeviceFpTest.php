<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{AwardService, SpamService};
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Device-fingerprint dedupe: a single device may nominate a given person only
 * once per cycle (so it can't inflate counts or the "different locations" tally),
 * but other devices and other nominees are unaffected.
 */
class NominationDeviceFpTest extends TestCase
{
    private AwardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AwardService(new SpamService());
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int)date('Y'), 'status' => 'nominations']);
    }

    private function data(array $over = []): array
    {
        return array_merge([
            'programme_id' => 1, 'nominee_name' => 'Ada Obi', 'nominee_email' => 'ada.nominee@x.io',
            'reason' => 'Outstanding cultural impact',
            'country_code' => 'NG', 'nominator_name' => 'Bob Roy', 'nominator_email' => 'bob@x.io',
            'device_fp' => 'DEVICE-A',
        ], $over);
    }

    public function test_same_device_same_nominee_is_blocked(): void
    {
        $this->svc->submitNomination($this->data(), '1.2.3.4');
        $this->expectException(\RuntimeException::class);
        // Different nominator + IP, but same device + same nominee → blocked.
        $this->svc->submitNomination($this->data(['nominator_email' => 'eve@x.io']), '9.9.9.9');
    }

    public function test_same_device_different_nominee_is_allowed(): void
    {
        $this->svc->submitNomination($this->data(), '1.2.3.4');
        $id = $this->svc->submitNomination($this->data(['nominee_name' => 'Chidi Eze']), '1.2.3.4');
        $this->assertGreaterThan(0, $id);
    }

    public function test_different_device_same_nominee_is_allowed(): void
    {
        $this->svc->submitNomination($this->data(), '1.2.3.4');
        $id = $this->svc->submitNomination($this->data(['device_fp' => 'DEVICE-B']), '1.2.3.4');
        $this->assertGreaterThan(0, $id);
        // Both nominations stored, with distinct hashed fingerprints.
        $this->assertSame(2, (int)DB::table('gates_nominations')->whereNotNull('device_fp')->count());
    }

    public function test_missing_fingerprint_does_not_block(): void
    {
        $this->svc->submitNomination($this->data(['device_fp' => '']), '1.2.3.4');
        $id = $this->svc->submitNomination($this->data(['device_fp' => '']), '1.2.3.4');
        $this->assertGreaterThan(0, $id); // no fp → falls back to existing rate limiting only
    }
}
