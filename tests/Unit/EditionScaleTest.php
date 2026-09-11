<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{NomineeScoringService, PublicResults, ResultRelease, VoteService};
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE COMMUNITY HALF IS A SHARE OF THE EDITION, NOT OF THE CATEGORY.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Both community terms are shares of a maximum, and the maximum used to be the biggest
 * number in the nominee's OWN category. Every category was therefore normalised to its own
 * leader, and every category's leader collected the whole community half:
 *
 *     Leader of a 1,955-vote category   community 450
 *     Leader of an 89-vote category     community 450
 *
 * Inside their own categories neither figure is wrong. {@see ResultRelease::overall()} then
 * puts them in one column — an overall standing is the whole cycle ranked — and the second
 * one is being paid for a field rather than for support. The operator's word for it was
 * "cheating", which is the right word for a number that does not move when the thing it
 * measures changes by a factor of twenty-two.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE TWO THINGS THE WIDER SCALE BREAKS IF NOBODY IS WATCHING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · THE SCALE-SETTER LEAVES THE PAGE. Every screen that explained a community half found
 *     the denominator by scanning its own rows for a nominee holding it. Edition-wide they
 *     are usually in another category, so the scan finds nobody and the screen reports the
 *     scale as unset — beside percentages that plainly came from somewhere.
 *
 * 2 · A CATEGORY WITH NO VOTE ROWS IS SILENTLY CRUSHED. Seventy per cent of the half counts
 *     PEOPLE, from `gates_votes`. Per category, a category with no rows had a maximum of
 *     zero and {@see \AfricaGates\Services\CpiService::reachPart()} fell back to the tally
 *     for the whole field at once. Per edition, one category with rows keeps the maximum
 *     above zero and every rowless category scores zero people — 315 of 450 points, gone,
 *     for a data-migration reason, with every other figure on the line looking normal.
 *
 * Both are asserted here rather than trusted, because neither produces an error, a log line
 * or a screen that looks wrong.
 */
final class EditionScaleTest extends TestCase
{
    private const CYCLE = 90;
    private const DEEP  = 900;          // a category with real support
    private const THIN  = 901;          // a category with very little

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => self::CYCLE, 'programme_id' => 0, 'year' => 2026, 'status' => 'judging',
        ]);
        foreach ([self::DEEP => 'Deep', self::THIN => 'Thin'] as $id => $title) {
            DB::table('gates_award_categories')->insertOrIgnore([
                'id' => $id, 'cycle_id' => self::CYCLE,
                'slug' => strtolower($title), 'title' => $title . ' category',
            ]);
        }
    }

    private function nominee(int $id, int $cat, string $name, int $votes): void
    {
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => $cat, 'name' => $name, 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => $votes, 'organic_vote_count' => $votes,
        ]);
    }

    /** Two judges, so a complete card at quorum is possible. */
    private function rubric(): void
    {
        foreach ([1, 2] as $j) {
            DB::table('gates_judges')->insertOrIgnore([
                'id' => $j, 'name' => 'Judge ' . $j, 'email' => 'j' . $j . '@x.test',
                'is_active' => 1,
            ]);
        }
    }

    /** A COMPLETE scorecard from each named judge — every active criterion, or it counts for nothing. */
    private function marks(int $nominee, int $cat, array $judges, int $score = 8): void
    {
        $crit = array_map('intval',
            DB::table('gates_judge_criteria')->where('is_active', 1)->pluck('id')->all());
        foreach ($judges as $j) {
            foreach ($crit as $cid) {
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee, 'category_id' => $cat,
                    'criterion_id' => $cid, 'score' => $score,
                ]);
            }
        }
    }

    /** $n real, distinct, verified voters — vote ROWS, which is what reach is counted from. */
    private function backers(int $nominee, int $cat, int $n, string $tag): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => $nominee, 'category_id' => $cat, 'vote_type' => 'standard',
                'weight' => 1, 'voter_email_hash' => VoteService::voterHash($tag . $i . '@x.test'),
            ]);
        }
    }

    // ══ the tally term ═══════════════════════════════════════════════════════

    /**
     * ONE DENOMINATOR, AND IT IS THE EDITION'S.
     *
     * The thin category's leader has 40 votes and is scored against 4,000 — not against
     * their own 40. That single line is the whole change.
     */
    public function test_the_tally_denominator_is_the_largest_in_the_edition(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 4000);
        $this->nominee(9002, self::THIN, 'Thin leader',   40);

        $scoring = new NomineeScoringService();

        $this->assertSame(4000, $scoring->scoreCategory(self::DEEP)[9001]['cohort_max']);
        $this->assertSame(4000, $scoring->scoreCategory(self::THIN)[9002]['cohort_max'],
            'the thin category is being normalised to its own leader again');
    }

    /**
     * AND THE REACH DENOMINATOR TOO — the 70% that decides most of the half.
     *
     * Asserted separately from the tally because they are two different maxima drawn from
     * two different sources: one from a counter on the nominee row, one counted from vote
     * rows. A change that widened one and left the other per-category would be invisible in
     * every figure except the score itself.
     */
    public function test_the_reach_denominator_is_the_largest_in_the_edition(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 10);
        $this->nominee(9002, self::THIN, 'Thin leader',  2);
        $this->backers(9001, self::DEEP, 10, 'deep');
        $this->backers(9002, self::THIN,  2, 'thin');

        $scoring = new NomineeScoringService();
        $thin    = $scoring->scoreCategory(self::THIN)[9002];

        $this->assertSame(2,  $thin['unique_voters']);
        $this->assertSame(10, $thin['cohort_max_unique'],
            'the thin category is counting people against its own best, so two supporters '
            . 'are being paid as though two were the most anybody in the cycle had');

        // 315 x 2/10 + 135 x 2/10 = 90. The whole community half, worked by hand, so a
        // change to either term has to be argued rather than absorbed.
        $this->assertSame(90, $thin['community_points']);
    }

    /**
     * THE SCALE-SETTER IS NAMED, AND SO IS THEIR CATEGORY.
     *
     * They are in a different category from the one being drawn, which is the normal case
     * now and the case every screen used to get wrong.
     */
    public function test_the_scale_setter_is_named_across_categories(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 4000);
        $this->nominee(9002, self::THIN, 'Thin leader',   40);

        $drawn = ResultRelease::category(self::THIN);

        $this->assertSame(4000, $drawn['cohort_max']);
        $this->assertSame('Deep leader', $drawn['scale_set_by'],
            'the release screen cannot say whose votes the denominator is');
        $this->assertSame('Deep category', $drawn['scale_category']);
        $this->assertFalse($drawn['scale_in_category'],
            'the screen thinks the scale-setter is one of the rows it is about to draw');

        $here = ResultRelease::category(self::DEEP);
        $this->assertTrue($here['scale_in_category']);
        $this->assertSame(9001, $here['scale_set_by_id']);
    }

    // ══ the hazard the wider scale opens ═════════════════════════════════════

    /**
     * A TALLY WITH NO VOTE ROWS IS FLAGGED, NOT SILENTLY SCORED AT ZERO PEOPLE.
     *
     * The deep category has real rows; the thin one has a tally and none. Per category the
     * thin one's maximum would be zero and reachPart() would fall back to the tally for the
     * whole field. Per edition the maximum is the deep category's, the fallback does not
     * fire, and the thin nominee loses 70% of the community half to a missing import.
     *
     * The score is still the strict one — understate rather than overstate, the same choice
     * the judge half makes for a panel that has not finished — and this flag is what stops
     * the understatement being mistaken for a measurement.
     */
    public function test_a_tally_with_no_vote_rows_is_flagged(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 10);
        $this->backers(9001, self::DEEP, 10, 'deep');
        $this->nominee(9002, self::THIN, 'Imported tally', 8);   // no rows at all

        $row = (new NomineeScoringService())->scoreCategory(self::THIN)[9002];

        $this->assertSame(0, $row['unique_voters']);
        $this->assertTrue($row['reach_unmeasured'],
            'a nominee whose 8 votes have no ballot rows behind them is being scored at '
            . 'zero reach with nothing anywhere to say the rows are missing');

        $drawn = ResultRelease::category(self::THIN);
        $this->assertSame(1, $drawn['reach_unmeasured']);
        $this->assertTrue($drawn['rows'][0]['reach_unmeasured']);
    }

    /**
     * AND THE THREE THINGS THAT ARE NOT THAT.
     *
     * The flag has to be narrow or it is noise on every release screen, and noise is how an
     * operator learns to scroll past the box that matters.
     */
    public function test_the_flag_does_not_fire_for_a_nominee_who_simply_has_no_support(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 10);
        $this->backers(9001, self::DEEP, 10, 'deep');
        $this->nominee(9002, self::THIN, 'Nobody voted for them', 0);

        $row = (new NomineeScoringService())->scoreCategory(self::THIN)[9002];

        $this->assertFalse($row['reach_unmeasured'],
            'no votes and no rows is a measurement — they have no support — and flagging '
            . 'it puts a data warning on every unbacked nominee on the platform');
    }

    /** Rows that all belong to nobody is the intended answer, not a gap. */
    public function test_the_flag_does_not_fire_for_a_tally_made_entirely_of_grants(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 10);
        $this->backers(9001, self::DEEP, 10, 'deep');
        $this->nominee(9002, self::THIN, 'All granted', 5);
        DB::table('gates_votes')->insert([
            'nominee_id' => 9002, 'category_id' => self::THIN, 'vote_type' => 'bonus',
            'weight' => 5, 'voter_email_hash' => 'bonus:1:' . bin2hex(random_bytes(4)),
        ]);

        $row = (new NomineeScoringService())->scoreCategory(self::THIN)[9002];

        $this->assertSame(0, $row['unique_voters'], 'a grant is not a supporter');
        $this->assertFalse($row['reach_unmeasured'],
            'the rows are there and they belong to nobody, which is a verdict rather than '
            . 'a missing import');
    }

    /**
     * AND NOT FOR A NOMINEE WHOSE ROWS WERE ALL FLAGGED AS FRAUD.
     *
     * The rows are there and the platform looked at them and refused them. Zero reach is
     * the verdict, not the absence of one — and flagging it would put "the ballot rows are
     * missing" on the one nominee whose rows are the reason anybody is reading the screen.
     */
    public function test_the_flag_does_not_fire_for_a_tally_whose_rows_were_all_flagged(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 10);
        $this->backers(9001, self::DEEP, 10, 'deep');
        $this->nominee(9002, self::THIN, 'All flagged', 3);
        for ($i = 0; $i < 3; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => 9002, 'category_id' => self::THIN, 'vote_type' => 'standard',
                'weight' => 1, 'fraud_flag' => 1,
                'voter_email_hash' => VoteService::voterHash('ring' . $i . '@x.test'),
            ]);
        }

        $row = (new NomineeScoringService())->scoreCategory(self::THIN)[9002];

        $this->assertSame(0, $row['unique_voters'], 'a flagged vote is not a supporter');
        $this->assertFalse($row['reach_unmeasured'],
            'the rows exist and were refused, which is a measurement — saying they are '
            . 'missing points an operator at an import that never happened');
    }

    /**
     * AND NOT WHEN NOBODY IN THE EDITION HAS ROWS.
     *
     * `reach_unmeasured` marks the nominee who loses 315 points while the REST of the
     * edition has rows — a per-nominee data fault, on a line where every other figure
     * looks normal. Where NOBODY was measured there is no such asymmetry: it is a fact
     * about the edition, stated once per cycle on both surfaces, and repeating it against
     * every name would read as a finding about each of those people.
     *
     * The half itself is capped at the tally term now. This test used to assert 450 for
     * the leader, from the all-or-nothing fallback that paid the whole community half on a
     * tally when nothing had counted supporters at all — see `CpiService::idealPart()` for
     * why that is gone.
     */
    public function test_an_edition_with_no_vote_rows_at_all_pays_the_tally_term_only(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 4000);
        $this->nominee(9002, self::THIN, 'Thin leader',   40);

        $scoring = new NomineeScoringService();
        $deep    = $scoring->scoreCategory(self::DEEP)[9001];
        $thin    = $scoring->scoreCategory(self::THIN)[9002];

        $this->assertSame(0, $deep['cohort_max_unique']);
        $this->assertFalse($deep['reach_unmeasured']);
        $this->assertFalse($thin['reach_unmeasured'],
            'the whole edition has no rows, so nothing has been lost and there is nothing '
            . 'to warn about, and the cap is stated once for the whole cycle');

        // 135 x 4000/4000 and 135 x 40/4000 — the 315 is not paid, because nothing
        // measured it.
        $this->assertSame(135, $deep['community_points']);
        $this->assertSame(1,   $thin['community_points']);
    }

    /**
     * THE "DENOMINATOR CAN STILL MOVE" WARNING FOLLOWS THE SETTER ACROSS CATEGORIES.
     *
     * A nominee below the judge quorum still sets the scale — below quorum is pending, not
     * out. The release screen names that, because when their panel finishes the denominator
     * moves and every community half moves with it.
     *
     * That check used to scan the drawn category's OWN rows for the setter, which was right
     * while the denominator was the category's and became a warning covering a strictly
     * smaller set of cases than the risk it describes the moment the scale went
     * edition-wide: the setter is normally in another category, so the scan found nobody
     * and the box stayed quiet while every figure in the cycle was provisional. The
     * existing test for it kept passing because its fixture has one category — which is
     * exactly how this kind of narrowing survives a green suite.
     */
    public function test_the_scale_warning_fires_when_the_setter_is_in_another_category(): void
    {
        $this->rubric();
        $this->nominee(9001, self::DEEP, 'Unfinished leader', 4000);
        $this->nominee(9002, self::THIN, 'Thin leader',         40);
        $this->marks(9001, self::DEEP, [1]);            // ONE judge — below quorum
        $this->marks(9002, self::THIN, [1, 2]);         // at quorum, so there is a ranking

        $thin = ResultRelease::category(self::THIN);

        $this->assertSame('Unfinished leader', $thin['scale_set_by']);
        $this->assertFalse($thin['scale_in_category']);
        $this->assertTrue($thin['scale_is_out'],
            'the whole cycle is being measured against somebody the panel has not '
            . 'finished, and the category being released says nothing about it');
    }

    /** And it stays silent for a setter the panel HAS finished with. */
    public function test_the_scale_warning_is_silent_once_that_panel_finishes(): void
    {
        $this->rubric();
        $this->nominee(9001, self::DEEP, 'Finished leader', 4000);
        $this->nominee(9002, self::THIN, 'Thin leader',       40);
        $this->marks(9001, self::DEEP, [1, 2]);
        $this->marks(9002, self::THIN, [1, 2]);

        $this->assertFalse(ResultRelease::category(self::THIN)['scale_is_out'],
            'the warning fires for a denominator that cannot move, which teaches an '
            . 'operator to skip the box on the pages where it means something');
    }

    /**
     * AND "NOBODY ASKED" IS NOT "THE PANEL HAS NOT FINISHED".
     *
     * The scale-setter's standing is only resolved when there is a quorum to resolve it
     * against, so it comes back NULL where there is none — and a programme may legitimately
     * run without one ({@see \AfricaGates\Services\RuleEngine} `min_judges_per_nominee`),
     * in which case every nominee is winner-eligible the moment they are scored.
     *
     * Treating that null as `false` would put "the panel has not finished, every community
     * half in this cycle can still move" on every category of such a programme, permanently
     * and about nobody. A red box that is always there is a red box nobody reads, which
     * costs more than the warning is worth on the pages where it means something.
     */
    public function test_no_quorum_is_not_reported_as_an_unfinished_panel(): void
    {
        (new \AfricaGates\Services\RuleEngine())->set('global', null,
            ['min_judges_per_nominee' => 0]);

        $this->rubric();
        $this->nominee(9001, self::DEEP, 'Never judged', 4000);
        $this->nominee(9002, self::THIN, 'Thin leader',    40);
        $this->marks(9002, self::THIN, [1]);

        $scale = (new NomineeScoringService())->editionScale(self::CYCLE);
        $this->assertNull($scale['votes_by']['eligible'],
            'a standing was asserted for a nominee nobody was asked to check');

        $this->assertFalse(ResultRelease::category(self::THIN)['scale_is_out'],
            'a programme with no judge quorum is being told on every category that its '
            . 'panels have not finished');
    }

    /**
     * AND A CATEGORY WHOSE ROWS ARE MISSING REACHES THE ROW AN OPERATOR READS FIRST.
     *
     * `reach_unmeasured` is the one finding on the release screen where nothing else looks
     * wrong — every other figure on the line is ordinary — so a caveat further down the
     * page is not enough. It has to be in the count of categories that need a person.
     */
    public function test_a_category_with_missing_rows_is_counted_as_needing_a_person(): void
    {
        $this->rubric();
        $this->nominee(9001, self::DEEP, 'Deep leader', 10);
        $this->backers(9001, self::DEEP, 10, 'deep');
        $this->nominee(9002, self::THIN, 'Imported tally', 8);
        $this->marks(9001, self::DEEP, [1, 2]);
        $this->marks(9002, self::THIN, [1, 2]);

        $n = ResultRelease::attention(ResultRelease::forCycle(self::CYCLE));

        $this->assertSame(1, $n['reach_unmeasured']);
        $this->assertGreaterThanOrEqual(1, $n['needs_person'],
            'a category holding a tally with no ballot rows behind it is not counted '
            . 'among the categories that need somebody to look at them');
    }

    // ══ what the wider scale costs to compute ════════════════════════════════    // ══ what the wider scale costs to compute ════════════════════════════════

    /**
     * THE EDITION IS READ ONCE PER CYCLE, NOT ONCE PER CATEGORY.
     *
     * Scoring ONE category now reads every category in its cycle, because that is where the
     * denominator comes from. Done naively that is quadratic in the size of an edition — a
     * full pass over `gates_votes` for every award drawn — and it lands on the public
     * results index and the Pulse, which draw up to sixty in a row. Nothing about that
     * failure is visible except a page that gets slower as a cycle grows, which is the
     * hardest kind of regression to attribute.
     *
     * So the scale is memoised on the SCORER and every loop over categories shares one.
     * Counted here rather than trusted, because the memo is invisible from the outside and
     * the next person to add a caller will construct their own scorer without thinking
     * about it.
     */
    public function test_the_edition_is_scanned_once_however_many_categories_are_drawn(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 10);
        $this->backers(9001, self::DEEP, 10, 'deep');
        $this->nominee(9002, self::THIN, 'Thin leader', 2);
        $this->backers(9002, self::THIN, 2, 'thin');

        $conn = DB::connection();
        $conn->flushQueryLog();
        $conn->enableQueryLog();
        ResultRelease::forCycle(self::CYCLE);
        $votes = 0;
        foreach ($conn->getQueryLog() as $q) {
            if (str_contains((string) $q['query'], 'gates_votes')) $votes++;
        }
        $conn->disableQueryLog();

        // One edition-wide pass, plus one per category for the nominees' own reach. Three
        // for a two-category cycle; six is the memo gone and the pass repeated per category.
        $this->assertLessThanOrEqual(3, $votes,
            'the edition scale is being recomputed per category, so drawing a cycle is '
            . 'quadratic in its own size — pass one scorer through the loop');
    }

    /**
     * AND THE SAME ON THE TWO PUBLIC LISTS, WHICH IS WHERE IT WOULD ACTUALLY HURT.
     *
     * `ResultRelease::forCycle()` already shared a scorer before any of this; the public
     * results index and the Pulse feed did not, because until the scale went edition-wide
     * there was nothing to share. They draw up to sixty awards in a row.
     */
    public function test_the_public_results_index_shares_one_scorer(): void
    {
        $prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'edition-scale-' . bin2hex(random_bytes(3)),
            'title' => 'Edition scale', 'is_active' => 1,
        ]);
        DB::table('gates_award_cycles')->where('id', self::CYCLE)->update([
            'programme_id' => $prog, 'status' => 'results',
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);

        $this->nominee(9001, self::DEEP, 'Deep leader', 10);
        $this->backers(9001, self::DEEP, 10, 'deep');
        $this->nominee(9002, self::THIN, 'Thin leader', 2);
        $this->backers(9002, self::THIN, 2, 'thin');

        $conn = DB::connection();
        $conn->flushQueryLog();
        $conn->enableQueryLog();
        $listed = PublicResults::index();
        $votes  = 0;
        foreach ($conn->getQueryLog() as $q) {
            if (str_contains((string) $q['query'], 'gates_votes')) $votes++;
        }
        $conn->disableQueryLog();

        $this->assertGreaterThan(0, count($listed['items']) + $listed['held'],
            'the fixture is not being listed at all, so this counts the queries of nothing');
        $this->assertLessThanOrEqual(3, $votes,
            'the public results index builds a scorer per award, so every card it draws '
            . 'scans the whole cycle again');
    }

    /**
     * AND THE SWEEP, BECAUSE THE NEXT CALLER WILL NOT KNOW ANY OF THIS.
     *
     * Two behavioural tests above cover the two loops that exist today. The cost is
     * structural, though, and invisible at the call site: `ResultRelease::category()` looks
     * like it draws one category, and drawing one category now reads a whole cycle. Anybody
     * adding a third list — a programme page, an export, a digest — will write the obvious
     * loop and make it quadratic without a single failing test.
     *
     * So this is the rule rather than the instances: a call to either drawing method from
     * inside a loop must pass a scorer. Cheap, static, and it fires on the line that would
     * cause the regression.
     */
    public function test_no_loop_draws_a_category_without_passing_a_scorer(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $bad  = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $lines = explode("\n", (string) file_get_contents($file->getPathname()));

            $fnAt = -1;                       // where the enclosing function began
            $loop = false;                    // has a loop opened since then
            foreach ($lines as $i => $line) {
                if (preg_match('/\bfunction\s+\w+\s*\(/', $line)) { $fnAt = $i; $loop = false; }
                if (preg_match('/\b(foreach|for|while)\s*\(/', $line)) $loop = true;

                if (!preg_match('/\b(PublicResults|ResultRelease)::category\s*\(/',
                                $line, $m, PREG_OFFSET_CAPTURE)) continue;
                // `{@see …::category()}` in a docblock is not a call.
                if (str_contains($line, '@see') || str_contains($line, '*')) continue;
                if (!$loop || $fnAt < 0) continue;

                // The argument list, with balanced parens — `(int) $catId` is one argument
                // and a naive `[^)]*` reads it as the whole list and finds no comma, which
                // makes this sweep fail on every correct call site.
                $open  = strpos($line, '(', $m[0][1]);
                $depth = 0; $args = '';
                for ($k = $open; $k < strlen($line); $k++) {
                    $ch = $line[$k];
                    if ($ch === '(') { $depth++; if ($depth === 1) continue; }
                    if ($ch === ')') { $depth--; if ($depth === 0) break; }
                    $args .= $ch;
                }
                if (str_contains($args, ',')) continue;      // a scorer is being passed

                $bad[] = basename($file->getPathname()) . ':' . ($i + 1) . '  ' . trim($line);
            }
        }

        $this->assertSame([], $bad,
            "a loop draws one category at a time without sharing a scorer, so each pass "
            . "re-reads the whole cycle:\n  " . implode("\n  ", $bad));
    }

    // ══ and the promises made about it in public ═════════════════════════════

    /**
     * NOTHING THIS PLATFORM PUBLISHES MAY STILL PROMISE THE PER-CATEGORY RULE.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE ARTICLE WAS TITLED "WHY A SMALL CATEGORY IS NOT A DISADVANTAGE"
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Its body said the community half is "normalised inside each category" and that
     * "being the clearest choice in a small field scores exactly as well as being the
     * clearest choice in a large one". Both were true, and the platform stopped doing
     * them — so the article became a published promise of the opposite of what the scorer
     * does, linked from /integrity, quoted inside how-cpi-works, and pasted into support
     * tickets by the assistant.
     *
     * The same article's sibling said "Paid votes are excluded entirely" while
     * `what-paid-votes-do`, four hundred lines away in the same file, said they count
     * exactly like a free vote. One help centre, two opposite answers, and the wrong one is
     * the one somebody reads before they decide whether to trust a result.
     *
     * ── AND WHY THIS IS A SWEEP RATHER THAN AN ASSERTION ABOUT ONE ARTICLE ──
     *
     * Because the fault is not "this article is wrong", it is "prose outlives the rule it
     * describes". Naming the article would pass forever after one edit while the next
     * person writes the same sentence somewhere else. The phrases below are the retired
     * rule stated in the words it was actually stated in, and the sweep is over every
     * article the platform will serve.
     */
    public function test_no_published_help_article_still_promises_the_per_category_scale(): void
    {
        $retired = [
            'normalised inside each category',
            'normalized inside each category',
            'strongest vote count in their own category',
            'Paid votes are excluded entirely',
            'paid votes are excluded from the score',
            'in a small field scores exactly as well',
            // ── AND THE PROMISE THE `ideal` BASIS RETIRED ────────────────────
            //
            // "What they cannot buy is the seventy per cent" was exactly true while that
            // seventy per cent divided by a count of PEOPLE: no cheque moves a number of
            // human beings. Both community counts now divide by the largest TOTAL, so the
            // total is what money moves, and a supporter is worth about 2.33 votes.
            //
            // The narrow claim still holds — splitting one payment into a thousand buys
            // nothing — and publishing ONLY the narrow claim is the worse kind of true:
            // a reader takes it to mean money cannot outrank supporters. This is the
            // article support pastes into a ticket about a contested result.
            'cannot buy the seventy per cent',
            'cannot buy the 70%',
            'What they cannot buy is the seventy',
            'buys no extra reach',
            'cannot buy the rest',
        ];

        $bad = [];
        foreach (\AfricaGates\Services\HelpCentre::all() as $a) {
            $text = \AfricaGates\Services\HelpCentre::plainText($a)
                  . ' ' . (string) ($a['title'] ?? '') . ' ' . (string) ($a['summary'] ?? '');
            foreach ($retired as $claim) {
                if (stripos($text, $claim) !== false) {
                    $bad[] = ($a['slug'] ?? '?') . ' — "' . $claim . '"';
                }
            }
        }

        $this->assertSame([], $bad,
            "the help centre still publishes a rule this platform retired:\n  "
            . implode("\n  ", $bad));
    }

    /**
     * AND THE ARTICLE SOMEBODY IS SENT TO STILL ANSWERS THE QUESTION.
     *
     * The slug is a published URL — it is on /integrity, inside how-cpi-works, and it is
     * what support pastes into a ticket. Retiring the promise is not a reason to break the
     * link: the answer at the end of it has to be the true one, and it has to still be
     * about small categories or the person who followed it has been sent nowhere.
     */
    public function test_the_small_category_article_still_answers_and_tells_the_truth(): void
    {
        $a = \AfricaGates\Services\HelpCentre::bySlug('why-a-small-category-is-not-a-disadvantage');

        $this->assertNotNull($a, 'a URL this platform publishes now resolves to nothing');

        $text = \AfricaGates\Services\HelpCentre::plainText($a);
        $this->assertStringContainsStringIgnoringCase('small category', $text);
        $this->assertStringContainsStringIgnoringCase('whole cycle', $text,
            'the article no longer says what the community half is measured against');
        $this->assertStringContainsStringIgnoringCase('panel decides the award', $text,
            'the article does not admit what the wider scale costs a small category, '
            . 'which is the one thing the person who followed this link needs');
    }

    /**
     * NO SCREEN MAY DESCRIBE A CATEGORY DISCOUNT, BECAUSE THERE ISN'T ONE.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE RELEASE SCREEN DREW ONE LONG AFTER IT COULD BE TRUE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * "category discounted — 89 votes against a full-credit mark of 1,000" sat under the
     * community column. It described {@see \AfricaGates\Services\CpiService::depth()},
     * which once scaled a category's whole community weight by how deep that category's
     * support was.
     *
     * It cannot be right under any basis this platform now runs:
     *
     *   · the default never calls depth() at all — the full-credit mark decides nothing;
     *   · `relative` passes the cohort maximum, and that is the EDITION's maximum, so the
     *     factor is one constant applied identically to every category in the cycle;
     *   · `absolute` passes the nominee's own tally, which was never about categories.
     *
     * The category is not a scoring unit here — the award is one, and categories are how
     * it is organised. This is a sweep rather than an assertion about one template,
     * because the fault is a CONCEPT surviving in prose after the arithmetic dropped it,
     * and the next person to reintroduce it will write it somewhere else.
     */
    public function test_no_screen_describes_a_per_category_discount(): void
    {
        $root = dirname(__DIR__, 2);
        $bad  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates'));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            $text = (string) file_get_contents($file->getPathname());
            foreach (['category discounted', 'category is discounted',
                      'discounted category'] as $claim) {
                if (stripos($text, $claim) !== false) {
                    $bad[] = basename($file->getPathname()) . ' — "' . $claim . '"';
                }
            }
        }

        $this->assertSame([], $bad,
            "a screen describes a per-category scoring adjustment that the arithmetic "
            . "does not have:\n  " . implode("\n  ", $bad));
    }

    /**
     * AND THE FACTOR IT DESCRIBED IS THE SAME FOR EVERY CATEGORY, MEASURED.
     *
     * The prose above is only worth trusting if the arithmetic backs it. Under `relative`
     * — the one basis that still reads the depth term against a cohort maximum — two
     * categories a factor of a hundred apart in their own support get an IDENTICAL
     * community factor, because the maximum both are measured against is the edition's.
     */
    public function test_the_depth_term_is_one_constant_across_the_whole_edition(): void
    {
        (new \AfricaGates\Services\RuleEngine())->set('global', null, [
            'community_basis' => \AfricaGates\Services\CpiService::BASIS_RELATIVE,
        ]);

        $this->nominee(9001, self::DEEP, 'Deep leader', 4000);
        $this->nominee(9002, self::THIN, 'Thin leader',   40);

        $scoring = new NomineeScoringService();
        $deep = $scoring->scoreCategory(self::DEEP)[9001];
        $thin = $scoring->scoreCategory(self::THIN)[9002];

        // Same denominator, so the depth factor depth(cohortMax) is the same term in both.
        $this->assertSame($deep['cohort_max'], $thin['cohort_max']);

        // And the thin category is not scaled by anything of its own: its nominee's share
        // is exactly (40/4000)^curve of what a full share would earn, with no extra
        // per-category factor applied on top.
        $this->assertSame(450, $deep['community_points'],
            'the edition leader is not collecting the full community half, so a factor '
            . 'is being applied that is not the share itself');
    }

    // ══ where the scale stops ════════════════════════════════════════════════

    /**
     * A CYCLE IS THE SCALE. A PROGRAMME'S WHOLE HISTORY IS NOT.
     *
     * Taking the maximum across every cycle a programme has held would re-scale a published
     * standing whenever a later edition drew a bigger tally, and would rank two different
     * electorates against each other. Last year's 9,000 votes must not touch this year's
     * arithmetic.
     */
    public function test_another_cycle_of_the_same_programme_does_not_set_this_scale(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 91, 'programme_id' => 0, 'year' => 2025, 'status' => 'closed',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 910, 'cycle_id' => 91, 'slug' => 'last-year', 'title' => 'Last year',
        ]);
        $this->nominee(9100, 910, 'Last year\'s phenomenon', 9000);

        $this->nominee(9001, self::DEEP, 'Deep leader', 4000);

        $this->assertSame(4000,
            (new NomineeScoringService())->scoreCategory(self::DEEP)[9001]['cohort_max'],
            'a previous edition is setting this one\'s denominator, so a published '
            . 'standing moves every time a later cycle draws a bigger tally');
    }

    /**
     * AND A MISSING `gates_award_cycles` ROW DOES NOT COLLAPSE THE SCALE.
     *
     * The scorer used to reach the cycle through an INNER join on that table, so a category
     * whose cycle row is absent resolved to no cycle at all — and would now silently fall
     * back to being scored against itself, which is the exact fault this whole change is
     * about, reappearing for a reason nothing on any screen would name.
     *
     * The row is absent more often than it looks: an import that carried categories and
     * nominees, a fixture, a cycle deleted after release. The category has always known
     * which edition it belongs to; the join was only ever there for the programme id.
     */
    public function test_a_category_whose_cycle_row_is_missing_is_still_scaled_across_it(): void
    {
        // Cycle 92 is never created. Both categories name it.
        DB::table('gates_award_categories')->insert([
            ['id' => 920, 'cycle_id' => 92, 'slug' => 'orphan-a', 'title' => 'Orphan A'],
            ['id' => 921, 'cycle_id' => 92, 'slug' => 'orphan-b', 'title' => 'Orphan B'],
        ]);
        $this->nominee(9200, 920, 'Orphan leader', 2000);
        $this->nominee(9201, 921, 'Orphan minnow',   20);

        $scoring = new NomineeScoringService();

        $this->assertSame(2000, $scoring->scoreCategory(921)[9201]['cohort_max'],
            'a category with no cycle row is being scored against itself again');
        $this->assertSame('edition', $scoring->scoreCategory(921)[9201]['cohort_scope']);
    }

    // ══ the one setting that puts it back ════════════════════════════════════

    /**
     * `community_scope = category` REPRODUCES WHAT WAS ANNOUNCED, AND NOTHING ELSE DOES.
     *
     * Results on this platform are published and printed onto physical awards, so a cycle
     * that has announced its standings has to be able to reproduce them to the digit. The
     * older bases and the older judge scale are kept for that reason; the denominator's
     * scope is the third leg, and without it setting the other two reproduces the shape of
     * an old cycle and not its numbers.
     *
     * It is deliberately not offered as an alternative rule. On this scope every category's
     * leader takes the whole community half however small their field — which is the fault
     * the edition scale exists to close, asserted here as the difference between 450 and 5.
     */
    public function test_the_category_scope_setting_reproduces_the_old_denominator(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 4000);
        $this->nominee(9002, self::THIN, 'Thin leader',   40);

        // No vote rows in this fixture, so the people term is unpaid and both figures are
        // the tally term alone. The point of this test is the DENOMINATOR and the ratio it
        // produces, which is unchanged: 40/4000 against 40/40.
        $wide = (new NomineeScoringService())->scoreCategory(self::THIN)[9002];
        $this->assertSame(4000, $wide['cohort_max']);
        $this->assertSame(1,    $wide['community_points']);

        (new \AfricaGates\Services\RuleEngine())->set('global', null,
            ['community_scope' => 'category']);

        $narrow = (new NomineeScoringService())->scoreCategory(self::THIN)[9002];
        $this->assertSame(40,  $narrow['cohort_max'],
            'the setting kept for reproducing an announced standing does not reproduce it');
        $this->assertSame('category', $narrow['cohort_scope']);
        // The whole TALLY TERM — 135 × 40/40 — because this fixture has no vote rows and
        // the people term is unpaid under either scope. What the old scope reproduces is
        // the denominator collapsing to the nominee's own category, which here is the
        // difference between 1 point and 135.
        $this->assertSame(135, $narrow['community_points'],
            'the old scope is meant to hand a category leader their whole community half — '
            . 'that is the fault it reproduces, and reproducing it is its only purpose');
        $this->assertGreaterThan($wide['community_points'] * 100, $narrow['community_points'],
            'a leader of a thin field must be paid enormously more under the old scope, or '
            . 'this setting is not reproducing the fault it exists to reproduce');
    }

    /**
     * AND IT CAN BE SET FROM A SCREEN, WHICH IS THE ONLY WAY IT CAN BE SET AT ALL.
     *
     * There is no shell on production. A rule reachable only by hand-editing a JSON column
     * is a rule nobody can apply, and the reason it would ever be applied — reproducing a
     * standing that has already been announced — arrives as an emergency, on the day, from
     * somebody who cannot open a file. `community_basis` was very nearly shipped in exactly
     * that state; a declared setting with no writer is the most expensive shape of bug in
     * this codebase.
     */
    public function test_the_scope_has_a_field_and_a_writer(): void
    {
        $root = dirname(__DIR__, 2);
        $tpl  = (string) file_get_contents($root . '/templates/admin/settings.twig');
        $ctl  = (string) file_get_contents($root . '/src/Admin/Controllers/SettingsController.php');

        $this->assertStringContainsString('name="community_scope"', $tpl,
            'there is no field for the denominator scope, so it can only be set by editing '
            . 'a file on a host with no shell');
        $this->assertStringContainsString("'community_scope'", $ctl,
            'the field posts a value the controller will not save');

        // And the screen says what choosing it does, in awards rather than in jargon: the
        // whole consequence is that one option pays a small field a full community half.
        $this->assertStringContainsString('The highest in the whole cycle', $tpl);
        $this->assertStringContainsString('The highest in each category', $tpl);
    }

    /**
     * AND A STRAY STRING FALLS BACK TO THE EDITION, NEVER TO THE CATEGORY.
     *
     * A settings form or a template is one typo away from writing an unrecognised value,
     * and the value it lands on decides how every award in the system is scaled. It has to
     * be the one that keeps the categories comparable.
     */
    public function test_an_unrecognised_scope_falls_back_to_the_edition(): void
    {
        $this->nominee(9001, self::DEEP, 'Deep leader', 4000);
        $this->nominee(9002, self::THIN, 'Thin leader',   40);

        (new \AfricaGates\Services\RuleEngine())->set('global', null,
            ['community_scope' => 'Category ']);

        $row = (new NomineeScoringService())->scoreCategory(self::THIN)[9002];
        $this->assertSame(4000, $row['cohort_max']);
        $this->assertSame('edition', $row['cohort_scope']);
    }

    /**
     * THE SCALE IS THE EDITION, AND THAT INCLUDES SOMEBODY OFF A SHORTLIST.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THIS TEST USED TO ASSERT THE OPPOSITE, AND THAT WAS THE FAULT
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The denominator used to be narrowed to each category's published shortlist, on the
     * reasoning that a popular nominee left off a list should not decide what the
     * finalists were worth.
     *
     * That reasoning was inherited from `relative`, where the denominator was the
     * nominee's OWN category leader: a 5,000-vote non-finalist could compress three
     * finalists on 500, 400 and 300 to four points apart on a thousand-point index, and
     * the panel then decided the final alone.
     *
     * Under an edition-wide `ideal` it inverts. Narrowing to the shortlist reintroduces
     * the exact fault the edition-wide scale exists to kill, one level down: every
     * shortlisted leader collects a full 450, precisely as every CATEGORY leader used to.
     * It produced a live report — a finalist on 500 votes from 500 supporters holding the
     * whole community half while a non-finalist in the same edition sat on 2,000 — where
     * every figure on the row was internally consistent and the backer count was plainly
     * smaller than the biggest tally anybody could see.
     *
     * And it contradicted the rule as published: "Highest Total Votes in award programme
     * edition", which is what a nominee is told their score means.
     *
     * ── THE OBJECTION IS REAL AND IS ACCEPTED, NOT ANSWERED ────────────────
     *
     * An edition with one runaway tally does hand out less community credit to everybody
     * else. Under `ideal` one denominator cancels out of every comparison, so the ORDER
     * never depends on it — what changes is the total credit in play, and an edition where
     * one person has most of the public support saying so is the honest answer rather than
     * a flattering one. The two rules cannot both hold; this is the one under which a
     * share means the same thing wherever it is printed.
     *
     * ── AND SETTING THE SCALE IS NOT BEING IN THE RUNNING ──────────────────
     *
     * The half of this that is easy to lose. Somebody left off a shortlist still cannot
     * win — that is decided separately, and is asserted here so a later reader does not
     * conclude the shortlist stopped meaning anything.
     */
    public function test_a_nominee_left_off_a_shortlist_still_sets_the_edition_scale(): void
    {
        $this->nominee(9001, self::DEEP, 'Shortlisted', 300);
        $this->nominee(9003, self::DEEP, 'Left off',   5000);
        $this->nominee(9002, self::THIN, 'Thin leader',   40);

        $sid = (int) DB::table('gates_shortlists')->insertGetId([
            'cycle_id' => self::CYCLE, 'category_id' => self::DEEP,
            'status' => 'published', 'entry_count' => 1, 'considered' => 2,
        ]);
        DB::table('gates_shortlist_entries')->insert([
            'shortlist_id' => $sid, 'nominee_id' => 9001,
        ]);

        $this->assertSame(5000,
            (new NomineeScoringService())->scoreCategory(self::THIN)[9002]['cohort_max'],
            'the published rule is the highest total votes in the EDITION, and a nominee '
            . 'off a shortlist is still in the edition');

        // The shortlisted nominee is measured against it too, rather than against
        // themselves — which is the whole point: 300 of 5,000, not 300 of 300.
        $this->assertSame(5000,
            (new NomineeScoringService())->scoreCategory(self::DEEP)[9001]['cohort_max']);

        // And they still cannot win. The shortlist decides the running; it does not
        // decide the yardstick.
        $drawn = \AfricaGates\Services\ResultRelease::category(self::DEEP);
        $off = null;
        foreach ($drawn['rows'] as $r) if ((int) $r['nominee_id'] === 9003) $off = $r;
        $this->assertNotNull($off, 'the left-off nominee is not drawn at all');
        $this->assertFalse($off['on_shortlist'],
            'setting the scale must not put somebody back into the running');
    }
}
