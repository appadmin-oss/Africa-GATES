<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{DemoSeeder, JudgeRubric};
use AfricaGates\Judge\Services\JudgeService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The judging rubric, and the two things editing it must never quietly do.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_judge_criteria` is what the entire scoring system runs on — every ballot, every
 * weighted average, every bias check, every published result — and it had NO EDITOR
 * ANYWHERE. It was written by the installer and by the sandbox seeder and read everywhere
 * else. An operator running a real cycle could not add a criterion, change a weight, fix a
 * description or retire one.
 *
 * The two rules that carry weight here both protect results that have already been reached:
 * a scored criterion is never deleted, and its reference never changes.
 */
final class JudgeRubricTest extends TestCase
{
    private int $prog = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_judge_criteria')->delete();

        $this->prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Principals', 'slug' => 'principals-' . bin2hex(random_bytes(3)),
        ]);
    }

    private function add(?int $scope, string $label, int $weight = 25, array $over = []): int
    {
        $r = JudgeRubric::save($scope, 0, $over + ['label' => $label, 'weight' => (string) $weight]);
        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        return (int) $r['id'];
    }

    private function score(int $criterionId, int $judge = 1, int $nominee = 1): void
    {
        DB::table('gates_judge_criteria_scores')->insert([
            'criterion_id' => $criterionId, 'judge_id' => $judge, 'nominee_id' => $nominee,
            // NOT NULL with no default in both schemas. Foreign keys are off in unit tests
            // (see TestCase), so the id needs to exist in the column, not in a table.
            'category_id'  => 1,
            'score' => 8, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AUTHORING
    // ════════════════════════════════════════════════════════════════════════

    public function test_a_criterion_can_be_added_and_gets_a_reference_from_its_name(): void
    {
        $id = $this->add(null, 'Documented impact', 30);

        $row = JudgeRubric::find($id);
        $this->assertSame('documented-impact', (string) $row->slug);
        $this->assertSame(30, (int) $row->weight);
        $this->assertSame(1, (int) $row->is_active);
    }

    public function test_a_criterion_without_a_name_is_refused(): void
    {
        $r = JudgeRubric::save(null, 0, ['label' => '   ', 'weight' => '25']);
        $this->assertFalse($r['ok']);
        $this->assertSame('label', $r['field']);
    }

    /** The weight is bounded, and the message says what the number means. */
    public function test_a_weight_outside_the_range_is_refused_with_the_reason(): void
    {
        foreach (['0', '-5', '9999', 'abc'] as $bad) {
            $r = JudgeRubric::save(null, 0, ['label' => 'X', 'weight' => $bad]);
            $this->assertFalse($r['ok'], "weight '{$bad}' was accepted");
            $this->assertSame('weight', $r['field']);
            $this->assertStringContainsString('not a percentage', $r['message'],
                'an operator who thinks 10 means 10% will be wrong about what they published');
        }
    }

    /**
     * Two criteria with one reference means the scorer keeps one and drops the other.
     *
     * NomineeScoringService::criteriaWeights() resolves by slug into an array keyed by slug,
     * so a duplicate silently loses a criterion's entire weight.
     */
    public function test_two_criteria_cannot_share_a_reference_in_one_rubric(): void
    {
        $this->add(null, 'Impact');
        $r = JudgeRubric::save(null, 0, ['label' => 'Impact', 'weight' => '25']);

        $this->assertFalse($r['ok']);
        $this->assertSame('slug', $r['field']);
    }

    /** But a programme may have its own with the same reference — that is the override. */
    public function test_a_programme_may_override_a_default_by_reusing_its_reference(): void
    {
        $this->add(null, 'Impact', 25);
        $own = JudgeRubric::save($this->prog, 0, ['label' => 'Impact', 'weight' => '60']);

        $this->assertTrue($own['ok'], (string) ($own['message'] ?? ''));

        $effective = JudgeRubric::effective($this->prog);
        $this->assertCount(1, $effective, 'the default and the override were both asked');
        $this->assertSame(60, (int) $effective[0]->weight);
        $this->assertNotNull($effective[0]->programme_id);
    }

    public function test_the_ballot_length_is_capped(): void
    {
        for ($i = 0; $i < JudgeRubric::MAX_PER_SCOPE; $i++) $this->add(null, 'Criterion ' . $i);

        $r = JudgeRubric::save(null, 0, ['label' => 'One too many', 'weight' => '25']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Retire one first', $r['message']);
    }

    /** One rubric's id is not authorisation to edit another's. */
    public function test_a_criterion_in_another_rubric_cannot_be_edited(): void
    {
        $global = $this->add(null, 'Impact');

        $r = JudgeRubric::save($this->prog, $global, ['label' => 'Hijacked', 'weight' => '99']);
        $this->assertFalse($r['ok']);
        $this->assertSame('Impact', (string) JudgeRubric::find($global)->label);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND THE TWO RULES THAT PROTECT PUBLISHED RESULTS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * A criterion that has been scored is retired, never deleted.
     *
     * `gates_judge_criteria_scores` points at it by id. Deleting the row orphans every
     * ballot that used it — and silently CHANGES published results, because the scorer
     * counts only criteria it can still resolve.
     */
    public function test_a_scored_criterion_is_retired_and_its_ballots_survive(): void
    {
        $id = $this->add(null, 'Impact');
        $this->score($id);
        $this->score($id, 2);

        $r = JudgeRubric::retire(null, $id);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['deleted'], 'a scored criterion was hard-deleted');
        $this->assertNotNull(JudgeRubric::find($id));
        $this->assertSame(0, (int) JudgeRubric::find($id)->is_active);
        $this->assertSame(2, (int) DB::table('gates_judge_criteria_scores')
            ->where('criterion_id', $id)->count(), 'ballots were destroyed');
    }

    /** One nobody scored is simply removed — there is nothing to protect. */
    public function test_an_unscored_criterion_is_deleted_outright(): void
    {
        $id = $this->add(null, 'Never used');

        $r = JudgeRubric::retire(null, $id);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['deleted']);
        $this->assertNull(JudgeRubric::find($id));
    }

    /**
     * The reference of a scored criterion cannot change.
     *
     * The per-programme override resolves BY SLUG, so a rename silently re-points a
     * programme's override at nothing — and the programme quietly reverts to the default
     * weight with no error anywhere.
     */
    public function test_a_scored_criterion_cannot_be_renamed(): void
    {
        $id = $this->add(null, 'Impact');
        $this->score($id);

        $r = JudgeRubric::save(null, $id, ['label' => 'Impact', 'weight' => '25', 'slug' => 'something-else']);

        $this->assertFalse($r['ok']);
        $this->assertSame('slug', $r['field']);
        $this->assertSame('impact', (string) JudgeRubric::find($id)->slug);
    }

    /** Before it is scored, renaming is harmless and allowed. */
    public function test_an_unscored_criterion_can_still_be_renamed(): void
    {
        $id = $this->add(null, 'Impact');

        $r = JudgeRubric::save(null, $id, ['label' => 'Impact', 'weight' => '25', 'slug' => 'documented-impact']);
        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $this->assertSame('documented-impact', (string) JudgeRubric::find($id)->slug);
    }

    /** A retired criterion can come back. */
    public function test_a_retired_criterion_can_be_restored(): void
    {
        $id = $this->add(null, 'Impact');
        $this->score($id);
        JudgeRubric::retire(null, $id);

        $this->assertTrue(JudgeRubric::restore(null, $id)['ok']);
        $this->assertSame(1, (int) JudgeRubric::find($id)->is_active);
    }

    // ════════════════════════════════════════════════════════════════════════
    // WHAT THE SCREEN TELLS AN OPERATOR
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Weights are relative and the share is computed, because an operator would otherwise
     * work it out wrongly in their head.
     */
    public function test_the_share_of_the_score_is_computed_from_relative_weights(): void
    {
        $a = $this->add(null, 'Impact', 3);
        $b = $this->add(null, 'Reach', 1);

        $shares = JudgeRubric::shares(null);
        $this->assertSame(75.0, $shares[$a]);
        $this->assertSame(25.0, $shares[$b]);
    }

    /** A retired criterion is worth nothing and is not counted in anybody's share. */
    public function test_a_retired_criterion_takes_no_share(): void
    {
        $a = $this->add(null, 'Impact', 1);
        $b = $this->add(null, 'Reach', 1);
        $this->score($b);
        JudgeRubric::retire(null, $b);

        $shares = JudgeRubric::shares(null);
        $this->assertSame([$a => 100.0], $shares);
    }

    /**
     * Changing a rubric that has been scored is allowed — and said out loud.
     *
     * Not a lock: an operator may have a real reason, and a platform that refuses is one
     * they work around in the database. But the scores already recorded were given against
     * the OLD weights, so the published result moves under them.
     */
    public function test_an_operator_is_told_when_a_change_will_move_a_published_result(): void
    {
        $id = $this->add(null, 'Impact');

        $clean = JudgeRubric::exposure(null);
        $this->assertFalse($clean['scored']);
        $this->assertStringContainsString('free to change', $clean['note']);

        $this->score($id);
        $used = JudgeRubric::exposure(null);
        $this->assertTrue($used['scored']);
        $this->assertSame(1, $used['ballots']);
        $this->assertStringContainsString('already been reached', $used['note']);
    }

    /**
     * A criterion whose ballot count cannot be read is treated as USED.
     *
     * Guessing "nobody scored it" would license a delete that orphans ballots. The cost of
     * being wrong the other way is one criterion retired instead of removed.
     */
    public function test_an_unreadable_score_count_is_treated_as_used(): void
    {
        $id = $this->add(null, 'Impact');

        // Renamed rather than dropped, and put back in a finally. The in-memory database is
        // built once for the process, so a DROP here takes the table away from every test
        // that runs after this one — which is how this cost six cascading errors the first
        // time it was written.
        DB::statement('ALTER TABLE gates_judge_criteria_scores RENAME TO _scores_hidden');
        try {
            $this->assertGreaterThan(0, JudgeRubric::scoreCount($id));
            $r = JudgeRubric::retire(null, $id);
            $this->assertFalse($r['deleted'], 'a criterion was deleted on an unreadable count');
        } finally {
            DB::statement('ALTER TABLE _scores_hidden RENAME TO gates_judge_criteria_scores');
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND THE SANDBOX JUDGE IS NOT A REAL ONE
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The demo judge must never appear on the public panel.
     *
     * DemoSeeder creates "DEMO — Test Judge" with `is_active = 1`, and it has to: the
     * sandbox exists so an operator can walk the judge portal without appointing a real
     * person, and the portal reads that flag. But `is_active` was also the ONLY thing the
     * public "Meet the Judges" page filtered on — so building a sandbox published a
     * fictional judge onto the page whose entire purpose is to say who is really deciding
     * these awards.
     */
    public function test_the_sandbox_judge_never_reaches_the_public_panel(): void
    {
        $demo = (int) DB::table('gates_judges')->insertGetId([
            'name' => DemoSeeder::PREFIX . 'Test Judge',
            'email' => 'judge@' . DemoSeeder::MAIL_DOMAIN,
            'title' => 'Rehearsal panellist', 'is_active' => 1,
        ]);
        $real = (int) DB::table('gates_judges')->insertGetId([
            'name' => 'Prof. Amina Yusuf', 'email' => 'amina@example.org',
            'title' => 'Chair', 'is_active' => 1,
        ]);

        $svc   = new JudgeService();
        $names = array_map(static fn (array $j): string => (string) $j['name'], $svc->publicRoster());

        $this->assertContains('Prof. Amina Yusuf', $names);
        $this->assertNotContains(DemoSeeder::PREFIX . 'Test Judge', $names,
            'the sandbox published a fictional judge onto the panel page');

        // And not by its own URL either — hiding it from the list while leaving the profile
        // reachable would be the same claim with one more click in front of it.
        $this->assertNull($svc->publicJudge($demo));
        $this->assertNotNull($svc->publicJudge($real));
    }

    /**
     * The filter matches the sandbox's DOMAIN, not the word "demo" anywhere in an address.
     *
     * A real panellist called Demola, or one at a university that runs a `demo.` subdomain,
     * is a person who agreed to sit on this panel and whose name belongs on the page. Hiding
     * them because of a substring would be the same class of bug as publishing the fake one,
     * pointed the other way — and it would be silent, because nobody checks a page for a
     * name that ISN'T there.
     *
     * `email` is NOT NULL in both schemas, so the empty string — not null — is the
     * no-address-on-file case that can actually reach the query.
     */
    public function test_a_real_judge_is_not_mistaken_for_the_sandbox(): void
    {
        DB::table('gates_judges')->insert([
            ['name' => 'Dr. Kofi Mensah',  'email' => 'demola@demo.university.edu.ng', 'is_active' => 1],
            ['name' => 'Prof. Ada Nwosu',  'email' => '',                              'is_active' => 1],
        ]);

        $names = array_map(static fn (array $j): string => (string) $j['name'],
                           (new JudgeService())->publicRoster());
        $this->assertContains('Dr. Kofi Mensah', $names);
        $this->assertContains('Prof. Ada Nwosu', $names);
    }
}
