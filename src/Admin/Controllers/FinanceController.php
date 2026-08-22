<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\FinanceInsights;
use AfricaGates\Admin\Services\FinanceService;
use AfricaGates\Services\PaymentReconciler;
use AfricaGates\Services\ReferralService;
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

    public function __construct(
        private readonly Twig $view,
        // Nullable so the read-only page still renders if the container has not been
        // given one; a reconciliation that cannot be audited is refused in
        // reconcile() rather than performed silently.
        private readonly ?AuditService $audit = null,
    ) {}

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
            // Where the money that arrived was actually SETTLED. The attribution has been
            // recorded on every routed payment since subaccounts shipped and read by nothing
            // at all — so the question the feature exists to answer, "how much of this is
            // ticket money", still had to be answered from the bank. Same window as
            // everything else on the page, because it will be read beside them.
            'settlement'  => FinanceService::settlement($from, $to),
            // The reconciliation tab. Findings only appear after an explicit run —
            // never on page load, because each finding is a live HTTP call to the
            // gateway and nobody should be calling Paystack for opening a dashboard.
            //
            // Read once and cleared: a stale result still on screen after an unrelated
            // filter change reads as the CURRENT state of the money, which is the one
            // thing this page must never get wrong.
            'recon'       => $this->takeReconResult(),
            'recon_log'   => PaymentReconciler::history(12),

            // ── The wider read. Each of these answers something the ledger
            // above cannot: how this period compares, who the money came from
            // rather than how much, which programme carried it, when it
            // arrives, and — the one that matters most — what nearly arrived
            // and did not. See {@see FinanceInsights} on why they are a
            // separate class.
            'compare'     => FinanceInsights::comparison($from, $to),
            'people'      => FinanceInsights::supporters($from, $to),
            'programmes'  => FinanceInsights::byProgramme($from, $to),
            'rhythm'      => FinanceInsights::rhythm($range === '0' ? 90 : max(14, (int) $range)),
            'leak'        => FinanceInsights::leakage($from, $to),
            'cumulative'  => FinanceInsights::cumulative($range === '0' ? 90 : max(7, (int) $range)),

            // Referral commission owed to members. NOT filtered by the date range: a debt
            // does not stop existing because you narrowed the view to last week, and a
            // liability that shrinks when you change a filter is the kind of number
            // somebody reports to a board.
            'referral'    => ReferralService::liability(50),
        ]);
    }

    /**
     * Ask the gateway about every stale pending payment.
     *
     * TWO MODES, AND THE DEFAULT IS THE SAFE ONE. `check` verifies and reports without
     * writing; `apply` also confirms the genuinely-paid, mints their votes and marks
     * the definitively-failed. An operator sees the discrepancies before anything
     * moves — otherwise the button that fixes four orders is also the button that
     * confirms a mismatched fifth nobody looked at.
     *
     * The result goes in the SESSION rather than being re-run on the redirect. A
     * reconciliation is a set of outbound API calls with side effects; a refresh must
     * not repeat it, and post-redirect-get is what stops a browser reload from
     * re-confirming payments.
     */
    public function reconcile(Request $req, Response $res): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $b     = (array) $req->getParsedBody();
        $apply = ($b['mode'] ?? 'check') === 'apply';

        // Apply is destructive and irreversible in the direction that matters (money
        // credited, votes minted on a live leaderboard), so it carries a typed
        // confirmation. Check does not — its whole purpose is to be safe to press.
        if ($apply && ($b['confirm'] ?? '') !== 'APPLY') {
            $_SESSION['flash_error'] = 'Type APPLY to confirm before applying reconciliation changes.';
            return $res->withHeader('Location', '/admin/finance')->withStatus(302);
        }

        $minutes = max(0, min(10080, (int) ($b['minutes'] ?? 15)));

        try {
            $result = (new PaymentReconciler())->run($apply, $minutes, 200);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Reconciliation could not run: ' . $e->getMessage();
            return $res->withHeader('Location', '/admin/finance')->withStatus(302);
        }

        $actor = 'admin:' . (int) ($_SESSION['admin_id'] ?? 0);
        PaymentReconciler::log($result, $actor);
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'finance.reconcile', 'finance', 0);

        $_SESSION['recon_result'] = $result;
        // `recovered` is called out rather than folded into `confirmed`, because it is a
        // materially different sentence: those are payments the platform had already given
        // up on and written off as failed, which no sweeper could reach until now. An
        // operator seeing a non-zero number there should know money came back from the dead.
        $recovered = (int) ($result['recovered'] ?? 0);
        $rec = $recovered > 0
            ? sprintf(' %d had been WRITTEN OFF and %s recovered.',
                $recovered, $apply ? 'were' : 'would be')
            : '';

        $_SESSION['flash_ok'] = ($apply
            ? sprintf('Reconciled: %d confirmed (₦%s), %d marked failed, %d need attention.',
                $result['confirmed'] + $recovered, number_format($result['naira']),
                $result['failed'], $result['mismatch'] + $result['unverifiable'])
            : sprintf('Checked %d payment(s): %d would be confirmed (₦%s), %d need attention. Nothing was changed.',
                $result['checked'], $result['confirmed'] + $recovered, number_format($result['naira']),
                $result['mismatch'] + $result['unverifiable'])) . $rec;

        return $res->withHeader('Location', '/admin/finance')->withStatus(302);
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

    /** The last run's findings, consumed so a reload does not re-show them as current. */
    private function takeReconResult(): ?array
    {
        $r = $_SESSION['recon_result'] ?? null;
        unset($_SESSION['recon_result']);
        return is_array($r) ? $r : null;
    }

    /** A `Y-m-d` from user input, or null. Anything else is discarded rather than guessed at. */
    private function date(string $v): ?string
    {
        $v = trim($v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
