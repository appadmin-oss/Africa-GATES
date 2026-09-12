<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use AfricaGates\Support\Swatch;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The catalogue: variants, images, stock that means something, and a browse that scales.
 *
 * ── WHY THIS EXISTS AS A SERVICE ─────────────────────────────────────────────
 *
 * "Is this in stock" is now asked in four places — the browse grid, the product page, the
 * checkout that must refuse an oversell, and the paid transition that decrements — and each
 * one has to answer it identically. This codebase keeps finding the cost of not doing that:
 * four implementations of "is it sold out" on events, three of "is this reference ours". So
 * the arithmetic is here and the callers do HTTP.
 *
 * ── THE STOCK RULE, WRITTEN ONCE ─────────────────────────────────────────────
 *
 * NULL means UNTRACKED and 0 means SOLD OUT. They are different statements and flattening
 * them with an intval() turns every product nobody counts into a product nobody can buy.
 *
 * When a product has variants, the variant's stock is the truth and the product's column is
 * ignored — a shirt is not "12 in stock", it is four in medium and none in large, and adding
 * those up produces a page that offers a size it cannot ship.
 *
 * ── AND THE BROWSE IS SERVER-SIDE NOW ────────────────────────────────────────
 *
 * The grid rendered every active product and hid the ones a client-side Alpine filter did not
 * match. That works at nine products and stops working at ninety: the page carries the whole
 * catalogue, "3 items" counts things nobody asked for, and there is no way to search at all.
 * {@see browse()} filters, sorts and pages in SQL, so the page is the size of what is on it.
 */
final class ShopCatalogue
{
    /** How many products a browse page shows. */
    public const PER_PAGE = 12;

    /** The orderings offered, and what each one means in SQL. */
    public const SORTS = [
        'featured' => 'What we are showing first',
        'new'      => 'Newest first',
        'popular'  => 'Most bought',
        'cheap'    => 'Price: low to high',
        'dear'     => 'Price: high to low',
        'name'     => 'A – Z',
    ];

    // ══ 1. variants ══════════════════════════════════════════════════════════

    /**
     * The variants a buyer may choose, each priced and with its own availability.
     *
     * @return list<array<string,mixed>>
     */
    public static function variants(int $productId, int $basePrice = 0): array
    {
        try {
            $rows = DB::table('gates_product_variants')
                ->where('product_id', $productId)->where('is_active', 1)
                ->orderBy('sort_order')->orderBy('id')->get();
        } catch (\Throwable) {
            // No variant table yet — a deployment that has uploaded this code and not run
            // /__setup/migrate. The product still sells at its base price, which is exactly
            // how it behaved before variants existed.
            return [];
        }

        return $rows->map(static function ($v) use ($basePrice): array {
            $stock  = $v->stock !== null ? (int) $v->stock : null;
            $swatch = (string) ($v->swatch ?? '');
            return [
                'id'     => (int) $v->id,
                'label'  => (string) $v->label,
                'sku'    => trim((string) ($v->sku ?? '')),
                'axis'   => trim((string) ($v->axis ?? '')),
                // The second answer, when this product asks two questions. Empty string
                // rather than null so a template can compare it without a default filter.
                'label2' => trim((string) ($v->label2 ?? '')),
                'axis2'  => trim((string) ($v->axis2 ?? '')),
                // Validated here, not in the template — it reaches a `style` attribute.
                // Empty means "no swatch", and the caller shows the label alone.
                'swatch'       => Swatch::store($swatch) ?? '',
                'swatch_css'   => Swatch::css($swatch),
                'swatch_light' => Swatch::isLight($swatch),
                'image'  => trim((string) ($v->swatch_image ?? '')),
                'delta'  => (int) $v->price_delta_naira,
                'price_naira' => max(0, $basePrice + (int) $v->price_delta_naira),
                'stock'  => $stock,
                // Two separate facts. `sold_out` is why a button is disabled; `low` is why a
                // line of text appears next to one that is not.
                'sold_out' => $stock !== null && $stock < 1,
                'low'      => $stock !== null && $stock > 0 && $stock <= 3,
            ];
        })->all();
    }

    /**
     * The questions this product asks, and the answers to each — one group per axis.
     *
     * ── WHY THE PAGE CANNOT JUST LIST THE VARIANTS ───────────────────────────
     *
     * A variant row is a COMBINATION: "M, Navy" with its own stock, price and SKU, which is
     * correct because all three belong to the combination rather than to the colour. But a
     * buyer answers two questions, not one of twelve — so this inverts the rows into the
     * groups the page shows, and works out which answers are still possible.
     *
     * `gone` on a choice means EVERY combination containing it is sold out, so the button can
     * be marked without lying: a colour that exists in stock in one size only must not read
     * as unavailable. Which is also why `gone` is recomputed against the OTHER axis's current
     * pick in the browser — "Navy is sold out in M" is a statement about a pair, and the
     * server cannot know the pair before it is chosen.
     *
     * @return list<array{name:string, key:string, kind:string, choices:list<array<string,mixed>>}>
     */
    public static function axes(int $productId, int $basePrice = 0): array
    {
        return self::axesFromVariants(self::variants($productId, $basePrice));
    }

    /**
     * The same inversion, from variants a caller has ALREADY loaded.
     *
     * Split out because the browse grid holds each product's variants for its stock note, and
     * a second query per card to find its colours is an N+1 on the busiest page in the shop.
     *
     * @param list<array<string,mixed>> $vs rows from {@see variants()}
     * @return list<array{name:string, key:string, kind:string, choices:list<array<string,mixed>>}>
     */
    public static function axesFromVariants(array $vs): array
    {
        if ($vs === []) {
            return [];
        }

        $groups = [];
        foreach ([['label', 'axis', 'a'], ['label2', 'axis2', 'b']] as [$lk, $ak, $key]) {
            // Group the rows by this axis's value first, so the checks below can ask questions
            // about a whole value rather than about one row that happens to be first.
            $byValue = [];
            foreach ($vs as $v) {
                $value = (string) $v[$lk];
                if ($value === '') {
                    continue;               // this product does not ask this question
                }
                $byValue[$value][] = $v;
            }
            if ($byValue === []) {
                continue;
            }

            // ── WHICH AXIS DOES A SWATCH BELONG TO? ──────────────────────────
            //
            // The swatch is stored on the variant, which is a COMBINATION — so on a
            // Colour × Size product every "S" row also carries a colour. Reading the swatch
            // off the first row of each value therefore painted the SIZE buttons as coloured
            // discs: five identical Indigo circles labelled S to XXL. (Found by seeding a real
            // two-axis product, not by reasoning about it.)
            //
            // The honest test is functional dependence: a swatch describes THIS axis only if
            // it is the same for every row sharing a value. Within "Indigo" all five sizes are
            // #2A3A63, so Colour owns it; within "S" there are four different colours, so Size
            // does not. No guessing from the axis's NAME, which would fail for an organiser who
            // called it "Shade" or "Colourway" — or worse, succeed for one who called a
            // non-colour axis "Colour scheme".
            $ownsSwatch = self::constantWithin($byValue, 'swatch');
            $ownsImage  = self::constantWithin($byValue, 'image');

            $choices = [];
            foreach ($byValue as $value => $rows) {
                $first = $rows[0];
                $choices[] = [
                    'value'        => (string) $value,
                    'swatch'       => $ownsSwatch ? (string) $first['swatch'] : '',
                    'swatch_css'   => $ownsSwatch ? (string) $first['swatch_css'] : '',
                    'swatch_light' => $ownsSwatch ? (bool) $first['swatch_light'] : true,
                    'image'        => $ownsImage ? (string) $first['image'] : '',
                    // Gone only when EVERY combination containing this value is sold out — a
                    // colour in stock in one size must not read as unavailable. Narrowed
                    // further against the other axis's live pick in the browser.
                    'gone'         => array_reduce(
                        $rows,
                        static fn (bool $c, array $r): bool => $c && (bool) $r['sold_out'],
                        true
                    ),
                ];
            }

            $name = trim((string) ($vs[0][$ak] ?? '')) ?: 'Option';
            // Drawn as swatches only when EVERY choice has one. A row of three coloured squares
            // and one word is not a swatch picker, it is a bug that looks like a design
            // decision — and the odd one out reads as unavailable.
            $allSwatched = $choices !== [] && array_reduce(
                $choices,
                static fn (bool $c, array $ch): bool => $c && $ch['swatch'] !== '',
                true
            );

            $groups[] = [
                'name'    => $name,
                'key'     => $key,
                'kind'    => $allSwatched ? 'swatch' : 'text',
                'choices' => array_values($choices),
            ];
        }

        return $groups;
    }

    /**
     * Is `$field` the same for every row sharing a value, and not the same for all of them?
     *
     * Both halves matter. "Constant within a value" is what makes the field a property OF that
     * value rather than of the combination. "Varies between values" rules out a field that is
     * simply identical everywhere — one colour applied to every row of a single-colour product
     * describes the product, not a choice, and rendering it as a swatch picker would offer a
     * decision with one possible answer.
     *
     * @param array<string, list<array<string,mixed>>> $byValue
     */
    private static function constantWithin(array $byValue, string $field): bool
    {
        $perValue = [];
        foreach ($byValue as $value => $rows) {
            $distinct = [];
            foreach ($rows as $r) {
                $distinct[(string) ($r[$field] ?? '')] = true;
            }
            if (count($distinct) > 1) {
                return false;               // differs inside one value: not this axis's property
            }
            $perValue[] = (string) ($rows[0][$field] ?? '');
        }

        // A single value cannot demonstrate variation, so a one-choice axis is allowed to own
        // the field: a product offered in one colour should still show that colour.
        if (count($perValue) < 2) {
            return true;
        }
        return count(array_unique($perValue)) > 1;
    }

    /** One variant, or null. Never trusts the caller's product id against it — see pick(). */
    public static function variant(int $variantId): ?object
    {
        try {
            return DB::table('gates_product_variants')->where('id', $variantId)->first() ?: null;
        } catch (\Throwable) { return null; }
    }

    /**
     * Resolve what a buyer actually chose, or say why it cannot be sold.
     *
     * ── WHY THE PRODUCT IS RE-CHECKED AGAINST THE VARIANT ────────────────────
     *
     * A variant id arrives from a form. Nothing stops somebody posting the id of a ₦0 variant
     * of a different product alongside the slug of an expensive one, so the pairing is
     * verified rather than assumed — the price is read from the product AND the variant that
     * belongs to it, and a mismatch is refused rather than silently priced from one of them.
     *
     * @return array{ok:bool, message:string, variant_id?:int, label?:string, price?:int, stock?:?int}
     */
    public static function pick(object $product, int $variantId): array
    {
        $base    = (int) $product->price_naira;
        $choices = self::variants((int) $product->id, $base);

        if ($choices === []) {
            // A variant id against a product that has none is an incoherent request — almost
            // always somebody's id paired with somebody else's slug. Answered as "not
            // available" rather than quietly priced from the product: the price would be
            // right and the LINE would be wrong, and a cart carrying a variant this product
            // does not have becomes a packing list nobody can read.
            if ($variantId > 0) {
                return ['ok' => false, 'message' => 'That option is no longer available.'];
            }
            // No variants and none asked for: the product itself is the thing being bought.
            return ['ok' => true, 'message' => '', 'variant_id' => 0, 'label' => '',
                    'price' => $base, 'stock' => self::productStock($product)];
        }

        if ($variantId <= 0) {
            // Name every question the product asks, not just the first. On a two-axis product
            // "Please choose a size" is misleading when a colour is also missing — somebody
            // picks the size, presses the button again and gets the same sentence.
            $names = [];
            foreach (['axis' => 'label', 'axis2' => 'label2'] as $ak => $lk) {
                if (trim((string) ($choices[0][$lk] ?? '')) === '') continue;
                $names[] = strtolower(trim((string) ($choices[0][$ak] ?? ''))) ?: 'option';
            }
            if ($names === []) $names = ['option'];
            return ['ok' => false, 'message' => 'Please choose a ' . implode(' and a ', $names) . '.'];
        }

        foreach ($choices as $c) {
            if ((int) $c['id'] !== $variantId) continue;
            if ($c['sold_out']) {
                return ['ok' => false, 'message' => self::describe($c) . ' is sold out.'];
            }
            return ['ok' => true, 'message' => '', 'variant_id' => (int) $c['id'],
                    // BOTH answers in the label, because this string becomes the order line,
                    // the packing list and the confirmation email — and "Navy" on a picking
                    // slip for a shirt that comes in four sizes is not an instruction anybody
                    // can follow.
                    'label' => self::describe($c), 'price' => (int) $c['price_naira'],
                    'stock' => $c['stock']];
        }

        // Either it does not exist, is inactive, or belongs to another product. All three are
        // answered the same way: an id that does not name a thing this product sells.
        return ['ok' => false, 'message' => 'That option is no longer available.'];
    }

    /**
     * One variant as a person would say it: "Navy · M", or just "M".
     *
     * The separator is a middle dot rather than a comma, because these strings land in CSV
     * exports of orders — {@see \AfricaGates\Admin\Controllers\ShopController} — and a comma
     * inside a field is the thing that makes a spreadsheet open wrong for whoever is packing.
     *
     * @param array<string,mixed> $v a row from {@see variants()}
     */
    public static function describe(array $v): string
    {
        return implode(' · ', array_filter([
            trim((string) ($v['label'] ?? '')),
            trim((string) ($v['label2'] ?? '')),
        ]));
    }

    /** A product's own stock, honouring NULL-means-untracked. */
    public static function productStock(object $product): ?int
    {
        return ($product->stock ?? null) !== null ? (int) $product->stock : null;
    }

    /**
     * How many of this exact thing can be sold right now.
     *
     * `null` is unlimited, which is a legitimate answer and not a missing one — a caller that
     * treats it as zero closes the shop for every untracked product.
     */
    public static function available(object $product, int $variantId = 0): ?int
    {
        if ($variantId > 0) {
            $v = self::variant($variantId);
            if (!$v || (int) $v->product_id !== (int) $product->id) return 0;
            return $v->stock !== null ? max(0, (int) $v->stock) : null;
        }

        // A product WITH variants has no sellable stock of its own: every unit is in a
        // variant, and answering from the product column would offer stock in no size.
        if (self::variants((int) $product->id) !== []) return 0;

        $s = self::productStock($product);
        return $s !== null ? max(0, $s) : null;
    }

    /** A one-line summary of stock for the browse grid: '' when there is nothing to say. */
    public static function stockNote(array $product): string
    {
        $vs = $product['variants'] ?? [];
        if ($vs !== []) {
            $live = array_filter($vs, static fn (array $v): bool => !$v['sold_out']);
            if ($live === []) return 'Sold out';

            // Count DISTINCT answers on the first axis, not variant rows. On a two-axis
            // product the rows are combinations, so counting them said "12 colours available"
            // for a shirt that comes in three — a number a buyer can see is wrong the moment
            // they open the page, which makes every other number on the card suspect.
            $axis   = strtolower((string) ($vs[0]['axis'] ?? '')) ?: 'option';
            $values = [];
            foreach ($live as $v) {
                $values[(string) $v['label']] = true;
            }
            $all = [];
            foreach ($vs as $v) {
                $all[(string) $v['label']] = true;
            }
            $n = count($values);
            $m = count($all);
            // Plural by adding an 's' — right for size, colour, format, length and option,
            // which is the whole of ProductsController::AXES. A word that pluralises another
            // way would need a table, and one wrong plural is a smaller cost than a lookup
            // nobody maintains.
            $word = $axis . ($n === 1 ? '' : 's');

            // "3 of 4" when some have gone, because the card also draws a dot for EVERY colour
            // the product comes in — and "3 colours available" beside four dots reads as one of
            // the two being wrong. Saying both numbers makes the dimmed dot self-explanatory.
            return $m > $n
                ? $n . ' of ' . $m . ' ' . $axis . 's in stock'
                : $n . ' ' . $word . ' available';
        }

        $s = ($product['stock'] ?? null) !== null ? (int) $product['stock'] : null;
        if ($s === null) return '';
        if ($s < 1) return 'Sold out';
        return $s <= 3 ? 'Only ' . $s . ' left' : '';
    }

    // ══ 2. images ════════════════════════════════════════════════════════════

    /**
     * The gallery, with the cover first.
     *
     * The cover column stays authoritative for the first image so a product that has never
     * had a gallery looks exactly as it did — and so the grid, which reads only the cover,
     * can never disagree with the first image on the product page.
     *
     * @return list<array{path:string, alt:string}>
     */
    public static function images(int $productId, ?string $cover = null, string $name = ''): array
    {
        $out = [];
        $cover = trim((string) $cover);
        if ($cover !== '') $out[] = ['path' => $cover, 'alt' => $name];

        try {
            foreach (DB::table('gates_product_images')->where('product_id', $productId)
                        ->orderBy('sort_order')->orderBy('id')->get() as $i) {
                $path = trim((string) $i->path);
                // The cover is often also in the gallery table; showing it twice makes the
                // first thumbnail look like a bug.
                if ($path === '' || $path === $cover) continue;
                $out[] = ['path' => $path, 'alt' => trim((string) ($i->alt ?? '')) ?: $name];
            }
        } catch (\Throwable) {}

        return $out;
    }

    // ══ 3. browse ════════════════════════════════════════════════════════════

    /**
     * Filter, sort and page the catalogue in SQL.
     *
     * ── EVERY FILTER IS A URL, AND THAT IS DELIBERATE ────────────────────────
     *
     * The rail on the shop page is a plain form of links and checkboxes that submits with GET.
     * Nothing here needs JavaScript, which means a filtered shop can be bookmarked, sent to
     * somebody, opened in a new tab and indexed — and it still works on the phone where the
     * script did not load. A JS-only filter is also a filter the server never validates.
     *
     * `mult` is the region price multiplier. The price bounds arrive as what the BUYER SEES,
     * so they are divided by it before hitting the column: filtering the stored price against
     * a displayed range would quietly exclude products in a region where prices are lifted,
     * and the buyer would see a range they set exclude a product priced inside it.
     *
     * @param array{q?:string, category?:string, sort?:string, page?:int, in_stock?:bool,
     *              featured?:bool, min?:?int, max?:?int, mult?:float} $f
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int,
     *               sort:string, q:string, category:string, in_stock:bool, featured:bool,
     *               min:?int, max:?int, filtered:bool}
     */
    public static function browse(array $f = []): array
    {
        $q        = trim((string) ($f['q'] ?? ''));
        $category = trim((string) ($f['category'] ?? ''));
        $sort     = isset(self::SORTS[(string) ($f['sort'] ?? '')]) ? (string) $f['sort'] : 'featured';
        $page     = max(1, (int) ($f['page'] ?? 1));
        $inStock  = (bool) ($f['in_stock'] ?? false);
        $featured = (bool) ($f['featured'] ?? false);
        $mult     = (float) ($f['mult'] ?? 1.0);
        if ($mult <= 0) $mult = 1.0;

        $min = ($f['min'] ?? null) !== null ? max(0, (int) $f['min']) : null;
        $max = ($f['max'] ?? null) !== null ? max(0, (int) $f['max']) : null;
        // A range typed backwards is a mistake, not an empty shop. Swapped rather than refused:
        // the buyer meant a range, and telling them off for the order of two numbers is worse
        // than showing them the range they described.
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $answer = static fn (array $extra): array => $extra + [
            'sort' => $sort, 'q' => $q, 'category' => $category,
            'in_stock' => $inStock, 'featured' => $featured, 'min' => $min, 'max' => $max,
            // Whether anything is narrowing the list — what the "clear all" control keys on,
            // and what makes an empty grid say "nothing matches" rather than "shop is empty".
            'filtered' => $q !== '' || $category !== '' || $inStock || $featured
                          || $min !== null || $max !== null,
        ];

        try {
            $base = DB::table('gates_products')->where('is_active', 1);

            if ($category !== '') $base->where('category', $category);

            // Bounds converted back to stored naira — see the note on `mult` above.
            if ($min !== null) $base->where('price_naira', '>=', (int) floor($min / $mult));
            if ($max !== null) $base->where('price_naira', '<=', (int) ceil($max / $mult));

            if ($featured && OptionalColumn::on('gates_products', 'is_featured')) {
                $base->where('is_featured', 1);
            }

            if ($inStock) {
                self::onlyBuyable($base);
            }

            if ($q !== '') {
                // LIKE, not a full-text index: this catalogue is tens of products, MySQL and
                // SQLite disagree on full-text syntax, and a shared helper that lied on one of
                // them would be worse than a scan nobody can measure.
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
                $base->where(static function ($w) use ($like): void {
                    $w->where('name', 'like', $like)
                      ->orWhere('description', 'like', $like)
                      ->orWhere('category', 'like', $like);
                    if (OptionalColumn::on('gates_products', 'subtitle')) {
                        $w->orWhere('subtitle', 'like', $like);
                    }
                });
            }

            $total = (int) (clone $base)->count();

            self::applySort($base, $sort);

            $rows = $base->forPage($page, self::PER_PAGE)->get()
                ->map(static fn ($r): array => (array) $r)->all();
        } catch (\Throwable) {
            return $answer(['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1]);
        }

        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        return $answer(['rows' => $rows, 'total' => $total,
                        'page' => min($page, $pages), 'pages' => $pages]);
    }

    /**
     * Narrow a query to products somebody can actually buy right now.
     *
     * ── WHY THIS CANNOT BE `stock > 0` ───────────────────────────────────────
     *
     * Three separate facts have to hold together, and every shortcut breaks one of them:
     *
     *   • NULL STOCK IS UNLIMITED, not zero. `stock > 0` closes the shop for every untracked
     *     product, which on this catalogue is most of them.
     *   • A PRODUCT WITH OPTIONS HAS NO STOCK OF ITS OWN. Every unit lives in a variant, so the
     *     product's own column is meaningless for it — the question is whether ANY active
     *     variant is buyable.
     *   • AND ONE WITHOUT OPTIONS is judged on its own column.
     *
     * So: either it has no active variants and its own stock allows a sale, or it has one whose
     * stock does. Expressed as EXISTS subqueries rather than a join, because a join against
     * variants multiplies the product rows and quietly breaks both the count and the paging.
     */
    private static function onlyBuyable(mixed $q): void
    {
        $hasVariants = DB::schema()->hasTable('gates_product_variants');

        if (!$hasVariants) {
            $q->where(static function ($w): void {
                $w->whereNull('stock')->orWhere('stock', '>', 0);
            });
            return;
        }

        $q->where(static function ($outer): void {
            // A buyable variant.
            $outer->whereExists(static function ($sub): void {
                $sub->selectRaw('1')->from('gates_product_variants as v')
                    ->whereColumn('v.product_id', 'gates_products.id')
                    ->where('v.is_active', 1)
                    ->where(static function ($w): void {
                        $w->whereNull('v.stock')->orWhere('v.stock', '>', 0);
                    });
            });
            // Or no variants at all, and its own stock allows a sale.
            $outer->orWhere(static function ($plain): void {
                $plain->whereNotExists(static function ($sub): void {
                    $sub->selectRaw('1')->from('gates_product_variants as v2')
                        ->whereColumn('v2.product_id', 'gates_products.id')
                        ->where('v2.is_active', 1);
                })->where(static function ($w): void {
                    $w->whereNull('stock')->orWhere('stock', '>', 0);
                });
            });
        });
    }

    /**
     * The cheapest and dearest active product, for the price rail's own bounds.
     *
     * Read from the catalogue rather than hard-coded, so a rail on a shop of ₦2,000 keyrings
     * does not offer a ₦500,000 upper bound — and so it widens by itself when somebody adds a
     * more expensive product.
     *
     * @return array{min:int, max:int}
     */
    public static function priceRange(float $mult = 1.0): array
    {
        if ($mult <= 0) $mult = 1.0;
        try {
            $row = DB::table('gates_products')->where('is_active', 1)
                ->selectRaw('MIN(price_naira) as lo, MAX(price_naira) as hi')->first();
            $lo = (int) floor(((int) ($row->lo ?? 0)) * $mult);
            $hi = (int) ceil(((int) ($row->hi ?? 0)) * $mult);
        } catch (\Throwable) {
            return ['min' => 0, 'max' => 0];
        }
        return ['min' => $lo, 'max' => max($lo, $hi)];
    }

    /**
     * The colours a product comes in, for a dot row on its grid card.
     *
     * The grid reads the cover image only, so before this a shirt in four colours looked like
     * one shirt — and the buyer had to open it to find out. Capped, because six dots on a small
     * card is a texture rather than information; the count of the rest is shown as "+2".
     *
     * @param list<array<string,mixed>> $variants rows from {@see variants()}
     * @return array{dots:list<array{css:string,name:string,gone:bool}>, more:int}
     */
    public static function swatchDots(array $variants, int $limit = 5): array
    {
        if ($variants === []) {
            return ['dots' => [], 'more' => 0];
        }
        // Only from the axis that actually owns the colours — the same ownership rule as
        // axes(), for the same reason: on a Colour × Size product every size row carries a
        // colour too, and reading them all would show each colour once per size.
        $swatched = null;
        foreach (self::axesFromVariants($variants) as $g) {
            if ($g['kind'] === 'swatch') { $swatched = $g; break; }
        }
        if ($swatched === null) {
            return ['dots' => [], 'more' => 0];
        }

        $all  = $swatched['choices'];
        $dots = [];
        foreach (array_slice($all, 0, $limit) as $c) {
            $dots[] = ['css' => (string) $c['swatch_css'], 'name' => (string) $c['value'],
                       'gone' => (bool) $c['gone']];
        }
        return ['dots' => $dots, 'more' => max(0, count($all) - count($dots))];
    }

    /**
     * The orderings, each with a stable tiebreaker.
     *
     * Every sort ends with `id` descending. Without it, two products at the same price come
     * back in whatever order the engine felt like — which means page 2 can repeat a row from
     * page 1 and omit another entirely, and the buyer never sees the missing one.
     */
    private static function applySort(mixed $q, string $sort): void
    {
        $featured = OptionalColumn::on('gates_products', 'is_featured');
        $sold     = OptionalColumn::on('gates_products', 'sold_count');

        switch ($sort) {
            case 'new':    $q->orderByDesc('id'); return;
            case 'cheap':  $q->orderBy('price_naira'); break;
            case 'dear':   $q->orderByDesc('price_naira'); break;
            case 'name':   $q->orderBy('name'); break;
            case 'popular':
                if ($sold) $q->orderByDesc(DB::raw('COALESCE(sold_count, 0)'));
                else $q->orderBy('sort_order');
                break;
            default:
                // The admin's own arrangement, with anything flagged featured lifted above it.
                if ($featured) $q->orderByDesc(DB::raw('COALESCE(is_featured, 0)'));
                $q->orderBy('sort_order');
        }
        $q->orderByDesc('id');
    }

    /** The categories that actually have something active in them. */
    public static function categories(): array
    {
        try {
            return DB::table('gates_products')->where('is_active', 1)
                ->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->orderBy('category')->pluck('category')
                ->map(static fn ($c): string => (string) $c)->all();
        } catch (\Throwable) { return []; }
    }

    /**
     * Count a sale against the products in it, for the "most bought" ordering.
     *
     * Denormalised on purpose: the alternative is scanning every order's items_json on every
     * browse request, which is a full table read to sort twelve rows.
     *
     * @param list<array<string,mixed>> $lines
     */
    public static function countSales(array $lines): void
    {
        if (!OptionalColumn::on('gates_products', 'sold_count')) return;
        foreach ($lines as $l) {
            $slug = (string) ($l['slug'] ?? '');
            $qty  = max(0, (int) ($l['qty'] ?? 0));
            if ($slug === '' || $qty === 0) continue;
            try {
                DB::table('gates_products')->where('slug', $slug)
                    ->update(['sold_count' => DB::raw('COALESCE(sold_count, 0) + ' . $qty)]);
            } catch (\Throwable) {}
        }
    }
}
