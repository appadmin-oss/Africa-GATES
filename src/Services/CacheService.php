<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class CacheService {
    /**
     * How long a stale entry may still be served while ONE request recomputes it.
     *
     * Also doubles as the recompute lease: if the elected request dies mid-flight,
     * the entry becomes electable again after this long, so a crash costs one grace
     * window rather than a permanently stuck key.
     */
    public const STAMPEDE_GRACE = 45;

    /**
     * Cache-aside read with stale-while-revalidate.
     *
     * WHY THE GRACE WINDOW EXISTS. The previous form was a plain check-then-compute:
     * on expiry, EVERY concurrent request found nothing and recomputed. Measured at
     * 20,000 nominees and 200,000 votes, a cold `/vote` costs 399ms across 36 queries,
     * three of them ~47ms table scans. With a 600-second TTL that means every ten
     * minutes the whole arriving population recomputes the same thing simultaneously —
     * a thundering herd, and on a platform sized for a continent it is the thing that
     * takes the site down long before row volume does. Nothing in the query counts
     * hints at it, because each individual request looks fine.
     *
     * So on expiry exactly ONE request refreshes and everyone else is served the
     * slightly-stale payload immediately. The election is a conditional UPDATE — the
     * same claim-by-write pattern the transitions ledger uses — so it needs no lock
     * table and no new column: pushing `expires_at` forward IS the lease, and only the
     * request whose UPDATE affects a row has won it.
     *
     * WHAT THIS DELIBERATELY DOES NOT DO. A key with no row at all (first ever
     * request, or just after a purge) has nothing stale to serve, so concurrent
     * requests there do all compute. That is a one-off per key rather than a recurring
     * cliff every TTL, and fixing it would need a separate lock table for a much
     * smaller win.
     *
     * Serving data up to STAMPEDE_GRACE seconds stale is safe for what this caches —
     * vote hubs, award listings, leaderboards. It is NOT used for anything that gates
     * a write: BallotGuard reads the clock directly and never comes through here.
     */
    public function remember(string $key, int $ttl, callable $cb, array $tags = [], ?int $grace = null): mixed {
        $grace = $grace ?? self::STAMPEDE_GRACE;
        $now   = Carbon::now();
        $row   = null;
        try { $row = DB::table('gates_cache')->where('cache_key',$key)->first(); } catch(\Throwable) {}

        if ($row !== null) {
            $expires = strtotime((string) $row->expires_at);
            if ($expires !== false && $expires > $now->getTimestamp()) {
                return json_decode($row->payload, true);   // fresh
            }
            if ($grace > 0) {
                // Elect a single recomputer. The conditional UPDATE succeeds for
                // exactly one caller: the winner moves expires_at forward, so every
                // other request's WHERE no longer matches and it gets 0 rows.
                $won = 0;
                try {
                    $won = DB::table('gates_cache')
                        ->where('cache_key', $key)
                        ->where('expires_at', '<=', $now->toDateTimeString())
                        ->update(['expires_at' => $now->copy()->addSeconds($grace)->toDateTimeString()]);
                } catch(\Throwable) {}
                if ($won === 0) {
                    // Someone else holds the lease — serve stale rather than pile on.
                    return json_decode($row->payload, true);
                }
            }
        }

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
