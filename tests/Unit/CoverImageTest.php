<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\CoverImageController;
use AfricaGates\Services\CoverImage;
use AfricaGates\Services\EventsFront;
use AfricaGates\Support\Schema;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The default cover as a SHARE image (DEFAULT-GRAPHICS §7): what WhatsApp, X and Google see
 * for an event nobody uploaded a photo for.
 *
 * Held here: every ratio renders a real PNG of the stated size; the route answers only for
 * a published, live event (a lookup by id is the shape that leaked the sandbox before), and
 * 404s an unknown subject, id or ratio; the alt states the picture in the spec's words; the
 * event page points og:image and the JSON-LD at the default only when there is no upload,
 * and an upload still wins; and "tied to an award" is read from the join table, because the
 * event row has no programme column — a NULL kind on a ceremony must still draw a ceremony.
 */
final class CoverImageTest extends TestCase
{
    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'og-gala', 'title' => 'Lagos Founders Gala',
            'event_date' => '2026-12-12 18:00:00', 'status' => 'published', 'venue' => 'Eko Hotel',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/var/cache/og/event-' . $this->eventId . '-*.png') ?: [] as $f) @unlink($f);
        parent::tearDown();
    }

    public function test_every_ratio_is_a_png_of_its_stated_size(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not installed');
        $facts = CoverImageController::facts((array) DB::table('gates_site_events')->find($this->eventId));
        foreach (CoverImage::RATIOS as $ratio) {
            $png = (new CoverImage())->png($facts, $ratio);
            $this->assertNotNull($png, $ratio);
            $this->assertStringStartsWith("\x89PNG", (string) $png, $ratio);
            [$w, $h] = getimagesizefromstring((string) $png) ?: [0, 0];
            $this->assertSame($ratio, $w . 'x' . $h);
        }
        $this->assertNull((new CoverImage())->png($facts, '640x480'), 'an unlisted ratio draws nothing');
    }

    public function test_the_route_serves_a_live_event_and_404s_everything_else(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not installed');
        $ok = $this->get('event', $this->eventId, '1200x630');
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('image/png', $ok->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", (string) $ok->getBody());

        $this->assertSame(404, $this->get('event', 999999, '1200x630')->getStatusCode(), 'unknown id');
        $this->assertSame(404, $this->get('event', $this->eventId, '800x800')->getStatusCode(), 'unknown ratio');
        $this->assertSame(404, $this->get('award', $this->eventId, '1200x630')->getStatusCode(), 'no other subject yet');

        DB::table('gates_site_events')->where('id', $this->eventId)->update(['status' => 'draft']);
        $this->assertSame(404, $this->get('event', $this->eventId, '1200x630')->getStatusCode(), 'a draft has no share image');
    }

    public function test_the_sandbox_event_has_no_share_image(): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId(['title' => 'Sandbox', 'slug' => 'sbx', 'is_active' => 0]);
        DB::table('gates_event_programmes')->insert(['event_id' => $this->eventId, 'programme_id' => $pid]);
        $this->assertSame(404, $this->get('event', $this->eventId, '1200x630')->getStatusCode());
    }

    public function test_a_null_kind_on_an_award_event_draws_a_ceremony(): void
    {
        $row = (array) DB::table('gates_site_events')->find($this->eventId);
        $this->assertSame('community', CoverImageController::facts($row)['kind'], 'no award, no kind → the spec default');

        $pid = (int) DB::table('gates_award_programmes')->insertGetId(['title' => 'Founders', 'slug' => 'fdr', 'is_active' => 1]);
        DB::table('gates_event_programmes')->insert(['event_id' => $this->eventId, 'programme_id' => $pid]);
        $this->assertSame([$this->eventId => true], EventsFront::linked([$this->eventId, 424242]));
        $this->assertSame('ceremony', CoverImageController::facts($row)['kind']);

        DB::table('gates_site_events')->where('id', $this->eventId)->update(['cover_kind' => 'concert']);
        $row = (array) DB::table('gates_site_events')->find($this->eventId);
        $this->assertSame('concert', CoverImageController::facts($row)['kind'], 'an organiser\'s kind wins');
    }

    public function test_the_alt_says_what_the_picture_says(): void
    {
        $this->assertSame('Lagos Founders Gala, Saturday 12 December, Eko Hotel',
            CoverImage::alt('Lagos Founders Gala', '2026-12-12', 'Eko Hotel'));
        $this->assertSame('Untitled night', CoverImage::alt('Untitled night', '', ''), 'nothing invented');
    }

    public function test_json_ld_lists_the_three_ratios_and_an_upload_wins(): void
    {
        $e = (array) DB::table('gates_site_events')->find($this->eventId);
        $ld = Schema::event($e, 'https://ag.test', [], CoverImageController::eventImages($this->eventId));
        $this->assertSame([
            "https://ag.test/og/event/{$this->eventId}-1200x675.png",
            "https://ag.test/og/event/{$this->eventId}-1200x900.png",
            "https://ag.test/og/event/{$this->eventId}-1200x1200.png",
        ], $ld['image']);

        $ld = Schema::event($e, 'https://ag.test', [], '/uploads/gala.jpg');
        $this->assertSame(['https://ag.test/uploads/gala.jpg'], $ld['image']);
        $this->assertArrayNotHasKey('image', Schema::event($e, 'https://ag.test', [], ''));
    }

    public function test_the_event_page_shares_the_default_only_without_an_upload(): void
    {
        $html = $this->page('/events/og-gala');
        $this->assertMatchesRegularExpression(
            '#<meta property="og:image" content="[^"]*/og/event/' . $this->eventId . '-1200x630\.png">#', $html);
        $this->assertStringContainsString('content="Lagos Founders Gala, Saturday 12 December, Eko Hotel"', $html);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        $this->assertStringContainsString('/og/event/' . $this->eventId . '-1200x1200.png', $html, 'JSON-LD carries the square');

        DB::table('gates_site_events')->where('id', $this->eventId)->update(['cover_image' => '/uploads/gala.jpg']);
        $html = $this->page('/events/og-gala');
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="[^"]*/uploads/gala\.jpg">#', $html);
        $this->assertStringNotContainsString('/og/event/', $html, 'an upload wins everywhere');
        $this->assertStringNotContainsString('og:image:width', $html, 'the upload\'s shape is not ours to state');
    }

    private function page(string $path): string
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        \Slim\Factory\AppFactory::setContainer($builder->build());
        $app = \Slim\Factory\AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        $res = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        $this->assertSame(200, $res->getStatusCode(), $path);

        return (string) $res->getBody();
    }

    private function get(string $subject, int $id, string $ratio): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', "/og/{$subject}/{$id}-{$ratio}.png");

        return (new CoverImageController())->show($req, (new ResponseFactory())->createResponse(),
            ['subject' => $subject, 'id' => (string) $id, 'ratio' => $ratio]);
    }
}
