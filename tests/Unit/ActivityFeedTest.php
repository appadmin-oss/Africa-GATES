<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The live activity search.
 *
 * Two properties carry the feature, and both are easy to claim and hard to keep:
 *
 *   LIVE — a search reads the tables on every request. The moment it is cached,
 *   "100% up to date" becomes "up to date within N seconds" and nothing in the code
 *   says so. The unfiltered feed IS cached, because it is byte-identical for every
 *   visitor; the tests below pin which is which so the two cannot quietly swap.
 *
 *   SAFE — the timeline aggregates seven sources, which makes it the one place where
 *   a row that was never meant to be public becomes easy to find. Individual votes
 *   are excluded deliberately: a public "someone voted for X at 14:03" trail is a
 *   de-anonymisation surface, and it is exactly what the integrity model depends on
 *   not publishing. Nothing pending, rejected, unpublished or merged away appears
 *   either, and each of those has its own test rather than one loose assertion.
 */
class ActivityFeedTest extends TestCase
{
    private ActivityFeedService $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new ActivityFeedService();
    }

    private function nominee(string $name, string $status = 'approved', array $extra = []): int
    {
        $id = (int) (DB::table('gates_nominees')->max('id') ?? 0) + 1;
        DB::table('gates_nominees')->insert($extra + [
            'id' => $id, 'category_id' => 1, 'name' => $name, 'status' => $status,
            'vote_count' => 0, 'nominated_at' => Carbon::now()->subMinutes(5)->toDateTimeString(),
        ]);
        return $id;
    }

    private function post(string $title, string $status = 'published'): void
    {
        DB::table('gates_posts')->insert([
            'slug' => 'p-' . bin2hex(random_bytes(4)), 'title' => $title, 'status' => $status,
            'published_at' => Carbon::now()->subMinutes(10)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @return list<string> titles in the returned order */
    private function titles(array $result): array
    {
        return array_map(static fn (array $i): string => $i['title'], $result['items']);
    }

    // ── Live vs cached ───────────────────────────────────────────────────────

    public function test_a_search_reflects_a_row_written_a_moment_ago(): void
    {
        // The promise. If a search were cached this would pass only after the TTL,
        // and the failure would look like "search is a bit slow to update" rather
        // than like a broken guarantee.
        $this->assertSame([], $this->titles($this->feed->search('Ifeoma Nwosu')));

        $this->nominee('Ifeoma Nwosu');

        $this->assertSame(['Ifeoma Nwosu'], $this->titles($this->feed->search('Ifeoma Nwosu')));
    }

    public function test_a_search_is_marked_live_and_the_bare_feed_is_not(): void
    {
        // Reported rather than implied, so the page can tell a visitor which one they
        // are looking at instead of the code knowing and nobody else.
        $this->assertTrue($this->feed->search('Nwosu')['live']);
        $this->assertFalse($this->feed->search('')['live']);
        $this->assertFalse($this->feed->search(null)['live']);
    }

    public function test_a_one_character_query_is_treated_as_no_query(): void
    {
        // A single letter matches most of the register, so running it live is an
        // expensive way to get the latest feed. Treated as empty, and the returned
        // `query` says so rather than echoing the discarded input.
        $this->nominee('Ada Obi');
        $r = $this->feed->search('A');

        $this->assertFalse($r['live']);
        $this->assertSame('', $r['query']);
        $this->assertSame(2, ActivityFeedService::MIN_QUERY);
    }

    public function test_the_unfiltered_feed_is_cached_briefly(): void
    {
        // The other half: identical for every visitor, so at continental traffic an
        // uncached version is the same seven queries per arrival for the same answer.
        $this->feed->search('');
        $row = DB::table('gates_cache')->where('cache_key', 'like', 'activity:latest:%')->first();

        $this->assertNotNull($row, 'the bare feed must be cached');
        $this->assertSame(15, ActivityFeedService::LATEST_TTL);
    }

    public function test_a_search_never_writes_a_cache_entry(): void
    {
        // A per-query cache would grow without bound AND break the freshness promise.
        DB::table('gates_cache')->delete();
        $this->feed->search('Nwosu');

        $this->assertSame(0, DB::table('gates_cache')->count(),
            'a live search must not be cached under any key');
    }

    // ── What must never appear ───────────────────────────────────────────────

    public function test_individual_votes_are_not_in_the_timeline(): void
    {
        // A public "someone voted for X at 14:03" trail, cross-referenced with a share
        // link or a social post, identifies who voted for whom. The leaderboard's
        // aggregate is the only vote information this platform publishes.
        $id = $this->nominee('Ada Obi');
        DB::table('gates_votes')->insert([
            'nominee_id' => $id, 'category_id' => 1, 'voter_email_hash' => hash('sha256', 'v@example.com'),
            'voted_at' => Carbon::now()->toDateTimeString(),
        ]);

        foreach ($this->feed->search('')['items'] as $item) {
            $this->assertNotSame('vote', $item['kind']);
        }
        // And the service must not reference the votes table at all.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/ActivityFeedService.php');
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $src);
        $this->assertStringNotContainsString('gates_votes', $code);
    }

    public function test_a_pending_nominee_is_not_published_by_the_timeline(): void
    {
        $this->nominee('Not Yet Reviewed', 'pending');
        $this->assertSame([], $this->titles($this->feed->search('Not Yet Reviewed')));
    }

    public function test_an_unpublished_post_is_not_leaked(): void
    {
        $this->post('Draft Only', 'draft');
        $this->assertSame([], $this->titles($this->feed->search('Draft Only')));
    }

    public function test_a_merged_away_nominee_is_not_shown(): void
    {
        // A tombstone is a row the public pages no longer display; a timeline that
        // surfaced it would resurrect a duplicate an admin deliberately folded away.
        $keep = $this->nominee('Ada Obi');
        $this->nominee('Ada Obi Duplicate', 'approved', ['merged_into' => $keep]);

        $this->assertSame(['Ada Obi'], $this->titles($this->feed->search('Ada Obi')));
    }

    public function test_no_contact_detail_can_reach_the_timeline(): void
    {
        // The aggregation is what makes this worth asserting: each source is fine
        // alone, and putting seven of them behind one search box is where an email
        // column gets pulled in by accident.
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/ActivityFeedService.php');
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $src);

        foreach (["'email'", "'phone'", 'author_email_hash', 'voter_email_hash', "'ip'", 'REMOTE_ADDR'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "the activity feed must never select {$forbidden}");
        }
    }

    // ── Shape and ordering ───────────────────────────────────────────────────

    public function test_newest_first_across_every_source(): void
    {
        // Sources are read separately and merged, so a bug here shows up as one
        // source's items always sorting above another's regardless of time.
        $this->post('Older Story');
        DB::table('gates_nominees')->insert([
            'id' => 9001, 'category_id' => 1, 'name' => 'Newer Nominee', 'status' => 'approved',
            'vote_count' => 0, 'nominated_at' => Carbon::now()->toDateTimeString(),
        ]);

        $titles = $this->titles($this->feed->search(''));
        $this->assertSame('Newer Nominee', $titles[0]);
        $this->assertContains('Older Story', $titles);
    }

    public function test_every_item_carries_what_a_reader_and_a_machine_each_need(): void
    {
        $this->nominee('Ada Obi');
        $item = $this->feed->search('')['items'][0];

        foreach (['kind', 'label', 'title', 'detail', 'url', 'at', 'at_label'] as $k) {
            $this->assertArrayHasKey($k, $item);
        }
        // `at` stays machine-readable for <time datetime>, `at_label` is the prose.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $item['at']);
        $this->assertNotSame($item['at'], $item['at_label']);
        $this->assertStringStartsWith('/', $item['url'], 'links must be internal paths');
    }

    public function test_the_label_is_language_a_visitor_recognises(): void
    {
        // Phase changes are the activity nothing else on the site surfaces, and
        // "Has voting opened?" is the most asked question about a cycle. Showing the
        // internal status token would answer it in the wrong vocabulary.
        DB::table('gates_cycle_transitions')->insert([
            'cycle_id' => 1, 'from_status' => 'nominations', 'to_status' => 'voting',
            'observed_at' => Carbon::now()->toDateTimeString(),
            'boundary_at' => Carbon::now()->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $labels = array_column($this->feed->search('')['items'], 'label');
        $this->assertContains('Voting opened', $labels);
        $this->assertNotContains('voting', $labels, 'the raw status token must not be shown');
    }

    public function test_a_limit_is_enforced_however_large_the_request(): void
    {
        // The endpoint takes `limit` from the query string, so this is the guard that
        // stops `?limit=100000` from being a way to dump the register.
        for ($i = 0; $i < 8; $i++) $this->nominee('Nominee ' . $i);

        $this->assertLessThanOrEqual(ActivityFeedService::MAX_LIMIT,
            count($this->feed->search('', 100000)['items']));
        $this->assertCount(3, $this->feed->search('', 3)['items']);
        $this->assertGreaterThanOrEqual(1, count($this->feed->search('', 0)['items']),
            'a zero limit must not mean an empty page');
    }

    public function test_a_wildcard_in_the_query_is_matched_literally(): void
    {
        // `%` and `_` are LIKE metacharacters. Unescaped, a query of "%" matches every
        // row in seven tables — a one-character way past the minimum-length guard to
        // dump the timeline.
        $this->nominee('Ada Obi');

        $this->assertSame([], $this->titles($this->feed->search('%%')),
            'a percent sign must be a literal, not a wildcard');
        $this->assertSame([], $this->titles($this->feed->search('A_a')),
            'an underscore must be a literal, not a single-character wildcard');
    }

    public function test_a_metacharacter_in_a_real_name_is_still_findable(): void
    {
        // The half of the guard that a backslash escape got wrong, and the reason it
        // needed an explicit ESCAPE clause. MySQL treats `\` as the default escape
        // character and SQLite does not, so escaping with a backslash produced:
        //
        //     query "100%"   MySQL 1 row (correct)   SQLite 0 rows
        //     query "A_B"    MySQL 1 row (correct)   SQLite 0 rows
        //
        // i.e. on SQLite any name containing a percent sign or an underscore became
        // unsearchable, and the wildcard was neutralised by accident rather than
        // escaped. Blocking a dump is only half the requirement.
        $this->nominee('100% Cotton Collective');
        $this->nominee('Afro_Beats Trust');

        $this->assertSame(['100% Cotton Collective'], $this->titles($this->feed->search('100%')));
        $this->assertSame(['Afro_Beats Trust'], $this->titles($this->feed->search('Afro_Beats')));
    }

    public function test_the_escape_character_itself_is_escaped(): void
    {
        // `!` is the escape character. Unescaped, a query containing it would consume
        // the character after it — so "Wow!" would search for "Wow" and a name that
        // really contains "!%" would become a wildcard search.
        $this->nominee('Wow! Studios');

        $this->assertSame(['Wow! Studios'], $this->titles($this->feed->search('Wow!')));
        $this->assertSame([], $this->titles($this->feed->search('!%')),
            'an escaped escape followed by a percent must stay literal');
    }

    public function test_the_query_is_reported_back_for_the_page_to_echo(): void
    {
        // The page renders "N results for “…”", and it must be the query that RAN.
        $r = $this->feed->search('  Ada Obi  ');
        $this->assertSame('Ada Obi', $r['query'], 'trimmed, so the echo matches what was searched');
    }

    public function test_how_many_sources_answered_is_reported(): void
    {
        // "No activity" and "eight of nine sources are unavailable on this install"
        // must be distinguishable — the page shows a warning for the second.
        //
        // Nine since the search became site-wide: the seven activity sources plus
        // `award` (programmes and categories) and `page` (the site's own
        // destinations). If this number falls, a source stopped answering.
        $this->assertSame(9, $this->feed->search('')['sources'],
            'every source must be readable on a complete schema');
    }

    public function test_a_search_across_two_sources_returns_both(): void
    {
        // A merged timeline that silently only ever returns one source's rows would
        // pass most of the tests above.
        $this->nominee('Sahara Collective');
        $this->post('Sahara Collective wins the prize');

        $kinds = array_column($this->feed->search('Sahara')['items'], 'kind');
        $this->assertContains('nominee', $kinds);
        $this->assertContains('post', $kinds);
    }

    public function test_relative_times_read_naturally(): void
    {
        $this->nominee('Just Now Person', 'approved', ['nominated_at' => Carbon::now()->toDateTimeString()]);
        $labels = array_column($this->feed->search('Just Now Person')['items'], 'at_label');

        $this->assertNotSame([], $labels);
        $this->assertMatchesRegularExpression('/(just now|minute)/', $labels[0]);
    }

    public function test_a_singular_count_is_not_pluralised(): void
    {
        // Small, but "1 minutes ago" on every recent item is the kind of thing that
        // makes a whole page read as unfinished.
        $this->nominee('One Minute', 'approved', ['nominated_at' => Carbon::now()->subMinutes(1)->toDateTimeString()]);
        $this->nominee('Two Minutes', 'approved', ['nominated_at' => Carbon::now()->subMinutes(2)->toDateTimeString()]);

        $byTitle = [];
        foreach ($this->feed->search('Minute')['items'] as $i) $byTitle[$i['title']] = $i['at_label'];

        $this->assertSame('1 minute ago', $byTitle['One Minute'] ?? null);
        $this->assertSame('2 minutes ago', $byTitle['Two Minutes'] ?? null);
    }
}
