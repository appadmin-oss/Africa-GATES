<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\Account\Roles;
use AfricaGates\Services\OrgAuth;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Services\UserAccountService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * ONE ACCOUNT, ONE LOGIN, ROLES GIVEN (owner, 29 Oct 2026).
 *
 * The two things posted here, each a thin call into Account\Roles where the rules live: the
 * switcher's choice (which also records where a later sign-in lands), and confirming it's you
 * before a console opened from the account changes money or configuration — or, for an
 * organisation's payout, its own password. Nothing is joined or linked: a role is given to an
 * email address by staff. Every write answers 303 with a sentence; the confirm screen is the
 * only GET, and it only draws.
 */
final class AccountSwitchController
{
    public function __construct(private readonly Twig $view, private readonly UserAccountService $accounts, private readonly RateLimitService $rateLimit) {}

    /** POST /account/switch — kind = member | volunteer | admin | org | judge */
    public function switch(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->to($res, '/account/login');
        $b = (array) $req->getParsedBody();
        $kind = (string) ($b['kind'] ?? '');
        $back = $this->safe((string) ($b['back'] ?? '')) ?? '/account';
        $r = Roles::open($user, $kind);
        if (!empty($r['confirm'])) {
            // The code is sent here, by this POST — never by the GET of a screen a refresh or a
            // mail scanner can repeat.
            $s = Roles::sendCode($user);
            $_SESSION[$s['ok'] ? 'acct_confirm_note' : 'acct_confirm_error'] = $s['ok']
                ? 'You signed in with a code sent to your phone. To open ' . strtolower(Roles::KINDS[$kind]) . ', enter the code we just emailed you.'
                : (string) $s['error'];
            return $this->to($res, '/account/confirm?for=' . $kind);
        }
        if (!$r['ok']) return $this->back($res, $back, (string) $r['error']);
        return $this->to($res, (string) $r['to']);
    }

    /** GET /account/confirm?for=admin|judge|console|org[&next=] — draws only; sends nothing. */
    public function confirmPage(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->to($res, '/account/login');
        $q = $req->getQueryParams();
        $for = (string) ($q['for'] ?? '');
        if (!in_array($for, ['admin', 'judge', 'console', 'org'], true)) return $this->to($res, '/account');
        $note = $_SESSION['acct_confirm_note'] ?? null;
        $error = $_SESSION['acct_confirm_error'] ?? null;
        unset($_SESSION['acct_confirm_note'], $_SESSION['acct_confirm_error']);
        return $this->view->render($res, 'pages/account/confirm.twig', [
            'page_title' => 'Confirm it’s you — Africa GATES',
            'for' => $for, 'next' => $this->safe((string) ($q['next'] ?? '')) ?? '',
            'masked' => $this->masked((string) $user->email),
            'otp_minutes' => Roles::OTP_MINUTES, 'confirm_minutes' => Roles::CONFIRM_MINUTES,
            'note' => $note, 'error' => $error,
            'back_to' => $for === 'org' ? '/org' : ($for === 'console' ? '/admin' : '/account'),
        ]);
    }

    /** POST /account/confirm — do = send | code | org */
    public function confirm(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->to($res, '/account/login');
        $b = (array) $req->getParsedBody();
        $for = (string) ($b['for'] ?? '');
        if (!in_array($for, ['admin', 'judge', 'console', 'org'], true)) return $this->to($res, '/account');
        $next = $this->safe((string) ($b['next'] ?? ''));
        $here = '/account/confirm?for=' . $for . ($next ? '&next=' . rawurlencode($next) : '');
        switch ((string) ($b['do'] ?? '')) {
            case 'send':
                $s = Roles::sendCode($user);
                $_SESSION[$s['ok'] ? 'acct_confirm_note' : 'acct_confirm_error'] = $s['ok'] ? 'A new code is on its way to ' . $this->masked((string) $user->email) . '.' : (string) $s['error'];
                return $this->to($res, $here);
            case 'org':
                $r = Roles::proveOrg(new OrgAuth($this->rateLimit), (string) ($b['password'] ?? ''), $this->ip($req));
                if (!$r['ok']) {
                    $_SESSION['acct_confirm_error'] = (string) $r['error'];
                    return $this->to($res, $here);
                }
                $_SESSION['org_flash_ok'] = 'Confirmed. Press the button again to go ahead.';
                return $this->to($res, $next ?? '/org');
            case 'code':
                $r = Roles::verifyCode($user, (string) ($b['code'] ?? ''));
                if (!$r['ok']) {
                    $_SESSION['acct_confirm_error'] = (string) $r['error'];
                    return $this->to($res, $here);
                }
                if ($for === 'admin' || $for === 'judge') {
                    $o = Roles::open($user, $for);
                    if (!$o['ok']) return $this->back($res, '/account', (string) ($o['error'] ?? 'That sign-in could not be opened.'));
                    return $this->to($res, $next ?? (string) $o['to']);
                }
                $_SESSION['flash_notice'] = 'Confirmed for ' . Roles::CONFIRM_MINUTES . ' minutes. Press the button again to go ahead.';
                return $this->to($res, $next ?? '/admin');
            default:
                return $this->to($res, $here);
        }
    }

    /** Only a path on this site, inside the places a switch or a confirmation may return to. */
    private function safe(string $p): ?string
    {
        $p = trim($p);
        if ($p === '' || $p[0] !== '/' || str_starts_with($p, '//') || str_contains($p, '\\') || preg_match('/[\x00-\x20\x7F]/', $p)) return null;
        return preg_match('~^/(account|volunteer|admin|org|judge)(/|\?|$)~', $p) ? $p : null;
    }

    private function masked(string $email): string
    {
        [$a, $d] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($a, 0, 1) . str_repeat('•', max(1, mb_strlen($a) - 1)) . '@' . $d;
    }

    private function ip(Request $req): string
    {
        return (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    private function back(Response $res, string $to, string $msg): Response
    {
        $_SESSION['flash_error'] = $msg;
        return $this->to($res, $to);
    }

    private function to(Response $res, string $url): Response
    {
        return $res->withHeader('Location', $url)->withStatus(303);
    }
}
