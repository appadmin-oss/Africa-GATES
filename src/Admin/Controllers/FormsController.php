<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Exception\HttpNotFoundException;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\FormService;

/** Admin form builder: list, design (builder UI), save, delete, view submissions. */
class FormsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_forms')->orderByDesc('updated_at')->get()->map(function ($r) {
            $a = (array) $r;
            $a['submissions'] = (int) DB::table('gates_form_submissions')->where('form_key', $r->form_key)->count();
            $schema = json_decode((string) $r->schema_json, true);
            $a['field_count'] = is_array($schema['fields'] ?? null) ? count($schema['fields']) : 0;
            return $a;
        })->all();
        return $this->view->render($res, 'admin/forms/index.twig', [
            'page_title' => 'Forms — Admin', 'admin_page' => 'forms', 'rows' => $rows,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id  = (int) ($args['id'] ?? 0);
        $row = $id ? (array) DB::table('gates_forms')->where('id', $id)->first()
                   : ['form_key' => '', 'title' => '', 'description' => '', 'submit_message' => '', 'status' => 'draft', 'schema_json' => '{"fields":[]}'];
        $fields = FormService::normalizeFields(json_decode((string) ($row['schema_json'] ?? '{"fields":[]}'), true)['fields'] ?? []);
        return $this->view->render($res, 'admin/forms/builder.twig', [
            'page_title'  => $id ? 'Edit form — Admin' : 'New form — Admin',
            'admin_page'  => 'forms',
            'row'         => $row,
            'is_new'      => !$id,
            'fields_seed' => $fields,
            'types'       => FormService::TYPES,
            // Flash renders from the Twig globals via the layout — do not shadow it.
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $b = (array) $req->getParsedBody();
        $key = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) ($b['form_key'] ?: $b['title'] ?? ''))), '-');
        $title = trim((string) ($b['title'] ?? ''));
        $back = $id ? "/admin/forms/{$id}" : '/admin/forms/new';
        if ($title === '' || $key === '') {
            $_SESSION['flash_error'] = 'Title and key are required.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        if (DB::table('gates_forms')->where('form_key', $key)->when($id, fn($q) => $q->where('id', '!=', $id))->exists()) {
            $_SESSION['flash_error'] = 'That form key is already in use — choose another.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $fields = json_decode((string) ($b['fields_json'] ?? '[]'), true);
        $fields = FormService::normalizeFields(is_array($fields) ? $fields : []);
        $data = [
            'form_key'       => $key,
            'title'          => mb_substr($title, 0, 200),
            'description'    => trim((string) ($b['description'] ?? '')),
            'submit_message' => trim((string) ($b['submit_message'] ?? '')),
            'status'         => in_array($b['status'] ?? '', ['published', 'draft'], true) ? $b['status'] : 'draft',
            'schema_json'    => json_encode(['fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'     => Carbon::now()->toDateTimeString(),
        ];
        if ($id) {
            DB::table('gates_forms')->where('id', $id)->update($data);
            $this->audit->record((int) $_SESSION['admin_id'], 'form.update', 'form', $id);
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int) DB::table('gates_forms')->insertGetId($data);
            $this->audit->record((int) $_SESSION['admin_id'], 'form.create', 'form', $id);
        }
        $_SESSION['flash_ok'] = 'Form saved.';
        return $res->withHeader('Location', '/admin/forms')->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        DB::table('gates_forms')->where('id', $id)->delete();
        $this->audit->record((int) $_SESSION['admin_id'], 'form.delete', 'form', $id);
        $_SESSION['flash_ok'] = 'Form deleted.';
        return $res->withHeader('Location', '/admin/forms')->withStatus(302);
    }

    public function submissions(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $form = DB::table('gates_forms')->where('id', $id)->first();
        if (!$form) throw new HttpNotFoundException($req);
        $form = (array) $form;
        $per = 50;
        $page = max(1, (int) ($req->getQueryParams()['page'] ?? 1));
        $base = DB::table('gates_form_submissions')->where('form_key', $form['form_key']);
        $total = (int) (clone $base)->count();
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        $rows = (clone $base)->orderByDesc('id')->offset(($page - 1) * $per)->limit($per)
            ->get()->map(function ($r) { $a = (array) $r; $a['data'] = json_decode((string) $r->data_json, true) ?: []; return $a; })->all();
        $fields = FormService::normalizeFields(json_decode((string) $form['schema_json'], true)['fields'] ?? []);
        return $this->view->render($res, 'admin/forms/submissions.twig', [
            'page_title' => $form['title'] . ' — Submissions',
            'admin_page' => 'forms',
            'form'       => $form,
            'fields'     => $fields,
            'rows'       => $rows,
            'page'       => $page, 'pages' => $pages, 'total' => $total,
        ]);
    }
}
