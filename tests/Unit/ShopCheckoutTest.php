<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\ShopCheckoutController as Co;
use AfricaGates\Services\{ShopDiscount, ShopShipping};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What a basket actually costs, decided in one place.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS THE MOST IMPORTANT TEST FILE IN THE SHOP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see Co::priceCart()} and {@see Co::totals()} decide the number that reaches the payment
 * gateway. Confirmation refuses a payment that does not equal the order, so an error here does
 * not merely undercharge — it makes the order unconfirmable and the money unmatchable, which is
 * the worst of the three possible failures.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. THE CART KEY CARRIES THE VARIANT. It used to be the slug alone, so a shirt in M and the
 *      same shirt in XL were one cart line and the second choice overwrote the first.
 *   2. A CART SAVED BEFORE VARIANTS EXISTED STILL PRICES. A bare slug is still accepted, or
 *      every basket in somebody's localStorage would have become unreadable on deploy.
 *   3. STOCK IS ENFORCED. `stock` existed and nothing read it: a sold-out item could be added,
 *      paid for and confirmed with the number floored at zero and nobody told.
 *   4. THE DISCOUNT COMES OFF THE ELIGIBLE PART. A code for Apparel in a basket of a shirt and
 *      a mug takes its percentage off the shirt; anything else gives money away on items the
 *      promotion never mentioned.
 *   5. DELIVERY IS QUOTED AFTER THE DISCOUNT. A free-shipping threshold that ignored a discount
 *      would promise free delivery on an order that no longer reaches it.
 *   6. NOTHING GOES NEGATIVE, AND NOTHING IS SILENTLY CHANGED. A trimmed quantity or a dropped
 *      line comes back as a sentence, because a total that does not match the last screen
 *      somebody saw needs one.
 */
final class ShopCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_product_variants')->delete();
        DB::table('gates_products')->delete();
        DB::table('gates_shop_codes')->delete();
        DB::table('gates_orders')->delete();
        DB::table('gates_settings')->whereIn('key_name', [
            ShopShipping::RATES_KEY, ShopShipping::THRESHOLD_KEY, 'shop_region_mult',
        ])->delete();
    }

    /** @param array<string,mixed> $over */
    private function product(array $over = []): int
    {
        return (int) DB::table('gates_products')->insertGetId(array_merge([
            'slug' => 'tee', 'name' => 'The Tee', 'category' => 'Apparel',
            'price_naira' => 10000, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    /** @param array<string,mixed> $over */
    private function variant(int $productId, array $over = []): int
    {
        return (int) DB::table('gates_product_variants')->insertGetId(array_merge([
            'product_id' => $productId, 'label' => 'M', 'axis' => 'Size',
            'price_delta_naira' => 0, 'stock' => null, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    /** @param array<string,mixed> $over */
    private function code(array $over = []): int
    {
        return (int) DB::table('gates_shop_codes')->insertGetId(array_merge([
            'code' => 'GATES10', 'label' => 'Community rate', 'kind' => 'percent', 'amount' => 10,
            'max_per_email' => 1, 'used_count' => 0, 'free_shipping' => 0, 'is_active' => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    private function rates(array $map, ?int $freeOver = null): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => ShopShipping::RATES_KEY, 'value' => json_encode($map),
        ]);
        if ($freeOver !== null) {
            DB::table('gates_settings')->insert([
                'key_name' => ShopShipping::THRESHOLD_KEY, 'value' => (string) $freeOver,
            ]);
        }
    }

    // ══ 1. the cart key ══════════════════════════════════════════════════════

    public function test_two_sizes_of_one_product_are_two_cart_lines(): void
    {
        $id = $this->product();
        $m  = $this->variant($id, ['label' => 'M']);
        $xl = $this->variant($id, ['label' => 'XL', 'price_delta_naira' => 1500, 'sort_order' => 1]);

        $priced = Co::priceCart([
            'tee|' . $m  => ['qty' => 1],
            'tee|' . $xl => ['qty' => 2],
        ], 'South West');

        // Keyed by slug alone, these were ONE line and the second choice overwrote the first —
        // which meant a buyer could only ever order one variant of anything.
        $this->assertCount(2, $priced['lines']);
        $this->assertSame(['M', 'XL'], array_column($priced['lines'], 'variant'));
        $this->assertSame(10000 + (11500 * 2), $priced['subtotal']);
        $this->assertSame(3, $priced['count']);
    }

    public function test_a_bare_slug_from_an_older_cart_still_prices(): void
    {
        $this->product(['price_naira' => 5500]);

        // A basket saved in somebody's localStorage before variants shipped. Refusing it would
        // empty every returning visitor's cart on deploy.
        $priced = Co::priceCart(['tee' => ['qty' => 2]], 'South West');

        $this->assertCount(1, $priced['lines']);
        $this->assertSame('tee', $priced['lines'][0]['key']);
        $this->assertSame(11000, $priced['subtotal']);
    }

    public function test_a_variant_id_paired_with_the_wrong_slug_is_dropped(): void
    {
        $this->product(['slug' => 'tee']);
        $capId = $this->product(['slug' => 'cap', 'name' => 'Cap', 'price_naira' => 1]);
        $cheap = $this->variant($capId, ['label' => 'One size', 'price_delta_naira' => 0]);

        // The variant id comes from the KEY, and the pairing is verified — so a ₦1 variant
        // cannot be attached to an expensive product to price it down.
        $priced = Co::priceCart(['tee|' . $cheap => ['qty' => 1]], 'South West');

        $this->assertSame([], $priced['lines']);
        $this->assertNotSame([], $priced['adjusted'], 'the buyer was told nothing about it');
    }

    public function test_a_product_with_variants_cannot_be_bought_without_choosing_one(): void
    {
        $id = $this->product();
        $this->variant($id, ['label' => 'M', 'stock' => 5]);

        $priced = Co::priceCart(['tee' => ['qty' => 1]], 'South West');

        $this->assertSame([], $priced['lines']);
        $this->assertStringContainsStringIgnoringCase('choose a size', $priced['adjusted'][0]);
    }

    public function test_an_unknown_or_inactive_slug_is_dropped(): void
    {
        $this->product(['slug' => 'draft', 'is_active' => 0]);

        $priced = Co::priceCart(['draft' => ['qty' => 1], 'ghost' => ['qty' => 1]], 'South West');
        $this->assertSame([], $priced['lines']);
    }

    // ══ 2. stock ═════════════════════════════════════════════════════════════

    public function test_a_sold_out_line_is_dropped_and_said_out_loud(): void
    {
        $this->product(['stock' => 0]);

        $priced = Co::priceCart(['tee' => ['qty' => 1]], 'South West');

        $this->assertSame([], $priced['lines']);
        $this->assertStringContainsStringIgnoringCase('sold out', $priced['adjusted'][0]);
    }

    public function test_a_quantity_beyond_stock_is_trimmed_rather_than_refused(): void
    {
        $this->product(['stock' => 2]);

        $priced = Co::priceCart(['tee' => ['qty' => 5]], 'South West');

        // Somebody who wanted five and can have two usually wants the two, and being told so
        // is better than an empty cart with no explanation.
        $this->assertSame(2, $priced['lines'][0]['qty']);
        $this->assertSame(20000, $priced['subtotal']);
        $this->assertStringContainsString('Only 2', $priced['adjusted'][0]);
    }

    public function test_untracked_stock_is_unlimited(): void
    {
        $this->product(['stock' => null]);

        $priced = Co::priceCart(['tee' => ['qty' => 9]], 'South West');

        // NULL means nobody counts these, not that there are none.
        $this->assertSame(9, $priced['lines'][0]['qty']);
        $this->assertSame([], $priced['adjusted']);
    }

    public function test_stock_is_read_from_the_variant_when_there_are_variants(): void
    {
        $id = $this->product(['stock' => 500]);   // meaningless once sizes exist
        $l  = $this->variant($id, ['label' => 'L', 'stock' => 1]);

        $priced = Co::priceCart(['tee|' . $l => ['qty' => 4]], 'South West');

        $this->assertSame(1, $priced['lines'][0]['qty']);
    }

    public function test_a_quantity_is_still_capped_at_twenty(): void
    {
        $this->product(['stock' => null]);
        $this->assertSame(20, Co::priceCart(['tee' => ['qty' => 900]], 'South West')['lines'][0]['qty']);
    }

    // ══ 3. discounts ═════════════════════════════════════════════════════════

    public function test_a_percentage_comes_off_only_the_eligible_lines(): void
    {
        $this->product(['slug' => 'tee', 'category' => 'Apparel', 'price_naira' => 10000]);
        $this->product(['slug' => 'mug', 'name' => 'Mug', 'category' => 'Home', 'price_naira' => 5000]);
        $this->code(['code' => 'APPAREL20', 'amount' => 20, 'categories' => json_encode(['Apparel'])]);

        $priced = Co::priceCart(['tee' => ['qty' => 1], 'mug' => ['qty' => 1]], 'South West');
        $t = Co::totals($priced, 'South West', 'APPAREL20', 'a@x.test');

        $this->assertSame(15000, $t['goods']);
        // 20% of the SHIRT, not of the basket. Discounting the mug too is the mistake that only
        // shows up in a month's revenue.
        $this->assertSame(2000, $t['discount']);
        $this->assertSame(13000, $t['charged']);
    }

    public function test_a_code_naming_nothing_in_the_basket_is_refused(): void
    {
        $this->product(['slug' => 'mug', 'name' => 'Mug', 'category' => 'Home']);
        $this->code(['code' => 'APPAREL20', 'categories' => json_encode(['Apparel'])]);

        $priced = Co::priceCart(['mug' => ['qty' => 1]], 'South West');
        $t = Co::totals($priced, 'South West', 'APPAREL20', 'a@x.test');

        $this->assertSame(0, $t['discount']);
        $this->assertStringContainsStringIgnoringCase('does not apply', $t['note']);
    }

    public function test_a_minimum_spend_is_enforced_and_says_how_short_they_are(): void
    {
        $this->product(['price_naira' => 6000]);
        $this->code(['min_spend_naira' => 20000]);

        $priced = Co::priceCart(['tee' => ['qty' => 1]], 'South West');
        $t = Co::totals($priced, 'South West', 'GATES10', 'a@x.test');

        $this->assertSame(0, $t['discount']);
        $this->assertStringContainsString('14,000 short', $t['note']);
    }

    public function test_a_free_shipping_code_removes_the_delivery_charge(): void
    {
        $this->product(['price_naira' => 6000]);
        $this->rates(['South West' => 2500]);
        $this->code(['code' => 'SHIPFREE', 'kind' => 'fixed', 'amount' => 0, 'free_shipping' => 1]);

        $priced = Co::priceCart(['tee' => ['qty' => 1]], 'South West');
        $t = Co::totals($priced, 'South West', 'SHIPFREE', 'a@x.test');

        $this->assertSame(0, $t['discount']);
        $this->assertSame(0, $t['shipping']);
        $this->assertSame(6000, $t['charged']);
        $this->assertStringContainsStringIgnoringCase('free delivery', $t['shipping_why']);
    }

    public function test_a_discount_can_never_take_the_charge_below_zero(): void
    {
        $this->product(['price_naira' => 4000]);
        $this->code(['kind' => 'fixed', 'amount' => 10000]);

        $priced = Co::priceCart(['tee' => ['qty' => 1]], 'South West');
        $t = Co::totals($priced, 'South West', 'GATES10', 'a@x.test');

        // A ₦10,000 code against a ₦4,000 order is a free order, not a refund.
        $this->assertSame(4000, $t['discount']);
        $this->assertSame(0, $t['charged']);
    }

    public function test_an_unknown_code_leaves_the_price_alone_and_explains_itself(): void
    {
        $this->product();

        $priced = Co::priceCart(['tee' => ['qty' => 1]], 'South West');
        $t = Co::totals($priced, 'South West', 'NONSENSE', 'a@x.test');

        // Somebody typing a bad code still wants the thing far more often than they want their
        // order refused, so the price stands and the reason is carried back.
        $this->assertSame(10000, $t['charged']);
        $this->assertSame('', $t['code']);
        $this->assertStringContainsStringIgnoringCase('not recognised', $t['note']);
    }

    // ══ 4. delivery ══════════════════════════════════════════════════════════

    public function test_delivery_is_charged_per_region_once_per_order(): void
    {
        $this->product();
        $this->rates(['South West' => 2500, 'North East' => 5000]);

        $priced = Co::priceCart(['tee' => ['qty' => 3]], 'North East');
        $t = Co::totals($priced, 'North East', '', '');

        // Once, not per item.
        $this->assertSame(5000, $t['shipping']);
        $this->assertSame(35000, $t['charged']);
    }

    public function test_a_region_with_no_rate_delivers_free(): void
    {
        $this->product();
        $this->rates(['North East' => 5000]);

        $t = Co::totals(Co::priceCart(['tee' => ['qty' => 1]], 'South West'), 'South West', '', '');
        $this->assertSame(0, $t['shipping']);
    }

    public function test_a_basket_of_delivery_included_items_never_pays_delivery(): void
    {
        $this->product(['slug' => 'pin', 'name' => 'Pin', 'price_naira' => 3500, 'ships_free' => 1]);
        $this->rates(['South West' => 2500]);

        $t = Co::totals(Co::priceCart(['pin' => ['qty' => 2]], 'South West'), 'South West', '', '');

        // Charging ₦2,500 to post two enamel pins is how a ₦7,000 order gets abandoned.
        $this->assertSame(0, $t['shipping']);
        $this->assertStringContainsStringIgnoringCase('included', $t['shipping_why']);
    }

    public function test_one_ordinary_item_reinstates_the_delivery_charge(): void
    {
        $this->product(['slug' => 'pin', 'name' => 'Pin', 'price_naira' => 3500, 'ships_free' => 1]);
        $this->product(['slug' => 'mug', 'name' => 'Mug', 'price_naira' => 5500]);
        $this->rates(['South West' => 2500]);

        $t = Co::totals(Co::priceCart(['pin' => ['qty' => 1], 'mug' => ['qty' => 1]], 'South West'),
                        'South West', '', '');
        $this->assertSame(2500, $t['shipping']);
    }

    public function test_the_free_over_threshold_is_measured_after_the_discount(): void
    {
        $this->product(['price_naira' => 52000]);
        $this->rates(['South West' => 2500], 50000);
        $this->code(['amount' => 10]);          // 10% of 52,000 = 5,200 → 46,800

        $priced = Co::priceCart(['tee' => ['qty' => 1]], 'South West');

        // Without the code the basket clears the threshold.
        $plain = Co::totals($priced, 'South West', '', '');
        $this->assertSame(0, $plain['shipping']);

        // With it, it does not — and promising free delivery on an order that no longer
        // reaches the threshold is a promise the seller pays for.
        $withCode = Co::totals($priced, 'South West', 'GATES10', 'a@x.test');
        $this->assertSame(2500, $withCode['shipping']);
        $this->assertSame(46800 + 2500, $withCode['charged']);
    }

    public function test_how_much_more_is_needed_for_free_delivery_is_reported(): void
    {
        $this->product(['price_naira' => 14800]);
        $this->rates(['South West' => 2500], 50000);

        $t = Co::totals(Co::priceCart(['tee' => ['qty' => 1]], 'South West'), 'South West', '', '');

        // The number that turns a delivery charge into a reason to add one more thing.
        $this->assertSame(35200, $t['short_by']);
    }

    public function test_an_empty_basket_costs_nothing_including_delivery(): void
    {
        $t = Co::totals(['lines' => [], 'subtotal' => 0], 'South West', '', '');
        $this->assertSame(0, $t['charged']);
        $this->assertSame(0, $t['shipping']);
    }

    // ══ 5. code uses ═════════════════════════════════════════════════════════

    public function test_a_paid_order_spends_the_buyers_per_person_allowance(): void
    {
        $this->product();
        $this->code(['max_per_email' => 1]);

        DB::table('gates_orders')->insert([
            'reference' => 'AFG-SHP-1', 'email' => 'a@x.test', 'name' => 'A',
            'items_json' => '[]', 'subtotal_naira' => 9000, 'status' => 'paid',
            'discount_code' => 'GATES10', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        // Counted from the ORDERS rather than a per-person counter: the orders are the record,
        // so the count cannot drift out of step with them.
        $this->assertSame(1, ShopDiscount::timesUsedBy('GATES10', 'a@x.test'));
        $this->assertFalse(ShopDiscount::apply('GATES10',
            [['line_total' => 10000, 'product_id' => 1, 'category' => 'Apparel']], 'a@x.test')['ok']);

        // Somebody else is unaffected.
        $this->assertTrue(ShopDiscount::apply('GATES10',
            [['line_total' => 10000, 'product_id' => 1, 'category' => 'Apparel']], 'b@x.test')['ok']);
    }

    public function test_a_failed_order_does_not_lock_somebody_out_of_their_own_code(): void
    {
        $this->code(['max_per_email' => 1]);
        DB::table('gates_orders')->insert([
            'reference' => 'AFG-SHP-2', 'email' => 'a@x.test', 'name' => 'A',
            'items_json' => '[]', 'subtotal_naira' => 9000, 'status' => 'failed',
            'discount_code' => 'GATES10', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        // A declined card must not permanently spend a discount they never received.
        $this->assertSame(0, ShopDiscount::timesUsedBy('GATES10', 'a@x.test'));
    }

    public function test_a_use_is_counted_and_can_be_given_back(): void
    {
        $id = $this->code(['max_uses' => 1]);

        ShopDiscount::countUse($id);
        $this->assertSame(1, (int) DB::table('gates_shop_codes')->where('id', $id)->value('used_count'));
        $this->assertFalse(ShopDiscount::apply('GATES10',
            [['line_total' => 10000, 'product_id' => 1, 'category' => 'Apparel']], 'b@x.test')['ok']);

        ShopDiscount::releaseUse('GATES10');
        $this->assertSame(0, (int) DB::table('gates_shop_codes')->where('id', $id)->value('used_count'));
        $this->assertTrue(ShopDiscount::apply('GATES10',
            [['line_total' => 10000, 'product_id' => 1, 'category' => 'Apparel']], 'b@x.test')['ok']);
    }

    public function test_releasing_a_use_never_goes_below_zero(): void
    {
        $id = $this->code();
        ShopDiscount::releaseUse('GATES10');
        ShopDiscount::releaseUse('GATES10');
        $this->assertSame(0, (int) DB::table('gates_shop_codes')->where('id', $id)->value('used_count'));
    }

    // ══ 6. regional pricing still applies on top ═════════════════════════════

    public function test_a_regional_multiplier_scales_the_variant_price_too(): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => 'shop_region_mult', 'value' => json_encode(['North East' => 1.2]),
        ]);
        $id = $this->product(['price_naira' => 10000]);
        $xl = $this->variant($id, ['label' => 'XL', 'price_delta_naira' => 1500]);

        $priced = Co::priceCart(['tee|' . $xl => ['qty' => 1]], 'North East');

        // The delta is added FIRST and the region multiplier applied to the result — the other
        // order would price a size below the product it is a size of.
        $this->assertSame(11500, $priced['lines'][0]['base_naira']);
        $this->assertSame(13800, $priced['lines'][0]['price_naira']);
    }
}
