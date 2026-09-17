<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ProductsController;
use AfricaGates\Admin\Services\{AuditService, UploadService};
use AfricaGates\Services\ShopCatalogue;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * The product editor writing variants, and refusing to lose anybody's history.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS PATH NEEDS ITS OWN TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Variants are the shop's headline change and this form is the ONLY way to create one, so a
 * silent failure here means the whole feature is unreachable — the public side would work
 * perfectly against data nobody can enter.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. IDS SURVIVE A SAVE. An order line points at its variant id; reissuing ids on every save
 *      would silently reattach a paid customer to a different size.
 *   2. A VARIANT WITH SALES IS DEACTIVATED, NOT DELETED. Hard-deleting it leaves a paying
 *      customer attached to nothing — a packing list showing a shirt with no size — and every
 *      "how many did we sell in large" answer changes retroactively.
 *   3. '' IS UNTRACKED AND 0 IS SOLD OUT. Two statements, and flattening them turns every
 *      uncounted size into one nobody can buy.
 *   4. A BLANK ROW IS NOT A VARIANT. The editor always renders one spare.
 *   5. A FORM WITH NO VARIANT FIELDS AT ALL LEAVES THEM ALONE. That is what a pre-migration
 *      deployment posts, and treating it as "delete everything" would wipe the sizes the
 *      moment somebody edited a description.
 */
final class ShopProductEditorTest extends TestCase
{
    private int $productId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_product_variants')->delete();
        DB::table('gates_products')->delete();
        DB::table('gates_orders')->delete();

        $this->productId = (int) DB::table('gates_products')->insertGetId([
            'slug' => 'tee', 'name' => 'The Tee', 'category' => 'Apparel',
            'price_naira' => 18500, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function controller(): ProductsController
    {
        // save() redirects and never renders, so any Twig instance satisfies the constructor.
        return new ProductsController(
            new Twig(new ArrayLoader([])),
            new AuditService(),
            new UploadService(),
        );
    }

    /** Post the product form. @param array<string,mixed> $body */
    private function save(array $body): void
    {
        $_SESSION['admin_id'] = 1;
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://afg.local/admin/products/' . $this->productId)
            ->withParsedBody(array_merge([
                'name' => 'The Tee', 'slug' => 'tee', 'category' => 'Apparel',
                'price_naira' => 18500, 'sort_order' => 0, 'is_active' => '1',
            ], $body));
        $this->controller()->save($req, new Response(), ['id' => $this->productId]);
    }

    /** @return list<array{label:string,stock:?int,delta:int,active:int,id:int}> */
    private function rows(): array
    {
        return DB::table('gates_product_variants')->where('product_id', $this->productId)
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(static fn ($v): array => [
                'id' => (int) $v->id, 'label' => (string) $v->label,
                'stock' => $v->stock === null ? null : (int) $v->stock,
                'delta' => (int) $v->price_delta_naira, 'active' => (int) $v->is_active,
            ])->all();
    }

    // ══ 1. creating ══════════════════════════════════════════════════════════

    public function test_variants_are_created_in_the_order_they_were_typed(): void
    {
        $this->save([
            'variant_axis' => 'Size',
            'var_id'    => ['', '', ''],
            'var_label' => ['S', 'M', 'L'],
            'var_sku'   => ['TEE-S', 'TEE-M', ''],
            'var_delta' => [0, 0, 500],
            'var_stock' => ['9', '0', ''],
        ]);

        $rows = $this->rows();
        $this->assertSame(['S', 'M', 'L'], array_column($rows, 'label'));
        // '' is untracked, 0 is sold out. Two different statements about a size.
        $this->assertSame([9, 0, null], array_column($rows, 'stock'));
        $this->assertSame([0, 0, 500], array_column($rows, 'delta'));
        $this->assertSame('Size', (string) DB::table('gates_product_variants')->value('axis'));
    }

    public function test_a_blank_row_is_not_a_variant(): void
    {
        // The editor always renders one spare row, and a whitespace label is the same thing.
        $this->save([
            'variant_axis' => 'Size',
            'var_id'    => ['', '', ''],
            'var_label' => ['S', '   ', ''],
            'var_sku'   => ['', '', ''],
            'var_delta' => [0, 0, 0],
            'var_stock' => ['4', '', ''],
        ]);

        $this->assertSame(['S'], array_column($this->rows(), 'label'));
    }

    public function test_the_axis_is_written_onto_every_variant(): void
    {
        $this->save([
            'variant_axis' => 'Colour',
            'var_id' => ['', ''], 'var_label' => ['Indigo', 'Sand'],
            'var_sku' => ['', ''], 'var_delta' => [0, 0], 'var_stock' => ['', ''],
        ]);

        // Named on each row so the public page can say "Colour" above the buttons rather than
        // "Options" — a product page that says "choose an option" has not been finished.
        $axes = DB::table('gates_product_variants')->pluck('axis')->all();
        $this->assertSame(['Colour', 'Colour'], array_map('strval', $axes));
    }

    // ══ 2. editing ═══════════════════════════════════════════════════════════

    public function test_ids_survive_a_save_so_a_paid_order_stays_attached(): void
    {
        $this->save([
            'variant_axis' => 'Size',
            'var_id' => ['', ''], 'var_label' => ['S', 'M'],
            'var_sku' => ['', ''], 'var_delta' => [0, 0], 'var_stock' => ['5', '5'],
        ]);
        $before = array_column($this->rows(), 'id');

        // The same two rows come back edited, carrying their ids.
        $this->save([
            'variant_axis' => 'Size',
            'var_id' => [(string) $before[0], (string) $before[1]],
            'var_label' => ['S', 'M'], 'var_sku' => ['NEW-S', ''],
            'var_delta' => [0, 1200], 'var_stock' => ['3', '5'],
        ]);

        $after = $this->rows();
        $this->assertSame($before, array_column($after, 'id'), 'ids were reissued on save');
        $this->assertSame(3, $after[0]['stock']);
        $this->assertSame(1200, $after[1]['delta']);
    }

    public function test_an_id_belonging_to_another_product_creates_a_new_row_instead(): void
    {
        $other = (int) DB::table('gates_products')->insertGetId([
            'slug' => 'cap', 'name' => 'Cap', 'category' => 'Apparel', 'price_naira' => 9000,
            'is_active' => 1, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $theirs = (int) DB::table('gates_product_variants')->insertGetId([
            'product_id' => $other, 'label' => 'One size', 'axis' => 'Size',
            'price_delta_naira' => 0, 'stock' => 40, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->save([
            'variant_axis' => 'Size',
            'var_id' => [(string) $theirs], 'var_label' => ['Mine'],
            'var_sku' => [''], 'var_delta' => [0], 'var_stock' => ['1'],
        ]);

        // Their variant is untouched — an id from a form must not be able to rewrite another
        // product's stock.
        $this->assertSame('One size',
            (string) DB::table('gates_product_variants')->where('id', $theirs)->value('label'));
        $this->assertSame(40, (int) DB::table('gates_product_variants')->where('id', $theirs)->value('stock'));
        $this->assertSame(['Mine'], array_column($this->rows(), 'label'));
    }

    // ══ 3. removing ══════════════════════════════════════════════════════════

    public function test_a_variant_nobody_bought_is_deleted(): void
    {
        $this->save([
            'variant_axis' => 'Size',
            'var_id' => ['', ''], 'var_label' => ['S', 'Typpo'],
            'var_sku' => ['', ''], 'var_delta' => [0, 0], 'var_stock' => ['', ''],
        ]);
        $ids = array_column($this->rows(), 'id');

        $this->save([
            'variant_axis' => 'Size',
            'var_id' => [(string) $ids[0]], 'var_label' => ['S'],
            'var_sku' => [''], 'var_delta' => [0], 'var_stock' => [''],
        ]);

        // A mistake being corrected, not history being rewritten — and a graveyard of inactive
        // typos makes the editor unreadable.
        $this->assertSame(['S'], array_column($this->rows(), 'label'));
        $this->assertSame(1, DB::table('gates_product_variants')->where('product_id', $this->productId)->count());
    }

    public function test_a_variant_with_a_paid_order_against_it_is_deactivated_not_deleted(): void
    {
        $this->save([
            'variant_axis' => 'Size',
            'var_id' => ['', ''], 'var_label' => ['S', 'M'],
            'var_sku' => ['', ''], 'var_delta' => [0, 0], 'var_stock' => ['5', '5'],
        ]);
        $ids = array_column($this->rows(), 'id');
        $sold = $ids[1];

        DB::table('gates_orders')->insert([
            'reference' => 'AFG-SHP-1', 'email' => 'a@x.test', 'name' => 'A',
            'items_json' => json_encode([['slug' => 'tee', 'variant_id' => $sold, 'variant' => 'M', 'qty' => 1]]),
            'subtotal_naira' => 18500, 'status' => 'paid',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        // The organiser removes M from the form.
        $this->save([
            'variant_axis' => 'Size',
            'var_id' => [(string) $ids[0]], 'var_label' => ['S'],
            'var_sku' => [''], 'var_delta' => [0], 'var_stock' => ['5'],
        ]);

        $row = DB::table('gates_product_variants')->where('id', $sold)->first();
        $this->assertNotNull($row, 'a paid order was left pointing at a deleted variant');
        $this->assertSame(0, (int) $row->is_active);
        // And it is no longer offered to a buyer.
        $this->assertSame(['S'], array_column(ShopCatalogue::variants($this->productId), 'label'));
    }

    public function test_a_form_with_no_variant_fields_leaves_the_variants_alone(): void
    {
        $this->save([
            'variant_axis' => 'Size',
            'var_id' => ['', ''], 'var_label' => ['S', 'M'],
            'var_sku' => ['', ''], 'var_delta' => [0, 0], 'var_stock' => ['5', '5'],
        ]);

        // What a pre-migration deployment posts: no variant inputs at all. Treating that as
        // "delete everything" would wipe the sizes the moment somebody fixed a typo in the
        // description.
        $this->save(['description' => 'A better description.']);

        $this->assertSame(['S', 'M'], array_column($this->rows(), 'label'));
    }

    // ══ 4. the product's own new fields ══════════════════════════════════════

    public function test_the_new_product_fields_are_written(): void
    {
        $this->save([
            'subtitle'    => 'Heavyweight cotton, embroidered gate mark.',
            'details'     => "100% combed cotton.\nCold wash.",
            'ships_free'  => '1',
            'is_featured' => '1',
        ]);

        $p = DB::table('gates_products')->where('id', $this->productId)->first();
        $this->assertSame('Heavyweight cotton, embroidered gate mark.', (string) $p->subtitle);
        $this->assertStringContainsString('Cold wash', (string) $p->details);
        $this->assertSame(1, (int) $p->ships_free);
        $this->assertSame(1, (int) $p->is_featured);
    }

    public function test_unticked_flags_are_written_as_off_rather_than_left_alone(): void
    {
        DB::table('gates_products')->where('id', $this->productId)
            ->update(['ships_free' => 1, 'is_featured' => 1]);

        // An unchecked checkbox is absent from a POST, so a save that only wrote the ticked
        // ones could never turn either of these off again.
        $this->save([]);

        $p = DB::table('gates_products')->where('id', $this->productId)->first();
        $this->assertSame(0, (int) $p->ships_free);
        $this->assertSame(0, (int) $p->is_featured);
    }
}
