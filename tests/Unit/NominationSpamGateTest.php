<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\AwardService;
use AfricaGates\Services\SpamService;

/**
 * Guards the rule that a nomination is NEVER destroyed at the submit boundary.
 *
 * The old gate threw on a 'reject' verdict, so the entry was never written to
 * gates_nominations — and, uniquely among SpamService callers, logDecision()
 * was never called either, so no operator could discover it. The heuristics
 * scored the very specificity the platform asks for: every URL is +0.15 and any
 * 8+ digit run reads as a "contact lure" (+0.35), so an evidence-rich
 * nomination citing sources and an impact figure cleared the 0.65 auto-reject
 * threshold, while content-free praise scored 0.00 and sailed through.
 *
 * The strings below are the exact cases from the audit.
 */
class NominationSpamGateTest extends TestCase
{
    /** The nomination text the old gate auto-rejected at score 0.90. */
    private const EVIDENCE_RICH = 'Built 3 schools in Nsukka, raising 250000000 naira for 4200 pupils. '
        . 'See https://example.org/report and https://news.example.com/story';

    /** The nomination text the old gate allowed at score 0.00. */
    private const CONTENT_FREE = 'She is amazing and deserves this award.';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => 1, 'slug' => 'excellence', 'title' => 'Excellence', 'is_active' => 1, 'sort_order' => 1,
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-7 days')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 10, 'cycle_id' => 1, 'slug' => 'music', 'title' => 'Music',
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(string $reason, array $over = []): array
    {
        return array_merge([
            'programme_id'    => 1,
            'category_id'     => 10,
            'nominee_name'    => 'Ada Obi',
            'nominee_email'   => 'ada@example.com',
            'country_code'    => 'NG',
            'reason'          => $reason,
            'nominator_name'  => 'Chidi Okeke',
            'nominator_email' => 'chidi@example.com',
        ], $over);
    }

    public function test_the_heuristics_still_score_evidence_rich_text_as_spam(): void
    {
        // Characterisation: this is WHY the throw was so damaging. If someone
        // recalibrates the heuristics later this test documents the baseline.
        $verdict = (new SpamService(null))->evaluate(self::EVIDENCE_RICH, ['target' => 'nomination']);

        $this->assertSame('reject', $verdict['decision'], 'baseline: links + a large number still score as spam');
        $this->assertGreaterThanOrEqual(0.65, (float) $verdict['score']);

        $clean = (new SpamService(null))->evaluate(self::CONTENT_FREE, ['target' => 'nomination']);
        $this->assertSame('allow', $clean['decision'], 'baseline: content-free praise still scores clean');
    }

    public function test_an_evidence_rich_nomination_is_persisted_not_destroyed(): void
    {
        $id = (new AwardService(new SpamService(null)))->submitNomination($this->payload(self::EVIDENCE_RICH), '1.2.3.4');

        $this->assertGreaterThan(0, $id, 'the nomination must be written');
        $row = DB::table('gates_nominations')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', (string) $row->status, 'it must reach the human review queue');
        $this->assertStringContainsString('250000000', (string) $row->reason, 'the evidence must survive intact');
    }

    public function test_a_spam_verdict_is_always_logged_against_the_nomination(): void
    {
        $id = (new AwardService(new SpamService(null)))->submitNomination($this->payload(self::EVIDENCE_RICH), '1.2.3.4');

        $log = DB::table('gates_moderation_log')
            ->where('target_type', 'nomination')->where('target_id', $id)->first();

        $this->assertNotNull($log, 'an automated verdict with no audit row is unreviewable and unappealable');
        $this->assertSame('reject', (string) $log->decision, 'the verdict is recorded verbatim...');
        $this->assertSame('pending', (string) DB::table('gates_nominations')->where('id', $id)->value('status'),
            '...but it does not decide whether the nomination exists');
    }

    public function test_a_clean_nomination_is_also_logged(): void
    {
        $id = (new AwardService(new SpamService(null)))->submitNomination($this->payload(self::CONTENT_FREE), '1.2.3.4');

        $log = DB::table('gates_moderation_log')->where('target_id', $id)->where('target_type', 'nomination')->first();
        $this->assertNotNull($log, 'every verdict is logged, not just the adverse ones');
        $this->assertSame('allow', (string) $log->decision);
    }

    public function test_submission_still_works_with_no_spam_service_at_all(): void
    {
        $id = (new AwardService(null))->submitNomination($this->payload(self::EVIDENCE_RICH), '1.2.3.4');

        $this->assertGreaterThan(0, $id);
        $this->assertSame(0, DB::table('gates_moderation_log')->count(), 'nothing to log without a moderator');
    }

    public function test_nominations_are_refused_once_the_close_date_has_passed(): void
    {
        // The stored column still says 'nominations' — no scheduler has run.
        // The computed phase must close the window anyway.
        DB::table('gates_award_cycles')->where('id', 1)->update([
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not open/i');
        (new AwardService(null))->submitNomination($this->payload(self::CONTENT_FREE), '1.2.3.4');
    }

    public function test_a_cycle_tagged_with_another_year_still_accepts_nominations(): void
    {
        // The write path used `year = date('Y')` while every public page picked
        // the cycle by status priority, so an off-calendar-year cycle was
        // advertised as open and then rejected here.
        DB::table('gates_award_cycles')->where('id', 1)->update(['year' => (int) date('Y') + 1]);

        $id = (new AwardService(null))->submitNomination($this->payload(self::CONTENT_FREE), '1.2.3.4');

        $this->assertGreaterThan(0, $id, 'an in-flight cycle must be nominable whatever year it is tagged with');
        $this->assertSame(1, (int) DB::table('gates_nominations')->where('id', $id)->value('cycle_id'));
    }
}
