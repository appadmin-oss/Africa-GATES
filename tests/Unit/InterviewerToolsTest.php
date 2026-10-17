<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiService;
use AfricaGates\Services\QuestionnaireInterview as I;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\QuestionnaireStyle as S;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * What the interviewer can now DO, and what it is now TOLD.
 *
 * It used to hold four tools — record, focus, note, propose — and to know the outcomes and the
 * transcript and nothing else. So it could not ask for the report a nominee had just
 * mentioned, could not put a pasted link where a judge would find it, asked for documents
 * already attached, and ran out of turns mid-subject with no idea it was close.
 *
 * Each test holds one capability on the STORED state, because the model's words are not the
 * thing a nominee relies on — the button that appears, the link that lands on their list, the
 * request that is never repeated, are.
 */
final class InterviewerToolsTest extends TestCase
{
    private const PROG = 99;     // TINYINT-safe
    private const CAT  = 9900;
    private const NOM  = 9901;

    protected function setUp(): void
    {
        parent::setUp();
        S::forget();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::PROG, 'title' => 'Seed Award', 'slug' => 'p-9900']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 9900, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::CAT, 'cycle_id' => 9900, 'title' => 'Farming', 'slug' => 'c-9900']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Ada Nwosu', 'status' => 'approved',
            'story' => 'Ada keeps a seed bank for 300 farmers. Call Kofi on 08031234567.',
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9900, 'category_id' => self::CAT,
            'nominee_name' => 'Ada Nwosu', 'nominee_email' => 'ada@example.org',
            'country_code' => 'NG', 'reason' => 'Runs a seed bank.',
            'nominator_name' => 'Kofi Mensah', 'nominator_email' => 'k@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9901',
        ]);
        DB::table('gates_judge_criteria')->insertOrIgnore([
            'id' => 9910, 'programme_id' => null, 'slug' => 'impact', 'label' => 'Impact',
            'description' => 'x', 'weight' => 100, 'sort_order' => 1, 'is_active' => 1,
        ]);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_openai_key'], ['value' => 'sk-test-not-a-real-key']);
        S::saveConfig(self::PROG, ['style' => S::INTERVIEW, 'max_turns' => 10]);
        S::saveOutcome(self::PROG, null, ['slug' => 'scale', 'label' => 'How far it reaches',
            'description' => 'x', 'criterion_id' => 9910, 'required' => 1, 'sort_order' => 1]);
        S::forget();
    }

    // ══ asking for a file ═══════════════════════════════════════════════════

    public function test_asking_for_a_file_puts_a_button_under_that_message(): void
    {
        $token = $this->open();
        $r = I::say($token, 'We wrote a report on the seed bank in 2024.', $this->ai([
            ['text' => 'Could you share that report?', 'calls' => [['request_file', ['title' => '2024 seed bank report', 'why' => 'It shows the numbers.', 'accept' => 'document']]]],
        ]));

        $this->assertTrue($r['ok']);
        $last = end($r['turns']);
        $this->assertSame('interviewer', $last['role']);
        $this->assertSame('2024 seed bank report', $last['requests'][0]['title'] ?? null, 'the upload button is not under the message that asked');
        $this->assertSame('open', $r['requests'][0]['status'] ?? null);
        $this->assertSame('document', $r['requests'][0]['accept']);
    }

    public function test_a_file_they_cannot_share_is_never_asked_for_again(): void
    {
        $token = $this->open();
        $ask = ['request_file', ['title' => 'Photos of the store', 'why' => 'x']];
        $r = I::say($token, 'We have photos of the store.', $this->ai([['text' => 'Can you share some?', 'calls' => [$ask]]]));
        $rid = $r['requests'][0]['id'];

        $d = I::declineFile($token, $rid);
        $this->assertTrue($d['ok']);
        $this->assertSame('event', end($d['turns'])['role'], 'declining left nothing on the transcript for the interviewer to answer');

        $again = I::say($token, 'Anything else?', $this->ai([['text' => 'Could you send photos?', 'calls' => [$ask]]]));
        $this->assertSame([], $again['requests'], 'a declined file was put back in front of them');
        $this->assertSame([], end($again['turns'])['requests']);
    }

    public function test_an_arrived_file_closes_its_request_and_the_interviewer_answers_it_once(): void
    {
        $token = $this->open();
        $r = I::say($token, 'There is a certificate.', $this->ai([['text' => 'Please share it.', 'calls' => [['request_file', ['title' => 'The certificate', 'why' => 'x']]]]]));
        $rid = $r['requests'][0]['id'];

        I::fileArrived($token, $rid, '', 'scan_0042.pdf');
        $st = I::state($token);
        $this->assertSame([], $st['requests'], 'the button stayed open after the file arrived');
        $this->assertStringContainsString('The certificate', end($st['turns'])['text'], 'the notice named the file and not what was asked for');

        $ai = $this->ai([['text' => 'Thank you for the certificate. What was it awarded for?']]);
        $after = I::react($token, $ai);
        $this->assertSame(1, $ai->calls);
        $this->assertSame('interviewer', end($after['turns'])['role']);
        $tail = end($ai->lastMessages);
        $this->assertSame('user', $tail['role'], 'a reaction must end on a user turn, not one the model continues');
        $this->assertStringContainsString('not the nominee', $tail['content']);
        // And the notice reached it as a FACT (system), never as a line of dialogue: as an
        // assistant turn it would be the model's own words, as a user turn the nominee's.
        foreach (array_slice($ai->lastMessages, 0, -1) as $m) {
            if ($m['role'] === 'system') continue;
            $this->assertStringNotContainsString('File attached', (string) $m['content'], 'a platform notice was replayed as dialogue');
        }

        // The guard: the last turn is now the interviewer's, so a second press does nothing.
        $twice = $this->ai([['text' => 'should never be said']]);
        I::react($token, $twice);
        $this->assertSame(0, $twice->calls, 'react ran with no notice to answer — it can be pressed to make the model talk');
    }

    public function test_a_notice_is_never_evidence(): void
    {
        $token = $this->open();
        I::say($token, 'Hello.', $this->ai([['text' => 'Hello!']]));
        I::fileArrived($token, '', 'Report on reaching 300 farmers in Kano', 'r.pdf');

        $r = I::react($token, $this->ai([['text' => 'Thanks.', 'calls' => [['record_outcome', [
            'slug' => 'scale', 'status' => 'met', 'summary' => 'Reaches 300 farmers', 'quote' => 'Report on reaching 300 farmers in Kano']]]]]));
        $this->assertSame('unmet', $r['ledger'][0]['status'], 'a platform notice was accepted as the nominee\'s own words');
    }

    // ══ links and quick replies ═════════════════════════════════════════════

    public function test_a_link_lands_on_the_works_list_only_if_they_gave_it(): void
    {
        $token = $this->open();
        I::say($token, 'Our video is at https://youtu.be/abc123 if that helps.', $this->ai([['text' => 'Added.', 'calls' => [
            ['add_link', ['link' => 'https://youtu.be/abc123', 'title' => 'Seed bank video', 'kind' => 'media']],
            ['add_link', ['link' => 'https://example.org/invented', 'title' => 'Something it imagined']],
        ]]]));

        $works = Q::formFor($token)['works'];
        $this->assertCount(1, $works, 'a link the nominee never typed reached a judge');
        $this->assertSame('https://youtu.be/abc123', $works[0]['link']);
        $this->assertSame('conversation', $works[0]['from']);

        I::say($token, 'Again: youtu.be/abc123', $this->ai([['text' => 'ok', 'calls' => [['add_link', ['link' => 'youtu.be/abc123', 'title' => 'Video']]]]]));
        $this->assertCount(1, Q::formFor($token)['works'], 'the same link was listed twice');
    }

    public function test_quick_replies_belong_to_the_last_question_only(): void
    {
        $token = $this->open();
        $r = I::say($token, 'Hi.', $this->ai([['text' => 'Do you keep figures?', 'calls' => [['suggest_replies', ['options' => ['Yes, I do', 'I don\'t have figures', 'Yes, I do', str_repeat('x', 80)]]]]]]));
        $this->assertSame(['Yes, I do', 'I don\'t have figures'], end($r['turns'])['chips'], 'duplicates or overlong options were offered');

        $next = I::say($token, 'Yes, I do', $this->ai([['text' => 'Great — roughly how many farmers?']]));
        foreach ($next['turns'] as $t) $this->assertSame([], $t['chips'], 'an old question kept its quick replies');
    }

    // ══ what it is told ═════════════════════════════════════════════════════

    public function test_the_interviewer_knows_the_session_and_not_the_nominator(): void
    {
        $token = $this->open();
        Q::addWork($token, 'Annual report', 'https://example.org/report');
        $ai = $this->ai([['text' => 'Thanks.']]);
        I::say($token, 'Hello there.', $ai);

        $system = implode("\n", array_map(static fn(array $m): string => (string) $m['content'],
            array_filter($ai->lastMessages, static fn(array $m): bool => $m['role'] === 'system')));
        $this->assertStringContainsString('Answers left in this interview: 9 of 10', $system);
        $this->assertStringContainsString('Annual report', $system, 'the interviewer cannot see what is already attached');
        $this->assertStringContainsString('seed bank for 300 farmers', $system, 'the nominator\'s case is not there to ask good questions from');
        $this->assertStringNotContainsString('Kofi Mensah', $system, 'the nominator was named to the model');
        $this->assertStringNotContainsString('08031234567', $system, 'a phone number in the nominator\'s text reached the model');
        $this->assertStringContainsString('request_file', $system, 'the doctrine does not teach the new tools');
    }

    public function test_a_note_to_the_team_keeps_its_kind(): void
    {
        $token = $this->open();
        I::say($token, 'This is not me, I do not want this.', $this->ai([['text' => 'Understood.', 'calls' => [['save_note', ['kind' => 'withdraw', 'text' => 'Says the nomination is not them.']]]]]));
        $this->assertSame('withdraw', I::state($token)['notes'][0]['kind'] ?? null);
    }

    private function open(): string
    {
        $r = Q::open(self::NOM);
        $this->assertTrue($r['ok'], (string) $r['message']);
        $token = (string) $r['token'];
        $this->assertSame(S::INTERVIEW, I::open($token)['style']);
        return $token;
    }

    /** Replies are a queue: one per model round. OpenAI-shaped, so the real translation runs. */
    private function ai(array $replies): AiService
    {
        return new class ($replies) extends AiService {
            public int $calls = 0;
            public array $lastMessages = [];
            public function __construct(private array $queue) { parent::__construct(openaiKey: 'test-key'); }
            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $this->calls++;
                $this->lastMessages = $payload['messages'] ?? [];
                $next = array_shift($this->queue);
                if ($next === null) return null;
                return ['choices' => [['finish_reason' => ($next['calls'] ?? null) ? 'tool_calls' : 'stop',
                    'message' => [
                        'content' => (string) ($next['text'] ?? ''),
                        'tool_calls' => array_map(static fn(array $c, int $i): array => [
                            'id' => 'c' . $i, 'type' => 'function',
                            'function' => ['name' => $c[0], 'arguments' => (string) json_encode($c[1])],
                        ], $next['calls'] ?? [], array_keys($next['calls'] ?? [])),
                    ]]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]];
            }
        };
    }
}
