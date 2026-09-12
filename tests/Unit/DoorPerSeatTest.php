<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\DoorController;
use AfricaGates\Services\{EventArrivals, EventScanPass, EventTicketService, RateLimitService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\{ResponseFactory, ServerRequestFactory};
use Slim\Views\Twig;
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

    /**
     * The scan pass and the endpoint, so these are answers the DOOR gave rather than
     * strings in its template.
     */
    private function scan(string $code, int $seats = 0): array
    {
        $token = (string) EventScanPass::issue(
            $this->eventId, Carbon::now()->addHours(6)->toDateTimeString(), null, 'Main gate');

        $ctrl = new DoorController(
            Twig::create(dirname(__DIR__, 2) . '/templates', ['cache' => false]),
            new RateLimitService());

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/door/' . $token . '/check')
            ->withParsedBody(['code' => $code, 'seats' => $seats]);

        $res = $ctrl->check($req, (new ResponseFactory())->createResponse(), ['token' => $token]);

        return json_decode((string) $res->getBody(), true) ?: [];
    }

    /** A single-seat ticket — most of them — must cost no extra tap. */
    public function test_the_picker_is_not_offered_for_a_one_seat_ticket(): void
    {
        $this->ticket('SOLO-1', 1);

        $v = $this->scan('SOLO-1');

        $this->assertSame('admit', $v['verdict'],
            'a one-seat ticket is being asked how many of it are present');
        $this->assertSame(1, $v['admitted_now']);
    }

    /**
     * THE ONE THAT MATTERS NOW. A party is asked about BEFORE anybody is admitted.
     *
     * The door used to admit one seat on the first scan and then offer to add more, so a
     * steward who scanned a table of four and turned away to talk had admitted one person
     * and recorded three as still to come — with the undo as the only way back, and no
     * reason to think they needed it.
     */
    public function test_a_party_is_asked_about_before_anybody_is_admitted(): void
    {
        $this->ticket('SEAT-4', 4);

        $v = $this->scan('SEAT-4');

        $this->assertSame('ask', $v['verdict'], 'a four-seat ticket admitted somebody unasked');
        $this->assertSame(4, $v['seats']);
        $this->assertSame(4, $v['seats_left']);
        $this->assertSame(0, $v['admitted_now']);

        // And NOTHING was written. That is the whole claim.
        $this->assertSame(0, $this->seatsIn('SEAT-4'),
            'the question was asked and a seat was taken anyway');
    }

    /** Answering it admits exactly what was chosen. */
    public function test_answering_the_question_admits_that_many(): void
    {
        $this->ticket('SEAT-4', 4);
        $this->scan('SEAT-4');

        $v = $this->scan('SEAT-4', 2);

        $this->assertSame('admit', $v['verdict']);
        $this->assertSame(2, $v['admitted_now']);
        $this->assertSame(2, $this->seatsIn('SEAT-4'));
    }

    /**
     * And it IS asked again on a partly-used ticket — the case that used to be a dead end,
     * where a steward was told "already checked in" about a ticket with two people still
     * to come and had to decide on their own authority.
     */
    public function test_the_question_is_asked_again_on_a_partly_used_ticket(): void
    {
        $this->ticket('SEAT-4', 4);
        $this->admit('SEAT-4', 1);

        $v = $this->scan('SEAT-4');

        $this->assertSame('ask', $v['verdict'], 'a partly-used ticket is a dead end again');
        $this->assertSame(3, $v['seats_left']);
    }

    /** With one seat left there is nothing to ask, so it just admits. */
    public function test_the_last_seat_is_not_worth_a_question(): void
    {
        $this->ticket('SEAT-2', 2);
        $this->admit('SEAT-2', 1);

        $v = $this->scan('SEAT-2');

        $this->assertSame('admit', $v['verdict'],
            'the door asked how many when only one seat could possibly be meant');
        $this->assertSame(2, $this->seatsIn('SEAT-2'));
    }

    /**
     * A fully-used ticket is a duplicate, not a question.
     *
     * And the door must not become an oracle on the way: an unknown code and a code from
     * another event get the same refusal, so a stranger cannot learn which namespace they
     * missed.
     */
    public function test_a_used_ticket_is_a_duplicate_and_an_unknown_one_is_refused(): void
    {
        $this->ticket('SEAT-1', 1);
        $this->admit('SEAT-1', 1);

        $this->assertSame('duplicate', $this->scan('SEAT-1')['verdict']);
        $this->assertSame('refuse', $this->scan('NOPE-9999')['verdict']);
    }
}
