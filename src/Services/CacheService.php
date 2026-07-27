<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class CacheService {
    public function remember(string $key, int $ttl, callable $cb, array $tags = []): mixed {
        try { $r=DB::table('gates_cache')->where('cache_key',$key)->where('expires_at','>',Carbon::now())->first(); if($r) return json_decode($r->payload,true); } catch(\Throwable) {}
        $v=$cb();
        try { DB::table('gates_cache')->updateOrInsert(['cache_key'=>$key],['payload'=>json_encode($v),'tags'=>$tags?implode(',',$tags):null,'expires_at'=>Carbon::now()->addSeconds($ttl)->toDateTimeString(),'created_at'=>Carbon::now()->toDateTimeString()]); } catch(\Throwable) {}
        return $v;
    }
    public function get(string $key): mixed {
        try { $r=DB::table('gates_cache')->where('cache_key',$key)->where('expires_at','>',Carbon::now())->first(); return $r?json_decode($r->payload,true):null; } catch(\Exception) { return null; }
    }
    public function forget(string $key): void { try { DB::table('gates_cache')->where('cache_key',$key)->delete(); } catch(\Exception) {} }
    public function forgetByTag(string $tag): void { try { DB::table('gates_cache')->where('tags','LIKE',"%$tag%")->delete(); } catch(\Exception) {} }

    /**
     * Every cached view derived from an award cycle's phase.
     *
     * Invalidation used to be done ad hoc with `LIKE 'awards:%'`, which matched
     * only two of these six prefixes. It missed `vote:hub` — the key the /vote
     * hub itself reads — and `award:prog:` does not even match the pattern (no
     * trailing 's'). So after a phase change /vote kept advertising "Voting
     * open" with live Vote buttons for up to 10 minutes, /awards/{slug} for 30,
     * and the public API for 30. This list is the single place that knowledge
     * lives; add a prefix here when a new phase-derived view is cached.
     */
    public const AWARD_VIEW_PREFIXES = [
        'awards:active',   // /nominate — open-for-nominations programmes
        'awards:index',    // /awards — programme cards
        'award:prog:',     // /awards/{slug} — one programme + its cycle
        'vote:hub',        // /vote — the hub cards
        'api:awards',      // GET /api/awards
        'api:nom:',        // GET /api/nominees
    ];

    /** Drop every phase-derived cached view. Returns rows removed. */
    public function forgetAwardViews(): int
    {
        $n = 0;
        foreach (self::AWARD_VIEW_PREFIXES as $prefix) {
            try {
                $n += (int) DB::table('gates_cache')->where('cache_key', 'LIKE', $prefix . '%')->delete();
            } catch (\Throwable) { /* a cache miss is never fatal */ }
        }
        try { $this->forgetByTag('leaderboard'); } catch (\Throwable) {}
        return $n;
    }
    public function prune(): void { try { DB::table('gates_cache')->where('expires_at','<',Carbon::now())->delete(); } catch(\Exception) {} }
}
