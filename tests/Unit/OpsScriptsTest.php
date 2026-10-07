<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiCapability;
use AfricaGates\Services\AiService;
use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\Ops\OpsAgent;
use AfricaGates\Services\Ops\OpsScripts;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The platform's own scripts, run with no shell — by a person, or by the admin assistant.
 *
 * What has to hold: every CHECK runs in-process against a real database and says something;
 * the assistant can run checks and never a repair; a repair needs a person, the right role
 * and a reason; a role sees only scripts its console gate covers; every run is recorded.
 */
final class OpsScriptsTest extends TestCase
{
    public function test_every_check_runs_in_process_and_is_recorded(): void
    {
        DemoSeeder::seed(0);
        foreach (array_keys(OpsScripts::forRole('superadmin', OpsScripts::CHECK)) as $key) {
            $r = OpsScripts::run($key, 'superadmin', null, 'person');
            $this->assertArrayNotHasKey('error', $r, $key . ': ' . ($r['error'] ?? ''));
            $this->assertNotSame('', $r['output'], $key . ' said nothing');
            $this->assertStringNotContainsString("\e[", $r['output'], $key . ': terminal colour codes reach the page');
        }
        $this->assertSame(count(OpsScripts::forRole('superadmin', OpsScripts::CHECK)),
            (int) DB::table('gates_ops_runs')->where('via', 'person')->count());
    }

    public function test_the_assistant_can_run_a_check_and_never_a_repair(): void
    {
        $this->assertTrue(OpsScripts::run('support_queue', 'superadmin', null, 'ai')['ok']);
        foreach (array_keys(OpsScripts::forRole('superadmin', OpsScripts::REPAIR)) as $key) {
            $r = OpsScripts::run($key, 'superadmin', null, 'ai', 'it said so');
            $this->assertFalse($r['ok'], $key);
            $this->assertStringContainsString('a person runs repairs', $r['error']);
        }
        $this->assertSame(0, (int) DB::table('gates_ops_runs')->where('kind', OpsScripts::REPAIR)->count());
    }

    public function test_a_repair_needs_a_reason_and_an_admin(): void
    {
        $this->assertStringContainsString('reason', OpsScripts::run('cache_clear', 'superadmin', null, 'person')['error']);
        $this->assertTrue(OpsScripts::run('cache_clear', 'superadmin', null, 'person', 'templates were stale')['ok']);
        $this->assertSame('templates were stale', DB::table('gates_ops_runs')->where('script_key', 'cache_clear')->value('reason'));
        $this->assertSame([], OpsScripts::forRole('editor', OpsScripts::REPAIR), 'an editor runs no repairs');
    }

    public function test_a_role_sees_only_what_its_console_gate_covers(): void
    {
        $mod = array_keys(OpsScripts::forRole('moderator'));
        $this->assertNotContains('payments_triage', $mod, 'a moderator\'s rail shows no payments, nor may its assistant');
        $this->assertNotContains('recent_errors', $mod);
        $this->assertContains('payments_triage', array_keys(OpsScripts::forRole('superadmin')));
        $this->assertStringContainsString('cannot run', OpsScripts::run('payments_triage', 'moderator', null, 'ai')['error']);
    }

    /** The assistant runs the check, answers from it, and offers the repair as a button. */
    public function test_the_assistant_answers_from_a_check_it_ran(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'ai_anthropic_key', 'value' => 'set']);
        AiCapability::forget();
        $answers = [
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'a', 'name' => 'support_queue', 'input' => []]]],
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'b', 'name' => 'cache_clear', 'input' => []],
                ['type' => 'tool_use', 'id' => 'c', 'name' => 'offer_action', 'input' => ['label' => 'Run the reconcile', 'url' => '/admin/assistant/scripts#payments_reconcile']],
                ['type' => 'tool_use', 'id' => 'd', 'name' => 'offer_action', 'input' => ['label' => 'Evil', 'url' => 'https://evil.example/']],
            ]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'No tickets are open.']]],
        ];
        $ai = new class ($answers) extends AiService {
            public array $sent = [];
            public function __construct(private array $answers) { parent::__construct(null, null, 'k'); }
            protected function httpPost(string $url, array $headers, array $payload): ?array
            { $this->sent[] = $payload; return array_shift($this->answers); }
        };

        $r = (new OpsAgent($ai))->answer('anything in the support queue?', [], 'superadmin', null);
        $this->assertSame('No tickets are open.', $r['reply']);
        $this->assertSame(['The support queue'], $r['ran']);
        $this->assertSame([['label' => 'Run the reconcile', 'url' => '/admin/assistant/scripts#payments_reconcile']], $r['actions']);
        $this->assertSame(0, (int) DB::table('gates_ops_runs')->where('script_key', 'cache_clear')->count(),
            'a repair named as a tool is refused, never run');
        $tools = array_column($ai->sent[0]['tools'], 'name');
        $this->assertContains('payments_triage', $tools);
        $this->assertNotContains('cache_clear', $tools, 'repairs are never offered to the model as tools');
        $this->assertSame(['type' => 'ephemeral'], $ai->sent[0]['cache_control']);
    }

    public function test_the_scripts_page_is_linked_from_the_assistant(): void
    {
        $this->assertStringContainsString('href="/admin/assistant/scripts"',
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/assistant.twig'));
    }
}
