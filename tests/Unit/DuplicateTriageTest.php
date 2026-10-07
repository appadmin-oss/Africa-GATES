<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiService;
use AfricaGates\Services\MergeSuggestionService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The duplicate scan's AI half, and the decisions it now remembers.
 *
 * Four faults, each of which made the AI analysis worth less than the screen claimed:
 *   · it ran only when the whole cycle held ≤120 names, so on a real edition it never ran;
 *   · the groups it did find had no members, so no votes, country or recommended survivor;
 *   · a rule match it disagreed with looked exactly like one it confirmed;
 *   · "these are two different people" was forgotten, and came back at 97% every scan.
 */
final class DuplicateTriageTest extends TestCase
{
    /** @var list<string> what the model was sent, one entry per call */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 71, 'title' => 'P', 'slug' => 'dup-p', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 71, 'programme_id' => 71, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 711, 'cycle_id' => 71, 'slug' => 'dup-a', 'title' => 'Alpha', 'sort_order' => 1]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 712, 'cycle_id' => 71, 'slug' => 'dup-b', 'title' => 'Beta', 'sort_order' => 2]);
    }

    private function nominee(int $id, string $name, int $cat = 711, string $country = 'NG', int $votes = 0): void
    {
        DB::table('gates_nominees')->insert(['id' => $id, 'category_id' => $cat, 'name' => $name, 'status' => 'approved',
                                             'vote_count' => $votes, 'country_code' => $country]);
    }

    /** @param callable(string):string $answer the model's JSON, given what it was sent */
    private function ai(callable $answer): AiService
    {
        $self = $this;
        return new class ($answer, $self) extends AiService {
            public function __construct(private $answer, private $test) { parent::__construct(null, 'k'); }
            public function complete(string $system, string $user, int $maxTokens = 512, bool $json = false,
                                     float $temperature = 0.2, array $route = [], int $maxAttempts = 0): ?string
            {
                $this->test->record($user);
                return ($this->answer)($user);
            }
        };
    }

    public function record(string $user): void { $this->asked[] = $user; }

    public function test_an_ai_group_arrives_with_members_and_a_survivor(): void
    {
        $this->nominee(9001, 'Ngozi Okonjo', 711, 'NG', 40);
        $this->nominee(9002, 'Ngozi Iweala', 711, 'NG', 900);   // married name — no rule catches it
        $this->nominee(9003, 'Samuel Eto', 711, 'CM');
        $ai = $this->ai(static fn () => '{"groups":[{"ids":[9001,9002],"confidence":0.8,"reason":"Same person, married name."}]}');

        $g = MergeSuggestionService::forCycle(71, $ai)['groups'];
        $this->assertCount(1, $g);
        $this->assertSame('found', $g[0]['ai_verdict']);
        $this->assertSame('Alpha', $g[0]['category']);
        $this->assertCount(2, $g[0]['members'], 'members, so the screen can show votes and country');
        $this->assertSame(9002, $g[0]['keep_id'], 'the record with the votes is recommended');
        $this->assertStringContainsString('[NG]', $this->asked[0], 'the model is told the country');
    }

    public function test_a_rule_match_the_ai_does_not_confirm_says_so(): void
    {
        $this->nominee(9011, 'Musa Bello', 711, 'NG');
        $this->nominee(9012, 'Musah Bello', 711, 'GH');
        $ai = $this->ai(static fn () => '{"groups":[]}');

        $g = MergeSuggestionService::forCycle(71, $ai)['groups'];
        $this->assertSame('unconfirmed', $g[0]['ai_verdict']);
        $this->assertStringContainsString('did not call them the same', $g[0]['reason']);

        // The same names are the same request, which the gateway now answers from cache —
        // emptied here because this half of the test is a DIFFERENT model's answer.
        DB::table('gates_cache')->delete();
        $agree = $this->ai(static fn () => '{"groups":[{"ids":[9011,9012],"confidence":0.9,"reason":"Spelling variant"}]}');
        $g = MergeSuggestionService::forCycle(71, $agree)['groups'];
        $this->assertCount(1, $g, 'one group, not the rule one and the AI one side by side');
        $this->assertSame('agrees', $g[0]['ai_verdict']);
        $this->assertStringContainsString('The AI agrees: Spelling variant.', $g[0]['reason']);
    }

    /** A real edition: more than one call's worth of names, and the AI still reads it. */
    public function test_a_large_cycle_is_read_in_batches_rather_than_skipped(): void
    {
        for ($i = 0; $i < 150; $i++) $this->nominee(9100 + $i, 'Person' . $i . ' Distinct' . $i, $i % 2 ? 711 : 712);
        $this->nominee(9300, 'Adaeze Nwosu', 711);
        $this->nominee(9301, 'Ada Nwosu', 711);
        $ai = $this->ai(static fn () => '{"groups":[]}');

        $r = MergeSuggestionService::forCycle(71, $ai);
        $this->assertTrue($r['ai'], 'over 120 names the AI used to be skipped entirely');
        $this->assertGreaterThanOrEqual(1, $r['ai_batches']);
        $this->assertLessThanOrEqual(6, $r['ai_batches'], 'and a scan never spends more than six calls');
        foreach ($this->asked as $call) {
            $this->assertLessThanOrEqual(120, substr_count($call, "\n") + 1);
            $this->assertFalse(str_contains($call, 'Alpha') && str_contains($call, 'Beta'));
        }
        $this->assertStringContainsString('Nwosu', implode("\n", $this->asked), 'the names sharing a word are the ones sent');
    }

    public function test_not_the_same_person_is_remembered(): void
    {
        $this->nominee(9021, 'John Mensah', 711);
        $this->nominee(9022, 'John Mensah', 711);
        $this->assertCount(1, MergeSuggestionService::forCycle(71)['groups']);

        $this->assertSame(1, MergeSuggestionService::dismiss([9022, 9021], 3, 'two people, checked with the nominators'));
        $this->assertSame(0, MergeSuggestionService::dismiss([9021, 9022]), 'saying no twice is one row');
        $this->assertSame([], MergeSuggestionService::forCycle(71)['groups']);

        // A third entry is a new pair, and is still offered — with the earlier ruling stated.
        $this->nominee(9023, 'John Mensah', 711);
        $g = MergeSuggestionService::forCycle(71)['groups'];
        $this->assertCount(1, $g);
        $this->assertStringContainsString('1 pair here were marked as different people before', $g[0]['reason']);
        $this->assertSame(3, (int) DB::table('gates_merge_dismissals')->where('pair_key', '9021-9022')->value('dismissed_by'));
    }

    /** And a pair only the AI proposes is held to the same ruling. */
    public function test_a_dismissed_pair_the_ai_proposes_is_not_shown_either(): void
    {
        $this->nominee(9031, 'Ngozi Okonjo', 711);
        $this->nominee(9032, 'Ngozi Iweala', 711);
        MergeSuggestionService::dismiss([9031, 9032]);
        $ai = $this->ai(static fn () => '{"groups":[{"ids":[9031,9032],"confidence":0.8,"reason":"married name"}]}');
        $this->assertSame([], MergeSuggestionService::forCycle(71, $ai)['groups']);
    }

    public function test_the_screen_offers_the_ruling_without_a_nested_form_and_reads_a_failure(): void
    {
        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/nominees/index.twig');
        $this->assertStringContainsString('formaction="/admin/nominees/not-duplicate"', $t);
        $this->assertStringContainsString("'Accept': 'application/json'", $t, 'so a 500 answers JSON with its reference');
        $this->assertStringNotContainsString("panel.innerHTML = '<p style=\"color:#b42318;font-size:13px\">Network error",
            str_replace('.catch(function () { btn.disabled = false; btn.textContent = orig; panel.innerHTML', '', $t),
            'a server fault is never called a network error');
    }
}
