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

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id  = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_products')->where('id', $id)->first() : [];
        return $this->view->render($res, 'admin/products/form.twig', [
            'page_title' => $id ? 'Edit product — Admin' : 'New product — Admin',
            'admin_page' => 'products',
            'row'        => $row,
            'is_new'     => !$id,
            'categories' => self::CATEGORIES,
            'tags'       => self::TAGS,
            'regions'    => self::REGIONS,
            'sel_regions'=> !empty($row['delivery_regions']) ? (json_decode((string)$row['delivery_regions'], true) ?: []) : [],
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
        ];

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

        if (empty($_SESSION['flash_error'])) $_SESSION['flash_ok'] = 'Product saved.';
        return $res->withHeader('Location', '/admin/products')->withStatus(302);
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
