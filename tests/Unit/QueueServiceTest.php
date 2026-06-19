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
}
