<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\HomeBlocks;

class DashboardController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        /** Only for the stalled-schedule alert; nullable so a mailerless build still renders. */
        private readonly ?\AfricaGates\Services\OtpService $mailer = null,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        // ── A STALLED SCHEDULE, EMAILED ONCE A DAY ───────────────────────────
        //
        // The layout banner covers the admin who is already looking. This covers
        // the days nobody looks, which are the days it matters — reconciliation and
        // automatic refunds live entirely inside the maintenance run, so a stall
        // means supporters who are owed money quietly stop being paid.
        //
        // Sent from a page load rather than from maintenance because a run that has
        // stopped cannot report that it has stopped. `claimAlert()` holds it to one
        // email a day; without that it would send one per click.
        if ($this->mailer !== null && \AfricaGates\Support\CronHealth::claimAlert()) {
            $h = \AfricaGates\Support\CronHealth::status();
            \AfricaGates\Services\Notifier::adminAlert(
                $this->mailer,
                'Scheduled maintenance has stopped',
                ($h['say'] ?? 'Scheduled maintenance is not running.') . "\n\n"
                . "Until it runs again: payments that the browser callback missed are NOT being "
                . "confirmed, and money owed for votes that could not be minted is NOT being "
                . "returned. Neither shows up anywhere else — the site serves normally throughout.\n\n"
                . "Last recorded run: " . ($h['last'] ?? 'never') . "\n"
                . "Fix: re-check the webcron job, or press \"Run maintenance now\" in "
                . "Settings → Automation & cron."
            );
        }

        // ── HOME, ADMINCONSOLE V2 (README §10b, 20 Oct 2026) ─────────────────
        //
        // The brief, the work by area, the running editions and Performance, with This
        // week, System and Recent beside them. Every block is read from a record the
        // platform keeps (HomeBlocks) and filtered by what this role can open, and each
        // has a calm empty state, because an operator must be able to see a zero.
        $role   = (string) ($_SESSION['admin_role'] ?? '');
        $qp     = $req->getQueryParams();
        $rkey   = in_array($qp['range'] ?? '', ['7d', '30d', '90d'], true) ? (string) $qp['range'] : '7d';
        $range  = \AfricaGates\Admin\Support\TimeRange::fromQuery(['range' => $rkey], '7d');
        $metric = isset(HomeBlocks::KPIS[$qp['metric'] ?? '']) ? (string) $qp['metric'] : 'votes';
        $hour   = (int) \AfricaGates\Admin\Support\TimeRange::show(time(), 'G');

        $jobs  = HomeBlocks::attention($role);
        $kpis  = HomeBlocks::kpis($range, $metric);
        $chart = null;
        foreach ($kpis as $k) if ($k['on']) $chart = $k;
        if ($chart === null && $kpis !== []) { $kpis[0]['on'] = true; $chart = $kpis[0]; }
        $pill  = \AfricaGates\Admin\Services\ConsoleAlerts::pill($role);

        return $this->view->render($res, 'admin/home.twig', [
            'page_title' => 'Home — Africa GATES Admin',
            'admin_page' => 'dashboard',
            'greeting'   => $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'),
            'state'      => $pill['label'] ?? null,
            'rkey'       => $rkey,
            'metric'     => $chart['key'] ?? $metric,
            'brief'      => HomeBlocks::brief($role, $jobs),
            'jobs'       => $jobs,
            'areas'      => HomeBlocks::AREAS,
            'editions'   => HomeBlocks::editions($role),
            'can_setup'  => \AfricaGates\Admin\Support\Permissions::canOpen($role, '/admin/programmes/new'),
            'kpis'       => $kpis,
            'chart'      => $chart,
            'week'       => HomeBlocks::week($role),
            'can_cal'    => \AfricaGates\Admin\Support\Permissions::canOpen($role, '/admin/calendar'),
            'system'     => HomeBlocks::system($role),
            'recent'     => HomeBlocks::recent($role, $this->audit),
            'can_audit'  => \AfricaGates\Admin\Support\Permissions::canOpen($role, '/admin/audit'),
        ]);
    }

    /** On-demand AI integrity briefing (JSON) for the dashboard button. */
    public function integrityBrief(Request $req, Response $res): Response
    {
        $r = \AfricaGates\Services\IntegrityBriefService::brief();
        $res->getBody()->write((string) json_encode([
            'ok' => true, 'text' => $r['text'], 'ai' => $r['ai'], 'total' => $r['signals']['total'] ?? 0,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
