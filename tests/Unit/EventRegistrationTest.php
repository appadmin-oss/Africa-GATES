<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Controllers\EventsController;
use AfricaGates\Services\CacheService;
use Slim\Views\Twig;
use Twig\Loader\ArrayLoader;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Production-readiness contract for on-platform event RSVPs: phone is required,
 * capacity is enforced, and closed/past events refuse new registrations.
 */
class EventRegistrationTest extends TestCase
{
    private function controller(): EventsController
    {
        // register() always returns JSON and never renders, so any Twig instance
        // (pointed at a throwaway path) satisfies the constructor.
        return new EventsController(new Twig(new ArrayLoader([])), new CacheService(), null);
    }

    private function seedEvent(array $over = []): string
    {
        $slug = $over['slug'] ?? 'test-gala';
        DB::table('gates_site_events')->insert(array_merge([
            'slug'       => $slug,
            'title'      => 'Test Gala',
            'event_date' => date('Y-m-d H:i:s', time() + 86400 * 30),
            'status'     => 'published',
            'created_at' => date('Y-m-d H:i:s'),
        ], $over));
        return $slug;
    }

    private function post(string $slug, array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', "https://afg.local/events/{$slug}/register", ['REMOTE_ADDR' => '1.2.3.4'])
            ->withParsedBody($body);
        $res = $this->controller()->register($req, new Response(), ['slug' => $slug]);
        return json_decode((string) $res->getBody(), true) ?: [];
    }

    public function test_phone_is_required(): void
    {
        $slug = $this->seedEvent();
        $d = $this->post($slug, ['name' => 'Ada Obi', 'email' => 'ada@example.com']);
        $this->assertFalse($d['success']);
        $this->assertStringContainsStringIgnoringCase('phone', $d['message']);
        $this->assertSame(0, DB::table('gates_event_registrations')->count());
    }

    public function test_valid_registration_stored_with_phone(): void
    {
        $slug = $this->seedEvent();
        $d = $this->post($slug, ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '+234 801 234 5678']);
        $this->assertTrue($d['success']);
        $row = DB::table('gates_event_registrations')->first();
        $this->assertSame('ada@example.com', $row->email);
        $this->assertSame('+234 801 234 5678', $row->phone);
    }

    public function test_capacity_is_enforced(): void
    {
        $slug = $this->seedEvent(['slug' => 'tiny', 'capacity' => 1]);
        $first = $this->post($slug, ['name' => 'One', 'email' => 'one@example.com', 'phone' => '08012345678']);
        $this->assertTrue($first['success']);
        $second = $this->post($slug, ['name' => 'Two', 'email' => 'two@example.com', 'phone' => '08087654321']);
        $this->assertFalse($second['success']);
        $this->assertTrue($second['full'] ?? false);
        $this->assertSame(1, DB::table('gates_event_registrations')->count());
    }

    public function test_past_event_registration_closed(): void
    {
        $slug = $this->seedEvent(['slug' => 'past', 'event_date' => date('Y-m-d H:i:s', time() - 86400)]);
        $d = $this->post($slug, ['name' => 'Late', 'email' => 'late@example.com', 'phone' => '08012345678']);
        $this->assertFalse($d['success']);
        $this->assertSame(0, DB::table('gates_event_registrations')->count());
    }
}
