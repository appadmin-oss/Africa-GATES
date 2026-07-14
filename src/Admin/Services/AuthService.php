<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\RateLimitService;
use AfricaGates\Support\Session;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

class AuthService
{
    public function __construct(
        private readonly LogService   $log,
        private readonly AuditService $audit,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    /** Find an active admin by email. */
    public function findByEmail(string $email): ?object
    {
        $email = strtolower(trim($email));
        $row = DB::table('gates_admins')->where('email', $email)->where('is_active', 1)->first();
        return $row ?: null;
    }

    public function findById(int $id): ?object
    {
        $row = DB::table('gates_admins')->where('id', $id)->first();
        return $row ?: null;
    }

    /**
     * Verify password and rate-limit failed attempts.
     * Returns the admin record on success, null on failure.
     */
    public function attemptLogin(string $email, string $password, string $ip): ?object
    {
        // Per-IP throttle (10/hr) — blunts credential-stuffing across accounts.
        if ($this->rateLimit && $ip !== ''
            && !$this->rateLimit->check(hash('sha256', $ip), 'admin_login_ip', 10, 3600)) {
            $this->log->warn('admin.login.ip_throttled', ['ip_hash' => hash('sha256', $ip)]);
            return null;
        }

        $admin = $this->findByEmail($email);
        if (!$admin) {
            // Equalize timing: run a dummy verify so a non-existent account is
            // not distinguishable from a wrong password by response time.
            password_verify($password, '$2y$10$' . str_repeat('.', 53));
            $this->log->info('admin.login.unknown', ['email' => $email, 'ip' => $ip]);
            return null;
        }

        // Lockout window
        if (!empty($admin->locked_until) && strtotime((string)$admin->locked_until) > time()) {
            $this->log->warn('admin.login.locked', ['email' => $email, 'until' => $admin->locked_until]);
            return null;
        }

        if (empty($admin->password_hash) || !password_verify($password, (string)$admin->password_hash)) {
            $attempts = (int)$admin->failed_attempts + 1;
            $update = ['failed_attempts' => $attempts];
            if ($attempts >= 5) {
                $update['locked_until'] = Carbon::now()->addMinutes(15)->toDateTimeString();
                $update['failed_attempts'] = 0;
                // Distinct high-signal security event for log-based alerting (see
                // docs/SECURITY-HARDENING-V3.md — alert on `admin.login.lockout`).
                $this->log->warn('admin.login.lockout', ['email' => $email, 'ip' => $ip]);
            }
            DB::table('gates_admins')->where('id', $admin->id)->update($update);
            $this->log->info('admin.login.fail', ['email' => $email, 'attempts' => $attempts]);
            return null;
        }

        DB::table('gates_admins')->where('id', $admin->id)->update([
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => Carbon::now()->toDateTimeString(),
            'last_login_ip'   => $ip ? hash('sha256', $ip) : null,
        ]);
        $this->audit->record($admin->id, 'login', 'admin', $admin->id, ['method' => 'password']);
        return $admin;
    }

    /** Begin a session for a given admin (must be called inside a started PHP session). */
    public function startSession(object $admin): void
    {
        // Defeat session fixation: rotate the id before writing identity.
        Session::rotate();
        $_SESSION['admin_id']    = (int)$admin->id;
        $_SESSION['admin_name']  = $admin->name;
        $_SESSION['admin_role']  = $admin->role;
        $_SESSION['admin_email'] = $admin->email;
        // Cycle the CSRF token on login to prevent fixation
        $_SESSION['csrf_token']  = bin2hex(random_bytes(32));
    }

    public function logout(): void
    {
        if (isset($_SESSION['admin_id'])) {
            $this->audit->record((int)$_SESSION['admin_id'], 'logout', 'admin', (int)$_SESSION['admin_id']);
        }
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['admin_email']);
    }

    public function currentAdmin(): ?object
    {
        $id = (int)($_SESSION['admin_id'] ?? 0);
        return $id ? $this->findById($id) : null;
    }

    public function hasRole(string ...$roles): bool
    {
        $role = $_SESSION['admin_role'] ?? '';
        return in_array($role, $roles, true);
    }

    /**
     * Create a magic-link token. Returns [rawToken, expiresAt] — the email layer
     * sends the raw token; the DB stores only its hash.
     */
    public function createMagicLink(string $email, string $purpose = 'admin_login', string $ip = ''): array
    {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expiresAt = Carbon::now()->addMinutes(15)->toDateTimeString();
        DB::table('gates_magic_links')->insert([
            'email'      => strtolower(trim($email)),
            'token_hash' => $hash,
            'purpose'    => $purpose,
            'expires_at' => $expiresAt,
            'ip_hash'    => $ip ? hash('sha256', $ip) : null,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->log->info('admin.magic.created', ['email' => $email, 'purpose' => $purpose]);
        return [$raw, $expiresAt];
    }

    /**
     * Consume a magic-link token. Returns the matching admin object or null.
     */
    public function consumeMagicLink(string $token, string $purpose = 'admin_login'): ?object
    {
        $hash = hash('sha256', $token);
        $row = DB::table('gates_magic_links')
            ->where('token_hash', $hash)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now()->toDateTimeString())
            ->first();
        if (!$row) return null;
        DB::table('gates_magic_links')->where('id', $row->id)->update(['used_at' => Carbon::now()->toDateTimeString()]);
        $admin = $this->findByEmail($row->email);
        if ($admin) {
            $this->audit->record($admin->id, 'login', 'admin', $admin->id, ['method' => 'magic_link']);
        }
        return $admin;
    }
}
