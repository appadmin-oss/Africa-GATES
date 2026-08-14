<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ShopCatalogue as C;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Variants, stock that means something, and a browse that scales.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS THERE BEFORE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * One price and one stock number per product, and the shop's first category is Apparel. You
 * cannot sell apparel without a size, so a buyer picked a shirt, paid, and somebody had to
 * email them afterwards to ask what size they were — which means the order was not actually
 * complete when the money arrived, and a "sold out" number counted shirts rather than
 * shirts-in-a-size.
 *
 * `stock` existed and NOTHING READ IT. A sold-out item could be added, paid for and confirmed,
 * and the confirmation floored the number at zero without telling anybody.
 *
 * And the grid rendered every active product, hiding the ones a client-side filter did not
 * match — which works at nine products and stops working at ninety.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. NULL IS UNTRACKED AND 0 IS SOLD OUT. Different statements; flattening them closes the
 *      shop for everything nobody counts.
 *   2. A VARIANT'S STOCK IS THE TRUTH WHEN THERE ARE VARIANTS. A shirt is four in medium and
 *      none in large, not twelve — and adding them up offers a size that cannot ship.
 *   3. A VARIANT IS VERIFIED AGAINST ITS PRODUCT. The pairing arrives from a form.
 *   4. PAGING IS STABLE. Without a deterministic tiebreaker, page 2 repeats a row from page 1
 *      and omits another that nobody ever sees.
 */
final class ShopCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_product_variants')->delete();
        DB::table('gates_product_images')->delete();
        DB::table('gates_products')->delete();
    }

    /** @param array<string,mixed> $over */
    private function product(array $over = []): object
    {
        $id = (int) DB::table('gates_products')->insertGetId(array_merge([
            'slug' => 'tee', 'name' => 'The Tee', 'category' => 'Apparel',
            'price_naira' => 10000, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
        return DB::table('gates_products')->where('id', $id)->first();
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

    // ══ 1. stock ═════════════════════════════════════════════════════════════

    public function test_null_stock_is_unlimited_and_zero_is_sold_out(): void
    {
        $untracked = $this->product(['slug' => 'a', 'stock' => null]);
        $gone      = $this->product(['slug' => 'b', 'stock' => 0]);
        $some      = $this->product(['slug' => 'c', 'stock' => 4]);

        // null, not 0. A caller that treats "we do not count these" as "there are none" closes
        // the shop for every untracked product on the site.
        $this->assertNull(C::available($untracked));
        $this->assertSame(0, C::available($gone));
        $this->assertSame(4, C::available($some));
    }

    public function test_a_product_with_variants_has_no_sellable_stock_of_its_own(): void
    {
        // 12 on the product row, and every unit actually lives in a size.
        $p = $this->product(['stock' => 12]);
        $this->variant((int) $p->id, ['label' => 'M', 'stock' => 4]);

        // Answering from the product column here would offer stock in no size at all.
        $this->assertSame(0, C::available($p));
        $this->assertSame(4, C::available($p, (int) DB::table('gates_product_variants')->value('id')));
    }

    public function test_a_variant_from_another_product_cannot_be_sold(): void
    {
        $tee   = $this->product(['slug' => 'tee']);
        $other = $this->product(['slug' => 'cap', 'name' => 'Cap']);
        $theirs = $this->variant((int) $other->id, ['label' => 'One size', 'stock' => 50]);

        // The pairing arrives from a form, so it is verified rather than assumed.
        $this->assertSame(0, C::available($tee, $theirs));
    }

    public function test_stock_note_says_the_thing_that_needs_saying_and_nothing_else(): void
    {
        $quiet = (array) $this->product(['slug' => 'a', 'stock' => 40]);
        $low   = (array) $this->product(['slug' => 'b', 'stock' => 2]);
        $gone  = (array) $this->product(['slug' => 'c', 'stock' => 0]);
        $free  = (array) $this->product(['slug' => 'd', 'stock' => null]);

        // Silence at 40: "in stock" on every card is noise that makes the scarcity line
        // invisible on the one card where it matters.
        $this->assertSame('', C::stockNote($quiet));
        $this->assertSame('Only 2 left', C::stockNote($low));
        $this->assertSame('Sold out', C::stockNote($gone));
        $this->assertSame('', C::stockNote($free));
    }

    public function test_stock_note_counts_options_rather_than_units_when_there_are_variants(): void
    {
        $p = $this->product();
        $this->variant((int) $p->id, ['label' => 'S', 'stock' => 3, 'sort_order' => 0]);
        $this->variant((int) $p->id, ['label' => 'M', 'stock' => 0, 'sort_order' => 1]);
        $this->variant((int) $p->id, ['label' => 'L', 'stock' => 9, 'sort_order' => 2]);

        $row = (array) $p;
        $row['variants'] = C::variants((int) $p->id);

        // Two sizes, not twelve shirts. The number a buyer is deciding about is how many
        // choices remain, not how many units exist across sizes they do not wear.
        $this->assertSame('2 sizes available', C::stockNote($row));
    }

    public function test_every_option_sold_out_reads_as_sold_out(): void
    {
        $p = $this->product();
        $this->variant((int) $p->id, ['label' => 'S', 'stock' => 0]);
        $this->variant((int) $p->id, ['label' => 'M', 'stock' => 0, 'sort_order' => 1]);

        $row = (array) $p;
        $row['variants'] = C::variants((int) $p->id);
        $this->assertSame('Sold out', C::stockNote($row));
    }

    // ══ 2. pricing a choice ══════════════════════════════════════════════════

    public function test_a_variant_price_is_the_base_plus_its_delta(): void
    {
        $p = $this->product(['price_naira' => 18500]);
        $this->variant((int) $p->id, ['label' => 'M', 'price_delta_naira' => 0]);
        $this->variant((int) $p->id, ['label' => 'XXL', 'price_delta_naira' => 1500, 'sort_order' => 1]);

        $v = C::variants((int) $p->id, 18500);

        $this->assertSame(18500, $v[0]['price_naira']);
        // A delta rather than a price, so a sale on the base still holds for every size.
        $this->assertSame(20000, $v[1]['price_naira']);
    }

    public function test_pick_refuses_when_nothing_has_been_chosen(): void
    {
        $p = $this->product();
        $this->variant((int) $p->id, ['label' => 'M', 'stock' => 5]);

        $r = C::pick($p, 0);

        $this->assertFalse($r['ok']);
        // Named by its axis, so the sentence is "choose a size" rather than "choose an option".
        $this->assertStringContainsStringIgnoringCase('choose a size', $r['message']);
    }

    public function test_pick_is_satisfied_by_a_product_with_no_variants(): void
    {
        $p = $this->product(['price_naira' => 5500, 'stock' => 3]);

        $r = C::pick($p, 0);

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['variant_id']);
        $this->assertSame(5500, $r['price']);
        $this->assertSame(3, $r['stock']);
    }

    public function test_pick_refuses_a_sold_out_option_by_name(): void
    {
        $p = $this->product();
        $vid = $this->variant((int) $p->id, ['label' => 'XXL', 'stock' => 0]);

        $r = C::pick($p, $vid);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('XXL', $r['message']);
    }

    public function test_pick_refuses_a_variant_belonging_to_another_product(): void
    {
        $tee   = $this->product(['slug' => 'tee']);
        $other = $this->product(['slug' => 'cap', 'name' => 'Cap']);
        $theirs = $this->variant((int) $other->id, ['label' => 'One size', 'stock' => 10]);

        // Nothing stops somebody posting a cheap variant's id beside an expensive slug, so the
        // pairing is checked rather than priced from whichever half was believed.
        $r = C::pick($tee, $theirs);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('no longer available', $r['message']);
    }

    public function test_an_inactive_variant_is_not_offered(): void
    {
        $p = $this->product();
        $this->variant((int) $p->id, ['label' => 'M', 'stock' => 5]);
        $off = $this->variant((int) $p->id, ['label' => 'Retired', 'stock' => 5, 'is_active' => 0]);

        $this->assertSame(['M'], array_column(C::variants((int) $p->id), 'label'));
        $this->assertFalse(C::pick($p, $off)['ok']);
    }

    // ══ 3. images ════════════════════════════════════════════════════════════

    public function test_the_cover_is_the_first_image_and_is_never_shown_twice(): void
    {
        $p = $this->product(['cover_path' => '/u/cover.jpg']);
        DB::table('gates_product_images')->insert([
            ['product_id' => (int) $p->id, 'path' => '/u/cover.jpg', 'alt' => null, 'sort_order' => 0],
            ['product_id' => (int) $p->id, 'path' => '/u/back.jpg', 'alt' => 'From behind', 'sort_order' => 1],
        ]);

        $g = C::images((int) $p->id, '/u/cover.jpg', 'The Tee');

        // The cover is usually also in the gallery table, and showing it twice makes the first
        // thumbnail look like a bug.
        $this->assertSame(['/u/cover.jpg', '/u/back.jpg'], array_column($g, 'path'));
        $this->assertSame('The Tee', $g[0]['alt']);
        $this->assertSame('From behind', $g[1]['alt']);
    }

    public function test_an_image_with_no_alt_falls_back_to_the_product_name(): void
    {
        $p = $this->product();
        DB::table('gates_product_images')->insert([
            'product_id' => (int) $p->id, 'path' => '/u/one.jpg', 'sort_order' => 0,
        ]);
        $this->assertSame('The Tee', C::images((int) $p->id, null, 'The Tee')[0]['alt']);
    }

    // ══ 4. browse ════════════════════════════════════════════════════════════

    /** @return list<string> the slugs on a page, in order */
    private function slugs(array $f): array
    {
        return array_column(C::browse($f)['rows'], 'slug');
    }

    public function test_search_matches_name_subtitle_and_category(): void
    {
        $this->product(['slug' => 'tee', 'name' => 'Heritage Tee', 'subtitle' => 'Heavyweight cotton']);
        $this->product(['slug' => 'mug', 'name' => 'Morning Mug', 'category' => 'Home']);

        $this->assertSame(['tee'], $this->slugs(['q' => 'heritage']));
        $this->assertSame(['tee'], $this->slugs(['q' => 'cotton']));
        $this->assertSame(['mug'], $this->slugs(['q' => 'Home']));
        $this->assertSame([], $this->slugs(['q' => 'bicycle']));
    }

    public function test_a_wildcard_in_a_search_is_treated_as_a_letter(): void
    {
        $this->product(['slug' => 'tee', 'name' => 'Heritage Tee']);

        // '%' matches everything in LIKE, so an unescaped one turns a search into "show me the
        // whole catalogue" — which reads as a broken search rather than a clever one.
        $this->assertSame([], $this->slugs(['q' => '%']));
        $this->assertSame([], $this->slugs(['q' => '_ee']));
    }

    public function test_a_category_filter_is_exact(): void
    {
        $this->product(['slug' => 'tee', 'category' => 'Apparel']);
        $this->product(['slug' => 'mug', 'category' => 'Home']);

        $this->assertSame(['tee'], $this->slugs(['category' => 'Apparel']));
    }

    public function test_price_sorts_run_in_both_directions(): void
    {
        $this->product(['slug' => 'cheap', 'price_naira' => 1800]);
        $this->product(['slug' => 'mid',   'price_naira' => 9500]);
        $this->product(['slug' => 'dear',  'price_naira' => 48000]);

        $this->assertSame(['cheap', 'mid', 'dear'], $this->slugs(['sort' => 'cheap']));
        $this->assertSame(['dear', 'mid', 'cheap'], $this->slugs(['sort' => 'dear']));
    }

    public function test_featured_products_come_first_inside_the_admins_own_order(): void
    {
        $this->product(['slug' => 'first-by-hand', 'sort_order' => 0]);
        $this->product(['slug' => 'featured', 'sort_order' => 9, 'is_featured' => 1]);

        // Featured lifts a product ABOVE the hand-arranged order rather than replacing it: an
        // admin who has sequenced their catalogue has not stopped meaning it.
        $this->assertSame(['featured', 'first-by-hand'], $this->slugs(['sort' => 'featured']));
    }

    public function test_most_bought_reads_the_counter(): void
    {
        $this->product(['slug' => 'quiet', 'sold_count' => 3]);
        $this->product(['slug' => 'loud',  'sold_count' => 301]);
        $this->product(['slug' => 'never', 'sold_count' => null]);

        // COALESCE, not a raw column: a product nobody has bought yet has NULL there, and NULL
        // sorts unpredictably enough to put it above the bestseller on one engine.
        $this->assertSame(['loud', 'quiet', 'never'], $this->slugs(['sort' => 'popular']));
    }

    public function test_paging_is_stable_and_never_repeats_or_drops_a_row(): void
    {
        // Every product at the SAME price and sort order, which is exactly when an engine is
        // free to return them in any order it likes.
        for ($i = 1; $i <= 20; $i++) {
            $this->product(['slug' => 'p' . $i, 'name' => 'Item ' . $i, 'price_naira' => 5000]);
        }

        $one = $this->slugs(['sort' => 'cheap', 'page' => 1]);
        $two = $this->slugs(['sort' => 'cheap', 'page' => 2]);

        $this->assertCount(C::PER_PAGE, $one);
        $this->assertSame([], array_intersect($one, $two), 'a row appeared on two pages');
        $this->assertCount(20, array_unique(array_merge($one, $two)));
    }

    public function test_the_total_counts_the_whole_result_not_the_page(): void
    {
        for ($i = 1; $i <= 20; $i++) $this->product(['slug' => 'p' . $i, 'name' => 'Item ' . $i]);

        $f = C::browse(['page' => 1]);

        $this->assertSame(20, $f['total']);
        $this->assertSame(2, $f['pages']);
        $this->assertCount(C::PER_PAGE, $f['rows']);
    }

    public function test_a_page_beyond_the_end_is_clamped_rather_than_empty(): void
    {
        $this->product(['slug' => 'only']);

        $f = C::browse(['page' => 99]);

        // Reported as page 1 of 1: a pager that says "page 99 of 1" is a pager somebody has
        // to reason about instead of using.
        $this->assertSame(1, $f['page']);
        $this->assertSame(1, $f['pages']);
    }

    public function test_an_inactive_product_is_never_browsed(): void
    {
        $this->product(['slug' => 'live']);
        $this->product(['slug' => 'draft', 'is_active' => 0]);

        $this->assertSame(['live'], $this->slugs([]));
        $this->assertSame(['live'], C::categories() ? $this->slugs([]) : []);
    }

    public function test_categories_are_only_the_ones_with_something_in_them(): void
    {
        $this->product(['slug' => 'a', 'category' => 'Apparel']);
        $this->product(['slug' => 'b', 'category' => 'Home']);
        $this->product(['slug' => 'c', 'category' => 'Keepsakes', 'is_active' => 0]);

        // A chip that filters to nothing is a control that cannot do anything.
        $this->assertSame(['Apparel', 'Home'], C::categories());
    }

    public function test_a_sale_moves_the_counter_the_popularity_sort_reads(): void
    {
        $this->product(['slug' => 'tee', 'sold_count' => 5]);

        C::countSales([['slug' => 'tee', 'qty' => 3], ['slug' => 'nope', 'qty' => 2]]);

        $this->assertSame(8, (int) DB::table('gates_products')->where('slug', 'tee')->value('sold_count'));
    }

    public function test_counting_a_sale_starts_from_zero_when_the_counter_is_null(): void
    {
        // COALESCE, because `NULL + 3` is NULL in both MySQL and SQLite — the same trap the
        // questionnaire's voice counters hit.
        $this->product(['slug' => 'tee', 'sold_count' => null]);

        C::countSales([['slug' => 'tee', 'qty' => 3]]);

        $this->assertSame(3, (int) DB::table('gates_products')->where('slug', 'tee')->value('sold_count'));
    }
}
