<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\EventsController;
use AfricaGates\Admin\Services\EventCopy;
use AfricaGates\Admin\Services\EventDirectory;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Duplicate an event, and never delete one somebody holds a ticket for (11 Nov 2026).
 *
 * The copy is the event's DESIGN — page, tiers, policies, series — as a draft. Never a
 * person's ticket, and never a tier's access code: that is a secret handed out for the
 * original, and copying it would start opening the new event to whoever holds it.
 */
final class EventDuplicateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
        DB::table('gates_site_events')->insert(['id' => 71, 'slug' => 'gala', 'title' => 'Awards gala', 'status' => 'published',
            'event_date' => '2026-12-05 18:00:00', 'venue' => 'Eko Hotel', 'capacity' => 400, 'refund_policy' => 'Refunds until a week before.']);
        DB::table('gates_event_tiers')->insert(['event_id' => 71, 'slug' => 'vip', 'name' => 'VIP', 'price_naira' => 50000,
            'capacity' => 40, 'access_code' => 'SECRET1', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_event_registrations')->insert(['event_id' => 71, 'name' => 'Ada', 'email' => 'ada@mail.example.org', 'status' => 'confirmed']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function ctrl(): EventsController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(EventsController::class);
    }

    public function test_a_copy_is_a_draft_with_the_design_and_none_of_the_people(): void
    {
        $r = EventCopy::copy(71);
        $this->assertTrue($r['ok']);
        $e = DB::table('gates_site_events')->where('id', $r['id'])->first();
        $this->assertSame('draft', $e->status, 'nothing public until somebody publishes it');
        $this->assertSame('gala-copy', $e->slug);
        $this->assertSame('Awards gala (copy)', $e->title);
        $this->assertSame('Eko Hotel', $e->venue);
        $this->assertSame('Refunds until a week before.', $e->refund_policy);

        $tier = DB::table('gates_event_tiers')->where('event_id', $r['id'])->first();
        $this->assertSame('VIP', $tier->name);
        $this->assertSame(40, (int) $tier->capacity);
        $this->assertNull($tier->access_code, 'a secret for the original never opens the copy');
        $this->assertSame(0, DB::table('gates_event_registrations')->where('event_id', $r['id'])->count());

        $this->assertSame('gala-copy-2', DB::table('gates_site_events')->where('id', EventCopy::copy(71)['id'])->value('slug'));
        $this->assertSame('gala-copy-3', DB::table('gates_site_events')->where('id', EventCopy::copy((int) $r['id'])['id'])->value('slug'),
            'a copy of a copy is not gala-copy-copy');
    }

    public function test_the_duplicate_action_opens_the_copy(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/events/71/duplicate');
        $res = $this->ctrl()->duplicate($req, new Response(), ['id' => 71]);
        $copy = (int) DB::table('gates_site_events')->where('slug', 'gala-copy')->value('id');
        $this->assertSame('/admin/events/' . $copy, $res->getHeaderLine('Location'));
        $this->assertTrue(DB::table('gates_audit_log')->where('action', 'event.duplicate')->where('target_id', $copy)->exists());
    }

    public function test_an_event_with_ticket_holders_is_never_deleted(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/events/71/delete');
        $this->ctrl()->delete($req, new Response(), ['id' => 71]);
        $this->assertTrue(DB::table('gates_site_events')->where('id', 71)->exists());
        $this->assertStringContainsString('1 registration belongs to it', (string) $_SESSION['flash_error']);

        $copy = (int) EventCopy::copy(71)['id'];
        $this->ctrl()->delete($req, new Response(), ['id' => $copy]);
        $this->assertFalse(DB::table('gates_site_events')->where('id', $copy)->exists(), 'an event nobody registered for may go');
    }

    public function test_the_list_says_how_full_each_event_is(): void
    {
        $row = EventDirectory::page([])['rows'][0];
        $this->assertSame(1, $row['confirmed']);
        $this->assertSame(1, $row['registrations']);
    }
}
