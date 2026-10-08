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
    /**
     * Every refusal names the FIELD it is about, not only the sentence.
     *
     * A form that answers "Password must be at least 8 characters" and reddens the
     * email box is worse than one that reddens nothing: it sends somebody to correct
     * an address that was right. That was live on `/account/register`, where the
     * template's `{% if error %}` marked the email input for ANY failure — under a
     * comment correctly describing it as "the one failure that has a field to blame".
     *
     * The key is additive, so the 20-odd callers reading `error` alone are unaffected.
     * A new refusal added here without a `field` degrades to the summary rather than
     * accusing the wrong box, which is the right failure direction.
     */
    public function register(string $name, string $email, string $phone, ?string $password): array
    {
        $name  = trim($name);
        $email = strtolower(trim($email));
        $phone = trim($phone);

        if (!self::isFullName($name)) return ['ok' => false, 'field' => 'name', 'error' => self::NAME_RULE];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))          return ['ok' => false, 'field' => 'email', 'error' => 'Please enter a valid email address.'];
        if (\AfricaGates\Support\DisposableEmail::isDisposable($email)) return ['ok' => false, 'field' => 'email', 'error' => 'Please use a permanent email address — disposable inboxes are not accepted.'];
        if (strlen((string) preg_replace('/\D+/', '', $phone)) < 7) return ['ok' => false, 'field' => 'phone', 'error' => 'Please enter a valid phone number.'];
        if ($password !== null && $password !== '' && strlen($password) < 8) return ['ok' => false, 'field' => 'password', 'error' => 'Password must be at least 8 characters.'];
        if (DB::table('gates_users')->where('email', $email)->exists()) return ['ok' => false, 'field' => 'email', 'error' => 'An account with that email already exists — please sign in.'];

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

    /**
     * THE NAME RULE, ONCE. "First and last", because a ballot, a receipt and a public profile
     * all print it — and the browser's `pattern` on every form that asks for a name is
     * {@see NAME_PATTERN}, which accepts exactly what this accepts (AccountJoinTest samples
     * the two against each other rather than pinning either).
     */
    public const NAME_RULE = 'Please enter your full name (first and last).';

    /** The browser half of {@see isFullName()}: some non-space, a space, some non-space. */
    public const NAME_PATTERN = '[\s\S]*\S\s+\S[\s\S]*';

    public static function isFullName(string $name): bool
    {
        $name = trim($name);
        return $name !== '' && (bool) preg_match('/\S+\s+\S+/u', $name);
    }

    // ══ PASSWORDLESS: ONE CODE, BY EMAIL OR BY PHONE (SignIn.dc.html) ═══════════════
    //
    // A code is keyed on the IDENTITY it was sent to, hashed — the email as every other
    // purpose in gates_otp_tokens already does, a phone as `tel:` + its E.164 form so the two
    // spaces can never collide. `user_login` is a code for an account that exists (its id in
    // the reused `nominee_id` column); `user_join` is a code for an identity no account holds
    // yet, minted so that "Sign in or join" answers both the same way — which is also why
    // neither path can be used to ask whether an address is a member.

    public const LOGIN_PURPOSE = 'user_login';
    public const JOIN_PURPOSE  = 'user_join';

    /**
     * How long before the SAME channel may send another code. The code screen counts it
     * down ("Resend in 0:42") and the controller refuses a resend inside it, so a
     * double-press cannot cancel the code that is on its way.
     */
    public const RESEND_AFTER_SECONDS = 60;

    /** The hash a code is filed under. `via` is 'email' or 'phone' (E.164). */
    public static function identityHash(string $via, string $value): string
    {
        return $via === 'phone'
            ? hash('sha256', 'tel:' . $value)
            : hash('sha256', strtolower(trim($value)));
    }

    /**
     * The one active account holding this number, if exactly one does.
     *
     * `shared` is true when two or more do — a family line, an office phone. A code sent to
     * that handset proves the handset, not which of the accounts on it is being opened, so
     * the controller asks that person to use their email instead. It is told only AFTER the
     * code is proved, so it discloses nothing to somebody who does not hold the phone.
     *
     * @return array{user:?object, shared:bool}
     */
    public function byPhone(string $e164): array
    {
        try {
            $rows = DB::table('gates_users')->where('phone_e164', $e164)->where('status', self::ACTIVE)
                ->limit(2)->get()->all();
        } catch (\Throwable) {
            $rows = [];   // pre-migration: no number is on file in the normalised shape
        }
        return ['user' => count($rows) === 1 ? $rows[0] : null, 'shared' => count($rows) > 1];
    }

    /**
     * Create an account from an identity a code has just PROVED (step 3 of joining).
     *
     * The same refusals as {@see register()}, field by field, so the two ways an account is
     * made cannot disagree about what a name or an address is. What differs is what was
     * proved: an email joiner's address is verified by the code itself; a phone joiner's
     * handset is, and the address they type here is not — it is stored unverified and a
     * link is sent, exactly as a registration's would be.
     *
     * The phone is optional for an email joiner: the DC's step asks for a name, what they
     * do and where they are based, and a sign-up that demands a number the design does not
     * ask for is a sign-up that adds a field. It is required nowhere a vote is concerned —
     * the ballot asks for its own.
     *
     * @param array{name:string,email:string,phone?:?string,phone_e164?:?string,headline?:string,based_in?:string,email_verified:bool} $in
     * @return array{ok:bool, id?:int, field?:string, error?:string}
     */
    public function join(array $in): array
    {
        $name  = trim((string) $in['name']);
        $email = strtolower(trim((string) $in['email']));
        $head  = trim((string) ($in['headline'] ?? ''));
        $based = trim((string) ($in['based_in'] ?? ''));

        if (!self::isFullName($name)) return ['ok' => false, 'field' => 'name', 'error' => self::NAME_RULE];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'field' => 'email', 'error' => 'Please enter a valid email address.'];
        if (\AfricaGates\Support\DisposableEmail::isDisposable($email)) return ['ok' => false, 'field' => 'email', 'error' => 'Please use a permanent email address — disposable inboxes are not accepted.'];
        if (mb_strlen($head) > 120)  return ['ok' => false, 'field' => 'headline', 'error' => 'Keep this to 120 characters.'];
        if (mb_strlen($based) > 120) return ['ok' => false, 'field' => 'based_in', 'error' => 'Keep this to 120 characters.'];
        if (DB::table('gates_users')->where('email', $email)->exists()) {
            return ['ok' => false, 'field' => 'email', 'error' => 'An account with that email already exists — please sign in.'];
        }

        $row = [
            'name'           => mb_substr($name, 0, 160),
            'email'          => $email,
            'phone'          => ($in['phone'] ?? null) !== null ? mb_substr((string) $in['phone'], 0, 40) : null,
            'password_hash'  => null,
            'points'         => 0,
            'status'         => self::ACTIVE,
            'email_verified' => !empty($in['email_verified']) ? 1 : 0,
            'created_at'     => Carbon::now()->toDateTimeString(),
        ];
        // The new columns only where the migration has run — a join must not 500 on a
        // database one deploy behind.
        $extra = \AfricaGates\Support\OptionalColumn::filter('gates_users', [
            'phone_e164' => $in['phone_e164'] ?? null,
            'headline'   => $head !== '' ? $head : null,
            'based_in'   => $based !== '' ? $based : null,
        ], ['phone_e164', 'headline', 'based_in']);

        $id = (int) DB::table('gates_users')->insertGetId($row + ($extra ?: []));
        return ['ok' => true, 'id' => $id];
    }

    /** "What you do" and "Where you're based", from the account page. */
    public function saveAbout(int $userId, string $headline, string $based): array
    {
        $headline = trim($headline); $based = trim($based);
        if (mb_strlen($headline) > 120) return ['ok' => false, 'field' => 'headline', 'error' => 'Keep this to 120 characters.'];
        if (mb_strlen($based) > 120)    return ['ok' => false, 'field' => 'based_in', 'error' => 'Keep this to 120 characters.'];
        $row = \AfricaGates\Support\OptionalColumn::filter('gates_users', [
            'headline' => $headline !== '' ? $headline : null,
            'based_in' => $based !== '' ? $based : null,
        ], ['headline', 'based_in']);
        if ($row) DB::table('gates_users')->where('id', $userId)->update($row);
        return ['ok' => true];
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
        if (!self::isFullName($name)) return ['ok' => false, 'error' => self::NAME_RULE];
        // Optional now — an account made with an emailed code has no number until its owner
        // adds one — but a number that IS given must be one: a code may be sent to it.
        if ($phone !== '' && strlen((string) preg_replace('/\D+/', '', $phone)) < 7) return ['ok' => false, 'error' => 'Please enter a valid phone number.'];
        $row = ['name' => mb_substr($name, 0, 160), 'phone' => $phone !== '' ? mb_substr($phone, 0, 40) : null];
        // The normalised copy moves with it, or the sign-in by phone would go on finding the
        // number the member has just replaced.
        $row += \AfricaGates\Support\OptionalColumn::filter('gates_users',
            ['phone_e164' => $phone !== '' ? \AfricaGates\Support\Phone::normalize($phone, 'NG') : null], ['phone_e164']);
        DB::table('gates_users')->where('id', $userId)->update($row);
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

    /** How long a verification link lives. Read by the page that explains it. */
    public const VERIFY_TTL_HOURS = 24;

    /** The only status a member may hold and still be signed in. */
    public const ACTIVE = 'active';

    /**
     * How long a one-time SIGN-IN code lives.
     *
     * Read by the minting, by both halves of the email that carries the code, and by
     * the screen that asks for it. It was typed into all four — `addMinutes(15)`, "It
     * expires in 15 minutes" in the HTML body, "(valid 15 minutes)" in the plain one,
     * and "It expires in 15 minutes." on the page — which is the shape that had the
     * verification link's window stated in the email and nowhere the person who needed
     * it could read. Shortening the window would have left three screens promising the
     * old one.
     *
     * NOT the same as a voting code's ten minutes ({@see OtpService::generate}): those
     * are different windows for different purposes, and one global constant across
     * them would be a worse lie than four copies of this one.
     */
    public const OTP_TTL_MINUTES = 15;

    /**
     * MAY THIS ACCOUNT BE SIGNED IN? ONE ANSWER, FOR EVERY WAY IN.
     *
     * ── TWO READERS OF THIS QUESTION DISAGREED ───────────────────────────────
     *
     * `findByEmail()` has always required `status = 'active'`, so a member who is not
     * active cannot sign in with a password — `attemptLogin()` never finds them. The two
     * LINK paths resolve their account with `findById()` instead, which has no status
     * filter because it is also how a profile is read, and both then call
     * `startSession()`. So a verification link and a password-reset link each let somebody
     * in through a door the password refuses.
     *
     * Measured, and stated precisely: this is LATENT rather than live. `gates_users.status`
     * is a free VARCHAR defaulting to 'active' and no admin screen writes it, so nothing
     * on this platform can currently produce a member who is not active. It becomes live
     * the first time somebody adds a suspend button — and the guard belongs with the
     * credential, not with the future feature, because whoever adds that button will be
     * looking at a members table rather than at two token consumers.
     */
    public function canSignIn(object $u): bool
    {
        return (string) ($u->status ?? self::ACTIVE) === self::ACTIVE;
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
            'expires_at' => Carbon::now()->addHours(self::VERIFY_TTL_HOURS)->toDateTimeString(),
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
        // Spent by a GUARDED update, and only the request that changed the row goes on.
        // A bare update by id after the SELECT lets two requests presenting the same link
        // together both read it unused and both sign in — see consumePasswordReset().
        $spent = DB::table('gates_otp_tokens')->where('id', $tok->id)->where('is_used', 0)->update(['is_used' => 1]);
        if ($spent !== 1) return null;
        $user = $this->findById((int) $tok->nominee_id);
        // The token is spent above whatever happens next, so a refusal here cannot leave a
        // presented link spendable — and a suspended account must not be signed in by a
        // link when the password it owns is already refused. See canSignIn().
        if (!$user || !$this->canSignIn($user)) return null;
        DB::table('gates_users')->where('id', $user->id)->update(['email_verified' => 1]);
        $user->email_verified = 1;
        return $user;
    }

    /** Flip an account to verified (e.g. after a successful one-time-code login). */
    public function markVerified(int $userId): void
    {
        DB::table('gates_users')->where('id', $userId)->update(['email_verified' => 1]);
    }

    // ── Forgotten password ──────────────────────────────────────────────────
    //
    // Same row shape as the verification token above, and deliberately: one table, one
    // expiry mechanism, one "invalidate the previous one" rule. `nominee_id` again holds
    // a gates_users id, which is safe because {@see MergeService::NOMINEE_OTP_PURPOSES}
    // is an ALLOWLIST — a purpose whose subject really is a nominee has to be named there,
    // so a new purpose inherits no rewrite when a nominee is merged away.
    //
    // ONE HOUR, not twenty-four. A reset link is a bearer credential for somebody's whole
    // account; a verification link only proves an address. They are not the same risk and
    // must not share a window.
    public const RESET_PURPOSE = 'user_pwreset';
    private const RESET_TTL_MINUTES = 60;

    /**
     * Mint a single-use reset token, invalidating any earlier unused one. Returns the RAW
     * token for the emailed link; only its hash is stored.
     */
    public function issuePasswordReset(int $userId, string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        $eh = hash('sha256', $email);
        DB::table('gates_otp_tokens')->where('email_hash', $eh)->where('purpose', self::RESET_PURPOSE)
            ->where('is_used', 0)->update(['is_used' => 1]);
        $raw = bin2hex(random_bytes(24));
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => $eh,
            'token_hash' => hash('sha256', $raw),
            'purpose'    => self::RESET_PURPOSE,
            'nominee_id' => $userId,   // reused column: the account this resets
            'award_id'   => 0,
            'attempts'   => 0,
            'is_used'    => 0,
            'expires_at' => Carbon::now()->addMinutes(self::RESET_TTL_MINUTES)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        return $raw;
    }

    /**
     * The account a live reset token belongs to, WITHOUT consuming it.
     *
     * Separate from {@see consumePasswordReset} because the reset page has to be drawn
     * before the new password is typed. Burning the token on the GET would mean the form
     * posts a token that no longer exists — so the person sets a password, is told the
     * link has expired, and the password they typed is gone. Which is the same fault as a
     * "check your email" screen that has already used the code it is asking for.
     */
    public function findByResetToken(string $token): ?object
    {
        $row = $this->liveResetRow($token);
        return $row === null ? null : $this->findById((int) $row->nominee_id);
    }

    /**
     * Consume the token and set the password. Returns the user, or null when the token is
     * invalid, expired, already used, or the password is too short.
     *
     * SETTING A PASSWORD THIS WAY ALSO VERIFIES THE EMAIL. Following a link sent to that
     * inbox proves the same thing a one-time code proves, and {@see AccountController}
     * already treats a code that way. Leaving the account unverified would strand somebody
     * who has just proved they own the address on a "confirm your email" screen.
     */
    public function consumePasswordReset(string $token, string $password): ?object
    {
        if (strlen($password) < 8) return null;
        $row = $this->liveResetRow($token);
        if ($row === null) return null;

        // Burn the token FIRST — before the account is even resolved. A link that has been
        // PRESENTED is spent, whatever is decided about it afterwards: if the write below
        // throws, or the account turns out to be one that may not be signed in, a live link
        // left in an inbox is one somebody can carry on trying.
        //
        // And the burn is the CLAIM: `is_used = 0` is in the update, and only the request
        // whose update changed the row goes on. Without the predicate, two submits of the
        // same link (a double-tap, or the owner and whoever else holds the inbox) both
        // read it live, both stamp it, and both set a password — the last write wins, and
        // it need not be the owner's. `attempts + 1`-style relativity is not needed here:
        // `0 → 1` always changes the value, so MySQL's rows-CHANGED and SQLite's
        // rows-matched agree on it.
        $spent = DB::table('gates_otp_tokens')->where('id', $row->id)->where('is_used', 0)->update(['is_used' => 1]);
        if ($spent !== 1) return null;

        $user = $this->findById((int) $row->nominee_id);
        if (!$user || !$this->canSignIn($user)) return null;

        // ── AND EVERY OTHER CREDENTIAL FOR THIS ACCOUNT ──────────────────────
        //
        // Changing a password has to end every way in that was outstanding when it
        // changed, not only the link that was just spent. A one-time SIGN-IN code lives
        // fifteen minutes and is a complete credential on its own — it needs no password —
        // so one issued before the reset still works for a quarter of an hour after it.
        //
        // That is not theoretical for the situation a reset is a response to. The threat a
        // password reset answers is somebody else reading the inbox: they take a sign-in
        // code from it, the owner notices and resets, and the code they already hold walks
        // straight past the new password. `issuePasswordReset()` already does exactly this
        // for prior RESET tokens at issue time; the sign-in ones were simply never in that
        // clause, because the two purposes were written on different days.
        //
        // Every purpose for this address, not a named list. A list is an enumeration of the
        // token kinds that existed when it was typed, and the next one added is the one
        // nobody remembers to add to it — while anything else outstanding for an address
        // whose owner has just had to take their account back is a credential it is right
        // to spend rather than keep.
        DB::table('gates_otp_tokens')
            ->where('email_hash', (string) $row->email_hash)
            ->where('is_used', 0)
            ->update(['is_used' => 1]);

        // And the codes filed under the account's PHONE. A code sent by text or WhatsApp is
        // keyed on the number, not on this address, so the clause above never meets it — and
        // it is as complete a credential as the emailed one. Scoped by the account id the
        // sign-in purpose carries, never by `nominee_id` alone: that column holds a NOMINEE's
        // id for the vote purposes, and a bare id match would spend a stranger's vote code.
        DB::table('gates_otp_tokens')
            ->where('purpose', self::LOGIN_PURPOSE)
            ->where('nominee_id', (int) $user->id)
            ->where('is_used', 0)
            ->update(['is_used' => 1]);

        DB::table('gates_users')->where('id', $user->id)->update([
            'password_hash'  => password_hash($password, PASSWORD_BCRYPT),
            'email_verified' => 1,
        ]);
        $user->email_verified = 1;
        return $user;
    }

    /** The unused, unexpired reset row for a raw token, or null. */
    private function liveResetRow(string $token): ?object
    {
        $token = trim($token);
        // A hash of the empty string is a perfectly valid sha256, so an empty token would
        // otherwise go to the database and match any row somebody had managed to store.
        if ($token === '') return null;
        $row = DB::table('gates_otp_tokens')
            ->where('token_hash', hash('sha256', $token))->where('purpose', self::RESET_PURPOSE)
            ->where('is_used', 0)->where('expires_at', '>', Carbon::now()->toDateTimeString())
            ->orderByDesc('id')->first();
        return $row ?: null;
    }
}
