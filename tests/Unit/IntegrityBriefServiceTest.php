<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\IntegrityBriefService;
use AfricaGates\Services\AiService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The AI integrity briefing: deterministic signal gathering (open collusion,
 * judge anomalies, fraud-flagged votes) and a templated narrative that always
 * works without AI.
 */
class IntegrityBriefServiceTest extends TestCase
{
    public function test_signals_count_open_collusion_and_fraud(): void
    {
        DB::table('gates_collusion_findings')->insert([
            ['kind' => 'shared_device', 'nominee_id' => 1, 'shared_key' => 'd1', 'distinct_voters' => 4, 'risk_score' => 80, 'status' => 'open'],
            ['kind' => 'shared_ip', 'nominee_id' => 2, 'shared_key' => 'ip1', 'distinct_voters' => 7, 'risk_score' => 40, 'status' => 'open'],
            ['kind' => 'shared_device', 'nominee_id' => 3, 'shared_key' => 'd2', 'distinct_voters' => 3, 'risk_score' => 55, 'status' => 'reviewed'], // not open
        ]);
        DB::table('gates_votes')->insert([
            ['nominee_id' => 1, 'category_id' => 10, 'voter_email_hash' => 'h1', 'vote_type' => 'standard', 'weight' => 1, 'fraud_flag' => 1, 'voted_at' => date('Y-m-d H:i:s')],
            ['nominee_id' => 1, 'category_id' => 10, 'voter_email_hash' => 'h2', 'vote_type' => 'standard', 'weight' => 1, 'fraud_flag' => 0, 'voted_at' => date('Y-m-d H:i:s')],
        ]);

        $s = IntegrityBriefService::signals();
        $this->assertSame(2, $s['collusion']['open'], 'only open findings counted');
        $this->assertSame(80, $s['collusion']['top_risk']);
        $this->assertSame(1, $s['collusion']['by_kind']['shared_ip']);
        $this->assertSame(1, $s['fraud_votes_24h']);
        $this->assertSame($s['collusion']['open'] + $s['judges']['flags'] + $s['fraud_votes_24h'], $s['total']);
    }

    public function test_templated_narrative_when_no_ai(): void
    {
        // Force the deterministic path with an unconfigured AiService.
        DB::table('gates_collusion_findings')->insert([
            ['kind' => 'timing_burst', 'nominee_id' => 5, 'shared_key' => 't1', 'distinct_voters' => 9, 'risk_score' => 72, 'status' => 'open'],
        ]);
        $s = IntegrityBriefService::signals();
        $n = IntegrityBriefService::narrative($s, new AiService());
        $this->assertFalse($n['ai']);
        $this->assertStringContainsStringIgnoringCase('collusion', $n['text']);
    }

    public function test_all_clear_narrative(): void
    {
        $s = IntegrityBriefService::signals();
        $this->assertSame(0, $s['total']);
        $n = IntegrityBriefService::narrative($s, new AiService());
        $this->assertFalse($n['ai']);
        $this->assertStringContainsStringIgnoringCase('clear', $n['text']);
    }
}
