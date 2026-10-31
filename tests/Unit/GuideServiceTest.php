<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GuideService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Gee v2: provider-agnostic answer chain with a guaranteed scripted floor,
 * live site-state knowledge, and the Make.com agent bridge configuration
 * (settings-first, env fallback). No network is touched — with nothing
 * configured every path must land on the scripted tier.
 */
final class GuideServiceTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ANTHROPIC_API_KEY', 'GROQ_API_KEY', 'GEMINI_API_KEY', 'OPENAI_API_KEY', 'GEE_MAKE_AGENT_URL', 'GEE_MAKE_AGENT_KEY'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) unset($_ENV[$k]); else $_ENV[$k] = $v;
        }
        parent::tearDown();
    }

    public function test_unconfigured_answer_falls_to_scripted_and_never_dies(): void
    {
        $g = new GuideService();
        $this->assertFalse($g->isAiEnabled());
        $out = $g->answer('How does voting work?', [], []);
        $this->assertSame('scripted', $out['source']);
        $this->assertStringContainsString('/vote', $out['reply']);
    }

    public function test_scripted_tier_is_directly_addressable_for_budget_degrade(): void
    {
        $out = (new GuideService())->scripted('how do I nominate someone');
        $this->assertSame('scripted', $out['source']);
        $this->assertStringContainsString('/nominate', $out['reply']);
    }

    public function test_site_state_reflects_live_platform_data(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'creative-arts', 'title' => 'Creative Arts', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting', 'voting_close' => date('Y-m-d', time() + 5 * 86400)]);
        DB::table('gates_nominees')->insert(['name' => 'Ada Obi', 'category_id' => 1, 'status' => 'approved']);
        DB::table('gates_threads')->insert(['slug' => 't', 'title' => 'T', 'body' => 'b', 'author_name' => 'M', 'author_email_hash' => 'x', 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);

        $s = (new GuideService())->siteState();
        $this->assertSame('Creative Arts', $s['programmes'][0]['title']);
        $this->assertSame('voting', $s['programmes'][0]['cycle_status']);
        $this->assertSame(1, $s['counts']['approved_nominees']);
        $this->assertSame(1, $s['counts']['community_threads']);
        $this->assertArrayHasKey('review_sla_hours', $s);
    }

    public function test_make_bridge_config_resolves_settings_first_then_env(): void
    {
        $g = new GuideService();
        $this->assertFalse($g->makeConfigured());
        $this->assertSame('', $g->agentKey());

        $_ENV['GEE_MAKE_AGENT_URL'] = 'https://hook.eu1.make.com/abc123';
        $_ENV['GEE_MAKE_AGENT_KEY'] = 'env-secret-key-123456';
        $this->assertTrue($g->makeConfigured());
        $this->assertSame('env-secret-key-123456', $g->agentKey());

        // Settings override env.
        DB::table('gates_settings')->insert([
            ['key_name' => 'gee_make_agent_url', 'value' => 'https://hook.eu1.make.com/from-settings'],
            ['key_name' => 'gee_make_agent_key', 'value' => 'settings-secret-key-654321'],
        ]);
        $this->assertSame('settings-secret-key-654321', $g->agentKey());
    }

    public function test_non_https_agent_url_is_not_considered_configured(): void
    {
        $_ENV['GEE_MAKE_AGENT_URL'] = 'http://insecure.example.com/hook';
        $this->assertFalse((new GuideService())->makeConfigured());
    }
}
