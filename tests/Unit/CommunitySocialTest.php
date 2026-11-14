<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{CommunityService, SpamService};
use Illuminate\Database\Capsule\Manager as DB;

/** Member-scoped social actions: follow, bookmark, repost (with count sync). */
class CommunitySocialTest extends TestCase
{
    private CommunityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CommunityService(new SpamService());
        DB::table('gates_threads')->insert(['id' => 1, 'slug' => 't', 'title' => 'T', 'author_name' => 'A', 'author_email_hash' => 'x', 'status' => 'approved', 'last_activity' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00']);
    }

    public function test_follow_toggle(): void
    {
        $this->assertTrue($this->svc->toggleFollow(5, 'thread', 1)['following']);
        $this->assertTrue($this->svc->isFollowing(5, 'thread', 1));
        $this->assertFalse($this->svc->toggleFollow(5, 'thread', 1)['following']);
        $this->assertFalse($this->svc->isFollowing(5, 'thread', 1));
    }

    public function test_follow_invalid_target(): void
    {
        $this->assertFalse($this->svc->toggleFollow(5, 'banana', 1)['ok']);
        $this->assertFalse($this->svc->toggleFollow(0, 'thread', 1)['ok']);
    }

    public function test_bookmark_toggle(): void
    {
        $this->assertTrue($this->svc->toggleBookmark(5, 1)['bookmarked']);
        $this->assertTrue($this->svc->isBookmarked(5, 1));
        $this->assertFalse($this->svc->toggleBookmark(5, 1)['bookmarked']);
    }

    public function test_repost_toggle_and_count(): void
    {
        $this->svc->toggleRepost(5, 1);
        $this->svc->toggleRepost(6, 1);
        $this->assertSame(2, (int)DB::table('gates_threads')->where('id', 1)->value('repost_count'));
        $this->assertFalse($this->svc->toggleRepost(5, 1)['reposted']);  // un-repost
        $this->assertSame(1, (int)DB::table('gates_threads')->where('id', 1)->value('repost_count'));
    }

    public function test_member_thread_state(): void
    {
        $this->svc->toggleBookmark(5, 1);
        $this->svc->toggleRepost(5, 1);
        $this->svc->toggleFollow(5, 'thread', 1);
        $st = $this->svc->memberThreadState(5, [1]);
        $this->assertContains(1, $st['bookmarked']);
        $this->assertContains(1, $st['reposted']);
        $this->assertContains(1, $st['following']);
        // A different member sees none of it.
        $empty = $this->svc->memberThreadState(99, [1]);
        $this->assertSame([], $empty['bookmarked']);
    }
}
