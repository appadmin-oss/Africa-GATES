<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Exception\HttpNotFoundException;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Admin\Support\DataRegistry;
use AfricaGates\Support\Filters;
use AfricaGates\Support\Paginator;

/**
 * Generic admin data explorer (read-only "data" section). One controller serves
 * every dataset in DataRegistry: a hub with live counts, a paginated/searchable/
 * sortable table per dataset, a full-detail page per record, and CSV export.
 */
class DataController
{
    private const PER = 50;
    private const EXPORT_CAP = 10000;

    public function __construct(private readonly Twig $view) {}

    /** Hub: every available dataset with its row count, grouped. */
    public function index(Request $req, Response $res): Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $cards = [];
        foreach (DataRegistry::availableForRole($role) as $key => $d) {
            $count = 0;
            try { $count = (int) DB::table($d['table'])->count(); } catch (\Throwable $e) {}
            $cards[] = ['key' => $key, 'label' => $d['label'], 'group' => $d['group'], 'count' => $count];
        }
        return $this->view->render($res, 'admin/data/index.twig', [
            'page_title' => 'Data — Admin',
            'admin_page' => 'data',
            'cards'      => $cards,
        ]);
    }

    /** Paginated, searchable, sortable table for one dataset. */
    public function browse(Request $req, Response $res, array $args): Response
    {
        [$key, $d, $existing] = $this->resolve($req, $args);
        $qp   = $req->getQueryParams();
        $q    = trim((string) ($qp['q'] ?? ''));
        $page = max(1, (int) ($qp['page'] ?? 1));

        // Ordering: a clicked column header (validated against real columns) else the default.
        [$ocol, $odir] = $d['order'];
        $dir = strtolower((string) ($qp['dir'] ?? $odir)) === 'asc' ? 'asc' : 'desc';
        $sort = (string) ($qp['sort'] ?? '');
        if ($sort !== '' && in_array($sort, $existing, true)) {
            $ocol = $sort;
        } elseif (!in_array($ocol, $existing, true)) {
            $ocol = in_array('id', $existing, true) ? 'id' : $existing[0];
        }

        $base = $this->filtered($d, $existing, $q);
        // Date-range filter on the dataset's natural time column (day/week/month
        // presets + custom from/to). No-op for tables without a timestamp column.
        $dateCol  = Filters::dateColumn($existing, $d['order'][0] ?? null);
        $dateMeta = Filters::applyDateRange($base, $dateCol, $qp);

        $base->orderBy($ocol, $dir);
        $pg    = Paginator::paginate($base, $page, self::PER);
        $rows  = $pg['rows']->map(fn($r) => (array) $r)->all();
        $total = $pg['total']; $pages = $pg['pages']; $page = $pg['page'];

        $cols = array_values(array_filter($d['cols'], fn($c) => in_array($c[0], $existing, true)));

        return $this->view->render($res, 'admin/data/list.twig', [
            'page_title' => $d['label'] . ' — Data',
            'admin_page' => 'data',
            'dataset'    => $key,
            'd'          => $d,
            'cols'       => $cols,
            'rows'       => $rows,
            'q'          => $q,
            'page'       => $page,
            'pages'      => $pages,
            'total'      => $total,
            'sort'       => $ocol,
            'dir'        => $dir,
            'has_id'     => in_array('id', $existing, true),
            'has_date'   => $dateCol !== null,
            'range'      => $dateMeta['range'],
            'from'       => $dateMeta['from'],
            'to'         => $dateMeta['to'],
            // Preserved query strings: `qs` keeps every filter incl. sort (for the
            // pager); `qs_base` drops sort/dir (for sort-header + export links).
            'qs'         => Filters::qs(['q' => $q, 'sort' => $ocol, 'dir' => $dir, 'range' => $dateMeta['range'], 'from' => $dateMeta['from'], 'to' => $dateMeta['to']]),
            'qs_base'    => Filters::qs(['q' => $q, 'range' => $dateMeta['range'], 'from' => $dateMeta['from'], 'to' => $dateMeta['to']]),
            'window'     => Filters::pageWindow($page, $pages),
        ]);
    }

    /** Full record — every column except the globally-hidden secrets. */
    public function detail(Request $req, Response $res, array $args): Response
    {
        [$key, $d] = $this->resolve($req, $args);
        $id  = (int) ($args['id'] ?? 0);
        $row = DB::table($d['table'])->where('id', $id)->first();
        if (!$row) throw new HttpNotFoundException($req);

        $fields = [];
        foreach ((array) $row as $col => $val) {
            if (DataRegistry::isHidden($col)) continue;
            $fields[] = ['key' => $col, 'value' => $val];
        }
        return $this->view->render($res, 'admin/data/detail.twig', [
            'page_title' => $d['label'] . ' #' . $id,
            'admin_page' => 'data',
            'dataset'    => $key,
            'd'          => $d,
            'id'         => $id,
            'fields'     => $fields,
        ]);
    }

    /** CSV export of the (filtered) dataset — all non-secret columns, capped. */
    public function export(Request $req, Response $res, array $args): Response
    {
        [, $d, $existing] = $this->resolve($req, $args);
        $qp = $req->getQueryParams();
        $exportCols = array_values(array_filter($existing, fn($c) => !DataRegistry::isHidden($c)));
        $q = trim((string) ($qp['q'] ?? ''));

        [$ocol, $odir] = $d['order'];
        if (!in_array($ocol, $existing, true)) $ocol = in_array('id', $existing, true) ? 'id' : $existing[0];
        // Export honours the SAME search + date-range filter as the on-screen list.
        $base = $this->filtered($d, $existing, $q);
        Filters::applyDateRange($base, Filters::dateColumn($existing, $d['order'][0] ?? null), $qp);
        $rows = $base->orderBy($ocol, $odir)->limit(self::EXPORT_CAP)->get();

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $exportCols, ',', '"', '\\');
        foreach ($rows as $r) {
            $line = [];
            foreach ($exportCols as $c) $line[] = (string) ($r->$c ?? '');
            fputcsv($fh, $line, ',', '"', '\\');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $res->getBody()->write($csv);
        return $res
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . ($args['dataset'] ?? 'data') . '.csv"');
    }

    /** Resolve + validate the dataset, returning [key, definition, existingColumns]. */
    private function resolve(Request $req, array $args): array
    {
        $key = (string) ($args['dataset'] ?? '');
        $d = DataRegistry::get($key);
        if (!$d || !DB::schema()->hasTable($d['table'])) throw new HttpNotFoundException($req);
        // Per-dataset role gate (the section is open to all roles; datasets are not).
        if (!DataRegistry::canRole($key, (string) ($_SESSION['admin_role'] ?? ''))) throw new HttpNotFoundException($req);
        return [$key, $d, DB::schema()->getColumnListing($d['table'])];
    }

    /** Base query with the search filter applied across the dataset's real search columns. */
    private function filtered(array $d, array $existing, string $q)
    {
        $base = DB::table($d['table']);
        $search = array_values(array_filter($d['search'] ?? [], fn($s) => in_array($s, $existing, true)));
        if ($q !== '' && $search) {
            $base->where(function ($w) use ($search, $q) {
                foreach ($search as $i => $s) {
                    $i === 0 ? $w->where($s, 'like', "%{$q}%") : $w->orWhere($s, 'like', "%{$q}%");
                }
            });
        }
        return $base;
    }
}
