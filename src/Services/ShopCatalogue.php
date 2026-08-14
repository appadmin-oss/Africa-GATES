<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
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
            $stock = $v->stock !== null ? (int) $v->stock : null;
            return [
                'id'     => (int) $v->id,
                'label'  => (string) $v->label,
                'sku'    => trim((string) ($v->sku ?? '')),
                'axis'   => trim((string) ($v->axis ?? '')),
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
            $axis = strtolower((string) ($choices[0]['axis'] ?? '')) ?: 'option';
            return ['ok' => false, 'message' => 'Please choose a ' . $axis . '.'];
        }

        foreach ($choices as $c) {
            if ((int) $c['id'] !== $variantId) continue;
            if ($c['sold_out']) {
                return ['ok' => false, 'message' => $c['label'] . ' is sold out.'];
            }
            return ['ok' => true, 'message' => '', 'variant_id' => (int) $c['id'],
                    'label' => (string) $c['label'], 'price' => (int) $c['price_naira'],
                    'stock' => $c['stock']];
        }

        // Either it does not exist, is inactive, or belongs to another product. All three are
        // answered the same way: an id that does not name a thing this product sells.
        return ['ok' => false, 'message' => 'That option is no longer available.'];
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
            $axis = strtolower((string) ($vs[0]['axis'] ?? '')) ?: 'option';
            $n = count($live);
            return $n . ' ' . $axis . ($n === 1 ? '' : 's') . ' available';
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
     * @param array{q?:string, category?:string, sort?:string, page?:int, in_stock?:bool} $f
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int,
     *               sort:string, q:string, category:string}
     */
    public static function browse(array $f = []): array
    {
        $q        = trim((string) ($f['q'] ?? ''));
        $category = trim((string) ($f['category'] ?? ''));
        $sort     = isset(self::SORTS[(string) ($f['sort'] ?? '')]) ? (string) $f['sort'] : 'featured';
        $page     = max(1, (int) ($f['page'] ?? 1));

        try {
            $base = DB::table('gates_products')->where('is_active', 1);

            if ($category !== '') $base->where('category', $category);

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
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1,
                    'sort' => $sort, 'q' => $q, 'category' => $category];
        }

        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        return ['rows' => $rows, 'total' => $total, 'page' => min($page, $pages),
                'pages' => $pages, 'sort' => $sort, 'q' => $q, 'category' => $category];
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
