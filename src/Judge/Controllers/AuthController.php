<?php
declare(strict_types=1);

namespace AfricaGates\Judge\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Services\{OtpService, RateLimitService};
use AfricaGates\Support\Session;

/**
 * Judges sign in by email + a 6-digit code (no passwords).
 * We reuse the existing OtpService transport (Brevo or log fallback).
 */
class AuthController
{
    private const MAX_OTP_ATTEMPTS = 5;

    public function __construct(
        private readonly Twig $view,
        private readonly JudgeService $judges,
        private readonly OtpService $otp,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    private function clientIp(Request $req): string {
        return (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    public function loginForm(Request $req, Response $res): Response
    {
        if (!empty($_SESSION['judge_id'])) {
            return $res->withHeader('Location', '/judge/ballot')->withStatus(302);
        }
        return $this->view->render($res, 'judge/login.twig', [
            'page_title' => 'Judges sign-in — Africa GATES',
            'sent' => $req->getQueryParams()['sent'] ?? null,
            'error' => $_SESSION['flash_error'] ?? null,
            'notice' => $_SESSION['flash_notice'] ?? null,
        ]);
    }

    public function loginRequest(Request $req, Response $res): Response
    {
        unset($_SESSION['flash_error'], $_SESSION['flash_notice']);
        $b = (array)$req->getParsedBody();
        $email = strtolower(trim((string)($b['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email.';
            return $res->withHeader('Location', '/judge/login')->withStatus(302);
        }
        // Per-IP throttle (anti mail-bomb / enumeration) — 5 requests/hour.
        if ($this->rateLimit && !$this->rateLimit->check(hash('sha256', $this->clientIp($req)), 'judge_otp_req', 5, 3600)) {
            $_SESSION['flash_error'] = 'Too many requests. Please try again later.';
            return $res->withHeader('Location', '/judge/login')->withStatus(302);
        }
        $judge = $this->judges->findByEmail($email);
        if ($judge) {
            // Reuse the OtpService — purpose='judge_login'
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::table('gates_otp_tokens')->where('email_hash', hash('sha256', $email))
                ->where('purpose', 'judge_login')->where('is_used', 0)
                ->update(['is_used' => 1]);
            DB::table('gates_otp_tokens')->insert([
                'email_hash' => hash('sha256', $email),
                'token_hash' => hash('sha256', $code),
                'purpose'    => 'judge_login',
                'nominee_id' => $judge->id,
                'award_id'   => 0,
                'attempts'   => 0,
                'is_used'    => 0,
                'expires_at' => Carbon::now()->addMinutes(15)->toDateTimeString(),
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
            $this->otp->sendCustom(
                $email,
                'Your Africa GATES judges sign-in code: ' . $code,
                "Hello {$judge->name},\n\nYour 6-digit sign-in code is: $code\n\nIt expires in 15 minutes."
            );
        }
        $_SESSION['judge_login_email'] = $email; // remember for the verify step
        $_SESSION['flash_notice'] = 'If that email belongs to an active judge, a 6-digit code is on the way.';
        return $res->withHeader('Location', '/judge/login?sent=1')->withStatus(302);
    }

    public function loginVerify(Request $req, Response $res): Response
    {
        $b = (array)$req->getParsedBody();
        $email = strtolower(trim((string)($b['email'] ?? ($_SESSION['judge_login_email'] ?? ''))));
        $code = trim((string)($b['otp'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $_SESSION['flash_error'] = 'Code must be 6 digits.';
            return $res->withHeader('Location', '/judge/login?sent=1')->withStatus(302);
        }
        // Per-IP throttle on verification attempts (anti brute-force).
        if ($this->rateLimit && !$this->rateLimit->check(hash('sha256', $this->clientIp($req)), 'judge_otp_verify', 10, 3600)) {
            $_SESSION['flash_error'] = 'Too many attempts. Please try again later.';
            return $res->withHeader('Location', '/judge/login?sent=1')->withStatus(302);
        }
        $hash = hash('sha256', $email);
        $tok = DB::table('gates_otp_tokens')->where('email_hash', $hash)
            ->where('purpose', 'judge_login')->where('is_used', 0)
            ->where('expires_at', '>', Carbon::now()->toDateTimeString())
            ->orderByDesc('id')->first();
        if (!$tok) {
            $_SESSION['flash_error'] = 'Invalid or expired code. Try again or request a new one.';
            return $res->withHeader('Location', '/judge/login?sent=1')->withStatus(302);
        }
        // Count the attempt; invalidate the code after too many guesses.
        DB::table('gates_otp_tokens')->where('id', $tok->id)->increment('attempts');
        if (((int)$tok->attempts + 1) > self::MAX_OTP_ATTEMPTS) {
            DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
            $_SESSION['flash_error'] = 'Too many attempts. Request a new code.';
            return $res->withHeader('Location', '/judge/login?sent=1')->withStatus(302);
        }
        // Constant-time comparison; do NOT burn the code on a wrong guess.
        if (!hash_equals((string)$tok->token_hash, hash('sha256', $code))) {
            $_SESSION['flash_error'] = 'Invalid or expired code. Try again or request a new one.';
            return $res->withHeader('Location', '/judge/login?sent=1')->withStatus(302);
        }
        DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
        $judge = $this->judges->findByEmail($email);
        if (!$judge) {
            $_SESSION['flash_error'] = 'Your judge account is not active. Contact the admin.';
            return $res->withHeader('Location', '/judge/login')->withStatus(302);
        }
        // Defeat session fixation: rotate the id before writing identity.
        Session::rotate();
        $_SESSION['judge_id']    = (int)$judge->id;
        $_SESSION['judge_name']  = $judge->name;
        $_SESSION['judge_email'] = $judge->email;
        $_SESSION['csrf_token']  = bin2hex(random_bytes(32));
        unset($_SESSION['judge_login_email']);
        return $res->withHeader('Location', '/judge/ballot')->withStatus(302);
    }

    public function logout(Request $req, Response $res): Response
    {
        unset($_SESSION['judge_id'], $_SESSION['judge_name'], $_SESSION['judge_email']);
        return $res->withHeader('Location', '/judge/login')->withStatus(302);
    }
}
