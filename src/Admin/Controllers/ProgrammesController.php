<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;

class ProgrammesController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_award_programmes')->orderBy('sort_order')->get();
        // Attach current cycle
        $progs = $rows->map(function ($p) {
            $cycle = DB::table('gates_award_cycles')->where('programme_id', $p->id)->orderByDesc('year')->first();
            $p->cycle = $cycle ? (array)$cycle : null;
            $p->cycles_count = (int)DB::table('gates_award_cycles')->where('programme_id', $p->id)->count();
            return (array)$p;
        })->all();
        return $this->view->render($res, 'admin/programmes/index.twig', [
            'page_title' => 'Award Programmes — Admin',
            'admin_page' => 'programmes',
            'rows'       => $progs,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_award_programmes')->where('id', $id)->first() : [];
        return $this->view->render($res, 'admin/programmes/form.twig', [
            'page_title' => $id ? 'Edit Programme — Admin' : 'New Programme — Admin',
            'admin_page' => 'programmes',
            'row'        => $row,
            'is_new'     => !$id,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $data = [
            'slug'        => preg_replace('/[^a-z0-9-]+/i','-', strtolower((string)($b['slug'] ?? ''))),
            'title'       => trim((string)($b['title'] ?? '')),
            'subtitle'    => trim((string)($b['subtitle'] ?? '')),
            'description' => trim((string)($b['description'] ?? '')),
            'scope'       => (string)($b['scope'] ?? 'continental'),
            'icon_emoji'  => (string)($b['icon_emoji'] ?? '🏆'),
            'sort_order'  => (int)($b['sort_order'] ?? 0),
            'is_active'   => isset($b['is_active']) ? 1 : 0,
        ];
        if ($id) {
            DB::table('gates_award_programmes')->where('id', $id)->update($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'programme.update', 'programme', $id);
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int)DB::table('gates_award_programmes')->insertGetId($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'programme.create', 'programme', $id);
        }
        $_SESSION['flash_ok'] = 'Programme saved.';
        return $res->withHeader('Location', '/admin/programmes')->withStatus(302);
    }

    public function cycleEdit(Request $req, Response $res, array $args): Response
    {
        $programmeId = (int)$args['id'];
        $programme = DB::table('gates_award_programmes')->where('id', $programmeId)->first();
        if (!$programme) throw new \Slim\Exception\HttpNotFoundException($req);
        $cycle = DB::table('gates_award_cycles')->where('programme_id', $programmeId)->orderByDesc('year')->first();
        $categories = $cycle ? DB::table('gates_award_categories')->where('cycle_id', $cycle->id)->orderBy('sort_order')->get()->map(fn($r)=>(array)$r)->all() : [];

        return $this->view->render($res, 'admin/programmes/cycle.twig', [
            'page_title' => $programme->title . ' — Cycle',
            'admin_page' => 'programmes',
            'programme'  => (array)$programme,
            'cycle'      => $cycle ? (array)$cycle : null,
            'categories' => $categories,
        ]);
    }

    public function cycleSave(Request $req, Response $res, array $args): Response
    {
        $programmeId = (int)$args['id'];
        $b = (array)$req->getParsedBody();
        $year = (int)($b['year'] ?? date('Y'));
        $cycle = DB::table('gates_award_cycles')->where('programme_id', $programmeId)->where('year', $year)->first();
        $data = [
            'programme_id'      => $programmeId,
            'year'              => $year,
            'edition_label'     => trim((string)($b['edition_label'] ?? '')),
            'status'            => (string)($b['status'] ?? 'upcoming'),
            'nominations_open'  => $b['nominations_open']  ?: null,
            'nominations_close' => $b['nominations_close'] ?: null,
            'voting_open'       => $b['voting_open']       ?: null,
            'voting_close'      => $b['voting_close']      ?: null,
            'results_date'      => $b['results_date']      ?: null,
        ];
        if ($cycle) {
            DB::table('gates_award_cycles')->where('id', $cycle->id)->update($data);
            $cid = (int)$cycle->id;
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $cid = (int)DB::table('gates_award_cycles')->insertGetId($data);
        }
        $this->audit->record((int)$_SESSION['admin_id'], 'cycle.save', 'cycle', $cid);
        $_SESSION['flash_ok'] = 'Cycle saved.';
        return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
    }

    public function categorySave(Request $req, Response $res, array $args): Response
    {
        $programmeId = (int)$args['id'];
        $b = (array)$req->getParsedBody();
        $cycle = DB::table('gates_award_cycles')->where('programme_id', $programmeId)->orderByDesc('year')->first();
        if (!$cycle) {
            $_SESSION['flash_error'] = 'Create the cycle first.';
            return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
        }
        $catId = (int)($b['id'] ?? 0);
        $data = [
            'cycle_id'    => (int)$cycle->id,
            'slug'        => preg_replace('/[^a-z0-9-]+/i','-', strtolower((string)($b['slug'] ?? ''))),
            'title'       => trim((string)($b['title'] ?? '')),
            'description' => trim((string)($b['description'] ?? '')),
            'sort_order'  => (int)($b['sort_order'] ?? 0),
        ];
        if ($catId) {
            DB::table('gates_award_categories')->where('id', $catId)->update($data);
        } else {
            $catId = (int)DB::table('gates_award_categories')->insertGetId($data);
        }
        $this->audit->record((int)$_SESSION['admin_id'], 'category.save', 'category', $catId);
        $_SESSION['flash_ok'] = 'Category saved.';
        return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
    }

    public function categoryDelete(Request $req, Response $res, array $args): Response
    {
        $catId = (int)$args['catId'];
        DB::table('gates_award_categories')->where('id', $catId)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'category.delete', 'category', $catId);
        $_SESSION['flash_ok'] = 'Category deleted.';
        return $res->withHeader('Location', '/admin/programmes')->withStatus(302);
    }
}
