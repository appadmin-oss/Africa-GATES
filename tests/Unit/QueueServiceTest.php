<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\QueueService;

/**
 * Phase 2 — durable job queue: push, claim-and-run, retry-with-backoff, and
 * delayed (run_after) scheduling.
 */
class QueueServiceTest extends TestCase
{
    public function test_push_and_work_runs_handler(): void
    {
        $q = new QueueService();
        $id = $q->push('test.echo', ['x' => 1]);
        $this->assertSame('pending', DB::table('gates_jobs')->where('id', $id)->value('status'));

        $seen = null;
        $q->on('test.echo', function (array $p) use (&$seen) { $seen = $p; });
        $r = $q->work();

        $this->assertSame(1, $r['done']);
        $this->assertSame(['x' => 1], $seen);
        $this->assertSame('done', DB::table('gates_jobs')->where('id', $id)->value('status'));
    }

    public function test_failed_handler_retries_with_backoff(): void
    {
        $q = new QueueService();
        $id = $q->push('test.boom');
        $q->on('test.boom', function () { throw new \RuntimeException('boom'); });

        $r = $q->work();

        $this->assertSame(1, $r['retried']);
        $row = DB::table('gates_jobs')->where('id', $id)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertStringContainsString('boom', (string) $row->last_error);
        $this->assertGreaterThan(Carbon::now()->toDateTimeString(), (string) $row->run_after); // backed off
    }

    public function test_delayed_job_not_run_until_due(): void
    {
        $q = new QueueService();
        $q->push('test.later', [], 3600); // due in 1h
        $ran = false;
        $q->on('test.later', function () use (&$ran) { $ran = true; });

        $r = $q->work();

        $this->assertSame(0, $r['done']);
        $this->assertFalse($ran);
    }

    public function test_missing_handler_marks_retry(): void
    {
        $q = new QueueService();
        $id = $q->push('test.unhandled');
        $r = $q->work(); // no handler registered
        $this->assertSame(1, $r['retried']);
        $this->assertStringContainsString('No handler', (string) DB::table('gates_jobs')->where('id', $id)->value('last_error'));
    }

    public function test_exhausts_attempts_then_marks_failed(): void
    {
        $q = new QueueService();
        $id = $q->push('test.boom');
        DB::table('gates_jobs')->where('id', $id)->update(['attempts' => 4]); // one short of MAX_ATTEMPTS (5)
        $q->on('test.boom', function () { throw new \RuntimeException('still boom'); });

        $r = $q->work();

        $this->assertSame(1, $r['failed']);
        $this->assertSame(0, $r['retried']);
        $row = DB::table('gates_jobs')->where('id', $id)->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame(5, (int) $row->attempts);
    }

    public function test_stale_locked_job_is_reclaimed(): void
    {
        // A worker claimed the job then died 10 minutes ago, leaving a stale lock.
        // The reaper must reclaim and run it rather than strand it forever.
        $q = new QueueService();
        $id = $q->push('test.stale');
        DB::table('gates_jobs')->where('id', $id)->update(['locked_at' => Carbon::now()->subMinutes(10)->toDateTimeString()]);
        $ran = false;
        $q->on('test.stale', function () use (&$ran) { $ran = true; });

        $r = $q->work();

        $this->assertTrue($ran, 'a job whose worker died holding the lock must be reclaimed');
        $this->assertSame(1, $r['done']);
        $this->assertSame('done', DB::table('gates_jobs')->where('id', $id)->value('status'));
    }

    public function test_stale_job_past_max_attempts_is_failed_not_run(): void
    {
        // A poison job that crashed the worker mid-handler every time (so attempts
        // climbed via claim-time increments) must eventually be failed, not reaped
        // forever.
        $q = new QueueService();
        $id = $q->push('test.poison');
        DB::table('gates_jobs')->where('id', $id)->update([
            'locked_at' => Carbon::now()->subMinutes(10)->toDateTimeString(),
            'attempts'  => 5, // already at MAX_ATTEMPTS
        ]);
        $ran = false;
        $q->on('test.poison', function () use (&$ran) { $ran = true; });

        $r = $q->work();

        $this->assertFalse($ran, 'a job that already burned all attempts must not run again');
        $this->assertSame(1, $r['failed']);
        $this->assertSame('failed', DB::table('gates_jobs')->where('id', $id)->value('status'));
    }

    public function test_locked_job_is_not_reprocessed(): void
    {
        $q = new QueueService();
        $id = $q->push('test.locked');
        DB::table('gates_jobs')->where('id', $id)->update(['locked_at' => Carbon::now()->toDateTimeString()]);
        $ran = false;
        $q->on('test.locked', function () use (&$ran) { $ran = true; });

        $r = $q->work();

        $this->assertFalse($ran);          // a claimed job is never re-run
        $this->assertSame(0, $r['done']);
        $this->assertSame('pending', DB::table('gates_jobs')->where('id', $id)->value('status'));
    }
    public function test_later_jobs_receive_fresh_leases_after_a_long_batch(): void
    {
        $start=Carbon::now()->startOfSecond();Carbon::setTestNow($start);
        try {
            $a=new QueueService();$b=new QueueService();$runs=0;
            $a->push('slow');$id=$a->push('later');
            $a->on('slow',static function()use($start){Carbon::setTestNow($start->copy()->addMinutes(6));});
            $b->on('later',static function()use(&$runs){$runs++;});
            $a->on('later',function()use(&$runs,$id,$b){
                $runs++;
                $this->assertSame(Carbon::now()->toDateTimeString(),DB::table('gates_jobs')->where('id',$id)->value('locked_at'));
                $this->assertSame(0,$b->work()['done']);
            });
            $this->assertSame(2,$a->work()['done']);
            $this->assertSame(1,$runs);
        } finally {Carbon::setTestNow();}
    }

    public function test_a_stale_worker_cannot_reschedule_a_job_a_new_owner_completed(): void
    {
        $start=Carbon::now()->startOfSecond();Carbon::setTestNow($start);
        try {
            $a=new QueueService();$b=new QueueService();$id=$a->push('long');
            $b->on('long',static function(){});
            $a->on('long',function()use($start,$b){
                Carbon::setTestNow($start->copy()->addMinutes(6));
                $this->assertSame(1,$b->work()['done']);
                throw new \RuntimeException('old worker failed');
            });
            $this->assertSame(['done'=>0,'failed'=>0,'retried'=>0],$a->work());
            $row=DB::table('gates_jobs')->find($id);
            $this->assertSame('done',$row->status);
            $this->assertSame(2,(int)$row->attempts);
            $this->assertNull($row->last_error);
        } finally {Carbon::setTestNow();}
    }

}
