<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{CurrencyService, CacheService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Currency conversion: off until the admin enables it, rates come from the cache
 * (no network in tests), NGN is the pass-through base, and formatting picks sane
 * decimals. Conversion is display-only — it never alters the NGN charge.
 */
class CurrencyServiceTest extends TestCase
{
    private function svc(): CurrencyService
    {
        return new CurrencyService(new CacheService());
    }

    /** Pre-seed the FX cache so rates() resolves locally (never hits the API). */
    private function seedRates(array $rates): void
    {
        DB::table('gates_cache')->updateOrInsert(
            ['cache_key' => 'fx:ngn:v1'],
            ['payload' => json_encode($rates), 'expires_at' => Carbon::now()->addHour()->toDateTimeString(), 'created_at' => Carbon::now()->toDateTimeString()]
        );
    }

    public function test_disabled_by_default_and_enabled_when_set(): void
    {
        $this->assertFalse($this->svc()->enabled());
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'currency_conversion'], ['value' => '1']);
        $this->assertTrue($this->svc()->enabled());
    }

    public function test_validity_and_symbols(): void
    {
        $s = $this->svc();
        $this->assertTrue($s->isValid('USD'));
        $this->assertFalse($s->isValid('XXX'));
        $this->assertSame('$', $s->symbol('USD'));
        $this->assertSame('₦', $s->symbol('NGN'));
    }

    public function test_convert_and_format_use_cached_rate(): void
    {
        $this->seedRates(['USD' => 0.001, 'GBP' => 0.0008]);
        $s = $this->svc();
        $this->assertSame(1.0, $s->rate('NGN'));
        $this->assertEqualsWithDelta(0.001, $s->rate('USD'), 1e-9);
        $this->assertEqualsWithDelta(10.0, $s->convert(10000, 'USD'), 1e-6);
        $this->assertSame('$10.00', $s->format(10000, 'USD'));   // < 100 → 2dp
        $this->assertSame('₦10,000', $s->format(10000, 'NGN'));  // base pass-through
    }

    public function test_format_large_value_has_no_decimals(): void
    {
        $this->seedRates(['USD' => 0.001]);
        $this->assertSame('$200', $this->svc()->format(200000, 'USD')); // 200 ≥ 100 → 0dp
    }
}
