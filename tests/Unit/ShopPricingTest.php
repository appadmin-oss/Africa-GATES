<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\ShopPricing;
use AfricaGates\Controllers\ShopCheckoutController;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Location-based shop pricing: a per-region multiplier (gates_settings JSON) scales
 * the base naira price, and priceCart() — the ONLY authoritative total — applies it
 * server-side. With nothing configured, prices must equal the base (no behaviour
 * change). Multipliers are clamped to a sane 0.1..10 range.
 */
class ShopPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // gates_products is migration-created in prod (absent from the base test
        // schema) — create a minimal one for pricing.
        DB::statement('CREATE TABLE IF NOT EXISTS gates_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, name TEXT, price_naira INTEGER,
            is_active INTEGER DEFAULT 1, delivery_regions TEXT, stock INTEGER, category TEXT, sort_order INTEGER
        )');
        DB::table('gates_products')->insert(['slug' => 'tee', 'name' => 'Heritage Tee', 'price_naira' => 1000, 'is_active' => 1]);
        DB::table('gates_settings')->where('key_name', 'shop_region_mult')->delete();
    }

    private function setMultipliers(array $map): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'shop_region_mult'], ['value' => json_encode($map)]);
    }

    public function test_default_is_base_price_when_unconfigured(): void
    {
        $this->assertSame(1000, ShopPricing::adjust(1000, 'South West'));
        $this->assertFalse(ShopPricing::isActive());
        $priced = ShopCheckoutController::priceCart(['tee' => ['qty' => 2]], 'South West');
        $this->assertSame(2000, $priced['subtotal']); // unchanged from base behaviour
    }

    public function test_adjust_applies_and_clamps(): void
    {
        $this->setMultipliers(['South West' => 1.25, 'North East' => 0.9, 'North West' => 99]);
        $this->assertSame(1250, ShopPricing::adjust(1000, 'South West'));
        $this->assertSame(900,  ShopPricing::adjust(1000, 'North East'));
        $this->assertSame(10000, ShopPricing::adjust(1000, 'North West')); // clamped to 10×
        $this->assertSame(1000, ShopPricing::adjust(1000, 'South East'));  // unset region → base
        $this->assertTrue(ShopPricing::isActive());
    }

    public function test_pricecart_charges_region_adjusted_total(): void
    {
        $this->setMultipliers(['South West' => 1.25]);
        $sw = ShopCheckoutController::priceCart(['tee' => ['qty' => 2]], 'South West');
        $this->assertSame(2500, $sw['subtotal']);          // 2 × round(1000 × 1.25)
        $this->assertSame(1250, $sw['lines'][0]['price_naira']);
        $this->assertSame(1000, $sw['lines'][0]['base_naira']);

        $ne = ShopCheckoutController::priceCart(['tee' => ['qty' => 2]], 'North East');
        $this->assertSame(2000, $ne['subtotal']);          // no multiplier → base
    }

    public function test_invalid_region_value_ignored(): void
    {
        // Non-positive / unknown-region entries are dropped, not applied.
        $this->setMultipliers(['South West' => 0, 'Mars' => 5]);
        $this->assertSame(1000, ShopPricing::adjust(1000, 'South West'));
        $this->assertFalse(ShopPricing::isActive());
    }
}
