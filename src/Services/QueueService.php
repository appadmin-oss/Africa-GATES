<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Minimal durable job queue (gates_jobs) for moving slow side-effects off the
 * request hot path. A cron drains it via work(); the interface is deliberately
 * small so it can later be backed by Redis/SQS without changing callers.
 */
class QueueService
{
    private const MAX_ATTEMPTS = 5;

    /** Seconds after which a held lock is assumed dead (worker crashed) and reclaimable. */
    private const LOCK_TIMEOUT = 300;

    /** @var array<string, callable> */
    private array $handlers = [];

    /** Enqueue a job. Returns its id. */
    public function push(string $type, array $payload = [], int $delaySeconds = 0): int
    {
        $now = Carbon::now();
        return (int) DB::table('gates_jobs')->insertGetId([
            'type'       => $type,
            'payload'    => json_encode($payload),
            'status'     => 'pending',
            'attempts'   => 0,
            'run_after'  => $now->copy()->addSeconds(max(0, $delaySeconds))->toDateTimeString(),
            'created_at'  => $now->toDateTimeString(),
            'updated_at'  => $now->toDateTimeString(),
        ]);
    }

    /** Register a handler for a job type. The handler receives the decoded payload. */
    public function on(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * Process up to $limit due jobs. Each is claimed with an optimistic lock so
     * concurrent workers don't double-run it; failures retry with linear backoff
     * up to MAX_ATTEMPTS, then land in 'failed'.
     * @return array{done:int, failed:int, retried:int}
     */
    public function work(int $limit = 20): array
    {
        $now = Carbon::now()->toDateTimeString();
        // A lock older than LOCK_TIMEOUT means the worker died holding it — reclaim.
        $staleBefore = Carbon::now()->subSeconds(self::LOCK_TIMEOUT)->toDateTimeString();
        $reclaimable = static function ($q) use ($staleBefore) {
            $q->whereNull('locked_at')->orWhere('locked_at', '<', $staleBefore);
        };

        $jobs = DB::table('gates_jobs')
            ->where('status', 'pending')->where('run_after', '<=', $now)->where($reclaimable)
            ->orderBy('id')->limit($limit)->get();

        $done = 0; $failed = 0; $retried = 0;
        foreach ($jobs as $job) {
            // Poison pill: a job that already burned all attempts (e.g. crashed the
            // worker mid-handler every time) must be failed, not reaped forever.
            if ((int) $job->attempts >= self::MAX_ATTEMPTS) {
                DB::table('gates_jobs')->where('id', $job->id)->update([
                    'status' => 'failed', 'locked_at' => null,
                    'last_error' => 'exceeded max attempts (likely crashed mid-handler)',
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
                $failed++;
                continue;
            }

            // Claim: flip the lock only if it's still free or still stale (so a fresh
            // claim by another worker wins the race). Increment attempts AT CLAIM so a
            // worker that dies mid-handler still burns an attempt — no infinite loop.
            $claimed = DB::table('gates_jobs')->where('id', $job->id)
                ->where('status', 'pending')->where($reclaimable)
                ->update(['locked_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);
            if (!$claimed) continue;

            $attempts = (int) $job->attempts + 1; // reflects the claim-time increment

            try {
                $handler = $this->handlers[$job->type] ?? null;
                if (!$handler) throw new \RuntimeException("No handler registered for job type '{$job->type}'");
                $handler(json_decode((string) $job->payload, true) ?: []);
                DB::table('gates_jobs')->where('id', $job->id)->update([
                    'status' => 'done', 'locked_at' => null, 'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
                $done++;
            } catch (\Throwable $e) {
                // attempts already incremented at claim — don't double-count.
                $exhausted = $attempts >= self::MAX_ATTEMPTS;
                DB::table('gates_jobs')->where('id', $job->id)->update([
                    'status'     => $exhausted ? 'failed' : 'pending',
                    'locked_at'  => null,
                    'run_after'  => Carbon::now()->addSeconds(60 * $attempts)->toDateTimeString(),
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
                $exhausted ? $failed++ : $retried++;
            }
        }
        return ['done' => $done, 'failed' => $failed, 'retried' => $retried];
    }
}
