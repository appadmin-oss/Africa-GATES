<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Console\Commands\StandingsVerifyCommand;
use AfricaGates\Services\SnapshotService;
use AfricaGates\Support\Maintenance;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The chain is now READ, and reading it is what makes it evidence.
 *
 * SnapshotService::verify() existed from the beginning and had no caller anywhere
 * outside the unit tests — no cron, no doctor, no admin screen. So the hashes were
 * being written on every capture and checked by nobody: an alteration would have
 * been recorded exactly as designed and then sat in a column no query selected.
 *
 * These tests pin the two things that closed that loop: a command an operator can
 * run and quote, and a daily maintenance task that FAILS THE RUN when the answer is
 * no. Both are asserted on the honest outcome as well as the happy one — a check
 * that can only report good news is not a check.
 */
class StandingsChainWatchTest extends TestCase
{
    private function seedAndCapture(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 1, 'name' => 'A', 'status' => 'approved', 'vote_count' => 5, 'organic_vote_count' => 5],
            ['id' => 2, 'category_id' => 1, 'name' => 'B', 'status' => 'approved', 'vote_count' => 3, 'organic_vote_count' => 3],
        ]);
        (new SnapshotService())->capture();
    }

    public function test_the_command_confirms_an_intact_chain(): void
    {
        $this->seedAndCapture();

        $t = new CommandTester(new StandingsVerifyCommand());
        $this->assertSame(0, $t->execute([]));
        $this->assertStringContainsString('2 snapshot row(s) verified', $t->getDisplay());
    }

    public function test_the_command_exits_non_zero_on_a_broken_chain(): void
    {
        $this->seedAndCapture();
        $id = (int) DB::table('gates_vote_snapshots')->orderBy('id')->value('id');
        DB::table('gates_vote_snapshots')->where('id', $id)->update(['vote_count' => 999]);

        $t = new CommandTester(new StandingsVerifyCommand());
        $this->assertSame(1, $t->execute([]), 'a broken chain must be a non-zero exit, so cron can shout');
        $this->assertStringContainsString('THE CHAIN IS BROKEN', $t->getDisplay());
    }

    /**
     * The break is reported with the ordinary explanation first. Two concurrent
     * captures forking the chain looks identical to an edit, is far likelier, and
     * accusing somebody of tampering in a cron log would usually be wrong.
     */
    public function test_the_failure_points_at_the_likely_cause_before_blaming_anyone(): void
    {
        $this->seedAndCapture();
        $id = (int) DB::table('gates_vote_snapshots')->orderBy('id')->value('id');
        DB::table('gates_vote_snapshots')->where('id', $id)->update(['cpi_score' => 4242]);

        $t = new CommandTester(new StandingsVerifyCommand());
        $t->execute([]);
        // Symfony hard-wraps the block, so compare against the unwrapped text.
        $out = (string) preg_replace('/\s+/', ' ', $t->getDisplay());

        $this->assertStringContainsString('rule out the ordinary cause: two captures running at the same time', $out);
        $this->assertStringContainsString('two rows sharing a timestamp and a prev_hash is a fork, not an edit', $out);
    }

    public function test_json_output_carries_the_verdict(): void
    {
        $this->seedAndCapture();

        $t = new CommandTester(new StandingsVerifyCommand());
        $t->execute(['--json' => true]);
        $r = json_decode($t->getDisplay(), true);

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['checked']);
        $this->assertSame(0, $r['unchained']);
    }

    public function test_the_maintenance_task_reports_a_healthy_chain(): void
    {
        $this->seedAndCapture();

        $r = (new Maintenance(null, false))->run('chain');

        $this->assertSame([], $r['failures'], 'an intact chain is not a failure');
        $this->assertContains('chain', array_column($r['ran'], 0));
    }

    /**
     * THE POINT OF THE WHOLE EXERCISE. An altered archive must make the maintenance
     * run FAIL — which is what puts it in gates_cron_log and in the webcron response
     * body, the two places an operator with no shell can actually see it. Returning
     * a quiet 0 would be indistinguishable from a healthy run with nothing to do,
     * and that indistinguishability is exactly how the check went unread for so long.
     */
    public function test_a_broken_chain_fails_the_maintenance_run(): void
    {
        $this->seedAndCapture();
        $id = (int) DB::table('gates_vote_snapshots')->orderBy('id')->value('id');
        DB::table('gates_vote_snapshots')->where('id', $id)->update(['vote_count' => 12345]);

        $m = new Maintenance(null, false);
        $r = $m->run('chain');

        $this->assertArrayHasKey('chain', $r['failures']);
        $this->assertStringContainsString('STANDINGS CHAIN IS BROKEN', $r['failures']['chain']);
        $this->assertSame(Maintenance::TASK_FAILED, $r['ran'][0][1] ?? null,
            'a failed task reports the sentinel, not 0 — 0 means "ran fine, nothing to do"');

        // And it is durable: written where an operator without a shell can read it.
        $log = DB::table('gates_cron_log')->where('job_name', 'maintenance')->orderByDesc('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('error', (string) $log->status);
    }
}
