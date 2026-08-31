<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\DoorController;
use AfricaGates\Services\{EventArrivals, EventScanPass, EventTicketService, RateLimitService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * A door that keeps working when the line goes down.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A failed fetch stopped the door. The message was honest — "the code was not checked" —
 * and the queue stopped moving, which at a gate is the entire failure. The controller's own
 * header names "a phone on one bar" as the design constraint.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AN OFFLINE GATE RECORDS AND DOES NOT DECIDE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The obvious design is to ship the event's codes to the phone so it can answer for itself.
 * Rejected on the numbers: a ticket code is eight characters from a 29-letter alphabet,
 * about 2^39, which is brute-forceable against a hash list in minutes — so a manifest on a
 * volunteer's phone is the event's whole valid-code set, recoverable, against a door whose
 * standing promise is that it "cannot show you the guest list".
 *
 * A stale list is the worse problem in practice: one fetched at 18:00 does not know about a
 * ticket sold at 19:30, and would refuse a paying attendee CONFIDENTLY on data it had no
 * way to know was old.
 *
 * So the server stays the only thing that decides. These tests hold that, and hold the two
 * things that make recording safe: the scan is not claimed as an admission, and the moment
 * it happened travels with it.
 */
final class DoorOfflineTest extends TestCase
{
    private int $eventId = 0;
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_registrations')->delete();
        try { DB::table('gates_event_checkin_log')->delete(); } catch (\Throwable) {}
        try { DB::table('gates_rate_limits')->delete(); } catch (\Throwable) {}

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Gala', 'slug' => 'off-gala', 'status' => 'published',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
        $this->token = (string) EventScanPass::issue(
            $this->eventId, Carbon::now()->addHours(6)->toDateTimeString(), null, 'Main gate');
    }

    private function ticket(string $code, int $qty = 1, string $status = 'confirmed'): void
    {
        DB::table('gates_event_registrations')->insert([
            'event_id' => $this->eventId, 'tier' => 'Regular', 'name' => 'Ada Obi',
            'email' => strtolower($code) . '@example.test', 'quantity' => $qty,
            'amount_naira' => 5000, 'ticket_code' => $code, 'status' => $status,
            'reference' => 'AFG-EVT-' . strtoupper(substr(md5($code), 0, 12)),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @param list<array<string,string>> $scans */
    private function sync(array $scans): array
    {
        $ctrl = new DoorController($this->twig(), new RateLimitService());
        $req  = (new ServerRequestFactory())
            ->createServerRequest('POST', '/door/' . $this->token . '/sync')
            ->withParsedBody(['scans' => $scans]);
        $res  = $ctrl->sync($req, (new ResponseFactory())->createResponse(), ['token' => $this->token]);

        return [json_decode((string) $res->getBody(), true) ?: [], $res->getStatusCode()];
    }

    private function twig(): Twig
    {
        return Twig::create(dirname(__DIR__, 2) . '/templates', ['cache' => false]);
    }

    private function door(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/door.twig');
    }

    // ══ 1 · the queue gets through ═══════════════════════════════════════════

    public function test_scans_taken_offline_are_admitted_when_the_line_comes_back(): void
    {
        $this->ticket('OFF-0001');
        $this->ticket('OFF-0002');

        [$d] = $this->sync([
            ['id' => 'a', 'code' => 'OFF-0001', 'at' => ''],
            ['id' => 'b', 'code' => 'OFF-0002', 'at' => ''],
        ]);

        $this->assertTrue($d['ok']);
        $this->assertCount(2, $d['results']);
        $this->assertSame(['admit', 'admit'], array_column($d['results'], 'verdict'));
        $this->assertSame(2, EventArrivals::inTheRoom($this->eventId));
    }

    /**
     * Every item answered, by the id it was sent with. A flush reporting only a total
     * would leave a gate guessing which forty of its forty-one it may now forget.
     */
    public function test_each_scan_is_answered_by_its_own_id(): void
    {
        $this->ticket('OFF-0003');

        [$d] = $this->sync([['id' => 'mine', 'code' => 'OFF-0003', 'at' => '']]);

        $this->assertSame('mine', $d['results'][0]['id']);
        $this->assertSame('OFF-0003', $d['results'][0]['code']);
    }

    /** A retried flush must not put the same person through twice. */
    public function test_flushing_the_same_queue_twice_admits_once(): void
    {
        $this->ticket('OFF-0004');
        $scan = [['id' => 'x', 'code' => 'OFF-0004', 'at' => '']];

        [$first]  = $this->sync($scan);
        [$second] = $this->sync($scan);

        $this->assertSame('admit', $first['results'][0]['verdict']);
        $this->assertSame('duplicate', $second['results'][0]['verdict'],
            'a retry admitted the same person a second time');
        $this->assertSame(1, EventArrivals::inTheRoom($this->eventId));
    }

    /** The server still applies every check it always had — offline is not a bypass. */
    public function test_an_unpaid_booking_is_still_refused_on_the_flush(): void
    {
        $this->ticket('OFF-0005', 1, 'pending');

        [$d] = $this->sync([['id' => 'p', 'code' => 'OFF-0005', 'at' => '']]);

        $this->assertSame('refuse', $d['results'][0]['verdict']);
        $this->assertSame(0, EventArrivals::inTheRoom($this->eventId));
    }

    // ══ 2 · the time travels with the scan ═══════════════════════════════════

    /**
     * Without this, forty people go through the arrivals log in the same second half an
     * hour after they walked in — and that log is what an organiser stands behind when an
     * entry is disputed.
     */
    public function test_the_arrivals_log_records_when_it_actually_happened(): void
    {
        $this->ticket('OFF-0006');
        $when = Carbon::now()->subMinutes(25)->toDateTimeString();

        $this->sync([['id' => 't', 'code' => 'OFF-0006', 'at' => $when]]);

        $at = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'OFF-0006')->value('checked_in_at');
        $this->assertSame($when, $at, 'the scan was stamped with the flush time');
    }

    /** A door may admit. It may not write history. */
    public function test_a_future_moment_is_refused(): void
    {
        $this->ticket('OFF-0007');
        $this->sync([['id' => 'f', 'code' => 'OFF-0007',
                      'at' => Carbon::now()->addHours(3)->toDateTimeString()]]);

        $at = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'OFF-0007')->value('checked_in_at');
        $this->assertLessThanOrEqual(Carbon::now()->addMinute()->toDateTimeString(), $at);
    }

    /** And a stamp old enough to be a wrong clock is not worth trusting either. */
    public function test_an_ancient_moment_falls_back_to_now(): void
    {
        $this->ticket('OFF-0008');
        $this->sync([['id' => 'o', 'code' => 'OFF-0008', 'at' => '2019-01-01 00:00:00']]);

        $at = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'OFF-0008')->value('checked_in_at');
        $this->assertStringStartsWith(Carbon::now()->format('Y-m-d'), $at);
    }

    /** The log says these came in late, so the record does not read as a live gate. */
    public function test_the_log_says_the_gate_was_offline(): void
    {
        $this->ticket('OFF-0009');
        $this->sync([['id' => 'l', 'code' => 'OFF-0009', 'at' => '']]);

        $this->assertStringContainsString('offline', (string) EventArrivals::recent($this->eventId)[0]['via']);
    }

    // ══ 3 · bounds ═══════════════════════════════════════════════════════════

    /** An unbounded loop of writes behind a bearer token is a denial of service. */
    public function test_a_flush_is_bounded(): void
    {
        $scans = [];
        for ($i = 0; $i < 260; $i++) $scans[] = ['id' => 'i' . $i, 'code' => 'NOPE-' . $i, 'at' => ''];

        [$d] = $this->sync($scans);

        $this->assertLessThanOrEqual(200, count($d['results']));
    }

    public function test_a_closed_pass_syncs_nothing(): void
    {
        DB::table('gates_event_scan_passes')->where('event_id', $this->eventId)
            ->update(['revoked_at' => Carbon::now()->toDateTimeString()]);
        $this->ticket('OFF-0010');

        [$d, $code] = $this->sync([['id' => 'r', 'code' => 'OFF-0010', 'at' => '']]);

        $this->assertSame(403, $code);
        $this->assertSame(0, EventArrivals::inTheRoom($this->eventId));
    }

    /** Rubbish in the payload must not throw, and must not stop the good items. */
    public function test_a_malformed_item_is_skipped_rather_than_fatal(): void
    {
        $this->ticket('OFF-0011');

        [$d] = $this->sync([
            'not-an-array',
            ['id' => 'blank', 'code' => ''],
            ['id' => 'good', 'code' => 'OFF-0011', 'at' => ''],
        ]);

        $this->assertTrue($d['ok']);
        $this->assertCount(1, $d['results']);
        $this->assertSame('good', $d['results'][0]['id']);
    }

    // ══ 4 · the page never claims more than it knows ═════════════════════════

    /**
     * THE ONE THAT MATTERS. An offline scan is recorded, never admitted. Painting it green
     * would be the screen deciding on the steward's behalf, with no way for either of them
     * to know it had guessed.
     */
    public function test_an_offline_scan_is_not_shown_as_an_admission(): void
    {
        $s = $this->door();
        $at = (int) strpos($s, 'qAdd(code);');
        $this->assertGreaterThan(0, $at, 'nothing is queued when the fetch fails');

        $body = substr($s, $at, 600);
        $this->assertStringContainsString("verdict: 'held'", $body);
        $this->assertStringContainsString('not checked', $body);
        $this->assertStringNotContainsString("verdict: 'admit'", $body,
            'an unverified scan is being painted as an admission');
    }

    /** And it tells the steward what to do, because they are the check now. */
    public function test_the_steward_is_told_they_are_the_check(): void
    {
        $this->assertStringContainsString('Check the ticket yourself', $this->door());
    }

    /**
     * The queue survives the tab dying. A phone at a venue suspends tabs to save memory,
     * and losing fifty admissions to a reload would be worse than no offline mode at all.
     */
    public function test_the_queue_outlives_the_page(): void
    {
        $s = $this->door();

        $this->assertStringContainsString('localStorage.setItem(QKEY', $s);
        $this->assertStringContainsString('localStorage.getItem(QKEY)', $s);
        $this->assertStringContainsString("'ag-door-q:' +", $s,
            'the queue is not scoped to this pass, so two gates on one phone share it');
    }

    /** A store that is full or switched off must not stop the door. */
    public function test_a_broken_store_does_not_stop_the_door(): void
    {
        $s = $this->door();
        $from = (int) strpos($s, 'function qSave()');

        $this->assertStringContainsString('catch (e)', substr($s, $from, 320));
    }

    /**
     * `navigator.onLine` reports the wifi association, not whether anything is reachable
     * through it — a captive portal is "online" and useless. So the recovery has three
     * ways in, one of which is a steward pressing a button.
     */
    public function test_coming_back_does_not_rely_on_the_browser_noticing(): void
    {
        $s = $this->door();

        $this->assertStringContainsString("window.addEventListener('online'", $s);
        $this->assertStringContainsString('setInterval(function () { if (pending.length) flush(true); }', $s);
        $this->assertStringContainsString('id="drFlush"', $s, 'no way to push the queue by hand');
    }

    /** Retiring by id, not by clearing: anything scanned mid-request must survive. */
    public function test_the_flush_retires_only_what_the_server_answered_for(): void
    {
        $s = $this->door();
        $from = (int) strpos($s, 'var done = {};');
        $body = substr($s, $from, 400);

        $this->assertStringContainsString('pending.filter(', $body);
        $this->assertStringNotContainsString('pending = [];', $body,
            'the queue is cleared wholesale, so a scan taken during the flush is lost');
    }
}
