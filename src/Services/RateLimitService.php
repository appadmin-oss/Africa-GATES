<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class RateLimitService {
    public function check(string $fp, string $action, int $max, int $windowSecs): bool {
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
        $row=DB::table('gates_rate_limits')->where('fingerprint',$fp)->where('action',$action)->first();
        if(!$row) return 0;
        return max(0,(int)($windowSecs-(time()-strtotime($row->window_start))));
    }
}
