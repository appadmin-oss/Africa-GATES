<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;

class OpportunitiesController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_opportunities')->orderByDesc('id')->get()->map(fn($r)=>(array)$r)->all();
        return $this->view->render($res, 'admin/opportunities/index.twig', [
            'page_title' => 'Opportunities — Admin',
            'admin_page' => 'opportunities',
            'rows'       => $rows,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_opportunities')->where('id', $id)->first() : [];
        return $this->view->render($res, 'admin/opportunities/form.twig', [
            'page_title' => $id ? 'Edit Opportunity — Admin' : 'New Opportunity — Admin',
            'admin_page' => 'opportunities',
            'row'        => $row,
            'is_new'     => !$id,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $data = [
            'slug'             => preg_replace('/[^a-z0-9-]+/i','-', strtolower((string)($b['slug'] ?? ''))),
            'title'            => trim((string)($b['title'] ?? '')),
            'opportunity_type' => (string)($b['opportunity_type'] ?? 'grant'),
            'scope'            => trim((string)($b['scope'] ?? 'Pan-African')),
            'provider'         => trim((string)($b['provider'] ?? '')),
            'description'      => trim((string)($b['description'] ?? '')),
            'eligibility'      => trim((string)($b['eligibility'] ?? '')),
            'value'            => trim((string)($b['value'] ?? '')),
            'deadline'         => $b['deadline'] ?: null,
            'apply_url'        => trim((string)($b['apply_url'] ?? '')),
            'min_cpi_tier'     => $b['min_cpi_tier'] ?: null,
            'status'           => (string)($b['status'] ?? 'active'),
        ];
        if ($id) {
            DB::table('gates_opportunities')->where('id', $id)->update($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'opp.update', 'opportunity', $id);
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int)DB::table('gates_opportunities')->insertGetId($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'opp.create', 'opportunity', $id);
        }
        $_SESSION['flash_ok'] = 'Opportunity saved.';
        return $res->withHeader('Location', '/admin/opportunities')->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        DB::table('gates_opportunities')->where('id', $id)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'opp.delete', 'opportunity', $id);
        $_SESSION['flash_ok'] = 'Opportunity deleted.';
        return $res->withHeader('Location', '/admin/opportunities')->withStatus(302);
    }
}
