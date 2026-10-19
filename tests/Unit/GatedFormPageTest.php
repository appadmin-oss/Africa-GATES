<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GatedFormService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * /form/{token}, the single-use nominee and judge form, through the real router.
 *
 * pages/gated-form.twig was destroyed on 3 Oct and not rebuilt, so the "Complete my nominee
 * form" button in every approval email answered a 500. GatedFormServiceTest proved the
 * token's life cycle the whole time; nothing fetched the page a person actually lands on.
 */
final class GatedFormPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0]);
    }

    public function test_a_live_link_draws_the_form_and_says_what_is_required(): void
    {
        $raw = GatedFormService::issue('nominee', 1, 'ada@x.io');
        $res = $this->get('/form/' . $raw);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), mb_substr(strip_tags($html), 0, 400));
        $this->assertStringContainsString('action="/form/' . $raw . '"', $html);
        $this->assertMatchesRegularExpression('/name="bio"[^>]*required/', $html);
        $this->assertMatchesRegularExpression('/name="accept_terms"[^>]*required/', $html);
        $this->assertStringContainsString('Required.', $html, 'the bio rule is only learned by being refused');
        // The URL is the person's whole credential.
        $this->assertStringNotContainsString('data-ag-share', $html);
    }

    public function test_a_dead_link_says_so_and_offers_a_way_on(): void
    {
        $res = $this->get('/form/not-a-token');
        $this->assertSame(410, $res->getStatusCode());
        $this->assertStringContainsString('This link is not working', (string) $res->getBody());
    }

    public function test_a_used_link_says_it_was_used_and_where_to_go(): void
    {
        $raw = GatedFormService::issue('nominee', 1, 'ada@x.io');
        GatedFormService::submit($raw, ['bio' => 'A teacher.', 'expertise' => '', 'phone' => '', 'links' => '', 'accept_terms' => 1]);
        $html = (string) $this->get('/form/' . $raw)->getBody();

        $this->assertStringContainsString('already complete', $html);
        $this->assertStringContainsString('href="/support"', $html);
        $this->assertStringNotContainsString('name="bio"', $html, 'a used link offered the form again');
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
}
