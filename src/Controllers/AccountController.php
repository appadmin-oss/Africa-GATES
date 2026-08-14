<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\{UserAccountService, OtpService, RateLimitService, PointsService, CommunityService};

/**
 * Public member accounts. Members register to accumulate voting points (earned from
 * purchases) and redeem them for votes. Sign-in is password OR a one-time email code
 * (same transport as judges). The /account dashboard is gated by UserAuthMiddleware.
 */
class AccountController
{
    private const MAX_OTP_ATTEMPTS = 5;

    public function __construct(
        private readonly Twig $view,
        private readonly UserAccountService $accounts,
        private readonly ?OtpService $otp = null,
        private readonly ?RateLimitService $rateLimit = null,
        private readonly ?CommunityService $community = null,
    ) {}

    private function ip(Request $req): string { return (string) ($req->getServerParams()['REMOTE_ADDR'] ?? ''); }

    private function flash(string $key): ?string { $v = $_SESSION[$key] ?? null; unset($_SESSION[$key]); return $v; }

    /**
     * A safe local redirect target for post-login. Only same-site absolute paths
     * are allowed — never a scheme, host, or protocol-relative (`//evil.com`) URL,
     * which would turn login into an open redirect. Also refuses auth pages so we
     * don't bounce a freshly-signed-in user back to a login screen.
     */
    private function safeNext(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw[0] !== '/' || str_starts_with($raw, '//') || str_contains($raw, "\\")) return null;
        $path = parse_url($raw, PHP_URL_PATH) ?: '';
        if (str_starts_with($path, '/account/login') || str_starts_with($path, '/account/register') || str_starts_with($path, '/account/verify')) return null;
        return $raw;
    }

    /** Consume the stored post-login destination, defaulting to the dashboard. */
    private function nextTarget(): string
    {
        $t = $this->safeNext($_SESSION['login_next'] ?? null);
        unset($_SESSION['login_next']);
        return $t ?? '/account';
    }

    /**
     * Welcome email on FIRST verification: the account is now usable, so show
     * the member what they can actually do (vote, nominate, community, points)
     * instead of leaving them at a dead end. Best-effort, never blocks login.
     */
    private function sendWelcome(Request $req, object $user): void
    {
        if (!$this->otp) return;
        $base = \AfricaGates\Support\SiteUrl::base($req);
        $esc  = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $btn  = static fn(string $href, string $label) => '<a href="' . $href . '" style="display:inline-block;background:#237b22;color:#fff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:999px;margin:0 6px 8px 0">' . $label . '</a>';
        $html = '<p>Hi ' . $esc($user->name) . ',</p>'
            . '<p>Your Africa GATES account is verified — welcome. Here&rsquo;s what your membership unlocks right now:</p>'
            . '<ul style="margin:0 0 14px;padding-left:18px;line-height:1.8;color:#10292c;font-size:14px">'
            . '<li><strong>Vote</strong> for the nominees shaping African culture — one verified vote per category.</li>'
            . '<li><strong>Nominate</strong> someone extraordinary, and share a link so others can second them.</li>'
            . '<li><strong>Earn voting points</strong> from shop purchases, tickets and donations, then redeem them at the ballot.</li>'
            . '<li><strong>Join the community</strong> — threads, polls and the people behind the platform.</li>'
            . '</ul>'
            . '<p>' . $btn($esc($base . '/vote'), 'Explore the ballot →') . $btn($esc($base . '/nominate'), 'Nominate someone') . $btn($esc($base . '/community'), 'Join the community') . '</p>'
            . '<p style="color:#6b7674;font-size:13px">Your dashboard at <a href="' . $esc($base . '/account') . '" style="color:#237b22;font-weight:600">' . $esc($base ?: '') . '/account</a> tracks your votes, nominations, points and share links.</p>';
        try { $this->otp->sendBranded((string) $user->email, 'Welcome to Africa GATES — your account is live', $html, strip_tags($html), 'Membership'); } catch (\Throwable) {}
    }

    // ── Register ────────────────────────────────────────────────────────────
    public function registerForm(Request $req, Response $res): Response
    {
        if (!empty($_SESSION['user_id'])) return $res->withHeader('Location', '/account')->withStatus(302);
        return $this->view->render($res, 'pages/account/register.twig', [
            'page_title' => 'Create your account — Africa GATES', 'gates_page' => 'account', 'has_hero' => false,
            'hide_chrome' => true, 'error' => $this->flash('flash_error'), 'old' => $this->flash('reg_old') ?? [],
        ]);
    }

    public function registerSubmit(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $r = $this->accounts->register((string) ($b['name'] ?? ''), (string) ($b['email'] ?? ''), (string) ($b['phone'] ?? ''), (string) ($b['password'] ?? '') ?: null);
        if (!$r['ok']) {
            $_SESSION['flash_error'] = $r['error'];
            $_SESSION['reg_old'] = ['name' => $b['name'] ?? '', 'email' => $b['email'] ?? '', 'phone' => $b['phone'] ?? ''];
            return $res->withHeader('Location', '/account/register')->withStatus(302);
        }
        // Email verification: do NOT auto-login. Send a confirmation link and park
        // the visitor on the "check your email" notice until they verify.
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $delivered = $this->sendVerification($req, (int) $r['id'], $email, (string) ($b['name'] ?? ''));
        $_SESSION['pending_verify_email'] = $email;
        $_SESSION['flash_notice'] = $delivered
            ? 'Almost there — check your inbox to confirm your email.'
            : 'Your account was created, but we could not send the confirmation email just now — use "Resend link" below in a minute, or contact support if it keeps failing.';
        \AfricaGates\Services\WebhookService::dispatch('member.registered', [
            'member_id'  => (int) $r['id'],
            'email_hash' => hash('sha256', $email),
        ]);
        return $res->withHeader('Location', '/account/verify')->withStatus(302);
    }

    // ── Email verification ────────────────────────────────────────────────────
    /** GET /account/verify — with ?token=… verifies + logs in; otherwise shows the notice. */
    public function verifyEmail(Request $req, Response $res): Response
    {
        $token = trim((string) ($req->getQueryParams()['token'] ?? ''));
        if ($token !== '') {
            $user = $this->accounts->verifyEmailToken($token);
            if ($user) {
                $this->accounts->startSession($user, $this->ip($req));
                $_SESSION['flash_ok'] = 'Your email is verified — welcome to Africa GATES.';
                \AfricaGates\Services\WebhookService::dispatch('member.verified', [
                    'member_id'  => (int) $user->id,
                    'email_hash' => hash('sha256', strtolower((string) $user->email)),
                ]);
                $this->sendWelcome($req, $user);
                return $res->withHeader('Location', $this->nextTarget())->withStatus(302);
            }
            $_SESSION['flash_error'] = 'That verification link is invalid or has expired — request a fresh one below.';
        }
        return $this->view->render($res, 'pages/account/verify-notice.twig', [
            'page_title' => 'Verify your email — Africa GATES', 'gates_page' => 'account', 'has_hero' => false, 'hide_chrome' => true,
            'email'  => $_SESSION['pending_verify_email'] ?? '',
            'error'  => $this->flash('flash_error'), 'notice' => $this->flash('flash_notice'),
        ]);
    }

    /** POST /account/verify/resend — re-issue a verification link (rate-limited, no enumeration). */
    public function resendVerification(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $email = strtolower(trim((string) ($b['email'] ?? ($_SESSION['pending_verify_email'] ?? ''))));
        if ($this->rateLimit && $email !== '' && !$this->rateLimit->check(hash('sha256', $email), 'verify_resend', 4, 3600)) {
            $_SESSION['flash_notice'] = 'If that account still needs verifying, a new link is on the way.';
            return $res->withHeader('Location', '/account/verify')->withStatus(302);
        }
        $user = $email !== '' ? $this->accounts->findByEmail($email) : null;
        $failed = false;
        if ($user && !$this->accounts->isVerified($user)) {
            $failed = !$this->sendVerification($req, (int) $user->id, $email, (string) $user->name);
        }
        $_SESSION['pending_verify_email'] = $email;
        // Keep the enumeration-safe phrasing, but never fake success when the
        // mailer itself reported a delivery failure.
        $_SESSION['flash_notice'] = $failed
            ? 'Our email service is having trouble right now — please try again in a few minutes.'
            : 'If that account still needs verifying, a new link is on the way.';
        return $res->withHeader('Location', '/account/verify')->withStatus(302);
    }

    /**
     * Send the email-verification link. Returns FALSE when delivery failed so
     * callers can tell the user the truth instead of "check your inbox" for a
     * message that never left the building.
     */
    private function sendVerification(Request $req, int $userId, string $email, string $name): bool
    {
        if (!$this->otp || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $raw = $this->accounts->issueEmailVerification($userId, $email);
        if ($raw === null) return false;
        $base = \AfricaGates\Support\SiteUrl::base($req);
        $link = $base . '/account/verify?token=' . urlencode($raw);
        $nm   = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');
        $html = "<p>Hello <strong>{$nm}</strong>,</p>"
            . "<p>Welcome to Africa GATES. Confirm your email to activate your account — this link expires in 24 hours.</p>"
            . "<p style=\"text-align:center;margin:26px 0\"><a href=\"{$link}\" style=\"display:inline-block;padding:13px 30px;background:#10292C;color:#fff;border-radius:999px;font-weight:700;text-decoration:none\">Verify my email &rarr;</a></p>"
            . "<p style=\"font-size:13px;color:#92a6a7;word-break:break-all\">If the button doesn't work, paste this into your browser:<br>{$link}</p>"
            . "<p style=\"font-size:13px;color:#92a6a7\">Didn't create an account? Ignore this email.</p>";
        try {
            $r = $this->otp->sendBranded($email, 'Confirm your Africa GATES email', $html,
                "Confirm your email to activate your Africa GATES account:\n{$link}\n\nThis link expires in 24 hours.",
                'Accounts', $base . '/assets/img/illustrations/illo-envelope.jpg');
            return (bool) ($r['success'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Login (password) ────────────────────────────────────────────────────
    public function loginForm(Request $req, Response $res): Response
    {
        if (!empty($_SESSION['user_id'])) return $res->withHeader('Location', $this->nextTarget())->withStatus(302);
        // Remember where the visitor was headed (e.g. a gate redirected them here)
        // so every sign-in path — password, code, or email verify — can return them.
        $next = $this->safeNext($req->getQueryParams()['next'] ?? null);
        if ($next !== null) $_SESSION['login_next'] = $next;
        return $this->view->render($res, 'pages/account/login.twig', [
            'page_title' => 'Sign in — Africa GATES', 'gates_page' => 'account', 'has_hero' => false, 'hide_chrome' => true,
            'sent'  => $req->getQueryParams()['sent'] ?? null,
            'error' => $this->flash('flash_error'), 'notice' => $this->flash('flash_notice'),
        ]);
    }

    public function loginSubmit(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $ip = $this->ip($req);
        if ($this->rateLimit && $ip !== '' && !$this->rateLimit->check(hash('sha256', $ip), 'user_login_ip', 12, 3600)) {
            $_SESSION['flash_error'] = 'Too many attempts. Please try again later.';
            return $res->withHeader('Location', '/account/login')->withStatus(302);
        }
        $user = $this->accounts->attemptLogin((string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''));
        if (!$user) {
            $_SESSION['flash_error'] = 'Invalid email or password — or try a sign-in code.';
            return $res->withHeader('Location', '/account/login')->withStatus(302);
        }
        // Unverified accounts must confirm their email first. (A one-time sign-in
        // code — /account/login/otp — proves email ownership and verifies them.)
        if (!$this->accounts->isVerified($user)) {
            $delivered = $this->sendVerification($req, (int) $user->id, (string) $user->email, (string) $user->name);
            $_SESSION['pending_verify_email'] = (string) $user->email;
            $_SESSION['flash_notice'] = $delivered
                ? 'Please confirm your email first — we just sent you a fresh link.'
                : 'Please confirm your email first — but our email service is having trouble right now. Try "Resend link" in a minute.';
            return $res->withHeader('Location', '/account/verify')->withStatus(302);
        }
        $this->accounts->startSession($user, $ip);
        return $res->withHeader('Location', $this->nextTarget())->withStatus(302);
    }

    // ── Login (one-time email code) ───────────────────────────────────────────
    public function otpRequest(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email.';
            return $res->withHeader('Location', '/account/login')->withStatus(302);
        }
        $ip = $this->ip($req);
        if ($this->rateLimit && (!$this->rateLimit->check(hash('sha256', $ip), 'user_otp_ip', 5, 3600)
            || !$this->rateLimit->check(hash('sha256', $email), 'user_otp_email', 3, 3600))) {
            $_SESSION['flash_notice'] = 'If that email has an account, a 6-digit code is on the way.';
            return $res->withHeader('Location', '/account/login?sent=1')->withStatus(302);
        }
        $user = $this->accounts->findByEmail($email);
        if ($user && $this->otp) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::table('gates_otp_tokens')->where('email_hash', hash('sha256', $email))->where('purpose', 'user_login')->where('is_used', 0)->update(['is_used' => 1]);
            DB::table('gates_otp_tokens')->insert([
                'email_hash' => hash('sha256', $email), 'token_hash' => hash('sha256', $code), 'purpose' => 'user_login',
                'nominee_id' => (int) $user->id, 'award_id' => 0, 'attempts' => 0, 'is_used' => 0,
                'expires_at' => Carbon::now()->addMinutes(15)->toDateTimeString(), 'created_at' => Carbon::now()->toDateTimeString(),
            ]);
            $nm = htmlspecialchars((string) $user->name, ENT_QUOTES, 'UTF-8');
            $html = "<p>Hello <strong>{$nm}</strong>,</p><p>Your Africa GATES sign-in code is below — it expires in 15 minutes.</p>"
                . "<div style=\"font:700 34px/1 'JetBrains Mono',monospace;letter-spacing:.3em;color:#10292C;margin:18px 0\">{$code}</div>"
                . "<p style=\"font-size:13px;color:#92a6a7\">Didn't request this? Ignore this email.</p>";
            // Surface delivery failure honestly — the previous version discarded
            // this result and told the user "code sent" while nothing left the
            // building. (Member-account existence is already discoverable via
            // registration, so this does not open a new enumeration channel.)
            $sendFailed = false;
            try {
                $r = $this->otp->sendBranded($email, 'Your Africa GATES sign-in code', $html, "Your sign-in code is {$code} (valid 15 minutes).", 'Accounts');
                $sendFailed = !($r['success'] ?? false);
            } catch (\Throwable $e) { $sendFailed = true; }
            if ($sendFailed) {
                $_SESSION['user_login_email'] = $email;
                $_SESSION['flash_error'] = 'We could not send your sign-in code — our email service is having trouble. Try again in a few minutes' . (!empty($user->password_hash) ? ', or sign in with your password' : '') . '.';
                return $res->withHeader('Location', '/account/login')->withStatus(302);
            }
        }
        $_SESSION['user_login_email'] = $email;
        $_SESSION['flash_notice'] = 'If that email has an account, a 6-digit code is on the way.';
        return $res->withHeader('Location', '/account/login?sent=1')->withStatus(302);
    }

    public function otpVerify(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $email = strtolower(trim((string) ($b['email'] ?? ($_SESSION['user_login_email'] ?? ''))));
        $code  = trim((string) ($b['otp'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $_SESSION['flash_error'] = 'Code must be 6 digits.';
            return $res->withHeader('Location', '/account/login?sent=1')->withStatus(302);
        }
        if ($this->rateLimit && !$this->rateLimit->check(hash('sha256', $this->ip($req)), 'user_otp_verify', 10, 3600)) {
            $_SESSION['flash_error'] = 'Too many attempts. Please try again later.';
            return $res->withHeader('Location', '/account/login?sent=1')->withStatus(302);
        }
        $tok = DB::table('gates_otp_tokens')->where('email_hash', hash('sha256', $email))->where('purpose', 'user_login')
            ->where('is_used', 0)->where('expires_at', '>', Carbon::now()->toDateTimeString())->orderByDesc('id')->first();
        if (!$tok) {
            $_SESSION['flash_error'] = 'Invalid or expired code. Request a new one.';
            return $res->withHeader('Location', '/account/login?sent=1')->withStatus(302);
        }
        DB::table('gates_otp_tokens')->where('id', $tok->id)->increment('attempts');
        if (((int) $tok->attempts + 1) > self::MAX_OTP_ATTEMPTS) {
            DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
            $_SESSION['flash_error'] = 'Too many attempts. Request a new code.';
            return $res->withHeader('Location', '/account/login?sent=1')->withStatus(302);
        }
        if (!hash_equals((string) $tok->token_hash, hash('sha256', $code))) {
            $_SESSION['flash_error'] = 'Invalid or expired code. Try again.';
            return $res->withHeader('Location', '/account/login?sent=1')->withStatus(302);
        }
        DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
        $user = $this->accounts->findByEmail($email);
        if (!$user) {
            $_SESSION['flash_error'] = 'No active account for that email. Create one first.';
            return $res->withHeader('Location', '/account/register')->withStatus(302);
        }
        // A successful one-time code proves the user controls this inbox, so it
        // also satisfies email verification (covers members created pre-verification).
        if (!$this->accounts->isVerified($user)) {
            $this->accounts->markVerified((int) $user->id);
            \AfricaGates\Services\WebhookService::dispatch('member.verified', [
                'member_id'  => (int) $user->id,
                'email_hash' => hash('sha256', strtolower((string) $user->email)),
            ]);
            $this->sendWelcome($req, $user);
        }
        unset($_SESSION['user_login_email']);
        $this->accounts->startSession($user, $this->ip($req));
        return $res->withHeader('Location', $this->nextTarget())->withStatus(302);
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────
    public function dashboard(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $res->withHeader('Location', '/account/login')->withStatus(302);
        // Activity aggregation (read-only): what this member has DONE on the
        // platform — votes, nominations, share links, community — plus the
        // onboarding checklist that always gives a fresh account a next step.
        $votes       = \AfricaGates\Services\MemberActivityService::votesFor((string) $user->email, 15);
        $nominations = \AfricaGates\Services\MemberActivityService::nominationsFor((string) $user->email, 15);
        $shareLinks  = \AfricaGates\Services\MemberActivityService::shareLinksFor((int) $user->id, 8);
        $communityC  = \AfricaGates\Services\MemberActivityService::communityCountsFor((string) $user->email);
        return $this->view->render($res, 'pages/account/dashboard.twig', [
            'page_title' => 'Your account — Africa GATES', 'gates_page' => 'account', 'has_hero' => false,
            'user'           => (array) $user,
            'points'         => PointsService::balance((int) $user->id),
            'points_enabled' => PointsService::enabled(),
            'points_per_vote'=> PointsService::pointsPerVote(),
            'redeemable'     => PointsService::votesForPoints(PointsService::balance((int) $user->id)),
            'summary'        => PointsService::summary((int) $user->id),
            'ledger'         => PointsService::ledger((int) $user->id, 30),
            'bookmarks'      => $this->community ? $this->community->bookmarkedThreads((int) $user->id, 12) : [],
            'my_votes'       => $votes,
            'my_nominations' => $nominations,
            'my_links'       => $shareLinks,
            // What they BOUGHT — absent until now. The dashboard was an accurate picture of
            // everything a member had contributed and said nothing about anything they had
            // paid for, so the only route to "has my order shipped" was a link in an email.
            'my_orders'      => \AfricaGates\Services\MemberActivityService::ordersFor((string) $user->email, 10),
            'my_tickets'     => \AfricaGates\Services\MemberActivityService::ticketsFor((string) $user->email, 10),
            'community_counts' => $communityC,
            'completeness'   => \AfricaGates\Services\MemberActivityService::completeness($user),
            'checklist'      => \AfricaGates\Services\MemberActivityService::checklist($user, $votes, $nominations, $communityC),
            'flash_ok'       => $this->flash('flash_ok'), 'flash_error' => $this->flash('flash_error'),
        ]);
    }

    /** POST /account/profile — update name/phone + optional password change. */
    public function profileUpdate(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $res->withHeader('Location', '/account/login')->withStatus(302);
        $b = (array) $req->getParsedBody();

        $r = $this->accounts->updateProfile((int) $user->id, (string) ($b['name'] ?? ''), (string) ($b['phone'] ?? ''));
        if (!$r['ok']) {
            $_SESSION['flash_error'] = $r['error'];
            return $res->withHeader('Location', '/account')->withStatus(302);
        }

        // Optional password change — require the current password when one is already set.
        $newPw = (string) ($b['new_password'] ?? '');
        if ($newPw !== '') {
            if (strlen($newPw) < 8) {
                $_SESSION['flash_error'] = 'New password must be at least 8 characters.';
                return $res->withHeader('Location', '/account')->withStatus(302);
            }
            if (!empty($user->password_hash) && !password_verify((string) ($b['current_password'] ?? ''), (string) $user->password_hash)) {
                $_SESSION['flash_error'] = 'Your current password is incorrect.';
                return $res->withHeader('Location', '/account')->withStatus(302);
            }
            $this->accounts->setPassword((int) $user->id, $newPw);
        }

        $_SESSION['flash_ok'] = 'Your account was updated.';
        return $res->withHeader('Location', '/account')->withStatus(302);
    }

    public function logout(Request $req, Response $res): Response
    {
        $this->accounts->logout();
        return $res->withHeader('Location', '/')->withStatus(302);
    }

    /** POST /account/redeem — spend points for one vote on a nominee (JSON). */
    public function redeem(Request $req, Response $res): Response
    {
        $json = function (array $p, int $code = 200) use ($res): Response {
            $res->getBody()->write(json_encode($p));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        };
        $user = $this->accounts->current();
        if (!$user) return $json(['ok' => false, 'message' => 'Please sign in to redeem points.'], 401);
        $b = (array) $req->getParsedBody();
        $r = PointsService::redeemForVote((int) $user->id, (int) ($b['nominee_id'] ?? 0));
        if ($r['ok'] ?? false) {
            \AfricaGates\Services\WebhookService::dispatch('points.redeemed', [
                'member_id'   => (int) $user->id,
                'nominee_id'  => (int) ($b['nominee_id'] ?? 0),
                'new_balance' => (int) ($r['new_balance'] ?? 0),
            ]);
        }
        return $json($r, $r['ok'] ? 200 : 422);
    }
}
