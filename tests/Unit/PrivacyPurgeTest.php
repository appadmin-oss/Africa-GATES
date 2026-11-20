<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Console\Commands\PrivacyPurgeCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Retention purge is SAFE BY DEFAULT: it is dry-run unless --commit, and only
 * touches a table when a retention window is configured (or has a built-in
 * default for transient data like drafts). Records with legal/integrity/public
 * value are never auto-purged.
 */
class PrivacyPurgeTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['RETAIN_DRAFT_DAYS'], $_ENV['RETAIN_REJECTED_NOMINATION_DAYS']);
        parent::tearDown();
    }

    private function seedDraft(string $key, string $updatedAt): void
    {
        DB::table('gates_nomination_drafts')->insert([
            'session_key' => $key, 'payload' => '{}', 'updated_at' => $updatedAt,
        ]);
    }

    private function runCmd(array $opts = []): CommandTester
    {
        $t = new CommandTester(new PrivacyPurgeCommand());
        $t->execute($opts);
        return $t;
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->seedDraft('old', '2000-01-01 00:00:00');
        $this->seedDraft('fresh', Carbon::now()->toDateTimeString());
        $this->runCmd([]); // no --commit
        $this->assertSame(2, DB::table('gates_nomination_drafts')->count());
    }

    public function test_commit_purges_only_rows_past_retention(): void
    {
        $this->seedDraft('old', '2000-01-01 00:00:00');
        $this->seedDraft('fresh', Carbon::now()->toDateTimeString());
        $this->runCmd(['--commit' => true]); // drafts have a built-in 30-day default
        $this->assertSame(['fresh'], DB::table('gates_nomination_drafts')->pluck('session_key')->all());
    }

    public function test_unconfigured_tables_are_skipped(): void
    {
        // No retention env set for nominations/partner enquiries → never purged,
        // even with --commit.
        $t = $this->runCmd(['--commit' => true]);
        $display = $t->getDisplay();
        $this->assertStringContainsString('gates_nominations', $display);
        $this->assertStringContainsString('gates_partner_enquiries', $display);
        $this->assertSame(0, $t->getStatusCode());
    }
}
