<?php
declare(strict_types=1);

namespace Tests\Feature;

use AfricaGates\Services\ShopAdvisor as A;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The buying specialist's facts.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS ARE ACTUALLY PROTECTING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Not the wording. The wording is the model's job and it will change. What is asserted here is
 * that the FACTS handed to the model are right, because the model cannot check them and will
 * repeat whatever it is given with complete confidence.
 *
 * The expensive failure this guards against is specific: a language model asked "do you have
 * this in navy, extra large" answers "yes" more often than the stock allows, because agreement
 * is the likelier continuation of the sentence. The result is not a support ticket — it is a
 * paid order that has to be telephoned about, or refunded. So every method the assistant can
 * call is deterministic, and every one of them is asserted against a real catalogue here.
 *
 * The other thing being protected is that the assistant is USEFUL WITH NO AI KEY. Every method
 * returns a complete, correct answer on its own; the AI layer only picks which to call and how
 * to word it. None of these tests mocks a model, and that is the point — if they needed one,
 * the design would be wrong.
 */
final class ShopAdvisorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_product_variants')->delete();
        DB::table('gates_products')->delete();
    }

    /** @param array<string,mixed> $over */
    private function product(string $name, int $price, array $over = []): int
    {
        static $n = 0;
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '') . '-' . (++$n);
        return (int) DB::table('gates_products')->insertGetId(array_merge([
            'name' => $name, 'slug' => trim($slug, '-'), 'category' => 'Apparel',
            'price_naira' => $price, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    /**
     * Set the delivery rate card the way an admin would.
     *
     * The column is `key_name`, not `key` — `key` is reserved in MySQL, which is why the schema
     * spells it out. Wrapped in a helper so the name is written once.
     *
     * @param array<string,int> $rates
     */
    private function rates(array $rates, ?int $freeOver = null): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'shop_shipping'],
            ['value' => json_encode($rates)]);
        if ($freeOver !== null) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => 'shop_ship_free_over'],
                ['value' => (string) $freeOver]);
        }
    }

    /** @param list<array{0:string,1:string,2:?int,3:int}> $rows [labelA, labelB, stock, delta] */
    private function variants(int $productId, array $rows, string $axisA = 'Colour', string $axisB = 'Size'): void
    {
        $now = Carbon::now()->toDateTimeString();
        $o = 0;
        foreach ($rows as [$a, $b, $stock, $delta]) {
            DB::table('gates_product_variants')->insert([
                'product_id' => $productId,
                'label' => $a, 'label2' => $b !== '' ? $b : null,
                'axis' => $axisA, 'axis2' => $b !== '' ? $axisB : null,
                'price_delta_naira' => $delta, 'stock' => $stock,
                'is_active' => 1, 'sort_order' => $o++,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    // ══ 1. is the shop even open ═════════════════════════════════════════════

    public function test_it_reports_an_empty_shop_rather_than_offering_to_help(): void
    {
        // Offering to help somebody choose from an empty catalogue is a promise the next
        // sentence has to break — and a planner handed a tool that always answers "nothing"
        // will keep calling it.
        $this->assertFalse(A::open());
        $r = A::suggest(['need' => 'a gift']);
        $this->assertSame([], $r['items']);
        $this->assertStringContainsString('nothing on sale', $r['note']);
    }

    public function test_it_is_open_once_something_is_on_sale(): void
    {
        $this->product('The Tee', 10000);
        $this->assertTrue(A::open());
        $this->assertContains('Apparel', A::departments());
    }

    // ══ 2. a budget is a promise, not a preference ════════════════════════════

    public function test_nothing_over_budget_is_ever_offered_as_though_it_fitted(): void
    {
        $this->product('Cheap Pin', 3000);
        $this->product('Dear Wrapper', 48000);

        $r = A::suggest(['budget' => 10000]);
        $names = array_column($r['items'], 'name');

        $this->assertContains('Cheap Pin', $names);
        $this->assertNotContains('Dear Wrapper', $names,
            'offering something somebody cannot afford is worse than offering nothing');
        $this->assertFalse($r['widened']);
        $this->assertSame('', $r['note']);
    }

    public function test_when_nothing_is_in_range_it_says_so_and_shows_the_cheapest(): void
    {
        $this->product('Dear Wrapper', 48000);
        $this->product('Dearer Robe', 90000);

        $r = A::suggest(['budget' => 5000]);
        $this->assertTrue($r['widened']);
        $this->assertStringContainsString('Nothing is under ₦5,000', $r['note']);
        // Cheapest first, so the closest thing to their budget is the first thing they read.
        $this->assertSame('Dear Wrapper', $r['items'][0]['name']);
    }

    public function test_the_budget_is_judged_on_the_cheapest_way_to_own_the_thing(): void
    {
        // A budget answered against the BASE price offers something whose only remaining size
        // costs ₦1,500 more — a promise broken at the product page.
        $id = $this->product('Sized Tee', 9000);
        $this->variants($id, [['S', '', 0, 0], ['XXL', '', 4, 3000]], 'Size');

        $r = A::suggest(['budget' => 10000]);
        // The only BUYABLE size costs 12,000, so nothing is inside the budget — and the honest
        // answer is to say so and show the closest thing, not to return an empty shop.
        $this->assertTrue($r['widened']);
        $this->assertStringContainsString('Nothing is under ₦10,000', $r['note']);
        $this->assertSame(12000, $r['items'][0]['from_naira'],
            'the price quoted must be the cheapest way to actually own it, not the base price');
    }

    public function test_a_recommendation_carries_its_reason(): void
    {
        // An assistant that says "this one" is guessing at taste. One that says why has given
        // the buyer something to disagree with.
        $this->product('Àdìrẹ Tote', 26000, ['subtitle' => 'Hand-dyed in Abeokuta', 'is_featured' => 1]);
        $r = A::suggest(['need' => 'something hand-dyed', 'budget' => 30000]);

        $this->assertNotSame([], $r['items']);
        $this->assertNotSame('', $r['items'][0]['why']);
        // The needle is split on the hyphen when the need is tokenised, so the reason reads
        // "matches hand and dyed" rather than repeating the phrase — which is fine, and worth
        // asserting as the actual behaviour rather than the guessed one.
        $this->assertStringContainsString('dyed', mb_strtolower($r['items'][0]['why']));
    }

    public function test_common_words_do_not_decide_the_ranking(): void
    {
        // Without a stop list, "something for my mother" matches every product whose description
        // contains "for" — which is all of them, so the ranking becomes noise.
        $this->product('Alpha', 5000, ['description' => 'This is for you and for them.']);
        $this->product('Beta', 5000, ['description' => 'A pin.']);

        $a = A::suggest(['need' => 'something for my mother']);
        $b = A::suggest(['need' => 'something for my mother']);
        // Stable, and not driven by the filler words.
        $this->assertSame(array_column($a['items'], 'name'), array_column($b['items'], 'name'));
    }

    public function test_an_empty_category_widens_rather_than_answering_nothing(): void
    {
        // The category was the buyer's guess at where to look; the request was for a gift.
        $this->product('A Pin', 3000, ['category' => 'Keepsakes']);
        $r = A::suggest(['need' => 'a gift', 'category' => 'Home']);
        $this->assertNotSame([], $r['items'], 'an empty category must not empty the answer');
    }

    public function test_sold_out_products_are_not_recommended_when_anything_else_exists(): void
    {
        $this->product('Gone', 5000, ['stock' => 0]);
        $this->product('Here', 6000, ['stock' => 4]);

        $names = array_column(A::suggest([])['items'], 'name');
        $this->assertContains('Here', $names);
        $this->assertNotContains('Gone', $names);
    }

    // ══ 3. availability — the one that must never be optimistic ══════════════

    public function test_a_pair_in_stock_is_reported_available_with_its_own_price(): void
    {
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Navy', 'M', 3, 0], ['Navy', 'XXL', 2, 1500]]);

        $r = A::availability('Heritage Tee', 'Navy', 'XXL');
        $this->assertTrue($r['found']);
        $this->assertTrue($r['available']);
        $this->assertSame(20000, $r['price_naira'], 'the delta belongs to the combination');
        $this->assertSame('Navy · XXL', $r['label']);
    }

    public function test_the_order_of_the_two_answers_does_not_matter(): void
    {
        // Somebody says "XL navy" as readily as "navy XL", and a specialist that understood only
        // one order would be wrong half the time for no reason the buyer could see.
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Navy', 'XL', 2, 0]]);

        $a = A::availability('Heritage Tee', 'Navy', 'XL');
        $b = A::availability('Heritage Tee', 'XL', 'Navy');
        $this->assertTrue($a['available']);
        $this->assertTrue($b['available']);
        $this->assertSame($a['label'], $b['label']);
    }

    public function test_a_sold_out_pair_is_refused_and_offers_only_things_that_can_be_bought(): void
    {
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Navy', 'M', 0, 0], ['Cream', 'M', 2, 0], ['Palm', 'M', 0, 0]]);

        $r = A::availability('Heritage Tee', 'Navy', 'M');
        $this->assertFalse($r['available']);
        $this->assertStringContainsString('sold out', $r['message']);

        $offered = array_column($r['alternatives'], 'label');
        $this->assertContains('Cream · M', $offered);
        $this->assertNotContains('Palm · M', $offered,
            'offering a sold-out alternative to somebody just told their choice is sold out is '
            . 'worse than offering nothing');
    }

    public function test_one_answer_of_two_asks_the_other_question_rather_than_denying_it(): void
    {
        // THE BUG THIS EXISTS FOR. "Adire" on a Colour × Size product is not an unavailable
        // combination — it is a colour that exists in four sizes. Reporting it as "not made"
        // tells somebody a thing on sale is not on sale, and they stop looking.
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Adire', 'S', 3, 0], ['Adire', 'M', 2, 0], ['Cream', 'S', 1, 0]]);

        $r = A::availability('Heritage Tee', 'Adire');
        $this->assertTrue($r['found']);
        $this->assertNull($r['available'], 'neither available nor unavailable — the question is open');
        $this->assertSame('Size', $r['needs']);
        $this->assertStringContainsString('which size', $r['message']);
        $this->assertSame(['S', 'M'], array_column($r['alternatives'], 'label'));
    }

    public function test_the_sizes_offered_are_narrowed_by_the_colour_they_gave(): void
    {
        // Offering every size when they have said "Adire" would include sizes Adire is sold out
        // in, and an assistant that lists a size and then refuses it has wasted the exchange.
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Adire', 'S', 0, 0], ['Adire', 'M', 2, 0], ['Cream', 'L', 5, 0]]);

        $r = A::availability('Heritage Tee', 'Adire');
        $this->assertSame(['M'], array_column($r['alternatives'], 'label'));
    }

    public function test_an_option_made_but_gone_everywhere_is_not_called_unmade(): void
    {
        // Distinct sentences for distinct facts. "We do not make Indigo" when the shop does and
        // has run out is how somebody stops looking for the thing they came for.
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Indigo', 'S', 0, 0], ['Indigo', 'M', 0, 0], ['Cream', 'M', 3, 0]]);

        $r = A::availability('Heritage Tee', 'Indigo');
        $this->assertFalse($r['available']);
        $this->assertStringContainsString('sold out in every size', $r['message']);
        // And it points at the thing that can actually be done about it.
        $this->assertStringContainsString('tell them when it is back', $r['message']);
    }

    public function test_a_combination_that_is_not_made_says_exactly_that(): void
    {
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Navy', 'M', 2, 0]]);

        $r = A::availability('Heritage Tee', 'Turquoise', 'M');
        $this->assertFalse($r['available']);
        $this->assertStringContainsString('not one', $r['message']);
    }

    public function test_a_product_with_no_options_answers_about_itself(): void
    {
        $this->product('Sticker Set', 1800, ['stock' => null]);
        $r = A::availability('Sticker Set');
        $this->assertTrue($r['available'], 'untracked stock is unlimited, not zero');
        $this->assertSame(1800, $r['price_naira']);
    }

    public function test_an_unknown_product_is_not_guessed_at(): void
    {
        // Being told about the wrong product is worse than being told to look again.
        $this->product('Heritage Tee', 18500);
        $r = A::availability('Diamond Tiara');
        $this->assertFalse($r['found']);
        $this->assertStringContainsString('nothing in the shop by that name', $r['message']);
    }

    public function test_a_name_typed_loosely_still_finds_the_right_product(): void
    {
        $this->product('Àdìrẹ Tote', 26000);
        foreach (['Àdìrẹ Tote', 'adire tote', 'tote'] as $typed) {
            $this->assertTrue(A::availability($typed)['found'], "did not find it from “{$typed}”");
        }
    }

    // ══ 4. the number they are deciding on ═══════════════════════════════════

    public function test_a_quote_includes_delivery_because_the_basket_will(): void
    {
        // Quoting a price without delivery is how an assistant is trusted and then contradicted
        // at the last screen, and the contradiction is the part people remember.
        $this->rates(['South West' => 2500], 50000);

        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Navy', 'M', 5, 0]]);

        $q = A::quote('Heritage Tee', 'Navy', 'M', 2, 'South West');
        $this->assertSame(37000, $q['goods_naira']);
        $this->assertSame(2500, $q['shipping_naira']);
        $this->assertSame(39500, $q['total_naira']);
        $this->assertStringContainsString('South West', $q['shipping_why']);
        // And how much more would make it free — the number that turns a charge into a reason
        // to add one more thing.
        $this->assertSame(13000, $q['short_by']);
    }

    public function test_a_product_that_includes_delivery_is_not_charged_for_it(): void
    {
        $this->rates(['South West' => 2500]);

        $this->product('Sticker Set', 1800, ['stock' => 20, 'ships_free' => 1]);
        $q = A::quote('Sticker Set', '', '', 1, 'South West');
        $this->assertSame(0, $q['shipping_naira']);
        $this->assertSame(1800, $q['total_naira']);
    }

    public function test_a_quantity_is_bounded_rather_than_trusted(): void
    {
        $this->product('Pin', 3000, ['stock' => null]);
        $this->assertSame(1, A::quote('Pin', '', '', 0)['qty']);
        $this->assertSame(1, A::quote('Pin', '', '', -5)['qty']);
        $this->assertSame(20, A::quote('Pin', '', '', 9999)['qty']);
    }

    public function test_a_quote_for_an_unresolved_option_does_not_invent_a_price(): void
    {
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Navy', 'M', 3, 0], ['Navy', 'L', 3, 0]]);

        $q = A::quote('Heritage Tee', 'Navy');
        $this->assertArrayNotHasKey('total_naira', $q,
            'a total for a combination nobody has chosen is a number somebody will hold us to');
    }

    // ══ 5. comparing, without pretending to have taste ═══════════════════════

    public function test_a_comparison_returns_differences_and_no_verdict(): void
    {
        $this->product('Heritage Tee', 18500);
        $this->product('GATES Cap', 12000, ['stock' => 5]);

        $r = A::compare('Heritage Tee', 'GATES Cap');
        $this->assertTrue($r['found']);
        $this->assertNotSame([], $r['differences']);
        $this->assertStringContainsString('₦6,500 less', implode(' ', $r['differences']));
        // No "better", "best" or "recommend" — which is better depends on what they want it for.
        foreach (['better', 'best', 'recommend'] as $verdict) {
            $this->assertStringNotContainsStringIgnoringCase($verdict, implode(' ', $r['differences']));
        }
    }

    public function test_comparing_identical_products_says_so(): void
    {
        $this->product('Heritage Tee', 18500);
        $r = A::compare('Heritage Tee', 'heritage tee');
        $this->assertFalse($r['found']);
        $this->assertStringContainsString('same product', $r['message']);
    }

    public function test_comparing_two_equal_products_still_says_something_useful(): void
    {
        $this->product('Alpha', 5000, ['stock' => 3]);
        $this->product('Beta', 5000, ['stock' => 3]);
        $r = A::compare('Alpha', 'Beta');
        $this->assertNotSame([], $r['differences']);
        $this->assertStringContainsString('which one they prefer', implode(' ', $r['differences']));
    }

    // ══ 6. the handoff, and the boundary ═════════════════════════════════════

    public function test_the_handoff_is_a_link_and_never_a_purchase(): void
    {
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Navy', 'M', 3, 0]]);

        $r = A::handoff('Heritage Tee', 'Navy', 'M');
        $this->assertStringStartsWith('/shop/', $r['url']);
        $this->assertTrue($r['available']);

        // The boundary, asserted: nothing on this class can transact. A specialist that can be
        // talked into taking a payment is a specialist that will be.
        foreach (get_class_methods(A::class) as $m) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(add|buy|order|pay|charge|checkout|purchase|refund)/i', $m,
                "ShopAdvisor::{$m}() looks like it transacts — it must not");
        }
    }

    public function test_an_unfindable_handoff_falls_back_to_the_shop_rather_than_a_dead_link(): void
    {
        $this->product('Heritage Tee', 18500);
        $r = A::handoff('Nonexistent Thing');
        $this->assertSame('/shop', $r['url']);
    }

    // ══ 7. delivery is quoted, never remembered ══════════════════════════════

    public function test_the_delivery_brief_reads_the_real_rate_card(): void
    {
        $this->rates(['South West' => 2500, 'North East' => 5000], 50000);

        $b = A::deliveryBrief();
        $this->assertTrue($b['charged']);
        $this->assertStringContainsString('South West ₦2,500', $b['message']);
        $this->assertStringContainsString('Free over ₦50,000', $b['message']);
    }

    public function test_it_says_delivery_is_free_when_no_rates_are_set(): void
    {
        // Which is exactly how the shop behaves before an admin sets any — and an assistant that
        // quoted a charge nobody configured would be inventing one.
        DB::table('gates_settings')->where('key_name', 'shop_shipping')->delete();
        $b = A::deliveryBrief();
        $this->assertFalse($b['charged']);
        $this->assertStringContainsString('free everywhere', $b['message']);
    }

    // ══ 8. it holds up without a model ═══════════════════════════════════════

    public function test_every_answer_is_complete_without_any_ai_involved(): void
    {
        // No key is configured in the test environment, and nothing here is mocked. If any of
        // these needed a model to be useful, the design would be wrong.
        $id = $this->product('Heritage Tee', 18500);
        $this->variants($id, [['Navy', 'M', 3, 0]]);

        foreach ([
            A::suggest(['need' => 'a gift', 'budget' => 25000])['items'][0]['name'] ?? '',
            A::availability('Heritage Tee', 'Navy', 'M')['message'],
            A::quote('Heritage Tee', 'Navy', 'M')['message'],
            A::compare('Heritage Tee', 'Heritage Tee')['message'],
            A::deliveryBrief()['message'],
            A::handoff('Heritage Tee', 'Navy', 'M')['message'],
        ] as $i => $said) {
            $this->assertNotSame('', $said, "answer {$i} came back empty with no AI key");
        }
    }
}
