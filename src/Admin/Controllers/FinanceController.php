<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\FinanceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Finance — what the platform has been paid, grouped by where it came from.
 *
 * ── ACCESS ───────────────────────────────────────────────────────────────────
 *
 * Superadmin and admin only. Deliberately NARROWER than the data explorer, which
 * lets `viewer` read `gates_donations` row by row: a per-row view of a donation is
 * an operational lookup, while a page that totals every naira the organisation has
 * ever taken and names its largest donors is a different kind of disclosure. An
 * editor or moderator has no reason to see either.
 *
 * ── ONE PAGE, FIVE TABS ──────────────────────────────────────────────────────
 *
 * Every figure comes from {@see FinanceService}, which reads the three unrelated
 * tables money actually lands in. The controller's whole job is the date window and
 * the tab split; no arithmetic happens here, so the numbers can be unit-tested
 * without booting a request.
 */
class FinanceController
{
    /** The window presets, in days. `0` means "everything". */
    private const RANGES = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months', '0' => 'All time'];

    public function __construct(private readonly Twig $view) {}

    /** Money is admin+. See the class note on why this is tighter than /admin/data. */
    private function blocked(Response $res): ?Response
    {
        if (in_array((string) ($_SESSION['admin_role'] ?? ''), ['superadmin', 'admin'], true)) return null;
        $_SESSION['flash_error'] = 'You don’t have access to finance.';
        return $res->withHeader('Location', '/admin/dashboard')->withStatus(302);
    }

    public function index(Request $req, Response $res): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $qp    = (array) $req->getQueryParams();
        $range = (string) ($qp['range'] ?? '30');
        if (!isset(self::RANGES[$range])) $range = '30';

        // An explicit from/to beats a preset — a reconciliation is almost always
        // against a specific statement period, not against "the last 30 days".
        $from = $this->date((string) ($qp['from'] ?? ''));
        $to   = $this->date((string) ($qp['to'] ?? ''));
        $custom = $from !== null || $to !== null;
        if (!$custom && $range !== '0') {
            $from = date('Y-m-d', strtotime('-' . ((int) $range - 1) . ' days'));
            $to   = date('Y-m-d');
        }

        $totals = FinanceService::totals($from, $to);
        $by     = FinanceService::bySource($from, $to);

        // Shares are computed here rather than in the template so a zero total cannot
        // divide by zero in Twig — where the failure is a 500 on the finance page.
        $rows = [];
        foreach ($by as $key => $v) {
            if ($v['count'] === 0 && $v['gross'] === 0) continue;
            $rows[] = [
                'key'   => $key,
                'label' => FinanceService::LABELS[$key] ?? $key,
                'gross' => $v['gross'],
                'count' => $v['count'],
                'pct'   => $totals['confirmed'] > 0 ? (int) round($v['gross'] * 100 / $totals['confirmed']) : 0,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['gross'] <=> $a['gross']);

        $daily = FinanceService::daily($range === '0' ? 90 : max(7, (int) $range));

        return $this->view->render($res, 'admin/finance.twig', [
            'page_title'  => 'Finance — Admin',
            'admin_page'  => 'finance',
            'ranges'      => self::RANGES,
            'range'       => $custom ? '' : $range,
            'from'        => $from,
            'to'          => $to,
            'totals'      => $totals,
            'sources'     => $rows,
            'daily'       => $daily,
            'peak'        => max(1, max(array_column($daily, 'naira') ?: [1])),
            'recent'      => FinanceService::recent(40),
            'uncredited'  => FinanceService::uncredited(60, 50),
            'owed'        => FinanceService::owedRefunds(25),
            'nominees'    => FinanceService::paidVotesByNominee(20),
            'providers'   => FinanceService::byProvider()['orders'],
        ]);
    }

    /**
     * The transaction list as CSV — the format a reconciliation actually happens in.
     *
     * A finance page that cannot be exported is a page someone re-types into a
     * spreadsheet, which is both slow and a source of transcription errors in exactly
     * the numbers that must not have any.
     */
    public function export(Request $req, Response $res): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $rows = FinanceService::recent(2000);

        // The separator/enclosure/escape triple is passed explicitly, matching the other
        // exporters: PHP 8.4 deprecates relying on the default $escape, and the default
        // is changing — a CSV whose quoting silently shifts under a PHP upgrade is a
        // reconciliation file that stops parsing.
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Source', 'Name', 'Email', 'Amount (NGN)', 'Status', 'Reference'], ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['when'],
                FinanceService::LABELS[$r['source']] ?? $r['source'],
                $r['who'],
                $r['email'],
                (string) $r['naira'],
                $r['status'],
                $r['ref'],
            ], ',', '"', '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $res->getBody()->write($csv);
        return $res
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="finance-' . date('Y-m-d') . '.csv"');
    }

    /** A `Y-m-d` from user input, or null. Anything else is discarded rather than guessed at. */
    private function date(string $v): ?string
    {
        $v = trim($v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
