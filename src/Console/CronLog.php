<?php
declare(strict_types=1);
namespace AfricaGates\Console;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Records the outcome of a scheduled job into gates_cron_log so the admin can
 * see what ran, when, how long it took, and whether it succeeded. Best-effort:
 * a logging failure never breaks the job itself.
 */
final class CronLog {
    /** Run $fn, time it, and persist the result. Returns $fn's array result. */
    public static function run(string $job, callable $fn): array {
        $t0 = microtime(true);
        try {
            $result = $fn();
            self::write($job, 'success', (string)($result['message'] ?? 'ok'), $t0);
            return $result;
        } catch (\Throwable $e) {
            self::write($job, 'error', $e->getMessage(), $t0);
            throw $e;
        }
    }

    private static function write(string $job, string $status, string $message, float $t0): void {
        try {
            DB::table('gates_cron_log')->insert([
                'job_name'   => $job,
                'status'     => $status,
                'message'    => mb_substr($message, 0, 1000),
                'runtime_ms' => (int)round((microtime(true) - $t0) * 1000),
                'ran_at'     => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log("[cron:$job] log write failed: " . $e->getMessage());
        }
    }
}
