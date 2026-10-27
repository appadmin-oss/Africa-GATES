<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventsFeed;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * `/api/events` and `/events.ics` — the calendar other sites read (the Afrovanguard site).
 *
 * Held to the /events page's own rules: published only, never the sandbox, upcoming and past
 * split the same way, times in UTC with the event's zone beside them.
 */
final class EventsFeedTest extends TestCase
{
    private function at(float $days, string $t = '18:00:00'): string
    {
        return date('Y-m-d', time() + (int) ($days * 86400)) . ' ' . $t;
    }

    private function event(array $e): int
    {
        return (int) DB::table('gates_site_events')->insertGetId($e + [
            'status' => 'published', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function world(): void
    {
        $this->event(['slug' => 'fd-later', 'title' => 'Later Gala', 'event_date' => $this->at(20), 'venue' => 'Eko Hotel', 'location' => 'Lagos',
                      'description' => '<p>An <b>evening</b> &amp; awards.</p>', 'cover_image' => '/uploads/x.jpg']);
        $this->event(['slug' => 'fd-soon', 'title' => 'Soon Forum', 'event_date' => $this->at(2), 'tagline' => 'Two days out']);
        $this->event(['slug' => 'fd-running', 'title' => 'Running Summit', 'event_date' => $this->at(-1), 'end_date' => $this->at(1)]);
        $this->event(['slug' => 'fd-past', 'title' => 'Last Year Gala', 'event_date' => $this->at(-30)]);
        $this->event(['slug' => 'fd-draft', 'title' => 'Draft Night', 'event_date' => $this->at(5), 'status' => 'draft']);
        $sbx = $this->event(['slug' => 'fd-sandbox', 'title' => 'Rehearsal Gala', 'event_date' => $this->at(10)]);
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['title' => 'Sandbox', 'slug' => 'sbx-' . uniqid(), 'is_active' => 0, 'sort_order' => 1]);
        DB::table('gates_event_programmes')->insert(['event_id' => $sbx, 'programme_id' => $p]);
    }

    public function test_upcoming_is_soonest_first_and_skips_drafts_the_sandbox_and_the_past(): void
    {
        $this->world();
        $titles = array_column(EventsFeed::events('upcoming', 20, 'https://afg.test'), 'title');
        $this->assertSame(['Running Summit', 'Soon Forum', 'Later Gala'], $titles);
        $this->assertSame(['Last Year Gala'], array_column(EventsFeed::events('past', 20, 'https://afg.test'), 'title'));
    }

    public function test_an_event_goes_out_with_absolute_links_utc_times_and_plain_text(): void
    {
        $this->world();
        $later = array_values(array_filter(EventsFeed::events('upcoming', 20, 'https://afg.test/'), fn ($e) => $e['slug'] === 'fd-later'))[0];
        $this->assertSame('https://afg.test/events/fd-later', $later['url']);
        $this->assertSame('https://afg.test/events/fd-later/calendar.ics', $later['calendar_url']);
        $this->assertSame('https://afg.test/uploads/x.jpg', $later['cover_image']);
        $this->assertMatchesRegularExpression('~^\d{4}-\d\d-\d\dT18:00:00Z$~', $later['starts_at']);
        $this->assertSame('', $later['ends_at']);
        $this->assertSame('An evening & awards.', $later['description']);
        $this->assertSame('Eko Hotel', $later['venue']);
        $this->assertNotSame('', $later['timezone']);
    }

    public function test_the_api_route_answers_json_any_origin_can_read(): void
    {
        $this->world();
        foreach (['/api/events?scope=upcoming&limit=2', '/api/v1/events?limit=2'] as $uri) {
            $res = ChromeRender::page($uri);
            $this->assertSame(200, $res->getStatusCode(), $uri);
            $this->assertSame('*', $res->getHeaderLine('Access-Control-Allow-Origin'));
            $d = json_decode((string) $res->getBody(), true);
            $this->assertTrue($d['success']);
            $this->assertSame(['Running Summit', 'Soon Forum'], array_column($d['events'], 'title'), $uri);
        }
        $d = json_decode((string) ChromeRender::page('/api/events?scope=past')->getBody(), true);
        $this->assertSame('past', $d['scope']);
        $this->assertSame(['Last Year Gala'], array_column($d['events'], 'title'));
    }

    public function test_the_calendar_holds_every_upcoming_event_in_one_file(): void
    {
        $this->world();
        $res = ChromeRender::page('/events.ics');
        $this->assertSame(200, $res->getStatusCode());
        $ics = (string) $res->getBody();
        $this->assertSame(1, substr_count($ics, 'BEGIN:VCALENDAR'));
        $this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('SUMMARY:Later Gala', $ics);
        $this->assertStringNotContainsString('Rehearsal Gala', $ics);
        $this->assertStringNotContainsString('Last Year Gala', $ics);
    }
}
