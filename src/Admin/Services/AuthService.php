<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Support\Session;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The console's session, read and ended. There is no console sign-in here any more: the staff
 * console is a role given to an email address and opened from the Africa GATES account that
 * holds it (Account\Roles, AdminAuthMiddleware). What remains is the one reader of the live
 * admin row the gate asks on every request, and the sign-out.
 */
class AuthService
{
    public function __construct(private readonly AuditService $audit) {}

    public function findById(int $id): ?object
    {
        $row = DB::table('gates_admins')->where('id', $id)->first();
        return $row ?: null;
    }

    public function logout(): void
    {
        if (isset($_SESSION['admin_id'])) {
            $this->audit->record((int)$_SESSION['admin_id'], 'logout', 'admin', (int)$_SESSION['admin_id']);
        }
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['admin_email']);
        // Rotate the session id on logout so the pre-logout id can't be reused
        // (kiosk/shared-machine hardening); also cycle the CSRF token.
        Session::rotate();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * The live row behind the signed-in session, or null when there is none.
     *
     * Read on every admin request by {@see \AfricaGates\Admin\Middleware\AdminAuthMiddleware},
     * which is what makes deactivating an account and changing a role take effect on
     * somebody already signed in. Deliberately UNFILTERED — the middleware needs to
     * tell a deactivated account from a deleted one, and `is_active` is its decision
     * to make, not this reader's.
     */
    public function currentAdmin(): ?object
    {
        $id = (int)($_SESSION['admin_id'] ?? 0);
        return $id ? $this->findById($id) : null;
    }

}
