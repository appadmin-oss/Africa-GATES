<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{CommunityService, SpamService};
use Illuminate\Database\Capsule\Manager as DB;

/** Polls: one per target (thread|post), 2–6 options, fp-deduped; single-answer switches, multi-answer toggles. */
class CommunityPollTest extends TestCase
{
    private CommunityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CommunityService(new SpamService());
        DB::table('gates_threads')->insert(['id' => 1, 'slug' => 't', 'title' => 'T', 'author_name' => 'A', 'author_email_hash' => 'x', 'status' => 'approved', 'last_activity' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00']);
    }

    public function test_create_poll_validates(): void
    {
        $this->assertFalse($this->svc->createPoll('thread', 1, '', ['a', 'b'])['ok']);
        $this->assertFalse($this->svc->createPoll('thread', 1, 'Q?', ['only-one'])['ok']);
        $this->assertFalse($this->svc->createPoll('bogus', 1, 'Q?', ['a', 'b'])['ok']); // bad target type
        $this->assertTrue($this->svc->createPoll('thread', 1, 'Q?', ['Yes', 'No', 'Maybe'])['ok']);
    }

    public function test_one_poll_per_target(): void
    {
        $this->assertTrue($this->svc->createPoll('thread', 1, 'Q?', ['a', 'b'])['ok']);
        $this->assertFalse($this->svc->createPoll('thread', 1, 'Q2?', ['c', 'd'])['ok']);
        // ...but a post with the same id is a different target.
        $this->assertTrue($this->svc->createPoll('post', 1, 'Q?', ['a', 'b'])['ok']);
    }

    public function test_vote_and_tally(): void
    {
        $pid = $this->svc->createPoll('thread', 1, 'Q?', ['Yes', 'No'])['id'];
        $this->assertTrue($this->svc->votePoll($pid, 0, 'fp1')['ok']);
        $this->assertTrue($this->svc->votePoll($pid, 0, 'fp2')['ok']);
        $this->assertTrue($this->svc->votePoll($pid, 1, 'fp3')['ok']);

        $poll = $this->svc->getPoll('thread', 1, 'fp1');
        $this->assertSame(3, $poll['total']);
        $this->assertSame(2, $poll['options'][0]['count']);
        $this->assertSame(1, $poll['options'][1]['count']);
        $this->assertSame(67, $poll['options'][0]['pct']);   // 2/3 → 67%
        $this->assertSame([0], $poll['my_votes']);           // fp1 chose option 0
        $this->assertSame(0, $poll['my_vote']);
    }

    public function test_single_answer_switch_is_deduped(): void
    {
        $pid = $this->svc->createPoll('thread', 1, 'Q?', ['Yes', 'No'])['id'];
        $this->svc->votePoll($pid, 0, 'fp1');
        $this->svc->votePoll($pid, 1, 'fp1');                // same fp switches
        $poll = $this->svc->getPoll('thread', 1, 'fp1');
        $this->assertSame(1, $poll['total']);                // still a single voter
        $this->assertSame([1], $poll['my_votes']);
    }

    public function test_multi_answer_toggles(): void
    {
        $pid = $this->svc->createPoll('thread', 1, 'Pick any', ['A', 'B', 'C'], true)['id'];
        $this->svc->votePoll($pid, 0, 'fp1');
        $this->svc->votePoll($pid, 2, 'fp1');                // fp1 now holds two selections
        $poll = $this->svc->getPoll('thread', 1, 'fp1');
        $this->assertSame(1, $poll['total']);                // one distinct voter
        $this->assertEqualsCanonicalizing([0, 2], $poll['my_votes']);
        $this->assertSame(1, $poll['options'][0]['count']);
        $this->assertSame(1, $poll['options'][2]['count']);

        $this->svc->votePoll($pid, 0, 'fp1');                // toggle option 0 back off
        $poll = $this->svc->getPoll('thread', 1, 'fp1');
        $this->assertSame([2], $poll['my_votes']);
    }

    public function test_set_poll_replaces_and_clears(): void
    {
        $this->svc->createPoll('post', 5, 'Old?', ['a', 'b']);
        $this->svc->setPoll('post', 5, 'New?', ['x', 'y', 'z']);
        $poll = $this->svc->getPoll('post', 5);
        $this->assertSame('New?', $poll['question']);
        $this->assertCount(3, $poll['options']);
        // Empty question clears the poll entirely.
        $this->svc->setPoll('post', 5, '', []);
        $this->assertNull($this->svc->getPoll('post', 5));
    }

    public function test_invalid_option_rejected(): void
    {
        $pid = $this->svc->createPoll('thread', 1, 'Q?', ['Yes', 'No'])['id'];
        $this->assertFalse($this->svc->votePoll($pid, 9, 'fp1')['ok']);
        $this->assertFalse($this->svc->votePoll($pid, -1, 'fp1')['ok']);
    }
}
