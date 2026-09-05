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
        // NOBODY VOTED AT ALL. This fixture used to hold real tallies with the organic
        // counter at zero — which was the dark case while the index read that column, and
        // is an ordinary decided award now that it reads the tally. What remains dark is
        // an empty ballot.
        $a = $this->nominee('Dr. Adegboyega Aborode', 0);
        $b = $this->nominee('Ajayi Temitope Oluwarotimi', 0);
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
        // The SPLIT travels with the announcement. A feed card reading "won with 849" is a
        // number to take on trust; one that decomposes is a claim somebody can check.
        $r = PublicResults::category($this->categoryId);
        $this->assertStringContainsString(
            (string) $r['winner']['community_points'] . ' community', (string) $t->body);
        $this->assertStringContainsString(
            (string) $r['winner']['judge_points'] . ' judges', (string) $t->body);
    }

    /**
     * THE ANNOUNCEMENT FITS THE CARD IT IS READ ON.
     *
     * `.pf__canvas p` clamps at SEVEN lines of a display face around 1.85rem in a column
     * near 415px. The first version of this post ran to four paragraphs and the feed cut it
     * mid-sentence at "Runner-up: Ajayi Temitope…", with the line carrying the address of
     * the page this post exists to reach clamped away entirely. Nothing errored — the post
     * simply stopped saying the thing it was written to say.
     *
     * Two blank lines cost two of the seven, which is how a body of 250 characters
     * overflowed. So: one paragraph, and a budget.
     */
    public function test_the_announcement_fits_inside_the_feed_cards_clamp(): void
    {
        $this->decided();
        ResultThread::ensure($this->categoryId);

        $body = (string) DB::table('gates_threads')->first()->body;

        $this->assertLessThanOrEqual(ResultThread::PULSE_CHARS, mb_strlen($body),
            'the announcement is longer than the feed card can show, so its last sentence '
            . 'is clamped away');
        $this->assertStringNotContainsString("\n", $body,
            'a blank line costs one of the seven the card has');
        // And it is not truncated to fit: a clause cut mid-word reads as a fault.
        $this->assertStringEndsWith('.', $body);
    }

    /**
     * AND THE BUDGET ACTUALLY BITES ON A LONG ONE.
     *
     * The test above passes on any short award whether the budget is enforced or not, so on
     * its own it pins nothing — mutation confirmed exactly that. This is the fixture the
     * limit exists for: two long Nigerian names and a full category title, which together
     * run past seven lines. The runner-up sentence is the one that has to go, because it is
     * last in priority and the page carries it either way.
     *
     * Dropped WHOLE, never truncated. A clause cut mid-word reads as a fault in the
     * platform rather than as an editorial choice, on the one post everybody sees.
     */
    public function test_a_long_award_drops_its_last_sentence_rather_than_overflowing(): void
    {
        DB::table('gates_award_categories')->where('id', $this->categoryId)
            ->update(['title' => 'Primary School Principal of the Year, Southwest Nigeria']);

        $a = $this->nominee('Dr. Oluwafunmilayo Adebanjo-Ogundipe', 1536);
        $b = $this->nominee('Ambassador Chukwuemeka Nnamdi Okonkwo-Eze', 620);
        $this->panel($a, 9.0);
        $this->panel($b, 7.0);

        ResultThread::ensure($this->categoryId);
        $body = (string) DB::table('gates_threads')->first()->body;

        $this->assertLessThanOrEqual(ResultThread::PULSE_CHARS, mb_strlen($body),
            'the announcement overflows the card the whole platform reads it on');
        $this->assertStringNotContainsString('Ambassador Chukwuemeka', $body,
            'the fixture no longer exceeds the budget, so this test pins nothing');
        // Whole sentences only: the winner and the split both survive intact.
        $this->assertStringContainsString('Dr. Oluwafunmilayo Adebanjo-Ogundipe takes', $body);
        $this->assertStringContainsString('judges.', $body);
        $this->assertStringEndsWith('.', $body);
    }

    /**
     * NO URL IN THE BODY — THE CARD CARRIES THE LINK.
     *
     * The address is ninety characters of display type: three of the seven lines, for a
     * link {@see \AfricaGates\Services\PulseFeedService} already puts on the card as its
     * own action. Printing it would push the split out of the card to save nothing.
     */
    public function test_the_feed_card_links_to_the_award_rather_than_to_its_own_thread(): void
    {
        $this->decided();
        ResultThread::ensure($this->categoryId);

        $page = (new \AfricaGates\Services\PulseFeedService())->page();
        $mine = null;
        foreach ($page['items'] as $i) {
            if ($i['slug'] === ResultThread::SLUG . $this->categoryId) { $mine = $i; break; }
        }

        $this->assertNotNull($mine, 'the result announcement is not in the Pulse feed');
        // The id alone, which resolves and 301s to the canonical address — one redirect on
        // click against one extra query per feed page forever.
        $this->assertSame('/results/' . $this->categoryId, $mine['link']);
        $this->assertStringNotContainsString('http', (string) $mine['body'],
            'the address is in the body as well, costing three of the card\'s seven lines');
    }

    /**
     * THE FEED IS TOLD WHAT IT IS LOOKING AT.
     *
     * A released result used to be a text post that happened to be written by "Africa
     * GATES", so the only way for the feed to know what it had was to read the prose. It
     * drew the most significant thing this platform does in the same face as somebody's
     * hello, with the index — the one number the whole platform exists to produce — as a
     * clause in a sentence.
     *
     * Typed instead: `kind` says what to draw, `result` carries the figures already
     * separated, and the card renders a structure rather than parsing one out of a
     * paragraph.
     */
    public function test_a_result_reaches_the_feed_as_a_typed_card_not_a_paragraph(): void
    {
        $this->decided();
        ResultThread::ensure($this->categoryId);

        $it = $this->feedItem(ResultThread::SLUG . $this->categoryId);

        $this->assertSame('result', $it['kind']);
        $r = PublicResults::category($this->categoryId);

        // Every figure the card draws, and each one identical to the award's own page. A
        // member screenshotting a feed card beside the standing and finding two different
        // indexes is the argument this platform cannot win, so the payload is asserted
        // against the page rather than against numbers typed here.
        $this->assertSame($r['winner']['name'], $it['result']['winner']);
        $this->assertSame($r['winner']['cpi'], $it['result']['cpi']);
        $this->assertSame($r['winner']['community_points'], $it['result']['community']);
        $this->assertSame($r['winner']['judge_points'], $it['result']['judges']);
        $this->assertSame($r['runner_up']['name'], $it['result']['runner_up']);
        $this->assertSame((string) $r['category']->title, $it['result']['award']);
        $this->assertSame('2026 edition', $it['result']['edition']);

        // And the two halves still add to the index printed beside them.
        $this->assertSame($it['result']['cpi'],
            $it['result']['community'] + $it['result']['judges']);
    }

    /**
     * A RESULT SINCE WITHHELD FALLS BACK TO AN ORDINARY POST.
     *
     * The announcement row stays in the feed — it was published, and deleting history is
     * not a correction — but a recount that empties a category's community half, or a
     * withdrawal, means the award's own page will no longer show a winner. Drawing that
     * winner's name on a card in front of that page is the worst available combination.
     */
    public function test_a_result_the_page_will_no_longer_show_stops_being_a_result_card(): void
    {
        $this->decided();
        ResultThread::ensure($this->categoryId);

        // Every vote is withdrawn, so the community half genuinely has nothing to read
        // and the award is held. (Zeroing only `organic_vote_count` would change nothing
        // now — the index counts the tally, which is the point of the change.)
        DB::table('gates_nominees')->where('category_id', $this->categoryId)
            ->update(['organic_vote_count' => 0, 'vote_count' => 0]);

        $it = $this->feedItem(ResultThread::SLUG . $this->categoryId);

        $this->assertSame('post', $it['kind'],
            'a withheld award was still drawn as a decided one in the feed');
        $this->assertNull($it['result']);
    }

    /** One item off the first page of the feed, by slug. */
    private function feedItem(string $slug): array
    {
        foreach ((new \AfricaGates\Services\PulseFeedService())->page()['items'] as $i) {
            if ($i['slug'] === $slug) return $i;
        }
        $this->fail('no feed item with slug ' . $slug);
    }

    /**
     * An ordinary post keeps its own thread page — the fallback must stay null.
     *
     * The slug is `my-top-5-picks` and it is chosen, not arbitrary. Strip seven characters
     * off the front of it — the length of the `result-` prefix — and what is left begins
     * `5`, so a link-out that skipped the prefix check and simply cast the remainder would
     * send this member's post to `/results/5`: somebody else's award, silently, from a
     * perfectly ordinary title. A fixture like `hello-there` casts to 0 and hides that
     * whole class of fault, which is exactly what the first version of this test did until
     * mutation caught it.
     */
    public function test_an_ordinary_post_carries_no_link_out(): void
    {
        DB::table('gates_threads')->insert([
            'slug' => 'my-top-5-picks', 'title' => 'My top 5 picks', 'body' => 'A member post.',
            'author_name' => 'A Member', 'author_email_hash' => str_repeat('a', 64),
            'status' => 'approved',
        ]);

        $page = (new \AfricaGates\Services\PulseFeedService())->page();

        $this->assertNull($page['items'][0]['link'],
            'a member post was sent to somebody else\'s award page');
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
        // Thousands-separated, which is why this asserts the formatted figure: a public
        // page that prints 1536 has technically disclosed a denominator and has not made
        // it readable, and every other number on the page is grouped.
        $this->assertStringContainsString((string) $r['winner']['community_points'], $html);
        $this->assertStringContainsString((string) $r['winner']['judge_points'], $html);
        $this->assertStringContainsString('45% community', $html);
        $this->assertStringContainsString(number_format($r['cohort_max']), $html);
    }

    /**
     * BOTH VOTE NUMBERS, AND THE DIFFERENCE BETWEEN THEM, SAID HERE.
     *
     * This page printed the organic count alone — under the label "community votes" —
     * while the same nominee's vote page prints `vote_count`, the full tally. One person,
     * two different vote figures, two pages of one platform, and no explanation on either.
     *
     * The distinction WAS documented: `/integrity` sets it out in full. Being written down
     * two clicks away is not the same as being stated where the numbers collide, and a
     * reader who spots the gap does not conclude there is a methodology. They conclude the
     * number was quietly revised down on the page where the award was decided.
     */
    public function test_the_page_states_both_vote_figures_and_names_the_difference(): void
    {
        $a = $this->nominee('Dr. Adegboyega Aborode', 1500, 300);   // 300 bought
        $b = $this->nominee('Ajayi Temitope Oluwarotimi', 620);
        $this->panel($a, 9.0);
        $this->panel($b, 7.0);

        $r    = PublicResults::category($this->categoryId);
        $html = self::flat($this->renderShow($r));

        // The tally is the sum of what the scorer already produced — not a second count.
        $this->assertSame(2420, $r['votes']['cast']);
        $this->assertSame(2120, $r['votes']['organic']);
        $this->assertSame(300,  $r['votes']['bought']);

        // Both figures on the row, and the second one only where it differs.
        $this->assertStringContainsString('1,800 votes cast, 1,500 of them organic', $html,
            'a nominee with purchased votes shows only one of their two vote figures');
        $this->assertStringContainsString('620 votes cast ·', $html,
            'a nominee with no purchased votes was given a redundant second figure');

        // And the difference is named in words, on this page, with the number in it.
        $this->assertStringContainsString('300', $html);
        $this->assertStringContainsString('bought in a pack or awarded as a bonus', $html,
            'the page shows two different vote numbers and does not say what separates them');
        $this->assertStringContainsString('/integrity', $html,
            'nothing links from the claim to where it is enforced and audited');
    }

    /**
     * AND IT DOES NOT INVENT A DIFFERENCE WHERE THERE IS NONE.
     *
     * A category nobody bought a vote in must say so rather than leaving a reader to
     * subtract two equal numbers and wonder what they missed.
     */
    public function test_a_category_with_no_purchased_votes_says_so(): void
    {
        $this->decided();

        $r    = PublicResults::category($this->categoryId);
        $html = self::flat($this->renderShow($r));

        $this->assertSame(0, $r['votes']['bought']);
        $this->assertStringContainsString('No vote in this category was bought or awarded',
            $html);
        $this->assertStringNotContainsString('bought in a pack or awarded as a bonus', $html);
    }

    /**
     * AND THE BALLOT PAGE SAYS IT TOO, WHERE THE LARGER NUMBER IS PRINTED.
     *
     * The disclosure is only complete if it is on BOTH pages that show a vote figure. The
     * result page prints the organic subset; `/vote/…` prints the full tally under the
     * single word "Votes". Fixing one and not the other leaves exactly the discrepancy
     * this is for — it just moves which page looks like it is hiding something.
     *
     * Asserted from source: that template needs Twig extensions the harness does not
     * register, so it cannot be rendered here. The markup and the guard are what matter,
     * and both are static properties of the file.
     */
    public function test_the_ballot_page_says_which_part_of_its_tally_counts(): void
    {
        $root = dirname(__DIR__, 2);
        $tpl  = (string) file_get_contents($root . '/templates/pages/vote-nominee.twig');

        $this->assertStringContainsString('n.organic_vote_count|number_format', $tpl,
            'the ballot page prints a tally and never says how much of it counts');
        $this->assertStringContainsString('Every one of them counts the same', $tpl,
            'the ballot page shows a split without saying that both parts count');

        // Only where the two differ. On a nominee nobody bought a vote for, a line saying
        // none were bought is noise on the page trying to get somebody to vote.
        $this->assertStringContainsString('n.vote_count > n.organic_vote_count', $tpl,
            'the caveat is drawn even where there is nothing to caveat');

        // And the column is selected optionally. This is THE BALLOT: a bare column name on
        // a deployment whose migrations have not run takes down the whole voting page
        // rather than one line of it — which is the trade the two columns beside it
        // already make.
        $ctl = self::code($root . '/src/Controllers/VoteController.php');
        $this->assertMatchesRegularExpression(
            "~OptionalColumn::on\('gates_nominees', 'organic_vote_count'\)~", $ctl,
            'the organic count is selected unguarded on the ballot page');
    }

    /**
     * A DRIFTED COUNTER CANNOT PRINT A NEGATIVE.
     *
     * `vote_count` and `organic_vote_count` are two denormalised counters maintained by
     * different paths, and a drifted pair can leave organic ABOVE the tally — which is the
     * whole reason {@see \AfricaGates\Services\VoteRecount} exists. "−40 votes bought"
     * on a public page is a worse answer than none.
     */
    public function test_a_drifted_counter_cannot_report_negative_purchased_votes(): void
    {
        $a = $this->nominee('Dr. Adegboyega Aborode', 1500);
        DB::table('gates_nominees')->where('id', $a)->update(['vote_count' => 900]);
        $this->panel($a, 9.0);
        $this->nominee('Ajayi Temitope Oluwarotimi', 620);

        $this->assertSame(0, PublicResults::category($this->categoryId)['votes']['bought']);
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

    // ══ the reply box ════════════════════════════════════════════════════════

    /**
     * THE COMPOSER RENDERS FOR A MEMBER, AND ITS SCRIPT PARSES.
     *
     * The guest branch is what the other render tests exercise, and it is the branch that
     * cannot break: it is a sentence and a link. The member branch is a textarea, a button
     * and eighty lines of JavaScript, and none of it is reached by a guest — so without
     * this the reply box could be missing, or its script a syntax error, and every test
     * here would still pass. A page whose whole second half only exists for signed-in
     * people has to be rendered as one.
     */
    public function test_a_signed_in_member_gets_a_working_reply_box(): void
    {
        $this->decided();
        // A decoy post FIRST, so the announcement's thread id and the category id are
        // different numbers. They are both small integers on a fresh database and both
        // land in the markup; with them equal, a template reading the wrong one renders
        // identically and the assertion below proves nothing. A reply posted to the wrong
        // small integer does not error — it lands on somebody else's post.
        DB::table('gates_threads')->insert([
            'slug' => 'decoy', 'title' => 'Decoy', 'body' => 'x',
            'author_name' => 'A Member', 'author_email_hash' => str_repeat('a', 64),
            'status' => 'approved',
        ]);
        ResultThread::ensure($this->categoryId);

        $r    = PublicResults::category($this->categoryId);
        $html = $this->renderShow($r, member: true);

        $this->assertStringContainsString('data-rs-body', $html, 'no reply box for a member');
        $this->assertStringContainsString('data-rs-send', $html);
        $this->assertStringNotContainsString('Sign in</a>', $html,
            'a signed-in member was told to sign in');

        // It posts through the endpoint that already exists — the member gate, the rate
        // limit, the spam verdict and the moderation queue come with it. A reply path of
        // its own would be a second one of each.
        $this->assertStringContainsString("targetType: 'thread'", $html);
        $this->assertStringContainsString('targetId: id', $html);

        // And the id it posts to is the announcement thread's, not the category's. They
        // are both small integers, so getting it wrong lands the reply on somebody else's
        // post rather than erroring.
        $t = ResultThread::forCategory($this->categoryId);
        $this->assertStringContainsString('data-thread="' . $t['id'] . '"', $html);
        $this->assertNotSame($t['id'], $this->categoryId,
            'the fixture cannot tell the two ids apart, so this assertion proves nothing');
    }

    /**
     * AND THE BUTTON IS BOUND TO SOMETHING.
     *
     * `ag-social.js` is loaded with `defer`, so it executes after the document is parsed —
     * which is after this page's own inline script runs. The first version read
     * `window.agSocial` at the top and returned early when it was undefined, so no listener
     * was ever attached: the box rendered, the textarea took text, the button was enabled
     * and styled, and pressing it did nothing. No error, no console line, no failed
     * request — there was no request. It was found by clicking it in a browser, and nothing
     * short of that would have found it.
     *
     * A deferred script runs BEFORE `DOMContentLoaded`, so that is the correct gate; and
     * the `readyState` branch matters because a bfcache restore can land here after the
     * event has already fired, where a listener for it would never run.
     */
    public function test_the_reply_script_waits_for_the_deferred_helper_it_needs(): void
    {
        $this->decided();
        ResultThread::ensure($this->categoryId);

        $html = $this->renderShow(PublicResults::category($this->categoryId), member: true);

        $this->assertStringContainsString("document.addEventListener('DOMContentLoaded'", $html,
            'the reply box binds its listener before ag-social.js has run, so the button '
            . 'does nothing at all');
        $this->assertStringContainsString("document.readyState === 'loading'", $html,
            'nothing runs the setup when the document is already parsed — a bfcache restore '
            . 'lands on a page whose button is inert');

        // And the layout still loads the helper this depends on. A page that waits for a
        // script nobody includes waits forever, which looks exactly like the bug above.
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout/gates.twig');
        $this->assertStringContainsString('js/ag-social.js', $layout);
    }

    /**
     * A QUARANTINED REPLY IS NOT DRAWN INTO THE THREAD.
     *
     * Rendering it as live tells its author their words are public before a moderator has
     * seen them. On a platform with children in the audience that is the one lie that
     * matters most — and the shared helper's own docblock says the caller MUST honour the
     * difference, which is precisely the kind of instruction a second implementation
     * forgets.
     */
    public function test_the_reply_box_honours_a_quarantine_verdict(): void
    {
        $this->decided();
        ResultThread::ensure($this->categoryId);

        $html = $this->renderShow(PublicResults::category($this->categoryId), member: true);

        $this->assertStringContainsString("r.status !== 'approved'", $html,
            'the reply box shows every reply as live, including quarantined ones');

        // And a reply that IS drawn goes in as TEXT, never as markup: this is somebody's
        // typed words returning to the page they were typed on. Asserted on the node the
        // body is written to rather than as a blanket ban on innerHTML — the flash line
        // beside it legitimately renders an anchor this file wrote, and a rule broad
        // enough to forbid that is a rule somebody deletes.
        $this->assertMatchesRegularExpression(
            '~p\.className = \'rs-c__b\';\s*(?://[^\n]*\n\s*)*p\.textContent = text;~', $html,
            'a reply is written into the thread as markup rather than as text');
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
        $this->assertStringContainsString("'/results'", $feed,
            'the site search cannot find the results page — its page list is hand-written '
            . 'and says so, and a page missing from it is a page nobody can search for');

        // ── AND A PERSON BROWSING CAN REACH IT ───────────────────────────────
        //
        // Everything above is a link somebody was SENT. A page reachable only from an
        // email, a feed card and a search result is one nobody finds on their own, which
        // is the same shape as this codebase's most expensive class of bug: a mechanism
        // complete and correct in every part except the route in.
        foreach (['templates/layout/nav.twig'    => 'the navigation',
                  'templates/layout/footer.twig' => 'the footer'] as $f => $what) {
            $this->assertStringContainsString('href="/results"',
                (string) file_get_contents($root . '/' . $f),
                $what . ' has no way into the results');
        }
    }

    /**
     * Rendered HTML with every run of whitespace collapsed to one space.
     *
     * A sentence in a template is wrapped for the file it lives in, so it reaches the
     * browser with newlines and indentation inside it. Asserting on the raw output would
     * pin where a line happens to break in the source, which is a property nobody wants
     * to preserve and which fails on the first reflow.
     */
    private static function flat(string $html): string
    {
        return (string) preg_replace('~\s+~u', ' ', $html);
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

    private function renderShow(array $r, bool $member = false): string
    {
        $thread = ResultThread::forCategory($this->categoryId);

        return $this->twig()->render('pages/results/show.twig', [
            'page_title' => 'Result', 'gates_page' => 'results',
            'r' => $r, 'thread' => $thread, 'replies' => [], 'is_member' => $member,
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
