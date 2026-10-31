<?php
declare(strict_types=1);
namespace AfricaGates\Services\Account;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\Grants;
use AfricaGates\Services\OrgAuth;
use AfricaGates\Services\Volunteer\Volunteers;
use AfricaGates\Support\OtpAttempt;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Session;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * ONE ACCOUNT, ONE LOGIN, ROLES GIVEN (owner, 28–29 Oct 2026; plan §7.1 "one person, many
 * memberships"; then "roles given, not joined", and the separate sign-in pages retired).
 *
 * A person's Africa GATES account (gates_users) is who they are, and /account/login is the only
 * way in. A staff, judging or organisation role is GIVEN by staff to an email address — the
 * address typed when the admin, the judge or the organisation user was created — and whoever
 * signs in with that address, VERIFIED, holds it. Nobody joins, links or adds anything: staff
 * give by address, take away by switching the role off or changing its address, and the account
 * page shows what the account holds. That is how a workspace invitation behaves elsewhere. The
 * rules, each held by AccountRolesTest:
 *
 *   · HELD IS READ FROM THE ROLE'S OWN ROW, every time (held()). No join table to drift: a role
 *     switched off, or moved to another address, is gone from the next request. The address
 *     must be verified on the account, and a member cannot change their account's address, so
 *     holding it is proving it — the same proof the console's magic link and the panel's code
 *     always asked for.
 *   · OPENING A ROLE IS DERIVED, NOT AN ACT. A console page reached by a signed-in account that
 *     holds it starts that role's session on the way in (derive(), called by the console, panel
 *     and organisation gates), using the role's own session start (rotate the id, write its
 *     keys) so every guard downstream sees exactly what its old sign-in produced.
 *   · BUT ONLY ON A PROOF THAT ROLE'S OLD DOOR ACCEPTED. A session proved by password, passkey
 *     or an emailed code or link opens staff and judging; one proved by a code TEXTED to a
 *     phone has proved a phone, which neither ever accepted, and is sent an email code once.
 *   · MONEY AND CONFIGURATION ASK AGAIN. A console opened from the account confirms by an
 *     emailed code before a write in Finance or Configuration (AccountReconfirmMiddleware); an
 *     organisation's payout needs the organisation's own password — an email is never enough
 *     to move a charity's money (OrgAuth). Fresh for CONFIRM_MINUTES, for this account only.
 *   · SIGNING OUT OF THE ACCOUNT CLOSES EVERY ROLE IT OPENED.
 */
final class Roles
{
    public const KINDS = ['admin' => 'Staff console', 'org' => 'Organisation', 'judge' => 'Judging'];
    public const CONFIRM_MINUTES = 30;
    public const OTP_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    private const PURPOSE = 'role_stepup';
    /** How this session's account was proved, per sign-in path (AccountController). */
    public const PROOFS = ['password', 'passkey', 'email', 'phone'];
    /** The proofs each role's own door accepts. Organisation entry is free; its money is not. */
    private const DOOR = ['admin' => ['password', 'passkey', 'email'], 'judge' => ['password', 'passkey', 'email'], 'org' => self::PROOFS];
    private const HOME = ['member' => '/account', 'volunteer' => '/volunteer', 'lead' => '/lead', 'admin' => '/admin', 'org' => '/org', 'judge' => '/judge/ballot'];

    /**
     * The staff, judging and organisation roles this account holds: rows whose address is the
     * account's own VERIFIED address. At most one of each kind (each table's email is unique).
     *
     * @return array<string, object> kind => the role's row, live or switched off
     */
    public static function held(object $user, ?string $only = null): array
    {
        $email = strtolower(trim((string) ($user->email ?? '')));
        if ($email === '' || (int) ($user->email_verified ?? 0) !== 1) return [];
        $out = [];
        foreach (['admin' => 'gates_admins', 'judge' => 'gates_judges', 'org' => 'gates_org_users'] as $kind => $t) {
            if ($only !== null && $only !== $kind) continue;
            try {
                $row = DB::table($t)->whereRaw('LOWER(email) = ?', [$email])->orderBy('id')->first();
            } catch (\Throwable) {
                $row = null;
            }
            if ($row) $out[$kind] = $row;
        }
        return $out;
    }

    /**
     * Tell somebody a role has been given to their address, and how to use it: sign in with
     * that address. Best-effort — a mail outage must not stop staff creating a judge — and the
     * message carries no link that signs anybody in.
     */
    public static function tellGiven(string $kind, string $email, string $givenBy = ''): bool
    {
        if (!isset(self::KINDS[$kind]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $what = ['admin' => 'the Africa GATES staff console', 'judge' => 'judging on Africa GATES', 'org' => 'your organisation’s dashboard on Africa GATES'][$kind];
        $url = rtrim((string) \AfricaGates\Support\Env::get('APP_URL', ''), '/') . '/account/login';
        try {
            $r = \AfricaGates\Services\OtpService::boot()->sendBranded($email, 'You now have ' . strtolower(self::KINDS[$kind]) . ' on Africa GATES',
                '<p>' . ($givenBy !== '' ? htmlspecialchars($givenBy, ENT_QUOTES) . ' has given you ' : 'You have been given ') . htmlspecialchars($what, ENT_QUOTES) . '.</p>'
                . '<p>Sign in to Africa GATES with this email address — <a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($url, ENT_QUOTES) . '</a> — '
                . 'and choose it from the switcher at the top of the menu. If you have no account yet, signing in with this address makes one.</p>',
                'Sign in to Africa GATES with this email address: ' . $url, 'security');
            return ($r['success'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── what the account menu offers ────────────────────────────────────────────────────

    /**
     * The account menu's "Switch to" list: everything this account is, read from the records
     * that decide it (links, grants, the volunteer record), never from a stored list of roles.
     * Volunteering is a role of the account itself: no link, no second proof.
     *
     * @return list<array{kind:string, label:string, sub:string, live:bool}>
     */
    public static function menu(object $user): array
    {
        $uid = (int) $user->id;
        $out = [['kind' => 'member', 'label' => 'My account', 'sub' => 'Votes, nominations, tickets', 'live' => true]];
        $v = Volunteers::ready() ? Volunteers::of($uid) : null;
        if ($v && !in_array($v->status, ['declined', 'left'], true)) {
            $places = [];
            foreach (Grants::forActor('member', $uid, 'vol.') as $g) $places[$g->scope_kind . ':' . $g->scope_id] = true;
            $out[] = ['kind' => 'volunteer', 'label' => 'My volunteering', 'live' => true,
                      'sub' => Volunteers::roleLabel($v) . ($places ? ' · ' . count($places) . (count($places) === 1 ? ' place' : ' places') : '')];
        }
        // Leading an event is the account's own, like volunteering: a grant to this member,
        // not a staff role (Plan::ACTOR), so organisers' leads never need the admin console.
        $led = self::ledEvents($uid);
        if ($led > 0) $out[] = ['kind' => 'lead', 'label' => 'Events I lead', 'live' => true, 'sub' => $led . ($led === 1 ? ' event' : ' events')];
        foreach (self::held($user) as $kind => $row) {
            $active = (int) ($row->is_active ?? 1) === 1;
            $sub = match ($kind) {
                'admin' => Permissions::ROLES[$row->role]['label'] ?? ucfirst((string) $row->role),
                'org' => self::orgName((int) $row->org_id),
                default => 'Your panels and scorecards',
            };
            $out[] = ['kind' => $kind, 'label' => self::KINDS[$kind], 'sub' => $active ? $sub : 'Switched off by an administrator', 'live' => $active];
        }
        return $out;
    }

    // ── switching ───────────────────────────────────────────────────────────────────────

    /** Record how this session's account was proved. Called by every sign-in path. */
    public static function proved(string $how): void
    {
        if (in_array($how, self::PROOFS, true)) $_SESSION['acct_proof'] = $how;
    }

    /** Does this session hold the proof the role's own door would ask for? */
    public static function proofFor(string $kind): bool
    {
        $doors = self::DOOR[$kind] ?? [];
        return in_array((string) ($_SESSION['acct_proof'] ?? ''), $doors, true) || self::confirmed();
    }

    /**
     * Switch to what the account is: the member's own pages, volunteering, or a role it holds.
     *
     * @return array{ok:bool, error?:string, confirm?:bool, to?:string, account?:int}
     */
    public static function open(object $user, string $kind): array
    {
        $uid = (int) $user->id;
        if ($kind === 'member' || $kind === 'volunteer' || $kind === 'lead') {
            if ($kind === 'volunteer' && !(Volunteers::ready() && Volunteers::of($uid))) return ['ok' => false, 'error' => 'You are not a volunteer yet. Apply from your account.'];
            if ($kind === 'lead' && self::ledEvents($uid) === 0) return ['ok' => false, 'error' => 'You don’t lead an event. The organiser gives it to your email address.'];
            self::remember($uid, $kind);
            return ['ok' => true, 'to' => self::HOME[$kind]];
        }
        if (!isset(self::KINDS[$kind])) return ['ok' => false, 'error' => 'That is not something this account can open.'];
        $row = self::held($user, $kind)[$kind] ?? null;
        if (!$row) return ['ok' => false, 'none' => true, 'error' => 'Your account does not have ' . strtolower(self::KINDS[$kind]) . '. Ask whoever runs it to give it to your email address.'];
        if ((int) ($row->is_active ?? 1) !== 1) return ['ok' => false, 'error' => 'Your ' . strtolower(self::KINDS[$kind]) . ' access has been switched off. Ask an administrator.'];
        if ($kind === 'org' && !empty($row->locked_until) && strtotime((string) $row->locked_until) > time()) {
            return ['ok' => false, 'error' => 'That organisation sign-in is locked for a few minutes after too many tries.'];
        }
        if (!self::proofFor($kind)) return ['ok' => false, 'confirm' => true];
        // Already in it, as this account: nothing to start again.
        if (in_array($kind, (array) ($_SESSION['acct_opened'] ?? []), true) && (self::sessionRoles()[$kind] ?? 0) === (int) $row->id) {
            self::remember($uid, $kind);
            return ['ok' => true, 'to' => self::HOME[$kind], 'account' => (int) $row->id, 'already' => true];
        }
        $keep = [];
        foreach (['acct_opened', 'acct_proof', 'acct_confirmed_at', 'acct_confirmed_user', 'acct_org_proven_at', 'acct_org_proven_user', 'user_id', 'user_name', 'user_email'] as $k) {
            if (isset($_SESSION[$k])) $keep[$k] = $_SESSION[$k];
        }
        // The role's own start: rotate the id and write its keys, exactly as its sign-in page does.
        Session::rotate();
        foreach ($keep as $k => $v) $_SESSION[$k] = $v;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        if ($kind === 'admin') {
            $_SESSION['admin_id'] = (int) $row->id;
            $_SESSION['admin_name'] = $row->name;
            $_SESSION['admin_role'] = $row->role;
            $_SESSION['admin_email'] = $row->email;
        } elseif ($kind === 'org') {
            $_SESSION['org_user_id'] = (int) $row->id;
            $_SESSION['org_id'] = (int) $row->org_id;
        } else {
            $_SESSION['judge_id'] = (int) $row->id;
            $_SESSION['judge_name'] = $row->name;
            $_SESSION['judge_email'] = $row->email;
        }
        $_SESSION['acct_opened'] = array_values(array_unique(array_merge((array) ($_SESSION['acct_opened'] ?? []), [$kind])));
        self::remember($uid, $kind);
        // The console's own record of who came in, and how — whichever page opened it.
        if ($kind === 'admin') (new \AfricaGates\Admin\Services\AuditService())->record((int) $row->id, 'login', 'admin', (int) $row->id, ['method' => 'account', 'user' => $uid]);
        return ['ok' => true, 'to' => self::HOME[$kind], 'account' => (int) $row->id];
    }

    /**
     * Where a sign-in lands. A requested page wins; a request for a console the account holds
     * opens it on the way (so the staff sign-in page can send people here and get them back).
     * With nowhere asked for, the place they last switched to — never a page asking which.
     */
    public static function landing(object $user, ?string $next): string
    {
        if ($next !== null) {
            $kind = self::kindOf($next);
            if ($kind !== null && !isset(self::sessionRoles()[$kind])) {
                $r = self::open($user, $kind);
                if (!empty($r['confirm'])) return '/account/confirm?for=' . $kind . '&next=' . rawurlencode($next);
            }
            return $next;
        }
        $last = (string) (self::lastPlace((int) $user->id) ?? 'member');
        if ($last === 'member') return '/account';
        $r = self::open($user, $last);
        return $r['ok'] ? (string) $r['to'] : '/account';
    }

    /**
     * A console, panel or organisation page reached without its session: open it from the
     * signed-in account if the account holds it. The gates call this before bouncing anybody.
     *
     * @return array{state:string, to?:string, error?:string} state: open | confirm | refused | anonymous
     */
    public static function derive(string $kind, string $next = ''): array
    {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $user = $uid > 0 ? DB::table('gates_users')->where('id', $uid)->first() : null;
        $sid = self::sessionRoles()[$kind] ?? 0;
        // A session this account did not open (one signed in before the one login shipped)
        // is left to its own gate and expires on its own.
        if ($sid > 0 && !in_array($kind, (array) ($_SESSION['acct_opened'] ?? []), true)) return ['state' => 'open'];
        if ($sid > 0) {
            // Opened from the account: still the account's? A role moved to another address
            // since leaves with the next request, not at the next sign-out.
            $row = $user ? (self::held($user, $kind)[$kind] ?? null) : null;
            if ($row && (int) $row->id === $sid) return ['state' => 'open'];
            self::close($kind);
        }
        if (!$user) return ['state' => 'anonymous', 'to' => '/account/login?next=' . rawurlencode($next !== '' ? $next : self::HOME[$kind])];
        $r = self::open($user, $kind);
        if ($r['ok']) return ['state' => 'open'];
        if (!empty($r['confirm'])) {
            return ['state' => 'confirm', 'to' => '/account/confirm?for=' . $kind . '&next=' . rawurlencode($next !== '' ? $next : self::HOME[$kind])];
        }
        return ['state' => 'refused', 'to' => '/account', 'error' => (string) ($r['error'] ?? '')];
    }

    /** Which role a path belongs to, or null for the member's own pages. */
    public static function kindOf(string $path): ?string
    {
        $p = (string) (parse_url($path, PHP_URL_PATH) ?? '');
        return str_starts_with($p, '/admin') ? 'admin' : (str_starts_with($p, '/judge') ? 'judge' : (preg_match('~^/org(/|$)~', $p) ? 'org' : null));
    }

    // ── confirming again ────────────────────────────────────────────────────────────────

    /** Has this account confirmed by emailed code within CONFIRM_MINUTES, in this session? */
    public static function confirmed(): bool
    {
        return (int) ($_SESSION['acct_confirmed_at'] ?? 0) > time() - self::CONFIRM_MINUTES * 60
            && (int) ($_SESSION['acct_confirmed_user'] ?? 0) === (int) ($_SESSION['user_id'] ?? -1);
    }

    /** A console opened from the account, not yet confirmed: Finance and Configuration ask. */
    public static function consoleNeedsConfirm(): bool
    {
        return in_array('admin', (array) ($_SESSION['acct_opened'] ?? []), true) && !self::confirmed();
    }

    /** An organisation opened from the account, not yet proved by ITS password: a payout asks. */
    public static function orgNeedsPassword(): bool
    {
        if (!in_array('org', (array) ($_SESSION['acct_opened'] ?? []), true)) return false;
        return !((int) ($_SESSION['acct_org_proven_at'] ?? 0) > time() - self::CONFIRM_MINUTES * 60
            && (int) ($_SESSION['acct_org_proven_user'] ?? 0) === (int) ($_SESSION['org_user_id'] ?? -1));
    }

    /** Prove the open organisation sign-in by its own password, through its throttle and lock. */
    public static function proveOrg(OrgAuth $auth, string $password, string $ip = ''): array
    {
        $id = (int) ($_SESSION['org_user_id'] ?? 0);
        $row = $id > 0 ? self::roleRow('org', $id) : null;
        $u = $row && $password !== '' ? $auth->attempt((string) $row->email, $password, $ip) : null;
        if (!$u || (int) $u->id !== $id) return ['ok' => false, 'error' => 'That is not the organisation’s password. After five tries it locks for a few minutes.'];
        $_SESSION['acct_org_proven_at'] = time();
        $_SESSION['acct_org_proven_user'] = $id;
        return ['ok' => true];
    }

    /**
     * Email a six-digit code to the account. A new code cancels the last.
     *
     * @param ?callable(string $to, string $code): bool $send for tests
     */
    public static function sendCode(object $user, ?callable $send = null): array
    {
        $email = strtolower(trim((string) $user->email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Your account has no email address to send a code to.'];
        $eh = hash('sha256', $email);
        DB::table('gates_otp_tokens')->where('email_hash', $eh)->where('purpose', self::PURPOSE)->where('is_used', 0)->update(['is_used' => 1]);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => $eh, 'token_hash' => hash('sha256', $code), 'purpose' => self::PURPOSE, 'nominee_id' => (int) $user->id, 'award_id' => 0,
            'attempts' => 0, 'is_used' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + self::OTP_MINUTES * 60), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $send ??= static function (string $to, string $c): bool {
            try {
                $r = \AfricaGates\Services\OtpService::boot()->sendBranded($to, 'Your code to confirm it’s you: ' . $c,
                    '<p>Your code is <strong>' . $c . '</strong>. It works for ' . self::OTP_MINUTES . ' minutes, once.</p>'
                    . '<p>Somebody signed in to your Africa GATES account asked to open the staff console or judging, or to change money or settings there. '
                    . 'If it was not you, change your password: they have it.</p>', 'Your code is ' . $c . '. It works for ' . self::OTP_MINUTES . ' minutes, once.', 'security');
                return ($r['success'] ?? false) === true;
            } catch (\Throwable) {
                return false;
            }
        };
        return $send($email, $code) ? ['ok' => true] : ['ok' => false, 'error' => 'The code could not be sent. Try again in a minute.'];
    }

    /** Check the code. Right: the session is confirmed for CONFIRM_MINUTES. Wrong: one guess spent. */
    public static function verifyCode(object $user, string $code): array
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $eh = hash('sha256', strtolower(trim((string) $user->email)));
        $tok = DB::table('gates_otp_tokens')->where('email_hash', $eh)->where('purpose', self::PURPOSE)->where('is_used', 0)
            ->where('nominee_id', (int) $user->id)->where('expires_at', '>', date('Y-m-d H:i:s'))->orderByDesc('id')->first();
        if (!$tok) return ['ok' => false, 'error' => 'That code has expired or was replaced. Send a new one.'];
        // Claim the guess before comparing, in one statement: concurrent guesses cannot all
        // read "0 attempts" and all be compared (OtpAttempt, CLAUDE.md).
        if (!OtpAttempt::claim((int) $tok->id, self::MAX_ATTEMPTS)) {
            DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
            return ['ok' => false, 'error' => 'Too many tries. Send a new code.'];
        }
        if (strlen($code) !== 6 || !hash_equals((string) $tok->token_hash, hash('sha256', $code))) {
            $left = self::MAX_ATTEMPTS - ((int) $tok->attempts + 1);
            if ($left <= 0) DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
            return ['ok' => false, 'error' => $left > 0 ? 'That code is not right. ' . $left . ($left === 1 ? ' try' : ' tries') . ' left.' : 'That was the last try. Send a new code.'];
        }
        DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
        $_SESSION['acct_confirmed_at'] = time();
        $_SESSION['acct_confirmed_user'] = (int) $user->id;
        return ['ok' => true];
    }

    /**
     * Signing out of the account closes every role opened FROM it in this browser, and the
     * confirmations with them. A role signed in on its own page is its own session and is
     * left alone: somebody may be signed in to the console here for a different reason.
     */
    public static function signOutOpened(): void
    {
        foreach (array_keys(self::KINDS) as $kind) self::close($kind);
        unset($_SESSION['acct_opened'], $_SESSION['acct_proof'], $_SESSION['acct_confirmed_at'], $_SESSION['acct_confirmed_user'],
              $_SESSION['acct_org_proven_at'], $_SESSION['acct_org_proven_user']);
    }

    /** End one role's session in this browser, leaving the account and the others. */
    public static function close(string $kind): void
    {
        $keys = ['admin' => ['admin_id', 'admin_name', 'admin_role', 'admin_email'], 'org' => ['org_user_id', 'org_id'],
                 'judge' => ['judge_id', 'judge_name', 'judge_email']];
        foreach ($keys[$kind] ?? [] as $k) unset($_SESSION[$k]);
        $_SESSION['acct_opened'] = array_values(array_diff((array) ($_SESSION['acct_opened'] ?? []), [$kind]));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,int> the role accounts signed in in this session */
    public static function sessionRoles(): array
    {
        $out = [];
        if (!empty($_SESSION['admin_id'])) $out['admin'] = (int) $_SESSION['admin_id'];
        if (!empty($_SESSION['org_user_id'])) $out['org'] = (int) $_SESSION['org_user_id'];
        if (!empty($_SESSION['judge_id'])) $out['judge'] = (int) $_SESSION['judge_id'];
        return $out;
    }

    public static function lastPlace(int $userId): ?string
    {
        if (!SchemaHas::column('gates_users', 'last_place')) return null;
        $p = DB::table('gates_users')->where('id', $userId)->value('last_place');
        return is_string($p) && isset(self::HOME[$p]) ? $p : null;
    }

    private static function remember(int $userId, string $kind): void
    {
        if ($userId > 0 && SchemaHas::column('gates_users', 'last_place')) DB::table('gates_users')->where('id', $userId)->update(['last_place' => $kind]);
    }

    private static function roleRow(string $kind, int $id): ?object
    {
        $t = ['admin' => 'gates_admins', 'org' => 'gates_org_users', 'judge' => 'gates_judges'][$kind] ?? null;
        if ($t === null) return null;
        try {
            return DB::table($t)->where('id', $id)->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function orgName(int $orgId): string
    {
        try {
            return (string) (DB::table('gates_partner_orgs')->where('id', $orgId)->value('name') ?? 'an organisation');
        } catch (\Throwable) {
            return 'an organisation';
        }
    }

    /** How many events this member leads now (live `event.lead` grants). */
    private static function ledEvents(int $userId): int
    {
        if ($userId <= 0 || !Grants::ready()) return 0;
        return count(array_filter(Grants::forActor('member', $userId, 'event.lead'), static fn ($g) => $g->capability === 'event.lead'));
    }
}
