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
