<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Spend one guess against a one-time code — the whole cap, in one statement.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A CLASS AND NOT FOUR COPIES OF TWO LINES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every consumer of `gates_otp_tokens` reads the live token first — it has to, to
 * check the purpose and the expiry — and then counts the guess. Written the obvious
 * way that is:
 *
 *     DB::table('gates_otp_tokens')->where('id', $tok->id)->increment('attempts');
 *     if (((int) $tok->attempts + 1) > self::MAX_ATTEMPTS) { … }
 *
 * The comparison is against the value the SELECT returned, and nothing serialises the
 * gap between the two statements. Fire N guesses at once and every one of them holds a
 * snapshot saying `attempts = 0`, so every one of them concludes it is the first and
 * every one of them reaches the hash comparison. The counter faithfully records all N;
 * the cap never consults it. At any concurrency above one the cap is decoration.
 *
 * That was found and fixed on the judges' sign-in, with a long comment explaining it
 * and `JudgeOtpAttemptCapTest` to hold it — and the identical two lines were left
 * standing in three other places, because the test asked "is the judge door right?"
 * rather than "does every door do this?". An enumeration of past failures is never a
 * fix for the next one. So the clause is here, once, and `OtpAttemptCapTest` sweeps
 * for a second.
 *
 * The predicate and the increment travel together now: the database evaluates
 * `attempts < $max` and adds one atomically, so exactly `$max` guesses can ever claim
 * an attempt however many arrive together. Affected-rows 0 means the cap is spent —
 * the same primitive `RateLimitService` gets from its conditional update.
 *
 * ── THE ONE CALLER THAT DOES NOT NEED THIS, AND WHY ──────────────────────────
 *
 * `VoteService::verifyAndVote()` reads its token `lockForUpdate()` inside a
 * transaction, so the second caller blocks on the row until the first commits and
 * then reads the incremented value. That is a different mechanism reaching the same
 * guarantee, and it is correct — rewriting it to call this would be churn, and
 * reporting it as broken would be a false finding. The sweep knows the difference.
 */
final class OtpAttempt
{
    /**
     * Claim one guess. True when it was allowed, false when the cap is spent.
     *
     * The caller still decides what a spent cap means — most burn the token, so a
     * code that has been ground against cannot be ground against again.
     *
     * ── THE DRIVER DIFFERENCE THIS RESTS ON, CONFIRMED RATHER THAN ASSUMED ───
     *
     * MySQL reports rows CHANGED by an UPDATE, not rows matched (SQLite reports
     * matched). `attempts + 1` always changes the value, so the two agree here, and
     * a parity run against MySQL 8 was measured allowing exactly five of ten claims
     * with the counter stopping at five. A future claim written as
     * `update(['attempts' => $n])` with the value it already holds would report 0 on
     * production and 1 in the suite — a cap that silently refuses every guess on the
     * only database that matters. Keep the increment relative.
     */
    public static function claim(int $tokenId, int $max): bool
    {
        return DB::table('gates_otp_tokens')
            ->where('id', $tokenId)
            ->where('attempts', '<', $max)
            ->update(['attempts' => DB::raw('attempts + 1')]) > 0;
    }
}
