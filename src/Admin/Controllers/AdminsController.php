<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;

class AdminsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
            $_SESSION['flash_error'] = 'Only superadmins can manage admin accounts.';
            return $res->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $rows = DB::table('gates_admins')->orderByDesc('id')->get()->map(fn($r)=>(array)$r)->all();
        return $this->view->render($res, 'admin/admins/index.twig', [
            'page_title' => 'Admin Accounts — Admin',
            'admin_page' => 'admins',
            'rows'       => $rows,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
            $_SESSION['flash_error'] = 'Only superadmins can manage admin accounts.';
            return $res->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_admins')->where('id', $id)->first() : [];
        return $this->view->render($res, 'admin/admins/form.twig', [
            'page_title' => $id ? 'Edit Admin — Admin' : 'New Admin — Admin',
            'admin_page' => 'admins',
            'row'        => $row,
            'is_new'     => !$id,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
            $_SESSION['flash_error'] = 'Only superadmins can manage admin accounts.';
            return $res->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $name  = trim((string)($b['name'] ?? ''));
        $role  = (string)($b['role'] ?? 'editor');
        $active = isset($b['is_active']) ? 1 : 0;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            $_SESSION['flash_error'] = 'Email and name are required.';
            return $res->withHeader('Location', '/admin/admins' . ($id ? "/$id" : '/new'))->withStatus(302);
        }
        $data = ['email' => $email, 'name' => $name, 'role' => $role, 'is_active' => $active, 'updated_at' => Carbon::now()->toDateTimeString()];
        if (!empty($b['password'])) {
            $data['password_hash'] = password_hash((string)$b['password'], PASSWORD_BCRYPT);
        }
        if ($id) {
            DB::table('gates_admins')->where('id', $id)->update($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'admin.update', 'admin', $id);
        } else {
            if (empty($data['password_hash'])) {
                // Generate a random initial password — the admin should use magic-link to set their own.
                $data['password_hash'] = password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT);
            }
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int)DB::table('gates_admins')->insertGetId($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'admin.create', 'admin', $id);
        }
        $_SESSION['flash_ok'] = 'Admin saved.';
        return $res->withHeader('Location', '/admin/admins')->withStatus(302);
    }

    public function toggle(Request $req, Response $res, array $args): Response
    {
        if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
            $_SESSION['flash_error'] = 'Forbidden.';
            return $res->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $id = (int)$args['id'];
        $row = DB::table('gates_admins')->where('id', $id)->first();
        if (!$row) throw new \Slim\Exception\HttpNotFoundException($req);
        DB::table('gates_admins')->where('id', $id)->update(['is_active' => $row->is_active ? 0 : 1]);
        $this->audit->record((int)$_SESSION['admin_id'], 'admin.toggle_active', 'admin', $id);
        $_SESSION['flash_ok'] = 'Admin status toggled.';
        return $res->withHeader('Location', '/admin/admins')->withStatus(302);
    }
}
