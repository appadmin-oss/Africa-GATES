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
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $stats = [
            'total_profiles'      => (int)DB::table('gates_profiles')->where('status','approved')->count(),
            'pending_profiles'    => (int)DB::table('gates_profiles')->where('status','pending')->count(),
            'total_votes'         => (int)DB::table('gates_votes')->count(),
            'votes_24h'           => (int)DB::table('gates_votes')->where('voted_at','>=', date('Y-m-d H:i:s', strtotime('-1 day')))->count(),
            'pending_nominations' => (int)DB::table('gates_nominations')->where('status','pending')->count(),
            'approved_nominations'=> (int)DB::table('gates_nominations')->where('status','approved')->count(),
            'legacy_events'       => (int)DB::table('gates_legacy_events')->where('is_published',1)->count(),
            'opportunities'       => (int)DB::table('gates_opportunities')->where('status','active')->count(),
            'partner_enquiries'   => (int)DB::table('gates_partner_enquiries')->whereIn('status',['new','in_review'])->count(),
            'judges'              => (int)DB::table('gates_judges')->where('is_active',1)->count(),
            'admins'              => (int)DB::table('gates_admins')->where('is_active',1)->count(),
        ];

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

        return $this->view->render($res, 'admin/dashboard.twig', [
            'page_title'  => 'Dashboard — Africa GATES Admin',
            'admin_page'  => 'dashboard',
            'stats'       => $stats,
            'region_dist' => $regionDist,
            'tier_dist'   => $tierDist,
            'vote_series' => $voteSeries,
            'top_nominees'=> $topNominees,
            'recent_activity' => $this->audit->recent(12),
        ]);
    }
}
