<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class RateLimitService {
    public function check(string $fp, string $action, int $max, int $windowSecs): bool {
        $windowStart=Carbon::now()->subSeconds($windowSecs)->toDateTimeString();
        DB::table('gates_rate_limits')->where('fingerprint',$fp)->where('action',$action)->where('window_start','<',$windowStart)->delete();
        $ex=DB::table('gates_rate_limits')->where('fingerprint',$fp)->where('action',$action)->first();
        if(!$ex){
            // First hit this window. A concurrent request might insert the same
            // (fingerprint,action) first; a UNIQUE violation then means we lost
            // the race — fall through to increment instead of surfacing a 500.
            try {
                DB::table('gates_rate_limits')->insert(['fingerprint'=>$fp,'action'=>$action,'hit_count'=>1,'window_start'=>Carbon::now()->toDateTimeString()]);
                return true;
            } catch (\Throwable $e) {
                DB::table('gates_rate_limits')->where('fingerprint',$fp)->where('action',$action)->increment('hit_count');
                return true;
            }
        }
        if((int)$ex->hit_count>=$max) return false;
        DB::table('gates_rate_limits')->where('fingerprint',$fp)->where('action',$action)->increment('hit_count');
        return true;
    }
    public function retryAfter(string $fp, string $action, int $windowSecs): int {
        $row=DB::table('gates_rate_limits')->where('fingerprint',$fp)->where('action',$action)->first();
        if(!$row) return 0;
        return max(0,(int)($windowSecs-(time()-strtotime($row->window_start))));
    }
}
