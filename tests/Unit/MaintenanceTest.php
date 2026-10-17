<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Support\Maintenance;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The shared maintenance orchestrator behind both the CLI hub and the webcron
 * endpoint: named tasks run, log to gates_cron_log, and have their real effect.
 * (Clock-based 'auto' selection isn't pinned here — it depends on the wall clock.)
 */
class MaintenanceTest extends TestCase
{
    public function test_cache_task_prunes_expired_rows_and_logs(): void
    {
        DB::table('gates_cache')->insert([
            ['cache_key' => 'stale', 'payload' => '{}', 'expires_at' => '2000-01-01 00:00:00'],
            // 2037, not 2999: MySQL's TIMESTAMP ceiling is 2038-01-19 03:14:07 and it
            // REJECTS anything beyond it, while SQLite stores the text happily. The
            // fixture only needs to mean "comfortably unexpired", so it costs nothing
            // to stay inside the range both drivers can represent.
            ['cache_key' => 'fresh', 'payload' => '{}', 'expires_at' => '2037-01-01 00:00:00'],
        ]);

        $r = (new Maintenance(null, false))->run('cache');

        $this->assertNull(DB::table('gates_cache')->where('cache_key', 'stale')->first(), 'expired row pruned');
        $this->assertNotNull(DB::table('gates_cache')->where('cache_key', 'fresh')->first(), 'live row kept');
        $this->assertSame('cache', $r['task']);
        $this->assertContains('cache', array_column($r['ran'], 0));
        // Every run records to the cron log.
        $this->assertSame(1, (int) DB::table('gates_cron_log')->where('job_name', 'maintenance')->count());
    }

    public function test_digest_task_writes_a_daily_activity_row(): void
    {
        (new Maintenance(null, false))->run('digest');
        $row = DB::table('gates_activity')->where('target_label', 'Daily digest')->first();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->is_public, 'digest is admin-only, not public');
    }

    public function test_unknown_task_is_handled_gracefully(): void
    {
        $r = (new Maintenance(null, false))->run('does-not-exist');
        $this->assertSame('does-not-exist', $r['task']);
        $this->assertSame([], $r['ran']);
        $this->assertNotEmpty($r['lines']);   // it logged "Unknown task" + "Done."
    }

    public function test_tick_is_gated_by_the_setting_then_runs(): void
    {
        $sentinel = dirname(__DIR__, 2) . '/var/data/.maintenance_tick';
        @unlink($sentinel);

        // Disabled (no setting) → skips BEFORE any lock/work.
        DB::table('gates_settings')->where('key_name', 'webcron_auto')->delete();
        $this->assertSame('disabled', (Maintenance::tick(null))['skipped'] ?? null);
        $this->assertFalse(Maintenance::autoEnabled());

        // Enabled + nothing run recently → it actually runs and logs.
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'webcron_auto'], ['value' => '1']);
        @unlink($sentinel);
        $this->assertTrue(Maintenance::autoEnabled());
        $r = Maintenance::tick(null);
        $this->assertArrayNotHasKey('skipped', $r, 'should run when enabled and due');
        $this->assertSame('auto', $r['task'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) DB::table('gates_cron_log')->where('job_name', 'maintenance')->count());
    }
    /**
     * Run $task with every query touching $table throwing, as a broken table would.
     *
     * beforeExecuting rather than a dropped table: the services behind these tasks treat
     * a MISSING table as "not set up here" and return 0 on purpose, which is right — the
     * fault this pins is a task that hits a real error and reports it as a quiet night.
     *
     * @return array<string,mixed>
     */
    private function runWithBrokenTable(string $task, string $table): array
    {
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, $table): void {
            if ($armed && str_contains($sql, $table) && stripos(ltrim($sql), 'update') === 0) {
                throw new \RuntimeException("simulated failure on {$table}");
            }
        });
        try {
            return (new Maintenance(null, false))->run($task);
        } finally {
            $armed = false;   // the callback outlives this test on the shared connection
        }
    }

    /**
     * A TASK THAT CATCHES ITS OWN CRASH AND RETURNS 0 IS A HEALTHY-LOOKING CRON.
     *
     * Ten tasks wrapped their body in `catch (Throwable) { log; return 0; }`, so a crash
     * arrived at task() as "ran, nothing to do": `failures` empty, gates_cron_log
     * `success`, /__cron/run `ok:true`. Stand offers is one of them, chosen because its
     * update is reachable with a single fixture row.
     */
    public function test_a_task_that_fails_is_reported_as_failed_not_as_nothing_to_do(): void
    {
        DB::table('gates_stand_applications')->insert([
            'call_id' => 1, 'event_id' => 1, 'org_id' => 1, 'stand_type_id' => 1,
            'decision' => \AfricaGates\Services\StandApplication::DECISION_OFFERED,
            'offer_expires_at' => '2001-01-01 00:00:00', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $r = $this->runWithBrokenTable('standoffers', 'gates_stand_applications');

        $this->assertArrayHasKey('standoffers', $r['failures'] ?? [],
            'a crashed task was reported as a quiet tick');
        $this->assertSame([['standoffers', Maintenance::TASK_FAILED]], $r['ran']);
        $this->assertSame('error', (string) DB::table('gates_cron_log')->where('job_name', 'maintenance')
            ->orderByDesc('id')->value('status'));
    }

    /** And a task that legitimately decides not to run still reports 0, not a failure. */
    public function test_a_switched_off_task_is_not_a_failure(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'auto_refund_unminted'], ['value' => '0']);
        $r = (new Maintenance(null, false))->run('refunds');
        if (\AfricaGates\Services\RefundService::autoEnabled()) {
            $this->markTestSkipped('automatic refunds cannot be switched off from this fixture');
        }
        $this->assertSame([], $r['failures'] ?? []);
        $this->assertSame([['refunds', 0]], $r['ran']);
    }
    /** A Maintenance whose container hands out a mailer that records and never sends. */
    private function withRecordingMailer(array &$sent, bool $throw = false): Maintenance
    {
        $mailer = new class($sent, $throw) extends \AfricaGates\Services\OtpService {
            public function __construct(private array &$sink, private bool $throw) { parent::__construct([]); }
            public function canSend(bool $bulk = false): bool { return true; }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                if ($this->throw) throw new \RuntimeException('transport down');
                $this->sink[] = $to;
                return ['success' => true];
            }
        };
        $c = new class($mailer) implements \Psr\Container\ContainerInterface {
            public function __construct(private object $m) {}
            public function get(string $id) { return $this->m; }
            public function has(string $id): bool { return $id === \AfricaGates\Services\OtpService::class; }
        };
        return new Maintenance($c, false);
    }

    private function staleNomination(): int
    {
        return (int) DB::table('gates_nominations')->insertGetId([
            'cycle_id' => 1, 'nominee_name' => 'Ada Obi', 'nominee_email' => 'ada@x.io',
            'reason' => 'Founded a coding school for girls.',
            'nominator_name' => 'A Person', 'nominator_email' => 'p@x.io',
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s', time() - 400 * 3600),
        ]);
    }

    /**
     * CLAIM BEFORE SEND. Two passes reading the same unacknowledged list both mailed the
     * nominator before either stamped. Here the other pass's stamp lands after our read
     * and before our claim; ours must not send.
     */
    public function test_an_acknowledgement_another_pass_claimed_is_not_sent_again(): void
    {
        $id = $this->staleNomination();
        $sent = [];
        $m = $this->withRecordingMailer($sent);

        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, $id): void {
            if ($armed && stripos(ltrim($sql), 'update') === 0 && str_contains($sql, 'gates_nominations')) {
                $armed = false;
                DB::table('gates_nominations')->where('id', $id)->update(['nominator_ack_at' => date('Y-m-d H:i:s')]);
            }
        });
        try {
            $n = (new \ReflectionMethod(Maintenance::class, 'sendPendingAcknowledgements'))->invoke($m);
            $fired = !$armed;
        } finally {
            $armed = false;
        }

        $this->assertTrue($fired, 'the interleaving never happened, so this test proves nothing');
        $this->assertSame(0, $n);
        $this->assertSame([], $sent, 'a nominator was acknowledged twice');
    }

    public function test_a_failed_acknowledgement_is_tried_again_next_run(): void
    {
        $id = $this->staleNomination();
        $sent = [];
        (new \ReflectionMethod(Maintenance::class, 'sendPendingAcknowledgements'))->invoke($this->withRecordingMailer($sent, true));
        $this->assertNull(DB::table('gates_nominations')->where('id', $id)->value('nominator_ack_at'),
            'a send that threw kept its claim, so the nominator is never acknowledged');

        (new \ReflectionMethod(Maintenance::class, 'sendPendingAcknowledgements'))->invoke($this->withRecordingMailer($sent));
        $this->assertSame(['p@x.io'], $sent);
        $this->assertNotNull(DB::table('gates_nominations')->where('id', $id)->value('nominator_ack_at'));
    }
}
