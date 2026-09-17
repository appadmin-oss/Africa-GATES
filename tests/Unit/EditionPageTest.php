<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PublicResults;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * THE EDITION PAGE, AND THE SITEMAP THAT NEVER MENTIONED ANY OF THIS.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY AN EDITION IS A PAGE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * An award programme is known by its edition — "the 2026 Principal Awards" — and until
 * now that name pointed nowhere. Results lived in one flat list of categories with the
 * programme and year repeated on every row, so the one question a reader arrives with
 * (who won this year?) could only be answered by reading every row and sorting them in
 * their head. There was no URL to send anybody, which is what press and families want.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE SITEMAP TESTS MATTER MORE THAN THE PAGE ONES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Not one `/results` URL was in the sitemap — not the index, not an award, not an
 * edition. Two things have to hold about the section that fixes it, and both are the
 * kind that fail silently:
 *
 *   · EVERY PATH IT EMITS MUST RESOLVE. A 404 in a sitemap is a penalty, not a gap, and
 *     a slug minted by a copy of the real minter agrees with the route exactly until one
 *     of them changes. This asks the ROUTER, so a drift is caught rather than assumed
 *     away.
 *
 *   · IT MUST NOT LIST AN UNANNOUNCED AWARD. Submitting a judged-but-unreleased
 *     category's URL to a search engine is announcing it, by a route nobody would think
 *     to check.
 */
final class EditionPageTest extends TestCase
{
    private int $programmeId;
    private int $cycleId;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slug = 'ed-' . bin2hex(random_bytes(3));
        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $this->slug, 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2026, 'status' => 'results',
            'edition_label' => '2026 edition',
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
    }

    private function category(string $title, int $sort = 1): int
    {
        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'c-' . bin2hex(random_bytes(4)),
            'title' => $title, 'sort_order' => $sort,
        ]);
    }

    private function nominee(int $categoryId, string $name, int $organic, string $photo = ''): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $categoryId, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $organic, 'vote_count' => $organic,
            'photo_path' => $photo !== '' ? $photo : null,
        ]);
    }

    /**
     * A complete panel for one nominee.
     *
     * Per-CRITERION rows through the programme's real rubric, which is how this platform
     * actually stores a mark — there is no `gates_judge_scores` table, and a fixture that
     * invents one scores nobody. And WHOLE numbers only: `score` is a TINYINT, so 7.9 and
     * 8.0 are a tenth apart on SQLite and identical on the production database, which is
     * how a fixture comes to assert on data the platform cannot hold.
     */
    private function panel(int $categoryId, int $nominee, int $mark): void
    {
        static $n = 0;
        for ($k = 0; $k < 2; $k++) {
            $j = (int) DB::table('gates_judges')->insertGetId([
                'name' => 'Judge ' . (++$n), 'is_active' => 1,
                'email' => 'ed-j' . $n . '-' . bin2hex(random_bytes(3)) . '@example.test',
                'programme_ids' => json_encode([$this->programmeId]),
            ]);
            foreach (\AfricaGates\Services\JudgeRubric::effective($this->programmeId) as $c) {
                if ((int) $c->is_active !== 1) continue;
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee, 'category_id' => $categoryId,
                    'criterion_id' => (int) $c->id, 'score' => $mark,
                    'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
                ]);
            }
        }
    }

    /** A decided award in its own category. */
    private function award(string $title, string $winner, int $votes, int $sort, string $photo = ''): int
    {
        $c = $this->category($title, $sort);
        $a = $this->nominee($c, $winner, $votes, $photo);
        $b = $this->nominee($c, $winner . ' (runner-up)', (int) ($votes * 0.6));
        $this->panel($c, $a, 9);
        $this->panel($c, $b, 7);
        return $c;
    }

    // ───────────────────────────── the URL shape ──────────────────────────────

    public function test_the_url_a_page_links_and_the_one_the_route_serves_are_minted_once(): void
    {
        $url = PublicResults::editionUrl('my-programme', 2026);
        $this->assertSame('/results/my-programme-2026', $url);

        // And the parser is the inverse. A minter and a parser that drift produce a page
        // that links to itself and 404s, which is why they are written together.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);
        $e = PublicResults::edition(PublicResults::editionSlug($this->slug, 2026));
        $this->assertNotNull($e, 'the slug this platform mints does not resolve');
        $this->assertSame(PublicResults::editionUrl($this->slug, 2026), $e['url']);
    }

    public function test_a_slug_that_names_nothing_is_null(): void
    {
        foreach (['', 'nope', 'nope-2026', 'no-year-here', $this->slug . '-1999'] as $bad) {
            $this->assertNull(PublicResults::edition($bad), "'{$bad}' resolved to an edition");
        }
    }

    public function test_an_unannounced_edition_has_no_page(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);
        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update(['status' => 'judging']);

        // Serving it publicly IS announcing it — the rule this platform breaks hardest
        // when it breaks it.
        $this->assertNull(PublicResults::edition(PublicResults::editionSlug($this->slug, 2026)));
    }

    // ───────────────────────────── the edition ────────────────────────────────

    public function test_an_edition_arrives_whole_with_its_leader_named(): void
    {
        $this->award('Teachers’ Choice',        'Oluwagbemiga Dorcas',        1500, 1, 'nominees/d.jpg');
        $this->award('Community Service',       'Awe-Olola Champion Victoria', 900, 2);
        $this->award('Innovation in Teaching',  'Adeyemi Bolanle',             600, 3);

        $e = PublicResults::edition(PublicResults::editionSlug($this->slug, 2026));
        $this->assertNotNull($e);
        $this->assertCount(3, $e['awards'], 'the edition did not arrive whole');
        $this->assertSame('Incredible Principal Awards', $e['programme']);
        $this->assertSame('2026 edition', $e['edition']);

        // The leader is the highest index in the EDITION, not the first row.
        $this->assertNotNull($e['top']);
        $best = max(array_map(static fn ($a) => (int) $a['winner']['cpi'], $e['awards']));
        $this->assertSame($best, (int) $e['top']['winner']['cpi']);
    }

    public function test_the_face_reaches_the_page(): void
    {
        // `gates_nominees.photo_path` has existed all along and no result surface ever
        // selected it — an awards platform showing its winners as text.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1500, 1, 'nominees/dorcas.jpg');

        $e = PublicResults::edition(PublicResults::editionSlug($this->slug, 2026));
        $this->assertSame('nominees/dorcas.jpg', $e['top']['winner']['photo']);

        $html = $this->render($e);
        $this->assertStringContainsString('dorcas.jpg', $html, 'the portrait never reached the markup');

        // The name outranks the number: a person is the story, the index is the evidence
        // for it. Anchored on the index BLOCK rather than on the figure's own markup —
        // the first version of this looked for `630</b>`, so rewriting the band to put a
        // `/1000` inside that same <b> failed a test about reading order for a reason
        // that had nothing to do with reading order.
        $this->assertLessThan(
            strpos($html, 'class="ed-idx"'),
            strpos($html, 'Oluwagbemiga Dorcas'),
            'the index is printed before the name it describes');
    }

    public function test_a_winner_with_no_photograph_still_gets_a_card(): void
    {
        // Plenty of nominees never upload one, so this is a common case and not an error.
        // It is also the case a fixture with six photographs never produces: the card's
        // box is a <span>, and before `display:block` it collapsed to nothing and spilled
        // its initial over the card beside it.
        $this->award('Teachers’ Choice', 'Ngozi Okereke', 1500, 1);

        $e = PublicResults::edition(PublicResults::editionSlug($this->slug, 2026));
        $this->assertSame('', $e['top']['winner']['photo']);
        $this->assertStringContainsString('Ngozi Okereke', $this->render($e));

        // And the stand-in is WORDED. An initial alone is a decoration that reads as a
        // broken image; "No photograph" says which of the two it is, on a page whose whole
        // subject is a person.
        $this->assertStringContainsString('No photograph', $this->render($e));

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/results/edition.twig');
        $this->assertMatchesRegularExpression(
            '~\.ed-ph\{[^}]*display:(?:block|flex)~', $css,
            'the portrait box is inline again — a <span> with no display collapses to '
          . 'nothing and spills its initial over whatever sits beside it');
    }


    // ──────────────── what the edition page could not say before ────────────────

    /**
     * SECOND AND THIRD IN THE EDITION, WHICH EXIST NOWHERE ELSE ON THIS SITE.
     *
     * A category page can only ever say who won that category. "Who came second across the
     * whole 2026 edition" had no answer anywhere — and being narrowly beaten in a deep
     * field says more about a nominee than winning a field of one, which is exactly why
     * `ResultRelease::overall()` ranks every nominee in the cycle rather than the category
     * winners.
     */
    public function test_the_edition_names_who_came_second_and_third_in_it(): void
    {
        $this->award('Teachers’ Choice',       'Oluwagbemiga Dorcas',  1500, 1);
        $this->award('Community Service',      'Awe-Olola Victoria',    900, 2);
        $this->award('Innovation in Teaching', 'Adeyemi Bolanle',       600, 3);

        $e = PublicResults::edition(PublicResults::editionSlug($this->slug, 2026));
        $this->assertNotNull($e['overall'], 'the edition has no standing of its own');
        $this->assertGreaterThan(1, count($e['overall']['top4']),
            'the edition standing carries a winner and nobody behind them');

        $html = $this->render($e);
        $this->assertStringContainsString('2nd in the edition', $html);

        // The figures are the SEALED ones — `overall` is computed from the categories
        // already drawn through ReleasedStanding, never by handing a cycle id back to the
        // scorer, which would rank a released edition under today's arithmetic and could
        // name somebody the platform never announced.
        $this->assertSame($e['top']['winner']['nominee_id'],
                          $e['overall']['top4'][0]['nominee_id'],
            'the band and the standing under it disagree about who led the edition');
    }

    /**
     * A WITHHELD AWARD IS A ROW, NOT A NUMBER IN A SENTENCE.
     *
     * It used to be counted and dropped, so an award being verified appeared on this page
     * as "1 further award is decided and not yet published" and nowhere else. A count says
     * four awards are being checked; a row says WHICH — and which is what a nominee
     * waiting on one of them actually needs. This class's own rule is that silence is how
     * a withheld award becomes a rumour.
     */
    public function test_an_award_being_checked_is_named_on_the_page(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1500, 1);

        // A category with nominees and no panel at all: nobody meets the judging quorum,
        // so it is decided-but-unpublishable rather than absent.
        $dark = $this->category('Lifetime Contribution', 2);
        $this->nominee($dark, 'Somebody Waiting', 700);

        $e = PublicResults::edition(PublicResults::editionSlug($this->slug, 2026));
        $this->assertSame(1, $e['held'], 'the fixture no longer produces a withheld award');

        $titles = array_column($e['categories'], 'title');
        $this->assertContains('Lifetime Contribution', $titles,
            'the withheld award is missing from the page entirely');

        $row = null;
        foreach ($e['categories'] as $c) if ($c['title'] === 'Lifetime Contribution') $row = $c;
        $this->assertSame('withheld', $row['status']['key']);
        $this->assertFalse($row['status']['publishes_standing']);
        $this->assertNull($row['award'], 'a withheld award is carrying a drawn standing');

        $html = $this->render($e);
        $this->assertStringContainsString('Lifetime Contribution', $html);
        $this->assertStringNotContainsString('Somebody Waiting', $html,
            'a nominee from an unannounced award reached a public page');
    }

    /**
     * AND THE CAVEAT IS ABOUT OUR PROCESS, NEVER ABOUT THE PERSON UNDER IT.
     *
     * Naming somebody "highest in the edition" and then quietly replacing them when a
     * withheld award lands is worse than saying it is not settled — so the caveat is
     * stated. It is never a mark on their name or their photograph, which is the form
     * that reads as an accusation.
     */
    public function test_a_withheld_award_makes_the_edition_top_provisional_and_says_why(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1500, 1);
        $dark = $this->category('Lifetime Contribution', 2);
        $this->nominee($dark, 'Somebody Waiting', 700);

        $e = PublicResults::edition(PublicResults::editionSlug($this->slug, 2026));
        $this->assertTrue($e['overall']['provisional']);
        $this->assertSame(1, $e['overall']['held']);

        // Whitespace-normalised: the sentence wraps in the template, so asserting it
        // verbatim fails on the indentation rather than on the copy.
        $html = (string) preg_replace('~\s+~', ' ', $this->body($e));
        $this->assertStringContainsString('Provisional', $html);
        $this->assertStringContainsString('not about the people named here', $html,
            'the caveat does not say whose process it is about, which is how a caveat '
          . 'reads as an accusation');

        // Cover the caveat and the winner still reads as the winner: it sits BELOW the
        // band, never on their name.
        //
        // TWO WAYS THIS ASSERTION WAS VACUOUS, AND BOTH WERE FOUND BY BREAKING IT.
        //
        // It first compared the position of "Oluwagbemiga Dorcas" against the caveat's.
        // The winner's name is in the <title>, the meta description and the JSON-LD, so
        // its first occurrence is always in the head — moving the caveat to the very top
        // of the body passed.
        //
        // Anchoring on the class names instead was no better: `.ed-band` and `.ed-caveat`
        // are both DECLARED in the page's own <style> block, in that order, so the
        // comparison was measuring the order of two CSS rules. It reads on the body, with
        // the head and the styles stripped — see body().
        $this->assertLessThan(strpos($html, 'ed-caveat'),
                              strpos($html, 'ed-band'),
            'the caveat is printed above the band it is qualifying, which puts a warning '
          . 'over a person rather than under a result');
    }

    // ───────────────────────────── the sitemap ────────────────────────────────

    public function test_the_sitemap_lists_the_edition_and_every_award_in_it(): void
    {
        $c1 = $this->award('Teachers’ Choice',  'Oluwagbemiga Dorcas', 1500, 1);
        $c2 = $this->award('Community Service', 'Awe-Olola Victoria',   900, 2);

        $paths = array_column($this->sitemap(), 'path');

        $this->assertContains(PublicResults::editionUrl($this->slug, 2026), $paths,
            'the edition is not in the sitemap');
        foreach ([$c1 => 'Teachers’ Choice', $c2 => 'Community Service'] as $id => $title) {
            $this->assertContains('/results/' . PublicResults::slug($id, $title), $paths,
                "the award '{$title}' is not in the sitemap");
        }
    }

    public function test_every_results_url_in_the_sitemap_resolves_to_a_real_route(): void
    {
        // ASKS THE ROUTER. A 404 in a sitemap is a penalty rather than a gap, and a slug
        // built by a copy of the real minter agrees with the route right up until either
        // one changes.
        $this->award('Teachers’ Choice',  'Oluwagbemiga Dorcas', 1500, 1);
        $this->award('Community Service', 'Awe-Olola Victoria',   900, 2);

        $dead = [];
        foreach (array_column($this->sitemap(), 'path') as $path) {
            if (!self::serves($path)) $dead[] = $path;
        }
        $this->assertSame([], $dead,
            "the sitemap offers URLs nothing serves:\n  " . implode("\n  ", $dead));
    }

    public function test_an_unannounced_cycle_is_not_submitted_to_a_crawler(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1500, 1);
        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update(['status' => 'judging']);

        $paths = array_column($this->sitemap(), 'path');
        $this->assertNotContains(PublicResults::editionUrl($this->slug, 2026), $paths,
            'an unannounced edition was submitted to a search engine, which is announcing it');
        $this->assertSame([], array_values(array_filter($paths,
            static fn ($p) => str_starts_with($p, '/results/'))));
    }

    // ───────────────────────── the working, folded away ───────────────────────

    public function test_the_arithmetic_is_collapsed_and_not_cut(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/results/show.twig');

        // COLLAPSED. `<details>` and not a scripted accordion: it opens with a keyboard,
        // survives a blocked script, and the browser gives it the right role for free.
        $this->assertStringContainsString('<details class="rs-work__d">', $src);
        $this->assertStringContainsString('How the index was worked out', $src);

        // NOT CUT. This section is an integrity commitment — "these are the actual inputs
        // it ran on" — so every figure it promised is still in the DOM, still crawlable,
        // still findable with ctrl-F.
        foreach (['Weighting', 'Judging quorum', 'Votes cast', 'Community denominator'] as $input) {
            $this->assertStringContainsString($input, $src, "the working lost '{$input}'");
        }
        // And a printed result carries its own evidence.
        $this->assertMatchesRegularExpression(
            '~@media print\{ \.rs-work__d > \*:not\(summary\)\{ display:revert~', $src,
            'a printed result folds its working away');
    }

    // ───────────────────────────── helpers ────────────────────────────────────

    /** @return list<array<string,mixed>> the sitemap's `results` section */
    private function sitemap(): array
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $svc = $b->build()->get(\AfricaGates\Services\SitemapService::class);

        $m = new \ReflectionMethod($svc, 'build');
        $m->setAccessible(true);
        /** @var list<array<string,mixed>> $rows */
        $rows = $m->invoke($svc, 'results');
        return $rows;
    }

    private static function serves(string $path): bool
    {
        static $patterns = null;
        if ($patterns === null) {
            $b = new ContainerBuilder();
            $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
            AppFactory::setContainer($b->build());
            $app = AppFactory::create();
            (require dirname(__DIR__, 2) . '/src/routes.php')($app);
            $patterns = [];
            foreach ($app->getRouteCollector()->getRoutes() as $r) {
                if (in_array('GET', $r->getMethods(), true)) $patterns[] = $r->getPattern();
            }
        }
        foreach ($patterns as $p) {
            if ($p === $path) return true;
            if (!str_contains($p, '{')) continue;
            if (str_contains((string) preg_replace('~\{[^}]*\}~', '', $p), '[')) continue;
            $rx = '';
            foreach (preg_split('~(\{[^}]*\})~', $p, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $bit) {
                if (!str_starts_with($bit, '{')) { $rx .= preg_quote($bit, '~'); continue; }
                $body  = substr($bit, 1, -1);
                $colon = strpos($body, ':');
                $rx   .= $colon === false ? '[^/]+' : '(?:' . substr($body, $colon + 1) . ')';
            }
            if (preg_match('~^' . $rx . '$~', $path) === 1) return true;
        }
        return false;
    }

    private function render(array $e): string
    {
        $_SESSION = ['csrf_token' => 'tok'];
        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(Twig::class)->fetch('pages/results/edition.twig', [
            'e' => $e, 'gates_page' => 'results', 'has_hero' => false,
        ]);
    }

    /**
     * The rendered page with its head and its style block removed.
     *
     * Anything asserting about the ORDER of two things a reader sees has to read the body
     * alone. A winner's name is in the <title>, the meta description and the JSON-LD, and
     * every class name is declared in the page's own <style> block — so a `strpos`
     * comparison over the whole document measures the head, which is never what the
     * assertion is about and always passes.
     */
    private function body(array $e): string
    {
        $html = $this->render($e);
        $at   = strpos($html, '<body');

        return (string) preg_replace('~<style\b.*?</style>~s', '',
            $at === false ? $html : substr($html, $at));
    }
}
