<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\CollusionService;

/**
 * Collusion detection — deterministic ring/burst surfacing over cast votes.
 */
class CollusionServiceTest extends TestCase
{
    private function scaffold(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'judging']);
        foreach ([10, 20, 30] as $cat) {
            DB::table('gates_award_categories')->insertOrIgnore(['id' => $cat, 'cycle_id' => 1, 'slug' => 'c' . $cat, 'title' => 'Cat ' . $cat]);
        }
        foreach ([[1, 10], [2, 20], [3, 30]] as [$nid, $cat]) {
            DB::table('gates_nominees')->insertOrIgnore(['id' => $nid, 'category_id' => $cat, 'name' => 'Nom ' . $nid, 'status' => 'approved', 'vote_count' => 0]);
        }
    }

    private function vote(int $nom, int $cat, string $email, ?string $dev, ?string $ip, string $at, string $type = 'standard'): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $nom, 'category_id' => $cat, 'voter_email_hash' => $email,
            'device_hash' => $dev, 'ip_hash' => $ip, 'vote_type' => $type, 'voted_at' => $at,
        ]);
    }

    public function test_shared_device_ring_detected(): void
    {
        $this->scaffold();
        $base = Carbon::now()->subDays(2);
        // 3 distinct accounts, ONE device, spread over hours (no burst), distinct IPs (no IP ring).
        foreach (['e1', 'e2', 'e3'] as $i => $email) {
            $this->vote(1, 10, $email, 'devX', 'ip' . $i, $base->copy()->addHours($i)->toDateTimeString());
        }

        $r = (new CollusionService())->scan();
        $this->assertSame(1, $r['by_kind']['shared_device']);

        $f = DB::table('gates_collusion_findings')->where('kind', 'shared_device')->first();
        $this->assertSame(1, (int) $f->nominee_id);
        $this->assertSame('devX', $f->shared_key);
        $this->assertSame(3, (int) $f->distinct_voters);
        $this->assertSame(3, (int) $f->vote_count);
        $this->assertSame(50, (int) $f->risk_score);
        $this->assertSame('open', $f->status);
    }

    public function test_shared_ip_ring_detected(): void
    {
        $this->scaffold();
        $base = Carbon::now()->subDays(2);
        // 6 distinct accounts, ONE ip, no device (no device ring), spread over hours (no burst).
        for ($i = 0; $i < 6; $i++) {
            $this->vote(2, 20, 'f' . $i, null, 'ipX', $base->copy()->addHours($i)->toDateTimeString());
        }

        $r = (new CollusionService())->scan();
        $this->assertSame(1, $r['by_kind']['shared_ip']);
        $this->assertSame(0, $r['by_kind']['shared_device']);

        $f = DB::table('gates_collusion_findings')->where('kind', 'shared_ip')->first();
        $this->assertSame(6, (int) $f->distinct_voters);
        $this->assertSame(25, (int) $f->risk_score);
    }

    public function test_timing_burst_detected(): void
    {
        $this->scaffold();
        $base = Carbon::now()->subDays(2);
        // 8 votes within ~7 minutes, distinct emails + IPs, no device → only a burst.
        for ($i = 0; $i < 8; $i++) {
            $this->vote(3, 30, 'g' . $i, null, 'gip' . $i, $base->copy()->addSeconds($i * 50)->toDateTimeString());
        }

        $r = (new CollusionService())->scan();
        $this->assertSame(1, $r['by_kind']['timing_burst']);
        $this->assertSame(0, $r['by_kind']['shared_ip']);

        $f = DB::table('gates_collusion_findings')->where('kind', 'timing_burst')->first();
        $this->assertSame(8, (int) $f->vote_count);
        $this->assertSame(30, (int) $f->risk_score);
    }

    public function test_clean_votes_produce_no_findings(): void
    {
        $this->scaffold();
        $base = Carbon::now()->subDays(3);
        // Different nominees, devices, IPs, far apart in time — nothing coordinated.
        $this->vote(1, 10, 'a', 'dA', 'iA', $base->copy()->toDateTimeString());
        $this->vote(2, 20, 'b', 'dB', 'iB', $base->copy()->addHours(5)->toDateTimeString());
        $this->vote(3, 30, 'c', 'dC', 'iC', $base->copy()->addHours(11)->toDateTimeString());

        $r = (new CollusionService())->scan();
        $this->assertSame(0, $r['findings']);
        $this->assertSame(0, DB::table('gates_collusion_findings')->count());
    }

    public function test_bonus_votes_are_ignored(): void
    {
        $this->scaffold();
        $base = Carbon::now()->subDays(2);
        // A device shared across 3 accounts — but they're BONUS votes, so excluded.
        foreach (['b1', 'b2', 'b3'] as $i => $email) {
            $this->vote(1, 10, $email, 'devB', 'bip' . $i, $base->copy()->addHours($i)->toDateTimeString(), 'bonus');
        }

        $r = (new CollusionService())->scan();
        $this->assertSame(0, $r['findings']);
    }

    public function test_rescan_is_idempotent_and_preserves_review_status(): void
    {
        $this->scaffold();
        $base = Carbon::now()->subDays(2);
        foreach (['e1', 'e2', 'e3'] as $i => $email) {
            $this->vote(1, 10, $email, 'devX', 'ip' . $i, $base->copy()->addHours($i)->toDateTimeString());
        }
        $svc = new CollusionService();

        $svc->scan();
        $this->assertSame(1, DB::table('gates_collusion_findings')->count());

        // An admin reviews the finding...
        DB::table('gates_collusion_findings')->where('kind', 'shared_device')->update(['status' => 'reviewed']);

        // ...a re-scan must not duplicate it or reset its status.
        $svc->scan();
        $this->assertSame(1, DB::table('gates_collusion_findings')->count());
        $this->assertSame('reviewed', DB::table('gates_collusion_findings')->value('status'));
    }
}
