<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\AiService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * AiService is the pluggable AI gateway. With no provider key it must be inert
 * (moderate/complete return null) so the platform runs fully on heuristics.
 * Keys resolve from gates_settings (with .env fallback) and the first provider
 * configured wins in priority order: Groq → Gemini → Anthropic → OpenAI.
 */
class AiServiceTest extends TestCase
{
    /** Remove any provider keys leaking in from the host .env / a prior test. */
    private function clearEnv(): void
    {
        foreach (['GROQ_API_KEY', 'GEMINI_API_KEY', 'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GROQ_MODERATION_KEY', 'GROQ_MODERATION_MODEL'] as $k) {
            unset($_ENV[$k]);
        }
    }

    public function test_moderation_purpose_uses_dedicated_key_and_best_model(): void
    {
        $this->clearEnv();
        DB::table('gates_settings')->where('key_name', 'like', 'ai_%')->delete();
        DB::table('gates_settings')->insert([
            ['key_name' => 'ai_groq_key',     'value' => 'general-groq'],
            ['key_name' => 'ai_groq_model',   'value' => 'llama-3.1-8b-instant'],
            ['key_name' => 'ai_groq_key_mod', 'value' => 'moderation-groq'],
        ]);
        $gen = AiService::boot();
        $mod = AiService::boot('moderation');
        $this->assertSame('groq', $gen->activeProvider());
        $this->assertSame('groq', $mod->activeProvider());
        // Moderation runs the best model by default; both are configured.
        $this->assertSame(AiService::MODERATION_MODEL, $mod->status()['model'] ?? AiService::MODERATION_MODEL);
        $this->assertTrue($mod->configured());
    }

    public function test_moderation_free_backup_falls_back_to_general_groq_key(): void
    {
        $this->clearEnv();
        DB::table('gates_settings')->where('key_name', 'like', 'ai_%')->delete();
        // Only the GENERAL Groq key is set — no dedicated moderation key.
        DB::table('gates_settings')->insert([['key_name' => 'ai_groq_key', 'value' => 'general-groq']]);
        $mod = AiService::boot('moderation');
        $this->assertTrue($mod->configured(), 'moderation must free-fall back to the general Groq key');
        $this->assertSame('groq', $mod->activeProvider());
    }

    public function test_moderation_model_is_admin_overridable(): void
    {
        $this->clearEnv();
        DB::table('gates_settings')->where('key_name', 'like', 'ai_%')->delete();
        DB::table('gates_settings')->insert([
            ['key_name' => 'ai_groq_key_mod',   'value' => 'mod-groq'],
            ['key_name' => 'ai_groq_model_mod', 'value' => 'custom-mod-model'],
        ]);
        $this->assertSame('custom-mod-model', AiService::boot('moderation')->status()['model']);
    }

    public function test_inert_when_no_key(): void
    {
        $ai = new AiService();
        $this->assertFalse($ai->configured());
        $this->assertNull($ai->activeProvider());
        $this->assertNull($ai->moderate('some perfectly ordinary text'));
        $this->assertNull($ai->complete('system', 'user'));

        $status = $ai->status();
        $this->assertFalse($status['groq']);
        $this->assertFalse($status['openai']);
        $this->assertNull($status['active']);
    }

    public function test_provider_priority(): void
    {
        $this->assertSame('groq',      (new AiService('g', 'm', 'a', 'o'))->activeProvider());
        $this->assertSame('gemini',    (new AiService(null, 'm', 'a', 'o'))->activeProvider());
        $this->assertSame('anthropic', (new AiService(null, null, 'a', 'o'))->activeProvider());
        $this->assertSame('openai',    (new AiService(null, null, null, 'o'))->activeProvider());
        $this->assertTrue((new AiService(null, null, null, 'o'))->configured());
    }

    public function test_boot_reads_keys_from_settings(): void
    {
        $this->clearEnv();
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_gemini_key'], ['value' => 'test-gemini-key']);

        $ai = AiService::boot();
        $this->assertTrue($ai->configured());
        $this->assertSame('gemini', $ai->activeProvider());   // resolved from settings, not env
        $this->assertTrue($ai->status()['gemini']);
        $this->assertFalse($ai->status()['groq']);
    }

    public function test_blank_setting_does_not_configure(): void
    {
        $this->clearEnv();
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_groq_key'], ['value' => '   ']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_openai_key'], ['value' => '']);

        $ai = AiService::boot();
        $this->assertFalse($ai->configured());                 // whitespace/empty = unset
    }

    public function test_env_fallback_when_no_setting(): void
    {
        $this->clearEnv();
        DB::table('gates_settings')->where('key_name', 'like', 'ai_%')->delete();
        $_ENV['ANTHROPIC_API_KEY'] = 'env-anthropic-key';

        $ai = AiService::boot();
        $this->assertTrue($ai->status()['anthropic']);

        unset($_ENV['ANTHROPIC_API_KEY']);                     // don't leak into later tests
    }

    public function test_general_features_fall_back_to_the_moderation_groq_key(): void
    {
        // Admin pasted ONLY the moderation Groq key. General features (Gee, filter,
        // triage, dedup) must still work — the symmetric fallback.
        $this->clearEnv();
        DB::table('gates_settings')->where('key_name', 'like', 'ai_%')->delete();
        DB::table('gates_settings')->insert([['key_name' => 'ai_groq_key_mod', 'value' => 'only-a-mod-key']]);

        $gen = AiService::boot();               // 'general' purpose
        $this->assertTrue($gen->configured(), 'a moderation-only Groq key must power general AI too');
        $this->assertSame('groq', $gen->activeProvider());
    }

    public function test_selftest_reports_missing_provider(): void
    {
        $r = (new AiService())->selfTest();
        $this->assertFalse($r['ok']);
        $this->assertNull($r['provider']);
        $this->assertStringContainsStringIgnoringCase('no provider', $r['error']);
    }

    public function test_provider_chain_is_priority_ordered(): void
    {
        $m = new \ReflectionMethod(AiService::class, 'providerChain');
        $m->setAccessible(true);
        $this->assertSame(['groq', 'gemini', 'anthropic', 'openai'], $m->invoke(new AiService('g', 'm', 'a', 'o')));
        $this->assertSame(['anthropic', 'openai'], $m->invoke(new AiService(null, null, 'a', 'o')));
        $this->assertSame([], $m->invoke(new AiService()));
    }

    public function test_every_provider_model_is_configurable(): void
    {
        // Constructor: anthropic/openai models are now injectable (positions 7/8).
        $ai = new AiService(null, null, 'ak', null, null, null, 'claude-best-model');
        $this->assertSame('anthropic', $ai->activeProvider());
        $this->assertSame('claude-best-model', $ai->activeModel());

        $ai2 = new AiService(null, null, null, 'ok', null, null, null, 'gpt-best-model');
        $this->assertSame('gpt-best-model', $ai2->activeModel());

        // …and resolved from settings by boot().
        $this->clearEnv();
        DB::table('gates_settings')->where('key_name', 'like', 'ai_%')->delete();
        DB::table('gates_settings')->insert([
            ['key_name' => 'ai_anthropic_key',   'value' => 'ak'],
            ['key_name' => 'ai_anthropic_model', 'value' => 'claude-from-settings'],
        ]);
        $this->assertSame('claude-from-settings', AiService::boot()->activeModel());
    }

    public function test_provider_defaults_when_model_unset(): void
    {
        $this->assertSame('claude-haiku-4-5-20251001', (new AiService(null, null, 'ak'))->activeModel());
        $this->assertSame('gpt-4o-mini', (new AiService(null, null, null, 'ok'))->activeModel());
    }

    public function test_json_fence_is_stripped(): void
    {
        $m = new \ReflectionMethod(AiService::class, 'stripJsonFence');
        $m->setAccessible(true);
        $this->assertSame('{"a":1}', $m->invoke(null, "```json\n{\"a\":1}\n```"));
        $this->assertSame('{"a":1}', $m->invoke(null, "```\n{\"a\":1}\n```"));
        $this->assertSame('{"a":1}', $m->invoke(null, '{"a":1}'));
    }

    // ══ the suite does not dial a provider ═══════════════════════════════════

    /** Reach the protected transport without going near a network. */
    private function transport(): object
    {
        return new class extends AiService {
            public function __construct() { parent::__construct(); }
            /** @param list<string> $headers */
            public function callHttp(string $url, array $headers): ?array
            {
                return $this->httpPost($url, $headers, ['x' => 1]);
            }
        };
    }

    /**
     * Three tests seed `sk-test-not-a-real-key` into gates_settings to render the
     * "AI is configured" state, and the suite dialled api.openai.com for real on every run
     * because of it — OpenAI's own 401 body was in the log, so the request left the
     * machine. That made the suite depend on outbound reachability, pay a handshake per
     * call, and send traffic to a third party on every CI build, to reach a failure path
     * that is reached identically without leaving the process.
     */
    public function test_a_placeholder_bearer_token_is_never_sent(): void
    {
        $t = $this->transport();

        $this->assertNull($t->callHttp('https://api.openai.com/v1/chat/completions',
            ['Authorization: Bearer sk-test-not-a-real-key']));
        $this->assertNull($t->callHttp('https://api.groq.com/openai/v1/chat/completions',
            ['Authorization: Bearer sk-test']), 'too short to be any provider\'s key');
        $this->assertNull($t->callHttp('https://api.anthropic.com/v1/messages',
            ['x-api-key: your_key_here']));
    }

    /**
     * Gemini takes its key as a `?key=` query parameter and calls the transport with NO
     * headers, so a header-only check would have left exactly one of the four providers
     * still dialling.
     */
    public function test_a_placeholder_in_the_url_is_never_sent_either(): void
    {
        $this->assertNull($this->transport()->callHttp(
            'https://generativelanguage.googleapis.com/v1beta/models/x:generateContent?key=changeme',
            []
        ));
    }

    /**
     * And the rule cannot catch a real credential — the failure that would matter far more
     * than dialling a fake one. Asserted on the predicate rather than by making a call,
     * because proving a real key IS sent would mean sending it.
     */
    public function test_a_real_looking_key_is_not_refused(): void
    {
        $m = new \ReflectionMethod(AiService::class, 'looksUnusable');
        $m->setAccessible(true);

        foreach ([
            'sk-' . str_repeat('A1b2C3d4', 6),              // OpenAI
            'gsk_' . str_repeat('x7Y9z2Qw', 7),             // Groq
            'sk-ant-api03-' . str_repeat('kL8mN2pQ', 8),    // Anthropic
            'AIza' . str_repeat('Sy9Bd3Kk', 5),             // Gemini
        ] as $key) {
            $this->assertFalse($m->invoke(null, $key),
                'a real ' . substr($key, 0, 4) . '… key was refused as a placeholder');
        }
    }
}
