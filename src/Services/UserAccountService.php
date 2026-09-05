<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Session;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Public user accounts (gates_users) — distinct from admins/judges. Members register
 * to accumulate voting points (see {@see PointsService}). Sign-in is password OR a
 * one-time email code (the controller owns the OTP token flow, mirroring judges).
 */
final class UserAccountService
{
    public function findByEmail(string $email): ?object
    {
        $row = DB::table('gates_users')->where('email', strtolower(trim($email)))->where('status', 'active')->first();
        return $row ?: null;
    }

    public function findById(int $id): ?object
    {
        $row = DB::table('gates_users')->where('id', $id)->first();
        return $row ?: null;
    }

    /** Create an account. Returns ['ok'=>bool, 'id'=>?int, 'error'=>?string]. */
    public function register(string $name, string $email, string $phone, ?string $password): array
    {
        $name  = trim($name);
        $email = strtolower(trim($email));
        $phone = trim($phone);

        if ($name === '' || !preg_match('/\S+\s+\S+/u', $name)) return ['ok' => false, 'error' => 'Please enter your full name (first and last).'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))          return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        if (\AfricaGates\Support\DisposableEmail::isDisposable($email)) return ['ok' => false, 'error' => 'Please use a permanent email address — disposable inboxes are not accepted.'];
        if (strlen((string) preg_replace('/\D+/', '', $phone)) < 7) return ['ok' => false, 'error' => 'Please enter a valid phone number.'];
        if ($password !== null && $password !== '' && strlen($password) < 8) return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        if (DB::table('gates_users')->where('email', $email)->exists()) return ['ok' => false, 'error' => 'An account with that email already exists — please sign in.'];

        $id = (int) DB::table('gates_users')->insertGetId([
            'name'          => mb_substr($name, 0, 160),
            'email'         => $email,
            'phone'         => mb_substr($phone, 0, 40),
            'password_hash' => ($password !== null && $password !== '') ? password_hash($password, PASSWORD_BCRYPT) : null,
            'points'        => 0,
            'status'        => 'active',
            'email_verified'=> 0,
            'created_at'    => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** Verify password; null on failure (with timing equalisation for unknown emails). */
    public function attemptLogin(string $email, string $password): ?object
    {
        $u = $this->findByEmail($email);
        if (!$u) { password_verify($password, '$2y$10$' . str_repeat('.', 53)); return null; }
        if (empty($u->password_hash) || !password_verify($password, (string) $u->password_hash)) return null;
        return $u;
    }

    public function startSession(object $u, string $ip = ''): void
    {
        Session::rotate(); // defeat session fixation
        $_SESSION['user_id']    = (int) $u->id;
        $_SESSION['user_name']  = $u->name;
        $_SESSION['user_email'] = $u->email;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        DB::table('gates_users')->where('id', $u->id)->update([
            'last_login_at' => Carbon::now()->toDateTimeString(),
            'last_login_ip' => $ip ? hash('sha256', $ip) : null,
        ]);
    }

    public function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);
    }

    public function current(): ?object
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        return $id ? $this->findById($id) : null;
    }

    /**
     * Contact details of the signed-in member, for OPT-IN form autofill
     * (nomination / voting / RSVP). Static so any controller can offer the
     * "use my profile details" control without DI churn; null for guests.
     * Fresh DB read (not session copies) so a just-edited phone is honoured.
     *
     * @return array{id:int,name:string,email:string,phone:string}|null
     */
    public static function memberForForms(): ?array
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id < 1) return null;
        try {
            $u = DB::table('gates_users')->where('id', $id)->where('status', 'active')->first();
        } catch (\Throwable) {
            return null;
        }
        if (!$u) return null;
        return [
            'id'    => (int) $u->id,
            'name'  => (string) $u->name,
            'email' => (string) $u->email,
            'phone' => (string) ($u->phone ?? ''),
        ];
    }

    /** Update a member's name + phone. Refreshes the session label. */
    public function updateProfile(int $userId, string $name, string $phone): array
    {
        $name = trim($name);
        $phone = trim($phone);
        if ($name === '' || !preg_match('/\S+\s+\S+/u', $name)) return ['ok' => false, 'error' => 'Please enter your full name (first and last).'];
        if (strlen((string) preg_replace('/\D+/', '', $phone)) < 7) return ['ok' => false, 'error' => 'Please enter a valid phone number.'];
        DB::table('gates_users')->where('id', $userId)->update(['name' => mb_substr($name, 0, 160), 'phone' => mb_substr($phone, 0, 40)]);
        if ((int) ($_SESSION['user_id'] ?? 0) === $userId) $_SESSION['user_name'] = $name;
        return ['ok' => true];
    }

    public function setPassword(int $userId, string $password): bool
    {
        if (strlen($password) < 8) return false;
        DB::table('gates_users')->where('id', $userId)->update(['password_hash' => password_hash($password, PASSWORD_BCRYPT)]);
        return true;
    }

    /* ── Email verification ──────────────────────────────────────────────────
       Reuses gates_otp_tokens (purpose 'verify_email') so no schema change is
       needed; only the SHA-256 hash of the token is stored, never the raw value. */

    /** True when the account's email has been confirmed. */
    public function isVerified(object $u): bool
    {
        return (int) ($u->email_verified ?? 0) === 1;
    }

    /**
     * Issue a single-use, 24-hour email-verification token. Invalidates any prior
     * unused token for the email, then returns the RAW token for the verify link
     * (or null on bad input). The raw token is never persisted.
     */
    public function issueEmailVerification(int $userId, string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        $eh = hash('sha256', $email);
        DB::table('gates_otp_tokens')->where('email_hash', $eh)->where('purpose', 'verify_email')
            ->where('is_used', 0)->update(['is_used' => 1]);
        $raw = bin2hex(random_bytes(20));
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => $eh,
            'token_hash' => hash('sha256', $raw),
            'purpose'    => 'verify_email',
            'nominee_id' => $userId,   // reused column: the account id to activate
            'award_id'   => 0,
            'attempts'   => 0,
            'is_used'    => 0,
            'expires_at' => Carbon::now()->addHours(24)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        return $raw;
    }

    /**
     * Consume a verification token: marks it used and flips the account verified.
     * Returns the now-verified user, or null when the token is invalid/expired.
     */
    public function verifyEmailToken(string $token): ?object
    {
        $token = trim($token);
        if ($token === '') return null;
        $tok = DB::table('gates_otp_tokens')
            ->where('token_hash', hash('sha256', $token))->where('purpose', 'verify_email')
            ->where('is_used', 0)->where('expires_at', '>', Carbon::now()->toDateTimeString())
            ->orderByDesc('id')->first();
        if (!$tok) return null;
        DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
        $user = $this->findById((int) $tok->nominee_id);
        if (!$user) return null;
        DB::table('gates_users')->where('id', $user->id)->update(['email_verified' => 1]);
        $user->email_verified = 1;
        return $user;
    }

    /** Flip an account to verified (e.g. after a successful one-time-code login). */
    public function markVerified(int $userId): void
    {
        DB::table('gates_users')->where('id', $userId)->update(['email_verified' => 1]);
    }
}
