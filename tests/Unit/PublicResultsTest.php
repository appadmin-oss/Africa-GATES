<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{DemoSeeder, JudgeRubric, PublicResults, ResultCard, ResultRelease, ResultThread};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * THE PUBLIC RECORD OF AN AWARD.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS MISSING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A cycle reached `results`, the materialiser promoted a winner, the announcer emailed
 * them a link to `/leaderboard` — and `/leaderboard` ranks registry PROFILES by a rolled-up
 * index. It names no category, no award and no winner. `/results` was a 301 to it.
 *
 * So the single most important thing this platform produces had no page, and the working
 * behind it lived on an admin screen: the only people who could check a result were the
 * people who published it. On a platform whose whole claim is that a ranking cannot be
 * bought, that is exactly the wrong way round.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE HOLDS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Three gates (released · not the sandbox · an index this platform can stand behind), one
 * arithmetic source, and the page actually rendering — under `strict_variables`, because
 * every way a Twig page dies is a missing key rather than a syntax error, and it dies on
 * results day.
 */
final class PublicResultsTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;
    private int $categoryId  = 0;

    /** The layout's blocks and nothing else — this is a test of the result page. */
    private const LAYOUT = <<<'TWIG'
        <!doctype html><title>{{ page_title }}</title>
        {% block head_styles %}{% endblock %}
        <main>{% block content %}{% endblock %}</main>
        {% block foot_scripts %}{% endblock %}
        TWIG;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'pub-' . bin2hex(random_bytes(3)),
            'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2026, 'status' => 'results',
            'edition_label' => '2026 edition',
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        $this->categoryId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'primary',
            'title' => 'Primary School Principal', 'sort_order' => 1,
        ]);
    }

    private function nominee(string $name, int $organic, int $paid = 0): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $organic, 'vote_count' => $organic + $paid,
        ]);
    }

    private function panel(int $nominee, float $mark): void
    {
        static $n = 0;
        for ($k = 0; $k < 2; $k++) {
            $j = (int) DB::table('gates_judges')->insertGetId([
                'name' => 'Judge ' . (++$n), 'is_active' => 1,
                'email' => 'j' . $n . '@example.test',
                'programme_ids' => json_encode([$this->programmeId]),
            ]);
            foreach (JudgeRubric::effective($this->programmeId) as $c) {
                if ((int) $c->is_active !== 1) continue;
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee, 'category_id' => $this->categoryId,
                    'criterion_id' => (int) $c->id, 'score' => $mark,
                    'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
                ]);
            }
        }
    }

    /** A decided, publishable award: two nominees, real community support, a real panel. */
    private function decided(): array
    {
        $a = $this->nominee('Dr. Adegboyega Aborode', 1536);
        $b = $this->nominee('Ajayi Temitope Oluwarotimi', 620);
        $this->panel($a, 9.0);
        $this->panel($b, 7.0);

        return [$a, $b];
    }

    // ══ gate one: the cycle has released ═════════════════════════════════════

    public function test_a_released_cycle_has_a_public_result(): void
    {
        $this->decided();

        $r = PublicResults::category($this->categoryId);

        $this->assertNotNull($r);
        $this->assertNull($r['held']);
        $this->assertSame('Dr. Adegboyega Aborode', $r['winner']['name']);
        $this->assertSame('2026 edition', $r['edition']);
    }

    /**
     * A JUDGED BUT UNRELEASED CATEGORY IS NOT PUBLIC.
     *
     * Its award is decided in the database the moment the last scorecard lands. Serving
     * that page is the announcement, made by whoever guessed the URL, before the people
     * running the cycle have said a word to the person who won.
     */
    public function test_a_cycle_that_has_not_released_has_no_public_result(): void
    {
        $this->decided();
        DB::table('gates_award_cycles')->where('id', $this->cycleId)
            ->update(['status' => 'judging', 'results_date' => null]);

        $this->assertNull(PublicResults::category($this->categoryId));
        $this->assertSame([], PublicResults::index()['items']);
    }

    /**
     * A results DATE that has passed releases it even where the status has not caught up.
     *
     * The status is moved by a cron on a host with no shell. If the cron is late — and it
     * has been dead for weeks on this platform before — the public page must follow the
     * date the cycle declared rather than the column the scheduler has not written yet.
     */
    public function test_a_passed_results_date_releases_it_even_before_the_status_moves(): void
    {
        $this->decided();
        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update(['status' => 'judging']);

        $this->assertNotNull(PublicResults::category($this->categoryId));
    }

    public function test_a_results_date_still_in_the_future_does_not(): void
    {
        $this->decided();
        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update([
            'status' => 'judging',
            'results_date' => Carbon::now()->addMonth()->toDateTimeString(),
        ]);

        $this->assertNull(PublicResults::category($this->categoryId));
    }

    // ══ gate two: the sandbox ════════════════════════════════════════════════

    /**
     * THE REHEARSAL CANNOT REACH THE PUBLIC RECORD.
     *
     * `DemoSeeder` builds real rows with real flags so the sandbox can be walked through
     * for real — which means two judges completing a practice scorecard on a practice
     * nominee meets the default quorum, and the practice cycle's own results date does the
     * rest with nobody deciding anything. Excluded through the programme, the way every
     * other public reader does it, rather than by a name prefix an operator can edit.
     */
    public function test_the_sandbox_has_no_public_result_page(): void
    {
        $demoP = DemoSeeder::programmeId();
        if ($demoP <= 0) {
            $demoP = (int) DB::table('gates_award_programmes')->insertGetId([
                'slug' => 'demo-sandbox', 'title' => 'DEMO — Sandbox', 'is_active' => 0,
            ]);
        }
        $cy = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $demoP, 'year' => 2026, 'status' => 'results',
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cy, 'slug' => 'demo-cat', 'title' => 'DEMO — Category', 'sort_order' => 1,
        ]);

        $this->assertNull(PublicResults::category($cat),
            'a rehearsal result was published as a real one');

        $urls = array_column(PublicResults::index()['items'], 'url');
        $this->assertNotContains('/results/' . $cat . '-demo-category', $urls);
    }

    // ══ gate three: an index this platform can stand behind ══════════════════

    /**
     * THE FAULT THIS WHOLE PAGE WAS BUILT AFTER.
     *
     * Four nominees on 1,536 · 1,955 · 126 · 398 votes, organic zero on every one of them,
     * and the panel deciding the award alone while the methodology promised 45% community.
     * The promotion refuses to crown one now; this refuses to PUBLISH one, so neither half
     * of the system is the only thing standing between a broken index and the public.
     *
     * And it names nobody while it is held — not the winner, not a ranking. Half a result
     * on a public page is the version people screenshot.
     */
    public function test_a_result_with_no_community_half_is_held_rather_than_published(): void
    {
        $a = $this->nominee('Dr. Adegboyega Aborode', 0);
        DB::table('gates_nominees')->where('id', $a)->update(['vote_count' => 1536]);
        $b = $this->nominee('Ajayi Temitope Oluwarotimi', 0);
        DB::table('gates_nominees')->where('id', $b)->update(['vote_count' => 1955]);
        $this->panel($a, 9.0);
        $this->panel($b, 7.0);

        $r = PublicResults::category($this->categoryId);

        $this->assertNotNull($r);
        $this->assertSame(PublicResults::HELD_DARK, $r['held']);

        $html = $this->renderShow($r);
        $this->assertStringNotContainsString('Dr. Adegboyega Aborode', $html,
            'a held result named its winner anyway');
        $this->assertStringContainsString('being verified', $html);
    }

    /** Nobody past the quorum is held too, and for a reason that reads differently. */
    public function test_a_category_that_crowns_nobody_is_held(): void
    {
        $this->nominee('Dr. Adegboyega Aborode', 1536);

        $r = PublicResults::category($this->categoryId);

        $this->assertSame(PublicResults::HELD_NOBODY, $r['held']);
    }

    /**
     * A HELD RESULT IS COUNTED, NOT ERASED.
     *
     * A category that simply vanishes from the list reads as one that never existed. One
     * that is counted as being verified is a promise somebody can hold this platform to,
     * and the difference is what stops a withheld award becoming a rumour.
     */
    public function test_held_results_are_counted_on_the_index(): void
    {
        $this->nominee('Dr. Adegboyega Aborode', 1536);   // below quorum → held

        $i = PublicResults::index();

        $this->assertSame([], $i['items']);
        $this->assertSame(1, $i['held']);
    }

    // ══ one arithmetic ═══════════════════════════════════════════════════════

    /**
     * THE PUBLIC PAGE DOES NOT WORK ANYTHING OUT.
     *
     * Every figure comes from {@see ResultRelease::category()} — the same call the admin
     * release screen audits, on the same scorer. A public page with its own arithmetic
     * could disagree with the screen an operator signed off, and the disagreement would
     * surface as a member arguing with an administrator about which Africa GATES page was
     * lying. Asserted as an IDENTITY rather than against expected numbers, so it survives
     * any later change to the index itself.
     */
    public function test_every_public_figure_is_the_release_screens_own(): void
    {
        $this->decided();

        $pub   = PublicResults::category($this->categoryId);
        $admin = ResultRelease::category($this->categoryId);

        foreach (['rows', 'winner', 'runner_up', 'margin', 'dead_heat', 'weights',
                  'quorum', 'cohort_max', 'scale_set_by', 'community_dark'] as $k) {
            $this->assertEquals($admin[$k], $pub[$k], "the public page computes `$k` its own way");
        }
    }

    /** The two halves add up to the index printed beside them, on every row. */
    public function test_the_split_shown_reconstructs_the_index(): void
    {
        $this->decided();

        foreach (PublicResults::category($this->categoryId)['rows'] as $row) {
            $this->assertSame($row['cpi'], $row['community_points'] + $row['judge_points'],
                $row['name'] . '’s halves do not add up to the index printed beside them');
        }
    }

    // ══ the address ══════════════════════════════════════════════════════════

    /**
     * The slug leads with the ID, not the category's own `slug` column.
     *
     * That column is unique per CYCLE, not globally: `/results/leadership` would mean a
     * different award every year, and every old share would silently start pointing at the
     * new one.
     */
    public function test_the_url_is_id_led_so_it_cannot_be_reused_by_next_years_award(): void
    {
        $this->decided();

        $r = PublicResults::category($this->categoryId);

        $this->assertSame($this->categoryId . '-primary-school-principal', $r['slug']);
        $this->assertSame($this->categoryId, PublicResults::idFrom($r['slug']));
        // And a stale name still resolves, which is what makes an old share safe.
        $this->assertSame($this->categoryId, PublicResults::idFrom($this->categoryId . '-anything'));
    }

    // ══ the Pulse post, and the replies that hang off it ═════════════════════

    /**
     * A RESULT POSTS TO THE PULSE, AND THAT POST IS WHERE THE REPLIES LIVE.
     *
     * The obvious design was a fifth `target_type` on `gates_comments`. On production that
     * column is `ENUM('profile','legacy','thread','nominee')` and MySQL answers a value
     * outside an ENUM with `Data truncated` — no error anybody sees. The reply box would
     * have worked in development and dropped every reply on the floor in production, on
     * the one page whose replies are the public record of an award.
     */
    public function test_a_published_result_posts_once_to_the_pulse(): void
    {
        $this->decided();

        $id = ResultThread::ensure($this->categoryId);
        $this->assertNotNull($id);

        // Idempotent: the announcer calls this once per nominee and again on every repair
        // of a stale backlog.
        $this->assertSame($id, ResultThread::ensure($this->categoryId));
        $this->assertSame($id, ResultThread::ensure($this->categoryId));
        $this->assertSame(1, DB::table('gates_threads')->count(),
            'the same award posted itself to the feed more than once');

        $t = DB::table('gates_threads')->where('id', $id)->first();
        $this->assertSame('approved', (string) $t->status);
        $this->assertStringContainsString('Dr. Adegboyega Aborode', (string) $t->body);
        // The SPLIT travels with the announcement. A feed card reading "won with 812" is a
        // number to take on trust; one that decomposes is a claim somebody can check.
        $this->assertStringContainsString('from the community', (string) $t->body);
        $this->assertStringContainsString('/results/' . $this->categoryId . '-', (string) $t->body);
    }

    /** A held result must not announce itself while its own page says it is unverified. */
    public function test_a_held_result_does_not_post_to_the_pulse(): void
    {
        $this->nominee('Dr. Adegboyega Aborode', 1536);   // below quorum → held

        $this->assertNull(ResultThread::ensure($this->categoryId));
        $this->assertSame(0, DB::table('gates_threads')->count());
    }

    /** And no operator address goes into a row the public feed reads, hashed or otherwise. */
    public function test_the_announcement_carries_no_persons_address(): void
    {
        $this->decided();
        ResultThread::ensure($this->categoryId);

        $t = DB::table('gates_threads')->first();

        $this->assertSame('Africa GATES', (string) $t->author_name);
        $this->assertSame(hash('sha256', 'africa-gates:results'), (string) $t->author_email_hash);
    }

    // ══ the card ═════════════════════════════════════════════════════════════

    /**
     * The share card rasterises, and REFUSES a held result.
     *
     * A card is the most durable thing this platform emits — it outlives the page in
     * caches, in threads, in screenshots. A withheld award leaking through the image while
     * its page says "still being verified" is the version of this bug that cannot be
     * recalled.
     */
    public function test_the_share_card_renders_and_refuses_a_held_result(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD unavailable');
        }
        $this->decided();

        $r   = PublicResults::category($this->categoryId);
        $png = (new ResultCard())->png($r);

        $this->assertNotNull($png);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr((string) $png, 0, 8));
        $size = getimagesizefromstring((string) $png);
        $this->assertSame([ResultCard::W, ResultCard::H], [$size[0], $size[1]]);

        $this->assertNull((new ResultCard())->png(['held' => PublicResults::HELD_DARK] + $r),
            'the share card drew a result the page will not show');
    }

    // ══ and the page draws ═══════════════════════════════════════════════════

    /**
     * RENDERED, UNDER strict_variables.
     *
     * Everything that takes a Twig page down is a missing key on a shape that moved, and
     * none of it shows in a syntax check. This page's bad morning is results day.
     */
    public function test_the_result_page_draws_the_whole_standing_and_the_working(): void
    {
        [$a, $b] = $this->decided();
        // Somebody the quorum leaves out, so the "out of the running" branch is exercised.
        $this->nominee('Never Judged', 90);

        $r    = PublicResults::category($this->categoryId);
        $html = $this->renderShow($r);

        $this->assertStringContainsString('Dr. Adegboyega Aborode', $html);
        $this->assertStringContainsString('Ajayi Temitope Oluwarotimi', $html);
        $this->assertStringContainsString('Never Judged', $html);
        $this->assertStringContainsString(ResultRelease::OUT_QUORUM, $html,
            'a nominee was left off the standing with no reason given');

        // The two halves, the weighting and the denominator — the working, not a summary.
        $this->assertStringContainsString((string) $r['winner']['community_points'], $html);
        $this->assertStringContainsString((string) $r['winner']['judge_points'], $html);
        $this->assertStringContainsString('45% community', $html);
        $this->assertStringContainsString((string) $r['cohort_max'], $html);
    }

    /** The index page draws, with and without anything on it. */
    public function test_the_index_page_draws_empty_and_full(): void
    {
        $empty = $this->renderIndex(PublicResults::index());
        $this->assertStringContainsString('No award has been decided yet', $empty);

        $this->decided();
        $full = $this->renderIndex(PublicResults::index());
        $this->assertStringContainsString('Dr. Adegboyega Aborode', $full);
        $this->assertStringContainsString('Primary School Principal', $full);
    }

    // ══ and something links to it ════════════════════════════════════════════

    /**
     * A PAGE WITH NO ROUTE IN IS THIS CODEBASE'S SECOND-MOST-EXPENSIVE BUG.
     *
     * `/results` used to 301 to `/leaderboard`; leaving that alias in place would have made
     * every one of the above pass while nobody could reach the page. And the congratulations
     * email — the single most important message this platform sends — pointed at the
     * leaderboard, which names neither the award nor the winner.
     */
    public function test_the_platform_actually_points_at_the_result_page(): void
    {
        $root  = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/src/routes.php');

        $this->assertStringContainsString("\$g->get('/results',", $routes,
            '/results is not a route');
        $this->assertStringNotContainsString("'/results'         => '/leaderboard'", $routes,
            '/results still redirects to the profile leaderboard, so the page is unreachable');

        // COMMENTS STRIPPED. The note explaining each of these fixes quotes the line it
        // replaced, so a scan that reads comments reports the fix as the fault — which has
        // now happened five times in this repository, in five different files.
        $announcer = self::code($root . '/src/Services/CycleAnnouncer.php');
        $this->assertStringContainsString('ResultThread::ensure', $announcer,
            'a result is promoted and emailed but never posted anywhere the public can see');

        $feed = self::code($root . '/src/Services/ActivityFeedService.php');
        $this->assertStringNotContainsString("url:    '/leaderboard'", $feed,
            'the searchable timeline still sends a result to the profile ranking');
    }

    /**
     * PHP source with its comments blanked. One helper, because remembering to strip at
     * each call site is the thing that failed.
     */
    private static function code(string $path): string
    {
        return (string) preg_replace(
            ['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
            (string) file_get_contents($path));
    }

    // ── rendering ───────────────────────────────────────────────────────────

    private function twig(): Environment
    {
        $t = new Environment(new ChainLoader([
            new ArrayLoader(['layout/gates.twig' => self::LAYOUT]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $t->addGlobal('csp_nonce', 'test-nonce');

        return $t;
    }

    private function renderShow(array $r): string
    {
        $thread = ResultThread::forCategory($this->categoryId);

        return $this->twig()->render('pages/results/show.twig', [
            'page_title' => 'Result', 'gates_page' => 'results',
            'r' => $r, 'thread' => $thread, 'replies' => [],
            'og_image' => '', 'og_image_w' => ResultCard::W, 'og_image_h' => ResultCard::H,
        ]);
    }

    private function renderIndex(array $i): string
    {
        return $this->twig()->render('pages/results/index.twig', [
            'page_title' => 'Results', 'gates_page' => 'results',
            'items' => $i['items'], 'held' => $i['held'],
        ]);
    }
}
