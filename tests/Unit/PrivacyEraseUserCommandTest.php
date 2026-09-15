<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Console\Commands\PrivacyEraseUserCommand;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Tester\CommandTester;

/** Right-to-erasure: anonymises a member's PII, preserves the row + accounting. */
class PrivacyEraseUserCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_users')->insert(['id' => 1, 'name' => 'Ada Obi', 'email' => 'ada@x.io', 'phone' => '+2348030000000', 'points' => 250, 'status' => 'active', 'last_login_ip' => '1.2.3.4']);
        DB::table('gates_comments')->insert(['target_type' => 'thread', 'target_id' => 1, 'author_name' => 'Ada Obi', 'author_email' => 'ada@x.io', 'author_user_id' => 1, 'body' => 'hi', 'status' => 'approved']);
        DB::table('gates_threads')->insert(['slug' => 't1', 'title' => 'T', 'author_name' => 'Ada Obi', 'author_email_hash' => 'abc', 'author_user_id' => 1, 'status' => 'approved']);
    }

    private function tester(array $input): CommandTester
    {
        $t = new CommandTester(new PrivacyEraseUserCommand());
        $t->execute($input);
        return $t;
    }

    public function test_dry_run_changes_nothing(): void
    {
        $t = $this->tester(['user' => '1']);
        $this->assertSame('ada@x.io', DB::table('gates_users')->where('id', 1)->value('email'));
        $this->assertStringContainsStringIgnoringCase('dry-run', $t->getDisplay());
    }

    public function test_commit_anonymises_pii_but_keeps_row_and_points(): void
    {
        $this->tester(['user' => 'ada@x.io', '--commit' => true]);

        $u = DB::table('gates_users')->where('id', 1)->first();
        $this->assertSame('Deleted member', $u->name);
        $this->assertSame('erased+1@deleted.invalid', $u->email);
        $this->assertNull($u->phone);
        $this->assertNull($u->last_login_ip);
        $this->assertSame('erased', $u->status);
        $this->assertSame(250, (int) $u->points);            // accounting preserved

        // Authored community content is scrubbed of the member's name/email.
        $c = DB::table('gates_comments')->where('author_user_id', 1)->first();
        $this->assertSame('Deleted member', $c->author_name);
        $this->assertNull($c->author_email);
        $this->assertSame('Deleted member', DB::table('gates_threads')->where('author_user_id', 1)->value('author_name'));
    }

    public function test_unknown_user_fails_cleanly(): void
    {
        $t = $this->tester(['user' => 'nobody@nowhere.io']);
        $this->assertSame(1, $t->getStatusCode()); // Command::FAILURE
    }
}
