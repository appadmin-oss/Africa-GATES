<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnaireInterview as I;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\QuestionnaireStyle as S;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * /my-work/{token} for a programme that answers by interview, through the real router.
 *
 * `pages/my-work/interview.twig` was destroyed on 3 Oct and not rebuilt, so every nominee on
 * an interview programme opened their invitation onto a 500 — and, like the guided form, no
 * test fetched the page and looked at it.
 *
 * Rebuilding it found a second fault behind it. The interview's Send form posts a name and
 * nothing else (its answers live in the ledger), and `save()` stored a "draft" from every
 * post — two EMPTY lists written over the nominee's works, every link and file, in the instant
 * before the submission went to the panel. That is held here on the stored rows.
 */
final class MyWorkInterviewPageTest extends TestCase
{
    private const PROG = 98;     // TINYINT-safe: an id above 255 clamps on MySQL
    private const CAT  = 9800;
    private const NOM  = 9801;

    protected function setUp(): void
    {
        parent::setUp();
        S::forget();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::PROG, 'title' => 'Seed Award', 'slug' => 'p-9800']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 9800, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::CAT, 'cycle_id' => 9800, 'title' => 'Farming', 'slug' => 'c-9800']);
        DB::table('gates_nominees')->insertOrIgnore(['id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Ada Nwosu', 'status' => 'approved']);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9800, 'category_id' => self::CAT,
            'nominee_name' => 'Ada Nwosu', 'nominee_email' => 'ada@example.org',
            'country_code' => 'NG', 'reason' => 'Runs a seed bank.',
            'nominator_name' => 'Kofi', 'nominator_email' => 'k@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9801',
        ]);
        DB::table('gates_judge_criteria')->insertOrIgnore([
            'id' => 9810, 'programme_id' => null, 'slug' => 'impact', 'label' => 'Impact',
            'description' => 'x', 'weight' => 100, 'sort_order' => 1, 'is_active' => 1,
        ]);
        // A configured key: whether an interview is possible is a question about the
        // deployment, answered by QuestionnaireStyle, not by a double.
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_openai_key'], ['value' => 'sk-test-not-a-real-key']);
        S::saveConfig(self::PROG, ['style' => S::INTERVIEW, 'persona' => 'Funmi']);
        S::saveOutcome(self::PROG, null, ['slug' => 'scale', 'label' => 'How far it reaches',
            'description' => 'x', 'criterion_id' => 9810, 'required' => 1, 'sort_order' => 1]);
        S::forget();
        $_SESSION = [];
    }

    public function test_the_interview_draws_the_conversation_and_both_ways_out(): void
    {
        $token = $this->open();
        $res = $this->send('GET', '/my-work/' . $token);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), mb_substr(strip_tags($html), 0, 600));
        $this->assertStringContainsString('Ada Nwosu — tell the judges about your work', $html);
        $this->assertStringNotContainsString('ag-tabbar', $html, 'a tab bar was drawn under a pinned composer');
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('Funmi', $html, 'the interviewer the programme named is not who the page says is asking');

        $greeting = (string) (I::state($token)['turns'][0]['text'] ?? '');
        $this->assertNotSame('', $greeting);
        $this->assertStringContainsString(htmlspecialchars($greeting, ENT_QUOTES), $html, 'the greeting is not on the first paint');

        // The way out is a real form post, so it works with the script broken.
        $this->assertStringContainsString('action="/my-work/' . $token . '/interview/switch"', $html);
        // Sending needs a typed name, and says nothing goes until then.
        $this->assertMatchesRegularExpression('/name="declared_name"[^>]*required/', $html);
        $this->assertStringContainsString('Nothing is sent until you press Send with your name typed.', $html);
        // The composer is the script's: without it, a post to the JSON turn endpoint would
        // land the nominee on a page of JSON.
        $this->assertMatchesRegularExpression('/<form class="iv__composer" data-iv-say hidden>/', $html);
        // An app screen: the shell's scroller is switched off by page id and the log scrolls.
        $this->assertStringContainsString('data-page="mw-interview"', $html, 'the page scrolls as a whole and carries the composer away');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/my-work.css');
        $this->assertMatchesRegularExpression('/body\[data-page="mw-interview"\] \.ag-main\{[^}]*overflow:hidden/', $css);
        $this->assertMatchesRegularExpression('/\.iv__log\{[^}]*overflow-y:auto/', $css, 'nothing scrolls the conversation');
        $this->assertStringContainsString('data-iv-attach', $html, 'there is no way to send a file from the conversation');
        // The header is one row: the title and the promise are the first card of the
        // conversation, so they scroll away instead of holding half a phone screen.
        $this->assertMatchesRegularExpression('/data-iv-log[^>]*>\s*<li class="iv__intro"[^>]*>.*?<h1/s', $html, 'the title is back above the conversation');
        // The URL carries the nominee's token — their whole credential. Nothing offers to share it.
        $this->assertStringNotContainsString('data-ag-share', $html, 'the page offers to share a link that IS the nominee\'s login');
        $this->assertStringContainsString('/assets/js/my-work-interview.js', $html);
    }

    public function test_a_stopped_conversation_says_why_and_offers_the_form(): void
    {
        $token = $this->open();
        DB::table('gates_settings')->where('key_name', 'ai_openai_key')->delete();
        S::forget();

        $html = (string) $this->send('GET', '/my-work/' . $token)->getBody();

        $this->assertStringContainsString('Nothing you said is lost.', $html);
        $this->assertStringContainsString(htmlspecialchars(I::degradedMessage('no_ai'), ENT_QUOTES), $html,
            'the page worded the outage itself instead of using the service\'s sentence');
        $this->assertStringContainsString('Carry on in the form', $html);
        $this->assertStringNotContainsString('data-iv-say', $html, 'a composer was offered to a conversation that cannot answer');
    }

    public function test_sending_from_the_interview_keeps_the_works(): void
    {
        $token = $this->open();
        Q::saveDraft($token, [], [['uid' => 'w1', 'title' => 'Seed bank ledger', 'link' => 'https://example.org/ledger']]);
        $this->assertCount(1, Q::formFor($token)['works']);

        $this->send('POST', '/my-work/' . $token, ['action' => 'submit', 'declared_name' => 'Ada Nwosu']);

        $works = json_decode((string) Q::byToken($token)->works_json, true);
        $this->assertCount(1, $works, 'sending from the interview erased the works the nominee had attached');
        $this->assertSame('Seed bank ledger', $works[0]['title']);
    }

    // ── the screens from the live-interview handoff (design_handoff_live_interview) ──

    /** Write a conversation onto the submission, as the engine stores it. */
    private function converse(string $token, array $turns, string $savedAt): object
    {
        $s = Q::byToken($token);
        $env = json_decode((string) ($s->transcript_json ?? ''), true) ?: [];
        $env['turns'] = array_merge((array) ($env['turns'] ?? []), $turns);
        DB::table('gates_nominee_submissions')->where('id', $s->id)
            ->update(['transcript_json' => json_encode($env), 'updated_at' => $savedAt]);
        return Q::byToken($token);
    }

    public function test_a_first_visit_opens_on_the_welcome_with_its_four_facts(): void
    {
        $html = (string) $this->send('GET', '/my-work/' . $this->open())->getBody();
        $this->assertStringContainsString('data-entry="welcome"', $html);
        $this->assertStringContainsString('data-iv-pane="welcome"', $html);
        foreach (['You are talking to an AI.', 'About 15 minutes.', 'Nothing reaches the judges until you read it and send it.',
                  'Switch to the form whenever you like.', 'Start the conversation', 'Fill in the form instead', 'Nothing about this costs money.'] as $line) {
            $this->assertStringContainsString($line, $html);
        }
        // Progress is outcomes, one segment each, with no percentage anywhere.
        preg_match('~<div class="iv__prog" data-iv-prog[^>]*>(.*?)</div>~s', $html, $rail);
        $this->assertSame(1, substr_count($rail[1] ?? '', 'iv__seg iv__seg--unmet'), 'the rail is not one segment per outcome');
        $this->assertDoesNotMatchRegularExpression('~\d+% covered~', $html, 'the rail is a guide, not a score');
        $this->assertStringContainsString('0 of 1', $html);
    }

    public function test_the_way_to_the_form_is_on_every_screen(): void
    {
        $token = $this->open();
        $html = (string) $this->send('GET', '/my-work/' . $token)->getBody();
        // the welcome, the conversation, the files, the read-back
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'action="/my-work/' . $token . '/interview/switch"'));
    }

    public function test_coming_back_after_a_gap_shows_where_we_stopped(): void
    {
        $token = $this->open();
        $this->converse($token, [
            ['role' => 'nominee', 'text' => 'We run a seed bank in Kano.', 'at' => '2026-09-01 10:00:00'],
            ['role' => 'interviewer', 'text' => 'How many farmers borrow seed from it each season?', 'at' => '2026-09-01 10:00:05'],
        ], date('Y-m-d H:i:s', time() - 2 * 86400));
        $html = (string) $this->send('GET', '/my-work/' . $token)->getBody();
        $this->assertStringContainsString('data-entry="back"', $html);
        $this->assertStringContainsString('Where we stopped', $html);
        $this->assertStringContainsString('How many farmers borrow seed from it each season?', $html);
        $this->assertStringContainsString('Pick up where we left off', $html);

        // A visit a minute after the last message goes straight back into the conversation.
        $this->converse($token, [], date('Y-m-d H:i:s', time() - 60));
        $this->assertStringContainsString('data-entry=""', (string) $this->send('GET', '/my-work/' . $token)->getBody());
    }

    public function test_what_was_noted_is_shown_at_its_turn_labelled_and_linked_back(): void
    {
        $token = $this->open();
        $s = $this->converse($token, [
            ['role' => 'nominee', 'text' => 'Four hundred farmers borrow seed from us every season.', 'at' => '2026-09-01 10:00:00'],
        ], date('Y-m-d H:i:s'));
        $env = json_decode((string) $s->transcript_json, true);
        $turn = count($env['turns']) - 1;
        $r = \AfricaGates\Services\QuestionnaireLedger::record($s, 'scale', 'met', 'Reaches about 400 farmers a season.',
            'Four hundred farmers borrow seed', $env['turns']);
        $this->assertTrue($r['ok'] ?? false, json_encode($r));

        $html = (string) $this->send('GET', '/my-work/' . $token)->getBody();
        $at = strpos($html, 'id="iv-t' . $turn . '"');
        $this->assertNotFalse($at, 'the turn has no anchor to link back to');
        $note = strpos($html, 'Noted · worked out by the interviewer', (int) $at);
        $this->assertNotFalse($note, 'the note is not drawn after the turn it came from');
        $this->assertStringContainsString('You read and correct every one of these before anything is sent.', $html);
        $this->assertStringContainsString('href="#iv-t' . $turn . '" data-iv-jump="' . $turn . '"', $html, 'the read-back cannot find its turn');
        $this->assertStringContainsString('iv__rowo iv__rowo--met', $html);
        $this->assertStringContainsString('Done · “Four hundred farmers borrow seed”', $html, 'a state is drawn without its word');
        $this->assertStringContainsString('1 of 1', $html);
    }

    private function open(): string
    {
        $r = Q::open(self::NOM);
        $this->assertTrue($r['ok'], (string) $r['message']);
        $token = (string) $r['token'];
        $this->assertSame(S::INTERVIEW, I::open($token)['style'], 'the fixture did not produce an interview');
        return $token;
    }

    private function send(string $method, string $path, array $body = []): ResponseInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        $req = (new ServerRequestFactory())->createServerRequest($method, $path);
        return $app->handle($body ? $req->withParsedBody($body) : $req);
    }
}
