<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\EventsController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\CacheService;
use AfricaGates\Services\EventsFront;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * A GOOGLE MAPS LINK MUST NOT 500 THE EVENT FORM.
 *
 * 4 Nov 2026: adding a map link to an event answered 500 on production. `map_embed` was
 * VARCHAR(500) on MySQL, a place link copied from the Maps address bar is routinely longer,
 * strict mode refused the UPDATE, and nothing caught it. SQLite has no width, so this suite was
 * green throughout — which is why the width itself is asserted here from the schema and the
 * repair, and not only through a save that SQLite would accept at any length.
 *
 * Held: the link is normalised (an embed code yields its address), refused with a sentence
 * when it is not an http(s) address, never costs the rest of the form, is checked again on
 * the way out to the public page, and every link column on the form is TEXT on MySQL.
 */
final class EventMapLinkTest extends TestCase
{
    /** A real-shaped place link: well over the old 500-character column. */
    private const PLACE = 'https://www.google.com/maps/place/Eko+Hotel+%26+Suites/@6.4262046,3.4296351,17z/'
        . 'data=!3m1!4b1!4m9!3m8!1s0x103bf52ef1f1b2c3:0x5a5e8c7a3c1b0f1d!5m2!4m1!1i2!8m2!3d6.4261999!4d3.43221!'
        . '16s%2Fg%2F1tgd2p8x?entry=ttu&g_ep=EgoyMDI2MTEwMS4wIKXMDSoASAFQAw%3D%3DEgoyMDI2MTEwMS4wIKXMDSoASAFQAw%3D%3D'
        . 'EgoyMDI2MTEwMS4wIKXMDSoASAFQAw%3D%3DEgoyMDI2MTEwMS4wIKXMDSoASAFQAw%3D%3DEgoyMDI2MTEwMS4wIKXMDSoASAFQAw%3D%3D'
        . 'EgoyMDI2MTEwMS4wIKXMDSoASAFQAw%3D%3DEgoyMDI2MTEwMS4wIKXMDSoASAFQAw%3D%3DEgoyMDI2MTEwMS4wIKXMDSoASAFQAw%3D%3D';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'admin';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    public function test_a_long_place_link_is_kept_and_an_embed_code_gives_up_its_address(): void
    {
        $this->assertGreaterThan(500, strlen(self::PLACE), 'the fixture must be longer than the old column');
        $this->assertSame(['url' => self::PLACE, 'error' => ''], EventsFront::mapLink('  ' . self::PLACE . ' '));

        $embed = '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12&amp;q=1" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy"></iframe>';
        $this->assertSame('https://www.google.com/maps/embed?pb=!1m18!1m12&q=1', EventsFront::mapLink($embed)['url']);
        $this->assertSame('https://maps.app.goo.gl/AbCdEf123', EventsFront::mapLink('https://maps.app.goo.gl/AbCdEf123')['url']);
        $this->assertSame(['url' => null, 'error' => ''], EventsFront::mapLink(''), 'blank is simply no link');
    }

    public function test_what_is_not_a_web_address_is_refused_with_a_sentence(): void
    {
        foreach (['javascript:alert(1)', 'Eko Hotel, Victoria Island', 'maps.google.com/?q=eko', 'https://' . str_repeat('a', EventsFront::MAX_LINK)] as $bad) {
            $r = EventsFront::mapLink($bad);
            $this->assertNull($r['url'], $bad);
            $this->assertStringContainsString('map link was not saved', $r['error']);
        }
    }

    public function test_a_bad_link_does_not_cost_the_rest_of_the_form(): void
    {
        $id = $this->event();
        $this->save($id, ['title' => 'Founders Night, renamed', 'map_embed' => self::PLACE]);
        $row = DB::table('gates_site_events')->where('id', $id)->first();
        $this->assertSame(self::PLACE, $row->map_embed, 'the long link is stored whole');
        $this->assertArrayNotHasKey('flash_error', $_SESSION);

        $this->save($id, ['title' => 'Founders Night, again', 'map_embed' => 'javascript:alert(1)']);
        $row = DB::table('gates_site_events')->where('id', $id)->first();
        $this->assertSame('Founders Night, again', $row->title, 'everything else on the form was saved');
        $this->assertSame(self::PLACE, $row->map_embed, 'the bad link was not stored, and did not erase the link that worked');
        $this->assertStringContainsString('map link was not saved', (string) ($_SESSION['flash_error'] ?? ''));
    }

    /**
     * The width SQLite cannot show: every link column the form writes is TEXT for a fresh
     * MySQL install, and the repair widens it on a database that already has the table.
     */
    public function test_every_link_column_is_wide_enough_on_mysql(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = (string) file_get_contents($root . '/database/schema.sql');
        preg_match('~CREATE TABLE IF NOT EXISTS gates_site_events \((.*?)\n\)~s', $schema, $m);
        $this->assertNotEmpty($m, 'gates_site_events not found in schema.sql');
        $repair = (string) file_get_contents($root . '/database/migrations/2027_05_09_event_link_columns_widen.php');
        foreach (['map_embed', 'rsvp_url', 'early_bird_url', 'cover_image'] as $col) {
            $this->assertMatchesRegularExpression('~\n\s*' . $col . ' TEXT NULL,~', $m[1], "$col is TEXT on a fresh install");
            $this->assertStringContainsString("'$col'", $repair, "$col is widened on an existing database");
        }
        $this->assertStringNotContainsString("VARCHAR(500)", (string) file_get_contents($root . '/database/migrations/2026_06_30_event_richfields.php'));
    }

    public function test_the_public_page_links_only_to_a_checked_address(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');
        $this->assertStringNotContainsString('event.map_embed', $tpl, 'the stored value went straight into an href');
        $this->assertStringContainsString('href="{{ map_link }}"', $tpl);
    }

    private function event(): int
    {
        return (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Founders Night', 'slug' => 'founders-' . bin2hex(random_bytes(3)),
            'event_date' => '2027-06-01 18:00:00', 'status' => 'published',
        ]);
    }

    private function save(int $id, array $fields): void
    {
        $row  = DB::table('gates_site_events')->where('id', $id)->first();
        $body = $fields + ['slug' => $row->slug, 'event_date' => '2027-06-01T18:00', 'end_date' => '', 'status' => 'published', 'cover_image' => ''];
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/events/' . $id)->withParsedBody($body);
        (new EventsController(\Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates'), new AuditService(),
            new CacheService(), null, new UploadService(sys_get_temp_dir())))->save($req, new Response(), ['id' => (string) $id]);
    }
}
