<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\OtpAttempt;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EVERY door that caps guesses on a one-time code, not the one we already fixed.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SHAPE, AND WHY A PASSING TEST HID IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_otp_tokens` is one table for every purpose — `vote`, `claim`, `ticket`,
 * `judge_login`, `user_login` — and five places spend a guess against it. Each reads
 * the live token (it must, to check purpose and expiry), then counts the attempt and
 * compares. Written the obvious way the count and the comparison are two statements
 * with nothing between them, so N simultaneous guesses each see `attempts = 0`, each
 * believe they are the first, and each reach the hash comparison. The counter records
 * all N and the cap never consults it.
 *
 * That was found on the judges' sign-in and fixed there, with a paragraph of comment
 * and {@see JudgeOtpAttemptCapTest} to hold it. That test proves the PRIMITIVE and
 * pins the judge controller's constant. It never asked who else spends a guess — and
 * three other doors carried the identical two lines, untouched:
 *
 *   · `AccountController::otpVerify()`   — the member sign-in. A complete credential
 *     that needs no password, and an attacker mints one for any address by posting it
 *     to the public form.
 *   · `NomineeClaimService::checkCode()` — the code that hands somebody a nominee
 *     profile.
 *   · `TicketSelfService`                — the code that opens a ticket.
 *
 * An enumeration of past failures is never a fix for the next one. So this file asks
 * the RULE: does every writer of `attempts` claim it atomically? There is one clause
 * now — {@see OtpAttempt::claim} — and this sweep fails on a second.
 *
 * ── WHAT THE SWEEP KNOWS THAT A NAIVE ONE WOULD NOT ──────────────────────────
 *
 * `VoteService::verifyAndVote()` keeps its bare increment and is CORRECT: it reads the
 * token `lockForUpdate()` inside a transaction, so the second caller blocks on the row
 * and reads the incremented value. Reporting it would be a false finding, and
 * rewriting it would be churn on the one path that already had the guarantee. A lock
 * held over the read is the other way to get there, and the sweep accepts it — by
 * looking for the lock in the same method, not in the same file.
 *
 * ── WHAT IT CANNOT SEE ───────────────────────────────────────────────────────
 *
 * Raw SQL. Every attempt write on this table today goes through the query builder; a
 * hand-written `UPDATE gates_otp_tokens SET attempts` would pass this sweep unread.
 * Said here rather than left for somebody to discover, because a sweep that narrows a
 * surface without saying where it stopped reads as having cleared it.
 */
final class OtpAttemptCapTest extends TestCase
{
    private const MAX = 5;

    /** Every PHP file under src/. */
    private function sources(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    /**
     * The body of the function containing $offset, found by brace matching from the
     * nearest `function` keyword above it. Method scope is what decides whether a row
     * lock protects this increment — a `lockForUpdate()` elsewhere in the same FILE
     * says nothing about this statement, and that is the difference between clearing
     * `VoteService` correctly and clearing it by accident.
     */
    private function enclosingBody(string $src, int $offset): string
    {
        $start = 0;
        if (preg_match_all('/\bfunction\b/', substr($src, 0, $offset), $m, PREG_OFFSET_CAPTURE)) {
            $start = (int) end($m[0])[1];
        }
        $open = strpos($src, '{', $start);
        if ($open === false) return '';
        $depth = 0;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}' && --$depth === 0) return substr($src, $open, $i - $open + 1);
        }
        return substr($src, $open);
    }

    /**
     * THE SWEEP. Prove it names a break before trusting it: put
     * `->increment('attempts')` back into any of the three doors and this fails with
     * that file's name and line.
     */
    public function test_every_attempt_cap_is_claimed_atomically_or_under_a_row_lock(): void
    {
        $offences = [];

        foreach ($this->sources() as $path) {
            if (str_ends_with($path, 'Support/OtpAttempt.php')) continue;   // the clause itself
            $src = (string) file_get_contents($path);
            if (!str_contains($src, 'gates_otp_tokens')) continue;

            // Any write to `attempts` on this table. `increment('attempts')` is the
            // shape that shipped; a hand-rolled `update(['attempts' => …])` is the
            // same fault wearing different clothes, so both are looked for.
            $pattern = '/->increment\(\s*[\'"]attempts[\'"]|[\'"]attempts[\'"]\s*=>\s*DB::raw/';
            if (!preg_match_all($pattern, $src, $m, PREG_OFFSET_CAPTURE)) continue;

            foreach ($m[0] as [$hit, $off]) {
                $body = $this->enclosingBody($src, (int) $off);
                // Two ways to be right: the predicate rides with the increment, or the
                // read that produced the row was locked for the duration.
                $atomic = (bool) preg_match('/->where\(\s*[\'"]attempts[\'"]\s*,\s*[\'"]<[\'"]/', $body);
                $locked = str_contains($body, 'lockForUpdate(');
                if ($atomic || $locked) continue;

                $offences[] = sprintf('%s:%d  %s',
                    str_replace(dirname(__DIR__, 2) . '/', '', $path),
                    substr_count(substr($src, 0, (int) $off), "\n") + 1,
                    trim($hit));
            }
        }

        $this->assertSame([], $offences, sprintf(
            "%d attempt cap(s) compare a STALE read, so the cap does not hold when the "
            . "guesses arrive together. Call OtpAttempt::claim(), or hold the row with "
            . "lockForUpdate() over the read:\n  %s",
            count($offences), implode("\n  ", $offences)));
    }

    // ═══════════════════════ the primitive itself ════════════════════════════

    private function liveToken(): int
    {
        return (int) DB::table('gates_otp_tokens')->insertGetId([
            'email_hash' => hash('sha256', 'someone@example.com'),
            'token_hash' => hash('sha256', '424242'),
            'purpose'    => 'user_login',
            'nominee_id' => 1,
            'award_id'   => 0,
            'attempts'   => 0,
            'is_used'    => 0,
            'expires_at' => Carbon::now()->addMinutes(15)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /**
     * Ten callers each holding the same stale snapshot — exactly what ten parallel
     * requests hold, because each does its own read before claiming. Five may proceed.
     */
    public function test_ten_callers_on_one_stale_read_still_only_get_five(): void
    {
        $id    = $this->liveToken();
        $stale = DB::table('gates_otp_tokens')->where('id', $id)->first();

        $allowed = 0;
        for ($i = 0; $i < 10; $i++) {
            $allowed += OtpAttempt::claim((int) $stale->id, self::MAX) ? 1 : 0;
        }

        $this->assertSame(self::MAX, $allowed);
        $this->assertSame(self::MAX, (int) DB::table('gates_otp_tokens')->where('id', $id)->value('attempts'),
            'and the counter must not run past the cap');
    }

    /** A spent cap stays spent — a later arrival cannot find room. */
    public function test_a_spent_cap_refuses_every_later_claim(): void
    {
        $id = $this->liveToken();
        for ($i = 0; $i < self::MAX; $i++) OtpAttempt::claim($id, self::MAX);

        $this->assertFalse(OtpAttempt::claim($id, self::MAX));
        $this->assertFalse(OtpAttempt::claim($id, self::MAX));
    }
}
