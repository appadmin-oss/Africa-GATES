<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Admin\Services\{AuthService, LogService};
use AfricaGates\Services\OtpService;
use AfricaGates\Services\RateLimitService;

class AuthController
{
    public function __construct(
        private readonly Twig        $view,
        private readonly AuthService $auth,
        private readonly LogService  $log,
        private readonly ?OtpService $otp = null,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    public function loginForm(Request $req, Response $res): Response
    {
        if (!empty($_SESSION['admin_id'])) {
            return $res->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        return $this->view->render($res, 'admin/login.twig', [
            'page_title' => 'Admin Login — Africa GATES',
            'next' => $req->getQueryParams()['next'] ?? '/admin/dashboard',
            'magic_sent' => $req->getQueryParams()['magic'] ?? null,
            'error' => $_SESSION['flash_error'] ?? null,
            'notice' => $_SESSION['flash_notice'] ?? null,
        ]);
    }

    public function loginSubmit(Request $req, Response $res): Response
    {
        $b = (array)$req->getParsedBody();
        $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
        $next = (string)($b['next'] ?? '/admin/dashboard');
        if (!str_starts_with($next, '/admin/')) $next = '/admin/dashboard';

        $admin = $this->auth->attemptLogin((string)($b['email'] ?? ''), (string)($b['password'] ?? ''), $ip);
        if (!$admin) {
            $_SESSION['flash_error'] = 'Invalid credentials, or account locked. Try again or use a magic link.';
            return $res->withHeader('Location', '/admin/login')->withStatus(302);
        }
        $this->auth->startSession($admin);
        unset($_SESSION['flash_error'], $_SESSION['flash_notice']);
        return $res->withHeader('Location', $next)->withStatus(302);
    }

    public function magicForm(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'admin/magic.twig', [
            'page_title' => 'Sign in with a link — Africa GATES',
            'notice' => $_SESSION['flash_notice'] ?? null,
            'error'  => $_SESSION['flash_error']  ?? null,
        ]);
    }

    public function magicRequest(Request $req, Response $res): Response
    {
        $b = (array)$req->getParsedBody();
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
        unset($_SESSION['flash_error'], $_SESSION['flash_notice']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email.';
            return $res->withHeader('Location', '/admin/magic')->withStatus(302);
        }
        // Anti mail-bomb: cap per IP (5/hr) and per email (3/hr). On limit, show
        // the SAME neutral notice (don't reveal throttling or email existence)
        // and send nothing.
        if ($this->rateLimit
            && (!$this->rateLimit->check(hash('sha256', $ip), 'admin_magic_ip', 5, 3600)
             || !$this->rateLimit->check(hash('sha256', $email), 'admin_magic_email', 3, 3600))) {
            $this->log->warn('admin.magic.throttled', ['email' => $email]);
            $_SESSION['flash_notice'] = 'If that email belongs to an active admin, we just sent a sign-in link valid for 15 minutes.';
            return $res->withHeader('Location', '/admin/login?magic=sent')->withStatus(302);
        }
        $admin = $this->auth->findByEmail($email);
        if ($admin) {
            [$token, $expires] = $this->auth->createMagicLink($email, 'admin_login', $ip);
            $appUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
            $link = ($appUrl ?: '') . '/admin/magic/consume?token=' . $token;
            $this->log->info('admin.magic.dispatch', ['email' => $email, 'expires' => $expires]);
            $this->sendMagicEmail($email, $link);
        }
        // Always show the same notice (don't reveal whether the email exists)
        $_SESSION['flash_notice'] = 'If that email belongs to an active admin, we just sent a sign-in link valid for 15 minutes.';
        return $res->withHeader('Location', '/admin/login?magic=sent')->withStatus(302);
    }

    public function magicConsume(Request $req, Response $res): Response
    {
        $token = (string)($req->getQueryParams()['token'] ?? '');
        if ($token === '') {
            $_SESSION['flash_error'] = 'Missing token.';
            return $res->withHeader('Location', '/admin/login')->withStatus(302);
        }
        $admin = $this->auth->consumeMagicLink($token);
        if (!$admin) {
            $_SESSION['flash_error'] = 'Magic link is invalid or expired. Please request a new one.';
            return $res->withHeader('Location', '/admin/login')->withStatus(302);
        }
        $this->auth->startSession($admin);
        return $res->withHeader('Location', '/admin/dashboard')->withStatus(302);
    }

    public function logout(Request $req, Response $res): Response
    {
        $this->auth->logout();
        return $res->withHeader('Location', '/admin/login')->withStatus(302);
    }

    /** Try sending via OtpService's mailer; fall back to a logged copy in dev. */
    private function sendMagicEmail(string $to, string $link): void
    {
        try {
            if ($this->otp) {
                $this->otp->sendCustom($to, 'Your Africa GATES admin sign-in link',
                    "Click here to sign in (valid 15 minutes): $link"
                );
                return;
            }
        } catch (\Throwable $e) {
            $this->log->warn('admin.magic.email_fallback', ['err' => $e->getMessage()]);
        }
        // SECURITY: never write the sign-in link to disk — it carries a live token,
        // which would turn a transient SMTP failure into a credential leak (and the
        // log file could be web-exposed on a misconfigured docroot). Admins can also
        // sign in with their password. Log the failure WITHOUT the token.
        $this->log->warn('admin.magic.email_unavailable', ['to' => $to]);
    }
}
