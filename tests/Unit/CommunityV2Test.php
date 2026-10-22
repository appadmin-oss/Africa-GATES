<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CommunityService;
use AfricaGates\Services\SpamService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Community v2: member attribution on posts, member reporting with the
 * quarantine threshold, own-post soft delete, thread locking, and the queued
 * reply notification for member-authored threads.
 */
final class CommunityV2Test extends TestCase
{
    private CommunityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CommunityService(new SpamService());
    }

    private function thread(array $over = []): array
    {
        return $this->svc->postThread(array_merge([
            'title' => 'Celebrating northern creatives',
            'body'  => 'Who should we be watching this cycle? Share the artists doing the work.',
            'author_name' => 'Ada Obi', 'author_email' => 'ada@x.io', 'author_user_id' => 7,
        ], $over));
    }

    public function test_member_attribution_is_stored_on_thread_and_reply(): void
    {
        $r = $this->thread();
        $this->assertTrue($r['ok']);
        $this->assertSame(7, (int) DB::table('gates_threads')->where('id', $r['id'])->value('author_user_id'));

        $reply = $this->svc->replyToThread($r['id'], [
            'author_name' => 'Chidi Okeke', 'author_email' => 'chidi@x.io', 'author_user_id' => 9,
            'body' => 'Great question — the Enugu collective deserves a look.',
        ]);
        $this->assertTrue($reply['ok']);
        $this->assertSame(9, (int) DB::table('gates_comments')->where('id', $reply['id'])->value('author_user_id'));
    }

    public function test_report_threshold_quarantines_content(): void
    {
        $r = $this->thread();
        // Two distinct reporters: still live.
        $this->svc->report('thread', $r['id'], 101);
        $out = $this->svc->report('thread', $r['id'], 102);
        $this->assertFalse($out['quarantined']);
        $this->assertSame('approved', DB::table('gates_threads')->where('id', $r['id'])->value('status'));
        // Same member reporting twice does NOT advance the count.
        $this->svc->report('thread', $r['id'], 102);
        $this->assertSame('approved', DB::table('gates_threads')->where('id', $r['id'])->value('status'));
        // Third distinct reporter crosses the threshold.
        $out = $this->svc->report('thread', $r['id'], 103);
        $this->assertTrue($out['quarantined']);
        $this->assertSame('quarantined', DB::table('gates_threads')->where('id', $r['id'])->value('status'));
        // Audit trail entry from the member-report provider.
        $this->assertSame(1, DB::table('gates_moderation_log')->where('provider', 'member-report')->where('target_id', $r['id'])->count());
    }

    public function test_delete_own_is_owner_gated_and_soft(): void
    {
        $r = $this->thread();
        $this->assertFalse($this->svc->deleteOwn('thread', $r['id'], 999)['ok'], 'stranger cannot delete');
        $this->assertTrue($this->svc->deleteOwn('thread', $r['id'], 7)['ok']);
        $this->assertSame('deleted', DB::table('gates_threads')->where('id', $r['id'])->value('status'));
        // Soft: the row still exists for the audit trail.
        $this->assertSame(1, DB::table('gates_threads')->where('id', $r['id'])->count());
    }

    public function test_deleting_own_reply_resyncs_reply_count(): void
    {
        $t = $this->thread();
        $reply = $this->svc->replyToThread($t['id'], [
            'author_name' => 'Chidi Okeke', 'author_email' => 'chidi@x.io', 'author_user_id' => 9,
            'body' => 'A reply that will be withdrawn shortly.',
        ]);
        $this->assertSame(1, (int) DB::table('gates_threads')->where('id', $t['id'])->value('reply_count'));
        $this->svc->deleteOwn('comment', $reply['id'], 9);
        $this->assertSame(0, (int) DB::table('gates_threads')->where('id', $t['id'])->value('reply_count'));
    }

    public function test_locked_thread_readable_but_not_replyable(): void
    {
        $t = $this->thread();
        $this->assertTrue($this->svc->setThreadFlag($t['id'], 'locked', true));
        $this->assertNotNull($this->svc->getThread($t['slug']), 'locked thread stays readable');
        $this->assertNotEmpty($this->svc->listThreads(), 'locked thread stays listed');

        $reply = $this->svc->replyToThread($t['id'], [
            'author_name' => 'Chidi Okeke', 'author_email' => 'chidi@x.io', 'body' => 'Can I still get in here?',
        ]);
        $this->assertFalse($reply['ok']);
        // Unlock restores replies.
        $this->assertTrue($this->svc->setThreadFlag($t['id'], 'locked', false));
        $this->assertTrue($this->svc->replyToThread($t['id'], [
            'author_name' => 'Chidi Okeke', 'author_email' => 'chidi@x.io', 'body' => 'Back in business, thanks mods.',
        ])['ok']);
    }

    public function test_reply_to_member_thread_queues_notification(): void
    {
        $t = $this->thread(); // author_user_id = 7
        $this->svc->replyToThread($t['id'], [
            'author_name' => 'Chidi Okeke', 'author_email' => 'chidi@x.io',
            'body' => 'Adding a thoughtful reply so the author hears about it.',
        ]);
        $job = DB::table('gates_jobs')->where('type', 'community.reply_email')->first();
        $this->assertNotNull($job);
        $p = json_decode((string) $job->payload, true);
        $this->assertSame(7, $p['author_user_id']);
        $this->assertSame($t['slug'], $p['slug']);
    }

    public function test_guest_thread_without_member_id_queues_nothing(): void
    {
        $t = $this->thread(['author_user_id' => null]);
        $this->svc->replyToThread($t['id'], [
            'author_name' => 'Chidi Okeke', 'author_email' => 'chidi@x.io',
            'body' => 'Replying to a legacy anonymous thread here.',
        ]);
        $this->assertSame(0, DB::table('gates_jobs')->where('type', 'community.reply_email')->count());
    }
}
