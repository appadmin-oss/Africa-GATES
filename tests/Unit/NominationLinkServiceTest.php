<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NominationLinkService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Shareable prefill nomination links: opaque 32-byte tokens, whitelisted
 * nominee-side payload only (nominator PII never rides along), expiry, and a
 * hit counter. Resolution is a plain unique-index lookup — no enumeration
 * surface beyond guessing 256 bits.
 */
final class NominationLinkServiceTest extends TestCase
{
    private NominationLinkService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new NominationLinkService();
    }

    public function test_create_and_resolve_round_trip(): void
    {
        $token = $this->svc->create([
            'nominee_name'  => 'Ada Obi',
            'nominee_email' => 'ada@x.io',
            'nominee_phone' => '+2348031234567',
            'country_code'  => 'NG',
            'nominee_state' => 'Enugu',
            'nominee_lga'   => 'Nsukka',
            'nominee_org'   => 'Uni of Nigeria',
            'programme_id'  => 3,
            'category_id'   => 7,
        ], '1.2.3.4');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);

        $p = $this->svc->resolve($token);
        $this->assertNotNull($p);
        $this->assertSame('Ada Obi', $p['nominee_name']);
        $this->assertSame('NG', $p['country_code']);
        $this->assertSame(3, $p['programme_id']);
    }

    public function test_payload_is_whitelisted_nominee_fields_only(): void
    {
        $token = $this->svc->create([
            'nominee_name'    => 'Ada Obi',
            'nominator_name'  => 'Someone Else',   // must NOT be stored
            'nominator_email' => 'private@x.io',   // must NOT be stored
            'reason'          => 'their story',    // nominator's words — not shared
            'evil'            => '<script>',
        ], null);

        $raw = (string) DB::table('gates_nomination_links')->value('payload');
        $this->assertStringNotContainsString('private@x.io', $raw);
        $this->assertStringNotContainsString('Someone Else', $raw);
        $this->assertStringNotContainsString('their story', $raw);

        $p = $this->svc->resolve($token);
        $this->assertArrayNotHasKey('nominator_email', $p);
        $this->assertArrayNotHasKey('reason', $p);
        $this->assertArrayNotHasKey('evil', $p);
    }

    public function test_resolve_counts_hits(): void
    {
        $token = $this->svc->create(['nominee_name' => 'Ada Obi'], null);
        $this->svc->resolve($token);
        $this->svc->resolve($token);
        $this->assertSame(2, (int) DB::table('gates_nomination_links')->value('hits'));
    }

    public function test_expired_link_resolves_null(): void
    {
        $token = $this->svc->create(['nominee_name' => 'Ada Obi'], null);
        DB::table('gates_nomination_links')->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertNull($this->svc->resolve($token));
    }

    public function test_unknown_or_malformed_token_resolves_null(): void
    {
        $this->assertNull($this->svc->resolve(str_repeat('a', 64)));
        $this->assertNull($this->svc->resolve('short'));
        $this->assertNull($this->svc->resolve(''));
    }

    public function test_create_requires_a_nominee_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc->create(['nominee_email' => 'a@b.co'], null);
    }

    public function test_values_are_length_capped(): void
    {
        $token = $this->svc->create(['nominee_name' => str_repeat('A', 500) . ' ' . str_repeat('B', 500)], null);
        $p = $this->svc->resolve($token);
        $this->assertLessThanOrEqual(200, mb_strlen($p['nominee_name']));
    }
}
