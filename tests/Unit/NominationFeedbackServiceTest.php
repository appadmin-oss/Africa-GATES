<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NominationFeedbackService as Feedback;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Nominator feedback loop: AI reason suggestion degrades to null without a
 * provider (moderator types their own), and the "no silence" acknowledgement
 * query only surfaces long-pending, not-yet-answered nominations, once each.
 */
final class NominationFeedbackServiceTest extends TestCase
{
    private function nom(array $over = []): int
    {
        return (int) DB::table('gates_nominations')->insertGetId(array_merge([
            'cycle_id' => 1, 'nominee_name' => 'Ada Obi', 'nominee_email' => 'ada@x.io',
            'reason' => 'Founded a coding school for girls.',
            'nominator_name' => 'A Person', 'nominator_email' => 'p@x.io',
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ], $over));
    }

    public function test_suggest_reason_null_without_ai(): void
    {
        foreach (['GROQ_API_KEY','GROQ_MODERATION_KEY','GEMINI_API_KEY','ANTHROPIC_API_KEY','OPENAI_API_KEY'] as $k) unset($_ENV[$k]);
        $id = $this->nom();
        $this->assertNull(Feedback::suggestReason(DB::table('gates_nominations')->find($id), 'rejected'),
            'no provider → null so the moderator types their own');
    }

    public function test_ack_query_surfaces_only_stale_unanswered_pending(): void
    {
        $stale   = $this->nom(['created_at' => date('Y-m-d H:i:s', time() - 200 * 3600)]);
        $fresh   = $this->nom(['created_at' => date('Y-m-d H:i:s', time() - 1 * 3600)]);
        $decided = $this->nom(['created_at' => date('Y-m-d H:i:s', time() - 200 * 3600), 'status' => 'approved']);
        $acked   = $this->nom(['created_at' => date('Y-m-d H:i:s', time() - 200 * 3600), 'nominator_ack_at' => date('Y-m-d H:i:s')]);

        $ids = array_map(fn($r) => (int) $r->id, Feedback::pendingNeedingAck(48));
        $this->assertContains($stale, $ids);
        $this->assertNotContains($fresh, $ids, 'within SLA — not yet nagged');
        $this->assertNotContains($decided, $ids, 'already decided — not pending');
        $this->assertNotContains($acked, $ids, 'already acknowledged once');
    }

    public function test_mark_acked_removes_from_the_queue(): void
    {
        $id = $this->nom(['created_at' => date('Y-m-d H:i:s', time() - 200 * 3600)]);
        $this->assertContains($id, array_map(fn($r) => (int) $r->id, Feedback::pendingNeedingAck(48)));
        Feedback::markAcked($id);
        $this->assertNotContains($id, array_map(fn($r) => (int) $r->id, Feedback::pendingNeedingAck(48)));
    }
}
