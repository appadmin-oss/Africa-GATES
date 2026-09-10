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
     * 450 WITH ALMOST NO BACKERS, AND IT IS THE RULE RATHER THAN A FAULT.
     *
     * Asserted on the scorer directly, at the two ends of the range, because the point is
     * that the backer count is not consulted at all: a leader with three supporters and a
     * leader with none are paid identically.
     */
    public function test_the_fallback_pays_the_leading_tally_the_whole_community_half(): void
    {
        foreach ([0, 3, 1999] as $backers) {
            $part = CpiService::communityPart(
                voteCount: 2000, cohortMaxVotes: 2000,
                basis: CpiService::BASIS_IDEAL,
                uniqueVoters: $backers, cohortMaxUnique: 0,
            );

            $this->assertSame(450.0, round($part * 450, 2),
                'the fallback pays on the tally alone, so ' . $backers . ' backers behind '
                . 'the leading tally is still the full community half — which is the '
                . 'figure an operator gets asked about');
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
        $this->assertSame(450, (int) $leader['community_points'],
            'the whole community half, from a tally with nobody counted behind it');
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
