<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EventArrivals, EventTicketService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A group ticket arriving in more than one piece.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE CASE THIS COULD NOT HANDLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Ada buys four seats. Two of her party arrive at seven, two more at half past eight.
 * `checked_in_at` was one nullable timestamp, so the first scan took the whole ticket and
 * the second said "Already checked in", leaving a steward to let two more of the same
 * party past on a ticket the screen had just called used. The verdict even printed "4
 * seats on this ticket" while having no way to split them — the system describing a
 * problem it declined to solve.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO THAT WOULD BE SILENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The headcount moved from "sum quantity where admitted" to "sum seats admitted", so
 * every already-admitted row needed a backfill or every past event's headcount became
 * zero — on the number closest to a fire-safety figure.
 *
 * And the race moved from "exactly one scanner wins" to a bounded increment: two gates
 * admitting two seats each off a four-seat ticket are BOTH correct, but two gates each
 * reading "2 left" and each taking 2 is an over-admission a read-then-write would allow.
 */
final class DoorPerSeatTest extends TestCase
{
    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_registrations')->delete();
        try { DB::table('gates_event_checkin_log')->delete(); } catch (\Throwable) {}

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Gala', 'slug' => 'seat-gala', 'status' => 'published',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
    }

    private function ticket(string $code, int $qty): void
    {
        DB::table('gates_event_registrations')->insert([
            'event_id' => $this->eventId, 'tier' => 'Table', 'name' => 'Ada Obi',
            'email' => strtolower($code) . '@example.test', 'quantity' => $qty,
            'amount_naira' => 5000 * $qty, 'ticket_code' => $code, 'status' => 'confirmed',
            'reference' => 'AFG-EVT-' . strtoupper(substr(md5($code), 0, 12)),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function seatsIn(string $code): int
    {
        return (int) DB::table('gates_event_registrations')
            ->where('ticket_code', $code)->value('checked_in_seats');
    }

    private function admit(string $code, int $want = 0): array
    {
        return EventTicketService::checkIn($code, $this->eventId, 'door: Main gate', null, '', $want);
    }

    // ══ 1 · the split ════════════════════════════════════════════════════════

    /** THE ONE THAT MATTERS. Two now, two later, both admitted, on one ticket. */
    public function test_a_party_can_arrive_in_two_halves(): void
    {
        $this->ticket('SEAT-4', 4);

        $first = $this->admit('SEAT-4', 2);
        $this->assertSame('admit', $first['verdict']);
        $this->assertSame(2, $first['admitted_now']);
        $this->assertSame(2, $first['seats_left']);

        $second = $this->admit('SEAT-4', 2);
        $this->assertSame('admit', $second['verdict'],
            'the second half of the party was turned away as a duplicate');
        $this->assertSame(4, $second['seats_in']);
        $this->assertSame(0, $second['seats_left']);
    }

    /** Only once they are all in is it a duplicate. */
    public function test_the_ticket_is_used_up_only_when_every_seat_is_in(): void
    {
        $this->ticket('SEAT-3', 3);
        $this->admit('SEAT-3', 3);

        $again = $this->admit('SEAT-3');

        $this->assertSame('duplicate', $again['verdict']);
        $this->assertSame(0, $again['seats_left']);
        $this->assertSame(3, $this->seatsIn('SEAT-3'));
    }

    /** A single-seat ticket behaves exactly as it always did — no extra step, no change. */
    public function test_a_one_seat_ticket_is_unchanged(): void
    {
        $this->ticket('SEAT-1', 1);

        $this->assertSame('admit', $this->admit('SEAT-1')['verdict']);
        $this->assertSame('duplicate', $this->admit('SEAT-1')['verdict']);
    }

    /** No count means everyone still to come, which is what most scans mean. */
    public function test_asking_for_nothing_admits_everyone_left(): void
    {
        $this->ticket('SEAT-5', 5);
        $this->admit('SEAT-5', 2);

        $rest = $this->admit('SEAT-5');

        $this->assertSame(3, $rest['admitted_now']);
        $this->assertSame(5, $this->seatsIn('SEAT-5'));
    }

    /** A steward asking for more than the ticket holds gets the ticket, not the number. */
    public function test_asking_for_more_than_remain_admits_only_what_remains(): void
    {
        $this->ticket('SEAT-2', 2);

        $r = $this->admit('SEAT-2', 99);

        $this->assertSame(2, $r['admitted_now']);
        $this->assertSame(2, $this->seatsIn('SEAT-2'),
            'a ticket admitted more people than it was sold');
    }

    // ══ 2 · the race ═════════════════════════════════════════════════════════

    /**
     * The ceiling lives in the UPDATE's own predicate, evaluated by the database together
     * with the increment.
     *
     * ── WHY THIS IS ASSERTED AGAINST THE STATEMENT AND NOT THROUGH checkIn() ──
     *
     * checkIn() ALSO clamps in PHP, from the row it read at the top. Sequential calls
     * therefore never exercise the SQL guard — each one re-reads and clamps correctly, and
     * removing the predicate entirely leaves every other test in this file green. I know
     * because I tried it.
     *
     * The predicate only earns its place under genuine interleaving: two gates that both
     * read "2 left" and both try to take 2. A single-process test cannot produce that, so
     * the guard is exercised where it actually lives — with a $take deliberately stale, as
     * a racing request's would be.
     */
    public function test_the_database_refuses_an_overflowing_increment(): void
    {
        $this->ticket('SEAT-RACE', 4);
        $this->admit('SEAT-RACE', 3);

        // What a second gate's UPDATE would look like if it had read the row before that
        // admission landed: it still believes it may take 3.
        $affected = DB::table('gates_event_registrations')
            ->where('ticket_code', 'SEAT-RACE')
            ->whereRaw('COALESCE(checked_in_seats, 0) + ? <= COALESCE(quantity, 1)', [3])
            ->update(['checked_in_seats' => DB::raw('COALESCE(checked_in_seats, 0) + 3')]);

        $this->assertSame(0, $affected,
            'a stale request could push a four-seat ticket to six');
        $this->assertSame(3, $this->seatsIn('SEAT-RACE'));
    }

    /** And the statement checkIn() runs really does carry that predicate. */
    public function test_the_admission_statement_carries_its_ceiling(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/EventTicketService.php');
        $from = (int) strpos($src, 'THE RACE, WHICH IS NOW AN INCREMENT');
        $body = substr($src, $from, 1800);

        $this->assertStringContainsString(
            "whereRaw('COALESCE(checked_in_seats, 0) + ? <= COALESCE(quantity, 1)'", $body,
            'the ceiling left the predicate, so it is enforced only by the PHP clamp — which '
            . 'a racing request computes from a row it read before the other gate wrote');
    }

    /** Two gates in sequence still land on the right total. */
    public function test_two_gates_cannot_between_them_admit_more_than_the_ticket_holds(): void
    {
        $this->ticket('SEAT-R', 4);

        $a = $this->admit('SEAT-R', 3);
        $b = $this->admit('SEAT-R', 3);

        $this->assertSame('admit', $a['verdict']);
        $this->assertSame(3, $a['admitted_now']);
        // The second is clamped to what is left rather than refused: one more of the party
        // really is standing there, and turning them away would be the wrong answer.
        $this->assertSame(1, $b['admitted_now']);
        $this->assertSame(4, $this->seatsIn('SEAT-R'));
    }

    // ══ 3 · the numbers everything else reads ════════════════════════════════

    /** Two of four in the room is two people, not four. */
    public function test_the_headcount_counts_seats_and_not_tickets(): void
    {
        $this->ticket('SEAT-H', 4);
        $this->admit('SEAT-H', 2);

        $this->assertSame(2, EventArrivals::inTheRoom($this->eventId),
            'the room counted two people who have not arrived yet');
    }

    /** A log that recorded four each time two walked in would count eight into a room of four. */
    public function test_the_log_records_the_seats_admitted_that_time(): void
    {
        $this->ticket('SEAT-L', 4);
        $this->admit('SEAT-L', 1);
        $this->admit('SEAT-L', 3);

        $log = EventArrivals::recent($this->eventId);

        $this->assertCount(2, $log);
        $this->assertSame([3, 1], array_map('intval', array_column($log, 'seats')));
    }

    /**
     * The first arrival's moment, never the latest. Four people arriving across an evening
     * have one ticket and one arrival time that means anything — the one an organiser is
     * asked about — and the log holds the rest.
     */
    public function test_the_stamp_is_the_first_arrival(): void
    {
        $this->ticket('SEAT-T', 4);
        $this->admit('SEAT-T', 2);
        $first = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'SEAT-T')->value('checked_in_at');

        $this->admit('SEAT-T', 2);

        $this->assertSame($first, (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'SEAT-T')->value('checked_in_at'));
    }

    // ══ 4 · taking it back ═══════════════════════════════════════════════════

    /**
     * A reversal takes back the WHOLE ticket, including a group that arrived in pieces.
     * "Not them" means this booking was never admitted, and leaving three of four seats
     * standing would be a half-used ticket with nothing recording which half.
     */
    public function test_a_reversal_gives_every_seat_back(): void
    {
        $this->ticket('SEAT-U', 4);
        $this->admit('SEAT-U', 2);
        $this->admit('SEAT-U', 1);
        $this->assertSame(3, EventArrivals::inTheRoom($this->eventId));

        EventTicketService::undoCheckIn('SEAT-U', $this->eventId, 'door', null, 'wrong table');

        $this->assertSame(0, $this->seatsIn('SEAT-U'));
        $this->assertSame(0, EventArrivals::inTheRoom($this->eventId));
        $this->assertSame('admit', $this->admit('SEAT-U', 4)['verdict'],
            'the real party could not be admitted after the reversal');
    }

    // ══ 5 · the backfill ═════════════════════════════════════════════════════

    /**
     * THE SILENT ONE. A row admitted before this column existed carries 0 in it, and the
     * headcount now sums that column — so without the migration's backfill every past
     * event's headcount becomes zero, on the number closest to a fire-safety figure.
     *
     * The reader carries the same belt as the migration's brace, which is what this holds:
     * a database restored from a backup taken between the two statements must still count.
     */
    public function test_a_row_admitted_before_this_column_still_counts(): void
    {
        $this->ticket('SEAT-OLD', 3);
        DB::table('gates_event_registrations')->where('ticket_code', 'SEAT-OLD')->update([
            'checked_in_at'    => Carbon::now()->subHour()->toDateTimeString(),
            'checked_in_seats' => 0,
        ]);

        $this->assertSame(3, EventArrivals::inTheRoom($this->eventId),
            'every event held before this change reads as an empty room');
    }

    // ══ 6 · the door only asks when there is something to ask ════════════════

    /** A single-seat ticket — most of them — must cost no extra tap. */
    public function test_the_picker_is_not_offered_for_a_one_seat_ticket(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/door.twig');
        $from = (int) strpos($s, 'function offerParty(v)');
        $body = substr($s, $from, 700);

        $this->assertStringContainsString("Number(v.seats || 0) < 2", $body,
            'a one-seat ticket is being asked how many of it are present');
        $this->assertStringContainsString('left < 1', $body,
            'a fully admitted ticket still offers a split');
    }

    /**
     * And it IS offered after a duplicate that has seats left — the case that used to be a
     * dead end, where a steward was told "already checked in" about a ticket with two
     * people still to come and had to decide on their own authority.
     */
    public function test_the_picker_is_offered_on_a_partly_used_ticket(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/door.twig');
        $from = (int) strpos($s, 'function offerParty(v)');

        $this->assertStringNotContainsString("v.verdict === 'admit'", substr($s, $from, 700),
            'the split is gated on the verdict, so a partly-used ticket is a dead end again');
    }
}
