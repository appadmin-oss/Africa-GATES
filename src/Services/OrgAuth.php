<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Sign-in for partner organisation staff.
 *
 * ── ITS OWN TABLE AND ITS OWN SESSION KEYS, DELIBERATELY ─────────────────────
 *
 * A donor account and an NGO treasurer are different trust domains. Sharing `gates_users`
 * would mean one credential-stuffing run against the public site reaches the screen that
 * moves a charity's money, and sharing the `user_id` session key would mean any bug that
 * sets it grants a dashboard. So: separate table, separate keys, and every read on the
 * dashboard scoped by the org id held in the session rather than by anything in the request.
 *
 * ── AND THE SCOPE IS TAKEN FROM THE SESSION, NEVER THE URL ───────────────────
 *
 * There is no `/org/{id}/dashboard`. The organisation is whichever one the signed-in user
 * belongs to, because an id in a path is an invitation to change it — the single most
 * common way multi-tenant dashboards leak one tenant's figures to another.
 */
final class OrgAuth
{
    private const SESSION_USER = 'org_user_id';
    private const SESSION_ORG  = 'org_id';
    private const MAX_FAILS    = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(private readonly ?RateLimitService $rateLimit = null) {}

    /**
     * Verify a password, throttling by IP and locking the account after repeated failures.
     *
     * Returns null for every kind of failure. The caller must not be able to tell an unknown
     * address from a wrong password from a locked account, because that difference is an
     * account-enumeration oracle.
     */
    public function attempt(string $email, string $password, string $ip): ?object
    {
        $email = strtolower(trim($email));

        // Per-IP first, so a stuffing run is stopped before it can lock out real people's
        // accounts one after another — a lockout is also a denial-of-service if an attacker
        // can trigger it at will.
        if ($this->rateLimit && $ip !== ''
            && !$this->rateLimit->check(hash('sha256', $ip), 'org_login_ip', 10, 3600)) {
            return null;
        }

        $user = $this->findByEmail($email);
        if (!$user) {
            // Equalise timing so a non-existent account is not distinguishable by response time.
            password_verify($password, '$2y$10$' . str_repeat('.', 53));
            return null;
        }

        if (!empty($user->locked_until) && strtotime((string) $user->locked_until) > time()) return null;
        if ((int) ($user->is_active ?? 1) !== 1) return null;

        if (empty($user->password_hash) || !password_verify($password, (string) $user->password_hash)) {
            $fails  = (int) ($user->failed_logins ?? 0) + 1;
            $update = ['failed_logins' => $fails];
            if ($fails >= self::MAX_FAILS) {
                $update['locked_until']  = date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60);
                $update['failed_logins'] = 0;
            }
            DB::table('gates_org_users')->where('id', $user->id)->update($update);
            return null;
        }

        // ── WHICH ORGANISATIONS MAY SIGN IN, AND WHY THAT IS NEARLY ALL OF THEM ──
        //
        // Signing in is not permission to collect money — PartnerOrg::canReceive() decides
        // that, on the public path, on every request. So the question here is only "may this
        // party see their own record", and the answer is almost always yes:
        //
        //   · DRAFT is a vendor who registered ten seconds ago on the public form and has
        //     not uploaded anything yet. Locking them out at exactly the moment they need to
        //     upload their certificates would make self-registration impossible.
        //   · SUSPENDED needs to read why they were suspended and get their paperwork back
        //     in order.
        //   · REJECTED is the case §10.10 of the vendor specification is about. An applicant
        //     is entitled to an outcome with a reason, and the cheapest honest way to deliver
        //     one is to let them read it. A rejected party can see their own decision and
        //     nothing else — there is no money on the account and no payout to request.
        //
        // The row that cannot sign in is the one that does not exist.
        $org = PartnerOrg::find((int) $user->org_id);
        if (!$org) return null;

        DB::table('gates_org_users')->where('id', $user->id)->update([
            'failed_logins' => 0,
            'locked_until'  => null,
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);
        return $user;
    }

    /** Establish the session. Regenerates the id so a fixated session cannot be inherited. */
    public function signIn(object $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_USER] = (int) $user->id;
        $_SESSION[self::SESSION_ORG]  = (int) $user->org_id;
    }

    public function signOut(): void
    {
        unset($_SESSION[self::SESSION_USER], $_SESSION[self::SESSION_ORG]);
    }

    public static function userId(): int { return (int) ($_SESSION[self::SESSION_USER] ?? 0); }
    public static function orgId(): int  { return (int) ($_SESSION[self::SESSION_ORG]  ?? 0); }

    /**
     * The signed-in user, re-read from the database on every request.
     *
     * Not cached in the session beyond the two ids: a user deactivated or moved between
     * organisations five minutes ago must lose access now, not at their next sign-in.
     */
    public static function user(): ?object
    {
        $id = self::userId();
        if ($id < 1) return null;
        try {
            $u = DB::table('gates_org_users')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
        if (!$u || (int) ($u->is_active ?? 1) !== 1) return null;
        // The session's org must still be this user's org. If it is not, the session is stale
        // or forged and is worth nothing.
        if ((int) $u->org_id !== self::orgId()) return null;
        return $u;
    }

    public static function findByEmail(string $email): ?object
    {
        $email = strtolower(trim($email));
        if ($email === '') return null;
        try {
            return DB::table('gates_org_users')->whereRaw('LOWER(email) = ?', [$email])->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Only an `owner` may move money. A `viewer` can read the dashboard and nothing else. */
    public static function canRequestPayout(?object $user): bool
    {
        return $user !== null && (string) ($user->role ?? 'viewer') === 'owner';
    }

    /**
     * Create a dashboard login. Used by the admin screen that onboards a partner.
     *
     * @return array{ok:bool,message:string}
     */
    public static function createUser(int $orgId, string $email, string $password, string $name = '', string $role = 'owner'): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'That is not a valid email address.'];
        if (strlen($password) < 12) {
            // Longer than the public site's minimum on purpose: this login can move money.
            return ['ok' => false, 'message' => 'Use at least 12 characters — this login can request payouts.'];
        }
        if (!PartnerOrg::find($orgId)) return ['ok' => false, 'message' => 'That organisation does not exist.'];
        if (self::findByEmail($email)) return ['ok' => false, 'message' => 'That email already has a dashboard login.'];

        DB::table('gates_org_users')->insert([
            'org_id'        => $orgId,
            'email'         => $email,
            'name'          => $name !== '' ? $name : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => in_array($role, ['owner', 'viewer'], true) ? $role : 'viewer',
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'message' => 'Dashboard login created.'];
    }
}
