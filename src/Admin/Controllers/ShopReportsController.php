<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\ShopReports;
use AfricaGates\Support\Csv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The shop's "today" dashboard, its sales reports, and the CSV for accounting (ShopReports).
 */
final class ShopReportsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function dashboard(Request $req, Response $res): Response
    {
        $error = null;
        $t = null;
        try {
            $t = ShopReports::today();
        } catch (\Throwable $e) {
            error_log('[shop] dashboard: ' . $e->getMessage());
            $error = 'The shop\'s figures could not be read just now.';
        }
        return $this->view->render($res, 'admin/shop/dashboard.twig', [
            'page_title' => 'Shop today — Admin',
            'admin_page' => 'shop-today',
            't'          => $t,
            'error'      => $error,
        ]);
    }

    public function reports(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        [$from, $to] = ShopReports::range((string) ($q['from'] ?? ''), (string) ($q['to'] ?? ''));
        $error = null;
        $money = null;
        $products = [];
        try {
            $money = ShopReports::money($from, $to);
            $products = ShopReports::products($from, $to);
        } catch (\Throwable $e) {
            error_log('[shop] reports: ' . $e->getMessage());
            $error = 'The report could not be built just now.';
        }
        return $this->view->render($res, 'admin/shop/reports.twig', [
            'page_title' => 'Shop reports — Admin',
            'admin_page' => 'shop-reports',
            'from'       => $from,
            'to'         => $to,
            'money'      => $money,
            'products'   => $products,
            'error'      => $error,
            'max_days'   => ShopReports::MAX_DAYS,
        ]);
    }

    /** The CSV: every order paid in the range, or the per-product table. Spreadsheet-safe cells. */
    public function csv(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        [$from, $to] = ShopReports::range((string) ($q['from'] ?? ''), (string) ($q['to'] ?? ''));
        $kind = ($q['kind'] ?? '') === 'products' ? 'products' : 'orders';

        $out = fopen('php://temp', 'r+');
        if ($kind === 'orders') {
            Csv::put($out, ShopReports::ORDER_COLUMNS);
            foreach (ShopReports::orderRows($from, $to) as $row) Csv::put($out, $row);
        } else {
            Csv::put($out, ['Product', 'Option', 'Units sold', 'Units returned', 'Takings (NGN)', 'Orders']);
            foreach (ShopReports::products($from, $to) as $r) {
                Csv::put($out, [$r['product'], $r['option'], $r['units'], $r['returned'], $r['takings'], $r['orders']]);
            }
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'shop.report.export', 'order', null,
            ['kind' => $kind, 'from' => $from, 'to' => $to]);
        $res->getBody()->write($csv);
        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="shop-' . $kind . '-' . $from . '-to-' . $to . '.csv"');
    }
}
