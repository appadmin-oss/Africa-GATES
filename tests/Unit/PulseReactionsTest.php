<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{CommunityService, PulseFeedService, SpamService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Four reactions, and reposts with a line of your own.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE INVARIANT: A REACTION IS SINGULAR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * One person holds at most one reaction on a post. Tapping a different one moves
 * it; tapping the held one clears it. That rule is not a UI preference — it is
 * what makes a count mean something. If the kind ever migrates INTO the unique
 * key on gates_cheers, one person can hold all four at once, every total starts
 * over-counting people, and nothing on screen looks wrong. So the arithmetic is
 * pinned here rather than trusted to the schema comment.
 *
 * The second thing worth pinning is that the counter column and the rows never
 * disagree. `gates_threads.cheer_count` is what the card renders; the rows are
 * the truth. A drift between them shows up as a card whose number does not
 * change when you react to it, which reads as a broken button.
 */
final class PulseReactionsTest extends TestCase
{
    private CommunityService $community;
    private PulseFeedService $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->community = new CommunityService(new SpamService());
        $this->feed      = new PulseFeedService();
        DB::table('gates_threads')->delete();
        DB::table('gates_cheers')->delete();
        DB::table('gates_reposts')->delete();
    }

    private function thread(string $slug = 'a-post'): int
    {
        return (int) DB::table('gates_threads')->insertGetId([
            'slug' => $slug, 'title' => 'A post', 'body' => 'Body',
            'author_name' => 'Member', 'author_email_hash' => hash('sha256', 'm@example.test'),
            'status' => 'approved', 'cheer_count' => 0, 'reply_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function rowsFor(int $id): int
    {
        return (int) DB::table('gates_cheers')
            ->where('target_type', 'thread')->where('target_id', $id)->count();
    }

    private function storedCount(int $id): int
    {
        return (int) DB::table('gates_threads')->where('id', $id)->value('cheer_count');
    }

    // ── one per person, changeable ───────────────────────────────────────────

    public function test_reacting_records_the_kind(): void
    {
        $id = $this->thread();

        $r = $this->community->react('thread', $id, 'u:5', 'insight');

        $this->assertTrue($r['cheered']);
        $this->assertSame('insight', $r['kind']);
        $this->assertSame(1, $r['count']);
        $this->assertSame(['insight' => 1], $r['breakdown']);
    }

    public function test_a_second_reaction_from_the_same_person_moves_it_rather_than_adding_one(): void
    {
        $id = $this->thread();

        $this->community->react('thread', $id, 'u:5', 'cheer');
        $r = $this->community->react('thread', $id, 'u:5', 'respect');

        $this->assertSame('respect', $r['kind']);
        $this->assertSame(1, $r['count'], 'changing your mind is not a second reaction');
        $this->assertSame(1, $this->rowsFor($id), 'and it is not a second row either');
        $this->assertSame(['respect' => 1], $r['breakdown'],
            'the reaction moved — the old kind must not be left behind in the breakdown');
    }

    public function test_tapping_the_reaction_you_hold_clears_it(): void
    {
        $id = $this->thread();

        $this->community->react('thread', $id, 'u:5', 'support');
        $r = $this->community->react('thread', $id, 'u:5', 'support');

        $this->assertFalse($r['cheered']);
        $this->assertNull($r['kind']);
        $this->assertSame(0, $r['count']);
        $this->assertSame(0, $this->rowsFor($id));
        $this->assertSame([], $r['breakdown']);
    }

    public function test_changing_a_reaction_keeps_when_you_first_reacted(): void
    {
        $id = $this->thread();
        $this->community->react('thread', $id, 'u:5', 'cheer');

        $first = (string) DB::table('gates_cheers')
            ->where('target_id', $id)->where('fp', 'u:5')->value('created_at');
        DB::table('gates_cheers')->where('target_id', $id)->where('fp', 'u:5')
            ->update(['created_at' => '2020-01-01 00:00:00']);

        $this->community->react('thread', $id, 'u:5', 'insight');

        $after = (string) DB::table('gates_cheers')
            ->where('target_id', $id)->where('fp', 'u:5')->value('created_at');

        $this->assertSame('2020-01-01 00:00:00', $after,
            'when somebody first reacted is more interesting than when they last '
            . 'changed their mind about how — an update, not a delete and re-insert');
        $this->assertNotSame('', $first);
    }

    public function test_different_people_reacting_differently_all_count(): void
    {
        $id = $this->thread();

        $this->community->react('thread', $id, 'u:1', 'cheer');
        $this->community->react('thread', $id, 'u:2', 'cheer');
        $this->community->react('thread', $id, 'u:3', 'insight');
        $r = $this->community->react('thread', $id, 'u:4', 'respect');

        $this->assertSame(4, $r['count']);
        $this->assertSame(['cheer' => 2, 'insight' => 1, 'respect' => 1], $r['breakdown']);
        $this->assertSame(4, array_sum($r['breakdown']),
            'the breakdown must sum to the total, or the rail and the number disagree');
    }

    public function test_the_stored_counter_tracks_the_rows(): void
    {
        $id = $this->thread();

        $this->community->react('thread', $id, 'u:1', 'cheer');
        $this->community->react('thread', $id, 'u:2', 'insight');
        $this->assertSame(2, $this->storedCount($id));

        $this->community->react('thread', $id, 'u:2', 'insight');   // clears
        $this->assertSame(1, $this->storedCount($id),
            'the card renders cheer_count — drift shows up as a button that does nothing');
    }

    public function test_an_unknown_kind_changes_nothing(): void
    {
        $id = $this->thread();
        $this->community->react('thread', $id, 'u:1', 'cheer');

        $r = $this->community->react('thread', $id, 'u:1', 'applause');

        $this->assertSame(1, $r['count'], 'a kind we do not know must not clear the one held');
        $this->assertSame(1, $this->rowsFor($id));
    }

    public function test_the_plain_cheer_door_still_works(): void
    {
        // Every caller that predates reactions — the profile page, the nominee
        // card, the comment row — goes through toggleCheer and must not have to
        // learn about kinds.
        $id = $this->thread();

        $on  = $this->community->toggleCheer('thread', $id, 'u:9');
        $off = $this->community->toggleCheer('thread', $id, 'u:9');

        $this->assertTrue($on['cheered']);
        $this->assertSame(1, $on['count']);
        $this->assertFalse($off['cheered']);
        $this->assertSame(0, $off['count']);
    }

    // ── what the feed hands the action rail ──────────────────────────────────

    public function test_the_feed_reports_which_reaction_this_viewer_holds(): void
    {
        $id = $this->thread();
        $this->community->react('thread', $id, 'u:7', 'insight');
        $this->community->react('thread', $id, 'u:8', 'cheer');

        $mine = $this->feed->page(null, 5, 7)['items'][0];

        $this->assertSame('insight', $mine['my_reaction']);
        $this->assertTrue($mine['cheered'], 'holding any reaction still reads as cheered');
        $this->assertSame(['cheer' => 1, 'insight' => 1], $mine['reactions']);
    }

    public function test_one_viewers_reaction_never_shows_as_anothers(): void
    {
        $id = $this->thread();
        $this->community->react('thread', $id, 'u:7', 'respect');

        $theirs = $this->feed->page(null, 5, 8)['items'][0];
        $guest  = $this->feed->page(null, 5, null)['items'][0];

        $this->assertNull($theirs['my_reaction']);
        $this->assertFalse($theirs['cheered']);
        $this->assertNull($guest['my_reaction']);
        // The public breakdown is public — it is the per-viewer state that is not.
        $this->assertSame(['respect' => 1], $guest['reactions']);
    }

    // ── reposts ──────────────────────────────────────────────────────────────

    public function test_a_repost_carries_a_line_of_your_own(): void
    {
        $id = $this->thread();

        $r = $this->community->toggleRepost(4, $id, '  This is the part people miss.  ');

        $this->assertTrue($r['reposted']);
        $this->assertSame(1, $r['count']);
        $this->assertSame('This is the part people miss.',
            DB::table('gates_reposts')->where('user_id', 4)->where('thread_id', $id)->value('comment'),
            'trimmed, and stored — a bare repost is a bookmark with extra steps');
    }

    public function test_a_repost_without_a_comment_stores_null_not_an_empty_string(): void
    {
        $id = $this->thread();
        $this->community->toggleRepost(4, $id);

        $this->assertNull(
            DB::table('gates_reposts')->where('user_id', 4)->where('thread_id', $id)->value('comment'),
            'sometimes passing something along IS the whole remark');
    }

    public function test_a_comment_longer_than_the_column_is_cut_not_rejected(): void
    {
        $id = $this->thread();
        $this->community->toggleRepost(4, $id, str_repeat('a', 900));

        $stored = (string) DB::table('gates_reposts')
            ->where('user_id', 4)->where('thread_id', $id)->value('comment');

        $this->assertSame(500, mb_strlen($stored),
            'VARCHAR(500) in strict-mode MySQL rejects the row outright — the '
            . 'service has to do the cutting before the insert');
    }

    public function test_reposting_twice_takes_it_back_down(): void
    {
        $id = $this->thread();

        $this->community->toggleRepost(4, $id, 'Worth reading.');
        $off = $this->community->toggleRepost(4, $id);

        $this->assertFalse($off['reposted']);
        $this->assertSame(0, $off['count']);
        $this->assertSame(0, (int) DB::table('gates_reposts')->where('thread_id', $id)->count());
    }

    public function test_the_repost_counter_on_the_thread_tracks_the_rows(): void
    {
        $id = $this->thread();

        $this->community->toggleRepost(4, $id);
        $this->community->toggleRepost(5, $id);

        $this->assertSame(2, (int) DB::table('gates_threads')->where('id', $id)->value('repost_count'));
        $this->assertSame(2, $this->feed->page(null, 5, 4)['items'][0]['repost_count']);
    }

    public function test_the_feed_says_whether_THIS_member_reposted(): void
    {
        $id = $this->thread();
        $this->community->toggleRepost(4, $id);

        // gates_reposts is keyed on the account id, not on the `u:<id>`
        // fingerprint the cheers table uses — it predates that convention. A feed
        // that looked it up the cheers way would say nobody had reposted anything.
        $this->assertTrue($this->feed->page(null, 5, 4)['items'][0]['reposted']);
        $this->assertFalse($this->feed->page(null, 5, 5)['items'][0]['reposted']);
        $this->assertFalse($this->feed->page(null, 5, null)['items'][0]['reposted'],
            'a guest holds nothing');
    }

    public function test_a_repost_needs_a_member_and_a_thread(): void
    {
        $this->assertFalse($this->community->toggleRepost(0, 1)['ok']);
        $this->assertFalse($this->community->toggleRepost(4, 0)['ok']);
    }

    // ── channels ─────────────────────────────────────────────────────────────

    /** @return array{int,int} two programme ids */
    private function programmes(): array
    {
        // Allocated, never hard-coded: gates_award_programmes.id is TINYINT
        // UNSIGNED, and a literal id like 880 is silently dropped by MySQL AND
        // drags AUTO_INCREMENT to 255, so the next insert dies out of range.
        $a = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'education-t', 'title' => 'Education', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $b = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'choral-t', 'title' => 'Choral', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return [$a, $b];
    }

    private function threadIn(?int $programmeId, string $slug): int
    {
        $id = $this->thread($slug);
        DB::table('gates_threads')->where('id', $id)->update(['programme_id' => $programmeId]);
        return $id;
    }

    public function test_a_post_carries_its_channel(): void
    {
        [$edu] = $this->programmes();
        $this->threadIn($edu, 'in-education');

        $item = $this->feed->page()['items'][0];

        $this->assertSame($edu, $item['programme_id']);
        $this->assertSame('Education', $item['channel']);
    }

    public function test_a_post_in_no_channel_says_so_rather_than_guessing(): void
    {
        $this->threadIn(null, 'no-channel');

        $item = $this->feed->page()['items'][0];

        $this->assertSame(0, $item['programme_id']);
        $this->assertSame('', $item['channel'], 'the chip is hidden on an empty string, not on a placeholder');
    }

    public function test_filtering_by_channel_happens_in_the_query(): void
    {
        [$edu, $choral] = $this->programmes();
        $this->threadIn($edu, 'e1');
        $this->threadIn($edu, 'e2');
        $this->threadIn($choral, 'c1');
        $this->threadIn(null, 'x1');

        $all = $this->feed->page(null, 10);
        $one = $this->feed->page(null, 10, null, $edu);

        $this->assertCount(4, $all['items']);
        $this->assertCount(2, $one['items'],
            'filtering the loaded page instead would show whatever happened to be on it');
        foreach ($one['items'] as $i) $this->assertSame($edu, $i['programme_id']);
    }

    public function test_the_chips_only_offer_channels_with_something_behind_them(): void
    {
        [$edu, $choral] = $this->programmes();
        $this->threadIn($edu, 'e1');
        $this->threadIn($edu, 'e2');
        $this->threadIn($choral, 'c1');

        $chips = $this->feed->channels();

        $this->assertSame(['Education', 'Choral'], array_column($chips, 'name'),
            'busiest first — and a programme nobody has posted in is not a chip, '
            . 'because pressing it lands on an empty page that reads as a broken filter');
        $this->assertSame([2, 1], array_column($chips, 'n'));
    }

    public function test_a_post_pointing_at_a_deleted_programme_is_not_a_blank_chip(): void
    {
        [$edu] = $this->programmes();
        $this->threadIn($edu, 'e1');
        DB::table('gates_award_programmes')->where('id', $edu)->delete();

        $this->assertSame([], (new PulseFeedService())->channels());
    }
}
