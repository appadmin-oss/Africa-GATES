<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\FraudService;

/**
 * The fraud engine is the gate that can REJECT a vote before it is cast
 * (decision 'block' at score >= 80). These pin the decision-band boundaries,
 * signal additivity, the 100 cap, and the fraud_flag stamping — none of which
 * had coverage.
 */
class FraudServiceTest extends TestCase
{
    private function seedVote(string $email, string $device, string $ip, int $cat = 10): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => $cat, 'voter_email_hash' => $email,
            'device_hash' => $device, 'ip_hash' => $ip, 'voted_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function seedOtp(string $emailHash): void
    {
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => $emailHash, 'token_hash' => 'x', 'purpose' => 'vote',
            'nominee_id' => 1, 'award_id' => 0, 'attempts' => 0, 'is_used' => 0,
            'expires_at' => Carbon::now()->addMinutes(10)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** decide() is the gate; pin every band boundary exactly (off-by-one guard). */
    public function test_decision_band_boundaries(): void
    {
        $svc = new FraudService();
        $decide = new \ReflectionMethod($svc, 'decide');

        $cases = [0 => 'allow', 29 => 'allow', 30 => 'monitor', 59 => 'monitor',
                  60 => 'flag', 79 => 'flag', 80 => 'block', 100 => 'block'];
        foreach ($cases as $score => $expected) {
            $this->assertSame($expected, $decide->invoke($svc, $score), "score $score");
        }
    }

    public function test_clean_attempt_scores_zero_and_allows(): void
    {
        $r = (new FraudService())->scoreVoteAttempt('E', 'I', 'D', 1, 10);
        $this->assertSame(0, $r['score']);
        $this->assertSame('allow', $r['decision']);
    }

    public function test_signals_are_additive_and_block(): void
    {
        // 3 prior votes from ONE device in this category within the hour → 50 + 25,
        // plus 3 vote-OTP requests in 10 min → 20. Total 95 → block.
        foreach (['e1', 'e2', 'e3'] as $e) $this->seedVote($e, 'D', 'I', 10);
        for ($i = 0; $i < 3; $i++) $this->seedOtp('E');

        $r = (new FraudService())->scoreVoteAttempt('E', 'I', 'D', 1, 10);

        $this->assertSame(95, $r['score']);
        $this->assertSame('block', $r['decision']);
        $this->assertNotEmpty($r['signals']);
    }

    public function test_score_is_capped_at_100(): void
    {
        // 25 votes share device 'D' + IP 'I' in this category: 50 + 25 + (15+30) = 120 → capped.
        for ($i = 0; $i < 25; $i++) $this->seedVote('c' . $i, 'D', 'I', 10);

        $r = (new FraudService())->scoreVoteAttempt('E', 'I', 'D', 1, 10);
        $this->assertSame(100, $r['score']);
        $this->assertSame('block', $r['decision']);
    }

    public function test_missing_device_does_not_evade_detection_for_single_ip_ring(): void
    {
        // Dropping the device hash must NOT erase detection. 5 prior device-less
        // votes from one IP in this category → IP-fallback concentration signals
        // fire (50 + 25) on top of missing_device (25) → block.
        for ($i = 0; $i < 5; $i++) $this->seedVote('e' . $i, '', 'I', 10);

        $r = (new FraudService())->scoreVoteAttempt('E', 'I', null, 1, 10);

        $this->assertSame('block', $r['decision'], 'a device-less single-IP ring must still be blockable');
    }

    public function test_single_device_less_vote_is_not_blocked(): void
    {
        // CGNAT guard: a lone vote from a privacy/device-less client (no IP
        // concentration) must never be blocked — legitimate mobile voters behind
        // a shared carrier IP have to get through (missing_device alone = 25).
        $r = (new FraudService())->scoreVoteAttempt('E', 'I', null, 1, 10);

        $this->assertNotSame('block', $r['decision']);
    }

    public function test_record_stamps_fraud_flag_at_60_boundary(): void
    {
        DB::table('gates_votes')->insert(['id' => 1, 'nominee_id' => 1, 'category_id' => 10, 'voter_email_hash' => 'z', 'voted_at' => Carbon::now()->toDateTimeString()]);
        $svc = new FraudService();

        $svc->record(1, 'z', 'I', 'D', 59, 'monitor', []);
        $this->assertSame(0, (int) DB::table('gates_votes')->where('id', 1)->value('fraud_flag'));

        $svc->record(1, 'z', 'I', 'D', 60, 'flag', ['sig']);
        $this->assertSame(1, (int) DB::table('gates_votes')->where('id', 1)->value('fraud_flag'));
        $this->assertSame(2, DB::table('gates_fraud_scores')->count()); // both persisted
    }
}
