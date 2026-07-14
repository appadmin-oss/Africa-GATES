<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\AwardService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Nominee contact contract (batch 3): EMAIL OR PHONE — at least one valid
 * contact point is required; either alone proceeds. Phones are normalised to
 * E.164 (Twilio / WhatsApp Business API compatible) using the nominee's
 * country, and the normalised value is what gets stored. A contact the
 * nominator typed but that fails validation is a hard error — never silently
 * dropped.
 */
class NominationContactTest extends TestCase
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
        ], $over), '1.2.3.4');
    }

    public function test_phone_only_proceeds_and_is_stored_e164(): void
    {
        $id = $this->nom(['nominee_email' => '', 'nominee_phone' => '0803 123 4567']);
        $row = DB::table('gates_nominations')->find($id);
        $this->assertSame('+2348031234567', $row->nominee_phone);
        $this->assertTrue($row->nominee_email === null || $row->nominee_email === '');
    }

    public function test_email_only_proceeds(): void
    {
        $id = $this->nom(['nominee_phone' => '']);
        $row = DB::table('gates_nominations')->find($id);
        $this->assertSame('ada@x.io', $row->nominee_email);
    }

    public function test_both_proceed_with_normalized_phone(): void
    {
        $id = $this->nom(['nominee_phone' => '+233 20 123 4567']);
        $row = DB::table('gates_nominations')->find($id);
        $this->assertSame('ada@x.io', $row->nominee_email);
        $this->assertSame('+233201234567', $row->nominee_phone);
    }

    public function test_neither_contact_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->nom(['nominee_email' => '', 'nominee_phone' => '']);
    }

    public function test_unparseable_phone_without_email_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->nom(['nominee_email' => '', 'nominee_phone' => 'ask around for me']);
    }

    public function test_typed_but_invalid_phone_is_rejected_even_with_valid_email(): void
    {
        // Never silently drop a contact the nominator typed.
        $this->expectException(\RuntimeException::class);
        $this->nom(['nominee_phone' => '12 34']);
    }

    public function test_enterprise_reference_is_persisted_on_submit(): void
    {
        $id = $this->nom([]);
        $row = DB::table('gates_nominations')->find($id);
        $this->assertSame(\AfricaGates\Support\Reference::nomination($id, (int) date('Y')), $row->reference);
        $this->assertTrue(\AfricaGates\Support\Reference::isValid((string) $row->reference));
    }

    public function test_phone_normalized_with_nominee_country(): void
    {
        $id = $this->nom(['nominee_email' => '', 'nominee_phone' => '0712345678', 'country_code' => 'KE']);
        $row = DB::table('gates_nominations')->find($id);
        $this->assertSame('+254712345678', $row->nominee_phone);
    }
}
