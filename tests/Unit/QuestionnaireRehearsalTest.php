<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnaireInterview as I;
use AfricaGates\Services\QuestionnaireLedger as L;
use AfricaGates\Services\QuestionnaireRehearsal as R;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\QuestionnaireStyle as S;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Rehearsing an interview.
 *
 * ── WHAT THESE TESTS ARE PROTECTING ──────────────────────────────────────────
 *
 * A rehearsal must be the REAL thing. The moment it becomes a simulation, it starts passing
 * while the interview it stands for is broken — and the first person to find out is a nominee
 * on a deadline. So: it is a real test submission, it runs the real engine, and it can never
 * reach a judge.
 *
 * The regression suite has one property worth more than the rest: reaching FEWER outcomes than
 * last time is reported as a loss. An administrator changing a brief to fix one complaint has
 * no other way to learn what that fix cost.
 */
final class QuestionnaireRehearsalTest extends TestCase
{
    private const PROG = 99;

    protected function setUp(): void
    {
        parent::setUp();
        S::forget();
        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'P', 'slug' => 'p-9900']);
        DB::table('gates_judge_criteria')->insertOrIgnore([
            'id' => 9910, 'programme_id' => null, 'slug' => 'impact', 'label' => 'Impact',
            'description' => 'x', 'weight' => 100, 'sort_order' => 1, 'is_active' => 1]);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_openai_key'],
                                                    ['value' => 'sk-test-not-a-real-key']);
        S::saveConfig(self::PROG, ['style' => S::INTERVIEW, 'brief' => 'Ask about the work.']);
        S::saveOutcome(self::PROG, null, ['slug' => 'scale', 'label' => 'How far it reaches',
            'criterion_id' => 9910, 'required' => true]);
        S::forget();
    }

    public function test_a_rehearsal_is_a_real_test_submission_running_the_real_interview(): void
    {
        $r = R::open(self::PROG, 1);
        $this->assertTrue($r['ok']);

        $s = Q::byToken((string) $r['token']);
        $this->assertSame(1, (int) $s->is_test, 'a rehearsal that is not a test can reach a judge');
        $this->assertSame('interview', (string) $s->style);
        // The greeting is already there, from the same open() a nominee gets.
        $this->assertNotEmpty(I::state((string) $r['token'])['turns']);
    }

    public function test_a_rehearsal_can_be_stamped_as_an_interview_on_a_form_programme(): void
    {
        // Rehearsing before switching a programme over is the main reason to open this screen.
        S::saveConfig(self::PROG, ['style' => S::FORM]);
        S::forget();

        $r = R::open(self::PROG, 1);
        $this->assertSame('interview', (string) Q::byToken((string) $r['token'])->style);
    }

    public function test_reopening_the_pane_resumes_rather_than_starting_a_new_one(): void
    {
        $a = R::open(self::PROG, 1);
        $b = R::open(self::PROG, 1);
        $this->assertSame($a['token'], $b['token'],
            'a refresh mid-rehearsal threw away the conversation');
        $this->assertSame(1, DB::table('gates_nominee_submissions')
            ->where('is_test', 1)->where('programme_id', self::PROG)->count());
    }

    public function test_starting_again_removes_the_old_rehearsal(): void
    {
        $a = R::open(self::PROG, 1);
        $b = R::reset(self::PROG, 1);
        $this->assertNotSame($a['token'], $b['token']);
        $this->assertSame(1, DB::table('gates_nominee_submissions')
            ->where('is_test', 1)->where('programme_id', self::PROG)->count());
    }

    // ══ cases ════════════════════════════════════════════════════════════════

    private function rehearse(): string
    {
        $r = R::open(self::PROG, 1);
        $token = (string) $r['token'];
        $s = Q::byToken($token);

        $env = json_decode((string) $s->transcript_json, true);
        $env['turns'][] = ['role' => 'nominee', 'text' => 'We reach 4,000 farmers across eight states.'];
        DB::table('gates_nominee_submissions')->where('id', (int) $s->id)
            ->update(['transcript_json' => (string) json_encode($env)]);

        $s = Q::byToken($token);
        L::record($s, 'scale', 'met', 'Eight states', 'We reach 4,000 farmers across eight states',
            [['i' => 1, 'role' => 'nominee', 'text' => 'We reach 4,000 farmers across eight states.']]);
        return $token;
    }

    public function test_a_case_keeps_only_the_nominee_half(): void
    {
        $token = $this->rehearse();
        $this->assertTrue(R::saveCase(self::PROG, $token, 'Eight states', 'short', 1)['ok']);

        $c = DB::table('gates_questionnaire_cases')->where('programme_id', self::PROG)->first();
        $said = json_decode((string) $c->transcript_json, true);
        // The interviewer's turns are what CHANGES when the brief changes. Storing them would
        // make a case that compares a new run against an old model's wording.
        $this->assertSame(['We reach 4,000 farmers across eight states.'], $said);
        // And the bar is what this run actually reached, not somebody's aspiration — a suite
        // of aspirations fails on the day it is written and is then ignored.
        $this->assertSame(['scale'], json_decode((string) $c->expect_json, true));
    }

    public function test_an_empty_rehearsal_cannot_be_saved_as_a_case(): void
    {
        $r = R::open(self::PROG, 1);
        $out = R::saveCase(self::PROG, (string) $r['token'], 'Nothing', '', 1);
        $this->assertFalse($out['ok']);
    }

    public function test_the_personas_are_the_failure_modes_not_a_cooperative_nominee(): void
    {
        $p = R::personas();
        $this->assertArrayHasKey('short', $p);
        $this->assertArrayHasKey('nonumbers', $p);
        // The one that tests the refusal the whole feature rests on.
        $this->assertArrayHasKey('writeit', $p);
        $this->assertNotEmpty($p['writeit']['lines']);
        $this->assertStringContainsString('write it', strtolower($p['writeit']['lines'][0]));
    }

    // ══ replaying a case ═════════════════════════════════════════════════════

    public function test_a_replay_that_cannot_run_says_so_instead_of_reporting_a_regression(): void
    {
        // With no key every turn was refused, nothing was recorded, and the verdict came out
        // as "LOST: …" with ok=true. That is the worst possible wrong answer: an operator
        // reads it as "the brief change you just made broke the interview" and goes to fix a
        // brief that is fine.
        $token = $this->rehearse();
        R::saveCase(self::PROG, $token, 'Eight states', '', 1);
        $id = (int) DB::table('gates_questionnaire_cases')->value('id');

        DB::table('gates_settings')->where('key_name', 'ai_openai_key')->update(['value' => '']);
        $r = R::runCase($id, 1);

        $this->assertFalse($r['ok']);
        $this->assertStringNotContainsString('LOST', $r['message']);
        $this->assertStringContainsString('cannot run', $r['message']);
        $this->assertStringContainsString('OpenAI key', $r['message']);
    }

    public function test_a_replay_leaves_no_test_submission_behind(): void
    {
        $token = $this->rehearse();
        R::saveCase(self::PROG, $token, 'Eight states', '', 1);
        $id = (int) DB::table('gates_questionnaire_cases')->value('id');

        // The breaker, so the replay fails WITHOUT a network call. The key is still configured
        // — so interviewPossible() passes and a replay row really is created — but every hop
        // is skipped, which is the failure this needs to happen after the row exists and
        // before the verdict. Reaching for a real 401 instead would make the suite depend on
        // outbound HTTPS to answer a question about a DELETE.
        \AfricaGates\Support\ProviderBreaker::open('openai');
        try {
            $before = DB::table('gates_nominee_submissions')->where('is_test', 1)->count();
            $r = R::runCase($id, 1);

            $this->assertSame($before, DB::table('gates_nominee_submissions')
                ->where('is_test', 1)->count(),
                'a replay row was left behind — one per failed run fills the table');
            // And a run that stopped early is reported as not having finished, never as a diff.
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('Did not finish', $r['message']);
            $this->assertStringNotContainsString('LOST', $r['message']);
        } finally {
            \AfricaGates\Support\ProviderBreaker::clearAll();
        }
    }

    public function test_a_case_can_be_removed(): void
    {
        $token = $this->rehearse();
        R::saveCase(self::PROG, $token, 'Eight states', '', 1);
        $id = (int) DB::table('gates_questionnaire_cases')->value('id');

        $this->assertTrue(R::dropCase($id));
        $this->assertSame(0, DB::table('gates_questionnaire_cases')->count());
    }

    public function test_cases_are_scoped_to_their_programme(): void
    {
        $token = $this->rehearse();
        R::saveCase(self::PROG, $token, 'Ours', '', 1);
        $this->assertCount(1, R::cases(self::PROG));
        $this->assertCount(0, R::cases(4242));
    }
}
