<?php
declare(strict_types=1);
namespace AfricaGates\Services\Account;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Volunteers;
use AfricaGates\Support\OtpAttempt;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Session;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * ONE ACCOUNT, MANY ROLES (owner, 28 Oct 2026; plan §7.1 "one person, many memberships",
 * §5.1 "one Actor over five sign-in kinds").
 *
 * A person's Africa GATES account (gates_users) is who they are. Staff, organisation and judge
 * accounts are ROLES that account holds, tied to it by gates_account_links, and opened from it.
 * The rules, each held by AccountRolesTest:
 *
 *   · A LINK IS MADE ON PROOF, NEVER ON AN EMAIL. The person is signed in to their account and
 *     to the role in the same browser, and confirms. Two records sharing an address is the
 *     join the plan calls a fault (§3.4); holding both credentials at once is not.
 *   · ONE ROLE ACCOUNT, ONE PERSON. A staff, organisation or judge account already linked to
 *     somebody cannot be linked to a second account.
 *   · OPENING A ROLE THAT CAN CHANGE WHAT OTHERS RELY ON ASKS AGAIN. The staff console, an
 *     organisation's money and a judge's scorecards each need a six-digit code emailed to the
 *     account, fresh within STEPUP_MINUTES. One account does not mean one password reaching a
 *     charity's payouts: the account proves who you are, the code proves it is you now. The
 *     code is spent on use, lives OTP_MINUTES, and its guesses are capped by OtpAttempt::claim
 *     (the one clause; a read-then-compare cap is decoration under concurrency).
 *   · ENTERING A ROLE USES THAT ROLE'S OWN SESSION START (rotate the id, write its keys), so
 *     every middleware, guard and permission downstream sees exactly what its own sign-in
 *     would have produced, and the role's own rules (an inactive admin, a locked organisation
 *     user, a judge taken off a panel) still decide.
 *   · THE OLD SIGN-IN PAGES STILL WORK, so nothing breaks on the day this ships; a role
 *     opened from the account is the same session either way.
 */
final class Roles
{
    public const KINDS = ['admin' => 'Staff console', 'org' => 'Organisation', 'judge' => 'Judging'];
    public const STEPUP_MINUTES = 30;
    public const OTP_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    private const PURPOSE = 'role_stepup';

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
     * The role accounts signed in in THIS session that are not yet the account's: what the
     * person could link right now, by having just proved they hold both.
     *
     * @return list<array{kind:string, id:int, name:string}>
     */
    public static function linkable(int $userId): array
    {
        $out = [];
        foreach (self::sessionRoles() as $kind => $id) {
            $owner = self::ownerOf($kind, $id);
            if ($owner === $userId) continue;
            $out[] = ['kind' => $kind, 'id' => $id, 'name' => self::roleName($kind, $id), 'taken' => $owner !== null];
        }
        return $out;
    }

    /** Link the role signed in in this session to the account signed in in this session. */
    public static function link(int $userId, string $kind): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if ($userId <= 0 || (int) ($_SESSION['user_id'] ?? 0) !== $userId) return ['ok' => false, 'error' => 'Sign in to your account first.'];
        $roles = self::sessionRoles();
        if (!isset($roles[$kind])) return ['ok' => false, 'error' => 'Sign in to that role in this browser first, then link it here.'];
        $id = $roles[$kind];
        $owner = self::ownerOf($kind, $id);
        if ($owner === $userId) return ['ok' => true, 'already' => true];
        if ($owner !== null) return ['ok' => false, 'error' => 'That ' . strtolower(self::KINDS[$kind]) . ' account is already part of another person’s account.'];
        if (self::liveLinkOfKind($userId, $kind) !== null && $kind === 'admin') {
            return ['ok' => false, 'error' => 'Your account already holds a staff role. Unlink it first.'];
        }
        $lid = (int) DB::table('gates_account_links')->insertGetId([
            'user_id' => $userId, 'kind' => $kind, 'account_id' => $id, 'via' => 'both_signed_in', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $lid];
    }

    public static function unlink(int $userId, int $linkId): array
    {
        $n = self::ready() ? DB::table('gates_account_links')->where('id', $linkId)->where('user_id', $userId)->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s'), 'revoked_by_kind' => 'member', 'revoked_by' => $userId]) : 0;
        return $n > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'That role is not on your account.'];
    }

    // ── the roles an account holds ──────────────────────────────────────────────────────

    /**
     * Everything this account can be, each with how it is opened. Read from the records that
     * decide it — links, grants, the volunteer record — never from a stored list of roles.
     *
     * @return list<array{key:string, label:string, detail:string, href:string, kind:string, live:bool, guarded:bool, link?:int}>
     */
    public static function of(object $user): array
    {
        $uid = (int) $user->id;
        $out = [['key' => 'member', 'label' => 'Member', 'detail' => 'Votes, nominations, tickets and your profile', 'href' => '/account', 'kind' => 'member', 'live' => true, 'guarded' => false]];
        // Volunteer is a role of the account itself (owner, 28 Oct 2026): no second sign-in and
        // no link, because the volunteer record is keyed to this account. Where they help comes
        // from their live grants, so a role that ended is gone from here on its own.
        $v = Volunteers::ready() ? Volunteers::of($uid) : null;
        if ($v && !in_array($v->status, ['declined', 'left'], true)) {
            $places = [];
            foreach (Grants::forActor('member', $uid, 'vol.') as $g) $places[$g->scope_kind . ':' . $g->scope_id] = true;
            $out[] = ['key' => 'volunteer', 'label' => 'Volunteer', 'kind' => 'volunteer', 'live' => true, 'guarded' => false, 'href' => '/volunteer',
                      'detail' => Volunteers::roleLabel($v) . ($places ? ' · helping in ' . count($places) . (count($places) === 1 ? ' place' : ' places') : '')];
        } else {
            $out[] = ['key' => 'volunteer', 'label' => 'Volunteer', 'kind' => 'volunteer', 'live' => false, 'guarded' => false, 'offer' => true, 'href' => '/volunteer/apply',
                      'detail' => $v && $v->status === 'declined' ? 'Your application was not taken forward. You can apply again.' : 'Help at events and awards. Apply, and the volunteer team replies.'];
        }
        foreach (self::links($uid) as $l) {
            $row = self::roleRow((string) $l->kind, (int) $l->account_id);
            $active = $row && (int) ($row->is_active ?? 1) === 1;
            $detail = match ((string) $l->kind) {
                'admin' => $row ? (Permissions::ROLES[$row->role]['label'] ?? ucfirst((string) $row->role)) . self::leadDetail((int) $l->account_id) : '',
                'org' => $row ? self::orgName((int) $row->org_id) . ' · ' . ucfirst((string) $row->role) : '',
                default => 'Your panels and scorecards',
            };
            $out[] = ['key' => $l->kind . ':' . $l->account_id, 'label' => self::KINDS[$l->kind] ?? $l->kind, 'detail' => $active ? $detail : 'Switched off by an administrator',
                      'href' => '/account/roles', 'kind' => (string) $l->kind, 'live' => $active, 'guarded' => true, 'link' => (int) $l->id];
        }
        return $out;
    }

    // ── opening a role ──────────────────────────────────────────────────────────────────

    /** Has this session confirmed the account recently enough to open a guarded role? */
    public static function steppedUp(): bool
    {
        return (int) ($_SESSION['acct_stepup_at'] ?? 0) > time() - self::STEPUP_MINUTES * 60
            && (int) ($_SESSION['acct_stepup_user'] ?? 0) === (int) ($_SESSION['user_id'] ?? -1);
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
                $r = \AfricaGates\Services\OtpService::boot()->sendBranded($to, 'Your code to open a role: ' . $c,
                    '<p>Your code is <strong>' . $c . '</strong>. It works for ' . self::OTP_MINUTES . ' minutes, once.</p>'
                    . '<p>Somebody asked to open a staff, organisation or judging role on your Africa GATES account. If it was not you, change your password: '
                    . 'they have it.</p>', 'Your code is ' . $c . '. It works for ' . self::OTP_MINUTES . ' minutes, once.', 'security');
                return ($r['success'] ?? false) === true;
            } catch (\Throwable) {
                return false;
            }
        };
        return $send($email, $code) ? ['ok' => true] : ['ok' => false, 'error' => 'The code could not be sent. Try again in a minute.'];
    }

    /** Check the code. Right: the session is confirmed for STEPUP_MINUTES. Wrong: one guess spent. */
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
        $_SESSION['acct_stepup_at'] = time();
        $_SESSION['acct_stepup_user'] = (int) $user->id;
        return ['ok' => true];
    }

    /**
     * Open a role the account holds: the role's own session start, after the step-up.
     *
     * @return array{ok:bool, error?:string, stepup?:bool, to?:string, account?:int}
     */
    public static function open(object $user, string $kind): array
    {
        if (!isset(self::KINDS[$kind])) return ['ok' => false, 'error' => 'That is not a role.'];
        $link = self::liveLinkOfKind((int) $user->id, $kind);
        if (!$link) return ['ok' => false, 'error' => 'Your account does not hold that role.'];
        $row = self::roleRow($kind, (int) $link->account_id);
        if (!$row || (int) ($row->is_active ?? 1) !== 1) return ['ok' => false, 'error' => 'That role has been switched off. Ask an administrator.'];
        if ($kind === 'org' && !empty($row->locked_until) && strtotime((string) $row->locked_until) > time()) {
            return ['ok' => false, 'error' => 'That organisation sign-in is locked for a few minutes after too many tries.'];
        }
        if (!self::steppedUp()) return ['ok' => false, 'stepup' => true];
        $member = ['acct_opened' => $_SESSION['acct_opened'] ?? null, 'user_id' => $_SESSION['user_id'] ?? null, 'user_name' => $_SESSION['user_name'] ?? null, 'user_email' => $_SESSION['user_email'] ?? null,
                   'acct_stepup_at' => $_SESSION['acct_stepup_at'] ?? null, 'acct_stepup_user' => $_SESSION['acct_stepup_user'] ?? null];
        // The role's own start: rotate the id and write its keys, exactly as its sign-in page does.
        Session::rotate();
        foreach ($member as $k => $v) if ($v !== null) $_SESSION[$k] = $v;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $to = '/account';
        if ($kind === 'admin') {
            $_SESSION['admin_id'] = (int) $row->id;
            $_SESSION['admin_name'] = $row->name;
            $_SESSION['admin_role'] = $row->role;
            $_SESSION['admin_email'] = $row->email;
            $to = '/admin';
        } elseif ($kind === 'org') {
            $_SESSION['org_user_id'] = (int) $row->id;
            $_SESSION['org_id'] = (int) $row->org_id;
            $to = '/org';
        } else {
            $_SESSION['judge_id'] = (int) $row->id;
            $_SESSION['judge_name'] = $row->name;
            $_SESSION['judge_email'] = $row->email;
            $to = '/judge/ballot';
        }
        $_SESSION['acct_opened'] = array_values(array_unique(array_merge((array) ($_SESSION['acct_opened'] ?? []), [$kind])));
        return ['ok' => true, 'to' => $to, 'account' => (int) $row->id];
    }

    /**
     * Signing out of the account closes every role opened FROM it in this browser, and the
     * confirmation with them. A role signed in on its own old page is its own session and is
     * left alone: somebody may be signed in to the console here for a different reason.
     */
    public static function signOutOpened(): void
    {
        $keys = ['admin' => ['admin_id', 'admin_name', 'admin_role', 'admin_email'], 'org' => ['org_user_id', 'org_id'],
                 'judge' => ['judge_id', 'judge_name', 'judge_email']];
        foreach ((array) ($_SESSION['acct_opened'] ?? []) as $kind) foreach ($keys[$kind] ?? [] as $k) unset($_SESSION[$k]);
        unset($_SESSION['acct_opened'], $_SESSION['acct_stepup_at'], $_SESSION['acct_stepup_user']);
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
            'org' => trim((string) ($r->name ?? '')) . ' at ' . self::orgName((int) $r->org_id),
            default => (string) ($r->name ?? ''),
        };
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

    /** ", leads 2 events" when the staff account holds event lead grants. */
    private static function leadDetail(int $adminId): string
    {
        $n = count(array_filter(Grants::forActor('admin', $adminId, 'event.lead'), static fn ($g) => $g->capability === 'event.lead'));
        return $n > 0 ? ' · leads ' . $n . ($n === 1 ? ' event' : ' events') : '';
    }
}
