<?php
declare(strict_types=1);

namespace Tests\Feature;

use AfricaGates\Services\ShopCatalogue;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Two questions instead of twelve answers.
 *
 * A variant row is one sellable COMBINATION — "M, Navy" — which is right, because the stock,
 * the price and the SKU all belong to the pair. A buyer, though, answers two questions. These
 * tests cover the inversion between the two, and the one thing that inversion gets wrong if
 * nobody thinks about it: which axis a swatch actually belongs to.
 */
final class ShopVariantAxesTest extends TestCase
{
    /**
     * @param list<array{0:string,1:string,2:?string,3:?int,4:int}> $rows
     *        [labelA, labelB, swatch, stock, delta]
     */
    private function product(array $rows, string $axisA = 'Colour', string $axisB = 'Size',
                             int $price = 10000, ?string $imageFor = null): int
    {
        $slug = 'ax-' . bin2hex(random_bytes(4));
        $id = (int) DB::table('gates_products')->insertGetId([
            'name' => 'Axes ' . $slug, 'slug' => $slug, 'category' => 'Apparel',
            'price_naira' => $price, 'is_active' => 1,
        ]);
        $now = Carbon::now()->toDateTimeString();
        $o = 0;
        foreach ($rows as [$a, $b, $swatch, $stock, $delta]) {
            DB::table('gates_product_variants')->insert([
                'product_id' => $id,
                'label' => $a, 'label2' => $b !== '' ? $b : null,
                'axis' => $axisA, 'axis2' => $b !== '' ? $axisB : null,
                'swatch' => $swatch,
                'swatch_image' => ($imageFor !== null && $a === $imageFor) ? '/uploads/' . $a . '.jpg' : null,
                'price_delta_naira' => $delta, 'stock' => $stock,
                'is_active' => 1, 'sort_order' => $o++,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return $id;
    }

    /** @return array<string, array<string,mixed>> group name => group */
    private function byName(array $axes): array
    {
        $out = [];
        foreach ($axes as $g) $out[(string) $g['name']] = $g;
        return $out;
    }

    // ══ 1. the inversion ═════════════════════════════════════════════════════

    public function test_a_two_axis_product_yields_two_groups_of_distinct_answers(): void
    {
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 3, 0],
            ['Navy',  'M', '#1B2A4A', 2, 0],
            ['Cream', 'S', '#EFE6D2', 1, 0],
            ['Cream', 'M', '#EFE6D2', 4, 0],
        ]);

        $g = $this->byName(ShopCatalogue::axes($id, 10000));
        $this->assertCount(2, $g);
        $this->assertSame(['Navy', 'Cream'], array_column($g['Colour']['choices'], 'value'));
        $this->assertSame(['S', 'M'], array_column($g['Size']['choices'], 'value'));
        // Keys are what the page binds its buttons to, and they must be stable.
        $this->assertSame('a', $g['Colour']['key']);
        $this->assertSame('b', $g['Size']['key']);
    }

    public function test_a_one_axis_product_is_unchanged(): void
    {
        // The whole migration must be invisible to a product nobody edits.
        $id = $this->product([
            ['S', '', null, 5, 0],
            ['M', '', null, 0, 0],
            ['L', '', null, null, 900],
        ], 'Size');

        $axes = ShopCatalogue::axes($id, 10000);
        $this->assertCount(1, $axes, 'a single-axis product must not grow a second question');
        $this->assertSame('Size', $axes[0]['name']);
        $this->assertSame('text', $axes[0]['kind']);
        $this->assertSame(['S', 'M', 'L'], array_column($axes[0]['choices'], 'value'));
    }

    public function test_a_product_with_no_variants_has_no_questions(): void
    {
        $id = $this->product([]);
        $this->assertSame([], ShopCatalogue::axes($id, 10000));
    }

    // ══ 2. which axis owns the swatch ════════════════════════════════════════

    public function test_a_swatch_belongs_only_to_the_axis_it_varies_with(): void
    {
        // THE BUG THIS EXISTS FOR. The swatch is stored on the variant, i.e. on the
        // combination — so every "S" row also carries a colour. Reading it off the first row
        // of each value painted the SIZE buttons as five identical navy discs labelled S to
        // XXL. Found by seeding a real two-axis product and looking at it.
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 3, 0],
            ['Navy',  'M', '#1B2A4A', 2, 0],
            ['Cream', 'S', '#EFE6D2', 1, 0],
            ['Cream', 'M', '#EFE6D2', 4, 0],
        ]);

        $g = $this->byName(ShopCatalogue::axes($id, 10000));

        $this->assertSame('swatch', $g['Colour']['kind']);
        $this->assertSame('#1B2A4A', $g['Colour']['choices'][0]['swatch']);

        $this->assertSame('text', $g['Size']['kind'], 'the size buttons became colour discs');
        foreach ($g['Size']['choices'] as $c) {
            $this->assertSame('', $c['swatch'], 'a size answer carries a colour it does not own');
            $this->assertSame('', $c['swatch_css']);
        }
    }

    public function test_the_owning_axis_is_found_by_dependence_and_not_by_its_name(): void
    {
        // An organiser who calls it "Shade" or "Colourway" must still get swatches, and one
        // who calls a non-colour axis "Colour scheme" must not get them on the wrong group.
        $id = $this->product([
            ['Indigo', 'Long',  '#2A3A63', 2, 0],
            ['Indigo', 'Short', '#2A3A63', 2, 0],
            ['Rust',   'Long',  '#8A4B2A', 2, 0],
            ['Rust',   'Short', '#8A4B2A', 2, 0],
        ], 'Shade', 'Length');

        $g = $this->byName(ShopCatalogue::axes($id, 10000));
        $this->assertSame('swatch', $g['Shade']['kind']);
        $this->assertSame('text', $g['Length']['kind']);
    }

    public function test_a_group_is_swatches_only_when_every_answer_has_one(): void
    {
        // A row of three coloured discs and one word is not a swatch picker — it is a bug that
        // looks like a design decision, and the odd one out reads as unavailable.
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 2, 0],
            ['Navy',  'M', '#1B2A4A', 2, 0],
            ['Cream', 'S', null,      2, 0],
            ['Cream', 'M', null,      2, 0],
        ]);

        $g = $this->byName(ShopCatalogue::axes($id, 10000));
        $this->assertSame('text', $g['Colour']['kind'],
            'a half-swatched group must fall back to words');
    }

    public function test_one_colour_across_every_row_is_not_offered_as_a_choice_of_swatch(): void
    {
        // A single colour applied everywhere describes the PRODUCT, not a decision. Drawing it
        // as a picker offers a question with one possible answer.
        $id = $this->product([
            ['S', '', '#1B2A4A', 2, 0],
            ['M', '', '#1B2A4A', 2, 0],
            ['L', '', '#1B2A4A', 2, 0],
        ], 'Size');

        $axes = ShopCatalogue::axes($id, 10000);
        $this->assertSame('text', $axes[0]['kind']);
    }

    public function test_a_product_offered_in_exactly_one_colour_still_shows_it(): void
    {
        // The other side of the same rule: with one value there is no variation to observe, and
        // hiding the colour would lose real information.
        $id = $this->product([['Navy', '', '#1B2A4A', 4, 0]], 'Colour');
        $axes = ShopCatalogue::axes($id, 10000);
        $this->assertSame('swatch', $axes[0]['kind']);
        $this->assertSame('#1B2A4A', $axes[0]['choices'][0]['swatch']);
    }

    public function test_a_per_colour_photograph_follows_the_same_ownership_rule(): void
    {
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 2, 0],
            ['Navy',  'M', '#1B2A4A', 2, 0],
            ['Cream', 'S', '#EFE6D2', 2, 0],
            ['Cream', 'M', '#EFE6D2', 2, 0],
        ], imageFor: 'Navy');

        $g = $this->byName(ShopCatalogue::axes($id, 10000));
        $this->assertSame('/uploads/Navy.jpg', $g['Colour']['choices'][0]['image']);
        // Only Navy has one, so the image is NOT constant within each colour... it is: every
        // Navy row has it and every Cream row has none, which is exactly the dependence being
        // asserted. What must not happen is a size inheriting it.
        foreach ($g['Size']['choices'] as $c) {
            $this->assertSame('', $c['image'], 'a size answer inherited a colour photograph');
        }
    }

    // ══ 3. what is still available ═══════════════════════════════════════════

    public function test_an_answer_is_gone_only_when_every_combination_of_it_is_gone(): void
    {
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 0, 0],   // Navy gone in S…
            ['Navy',  'M', '#1B2A4A', 2, 0],   // …but not in M
            ['Cream', 'S', '#EFE6D2', 0, 0],
            ['Cream', 'M', '#EFE6D2', 0, 0],   // Cream gone everywhere
        ]);

        $g = $this->byName(ShopCatalogue::axes($id, 10000));
        $colours = array_column($g['Colour']['choices'], 'gone', 'value');
        $this->assertFalse($colours['Navy'], 'a colour in stock in one size read as unavailable');
        $this->assertTrue($colours['Cream']);

        $sizes = array_column($g['Size']['choices'], 'gone', 'value');
        $this->assertTrue($sizes['S'], 'S is 0 in both colours');
        $this->assertFalse($sizes['M']);
    }

    public function test_untracked_stock_is_never_read_as_sold_out(): void
    {
        // NULL is unlimited, which is a legitimate answer and not a missing one.
        $id = $this->product([
            ['Navy', 'S', '#1B2A4A', null, 0],
            ['Navy', 'M', '#1B2A4A', 0, 0],
        ]);
        $g = $this->byName(ShopCatalogue::axes($id, 10000));
        $this->assertFalse($g['Colour']['choices'][0]['gone']);
        $sizes = array_column($g['Size']['choices'], 'gone', 'value');
        $this->assertFalse($sizes['S']);
        $this->assertTrue($sizes['M']);
    }

    public function test_an_inactive_combination_is_not_offered(): void
    {
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 2, 0],
            ['Cream', 'S', '#EFE6D2', 2, 0],
        ]);
        DB::table('gates_product_variants')->where('product_id', $id)
            ->where('label', 'Cream')->update(['is_active' => 0]);

        $g = $this->byName(ShopCatalogue::axes($id, 10000));
        $this->assertSame(['Navy'], array_column($g['Colour']['choices'], 'value'));
    }

    // ══ 4. what a chosen pair is called, and what it costs ═══════════════════

    public function test_picking_a_combination_names_both_answers(): void
    {
        // This string becomes the order line, the packing list and the confirmation email.
        // "Navy" on a picking slip for a shirt that comes in four sizes is not an instruction.
        $id = $this->product([
            ['Navy', 'S',   '#1B2A4A', 2, 0],
            ['Navy', 'XXL', '#1B2A4A', 2, 1500],
        ]);
        $product = DB::table('gates_products')->where('id', $id)->first();
        $vs = ShopCatalogue::variants($id, 10000);

        $big = null;
        foreach ($vs as $v) if ($v['label2'] === 'XXL') $big = $v;
        $this->assertNotNull($big);

        $r = ShopCatalogue::pick($product, (int) $big['id']);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('Navy · XXL', $r['label']);
        $this->assertSame(11500, $r['price'], 'the delta belongs to the combination');
    }

    public function test_the_separator_is_never_a_comma(): void
    {
        // These strings land in the orders CSV, and a comma inside a field is what makes a
        // spreadsheet open wrong for whoever is packing.
        $this->assertStringNotContainsString(',',
            ShopCatalogue::describe(['label' => 'Navy', 'label2' => 'XXL']));
        $this->assertSame('Navy · XXL',
            ShopCatalogue::describe(['label' => 'Navy', 'label2' => 'XXL']));
        $this->assertSame('M', ShopCatalogue::describe(['label' => 'M', 'label2' => '']));
        $this->assertSame('', ShopCatalogue::describe([]));
    }

    public function test_choosing_nothing_names_every_question_that_is_open(): void
    {
        // "Please choose a size" while a colour is also missing is the sentence somebody reads,
        // picks the size, presses the button and gets again.
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 2, 0],
            ['Cream', 'M', '#EFE6D2', 2, 0],
        ]);
        $product = DB::table('gates_products')->where('id', $id)->first();

        $r = ShopCatalogue::pick($product, 0);
        $this->assertFalse($r['ok']);
        $this->assertSame('Please choose a colour and a size.', $r['message']);
    }

    public function test_a_sold_out_combination_is_refused_by_both_its_names(): void
    {
        $id = $this->product([['Navy', 'S', '#1B2A4A', 0, 0]]);
        $product = DB::table('gates_products')->where('id', $id)->first();
        $vid = (int) ShopCatalogue::variants($id, 10000)[0]['id'];

        $r = ShopCatalogue::pick($product, $vid);
        $this->assertFalse($r['ok']);
        $this->assertSame('Navy · S is sold out.', $r['message']);
    }

    // ══ 5. the grid card ═════════════════════════════════════════════════════

    public function test_the_grid_counts_colours_rather_than_combinations(): void
    {
        // It counted variant ROWS, so a shirt in three colours and four sizes advertised
        // "12 colours available" — a number a buyer can see is wrong the moment they open the
        // page, which makes every other number on the card suspect.
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 2, 0], ['Navy',  'M', '#1B2A4A', 2, 0],
            ['Cream', 'S', '#EFE6D2', 2, 0], ['Cream', 'M', '#EFE6D2', 2, 0],
            ['Palm',  'S', '#2F6B41', 2, 0], ['Palm',  'M', '#2F6B41', 2, 0],
        ]);
        $note = ShopCatalogue::stockNote([
            'variants' => ShopCatalogue::variants($id, 10000), 'stock' => null,
        ]);
        $this->assertSame('3 colours available', $note);
    }

    public function test_the_grid_says_sold_out_when_no_combination_can_be_bought(): void
    {
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 0, 0],
            ['Cream', 'S', '#EFE6D2', 0, 0],
        ]);
        $this->assertSame('Sold out', ShopCatalogue::stockNote([
            'variants' => ShopCatalogue::variants($id, 10000), 'stock' => null,
        ]));
    }

    /**
     * The singular wording — "1 colour", not "1 colours".
     *
     * ── WHY THE FIXTURE CHANGED ──────────────────────────────────────────────
     *
     * It used to seed Navy in stock AND Cream sold out, then assert
     * "1 colour available". That stopped being the right answer when stockNote()
     * learned to say "N of M" whenever some have gone — which it does because the
     * card draws a dot for EVERY colour a product comes in, and "1 colour available"
     * beside two dots reads as one of the two being wrong.
     *
     * So the old fixture was asserting the arithmetic, not the plural, and the
     * arithmetic had moved. Nobody found out, because this file sits in tests/Feature
     * and tests/Feature was not in the suite.
     *
     * One variant, in stock: the only shape where the singular is what should be said.
     */
    public function test_one_available_answer_is_singular(): void
    {
        $id = $this->product([['Navy', 'S', '#1B2A4A', 2, 0]]);
        $this->assertSame('1 colour available', ShopCatalogue::stockNote([
            'variants' => ShopCatalogue::variants($id, 10000), 'stock' => null,
        ]));
    }

    /** And the "N of M" form the moment one of them has gone, for the reason above. */
    public function test_a_colour_that_has_gone_is_counted_in_the_total(): void
    {
        $id = $this->product([
            ['Navy',  'S', '#1B2A4A', 2, 0],
            ['Cream', 'S', '#EFE6D2', 0, 0],
        ]);
        $this->assertSame('1 of 2 colours in stock', ShopCatalogue::stockNote([
            'variants' => ShopCatalogue::variants($id, 10000), 'stock' => null,
        ]));
    }

    // ══ 6. a hostile row cannot reach the page ═══════════════════════════════

    public function test_a_swatch_written_straight_into_the_database_is_still_refused(): void
    {
        // Which is why the value is validated on READ as well as on write: a row can arrive
        // from a restored backup, a direct SQL edit, or an older build, and never pass through
        // the admin form at all.
        //
        // ── THE PAYLOAD HAS TO FIT THE COLUMN ────────────────────────────────
        //
        // This used to write a 36-character CSS injection. `swatch` is VARCHAR(20) on
        // MySQL, so the database refused the write outright and the test errored before it
        // could assert anything — it was describing a row production cannot hold, which is
        // the opposite of what a test about hostile rows is for. Nineteen characters, still
        // a break-out of the declaration, and something a restored backup really could
        // carry.
        $id = $this->product([['Navy', '', '#1B2A4A', 2, 0]], 'Colour');
        DB::table('gates_product_variants')->where('product_id', $id)
            ->update(['swatch' => '#fff;width:100vw']);

        $v = ShopCatalogue::variants($id, 10000)[0];
        $this->assertSame('', $v['swatch']);
        $this->assertSame('', $v['swatch_css']);
        // And the group falls back to words rather than drawing an empty disc.
        $this->assertSame('text', ShopCatalogue::axes($id, 10000)[0]['kind']);
    }
}
