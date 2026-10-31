<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The search reaches the SITE, not only its activity.
 *
 * Before the `award` and `page` sources existed, the two most likely things a
 * visitor types found nothing: the name of a category ("choral"), and a question
 * about how the thing works ("how does voting work"). Both returned an empty
 * list, which reads as "we have nothing on that" rather than "this box only
 * covers recent events".
 */
final class SiteSearchTest extends TestCase
{
    private ActivityFeedService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ActivityFeedService();
        DB::table('gates_nominees')->delete();
        DB::table('gates_award_categories')->delete();
        DB::table('gates_award_cycles')->delete();
        DB::table('gates_award_programmes')->delete();
    }

    private function seedAwards(): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'gates-of-excellence', 'title' => 'GATES of Excellence',
            'subtitle' => 'The continental cultural recognition cycle',
            'is_active' => 1, 'sort_order' => 1,
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => 2026, 'status' => 'voting',
        ]);
        DB::table('gates_award_categories')->insert([
            'cycle_id' => $cid, 'slug' => 'choral-excellence', 'title' => 'Choral Excellence',
            'description' => 'Choirs and choral direction.', 'sort_order' => 1,
        ]);
    }

    /** @return list<array{string,string}> [label, url] */
    private function search(string $q, int $limit = 20): array
    {
        $r = $this->svc->search($q, $limit, interpret: false);
        return array_map(static fn ($i) => [$i['kind'], $i['url']], $r['items']);
    }

    public function test_a_category_name_finds_the_programme_page(): void
    {
        $this->seedAwards();
        $hits = $this->search('choral');

        $this->assertContains(['award', '/awards/gates-of-excellence'], $hits,
            'typing a category name must reach the page that hosts it');
    }

    public function test_an_inactive_programme_is_not_searchable(): void
    {
        $this->seedAwards();
        DB::table('gates_award_programmes')->update(['is_active' => 0]);

        $this->assertSame([], array_filter($this->search('choral'), static fn ($h) => $h[0] === 'award'));
    }

    /**
     * The question a first-time visitor actually types.
     *
     * Under the original all-words rule this matched nothing: "does" appears in no
     * page's vocabulary, so one stop word killed the whole query.
     */
    public function test_a_natural_language_question_reaches_a_page(): void
    {
        $this->assertContains(['page', '/integrity'], $this->search('how does voting work'));
        $this->assertContains(['page', '/nominate'], $this->search('where do I nominate someone'));
        $this->assertContains(['page', '/shop'],     $this->search('buy a t-shirt'));
    }

    /** Every content word must match, or "vote" alone would hit half the site. */
    public function test_all_content_words_must_match(): void
    {
        $pages = array_filter($this->search('vote quantumphysics'), static fn ($h) => $h[0] === 'page');
        $this->assertSame([], $pages, 'one unmatched content word must sink the result');
    }

    /** A query of nothing but stop words has no content, so it must match nothing. */
    public function test_a_query_of_only_stop_words_matches_no_page(): void
    {
        foreach (['what is it', 'how do you', 'the'] as $q) {
            $this->assertSame([], array_filter($this->search($q), static fn ($h) => $h[0] === 'page'), $q);
        }
    }

    /**
     * Every page the search offers must be somewhere that exists.
     *
     * This caught a real one: the list originally offered `/contact`, and this
     * application has no such route — contact lives on the parent site. A search
     * result that 404s is worse than no result, because the reader concludes the
     * page is broken rather than that they searched for the wrong thing.
     */
    public function test_every_page_result_points_somewhere_real(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        // Drive the source itself rather than a copy of the list, so a URL added
        // to pages() without a route fails here instead of in production.
        $urls = [];
        foreach (['vote', 'nominate', 'leaderboard', 'registry', 'integrity', 'pulse',
                  'community', 'events', 'blog', 'judges', 'awards', 'legacy', 'donate',
                  'shop', 'partner', 'opportunities', 'register', 'login', 'contact',
                  'privacy', 'terms'] as $word) {
            foreach ($this->svc->search($word, 20, interpret: false)['items'] as $i) {
                if ($i['kind'] === 'page') $urls[$i['url']] = true;
            }
        }
        $this->assertNotEmpty($urls, 'the page source must actually return something');

        foreach (array_keys($urls) as $url) {
            if (str_starts_with($url, 'http')) continue;   // an external destination
            $this->assertStringContainsString("'" . $url . "'", $routes,
                "the search offers {$url} — it must be a route that exists");
        }
    }

    /**
     * Signposts survive the recency cut.
     *
     * They carry no timestamp, so a plain recency sort puts them last and a busy
     * week pushes them past the limit — the reader asks "how does voting work",
     * gets twenty recent items, and never sees the page.
     */
    public function test_signposts_survive_a_crowded_result_set(): void
    {
        $this->seedAwards();
        for ($i = 0; $i < 40; $i++) {
            DB::table('gates_threads')->insert([
                'slug' => 'choral-noise-' . $i, 'title' => 'Choral chatter ' . $i,
                'body' => 'Talking about choral things', 'author_name' => 'Member ' . $i,
                'author_email_hash' => hash('sha256', "m{$i}@example.test"),
                'status' => 'approved', 'created_at' => date('Y-m-d H:i:s', time() - $i),
            ]);
        }

        $kinds = array_column($this->search('choral', 12), 0);
        $this->assertContains('award', $kinds,
            'forty fresher results must not bury the destination page');
        $this->assertLessThanOrEqual(12, count($kinds));
    }
}
