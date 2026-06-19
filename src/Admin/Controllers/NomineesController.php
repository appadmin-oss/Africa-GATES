<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Admin\Services\AuditService;

class NomineesController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $cycleId = (int)($p['cycle'] ?? 0);
        $status  = (string)($p['status'] ?? '');
        $q       = (string)($p['q'] ?? '');

        $base = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c','c.id','=','n.category_id')
            ->join('gates_award_cycles as cy','cy.id','=','c.cycle_id')
            ->join('gates_award_programmes as p','p.id','=','cy.programme_id')
            ->leftJoin('gates_profiles as pr','pr.id','=','n.profile_id')
            ->select(['n.id','n.name','n.tagline','n.country_code','n.vote_count','n.status','n.photo_path','n.profile_id','c.title as category','p.title as programme','cy.id as cycle_id','cy.year','pr.slug as profile_slug','pr.display_name as profile_name']);
        if ($cycleId) $base->where('cy.id', $cycleId);
        if ($status)  $base->where('n.status', $status);
        if ($q)       $base->where('n.name','like',"%$q%");
        $rows = $base->orderByDesc('n.vote_count')->limit(200)->get();

        $cycles = DB::table('gates_award_cycles as c')
            ->join('gates_award_programmes as p','p.id','=','c.programme_id')
            ->select(['c.id','c.year','p.title'])->orderByDesc('c.year')->get();

        return $this->view->render($res, 'admin/nominees/index.twig', [
            'page_title' => 'Nominees — Admin',
            'admin_page' => 'nominees',
            'rows'       => $rows->map(fn($r)=>(array)$r)->all(),
            'cycles'     => $cycles->map(fn($r)=>(array)$r)->all(),
            'filters'    => ['cycle' => $cycleId, 'status' => $status, 'q' => $q],
        ]);
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $action = $args['action'];
        $map = ['winner' => 'winner', 'runner_up' => 'runner_up', 'approve' => 'approved', 'remove' => 'pending'];
        if (!isset($map[$action])) throw new \Slim\Exception\HttpNotFoundException($req);
        DB::table('gates_nominees')->where('id', $id)->update(['status' => $map[$action]]);
        $this->audit->record((int)$_SESSION['admin_id'], "nominee.$action", 'nominee', $id);
        $_SESSION['flash_ok'] = 'Nominee updated.';
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Link / unlink a nominee to a registry profile so its votes + judge
     * scores roll up into the profile's CPI. Accepts profile_slug or
     * profile_id; empty value unlinks.
     */
    public function link(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $b  = (array)$req->getParsedBody();
        $slug = trim((string)($b['profile_slug'] ?? ''));
        $pid  = (int)($b['profile_id'] ?? 0);

        $profileId = null;
        if ($slug !== '') {
            $profileId = DB::table('gates_profiles')->where('slug', $slug)->value('id');
            if (!$profileId) {
                $_SESSION['flash_error'] = 'No profile found for slug "' . $slug . '".';
                $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
                return $res->withHeader('Location', $back)->withStatus(302);
            }
        } elseif ($pid > 0) {
            $profileId = DB::table('gates_profiles')->where('id', $pid)->value('id');
        }

        DB::table('gates_nominees')->where('id', $id)->update(['profile_id' => $profileId ?: null]);
        $this->audit->record((int)$_SESSION['admin_id'], $profileId ? 'nominee.link' : 'nominee.unlink', 'nominee', $id, ['profile_id' => $profileId]);
        $_SESSION['flash_ok'] = $profileId ? 'Nominee linked to profile.' : 'Nominee unlinked.';
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        DB::table('gates_nominees')->where('id', $id)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'nominee.delete', 'nominee', $id);
        $_SESSION['flash_ok'] = 'Nominee deleted.';
        return $res->withHeader('Location', '/admin/nominees')->withStatus(302);
    }
}
