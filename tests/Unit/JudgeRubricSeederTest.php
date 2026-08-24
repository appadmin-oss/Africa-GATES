<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Services\{JudgeRubric, JudgeRubricSeeder};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The published rubric, installed as the default — and the one thing it must never do.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A DEFAULT RUBRIC HAD TO EXIST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_judge_criteria` had NO default rows anywhere — not in either schema file, not in
 * `seed.sql`, not in any migration. The only writer was the sandbox seeder, into its own
 * programme. So a fresh installation had an empty global rubric, and
 * {@see JudgeService::criteria()} returning nothing LOCKS scoring: the judging panel of a
 * new deployment could not score anybody until four rows were written by hand in the
 * database.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE ONE THING IT MUST NEVER DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Overwrite a rubric somebody is already using. This runs from a MIGRATION, and migrations
 * run on every deploy — so a seeder that reasserted the shipped four would silently undo a
 * retirement, a reweight or a fifth criterion, mid-cycle, on rows that ballots already point
 * at. That is the same class of harm as deleting a scored criterion, arriving through the
 * back door where nobody is looking for it.
 */
final class JudgeRubricSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_judge_criteria')->delete();
    }

    // ════════════════════════════════════════════════════════════════════════
    // WHAT IT INSTALLS
    // ════════════════════════════════════════════════════════════════════════

    public function test_it_installs_the_four_published_criteria(): void
    {
        $r = JudgeRubricSeeder::install();

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertSame(4, $r['installed']);

        $slugs = array_map(
            static fn (object $c): string => (string) $c->slug,
            JudgeRubric::forScope(null)
        );
        $this->assertSame(['impact', 'originality', 'reach', 'integrity'], $slugs,
            'the four, in the order they are published');
    }

    /**
     * Equal weights, and it is the POINT rather than a neutral default.
     *
     * Four equal quarters mean no single dimension can carry a result on its own — not
     * wealth, not a following, not the public tally. A rubric weighted 40/25/20/15 lets its
     * heaviest criterion decide, and the heaviest criterion is always the one easiest to
     * perform.
     */
    public function test_all_four_carry_exactly_a_quarter_of_the_score(): void
    {
        JudgeRubricSeeder::install();

        $shares = JudgeRubric::shares(null);

        $this->assertCount(4, $shares);
        foreach ($shares as $id => $pct) {
            $this->assertSame(25.0, $pct, 'criterion #' . $id . ' is not worth a quarter');
        }
    }

    /**
     * Installed GLOBAL, because that is how the scorer resolves.
     *
     * Global applies to every programme, and a programme needing its own version overrides
     * a criterion by reusing its slug. Copying four rows into every programme would produce
     * the same ballot today and four separate things to edit tomorrow.
     */
    public function test_it_is_the_default_for_every_programme_not_a_copy_per_programme(): void
    {
        $prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Continental', 'slug' => 'continental-' . bin2hex(random_bytes(3)),
        ]);

        JudgeRubricSeeder::install();

        $this->assertSame(4, DB::table('gates_judge_criteria')->whereNull('programme_id')->count());
        $this->assertSame(0, DB::table('gates_judge_criteria')->whereNotNull('programme_id')->count(),
            'a per-programme copy is four more things to edit and the same ballot');

        // And a programme with no rubric of its own is asked all four.
        $this->assertCount(4, JudgeRubric::effective($prog));
    }

    /** Every criterion carries the question and the test a judge applies, not just a name. */
    public function test_each_criterion_says_what_a_judge_is_actually_deciding(): void
    {
        JudgeRubricSeeder::install();

        foreach (JudgeRubric::forScope(null) as $c) {
            $desc = (string) $c->description;
            $this->assertNotSame('', trim($desc), $c->slug . ' has no description');
            $this->assertStringContainsString('The test:', $desc,
                $c->slug . ' states no test, so it is a label rather than a criterion');
            // MAX_DESC would truncate silently, and a truncated criterion loses its test —
            // which is the half a judge applies.
            $this->assertLessThanOrEqual(JudgeRubric::MAX_DESC, mb_strlen($desc),
                $c->slug . ' was truncated on the way in');
        }
    }

    /** Reach is not follower count, and the criterion has to say so where a judge reads it. */
    public function test_reach_distinguishes_itself_from_popularity(): void
    {
        JudgeRubricSeeder::install();

        $reach = (string) DB::table('gates_judge_criteria')->where('slug', 'reach')->value('description');
        $this->assertStringContainsStringIgnoringCase('follower count', $reach,
            'without this, Reach is scored as an audience size');
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND WHAT IT REFUSES TO DO
    // ════════════════════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS. It never overwrites a rubric already in use.
     *
     * The migration runs on every deploy. An operator who reweighted a criterion made a
     * decision about rows that ballots already point at.
     */
    public function test_it_never_touches_a_rubric_that_already_exists(): void
    {
        $mine = JudgeRubric::save(null, 0, ['label' => 'Our own measure', 'weight' => '40']);
        $this->assertTrue($mine['ok']);

        $r = JudgeRubricSeeder::install();

        $this->assertTrue($r['ok'], 'refusing is a success, not an error');
        $this->assertSame(0, $r['installed']);
        $this->assertCount(1, JudgeRubric::forScope(null), 'the shipped four were forced in');
        $this->assertSame(40, (int) JudgeRubric::find((int) $mine['id'])->weight);
    }

    /** Running it twice is the same as running it once — migrations run on every deploy. */
    public function test_installing_twice_does_not_duplicate_the_rubric(): void
    {
        JudgeRubricSeeder::install();
        JudgeRubricSeeder::install();

        $this->assertCount(4, JudgeRubric::forScope(null));
    }

    /**
     * A RETIRED criterion still counts as a rubric.
     *
     * Retiring every criterion is a deliberate act — mid-cycle, deciding nobody scores until
     * the rubric is rewritten. Reading "no ACTIVE criteria" as "no rubric" would have the
     * next deploy quietly put four back and start the panel scoring again.
     */
    public function test_a_deliberately_emptied_rubric_is_not_refilled(): void
    {
        $id = (int) JudgeRubric::save(null, 0, ['label' => 'Impact', 'weight' => '25'])['id'];
        DB::table('gates_judge_criteria_scores')->insert([
            'criterion_id' => $id, 'judge_id' => 1, 'nominee_id' => 1, 'category_id' => 1,
            'score' => 8, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        JudgeRubric::retire(null, $id);

        $this->assertTrue(JudgeRubricSeeder::installed(), 'a retired row is still a row');
        $this->assertSame(0, JudgeRubricSeeder::install()['installed']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND THE DOCTRINE DESCRIBES THE CODE
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The independence principle is printed on the admin screen as a statement of fact, so
     * the fact has to hold: the public tally must not reach a judge.
     *
     * Asserted against the real ballot rather than against the wording, because a principle
     * that is only printed is one that quietly stops being true. The tally is UNSET at the
     * boundary rather than left unrendered — the template not printing it today is a
     * property of today's template, and one `{{ n.vote_count }}` added while building a
     * nicer card would put the community signal back inside the expert one.
     */
    public function test_the_printed_independence_claim_is_true_of_the_ballot(): void
    {
        $this->assertNotSame([], JudgeRubricSeeder::doctrine());

        $prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Continental', 'slug' => 'continental-' . bin2hex(random_bytes(3)),
            'is_active' => 1,
        ]);
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $prog, 'year' => (int) date('Y'), 'status' => 'judging',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycle, 'slug' => 'c1', 'title' => 'Category', 'sort_order' => 1,
        ]);
        DB::table('gates_nominees')->insert([
            'category_id' => $cat, 'name' => 'Somebody Popular', 'status' => 'approved',
            'vote_count' => 9999, 'organic_vote_count' => 9999, 'country_code' => 'NG',
        ]);
        $judge = (int) DB::table('gates_judges')->insertGetId([
            'name' => 'Chair', 'email' => 'chair@example.org', 'is_active' => 1,
            'programme_ids' => json_encode([$prog]),
        ]);

        JudgeRubricSeeder::install();

        $ballot = (new JudgeService())->ballot($judge, $prog);

        $seen = 0;
        foreach ($ballot['categories'] ?? [] as $c) {
            foreach ($c['nominees'] ?? [] as $n) {
                $seen++;
                $this->assertArrayNotHasKey('vote_count', $n,
                    'the public tally reached the judging screen');
                $this->assertArrayNotHasKey('organic_vote_count', $n);
            }
        }
        $this->assertGreaterThan(0, $seen, 'nothing was on the ballot, so nothing was proven');
    }
}
