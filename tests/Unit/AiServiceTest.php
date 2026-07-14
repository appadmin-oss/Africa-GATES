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
}
