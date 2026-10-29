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
 * ONE ACCOUNT, MANY ROLES (owner, 28–29 Oct 2026; plan §7.1 "one person, many memberships").
 *
 * A person's Africa GATES account (gates_users) is who they are. Staff, organisation and judge
 * accounts are ROLES that account holds (gates_account_links). There is NO roles page: the
 * person switches from the account menu wherever they are, as on Airbnb, Shopify and GitHub,
 * and a sign-in lands where they last worked. The rules, each held by AccountRolesTest:
 *
 *   · A ROLE JOINS THE ACCOUNT ON THE PROOF ITS OWN DOOR ACCEPTS, CONFIRMED ONCE, AND RECORDED.
 *     Staff and judging sign in by email (the console's magic link, the panel's code), so an
 *     account whose address is VERIFIED and equal to the role's is that proof. An
 *     organisation's door is its own password and nothing else — a reset link in an inbox
 *     must never become a payout (OrgAuth) — so an organisation joins on that password, tried
 *     through OrgAuth::attempt with its throttle and lock. Being signed in to both in one
 *     browser is proof too. What the plan calls a fault (§3.4) is records joined by email AT
 *     READ TIME, silently; this is one confirmed link, kept, and removable.
 *   · ONE ROLE ACCOUNT, ONE PERSON.
 *   · SWITCHING IS FREE WHEN THIS SESSION HOLDS THE PROOF THE ROLE'S DOOR WOULD ASK FOR. A
 *     member who signed in with a password, a passkey or an emailed code/link may move into
 *     the console or the panel without being asked again; one who signed in with a code TEXTED
 *     to a phone has proved a phone, which neither door accepts, and is sent an email code once.
 *   · MONEY AND CONFIGURATION ASK AGAIN. A console opened from the account must confirm by an
 *     emailed code before a write in Finance or Configuration (AccountReconfirmMiddleware);
 *     an organisation opened from the account must give the organisation's password before a
 *     payout. Fresh for CONFIRM_MINUTES, for this account only. GitHub's "sudo mode" shape.
 *   · ENTERING A ROLE USES THAT ROLE'S OWN SESSION START (rotate the id, write its keys), so
 *     every guard downstream sees what its own sign-in would have produced, and the role's own
 *     rules (an inactive admin, a locked organisation user) still decide.
 *   · SIGNING OUT OF THE ACCOUNT CLOSES WHAT IT OPENED, and nothing signed in separately.
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
    private const HOME = ['member' => '/account', 'volunteer' => '/volunteer', 'admin' => '/admin', 'org' => '/org', 'judge' => '/judge/ballot'];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_account_links');
    }

    // ── links ───────────────────────────────────────────────────────────────────────────

    /** @return list<object> the account's live links */
    public static function links(int $userId): array
    {
        if (!self::ready() || $userId <= 0) return [];
        return DB::table('gates_account_links')->where('user_id', $userId)->whereNull('revoked_at')->orderBy('id')->get()->all();
    }

    /** The account a role account is linked to, if any. */
    public static function ownerOf(string $kind, int $accountId): ?int
    {
        if (!self::ready()) return null;
        $u = DB::table('gates_account_links')->where('kind', $kind)->where('account_id', $accountId)->whereNull('revoked_at')->value('user_id');
        return $u ? (int) $u : null;
    }

    /**
     * Role accounts this person could join to their account now, and how each would be proved:
     * 'session' (signed in to it in this browser), 'email' (staff or judging, on the account's
     * verified address) or 'password' (an organisation, on its own password).
     *
     * @return list<array{kind:string, id:int, name:string, how:string, taken:bool}>
     */
    public static function found(object $user): array
    {
        $uid = (int) $user->id;
        $out = [];
        $add = static function (string $kind, int $id, string $how) use (&$out, $uid): void {
            if (isset($out[$kind . ':' . $id])) return;
            $owner = self::ownerOf($kind, $id);
            if ($owner === $uid) return;
            $out[$kind . ':' . $id] = ['kind' => $kind, 'id' => $id, 'name' => self::roleName($kind, $id), 'how' => $how, 'taken' => $owner !== null];
        };
        foreach (self::sessionRoles() as $kind => $id) $add($kind, $id, 'session');
        $email = strtolower(trim((string) ($user->email ?? '')));
        if ($email !== '' && (int) ($user->email_verified ?? 0) === 1) {
            foreach (['admin' => 'gates_admins', 'judge' => 'gates_judges', 'org' => 'gates_org_users'] as $kind => $t) {
                try {
                    $ids = DB::table($t)->whereRaw('LOWER(email) = ?', [$email])->where('is_active', 1)->pluck('id')->all();
                } catch (\Throwable) {
                    $ids = [];
                }
                foreach ($ids as $id) $add($kind, (int) $id, $kind === 'org' ? 'password' : 'email');
            }
        }
        // Somebody else's role is not news to this person unless they are signed in to it here.
        return array_values(array_filter($out, static fn ($f) => !$f['taken'] || $f['how'] === 'session'));
    }

    /**
     * Join a found role to the account, on its proof. An organisation needs its password
     * (through $orgAuth, so its throttle and lock apply to this door exactly as to its own).
     */
    public static function join(object $user, string $kind, int $id, string $password = '', ?OrgAuth $orgAuth = null, string $ip = ''): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $uid = (int) $user->id;
        if ($uid <= 0 || (int) ($_SESSION['user_id'] ?? 0) !== $uid) return ['ok' => false, 'error' => 'Sign in to your account first.'];
        $f = null;
        foreach (self::found($user) as $c) if ($c['kind'] === $kind && $c['id'] === $id) $f = $c;
        if ($f === null) return ['ok' => false, 'error' => 'That sign-in is not one this account can add.'];
        if ($f['taken']) return ['ok' => false, 'error' => 'That ' . strtolower(self::KINDS[$kind]) . ' sign-in is already part of another person’s account.'];
        if ($kind === 'admin' && self::liveLinkOfKind($uid, 'admin') !== null) return ['ok' => false, 'error' => 'Your account already holds a staff sign-in. Remove it first.'];
        if ($f['how'] === 'password') {
            $row = self::roleRow('org', $id);
            $ok = $row && $orgAuth && $password !== '' && ($u = $orgAuth->attempt((string) $row->email, $password, $ip)) && (int) $u->id === $id;
            if (!$ok) return ['ok' => false, 'error' => 'That password did not open the organisation sign-in. After five tries it locks for a few minutes.'];
        }
        $lid = (int) DB::table('gates_account_links')->insertGetId([
            'user_id' => $uid, 'kind' => $kind, 'account_id' => $id, 'via' => ['session' => 'both_signed_in', 'email' => 'verified_email', 'password' => 'org_password'][$f['how']],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $lid];
    }

    public static function unlink(int $userId, int $linkId): array
    {
        $n = self::ready() ? DB::table('gates_account_links')->where('id', $linkId)->where('user_id', $userId)->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s'), 'revoked_by_kind' => 'member', 'revoked_by' => $userId]) : 0;
        return $n > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'That sign-in is not on your account.'];
    }

    // ── what the account menu offers ────────────────────────────────────────────────────

    /**
     * The account menu's "Switch to" list: everything this account is, read from the records
     * that decide it (links, grants, the volunteer record), never from a stored list of roles.
     * Volunteering is a role of the account itself: no link, no second proof.
     *
     * @return list<array{kind:string, label:string, sub:string, live:bool, link?:int}>
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
        foreach (self::links($uid) as $l) {
            $row = self::roleRow((string) $l->kind, (int) $l->account_id);
            $active = $row && (int) ($row->is_active ?? 1) === 1;
            $sub = match ((string) $l->kind) {
                'admin' => $row ? (Permissions::ROLES[$row->role]['label'] ?? ucfirst((string) $row->role)) . self::leadDetail((int) $l->account_id) : '',
                'org' => $row ? self::orgName((int) $row->org_id) : '',
                default => 'Your panels and scorecards',
            };
            $out[] = ['kind' => (string) $l->kind, 'label' => self::KINDS[$l->kind] ?? (string) $l->kind, 'sub' => $active ? $sub : 'Switched off by an administrator',
                      'live' => $active, 'link' => (int) $l->id];
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
        if ($kind === 'member' || $kind === 'volunteer') {
            if ($kind === 'volunteer' && !(Volunteers::ready() && Volunteers::of($uid))) return ['ok' => false, 'error' => 'You are not a volunteer yet. Apply from your account.'];
            self::remember($uid, $kind);
            return ['ok' => true, 'to' => self::HOME[$kind]];
        }
        if (!isset(self::KINDS[$kind])) return ['ok' => false, 'error' => 'That is not something this account can open.'];
        $link = self::liveLinkOfKind($uid, $kind);
        if (!$link) return ['ok' => false, 'error' => 'Your account does not hold that sign-in.'];
        $row = self::roleRow($kind, (int) $link->account_id);
        if (!$row || (int) ($row->is_active ?? 1) !== 1) return ['ok' => false, 'error' => 'That sign-in has been switched off. Ask an administrator.'];
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
            $path = (string) (parse_url($next, PHP_URL_PATH) ?? '');
            $kind = str_starts_with($path, '/admin') ? 'admin' : (str_starts_with($path, '/judge') ? 'judge' : (preg_match('~^/org(/|$)~', $path) ? 'org' : null));
            if ($kind !== null && !isset(self::sessionRoles()[$kind])) {
                if (self::liveLinkOfKind((int) $user->id, $kind)) {
                    $r = self::open($user, $kind);
                    if (!empty($r['confirm'])) return '/account/confirm?for=' . $kind . '&next=' . rawurlencode($next);
                } elseif (array_filter(self::found($user), static fn ($f) => $f['kind'] === $kind && !$f['taken'])) {
                    // Came from that role's sign-in page and it is not joined yet: the account
                    // page is where it is offered, rather than bouncing back to the old door.
                    return '/account';
                }
            }
            return $next;
        }
        $last = (string) (self::lastPlace((int) $user->id) ?? 'member');
        if ($last === 'member') return '/account';
        $r = self::open($user, $last);
        return $r['ok'] ? (string) $r['to'] : '/account';
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
        $keys = ['admin' => ['admin_id', 'admin_name', 'admin_role', 'admin_email'], 'org' => ['org_user_id', 'org_id'],
                 'judge' => ['judge_id', 'judge_name', 'judge_email']];
        foreach ((array) ($_SESSION['acct_opened'] ?? []) as $kind) foreach ($keys[$kind] ?? [] as $k) unset($_SESSION[$k]);
        unset($_SESSION['acct_opened'], $_SESSION['acct_proof'], $_SESSION['acct_confirmed_at'], $_SESSION['acct_confirmed_user'],
              $_SESSION['acct_org_proven_at'], $_SESSION['acct_org_proven_user']);
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

    public static function roleName(string $kind, int $id): string
    {
        $r = self::roleRow($kind, $id);
        if (!$r) return '';
        return match ($kind) {
            'org' => self::orgName((int) $r->org_id),
            default => (string) ($r->name ?? ''),
        };
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

    private static function liveLinkOfKind(int $userId, string $kind): ?object
    {
        if (!self::ready()) return null;
        return DB::table('gates_account_links')->where('user_id', $userId)->where('kind', $kind)->whereNull('revoked_at')->orderByDesc('id')->first() ?: null;
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

    /** " · leads 2 events" when the staff account holds event lead grants. */
    private static function leadDetail(int $adminId): string
    {
        $n = count(array_filter(Grants::forActor('admin', $adminId, 'event.lead'), static fn ($g) => $g->capability === 'event.lead'));
        return $n > 0 ? ' · leads ' . $n . ($n === 1 ? ' event' : ' events') : '';
    }
}
