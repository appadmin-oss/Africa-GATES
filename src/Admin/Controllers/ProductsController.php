<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\{AuditService, UploadService};

/** Admin CRUD for shop products (gates_products). Prices are whole naira. */
class ProductsController
{
    public const CATEGORIES = ['Apparel', 'Accessories', 'Home', 'Keepsakes'];
    public const TAGS       = ['Bestseller', 'New', 'Limited'];
    /** Canonical delivery regions (Nigerian geopolitical zones). No selection = nationwide. */
    public const REGIONS    = ['North Central', 'North East', 'North West', 'South East', 'South South', 'South West'];

    public function __construct(
        private readonly Twig          $view,
        private readonly AuditService  $audit,
        private readonly UploadService $uploads,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_products')->orderBy('sort_order')->orderByDesc('id')
            ->get()->map(fn($r) => (array)$r)->all();
        return $this->view->render($res, 'admin/products/index.twig', [
            'page_title' => 'Products — Admin',
            'admin_page' => 'products',
            'rows'       => $rows,
        ]);
    }

    /** What a variant answers. Free text on the row; these are the ones offered in the editor. */
    public const AXES = ['Size', 'Colour', 'Format', 'Length', 'Option'];

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id  = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_products')->where('id', $id)->first() : [];

        // Seeds for the Alpine repeaters — stable ids so editing a row does not lose focus,
        // and the DATABASE id carried separately so a save upserts rather than reissuing.
        $variantSeed = [];
        $i = 0;
        foreach ($id ? \AfricaGates\Services\ShopCatalogue::variants($id) : [] as $v) {
            $variantSeed[] = [
                'id' => ++$i, 'vid' => (int) $v['id'], 'label' => (string) $v['label'],
                'sku' => (string) $v['sku'], 'delta' => (int) $v['delta'],
                'stock' => $v['stock'] === null ? '' : (string) (int) $v['stock'],
                'live' => true,
            ];
        }

        $imageSeed = [];
        $j = 0;
        if ($id) {
            try {
                foreach (DB::table('gates_product_images')->where('product_id', $id)
                            ->orderBy('sort_order')->orderBy('id')->get() as $im) {
                    $imageSeed[] = ['id' => ++$j, 'iid' => (int) $im->id,
                                    'path' => (string) $im->path, 'alt' => (string) ($im->alt ?? '')];
                }
            } catch (\Throwable) {}
        }

        return $this->view->render($res, 'admin/products/form.twig', [
            'page_title' => $id ? 'Edit product — Admin' : 'New product — Admin',
            'admin_page' => 'products',
            'row'        => $row,
            'is_new'     => !$id,
            'categories' => self::CATEGORIES,
            'tags'       => self::TAGS,
            'axes'       => self::AXES,
            'regions'    => self::REGIONS,
            'sel_regions'=> !empty($row['delivery_regions']) ? (json_decode((string)$row['delivery_regions'], true) ?: []) : [],
            'variant_seed' => $variantSeed,
            'image_seed'   => $imageSeed,
            // Hidden rather than shown-and-dropped when the migration has not run: an editor
            // that silently discards an organiser's work is worse than one missing a section.
            'shop_missing' => \AfricaGates\Support\OptionalColumn::missing('gates_products', [
                'subtitle', 'details', 'variant_axis', 'ships_free', 'is_featured',
            ]),
            'variants_ready' => DB::schema()->hasTable('gates_product_variants'),
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id      = (int)($args['id'] ?? 0);
        $b       = (array)$req->getParsedBody();
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $name    = trim((string)($b['name'] ?? ''));

        if ($name === '') {
            $_SESSION['flash_error'] = 'Product name is required.';
            return $res->withHeader('Location', '/admin/products' . ($id ? '/' . $id : '/new'))->withStatus(302);
        }

        $stockRaw = trim((string)($b['stock'] ?? ''));
        $data = [
            'name'        => $name,
            'slug'        => $this->uniqueSlug(trim((string)($b['slug'] ?? '')) ?: $name, $id),
            'category'    => trim((string)($b['category'] ?? '')) ?: 'Apparel',
            'description' => trim((string)($b['description'] ?? '')),
            'price_naira' => max(0, (int)($b['price_naira'] ?? 0)),
            'tag'         => in_array($b['tag'] ?? '', self::TAGS, true) ? $b['tag'] : null,
            'stock'       => $stockRaw === '' ? null : max(0, (int)$stockRaw),
            'is_active'   => isset($b['is_active']) ? 1 : 0,
            'sort_order'  => (int)($b['sort_order'] ?? 0),
            // Restrict where this product can be delivered. NULL = nationwide.
            'delivery_regions' => $this->normRegions((array)($b['delivery_regions'] ?? [])),
            // One line under the name, so a buyer does not have to read a paragraph to find
            // out what the thing is.
            'subtitle'     => mb_substr(trim((string) ($b['subtitle'] ?? '')), 0, 200) ?: null,
            // Materials, sizing, washing — the three questions a support inbox answers about
            // apparel forever.
            'details'      => trim((string) ($b['details'] ?? '')) ?: null,
            'variant_axis' => mb_substr(trim((string) ($b['variant_axis'] ?? '')), 0, 40) ?: null,
            'ships_free'   => !empty($b['ships_free']) ? 1 : 0,
            'is_featured'  => !empty($b['is_featured']) ? 1 : 0,
        ];
        // Dropped rather than written when the migration has not run: an operator uploads the
        // zip and runs /__setup/migrate as two separate acts, and a save that 500ed in between
        // would read as the editor breaking rather than a step being outstanding.
        $data = \AfricaGates\Support\OptionalColumn::filter('gates_products', $data, [
            'subtitle', 'details', 'variant_axis', 'ships_free', 'is_featured',
        ]);

        // Optional cover image — validated + re-encoded; keeps the old one if none sent.
        $cover = $req->getUploadedFiles()['cover'] ?? null;
        if ($cover instanceof UploadedFileInterface && $cover->getError() === UPLOAD_ERR_OK && $cover->getSize() > 0) {
            try {
                $up = $this->uploads->uploadImage($cover, 'products', 1400, 82, $adminId, 'product', $id ?: null, 300);
                $data['cover_path'] = $up['path'];
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Image rejected: ' . $e->getMessage() . ' — other fields were saved.';
            }
        }

        if ($id) {
            DB::table('gates_products')->where('id', $id)->update($data);
            $this->audit->record($adminId, 'product.update', 'product', $id);
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int)DB::table('gates_products')->insertGetId($data);
            $this->audit->record($adminId, 'product.create', 'product', $id);
        }

        $this->saveVariants($id, $b);
        // Existing rows first, so the renumbering is settled before new uploads are appended.
        $this->saveImages($id, $b);
        $this->saveGalleryFiles($req, $id, $adminId);

        if (empty($_SESSION['flash_error'])) $_SESSION['flash_ok'] = 'Product saved.';
        return $res->withHeader('Location', '/admin/products')->withStatus(302);
    }

    /**
     * Write the variant rows the form submitted.
     *
     * ── A VARIANT WITH SALES AGAINST IT IS DEACTIVATED, NOT DELETED ──────────
     *
     * An order line points at its variant id. Hard-deleting the row would leave a paid
     * customer attached to nothing — the packing list would show a shirt with no size, and
     * every "how many did we sell in large" answer would change retroactively.
     *
     * A variant nobody has bought IS deleted, because that is a typo being corrected rather
     * than history being rewritten, and a graveyard of inactive mistakes makes the editor
     * unreadable.
     *
     * @param array<string,mixed> $b
     */
    private function saveVariants(int $productId, array $b): void
    {
        // Absent, not empty. A form posted from a deployment whose migration has not run has
        // no variant inputs at all, and treating that as "delete every variant" would wipe the
        // sizes the moment somebody edited a description.
        if (!array_key_exists('var_label', $b)) return;
        if (!DB::schema()->hasTable('gates_product_variants')) return;

        $labels = (array) $b['var_label'];
        $ids    = (array) ($b['var_id'] ?? []);
        $skus   = (array) ($b['var_sku'] ?? []);
        $deltas = (array) ($b['var_delta'] ?? []);
        $stocks = (array) ($b['var_stock'] ?? []);
        $axis   = mb_substr(trim((string) ($b['variant_axis'] ?? '')), 0, 40) ?: null;

        $now = Carbon::now()->toDateTimeString();
        $kept = [];
        $order = 0;

        foreach ($labels as $i => $rawLabel) {
            $label = trim((string) $rawLabel);
            if ($label === '') continue;                 // an empty spare row from the editor

            $rawStock = trim((string) ($stocks[$i] ?? ''));
            $row = [
                'product_id' => $productId,
                'label'      => mb_substr($label, 0, 80),
                'axis'       => $axis,
                'sku'        => mb_substr(trim((string) ($skus[$i] ?? '')), 0, 60) ?: null,
                'price_delta_naira' => (int) ($deltas[$i] ?? 0),
                // '' is UNTRACKED and 0 is SOLD OUT. Two different statements, and an intval()
                // would flatten every uncounted size into one nobody can buy.
                'stock'      => $rawStock === '' ? null : max(0, (int) $rawStock),
                'is_active'  => 1,
                'sort_order' => $order++,
                'updated_at' => $now,
            ];

            $vid = (int) ($ids[$i] ?? 0);
            try {
                if ($vid > 0 && DB::table('gates_product_variants')->where('id', $vid)
                        ->where('product_id', $productId)->exists()) {
                    DB::table('gates_product_variants')->where('id', $vid)->update($row);
                } else {
                    $vid = (int) DB::table('gates_product_variants')
                        ->insertGetId($row + ['created_at' => $now]);
                }
                $kept[] = $vid;
            } catch (\Throwable $e) {
                error_log('[shop] could not save variant "' . $label . '": ' . $e->getMessage());
            }
        }

        try {
            $gone = DB::table('gates_product_variants')->where('product_id', $productId)
                ->whereNotIn('id', $kept ?: [0])->get();
            foreach ($gone as $v) {
                $sold = DB::table('gates_orders')
                    ->where('items_json', 'like', '%"variant_id":' . (int) $v->id . '%')
                    ->whereIn('status', ['paid', 'pending'])->count();
                if ($sold > 0) {
                    DB::table('gates_product_variants')->where('id', (int) $v->id)
                        ->update(['is_active' => 0, 'updated_at' => $now]);
                } else {
                    DB::table('gates_product_variants')->where('id', (int) $v->id)->delete();
                }
            }
        } catch (\Throwable $e) {
            error_log('[shop] could not tidy removed variants: ' . $e->getMessage());
        }
    }

    /**
     * The gallery: existing rows re-ordered or removed, plus any newly uploaded files.
     *
     * Paths only — the FILES go through {@see UploadService} exactly as the cover does, so a
     * gallery image gets the same validation, re-encoding and minimum-size check. An editor
     * that accepted an arbitrary path here would be an open redirect for image tags.
     *
     * @param array<string,mixed> $b
     */
    private function saveImages(int $productId, array $b): void
    {
        if (!DB::schema()->hasTable('gates_product_images')) return;

        $now = Carbon::now()->toDateTimeString();
        $kept = [];
        $order = 0;

        // Rows the editor still lists. `img_path` is echoed back from what we stored, and
        // anything that is not a path we already have is ignored rather than trusted.
        $ids   = (array) ($b['img_id'] ?? []);
        $alts  = (array) ($b['img_alt'] ?? []);
        foreach ($ids as $i => $rawId) {
            $iid = (int) $rawId;
            if ($iid <= 0) continue;
            try {
                $ok = DB::table('gates_product_images')->where('id', $iid)
                    ->where('product_id', $productId)
                    ->update(['alt' => mb_substr(trim((string) ($alts[$i] ?? '')), 0, 300) ?: null,
                              'sort_order' => $order++]);
                if ($ok !== false) $kept[] = $iid;
            } catch (\Throwable) {}
        }

        // Anything the editor dropped. Deleted outright: an image is not a record of a sale,
        // and keeping a hidden one would mean the editor shows a row somebody removed.
        if (array_key_exists('img_id', $b)) {
            try {
                DB::table('gates_product_images')->where('product_id', $productId)
                    ->whereNotIn('id', $kept ?: [0])->delete();
            } catch (\Throwable) {}
        }
    }

    /**
     * Newly uploaded gallery files. Separate from {@see saveImages()} because the request is
     * needed and the ordering must come after the existing rows have been renumbered.
     */
    private function saveGalleryFiles(Request $req, int $productId, int $adminId): void
    {
        if (!DB::schema()->hasTable('gates_product_images')) return;

        $files = $req->getUploadedFiles()['gallery'] ?? [];
        if (!is_array($files)) $files = [$files];
        if ($files === []) return;

        $now = Carbon::now()->toDateTimeString();
        try {
            $next = (int) DB::table('gates_product_images')->where('product_id', $productId)
                ->max('sort_order');
        } catch (\Throwable) { $next = 0; }

        foreach ($files as $f) {
            if (!$f instanceof UploadedFileInterface) continue;
            if ($f->getError() !== UPLOAD_ERR_OK || $f->getSize() < 1) continue;
            try {
                // The SAME validation the cover gets. A gallery image nobody checked is a
                // gallery image somebody uploaded a 12MB TIFF into.
                $up = $this->uploads->uploadImage($f, 'products', 1400, 82, $adminId,
                                                  'product', $productId ?: null, 300);
                DB::table('gates_product_images')->insert([
                    'product_id' => $productId, 'path' => $up['path'],
                    'sort_order' => ++$next, 'created_at' => $now,
                ]);
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'One gallery image was rejected: ' . $e->getMessage();
            }
        }
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id  = (int)($args['id'] ?? 0);
        $row = $id ? DB::table('gates_products')->where('id', $id)->first() : null;
        if ($row) {
            DB::table('gates_products')->where('id', $id)->delete();
            $this->audit->record((int)($_SESSION['admin_id'] ?? 0), 'product.delete', 'product', $id);
            $_SESSION['flash_ok'] = 'Product deleted.';
        }
        return $res->withHeader('Location', '/admin/products')->withStatus(302);
    }

    /** Slugify + guarantee uniqueness (excluding the row being edited). */
    private function uniqueSlug(string $base, int $excludeId): string
    {
        // Slug::make, which FOLDS accents rather than deleting them. A product called
        // "Àdìrẹ Tote" slugged to "d-r-tote" here, same defect as the nominee URLs.
        $base = \AfricaGates\Support\Slug::make($base) ?: 'product';
        $slug = $base; $i = 1;
        while (DB::table('gates_products')->where('slug', $slug)->where('id', '!=', $excludeId)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * Whitelist + JSON-encode the selected delivery regions. Returns NULL (=
     * nationwide) when nothing is selected OR every region is selected — so the
     * stored value only ever represents a genuine restriction.
     */
    private function normRegions(array $selected): ?string
    {
        $regions = array_values(array_intersect(self::REGIONS, $selected));
        if (count($regions) === 0 || count($regions) === count(self::REGIONS)) {
            return null;
        }
        return json_encode($regions);
    }
}
