<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\Permissions;

/**
 * Manage admin accounts. Every route here is superadmin-gated by the
 * RoleMiddleware('superadmin') group in routes.php, so no per-action role
 * check is needed (or duplicated) inside the controller.
 */
class AdminsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_admins')->orderByDesc('id')->get()->map(fn($r)=>(array)$r)->all();
        return $this->view->render($res, 'admin/admins/index.twig', [
            'page_title' => 'Admin Accounts — Admin',
            'admin_page' => 'admins',
            'rows'       => $rows,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_admins')->where('id', $id)->first() : [];
        return $this->view->render($res, 'admin/admins/form.twig', [
            'page_title' => $id ? 'Edit Admin — Admin' : 'New Admin — Admin',
            'admin_page' => 'admins',
            'row'        => $row,
            'is_new'     => !$id,
            'roles'      => \AfricaGates\Admin\Support\Permissions::ROLES,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $name  = trim((string)($b['name'] ?? ''));
        $active = isset($b['is_active']) ? 1 : 0;
        $submitted = (string)($b['role'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            $_SESSION['flash_error'] = 'Email and name are required.';
            return $res->withHeader('Location', '/admin/admins' . ($id ? "/$id" : '/new'))->withStatus(302);
        }

        // Resolve the role SAFELY. Accept only one of the five console roles; when
        // editing, never coerce an out-of-set stored role (e.g. a legacy 'judge'
        // row) — keep it unless a valid console role is explicitly chosen. This
        // closes the "no <option> matches → browser submits the first (superadmin)
        // option → silent escalation" hole.
        $stored = $id ? (string)(DB::table('gates_admins')->where('id', $id)->value('role') ?? 'editor') : '';
        // An out-of-set stored role (e.g. a legacy 'judge' row) is never reassigned
        // through this form — keep it. Otherwise accept any valid console role.
        $role = ($id && !Permissions::isRole($stored))
            ? $stored
            : (Permissions::isRole($submitted) ? $submitted : ($id ? $stored : 'editor'));

        // Last-superadmin lockout guard — refuse to demote/deactivate the only
        // active superadmin (configuration is hard-gated to superadmin).
        if ($id && $this->wouldOrphanSuperadmins($id, $role, $active)) {
            $_SESSION['flash_error'] = 'You cannot remove the last active superadmin.';
            return $res->withHeader('Location', "/admin/admins/$id")->withStatus(302);
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
        $id = (int)$args['id'];
        $row = DB::table('gates_admins')->where('id', $id)->first();
        if (!$row) throw new \Slim\Exception\HttpNotFoundException($req);
        $newActive = $row->is_active ? 0 : 1;
        if ($newActive === 0 && $this->wouldOrphanSuperadmins($id, (string)$row->role, 0)) {
            $_SESSION['flash_error'] = 'You cannot disable the last active superadmin.';
            return $res->withHeader('Location', '/admin/admins')->withStatus(302);
        }
        DB::table('gates_admins')->where('id', $id)->update(['is_active' => $newActive]);
        $this->audit->record((int)$_SESSION['admin_id'], 'admin.toggle_active', 'admin', $id);
        $_SESSION['flash_ok'] = 'Admin status toggled.';
        return $res->withHeader('Location', '/admin/admins')->withStatus(302);
    }

    /** True when applying $newRole/$active to admin #$id would leave zero active superadmins. */
    private function wouldOrphanSuperadmins(int $id, string $newRole, int $active): bool
    {
        $cur = DB::table('gates_admins')->where('id', $id)->first();
        if (!$cur || $cur->role !== 'superadmin' || !$cur->is_active) return false; // not an active superadmin today
        if ($newRole === 'superadmin' && $active === 1) return false;               // stays an active superadmin
        $others = (int)DB::table('gates_admins')
            ->where('role', 'superadmin')->where('is_active', 1)->where('id', '!=', $id)->count();
        return $others === 0;
    }
}
