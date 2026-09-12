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

    public function test_a_draft_call_is_not_a_public_fact(): void
    {
        // Its terms are still being written. Half-publishing them is how a quota gets quoted
        // before it has been decided — and StandApplyController::call() redirects for the
        // same reason, so the two must agree.
        $e = $this->makeEvent();
        $this->draftCall($e);
        $this->assertNull(StandCall::nudge((int) $e->id, (string) $e->slug));
        $this->assertStringNotContainsString('Vendor stands', $this->detail($e));
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

    public function test_the_event_page_offers_the_stand_and_says_applying_is_free(): void
    {
        $e = $this->makeEvent();
        $this->openCall($e, [['name' => 'Food pitch', 'category' => 'food',
                              'price_naira' => '50000', 'quota' => '4']]);
        $html = $this->detail($e);

        $this->assertStringContainsString('/events/' . $e->slug . '/stands', $html,
            'the call page has to be reachable from the event it belongs to');
        $this->assertStringContainsString('Apply for a stand', $html);
        $this->assertStringContainsString('of 4 places open', $html, 'how many places are left is the first question');
        $this->assertStringContainsString('₦50,000', $html);
        $this->assertStringContainsString('Applying is free', $html,
            'the cost of trying is the friction worth removing, and it is genuinely nil');
    }

    public function test_a_closed_call_gets_the_date_rather_than_a_button(): void
    {
        // A vendor who arrives a fortnight late is owed "this closed on the 14th" and a page
        // of terms, so that next year they know when to look. What they are not owed is a
        // gold button that leads to a form which will refuse them.
        $e = $this->makeEvent();
        $this->openCall($e);
        DB::table('gates_stand_calls')->where('event_id', $e->id)
            ->update(['closes_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);

        $html = $this->detail($e);
        $this->assertStringContainsString('Applications closed on', $html);
        $this->assertStringContainsString('/events/' . $e->slug . '/stands', $html);
        $this->assertStringNotContainsString('Apply for a stand', $html);
    }

    public function test_the_nudge_does_not_appear_on_an_event_that_has_no_call(): void
    {
        $html = $this->detail($this->makeEvent());
        $this->assertStringNotContainsString('Vendor stands', $html);
        $this->assertStringNotContainsString('stands', $html,
            'not even a stray link — this event has nothing to apply for');
    }

    // ───────────────────────────── the events list ──────────────────────────

    public function test_a_card_is_chipped_only_while_its_call_is_actually_accepting(): void
    {
        $open = $this->makeEvent();
        $this->openCall($open);
        $shut = $this->makeEvent();
        $this->openCall($shut);
        DB::table('gates_stand_calls')->where('event_id', $shut->id)
            ->update(['closes_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $none = $this->makeEvent();

        $map = StandCall::openFor([(int) $open->id, (int) $shut->id, (int) $none->id]);
        $this->assertArrayHasKey((int) $open->id, $map);
        $this->assertArrayNotHasKey((int) $shut->id, $map,
            'the status column still says open; the clock is what decides');
        $this->assertArrayNotHasKey((int) $none->id, $map);

        $html = $this->index();
        $this->assertStringContainsString('Vendor stands open', $html);
        // One chip, for the one event that can take an application today. Matched on the
        // attribute rather than the bare class name, which also appears in the stylesheet.
        $this->assertSame(1, substr_count($html, 'class="ev-card__stand"'));
    }

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
