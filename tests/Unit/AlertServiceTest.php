<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\AlertService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Alerts: derived on read, scoped to you, and grouped so they are readable.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS ARE PROTECTING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Alerts are computed from six tables rather than stored, which buys correctness
 * — an alert cannot outlive the thing it is about — at the cost of six places to
 * get a WHERE clause wrong. Two of those clauses matter more than the rest:
 *
 *   • "my threads" — get it wrong and you are told about strangers' posts, or
 *     worse, shown the names of people who interacted with somebody else's;
 *   • "not me" — get it wrong and every action you take notifies you about
 *     yourself, which is how a notifications screen becomes noise nobody opens.
 *
 * The third property is grouping. Forty-three separate "X reacted to your post"
 * rows is not a screen, it is a punishment, and the fold happens in PHP because
 * MySQL 5.7 on the target host has no window functions.
 */
final class AlertServiceTest extends TestCase
{
    private AlertService $alerts;

    private const ME    = 'me@example.test';
    private const OTHER = 'other@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->alerts = new AlertService();
        foreach (['gates_threads','gates_cheers','gates_comments','gates_reposts',
                  'gates_bookmarks','gates_follows','gates_users','gates_profiles'] as $t) {
            DB::table($t)->delete();
        }
    }

    private function user(int $id, string $name, string $email): int
    {
        DB::table('gates_users')->insert([
            'id' => $id, 'name' => $name, 'email' => $email,
            'status' => 'active', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    private function thread(int $authorId, string $slug = 'mine'): int
    {
        return (int) DB::table('gates_threads')->insertGetId([
            'slug' => $slug, 'title' => 'A post', 'body' => 'Body',
            'author_name' => 'Author', 'author_email_hash' => hash('sha256', $slug),
            'author_user_id' => $authorId, 'status' => 'approved',
            'cheer_count' => 0, 'reply_count' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function reactedBy(int $threadId, int $userId, string $when = 'now'): void
    {
        DB::table('gates_cheers')->insert([
            'target_type' => 'thread', 'target_id' => $threadId, 'fp' => 'u:' . $userId,
            'kind' => 'cheer', 'created_at' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }

    /** @return list<string> */
    private function texts(): array
    {
        return array_column($this->alerts->forMember(1, self::ME), 'text');
    }

    // ── scoping ──────────────────────────────────────────────────────────────

    public function test_a_guest_gets_nothing(): void
    {
        $this->assertSame([], $this->alerts->forMember(0, ''));
        $this->assertSame(0, $this->alerts->unreadFor(0, ''));
    }

    public function test_you_are_told_about_reactions_to_your_own_posts(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        $this->reactedBy($this->thread(1), 2);

        $this->assertSame(['Adaeze reacted to your post'], $this->texts());
    }

    public function test_you_are_not_told_about_reactions_to_somebody_elses_post(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        $this->reactedBy($this->thread(2, 'theirs'), 1);   // I reacted to THEIR post

        $this->assertSame([], $this->texts(),
            'the feed must never hand one member the activity on another member’s post');
    }

    public function test_your_own_reaction_to_your_own_post_is_not_news(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->reactedBy($this->thread(1), 1);

        $this->assertSame([], $this->texts());
    }

    public function test_your_own_reply_to_your_own_post_is_not_news(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $id = $this->thread(1);
        DB::table('gates_comments')->insert([
            'target_type' => 'thread', 'target_id' => $id, 'author_name' => 'Amara Test',
            'author_user_id' => 1, 'body' => 'Adding to this', 'status' => 'approved',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], $this->texts());
    }

    public function test_a_quarantined_reply_is_not_announced(): void
    {
        // Telling somebody they have a reply that no moderator has cleared shows
        // them content the platform has not published.
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        $id = $this->thread(1);
        DB::table('gates_comments')->insert([
            'target_type' => 'thread', 'target_id' => $id, 'author_name' => 'Adaeze',
            'author_user_id' => 2, 'body' => 'held', 'status' => 'quarantined',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], $this->texts());
    }

    // ── grouping ─────────────────────────────────────────────────────────────

    public function test_many_reactions_to_one_post_are_one_line(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $id = $this->thread(1);
        for ($u = 2; $u <= 6; $u++) {
            $this->user($u, 'Person' . $u . ' Surname', 'p' . $u . '@example.test');
            $this->reactedBy($id, $u, '-' . $u . ' minutes');
        }

        $a = $this->alerts->forMember(1, self::ME);

        $this->assertCount(1, $a, 'five rows saying the same thing is not a screen');
        $this->assertSame(5, $a[0]['actors']);
        $this->assertSame('Person2 and 4 others reacted to your post', $a[0]['text'],
            'the most recent actor is named, and it is a sentence rather than a list');
    }

    public function test_one_reaction_names_the_person_without_an_others_clause(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        $this->reactedBy($this->thread(1), 2);

        $this->assertStringNotContainsString('other', $this->texts()[0]);
    }

    public function test_reactions_to_different_posts_stay_separate(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        $this->reactedBy($this->thread(1, 'one'), 2, '-1 minute');
        $this->reactedBy($this->thread(1, 'two'), 2, '-2 minutes');

        $this->assertCount(2, $this->alerts->forMember(1, self::ME),
            'grouping is per post — folding two posts into one loses which one');
    }

    public function test_follows_group_per_follower_not_per_target(): void
    {
        // Keyed on the target, fifty different people would collapse into one
        // permanent line reading "Adaeze and 49 others started following you".
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        $this->user(3, 'Ibrahim Sule', 'ib@example.test');
        $pid = (int) DB::table('gates_profiles')->insertGetId([
            'slug' => 'amara', 'display_name' => 'Amara Test', 'email' => self::ME,
            'status' => 'approved',
        ]);
        foreach ([2, 3] as $u) {
            DB::table('gates_follows')->insert([
                'user_id' => $u, 'target_type' => 'profile', 'target_id' => $pid,
                'created_at' => date('Y-m-d H:i:s', strtotime('-' . $u . ' minutes')),
            ]);
        }

        $texts = $this->texts();

        $this->assertCount(2, $texts);
        $this->assertContains('Adaeze started following you', $texts);
        $this->assertContains('Ibrahim started following you', $texts);
    }

    public function test_someone_following_a_different_profile_is_not_your_alert(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        DB::table('gates_profiles')->insert([
            'slug' => 'amara', 'display_name' => 'Amara Test', 'email' => self::ME,
            'status' => 'approved',
        ]);
        $theirs = (int) DB::table('gates_profiles')->insertGetId([
            'slug' => 'adaeze', 'display_name' => 'Adaeze', 'email' => self::OTHER,
            'status' => 'approved',
        ]);
        DB::table('gates_follows')->insert([
            'user_id' => 1, 'target_type' => 'profile', 'target_id' => $theirs,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], $this->texts());
    }

    // ── the read watermark ───────────────────────────────────────────────────

    public function test_everything_is_unread_until_you_open_it(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        $this->reactedBy($this->thread(1), 2);

        $this->assertTrue($this->alerts->forMember(1, self::ME)[0]['unread'],
            'a null watermark means never opened, which is everything unread');
        $this->assertSame(1, $this->alerts->unreadFor(1, self::ME));
    }

    public function test_marking_read_clears_what_was_already_there(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        $this->reactedBy($this->thread(1), 2, '-5 minutes');

        $this->assertTrue($this->alerts->markRead(1));

        $this->assertSame(0, $this->alerts->unreadFor(1, self::ME));
        $this->assertFalse($this->alerts->forMember(1, self::ME)[0]['unread'],
            'the row stays on screen — it just stops being highlighted');
    }

    public function test_something_that_happens_after_you_read_is_unread_again(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Adaeze Okonkwo', self::OTHER);
        $id = $this->thread(1);
        $this->reactedBy($id, 2, '-10 minutes');
        $this->alerts->markRead(1);

        $this->user(3, 'Ibrahim Sule', 'ib@example.test');
        $this->reactedBy($this->thread(1, 'later'), 3, '+2 minutes');

        $this->assertSame(1, $this->alerts->unreadFor(1, self::ME));
    }

    public function test_the_badge_and_the_list_can_never_disagree(): void
    {
        // unreadFor() counts what forMember() returns rather than running its own
        // cheaper query. A badge saying 3 over a screen showing 2 is worse than
        // no badge at all.
        $this->user(1, 'Amara Test', self::ME);
        for ($u = 2; $u <= 5; $u++) {
            $this->user($u, 'Person' . $u . ' S', 'p' . $u . '@example.test');
            $this->reactedBy($this->thread(1, 'post-' . $u), $u, '-' . $u . ' minutes');
        }

        $list  = $this->alerts->forMember(1, self::ME);
        $shown = count(array_filter($list, static fn($a) => $a['unread']));

        $this->assertSame($shown, $this->alerts->unreadFor(1, self::ME));
    }

    // ── ordering ─────────────────────────────────────────────────────────────

    public function test_newest_first(): void
    {
        $this->user(1, 'Amara Test', self::ME);
        $this->user(2, 'Old Person', 'old@example.test');
        $this->user(3, 'New Person', 'new@example.test');
        $this->reactedBy($this->thread(1, 'older'), 2, '-3 hours');
        $this->reactedBy($this->thread(1, 'newer'), 3, '-1 minute');

        $this->assertSame('New reacted to your post', $this->texts()[0]);
    }
}
