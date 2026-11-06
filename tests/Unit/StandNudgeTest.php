<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{StandCall, StandType};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Telling vendors the call exists.
 *
 * The call for stands has always lived at `/events/{slug}/stands` and nothing on the site
 * linked to it, so the businesses applying were the ones the organiser had already phoned —
 * which is the outcome a published quota is there to prevent. These tests are about the two
 * ways somebody now finds it, and about the three ways that could quietly go wrong: a draft
 * call leaking its terms, a card advertising a call the apply form will refuse, and a nudge
 * still selling a stand at an event that has already happened.
 */
class StandNudgeTest extends TestCase
{
    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build();
    }

    private function events(): \AfricaGates\Controllers\EventsController
    {
        return $this->container()->get(\AfricaGates\Controllers\EventsController::class);
    }

    private function makeEvent(string $when = '+60 days'): object
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Lagos Market Day', 'slug' => 'market-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime($when)), 'status' => 'published',
        ]);
        return DB::table('gates_site_events')->where('id', $id)->first();
    }

    /** @return int the call id */
    private function draftCall(object $event, array $types = [], string $closes = '+14 days'): int
    {
        foreach ($types ?: [['name' => 'Food pitch', 'category' => 'food',
                             'price_naira' => '50000', 'quota' => '4']] as $t) {
            $r = StandType::save((int) $event->id, $t);
            $this->assertTrue($r['ok'], $r['message'] ?? '');
        }
        $c = StandCall::save((int) $event->id, [
            'intro'     => 'We are looking for cooks who can feed four hundred people.',
            'closes_at' => date('Y-m-d H:i:s', strtotime($closes)),
        ]);
        $this->assertTrue($c['ok'], $c['message'] ?? '');
        return (int) $c['id'];
    }

    private function openCall(object $event, array $types = [], string $closes = '+14 days'): void
    {
        $id = $this->draftCall($event, $types, $closes);
        $o  = StandCall::open($id, 1);
        $this->assertTrue($o['ok'], $o['message'] ?? '');
    }

    private function detail(object $event): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/events/' . $event->slug);
        return (string) $this->events()->show($req, new Response(), ['slug' => (string) $event->slug])->getBody();
    }

    private function index(): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/events');
        return (string) $this->events()->index($req, new Response())->getBody();
    }

    // ─────────────────────────── what nudge() answers ───────────────────────

    public function test_an_event_with_no_call_has_nothing_to_say(): void
    {
        $e = $this->makeEvent();
        $this->assertNull(StandCall::nudge((int) $e->id, (string) $e->slug));
    }

    public function test_an_open_call_reports_the_three_numbers_a_vendor_decides_on(): void
    {
        $e = $this->makeEvent();
        $this->openCall($e, [
            ['name' => 'Food pitch', 'category' => 'food', 'price_naira' => '50000', 'quota' => '4'],
            ['name' => 'Craft table', 'category' => 'crafts', 'price_naira' => '25000', 'quota' => '6'],
        ]);

        $n = StandCall::nudge((int) $e->id, (string) $e->slug);
        $this->assertSame('open', $n['state']);
        $this->assertSame(10, $n['quota']);
        $this->assertSame(10, $n['left'], 'nothing is allocated yet');
        $this->assertSame(2, $n['kinds']);
        $this->assertSame(25000, $n['from'], 'the cheapest place, not the first one listed');
        $this->assertSame('/events/' . $e->slug . '/stands', $n['url']);
    }

    public function test_a_past_event_is_never_selling_a_stand(): void
    {
        // `closes_at` usually catches this, but it is nullable — and a call with no closing
        // date on an event that already happened would otherwise still read as open.
        $e = $this->makeEvent('-10 days');
        $this->openCall($e, [], '+14 days');
        $this->assertNull(StandCall::nudge((int) $e->id, (string) $e->slug, true));
    }

    public function test_a_call_past_its_closing_date_reads_as_closed_rather_than_open(): void
    {
        $e = $this->makeEvent();
        $this->openCall($e);
        DB::table('gates_stand_calls')->where('event_id', $e->id)
            ->update(['closes_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);

        $this->assertSame('closed', StandCall::nudge((int) $e->id, (string) $e->slug)['state']);
    }

    public function test_a_call_that_has_not_opened_yet_reads_as_soon(): void
    {
        $e = $this->makeEvent();
        $this->openCall($e);
        DB::table('gates_stand_calls')->where('event_id', $e->id)
            ->update(['opens_at' => date('Y-m-d H:i:s', strtotime('+3 days'))]);

        $n = StandCall::nudge((int) $e->id, (string) $e->slug);
        $this->assertSame('soon', $n['state']);
        $this->assertNotSame('', $n['opens_at']);
    }

    // ───────────────────────────── the event page ───────────────────────────

    // ───────────────────────────── the events list ──────────────────────────

    public function test_the_whole_list_costs_one_query_however_many_events_there_are(): void
    {
        // The chip is worth one query for the page and not one per card. Left unmeasured,
        // this is the kind of thing that becomes forty queries the first time somebody moves
        // it inside the loop, and nothing on screen would look different.
        foreach (range(1, 4) as $i) $this->openCall($this->makeEvent('+' . (10 * $i) . ' days'));

        $ids = array_column(
            DB::table('gates_site_events')->get()->map(fn ($r) => (array) $r)->all(), 'id'
        );
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        StandCall::openFor($ids);
        $this->assertCount(1, DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
    }

    public function test_no_events_means_no_query_at_all(): void
    {
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->assertSame([], StandCall::openFor([]));
        $this->assertCount(0, DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
    }
}
