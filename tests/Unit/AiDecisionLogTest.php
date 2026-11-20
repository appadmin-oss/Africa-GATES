<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\AiDecisionLog;

/**
 * Whether the AI was any use, as opposed to merely whether it ran.
 *
 * gates_ai_calls records that a call happened; this records the human verdict
 * beside the suggestion. Without it there is no accountability trail for a
 * decision made with a machine score in front of the reviewer, and no way to
 * answer the only question that justifies an advisory AI at all.
 */
class AiDecisionLogTest extends TestCase
{
    public function test_agreement_is_recorded_when_the_human_matches_the_suggestion(): void
    {
        AiDecisionLog::record('nomination.triage', 'nomination', 1, 'approved', 'approved', 7);

        $row = DB::table('gates_ai_decisions')->first();
        $this->assertSame('approved', (string) $row->suggested);
        $this->assertSame('approved', (string) $row->decided);
        $this->assertSame(1, (int) $row->agreed);
        $this->assertSame(7, (int) $row->actor_id, 'who decided is part of the trail');
    }

    public function test_disagreement_is_recorded_as_such(): void
    {
        AiDecisionLog::record('nomination.triage', 'nomination', 2, 'rejected', 'approved', 7);

        $this->assertSame(0, (int) DB::table('gates_ai_decisions')->value('agreed'),
            'a reviewer who overrode the AI must be on the record as having done so');
    }

    public function test_a_decision_with_no_suggestion_is_excluded_from_the_rate(): void
    {
        // Counting "no suggestion" as disagreement would make a capability look
        // worse the LESS often it managed to run.
        AiDecisionLog::record('nomination.triage', 'nomination', 3, null, 'approved', 7);

        $this->assertNull(DB::table('gates_ai_decisions')->value('agreed'));
        $this->assertSame([], AiDecisionLog::agreement(30), 'nothing comparable, so nothing reported');
    }

    public function test_the_agreement_rate_is_computed_over_comparable_decisions_only(): void
    {
        foreach ([['approved', 'approved'], ['approved', 'approved'], ['rejected', 'approved']] as $i => [$s, $d]) {
            AiDecisionLog::record('nomination.triage', 'nomination', 100 + $i, $s, $d, 7);
        }
        // Plus one with no suggestion, which must not move the denominator.
        AiDecisionLog::record('nomination.triage', 'nomination', 200, null, 'approved', 7);

        $r = AiDecisionLog::agreement(30);

        $this->assertCount(1, $r);
        $this->assertSame(3, $r[0]['decisions'], 'the un-suggested row is excluded');
        $this->assertSame(2, $r[0]['agreed']);
        $this->assertEqualsWithDelta(66.7, $r[0]['rate'], 0.05);
    }

    public function test_an_empty_capability_reports_null_rather_than_zero_percent(): void
    {
        // "0% agreement" and "no data" are very different signals to an operator.
        $this->assertSame([], AiDecisionLog::agreement(30));
    }

    public function test_old_decisions_fall_outside_the_window(): void
    {
        DB::table('gates_ai_decisions')->insert([
            'capability' => 'nomination.triage', 'subject_type' => 'nomination', 'subject_id' => 9,
            'suggested' => 'approved', 'decided' => 'approved', 'agreed' => 1,
            'created_at' => date('Y-m-d H:i:s', strtotime('-90 days')),
        ]);

        $this->assertSame([], AiDecisionLog::agreement(30), 'a stale rate is worse than none');
        $this->assertCount(1, AiDecisionLog::agreement(365));
    }

    public function test_the_trail_for_one_subject_is_retrievable(): void
    {
        AiDecisionLog::record('nomination.triage', 'nomination', 42, 'rejected', 'approved', 7, 'looked credible to me');
        AiDecisionLog::record('nomination.triage', 'nomination', 99, 'approved', 'approved', 7);

        $trail = AiDecisionLog::forSubject('nomination', 42);

        $this->assertCount(1, $trail, 'scoped to the subject asked about');
        $this->assertSame('looked credible to me', (string) $trail[0]['note']);
    }

    public function test_recording_never_throws_even_without_the_table(): void
    {
        // A moderator's decision must never fail because a log write did.
        DB::statement('DROP TABLE IF EXISTS gates_ai_decisions');

        AiDecisionLog::record('nomination.triage', 'nomination', 1, 'approved', 'approved', 7);
        $this->assertSame([], AiDecisionLog::agreement(30));
        $this->assertSame([], AiDecisionLog::forSubject('nomination', 1));
    }

    public function test_long_values_are_truncated_not_rejected(): void
    {
        AiDecisionLog::record(
            'nomination.triage', 'nomination', 5,
            str_repeat('a', 400), str_repeat('b', 400), 7, str_repeat('c', 900)
        );

        $row = DB::table('gates_ai_decisions')->first();
        $this->assertSame(120, mb_strlen((string) $row->suggested));
        $this->assertSame(120, mb_strlen((string) $row->decided));
        $this->assertSame(300, mb_strlen((string) $row->note));
    }
}
