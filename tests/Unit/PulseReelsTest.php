<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PulseFeedService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reels — the Pulse feed restricted to video.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Reels was advertised in the main navigation ("Reels, posts and live moments"),
 * on the home page, and on the public status page — where it was listed as
 * **Operational**. No such surface existed anywhere on the platform. The one thing
 * worse than a missing feature is a status page that says it is working.
 *
 * ── IT IS ONE FEED, NOT TWO ──────────────────────────────────────────────────
 *
 * The mockup is explicit: "VERTICAL FEED (Feed + Reels share this)". So this is a
 * filter on the existing query, not a parallel one — a second query would mean
 * every future change to a card gets made twice, or silently applies to one tab.
 *
 * ── THE CASES THAT MATTER ────────────────────────────────────────────────────
 *
 * Filtering is easy; the two ways it goes quietly wrong are not:
 *
 *   PAGE TWO. If the cursor request drops the filter, photos appear halfway down
 *     Reels and the tab stops meaning anything mid-scroll. The service already
 *     carries a comment warning about this for channels.
 *   THE PILL. If "N new posts" counts unfiltered while the refetch filters, Reels
 *     offers three new posts — almost all photos — and delivers none. A control
 *     that promises something and does nothing reads as a broken page.
 */
final class PulseReelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_threads')->delete();
    }

    /** @return int the new thread's id */
    private function post(string $title, ?string $mediaType, int $programme = 0): int
    {
        $row = [
            'title' => $title, 'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)) . '-' . bin2hex(random_bytes(3)),
            'body' => 'body', 'author_name' => 'A Member', 'author_user_id' => null,
            // NOT NULL with no default on this table — the column that identifies a
            // poster when there is no account behind them.
            'author_email_hash' => hash('sha256', 'member@example.test'),
            'status' => 'approved', 'cheer_count' => 0, 'reply_count' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ];
        if ($programme > 0) $row['programme_id'] = $programme;
        if ($mediaType !== null) {
            $row['media_path'] = 'pulse/' . $mediaType . '.bin';
            $row['media_type'] = $mediaType;
        }
        return (int) DB::table('gates_threads')->insertGetId(
            \AfricaGates\Support\OptionalColumn::filter('gates_threads', $row,
                ['media_path', 'media_type', 'programme_id']));
    }

    /** @param array<int,array<string,mixed>> $items */
    private function titles(array $items): array
    {
        return array_map(static fn($i) => (string) $i['title'], $items);
    }

    // ══ the filter ═══════════════════════════════════════════════════════════

    public function test_reels_returns_only_video_posts(): void
    {
        $this->post('A photo',  'image');
        $this->post('A reel',   'video');
        $this->post('Just text', null);

        $page = (new PulseFeedService())->page(null, 20, null, null, 'video');

        $this->assertSame(['A reel'], $this->titles($page['items']));
    }

    /** The unfiltered feed is unchanged — Reels must not narrow the main tab. */
    public function test_the_ordinary_feed_still_shows_everything(): void
    {
        $this->post('A photo',  'image');
        $this->post('A reel',   'video');
        $this->post('Just text', null);

        $page = (new PulseFeedService())->page(null, 20, null, null, null);

        $this->assertCount(3, $page['items']);
    }

    /**
     * PAGE TWO KEEPS THE FILTER.
     *
     * The quiet failure: a cursor request that drops `media` returns photos into
     * Reels halfway down the scroll, so the tab silently stops meaning anything.
     */
    public function test_the_second_page_is_still_video_only(): void
    {
        // Interleaved, so an unfiltered page two would certainly contain photos.
        for ($i = 1; $i <= 6; $i++) {
            $this->post("photo {$i}", 'image');
            $this->post("reel {$i}",  'video');
        }

        $svc  = new PulseFeedService();
        $one  = $svc->page(null, 3, null, null, 'video');
        $this->assertCount(3, $one['items']);
        $this->assertNotNull($one['next_cursor']);

        $two = $svc->page((int) $one['next_cursor'], 3, null, null, 'video');

        foreach ($this->titles($two['items']) as $t) {
            $this->assertStringStartsWith('reel', $t,
                'A photo reached page two of Reels, so the filter was dropped with the cursor.');
        }
    }

    /** Channel and media compose — a channel chip inside Reels narrows both ways. */
    public function test_the_channel_and_the_media_filter_apply_together(): void
    {
        $this->post('reel in 1', 'video', 1);
        $this->post('reel in 2', 'video', 2);
        $this->post('photo in 1', 'image', 1);

        $page = (new PulseFeedService())->page(null, 20, null, 1, 'video');

        $this->assertSame(['reel in 1'], $this->titles($page['items']));
    }

    /** An unknown media value is refused by the controller, never passed through. */
    public function test_only_known_media_kinds_are_accepted(): void
    {
        $m = new \ReflectionMethod(\AfricaGates\Controllers\PulseController::class, 'mediaFilter');
        $m->setAccessible(true);

        $this->assertSame('video', $m->invoke(null, ['media' => 'video']));
        $this->assertSame('image', $m->invoke(null, ['media' => 'IMAGE']));
        $this->assertNull($m->invoke(null, ['media' => 'audio']));
        $this->assertNull($m->invoke(null, ['media' => '']));
        $this->assertNull($m->invoke(null, []),
            'Absent means "everything", which is what the ordinary feed is.');
    }

    // ══ the pill has to agree with the feed ═════════════════════════════════

    /**
     * "N new posts" counts what the tab will actually show.
     *
     * Counting unfiltered while fetching filtered is how Reels offers three new
     * posts and then delivers none.
     */
    public function test_the_new_post_count_respects_the_media_filter(): void
    {
        $first = $this->post('older reel', 'video');
        $this->post('a photo since',  'image');
        $this->post('a photo again',  'image');
        $this->post('a newer reel',   'video');

        $svc = new PulseFeedService();

        $this->assertSame(3, $svc->newSince($first),
            'Unfiltered, everything since counts.');
        $this->assertSame(1, $svc->newSince($first, null, 'video'),
            'On Reels only the newer reel counts — the two photos will never appear.');
    }

    public function test_the_new_post_count_respects_the_channel_too(): void
    {
        $first = $this->post('anchor', null, 1);
        $this->post('in channel 1', null, 1);
        $this->post('in channel 2', null, 2);

        $this->assertSame(1, (new PulseFeedService())->newSince($first, 1));
    }

    // ══ degrading honestly ══════════════════════════════════════════════════

    /**
     * With no media column there are no videos, so Reels is EMPTY — not "everything".
     *
     * The tempting shortcut is to ignore a filter the schema cannot serve. That
     * would serve the entire feed under a Reels heading on any database sitting
     * between a deploy and its migration, and nothing would look wrong. An empty
     * state is the honest answer and the visible one.
     */
    public function test_without_the_media_column_reels_is_empty_not_unfiltered(): void
    {
        $this->post('a post', null);

        $svc = new PulseFeedService();
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Services/PulseFeedService.php');

        $this->assertMatchesRegularExpression(
            "~!OptionalColumn::on\('gates_threads', 'media_type'\)\)\s*\{\s*\n\s*return \['items' => \[\], 'next_cursor' => null\];~",
            $src,
            'A video filter on a database with no media column must return nothing, '
            . 'not fall through and serve the whole feed under a Reels heading.');

        // And the same rule for the pill.
        $this->assertStringContainsString(
            "if (!OptionalColumn::on('gates_threads', 'media_type')) return 0;", $src);
    }
}
