<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CpiService, JudgeRubric, PublicResults, ResultCard, ResultRelease,
    ResultThread, RuleEngine, VoteService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * THE CYCLE WHERE THE SEVENTY PER CENT NEVER RAN, AND NO SCREEN SAID SO.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE REPORT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "Someone still scored 450 for community votes. Their backers was less than the total
 * highest." Under {@see CpiService::BASIS_IDEAL} that combination is arithmetically
 * impossible where reach was measured: both terms divide by the same figure, so a full
 * 450 needs as many supporters as the biggest tally in the edition.
 *
 * It is not impossible in the FALLBACK. Where `cohort_max_unique` is zero — not one
 * nominee anywhere in the edition has a countable ballot row — the scorer pays the
 * community half on the tally alone, so the leading tally collects the whole 450 with any
 * number of backers at all, including none. That is deliberate, documented, and the right
 * behaviour: flooring the denominator at one instead would pay the entire field 30% of the
 * half, which preserves the order and so goes unnoticed.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS ACTUALLY BROKEN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every branch on every screen that explains a community half was gated on
 * `cohort_max_unique > 0` with NOTHING on the other side of the gate. So in exactly the
 * case that inflates a score, the release screen's denominator cell rendered empty and the
 * public page's method note described a seventy per cent that had not been computed.
 *
 * `reach_unmeasured` cannot cover it and must not: that flag requires the maximum to be
 * ABOVE zero, because it exists for the nominee whose rows are missing while the rest of
 * the edition has them — the one who silently loses 315 points. Two different facts, and
 * the one with no flag anywhere was the one that hands a nominee points rather than taking
 * them away.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT RENDERS RATHER THAN SWEEPS THE SOURCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A sweep for the sentence in the file would pass with the sentence inside a branch that
 * never fires — which is the whole fault being fixed. Both screens are drawn from the same
 * services their controllers call, on a fixture that IS the fallback, under
 * `strict_variables`.
 *
 * Mutation-checked: removing the `{% elseif %}` from `admin/result-release.twig` and the
 * `tally_only` clause from `pages/results/show.twig` fails
 * `test_the_release_screen_states_the_half_was_paid_on_tally_alone` and
 * `test_the_public_page_states_the_half_was_paid_on_tally_alone` respectively.
 */
final class TallyOnlyCommunityHalfTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;
    private int $categoryId  = 0;

    private const ADMIN_LAYOUT = <<<'TWIG'
        <!doctype html><title>{% block topbar_title %}{% endblock %}</title>
        {% block head_styles %}{% endblock %}
        <main>{% block content %}{% endblock %}</main>
        TWIG;

    private const SITE_LAYOUT = <<<'TWIG'
        <!doctype html><title>{{ page_title }}</title>
        {% block head_styles %}{% endblock %}
        <main>{% block content %}{% endblock %}</main>
        {% block foot_scripts %}{% endblock %}
        TWIG;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'tally-' . bin2hex(random_bytes(3)),
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

    private function nominee(string $name, int $votes): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $votes, 'vote_count' => $votes,
        ]);
    }

    /** Real, distinct, verified voters — the vote ROWS reach is counted from. */
    private function backers(int $nominee, int $n, string $tag): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => $nominee, 'category_id' => $this->categoryId,
                'vote_type' => 'standard', 'weight' => 1,
                'voter_email_hash' => VoteService::voterHash($tag . $i . '@x.test'),
            ]);
        }
    }

    /**
     * Publish a shortlist naming exactly these nominees.
     *
     * Two tables, and the resolver reads them together — {@see ResultRelease::shortlistedIn()}
     * takes the newest PUBLISHED list for the category and then its entries. A fixture that
     * wrote a draft would silently shortlist nobody, which is the state that changes
     * nothing, so the test would pass by testing the unshortlisted path.
     *
     * @param list<int> $nomineeIds
     */
    private function shortlist(array $nomineeIds): void
    {
        // `cycle_id` is NOT NULL with no default. Omitting it throws on MySQL and on the
        // SQLite harness alike — check the schema, not the fixture.
        $id = (int) DB::table('gates_shortlists')->insertGetId([
            'cycle_id'    => $this->cycleId,
            'category_id' => $this->categoryId,
            'status'      => 'published',
            'entry_count' => count($nomineeIds),
        ]);
        foreach ($nomineeIds as $n) {
            DB::table('gates_shortlist_entries')->insert([
                'shortlist_id' => $id, 'nominee_id' => $n,
            ]);
        }
    }

    private function panel(int $nominee, int $mark): void
    {
        static $n = 0;
        for ($k = 0; $k < 2; $k++) {
            $j = (int) DB::table('gates_judges')->insertGetId([
                'name' => 'Judge ' . (++$n), 'is_active' => 1,
                'email' => 'tally.j' . $n . '@example.test',
                'programme_ids' => json_encode([$this->programmeId]),
            ]);
            foreach (JudgeRubric::effective($this->programmeId) as $c) {
                if ((int) $c->is_active !== 1) continue;
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee,
                    'category_id' => $this->categoryId, 'criterion_id' => (int) $c->id,
                    'score' => $mark,
                    'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
                ]);
            }
        }
    }

    /**
     * A cycle with tallies and NO ballot rows anywhere — an import, a restore, a purge.
     * This is the shape the fallback exists for, and it is the shape a great many of this
     * suite's own fixtures already had while nothing asserted what the screens said.
     */
    private function importedCycle(): array
    {
        $a = $this->nominee('Dr. Adegboyega Aborode', 2000);
        $b = $this->nominee('Ajayi Temitope Oluwarotimi', 600);
        $this->panel($a, 8);
        $this->panel($b, 7);

        return [$a, $b];
    }

    // ══ the arithmetic the report describes ══════════════════════════════════

    /**
     * AN UNMEASURED EDITION IS CAPPED AT THE TALLY TERM, NOT PAID THE WHOLE HALF.
     *
     * This used to assert 450 — the all-or-nothing fallback, which handed the tally the
     * entire community half when nobody's supporters could be counted, so the leading
     * tally collected the lot with any number of backers at all, including none. That was
     * one of the two mechanisms behind the report this file is named for.
     *
     * Asserted across the range because the point is that the backer count genuinely is
     * not consulted here: whatever it is, only the 135 is paid, because only the tally was
     * measured.
     */
    public function test_an_unmeasured_edition_pays_the_tally_term_only(): void
    {
        foreach ([0, 3, 1999] as $backers) {
            $part = CpiService::communityPart(
                voteCount: 2000, cohortMaxVotes: 2000,
                basis: CpiService::BASIS_IDEAL,
                uniqueVoters: $backers, cohortMaxUnique: 0,
            );

            $this->assertSame(135.0, round($part * 450, 2),
                'with ' . $backers . ' backers and nothing counted anywhere in the '
                . 'edition, the 315 must not be paid — nothing measured it');
        }
    }

    /**
     * AND WITH THE ROWS PRESENT THE SAME REPORT IS IMPOSSIBLE.
     *
     * Both terms of `ideal` divide by the same figure, so the full half needs as many
     * separate supporters as the biggest tally anybody reached. This is the assertion that
     * makes the fallback the only explanation worth chasing.
     */
    public function test_measured_reach_cannot_pay_a_full_half_to_a_narrow_nominee(): void
    {
        $narrow = CpiService::communityPart(
            voteCount: 2000, cohortMaxVotes: 2000,
            basis: CpiService::BASIS_IDEAL,
            uniqueVoters: 3, cohortMaxUnique: 1200,
        );
        // 0.70 × (3 ÷ 2,000) + 0.30 × (2,000 ÷ 2,000) = 0.30105
        $this->assertSame(135.47, round($narrow * 450, 2),
            '2,000 votes from three people is the 30% term and almost none of the 70%');

        $broad = CpiService::communityPart(
            voteCount: 2000, cohortMaxVotes: 2000,
            basis: CpiService::BASIS_IDEAL,
            uniqueVoters: 2000, cohortMaxUnique: 2000,
        );
        $this->assertSame(450.0, round($broad * 450, 2),
            'a full half under `ideal` means one supporter per vote of the biggest tally');
    }

    /**
     * THE DRAWN CYCLE CARRIES THE FACT THE SCREENS NEED.
     *
     * `cohort_max_unique` at zero is the signal, and it comes off the scorer rather than
     * being worked out again by a template. Pinned here because both screens branch on it
     * and a service that stopped publishing it would make both branches unreachable in
     * silence — which is the state this file was written to end.
     */
    public function test_the_drawn_cycle_reports_that_reach_was_unmeasurable(): void
    {
        [$a] = $this->importedCycle();

        $r = ResultRelease::category($this->categoryId);

        $this->assertSame(0, $r['cohort_max_unique'],
            'the fixture has tallies and no ballot rows, so nothing was measurable');
        $this->assertSame(CpiService::BASIS_IDEAL, $r['community_basis']);
        $this->assertSame(0, $r['reach_unmeasured'],
            'the per-nominee flag deliberately does not fire here — it needs the cycle '
            . 'maximum above zero, because it marks the nominee who LOSES the 315 while '
            . 'the rest of the edition has rows');

        $leader = null;
        foreach ($r['rows'] as $row) if ((int) $row['nominee_id'] === $a) $leader = $row;
        $this->assertNotNull($leader, 'the leader is not in the drawn field');
        $this->assertSame(135, (int) $leader['community_points'],
            'the tally term alone — the 315 is not paid where nothing counted supporters');
    }

    // ══ and both screens say so ══════════════════════════════════════════════

    private function releaseScreen(): string
    {
        $categories = ResultRelease::forCycle($this->cycleId);

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['admin/layout.twig' => self::ADMIN_LAYOUT]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        // A Twig GLOBAL in the running app, not a controller argument. See
        // ResultReleaseScreenRenderTest, which pays for this in the same way.
        $twig->addGlobal('csrf_token', 'test-csrf');

        $cycle = ['id' => $this->cycleId, 'year' => 2026, 'status' => 'results',
                  'edition_label' => '2026 edition', 'results_date' => null,
                  'programme' => 'Incredible Principal Awards'];

        return (string) preg_replace('~\s+~u', ' ', $twig->render('admin/result-release.twig', [
            'page_title' => 'Result release',
            'admin_page' => 'result-release',
            'cycles'     => [$cycle],
            'cycle_id'   => $this->cycleId,
            'cycle'      => $cycle,
            'paid_only'  => \AfricaGates\Services\PaidVoteService::freeVotingDisabled(),
            'categories' => $categories,
            'attention'  => ResultRelease::attention($categories),
            'basis_default' => RuleEngine::DEFAULTS['community_basis'],
            'overall'    => ResultRelease::overall($this->cycleId, $categories),
            'sealed'     => \AfricaGates\Services\ReleasedStanding::divergence(
                                $categories, $this->cycleId),
            'recount_said' => null,
            'failed'     => false,
        ]));
    }

    private function publicPage(): string
    {
        $r = PublicResults::category($this->categoryId);
        $this->assertNotNull($r, 'the fixture is not publishable');

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['layout/gates.twig' => self::SITE_LAYOUT]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return (string) preg_replace('~\s+~u', ' ', $twig->render('pages/results/show.twig', [
            'page_title' => 'Result', 'gates_page' => 'results',
            'r' => $r, 'thread' => ResultThread::forCategory($this->categoryId),
            'replies' => [], 'is_member' => false,
            'og_image' => '', 'og_image_w' => ResultCard::W, 'og_image_h' => ResultCard::H,
        ]));
    }

    public function test_the_release_screen_states_the_half_was_paid_on_tally_alone(): void
    {
        $this->importedCycle();

        $this->assertStringContainsString('tally only', $this->releaseScreen(),
            'the denominator cell rendered EMPTY in the one case that inflates a score: '
            . 'both branches were gated on cohort_max_unique > 0 and there was no else');
    }

    public function test_the_public_page_states_the_half_was_paid_on_tally_alone(): void
    {
        $this->importedCycle();
        $html = $this->publicPage();

        $this->assertStringContainsString('total votes alone', $html,
            'the method note described a seventy per cent that was never computed');
        $this->assertStringContainsString('it applies to everybody in the cycle', $html,
            'a caveat that reads as a finding about one nominee would be an accusation — '
            . 'this is a gap in our records and it covers the whole field');
    }

    /**
     * AND NEITHER SCREEN SAYS IT WHERE IT IS UNTRUE.
     *
     * A disclosure printed on every cycle is a disclosure nobody reads, and this one would
     * be actively wrong: it says the seventy per cent was not computed. With rows present
     * it was.
     */
    public function test_neither_screen_says_it_when_the_rows_are_there(): void
    {
        [$a, $b] = $this->importedCycle();
        $this->backers($a, 2, 'a');
        $this->backers($b, 4, 'b');

        $r = ResultRelease::category($this->categoryId);
        $this->assertGreaterThan(0, $r['cohort_max_unique'], 'the fixture has no rows');

        $this->assertStringNotContainsString('tally only', $this->releaseScreen());
        $this->assertStringNotContainsString('total votes alone', $this->publicPage());
    }

    // ══ and the screen says WHICH rule produced the numbers ═════════════════

    /**
     * A SAVED OVERRIDE IS THE COMMONEST CAUSE OF A FIGURE NOBODY CAN EXPLAIN.
     *
     * The default is `ideal`, under which a full community half requires as many
     * supporters as the biggest tally in the edition — so "450 with fewer backers than the
     * highest total" is arithmetically impossible under it once reach is measured. Under
     * `reach` it is ordinary: the people term has its own denominator, so the nominee with
     * the most BACKERS takes the whole 315 whatever the largest tally was.
     *
     * A cycle carrying an older `reach` override is scored the old way deliberately, so an
     * announced standing stays reproducible. What was missing is any way to KNOW that:
     * every figure on the row looks ordinary, there is no shell on this host, and the
     * operator cannot tell a bug from a setting.
     */
    public function test_the_screen_names_the_layer_that_chose_the_basis(): void
    {
        $this->importedCycle();
        (new RuleEngine())->set('cycle', $this->cycleId,
            ['community_basis' => CpiService::BASIS_REACH]);

        $this->assertSame('cycle', (new RuleEngine())->provenance(
            'community_basis', $this->programmeId, $this->cycleId)['from']);

        $html = $this->releaseScreen();

        $this->assertStringContainsString('produced by a saved override', $html);
        $this->assertStringContainsString('set at the <b>cycle</b> level', $html,
            'the notice has to name the LAYER — global, programme or cycle — because that '
            . 'is where the operator goes to change it');
        $this->assertStringContainsString('fewer backers than the largest vote total', $html,
            'the notice has to state the consequence, or it is a fact with no meaning '
            . 'attached on the page an award is signed off from');
    }

    /** A programme-level override is named as the programme's, not the cycle's. */
    public function test_it_distinguishes_a_programme_override_from_a_cycle_one(): void
    {
        $this->importedCycle();
        (new RuleEngine())->set('programme', $this->programmeId,
            ['community_basis' => CpiService::BASIS_REACH]);

        $this->assertSame('programme', (new RuleEngine())->provenance(
            'community_basis', $this->programmeId, $this->cycleId)['from']);
        $this->assertStringContainsString('set at the <b>programme</b> level',
            $this->releaseScreen());
    }

    /**
     * THE CYCLE LAYER WINS, AND `provenance()` MUST AGREE WITH THE SCORER.
     *
     * Two walks of the same rows is how a screen comes to name a layer the scorer did not
     * read. `provenance()` and `effective()` share one resolution path for that reason.
     */
    public function test_the_narrowest_layer_decides_and_matches_what_was_scored(): void
    {
        $this->importedCycle();
        $rules = new RuleEngine();
        $rules->set('global',    null,               ['community_basis' => CpiService::BASIS_RELATIVE]);
        $rules->set('programme', $this->programmeId, ['community_basis' => CpiService::BASIS_REACH]);
        $rules->set('cycle',     $this->cycleId,     ['community_basis' => CpiService::BASIS_IDEAL]);

        $p = $rules->provenance('community_basis', $this->programmeId, $this->cycleId);
        $this->assertSame('cycle', $p['from']);
        $this->assertSame(CpiService::BASIS_IDEAL, $p['value']);
        $this->assertSame($p['value'],
            $rules->effective($this->programmeId, $this->cycleId)['community_basis'],
            'the layer this screen names must be the layer the scorer actually used');

        $this->assertSame(CpiService::BASIS_IDEAL,
            ResultRelease::category($this->categoryId)['community_basis']);
    }

    /**
     * AND IT SAYS NOTHING WHERE NOTHING WAS OVERRIDDEN.
     *
     * "This cycle uses the default" on every ordinary cycle is a line an operator learns to
     * scroll past — and then does not read on the one cycle where it matters.
     */
    public function test_an_unoverridden_cycle_gets_no_notice(): void
    {
        $this->importedCycle();

        $this->assertSame('default', (new RuleEngine())->provenance(
            'community_basis', $this->programmeId, $this->cycleId)['from']);
        $this->assertStringNotContainsString('saved override', $this->releaseScreen());
    }

    // ══ the yardstick is the edition, shortlist or no shortlist ═════════════

    /**
     * A SHORTLIST NO LONGER HIDES A BIGGER TALLY FROM THE YARDSTICK.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THIS IS THE REPORTED FAULT, AND THIS TEST IS WHAT KEEPS IT FIXED
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The denominator used to be narrowed to each category's published shortlist. So a
     * finalist on 500 votes from 500 supporters held the whole 450 while a non-finalist in
     * the same edition sat on 2,000 — every figure on the row internally consistent, and a
     * full community half beside a backer count plainly smaller than the biggest tally
     * anybody could see.
     *
     * The published rule is "Highest Total Votes in award programme edition". A nominee off
     * a shortlist is still in the edition, so their tally still sets the scale. 500 of
     * 2,000 is a quarter of the half, not all of it.
     *
     * ── AND SETTING THE SCALE IS NOT BEING IN THE RUNNING ──────────────────
     *
     * The half of this that is easy to lose on a later read. The shortlist decides who can
     * win; it does not decide what a vote is worth.
     */
    public function test_a_shortlist_no_longer_hides_a_bigger_tally_from_the_yardstick(): void
    {
        $finalist = $this->nominee('Dr. Adegboyega Aborode', 500);
        $outsider = $this->nominee('Ogunyemi Olusola Titilope', 2000);
        $this->panel($finalist, 8);
        $this->panel($outsider, 8);
        $this->backers($finalist, 500, 'f');
        $this->backers($outsider, 4, 'o');
        $this->shortlist([$finalist]);

        $r = ResultRelease::category($this->categoryId);

        $this->assertSame(2000, $r['cohort_max'],
            'the yardstick is the largest total in the EDITION, and somebody off a '
            . 'shortlist is still in the edition');

        $row = $out = null;
        foreach ($r['rows'] as $x) {
            if ((int) $x['nominee_id'] === $finalist) $row = $x;
            if ((int) $x['nominee_id'] === $outsider) $out = $x;
        }
        $this->assertNotNull($row);

        // 315 × 500/2,000 + 135 × 500/2,000 = 112.5 → 113.
        $this->assertSame(113, (int) $row['community_points'],
            '500 supporters and 500 votes against a 2,000-vote yardstick is a quarter of '
            . 'the community half; it used to be all of it');
        $this->assertLessThan(450, (int) $row['community_points'],
            'a full community half with fewer backers than the biggest tally in the '
            . 'edition is the exact fault that was reported');

        // And the shortlist still decides the running.
        $this->assertNotNull($out);
        $this->assertFalse($out['on_shortlist'],
            'setting the scale must not put somebody back into the running');
    }

    /**
     * AND THE FULL HALF NOW MEANS WHAT IT SAYS.
     *
     * Under the published rule 450 requires as many separate supporters as the biggest
     * total anybody managed. With the two mechanisms that broke that removed, this is the
     * only way to reach it — which is the property the whole 70/30 split exists for.
     */
    public function test_a_full_community_half_now_requires_matching_the_biggest_tally(): void
    {
        $a = $this->nominee('Dr. Adegboyega Aborode', 800);
        $b = $this->nominee('Ajayi Temitope Oluwarotimi', 800);
        $this->panel($a, 8);
        $this->panel($b, 8);
        $this->backers($a, 800, 'a');     // one vote, one person
        $this->backers($b, 40, 'b');      // the same tally from forty people

        $r = ResultRelease::category($this->categoryId);
        $by = [];
        foreach ($r['rows'] as $x) $by[(int) $x['nominee_id']] = $x;

        $this->assertSame(800, $r['cohort_max']);
        $this->assertSame(450, (int) $by[$a]['community_points'],
            'as many supporters as the biggest tally IS the full half');
        $this->assertSame(151, (int) $by[$b]['community_points'],
            '315 × 40/800 + 135 × 800/800 = 150.75 → the same tally from forty people is '
            . 'worth a third of what it is worth from eight hundred');
    }

    /**
     * THE WORKING IS ON THE ROW.
     *
     * A score an operator cannot reproduce from the row is one they have to take on trust,
     * and the call they get is "this cannot be right". Every screen here explained the
     * RULE and printed the inputs somewhere else, so checking one nominee meant holding
     * three numbers from three places in your head.
     */
    public function test_the_row_prints_the_arithmetic_of_the_community_half(): void
    {
        [$a] = $this->importedCycle();
        $this->backers($a, 400, 'a');

        $r = ResultRelease::category($this->categoryId);
        $row = null;
        foreach ($r['rows'] as $x) if ((int) $x['nominee_id'] === $a) $row = $x;
        $this->assertNotNull($row);

        $html = $this->releaseScreen();

        $this->assertStringContainsString(
            '315 &times; 400/2,000 + 135 &times; 2,000/2,000 = <b>'
            . $row['community_points'] . '</b>', $html,
            'the half cannot be checked against the number printed beside it');
    }

    /**
     * THE LEGACY BASES ARE NOT COVERED, AND THAT IS CORRECT.
     *
     * `relative` and `absolute` have no reach term to lose, so a sentence about a seventy
     * per cent that could not be worked out would describe a rule the cycle never ran
     * under — which is this repository's most expensive documented fault, on the page a
     * nominee checks their own score against.
     */
    public function test_a_basis_with_no_reach_term_gets_no_such_caveat(): void
    {
        $this->importedCycle();
        (new RuleEngine())->set('cycle', $this->cycleId,
            ['community_basis' => CpiService::BASIS_RELATIVE]);

        $r = ResultRelease::category($this->categoryId);
        $this->assertSame(CpiService::BASIS_RELATIVE, $r['community_basis']);

        $this->assertStringNotContainsString('tally only', $this->releaseScreen());
        $this->assertStringNotContainsString('total votes alone', $this->publicPage());
    }
}
