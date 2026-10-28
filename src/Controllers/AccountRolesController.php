<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\Account\Roles;
use AfricaGates\Services\UserAccountService;
use AfricaGates\Services\Volunteer\Workspace;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * /account/roles — ONE ACCOUNT, EVERY ROLE (owner, 28 Oct 2026; plan §7.1).
 *
 * What this account can be (Member, Volunteer, Staff, Organisation, Judging), a way to add a
 * role it already holds elsewhere, and the door into each. Behind the member session; every
 * write is a POST that answers 303 with a sentence, and the rules live in Account\Roles.
 *
 * Opening a staff, organisation or judging role is audited on the audit log as that role's
 * sign-in ("login", method "account"), so the console's own record of who came in, and how,
 * stays complete whichever door they used.
 */
final class AccountRolesController
{
    public function __construct(private readonly Twig $view, private readonly UserAccountService $accounts, private readonly AuditService $audit) {}

    /** GET /account/roles[?confirm={kind}] */
    public function index(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->to($res, '/account/login?next=/account/roles');
        $confirm = (string) ($req->getQueryParams()['confirm'] ?? '');
        $secs = [];
        if (isset(Roles::KINDS[$confirm]) && !Roles::steppedUp()) {
            $secs[] = ['k' => 'form', 'h' => 'Confirm it’s you', 'do' => 'verify', 'hidden' => ['kind' => $confirm], 'submit' => 'Confirm and open',
                       'route' => 'A new code cancels the last one.', 'groups' => [
                ['label' => 'The code we emailed you', 'help' => 'Opening ' . strtolower(Roles::KINDS[$confirm]) . ' needs a six-digit code sent to ' . $this->masked((string) $user->email)
                    . '. It works for ' . Roles::OTP_MINUTES . ' minutes, and you stay confirmed for ' . Roles::STEPUP_MINUTES . ' minutes after.',
                 'fields' => [['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'placeholder' => '123456']]],
            ]];
            $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No code arrived?', 'cta' => ['label' => 'Send a new code', 'post' => ['do' => 'send', 'kind' => $confirm]]];
        }
        $rows = [];
        foreach (Roles::of($user) as $r) {
            $row = ['t' => $r['label'], 'sub' => $r['detail'], 'ini' => Workspace::initials($r['label'])];
            if (!empty($r['offer'])) {
                $row['cta'] = ['label' => 'Apply', 'href' => $r['href']];
            } elseif (!$r['guarded']) {
                $row['cta'] = ['label' => 'Open', 'href' => $r['href']];
            } elseif ($r['live']) {
                $row['chip'] = Roles::steppedUp() ? ['Confirmed', 'ok'] : ['Needs a code', 'mute'];
                $row['cta'] = ['label' => 'Open', 'post' => ['do' => 'open', 'kind' => $r['kind']], 'primary' => true];
            } else {
                $row['chip'] = ['Switched off', 'mute'];
            }
            $rows[] = $row;
        }
        $secs[] = ['k' => 'rows', 'h' => 'Your roles', 'items' => $rows];

        $add = [];
        foreach (Roles::linkable((int) $user->id) as $l) {
            $add[] = $l['taken']
                ? ['t' => Roles::KINDS[$l['kind']] . ': ' . $l['name'], 'sub' => 'Already part of another person’s account, so it cannot be added to yours.', 'chip' => ['Taken', 'bad']]
                : ['t' => Roles::KINDS[$l['kind']] . ': ' . $l['name'], 'sub' => 'You are signed in to this in this browser too. If it is yours, add it, and from now on you open it from here.',
                   'cta' => ['label' => 'Add to my account', 'post' => ['do' => 'link', 'kind' => $l['kind']], 'primary' => true,
                             'confirm' => 'Add ' . $l['name'] . ' to your account? Only do this if it is yours.']];
        }
        if ($add) $secs[] = ['k' => 'rows', 'h' => 'Signed in here as well', 'box' => true, 'items' => $add];

        $linked = array_filter(Roles::of($user), static fn ($r) => isset($r['link']));
        if ($linked) {
            $secs[] = ['k' => 'rows', 'h' => 'Take a role off this account', 'items' => array_values(array_map(static fn ($r) => ['t' => $r['label'], 'sub' => $r['detail'],
                'cta' => ['label' => 'Remove', 'post' => ['do' => 'unlink', 'link' => $r['link']], 'confirm' => 'Take this role off your account? You can still use its own sign-in page.']], $linked))];
        }
        $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Have a staff, organisation or judging sign-in from before? Sign in to it on its usual page in this browser, '
            . 'then come back here and add it. After that you open it from this account, with a code instead of a second password.'];

        return $this->view->render($res, 'account/roles.twig', [
            'page_title' => 'Your roles — Africa GATES',
            'shell' => ['scope' => 'root', 'title' => 'Your roles', 'purpose' => 'One account for everything you do on Africa GATES. Open any role from here.',
                'nav' => [['label' => null, 'items' => [
                    ['key' => 'roles', 'label' => 'Your roles', 'icon' => 'users', 'href' => '/account/roles', 'current' => true, 'drill' => false, 'count' => null],
                    ['key' => 'account', 'label' => 'Your account', 'icon' => 'user', 'href' => '/account', 'current' => false, 'drill' => false, 'count' => null],
                ]]],
                'crumbs' => [['label' => 'Your account', 'href' => '/account'], ['label' => 'Your roles']], 'tabs' => [], 'secs' => $secs,
                'brand' => 'Your account', 'railLabel' => 'Your account', 'act' => '/account/roles', 'home' => '/account/roles',
                'me' => ['name' => (string) $user->name, 'ini' => Workspace::initials((string) $user->name)],
                'toast' => $this->take('roles_toast'), 'error' => $this->take('roles_error'),
                'here' => '/account/roles' . ($confirm !== '' ? '?confirm=' . rawurlencode($confirm) : '')],
        ]);
    }

    /** POST /account/roles */
    public function act(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->to($res, '/account/login?next=/account/roles');
        $b = (array) $req->getParsedBody();
        $kind = (string) ($b['kind'] ?? '');
        switch ((string) ($b['do'] ?? '')) {
            case 'link':
                $r = Roles::link((int) $user->id, $kind);
                if ($r['ok'] && empty($r['already'])) $this->audit->record($kind === 'admin' ? (int) ($_SESSION['admin_id'] ?? 0) ?: null : null, 'account.role_linked', 'user', (int) $user->id, ['kind' => $kind]);
                return $this->back($res, $r['ok'], $r['ok'] ? 'Added. Open it from here from now on.' : (string) $r['error']);
            case 'unlink':
                $r = Roles::unlink((int) $user->id, (int) ($b['link'] ?? 0));
                return $this->back($res, $r['ok'], $r['ok'] ? 'Removed from your account.' : (string) $r['error']);
            case 'open':
                return $this->open($res, $user, $kind);
            case 'send':
                if (!isset(Roles::KINDS[$kind])) return $this->back($res, false, 'That is not a role.');
                $r = Roles::sendCode($user);
                return $this->back($res, $r['ok'], $r['ok'] ? 'A new code is on its way to your email.' : (string) $r['error'], '/account/roles?confirm=' . $kind);
            case 'verify':
                $r = Roles::verifyCode($user, (string) ($b['code'] ?? ''));
                if (!$r['ok']) return $this->back($res, false, (string) $r['error'], '/account/roles?confirm=' . $kind);
                return $this->open($res, $user, $kind);
            default:
                return $this->back($res, false, 'That isn’t something this page can do.');
        }
    }

    private function open(Response $res, object $user, string $kind): Response
    {
        $r = Roles::open($user, $kind);
        if (!empty($r['stepup'])) {
            $s = Roles::sendCode($user);
            return $this->back($res, $s['ok'], $s['ok'] ? 'We emailed you a code. Enter it to open ' . strtolower(Roles::KINDS[$kind]) . '.' : (string) $s['error'], '/account/roles?confirm=' . $kind);
        }
        if (!$r['ok']) return $this->back($res, false, (string) $r['error']);
        // The console's record of who came in, and how, whichever door they used.
        if ($kind === 'admin') $this->audit->record((int) $r['account'], 'login', 'admin', (int) $r['account'], ['method' => 'account', 'user' => (int) $user->id]);
        return $this->to($res, (string) $r['to']);
    }

    private function masked(string $email): string
    {
        [$a, $d] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($a, 0, 1) . str_repeat('•', max(1, mb_strlen($a) - 1)) . '@' . $d;
    }

    private function back(Response $res, bool $ok, string $msg, string $to = '/account/roles'): Response
    {
        $_SESSION[$ok ? 'roles_toast' : 'roles_error'] = $msg;
        return $this->to($res, $to);
    }

    private function take(string $k): mixed
    {
        $v = $_SESSION[$k] ?? null;
        unset($_SESSION[$k]);
        return $v;
    }

    private function to(Response $res, string $url): Response
    {
        return $res->withHeader('Location', $url)->withStatus(303);
    }
}
