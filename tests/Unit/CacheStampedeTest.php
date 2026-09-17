<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\CacheService;

/**
 * On expiry, exactly ONE request may recompute. Everyone else is served stale.
 *
 * The previous `remember()` was a plain check-then-compute, so at expiry every
 * concurrent request found nothing and recomputed the same thing. Measured at 20,000
 * nominees and 200,000 votes, a cold `/vote` costs 399ms across 36 queries — three of
 * them ~47ms scans. With a 600-second TTL that is the entire arriving population
 * recomputing simultaneously every ten minutes.
 *
 * Nothing in a query count reveals it: each individual request looks fine, and the
 * failure only appears under concurrency the test suite never creates. Hence these
 * tests simulate the concurrency directly.
 */
class CacheStampedeTest extends TestCase
{
    private function seedExpired(string $key, mixed $payload, int $secondsAgo = 5): void
    {
        DB::table('gates_cache')->insert([
            'cache_key'  => $key,
            'payload'    => json_encode($payload),
            'expires_at' => Carbon::now()->copy()->subSeconds($secondsAgo)->toDateTimeString(),
            'created_at' => Carbon::now()->copy()->subSeconds(600)->toDateTimeString(),
        ]);
    }

    public function test_a_fresh_entry_is_returned_without_recomputing(): void
    {
        $cache = new CacheService();
        $cache->remember('k', 600, fn () => 'first');

        $calls = 0;
        $v = $cache->remember('k', 600, function () use (&$calls) { $calls++; return 'second'; });

        $this->assertSame('first', $v);
        $this->assertSame(0, $calls, 'a fresh hit must not run the callback');
    }

    public function test_only_one_of_many_concurrent_requests_recomputes(): void
    {
        // THE POINT OF THE CHANGE. Five requests arrive after expiry; one refreshes and
        // four are served the stale payload instead of all five doing 399ms of work.
        $this->seedExpired('hub', ['stale' => true]);

        // Concurrency has to be simulated from INSIDE the winner's callback. A
        // sequential loop proves nothing: the winner would finish and write the fresh
        // value before the second request even started, so the others would correctly
        // see it as fresh. The dangerous moment is precisely while the recompute is
        // still in flight, which is what this reproduces.
        $calls = 0;
        $concurrent = [];

        $winner = (new CacheService())->remember('hub', 600, function () use (&$calls, &$concurrent) {
            $calls++;
            // Four more requests arrive mid-recompute.
            for ($i = 0; $i < 4; $i++) {
                $concurrent[] = (new CacheService())->remember('hub', 600, function () use (&$calls) {
                    $calls++;                       // must never happen
                    return ['fresh' => true];
                });
            }
            return ['fresh' => true];
        });

        $this->assertSame(1, $calls, 'exactly one request may recompute; the other four must not');
        $this->assertSame(['fresh' => true], $winner, 'the winner gets the fresh value');
        $this->assertCount(4, $concurrent);
        foreach ($concurrent as $r) {
            $this->assertSame(['stale' => true], $r, 'mid-flight arrivals are served stale, not blocked');
        }
    }

    public function test_the_losers_are_served_immediately_rather_than_an_empty_result(): void
    {
        // Serving null would be worse than stale: the page would render an empty state
        // to a real visitor for no reason.
        $this->seedExpired('hub', ['programmes' => 3]);

        $loser = null;
        (new CacheService())->remember('hub', 600, function () use (&$loser) {
            $loser = (new CacheService())->remember('hub', 600, fn () => ['programmes' => 5]);
            return ['programmes' => 4];
        });

        $this->assertSame(['programmes' => 3], $loser,
            'serving null would render an empty page to a real visitor for no reason');
    }

    public function test_a_crashed_recomputer_does_not_wedge_the_key_forever(): void
    {
        // The election pushes expires_at forward, so it doubles as a lease. If the
        // winner dies, the key becomes electable again after the grace window rather
        // than serving stale for ever.
        $this->seedExpired('hub', ['v' => 1]);

        try {
            (new CacheService())->remember('hub', 600, function () { throw new \RuntimeException('worker died'); });
        } catch (\RuntimeException) { /* expected */ }

        // Lease still held: a request now is a loser and gets stale.
        $this->assertSame(['v' => 1], (new CacheService())->remember('hub', 600, fn () => ['v' => 2]));

        // Once the lease has passed, the next request wins and refreshes.
        DB::table('gates_cache')->where('cache_key', 'hub')
            ->update(['expires_at' => Carbon::now()->copy()->subSecond()->toDateTimeString()]);
        $this->assertSame(['v' => 3], (new CacheService())->remember('hub', 600, fn () => ['v' => 3]));
    }

    public function test_a_key_that_has_never_been_computed_still_computes(): void
    {
        // No row means nothing stale to serve, so the first caller must do the work
        // rather than return null. Documented as the one case not protected.
        $calls = 0;
        $v = (new CacheService())->remember('brand-new', 600, function () use (&$calls) { $calls++; return 'x'; });

        $this->assertSame('x', $v);
        $this->assertSame(1, $calls);
    }

    public function test_grace_can_be_disabled_for_a_caller_that_must_never_see_stale(): void
    {
        // An explicit 0 restores the old always-recompute behaviour, so a future
        // caller with a correctness requirement is not forced into staleness.
        $this->seedExpired('k', 'old');

        $a = (new CacheService())->remember('k', 600, fn () => 'new', [], 0);
        $this->seedExpiredIfMissing();
        $this->assertSame('new', $a);
    }

    private function seedExpiredIfMissing(): void
    {
        if (DB::table('gates_cache')->where('cache_key', 'k')->doesntExist()) {
            $this->seedExpired('k', 'old');
        }
    }

    public function test_forget_still_clears_an_entry_within_its_grace_window(): void
    {
        // Invalidation must beat staleness: an admin who edits a cycle expects the
        // public page to change, not to keep serving a stale copy for 45 seconds.
        $cache = new CacheService();
        $cache->remember('k', 600, fn () => 'v1');
        $cache->forget('k');

        $this->assertSame('v2', $cache->remember('k', 600, fn () => 'v2'));
    }
}
