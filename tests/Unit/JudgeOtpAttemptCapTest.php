<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The five-guess cap on a judge's sign-in code has to hold when the guesses
 * arrive together, not just when they arrive in a queue.
 *
 * The old code read `attempts`, incremented, then compared the value it had read.
 * Nothing serialised that gap — no transaction, no row lock — so N simultaneous
 * requests each saw attempts = 0 and each concluded it was the first guess. The
 * cap was a formality at any concurrency above one.
 *
 * That matters more here than almost anywhere else on the platform: this is the
 * door to the ballot that carries 55% of a nominee's score, and an attacker does
 * not have to wait for a judge to sign in — submitting the judge's address to the
 * public sign-in form is what mints the code they are guessing at.
 *
 * The per-IP rate limit was, and is, atomic (RateLimitService bumps behind the
 * same guarded predicate), so the outer bound survived. What was missing was the
 * inner one — the cap that is supposed to make the code single-use-ish regardless
 * of where the traffic comes from.
 *
 * These tests exercise the DATABASE PRIMITIVE the controller now uses, rather
 * than the HTTP handler, because the failure was never in the branching — it was
 * in whether the count and the comparison happen as one operation. Testing it at
 * the statement level is testing the actual fix.
 */
class JudgeOtpAttemptCapTest extends TestCase
{
    private const MAX = 5;

    private function liveToken(): int
    {
        return (int) DB::table('gates_otp_tokens')->insertGetId([
            'email_hash' => hash('sha256', 'judge@x.io'),
            'token_hash' => hash('sha256', '424242'),
            'purpose'    => 'judge_login',
            'nominee_id' => 1,
            'award_id'   => 0,
            'attempts'   => 0,
            'is_used'    => 0,
            'expires_at' => Carbon::now()->addMinutes(15)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** The guarded claim the controller performs, in one statement. */
    private function claimAttempt(int $tokenId): int
    {
        return DB::table('gates_otp_tokens')
            ->where('id', $tokenId)
            ->where('attempts', '<', self::MAX)
            ->update(['attempts' => DB::raw('attempts + 1')]);
    }

    /** The old shape, kept here so the difference is visible rather than asserted about. */
    private function claimAttemptTheOldWay(object $staleToken): int
    {
        DB::table('gates_otp_tokens')->where('id', $staleToken->id)->increment('attempts');
        return ((int) $staleToken->attempts + 1) > self::MAX ? 0 : 1;
    }

    public function test_exactly_five_guesses_can_be_claimed(): void
    {
        $id = $this->liveToken();

        for ($i = 1; $i <= self::MAX; $i++) {
            $this->assertSame(1, $this->claimAttempt($id), "guess $i should be allowed");
        }
        $this->assertSame(0, $this->claimAttempt($id), 'the sixth must be refused');
        $this->assertSame(self::MAX, (int) DB::table('gates_otp_tokens')->where('id', $id)->value('attempts'),
            'and the counter must not run past the cap');
    }

    /**
     * THE RACE. Every caller reads the token first — as the controller does, to
     * check expiry and purpose — and only then tries to claim an attempt. All ten
     * hold a snapshot showing attempts = 0, exactly as ten parallel requests would.
     *
     * Five may proceed. Not ten.
     */
    public function test_ten_callers_holding_the_same_stale_read_still_only_get_five(): void
    {
        $id = $this->liveToken();

        $stale = DB::table('gates_otp_tokens')->where('id', $id)->first();   // one read, shared
        $allowed = 0;
        for ($i = 0; $i < 10; $i++) {
            $allowed += $this->claimAttempt((int) $stale->id) > 0 ? 1 : 0;
        }

        $this->assertSame(self::MAX, $allowed,
            'the count and the comparison must happen as one statement');
    }

    /**
     * The same ten callers against the old read-then-compare, to show the guard is
     * load-bearing and not decoration. Every one of them is waved through.
     */
    public function test_the_old_shape_lets_all_ten_through(): void
    {
        $id = $this->liveToken();

        $stale = DB::table('gates_otp_tokens')->where('id', $id)->first();
        $allowed = 0;
        for ($i = 0; $i < 10; $i++) {
            $allowed += $this->claimAttemptTheOldWay($stale) > 0 ? 1 : 0;
        }

        $this->assertSame(10, $allowed,
            'BUG REPRODUCED: a stale read means every parallel guess believes it is the first');
        $this->assertSame(10, (int) DB::table('gates_otp_tokens')->where('id', $id)->value('attempts'),
            'the counter recorded all ten — the cap simply never consulted it');
    }

    /** The controller still uses the constant this test pins. */
    public function test_the_controller_cap_matches(): void
    {
        $ref = new \ReflectionClass(\AfricaGates\Judge\Controllers\AuthController::class);
        $this->assertSame(self::MAX, $ref->getConstant('MAX_OTP_ATTEMPTS'));
    }
}
