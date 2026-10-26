<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiGateway;
use AfricaGates\Services\AiService;
use AfricaGates\Services\NominationTriageService as Triage;
use AfricaGates\Support\AiJson;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "AI triage unavailable (SCHEMA_REJECTED)" (owner, 13 Oct 2026).
 *
 * Every JSON capability's schema began with json_decode($raw), and a reply that wrapped a
 * correct object in a sentence — or a fence that did not open the reply — was discarded
 * whole. The gateway now hands schemas the JSON in the reply (Support\AiJson), and a triage
 * that failed for a reason a retry can change is tried again instead of standing for ever.
 */
final class AiJsonTest extends TestCase
{
    public function test_the_object_is_found_inside_words_and_fences(): void
    {
        $this->assertSame('{"a":1}', AiJson::extract('{"a":1}'));
        $this->assertSame('{"a":1}', AiJson::extract("Here is the assessment:\n{\"a\":1}\nHope that helps."));
        $this->assertSame('{"a":1}', AiJson::extract("Sure.\n```json\n{\"a\":1}\n```"));
        $this->assertSame('[1,2]', AiJson::extract('The list is [1,2].'));
        // A brace inside a quoted value does not end the object early.
        $this->assertSame('{"s":"a {draft} }","n":2}', AiJson::extract('Result: {"s":"a {draft} }","n":2} done'));
    }

    public function test_nothing_is_repaired(): void
    {
        $this->assertNull(AiJson::extract('{"score": 70, "summary": "cut off'), 'a truncated object stays rejected');
        $this->assertNull(AiJson::extract('no json at all'));
        $this->assertNull(AiJson::extract(''));
    }

    private function fake(string $reply): AiService
    {
        return new class ($reply) extends AiService {
            public function __construct(private readonly string $reply) { parent::__construct(groqKey: 'test-key'); }
            public function complete(string $system, string $user, int $maxTokens = 512, bool $json = false, float $temperature = 0.2, array $route = [], int $maxAttempts = 0): ?string
            { return $this->reply; }
            public function lastUsage(): array { return ['in' => 1, 'out' => 1]; }
        };
    }

    private function nom(): int
    {
        return (int) DB::table('gates_nominations')->insertGetId([
            'cycle_id' => 1, 'nominee_name' => 'Ada Obi', 'nominee_email' => 'ada@x.io',
            'reason' => 'She built three rural libraries and trained 400 teachers.',
            'nominator_name' => 'A Person', 'nominator_email' => 'p@x.io',
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** The reply that used to be rejected, scored — through the real gateway and schema. */
    public function test_a_wrapped_triage_reply_is_scored(): void
    {
        $id  = $this->nom();
        $row = Triage::generate($id, $this->fake("Here is my triage:\n```json\n{\"score\": \"72/100\", \"summary\": \"Specific and checkable.\"}\n```"));
        $this->assertSame(72, $row['quality_score']);
        $this->assertSame('Specific and checkable.', $row['summary']);
    }

    /** And an unreadable one says what came back, so the next one can be diagnosed. */
    public function test_a_rejected_reply_is_logged_with_what_came_back(): void
    {
        $r = (new AiGateway($this->fake('I cannot score this.')))->run('nomination.triage', [
            'system' => 's', 'user' => 'u', 'json' => true, 'fresh' => true,
            'schema' => static fn (string $raw): ?array => is_array($j = json_decode($raw, true)) ? $j : null,
        ]);
        $this->assertSame('SCHEMA_REJECTED', $r->code);
        $err = (string) DB::table('gates_ai_calls')->where('capability', 'nomination.triage')->orderByDesc('id')->value('error');
        $this->assertStringContainsString('I cannot score this.', $err);
    }

    /** A failure a retry can change is tried again after an hour; AI switched off is not. */
    public function test_a_failed_triage_is_retried_and_only_when_it_could_change(): void
    {
        $retry = $this->nom();
        $off   = $this->nom();
        $hourAgo = date('Y-m-d H:i:s', time() - 7200);
        DB::table('gates_nomination_insights')->insert([
            ['nomination_id' => $retry, 'quality_score' => null, 'duplicates_json' => '[]', 'created_at' => $hourAgo,
             'summary' => Triage::UNAVAILABLE . ' (SCHEMA_REJECTED) — reviewed on duplicates and heuristics only.'],
            ['nomination_id' => $off, 'quality_score' => null, 'duplicates_json' => '[]', 'created_at' => $hourAgo,
             'summary' => Triage::UNAVAILABLE . ' (DISABLED) — reviewed on duplicates and heuristics only.'],
        ]);
        DB::table('gates_jobs')->delete();
        $this->assertSame(1, Triage::backfill(50));
        // Decoded, never matched as text: MySQL's JSON column stores `{"nomination_id": 2}`
        // with a space, so a byte comparison held only on SQLite.
        $job = (array) json_decode((string) DB::table('gates_jobs')->where('type', Triage::JOB_TRIAGE)->value('payload'), true);
        $this->assertSame($retry, (int) ($job['nomination_id'] ?? 0));
    }
}
