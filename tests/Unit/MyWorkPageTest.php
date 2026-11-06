<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnaireIntro;
use AfricaGates\Services\QuestionnaireService as Q;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * /my-work/{token}, the nominee's questionnaire, through the real router.
 *
 * The template was destroyed with the old pages on 3 Oct and not rebuilt, so the link in
 * every questionnaire invitation and reminder answered a 500. Nothing in the suite noticed:
 * the questionnaire's own tests drive the SERVICE, and the one that fetched the page asserted
 * only that a GET changes nothing — which a 500 also satisfies. So this asserts the page.
 *
 * What it holds, because each is a way the page can look fine and fail somebody:
 *   · the brief comes first, and the questions only after the nominee has read it;
 *   · every question is in the DOM at once — the one-at-a-time layer is JavaScript HIDING
 *     fieldsets, so a page without the script still posts every answer;
 *   · the upload is its own form, after the main one (a nested form is silently dropped);
 *   · a dead link is a 404 that says nothing about whether it ever existed;
 *   · the page is noindex, because the token in its URL is the nominee's whole credential.
 */
final class MyWorkPageTest extends TestCase
{
    private const PROG = 97;      // TINYINT-safe: an id above 255 clamps on MySQL
    private const CYCLE = 9700;
    private const CAT = 9700;
    private const NOM = 9701;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'Teaching Award', 'slug' => 'prog-9700',
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => self::CYCLE, 'programme_id' => self::PROG, 'year' => 2026,
            'status' => 'judging', 'results_date' => '2026-11-20 18:00:00',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => self::CYCLE, 'title' => 'Primary', 'slug' => 'cat-9700',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Bola Adeyemi',
            'organisation' => 'Green Roots', 'country_code' => 'NG', 'status' => 'approved',
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => self::CYCLE, 'category_id' => self::CAT,
            'nominee_name' => 'Bola Adeyemi', 'nominee_email' => 'bola@example.org',
            'country_code' => 'NG', 'reason' => 'Planted 12000 trees with 40 schools.',
            'nominator_name' => 'Ade', 'nominator_email' => 'ade@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9701',
        ]);
        foreach ([['impact', 'Impact'], ['originality', 'Originality'],
                  ['reach', 'Reach'], ['integrity', 'Integrity']] as $i => [$slug, $label]) {
            DB::table('gates_judge_criteria')->insertOrIgnore([
                'id' => 9710 + $i, 'programme_id' => null, 'slug' => $slug, 'label' => $label,
                'description' => 'x', 'weight' => 25, 'sort_order' => $i + 1, 'is_active' => 1,
            ]);
        }
    }

    public function test_the_brief_comes_before_the_questions(): void
    {
        $token = $this->open();
        $res = $this->get('/my-work/' . $token);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), $this->excerpt($html));
        $this->assertStringContainsString('action="/my-work/' . $token . '/ready"', $html,
            'the brief has no way past it');
        $this->assertStringNotContainsString('data-mw-step', $html,
            'the questions were drawn before the nominee was told what this takes');
    }

    public function test_every_question_is_in_the_page_at_once(): void
    {
        $token = $this->open();
        QuestionnaireIntro::markSeen($token);
        $html = (string) $this->get('/my-work/' . $token)->getBody();

        // The questions THIS submission is asked, which is what the page draws — not the
        // programme's raw list, which an adaptive questionnaire may narrow.
        $qs = Q::formFor($token)['questions'];
        $this->assertNotEmpty($qs);
        foreach ($qs as $q) {
            $this->assertStringContainsString('name="a[' . $q['slug'] . ']"', $html,
                'question "' . $q['slug'] . '" is not in the form, so it cannot be answered');
        }
        $this->assertStringContainsString('value="submit"', $html);
        $this->assertStringContainsString('value="save"', $html);
        $this->assertStringContainsString('name="declared_name"', $html);
        // Nothing hidden by the server: the one-question layer is the script's, and a page
        // without it must still post every answer.
        $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*data-mw-step[^>]*\bhidden\b/', $html);
        $this->assertStringContainsString('/assets/js/my-work.js', $html);
        $this->assertStringNotContainsString('data-ag-share', $html, 'the page offers to share a link that IS the nominee\'s login');
    }

    public function test_the_upload_is_its_own_form_after_the_main_one(): void
    {
        $token = $this->open();
        QuestionnaireIntro::markSeen($token);
        $html = (string) $this->get('/my-work/' . $token)->getBody();

        $main = strpos($html, 'action="/my-work/' . $token . '"');
        $up   = strpos($html, 'action="/my-work/' . $token . '/upload"');
        $this->assertNotFalse($main);
        $this->assertNotFalse($up, 'there is no way to attach a file');
        $close = strpos($html, '</form>', (int) $main);
        $this->assertGreaterThan($close, $up, 'the upload form opens inside the main form and the parser drops it');
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
    }

    public function test_a_dead_link_is_a_quiet_404(): void
    {
        $res = $this->get('/my-work/' . str_repeat('0', 32));
        $html = (string) $res->getBody();

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('This link is not working', $html);
        $this->assertStringNotContainsString('data-mw-form', $html);
    }

    public function test_the_page_is_not_indexed(): void
    {
        $token = $this->open();
        $this->assertStringContainsString('noindex',
            $this->get('/my-work/' . $token)->getHeaderLine('X-Robots-Tag'));
    }

    private function open(): string
    {
        $r = Q::open(self::NOM);
        $this->assertTrue($r['ok'], (string) $r['message']);
        return (string) $r['token'];
    }

    private function get(string $path): ResponseInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    private function excerpt(string $html): string
    {
        return mb_substr(trim(strip_tags($html)), 0, 600);
    }
}
