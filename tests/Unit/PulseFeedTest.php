<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\PulseFeedService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The feed assembler.
 *
 * The properties worth pinning are the ones that go wrong silently: paging that
 * duplicates rows when someone posts mid-scroll, and per-viewer state — a like
 * or a save — leaking from one member to another. A feed that shows you someone
 * else's likes is not a cosmetic bug; it is a privacy failure that looks
 * completely normal on screen.
 */
final class PulseFeedTest extends TestCase
{
    private PulseFeedService $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new PulseFeedService();
        DB::table('gates_threads')->delete();
        DB::table('gates_comments')->delete();
        DB::table('gates_cheers')->delete();
        DB::table('gates_bookmarks')->delete();
    }

    /** @return list<int> ids, oldest first */
    private function seed(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = (int) DB::table('gates_threads')->insertGetId([
                'slug' => 'post-' . $i, 'title' => 'Post ' . $i, 'body' => 'Body ' . $i,
                'author_name' => 'Member ' . $i,
                'author_email_hash' => hash('sha256', "m{$i}@example.test"),
                'status' => 'approved', 'cheer_count' => 0, 'reply_count' => 0,
                'created_at' => date('Y-m-d H:i:s', time() - 60 * ($n - $i)),
            ]);
        }
        return $ids;
    }

    public function test_a_page_comes_back_newest_first_with_a_cursor(): void
    {
        $ids = $this->seed(10);
        $page = $this->feed->page(null, 4);

        $this->assertCount(4, $page['items']);
        $this->assertSame(end($ids), $page['items'][0]['id'], 'newest post leads the feed');
        $this->assertSame($page['items'][3]['id'], $page['next_cursor'], 'the cursor is the last id returned');
    }

    public function test_the_last_page_reports_no_cursor(): void
    {
        $this->seed(3);
        $page = $this->feed->page(null, 10);
        $this->assertCount(3, $page['items']);
        $this->assertNull($page['next_cursor'], 'nothing left means no cursor, so the client stops asking');
    }

    /**
     * The reason paging is a cursor and not an OFFSET.
     *
     * With OFFSET, a post arriving between page 1 and page 2 shifts every row
     * down one and the reader sees the last post of page 1 again at the top of
     * page 2. On a live feed that is not an edge case, it is Tuesday.
     */
    public function test_a_post_arriving_mid_scroll_does_not_duplicate_a_row(): void
    {
        $this->seed(6);
        $first = $this->feed->page(null, 3);

        DB::table('gates_threads')->insert([
            'slug' => 'interloper', 'title' => 'Posted mid-scroll', 'body' => 'Landed between pages',
            'author_name' => 'Latecomer', 'author_email_hash' => hash('sha256', 'late@example.test'),
            'status' => 'approved', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $second = $this->feed->page($first['next_cursor'], 3);

        $seen = array_column($first['items'], 'id');
        foreach ($second['items'] as $it) {
            $this->assertNotContains($it['id'], $seen, 'no row may appear on two pages');
        }
    }

    public function test_only_approved_posts_are_in_the_feed(): void
    {
        $this->seed(2);
        DB::table('gates_threads')->insert([
            'slug' => 'held', 'title' => 'Held for review', 'body' => 'Quarantined',
            'author_name' => 'Someone', 'author_email_hash' => hash('sha256', 's@example.test'),
            'status' => 'quarantined', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $slugs = array_column($this->feed->page(null, 20)['items'], 'slug');
        $this->assertNotContains('held', $slugs, 'a quarantined post must never reach the public feed');
    }

    /** A like is per-viewer. Seeing someone else's is a privacy failure. */
    public function test_cheered_and_saved_are_scoped_to_the_viewer(): void
    {
        $ids = $this->seed(2);

        DB::table('gates_cheers')->insert([
            'target_type' => 'thread', 'target_id' => $ids[0], 'fp' => 'u:7',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('gates_bookmarks')->insert([
            'user_id' => 7, 'thread_id' => $ids[0], 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $mine  = array_column($this->feed->page(null, 10, 7)['items'], null, 'id');
        $other = array_column($this->feed->page(null, 10, 8)['items'], null, 'id');
        $guest = array_column($this->feed->page(null, 10, null)['items'], null, 'id');

        $this->assertTrue($mine[$ids[0]]['cheered']);
        $this->assertTrue($mine[$ids[0]]['saved']);
        $this->assertFalse($other[$ids[0]]['cheered'], "member 8 must not see member 7's cheer");
        $this->assertFalse($other[$ids[0]]['saved'],   "member 8 must not see member 7's save");
        $this->assertFalse($guest[$ids[0]]['cheered'], 'a guest has no state at all');
    }

    public function test_comments_are_previewed_oldest_first_and_capped(): void
    {
        $ids = $this->seed(1);
        foreach (['first', 'second', 'third'] as $i => $body) {
            DB::table('gates_comments')->insert([
                'target_type' => 'thread', 'target_id' => $ids[0],
                'author_name' => 'Commenter ' . $i, 'body' => $body, 'status' => 'approved',
                'created_at' => date('Y-m-d H:i:s', time() - 60 * (3 - $i)),
            ]);
        }

        $item = $this->feed->page(null, 5)['items'][0];
        $this->assertCount(PulseFeedService::PREVIEW_COMMENTS, $item['comments']);
        // The two NEWEST are chosen, then shown oldest-first so the preview reads
        // like the start of a conversation rather than the end of one.
        $this->assertSame(['second', 'third'], array_column($item['comments'], 'body'));
    }

    public function test_a_quarantined_comment_is_not_previewed(): void
    {
        $ids = $this->seed(1);
        DB::table('gates_comments')->insert([
            'target_type' => 'thread', 'target_id' => $ids[0], 'author_name' => 'Spammer',
            'body' => 'buy things', 'status' => 'quarantined', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertSame([], $this->feed->page(null, 5)['items'][0]['comments']);
    }

    public function test_new_since_counts_only_what_landed_after(): void
    {
        $ids = $this->seed(5);
        $this->assertSame(2, $this->feed->newSince($ids[2]));
        $this->assertSame(0, $this->feed->newSince(end($ids)));
        $this->assertSame(0, $this->feed->newSince(0), 'no reference point means nothing to report');
    }

    /** A caller asking for 10,000 posts gets a page, not the whole table. */
    public function test_the_page_size_is_clamped(): void
    {
        $this->seed(40);
        $this->assertLessThanOrEqual(30, count($this->feed->page(null, 10000)['items']));
        $this->assertCount(1, $this->feed->page(null, 0)['items']);
    }
}
