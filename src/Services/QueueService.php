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
        $jobs = DB::table('gates_jobs')
            ->where('status', 'pending')->where('run_after', '<=', $now)->whereNull('locked_at')
            ->orderBy('id')->limit($limit)->get();

        $done = 0; $failed = 0; $retried = 0;
        foreach ($jobs as $job) {
            // Claim: only one worker can flip locked_at from NULL.
            $claimed = DB::table('gates_jobs')->where('id', $job->id)->whereNull('locked_at')
                ->update(['locked_at' => $now, 'updated_at' => $now]);
            if (!$claimed) continue;

            try {
                $handler = $this->handlers[$job->type] ?? null;
                if (!$handler) throw new \RuntimeException("No handler registered for job type '{$job->type}'");
                $handler(json_decode((string) $job->payload, true) ?: []);
                DB::table('gates_jobs')->where('id', $job->id)->update([
                    'status' => 'done', 'locked_at' => null, 'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
                $done++;
            } catch (\Throwable $e) {
                $attempts = (int) $job->attempts + 1;
                $exhausted = $attempts >= self::MAX_ATTEMPTS;
                DB::table('gates_jobs')->where('id', $job->id)->update([
                    'status'     => $exhausted ? 'failed' : 'pending',
                    'attempts'   => $attempts,
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
