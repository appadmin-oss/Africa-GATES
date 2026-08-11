<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Admin\Services\AuditService;

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

        // ── WHAT NEEDS A PERSON, BEFORE ANYTHING THAT MERELY MEASURES ────────
        //
        // The dashboard used to open on eight counts and three charts. Every number was true
        // and not one of them was a job — while a chargeback on a sixteen-hour clock, an
        // interview held weeks ago whose transcript nobody published, and sixty nominees never
        // told their questionnaire exists appeared nowhere on the first screen after login.
        //
        // Filtered by ROLE through the same resolver the section guard uses, so a card can
        // never offer a screen that then bounces the person who clicked it.
        $board = \AfricaGates\Admin\Services\AttentionBoard::forRole((string) ($_SESSION['admin_role'] ?? ''));

        // Region distribution
        $regionDist = DB::table('gates_profiles')->where('status','approved')
            ->selectRaw('region, COUNT(*) as count')->groupBy('region')->get()
            ->map(fn($r) => ['region' => $r->region, 'count' => (int)$r->count])->all();

        // Tier distribution
        $tierDist = DB::table('gates_profiles')->where('status','approved')
            ->selectRaw('cpi_tier, COUNT(*) as count')->groupBy('cpi_tier')->get()
            ->map(fn($r) => ['tier' => $r->cpi_tier, 'count' => (int)$r->count])->all();

        // Votes per day (last 14 days)
        $rows = DB::table('gates_votes')->where('voted_at','>=', date('Y-m-d', strtotime('-13 days')))
            ->selectRaw("substr(voted_at,1,10) as d, COUNT(*) as c")
            ->groupBy('d')->orderBy('d')->get();
        $byDay = [];
        foreach ($rows as $r) { $byDay[$r->d] = (int)$r->c; }
        $voteSeries = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $voteSeries[] = ['date' => $d, 'count' => $byDay[$d] ?? 0];
        }

        // Top nominees right now
        $topNominees = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c','c.id','=','n.category_id')
            ->where('n.status','approved')
            ->orderByDesc('n.vote_count')->limit(6)
            ->select(['n.id','n.name','n.vote_count','n.country_code','c.title as category'])
            ->get()->map(fn($r)=>(array)$r)->all();

        // Integrity briefing — deterministic signals + a templated summary on
        // load (NO AI call here); the "AI briefing" button upgrades it on demand.
        $intSignals = \AfricaGates\Services\IntegrityBriefService::signals();
        $intBrief   = \AfricaGates\Services\IntegrityBriefService::narrative($intSignals, new \AfricaGates\Services\AiService());

        return $this->view->render($res, 'admin/dashboard.twig', [
            'page_title'  => 'Dashboard — Africa GATES Admin',
            'admin_page'  => 'dashboard',
            'board'       => $board,
            'board_total' => \AfricaGates\Admin\Services\AttentionBoard::total($board),
            // The counts that are worth knowing but are not jobs, rendered small and BELOW the
            // work rather than across the top of it.
            'pulse'       => \AfricaGates\Admin\Services\AttentionBoard::pulse(),
            'region_dist' => $regionDist,
            'tier_dist'   => $tierDist,
            'vote_series' => $voteSeries,
            'top_nominees'=> $topNominees,
            'recent_activity' => $this->audit->recent(12),
            'integrity'       => $intSignals,
            'integrity_brief' => $intBrief['text'],
            'ai_enabled'      => \AfricaGates\Services\AiGateway::available('integrity.brief'),
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
