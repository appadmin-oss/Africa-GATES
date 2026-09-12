<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventScanPass;
use AfricaGates\Support\{DisplayTime, EventTime};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The clock in the room, which is not the clock on the settings screen.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY AN EVENT NEEDS ITS OWN ZONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see DisplayTime} holds ONE zone for the whole platform, and that is right for a
 * deadline: a cycle closes at a moment announced once, and every nominee is measured
 * against the same instant.
 *
 * An event is not a deadline. It is a room, in a city, at a wall-clock time. A platform
 * that calls itself continental cannot print a Nairobi gala's start in Lagos hours because
 * that is where its settings screen points — the guest reads "19:00", arrives at 19:00, and
 * is an hour late to a ceremony held for them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE BUG UNDER IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The door printed its window with `|slice(11, 5)` over the stored string. Storage is UTC
 * by this application's convention, so a gate closing at 23:00 in Lagos told the steward
 * holding the phone that it closed at 22:00. Slicing a datetime is not formatting it — it
 * reads the storage convention out loud.
 */
final class EventTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DisplayTime::forget();
    }

    protected function tearDown(): void
    {
        DisplayTime::forget();
        parent::tearDown();
    }

    /** @param array<string,mixed> $over */
    private function event(array $over = []): object
    {
        return (object) ($over + ['id' => 1, 'timezone' => null]);
    }

    // ══ 1 · the fallback is the migration ════════════════════════════════════

    /**
     * An event with no zone reads exactly as it did before the column existed. That is
     * the whole reason nothing was backfilled: a backfill would have to GUESS, and
     * guessing a timezone onto a past event rewrites what its tickets said.
     */
    public function test_an_event_with_no_zone_uses_the_platforms(): void
    {
        $this->assertSame(DisplayTime::zone(), EventTime::zone($this->event()));
        $this->assertSame(
            DisplayTime::show('2026-06-10 18:00:00', 'H:i'),
            EventTime::at($this->event(), '2026-06-10 18:00:00', 'H:i'));
    }

    /** Rubbish in the column must not take every date on the event's pages down with it. */
    public function test_an_impossible_zone_falls_back_rather_than_throwing(): void
    {
        $e = $this->event(['timezone' => 'Middle/Earth']);

        $this->assertSame(DisplayTime::zone(), EventTime::zone($e));
        $this->assertNotSame('', EventTime::at($e, '2026-06-10 18:00:00', 'H:i'));
    }

    // ══ 2 · the room's own clock ═════════════════════════════════════════════

    /** THE ONE THAT MATTERS. 18:00 UTC is 19:00 in Lagos and 21:00 in Nairobi. */
    public function test_the_same_instant_reads_differently_in_two_cities(): void
    {
        $stored = '2026-06-10 18:00:00';

        $this->assertSame('19:00', EventTime::at($this->event(['timezone' => 'Africa/Lagos']), $stored, 'H:i'));
        $this->assertSame('21:00', EventTime::at($this->event(['timezone' => 'Africa/Nairobi']), $stored, 'H:i'));
    }

    /**
     * And what an organiser TYPES is read as the venue's clock. This is the half that is
     * easy to forget: setting a Nairobi gala to 19:00 means 19:00 in Nairobi, and reading
     * it in the platform's zone starts the evening two hours out for everybody with a ticket.
     */
    public function test_a_typed_time_means_the_venues_clock(): void
    {
        $nairobi = $this->event(['timezone' => 'Africa/Nairobi']);

        $this->assertSame('2026-06-10 16:00:00', EventTime::toStored($nairobi, '2026-06-10 19:00:00'));
        // And it round-trips: what was typed is what is shown back.
        $this->assertSame('19:00', EventTime::at($nairobi, '2026-06-10 16:00:00', 'H:i'));
    }

    /** The form must show back exactly what was saved, or a save with no edit shifts it. */
    public function test_the_admin_form_round_trips_without_drift(): void
    {
        $e = $this->event(['timezone' => 'Africa/Nairobi']);
        $stored = EventTime::toStored($e, '2026-06-10 19:30:45');

        $this->assertSame('2026-06-10T19:30:45', EventTime::forInput($e, $stored));
        $this->assertSame($stored, EventTime::toStored($e, EventTime::forInput($e, $stored)));
    }

    // ══ 3 · the label never comes from anywhere else ═════════════════════════

    /**
     * The event page printed the time from one source and the letters "WAT" typed by hand
     * beside it — correct only while nobody changes the platform zone and no event is held
     * outside Lagos. The moment either stops holding it states a wrong hour with a
     * confident label, which is worse than an unlabelled one.
     */
    public function test_the_zone_letters_come_from_the_event(): void
    {
        $this->assertSame('WAT', EventTime::abbr($this->event(['timezone' => 'Africa/Lagos'])));
        $this->assertSame('EAT', EventTime::abbr($this->event(['timezone' => 'Africa/Nairobi'])));
    }

    /** And they are read at the EVENT's moment, so a summer offset is not January's. */
    public function test_the_letters_are_read_at_the_events_own_date(): void
    {
        $e = $this->event(['timezone' => 'Europe/London']);

        $this->assertSame('GMT', EventTime::abbr($e, '2026-01-15 12:00:00'));
        $this->assertSame('BST', EventTime::abbr($e, '2026-07-15 12:00:00'));
    }

    public function test_the_time_and_its_zone_arrive_together(): void
    {
        $out = EventTime::zoned($this->event(['timezone' => 'Africa/Nairobi']), '2026-06-10 18:00:00', 'H:i');

        $this->assertSame('21:00 EAT', $out);
    }

    /** A column nobody filled in is blank, not 1 January 1970. */
    public function test_an_empty_datetime_shows_as_nothing(): void
    {
        foreach (['', null, '0000-00-00 00:00:00'] as $empty) {
            $this->assertSame('', EventTime::at($this->event(), $empty));
            $this->assertSame('', EventTime::zoned($this->event(), $empty));
        }
    }

    /** For a screen deciding whether an explanation is worth the room it takes. */
    public function test_it_knows_when_an_event_is_somewhere_else(): void
    {
        $this->assertFalse(EventTime::elsewhere($this->event()));
        $this->assertTrue(EventTime::elsewhere($this->event(['timezone' => 'Asia/Dubai'])));
    }

    // ══ 4 · the door tells the truth about its window ════════════════════════

    /**
     * The bug this closes. `|slice(11, 5)` over a stored string reads UTC, so a gate
     * closing at 23:00 in Lagos told its steward 22:00.
     */
    public function test_the_door_no_longer_slices_the_stored_string(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/door.twig');

        $this->assertStringNotContainsString("closes_at|slice", $s,
            'the door is reading the UTC storage convention out loud again');
        $this->assertStringNotContainsString("opens_at|slice", $s);
    }

    /** And what it shows is the venue's clock, formatted before it reaches the page. */
    public function test_the_doors_window_is_shown_in_the_events_zone(): void
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Nairobi gala', 'slug' => 'tz-gala', 'status' => 'published',
            'timezone' => 'Africa/Nairobi',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
        // Stored UTC — 20:00 UTC is 23:00 in Nairobi.
        $token = EventScanPass::issue($id, '2026-06-10 20:00:00', null, 'Main gate');
        $this->assertNotNull($token);

        $pass = DB::table('gates_event_scan_passes')->where('event_id', $id)->first();
        $ev   = DB::table('gates_site_events')->where('id', $id)->first();

        $this->assertSame('23:00', EventTime::at($ev, (string) $pass->closes_at, 'H:i'),
            'the steward is being told a closing time an hour or three out');
    }

    // ══ 5 · the platform zone still governs a deadline ═══════════════════════

    /**
     * An event's zone must NOT leak into anything platform-wide. A cycle closes at one
     * instant for the whole continent, and making that relative to whichever event a
     * reader last looked at would be the opposite of a deadline.
     */
    public function test_an_events_zone_does_not_move_the_platforms(): void
    {
        $before = DisplayTime::zone();
        EventTime::at($this->event(['timezone' => 'Asia/Dubai']), '2026-06-10 18:00:00');

        $this->assertSame($before, DisplayTime::zone());
        $this->assertSame(DisplayTime::show('2026-06-10 18:00:00', 'H:i'),
                          DisplayTime::show('2026-06-10 18:00:00', 'H:i'));
    }

    /** Storage does not move. This is a second display edge, not a second convention. */
    public function test_storage_stays_utc(): void
    {
        $this->assertSame('2026-06-10 16:00:00',
            EventTime::toStored($this->event(['timezone' => 'Africa/Nairobi']), '2026-06-10 19:00:00'));
        $this->assertSame('UTC', date_default_timezone_get(),
            'the process clock moved, which reinterprets every stored row by the offset');
    }
}
