<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class RateLimitService {
    /**
     * `gates_rate_limits.fingerprint` is VARCHAR(64) on MySQL.
     *
     * A sha256 hex digest is EXACTLY 64 characters, which is why callers reach for one —
     * and why anything concatenated onto one overflows by exactly as much as it adds.
     */
    private const FP_MAX = 64;

    /**
     * Any fingerprint too wide for the column, folded to one that fits.
     *
     * ── THE BUG THIS CLOSES, WHICH WAS SILENT AND NOT SMALL ──────────────────
     *
     * The vote-message report endpoint keys its once-per-day limit on
     * `sha256(ip) . '|' . $token` — ninety-odd characters into a sixty-four character
     * column. In strict mode MySQL refused the INSERT; the catch below reads a failed
     * insert as "the row already exists" and falls through to the increment, which
     * matched nothing and returned 0, which `check()` reports as OVER THE LIMIT.
     *
     * So every report of a vote message was refused, on production, always. The reporter
     * was thanked — deliberately, so a brigade cannot map the ceiling — and nothing was
     * recorded. The one mechanism for getting an abusive message about a named person in
     * front of a moderator did not work, and could not be seen not to work.
     *
     * Folded HERE rather than at the thirty-odd call sites, because the width of this
     * column is this class's business and no caller should have to know it. A digest is
     * still a unique key per input, so no limit changes meaning; a short fingerprint is
     * left alone so existing rows and readable keys like `pass:15` keep working.
     */
    private static function fit(string $fp): string {
        return strlen($fp) <= self::FP_MAX ? $fp : hash('sha256', $fp);
    }

    public function check(string $fp, string $action, int $max, int $windowSecs): bool {
        $fp = self::fit($fp);
        $windowStart=Carbon::now()->subSeconds($windowSecs)->toDateTimeString();
        DB::table('gates_rate_limits')->where('fingerprint',$fp)->where('action',$action)->where('window_start','<',$windowStart)->delete();

        // Start a fresh window. A concurrent request may insert the same
        // (fingerprint,action) first; the UNIQUE violation then drops us to the
        // atomic-increment path below instead of surfacing a 500.
        try {
            DB::table('gates_rate_limits')->insert(['fingerprint'=>$fp,'action'=>$action,'hit_count'=>1,'window_start'=>Carbon::now()->toDateTimeString()]);
            return $max >= 1; // first hit allowed unless the cap is below 1
        } catch (\Throwable $e) {
            // Row exists: increment ONLY while still under the cap, in a SINGLE
            // statement. The DB evaluates the predicate and the increment together,
            // so two concurrent requests can't both slip past the limit (no
            // check-then-increment gap). Affected-rows 0 → at/over the cap → blocked.
            $bumped = DB::table('gates_rate_limits')
                ->where('fingerprint',$fp)->where('action',$action)
                ->where('hit_count','<',$max)
                ->update(['hit_count'=>DB::raw('hit_count + 1')]);
            return $bumped > 0;
        }
    }
    public function retryAfter(string $fp, string $action, int $windowSecs): int {
        // Folded the same way, or this reads a row check() never wrote.
        $fp = self::fit($fp);
        $row=DB::table('gates_rate_limits')->where('fingerprint',$fp)->where('action',$action)->first();
        if(!$row) return 0;
        return max(0,(int)($windowSecs-(time()-strtotime($row->window_start))));
    }
}
