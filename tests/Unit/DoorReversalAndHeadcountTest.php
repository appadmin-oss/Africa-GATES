<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EventArrivals, EventRefundPolicy, EventScanPass,
                         EventTicketService, InvitePass, TicketSelfService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Taking an admission back, and counting everybody who is actually in the room.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO FAULTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * **A check-in was terminal.** `checked_in_at` was written by the door and cleared by
 * nothing, anywhere — and four things gate on it: the door's duplicate check, a rename, a
 * transfer, and {@see EventRefundPolicy}, which refuses a refund to anybody marked admitted.
 * So a camera catching the ticket of the person behind in the queue turned that attendee
 * away at the door, stopped them handing the ticket to somebody who could use it, AND kept
 * their money, with no route to reverse it on a host with no shell. The refund is the one
 * that makes this a four rather than a three.
 *
 * **The headcount omitted every guest of honour.** The door summed registrations alone, and
 * a nominee admitted on an invitation has no registration row by design — a complimentary
 * ticket would have counted as a sale and stopped the hall selling. So the number a steward
 * reads to judge the room excluded every nominee and judge in it.
 */
final class DoorReversalAndHeadcountTest extends TestCase
{
    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_registrations')->delete();
        try { DB::table('gates_event_checkin_log')->delete(); } catch (\Throwable) {}
        try { DB::table('gates_event_invites')->delete(); } catch (\Throwable) {}

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Gala', 'slug' => 'rev-gala', 'status' => 'published',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
    }

    private function ticket(string $code, int $qty = 1, string $status = 'confirmed'): int
    {
        return (int) DB::table('gates_event_registrations')->insertGetId([
            'event_id' => $this->eventId, 'tier' => 'Regular',
            'name' => 'Ada Obi', 'email' => strtolower($code) . '@example.test',
            'quantity' => $qty, 'amount_naira' => 5000,
            'reference' => 'AFG-EVT-' . strtoupper(substr(md5($code), 0, 12)),
            'ticket_code' => $code, 'status' => $status,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** A guest of honour: an invitation, never a registration row. */
    private function invite(string $ref, bool $scanned, bool $sent = true): int
    {
        return (int) DB::table('gates_event_invites')->insertGetId([
            'event_id' => $this->eventId, 'audience' => 'nominee',
            'name' => 'Chidi Okeke', 'email' => strtolower($ref) . '@example.test',
            'reference' => $ref, 'id_secret' => InvitePass::secret(),
            'discount_code' => $ref, 'guest_quota' => 25,
            'scans' => $scanned ? 1 : 0,
            'sent_at' => $sent ? Carbon::now()->toDateTimeString() : null,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function checkedInAt(string $code): ?string
    {
        $v = DB::table('gates_event_registrations')->where('ticket_code', $code)->value('checked_in_at');

        return $v === null ? null : (string) $v;
    }

    // ══ 1 · the reversal ═════════════════════════════════════════════════════

    /** THE ONE THAT MATTERS. A mis-scan can be taken back, on the spot. */
    public function test_an_admission_can_be_reversed(): void
    {
        $this->ticket('WRONG-1');
        EventTicketService::checkIn('WRONG-1', $this->eventId, 'door: Main gate');
        $this->assertNotNull($this->checkedInAt('WRONG-1'));

        $r = EventTicketService::undoCheckIn('WRONG-1', $this->eventId, 'door: Main gate',
                                             null, 'scanned in error at the door');

        $this->assertTrue($r['ok'], (string) $r['detail']);
        $this->assertNull($this->checkedInAt('WRONG-1'),
            'the ticket is still marked admitted, so its holder is still locked out');
    }

    /** And the door lets them in again afterwards, as an ordinary admit rather than a duplicate. */
    public function test_the_real_holder_is_admitted_normally_after_a_reversal(): void
    {
        $this->ticket('WRONG-2');
        EventTicketService::checkIn('WRONG-2', $this->eventId, 'door');
        EventTicketService::undoCheckIn('WRONG-2', $this->eventId, 'door', null, 'wrong person');

        $v = EventTicketService::checkIn('WRONG-2', $this->eventId, 'door');

        $this->assertSame('admit', $v['verdict'],
            'the person who actually holds this ticket was turned away as a duplicate');
    }

    /**
     * THE EXPENSIVE HALF. A mis-scan used to keep their money: EventRefundPolicy refuses
     * anybody marked admitted, and nothing could clear the mark.
     */
    public function test_a_reversal_gives_the_refund_back(): void
    {
        // self_cancel on, or quote() stops at 'off' and this test proves nothing — which is
        // what the guard assertion below is for.
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['self_cancel' => 1]);
        $this->ticket('WRONG-3');
        EventTicketService::checkIn('WRONG-3', $this->eventId, 'door');
        $reg = DB::table('gates_event_registrations')->where('ticket_code', 'WRONG-3')->first();
        $this->assertSame('checked_in', EventRefundPolicy::quote($reg)['reason'] ?? '',
            'this test is asserting against the wrong gate');

        EventTicketService::undoCheckIn('WRONG-3', $this->eventId, 'door', null, 'wrong person');

        $fresh = DB::table('gates_event_registrations')->where('ticket_code', 'WRONG-3')->first();
        $this->assertNotSame('checked_in', EventRefundPolicy::quote($fresh)['reason'] ?? '',
            'somebody who never got into the event still cannot be refunded');
    }

    /** A transfer becomes possible again too — the other thing check-in was blocking. */
    public function test_a_reversal_lets_the_ticket_be_handed_on(): void
    {
        $id = $this->ticket('WRONG-4');
        EventTicketService::checkIn('WRONG-4', $this->eventId, 'door');
        EventTicketService::undoCheckIn('WRONG-4', $this->eventId, 'door', null, 'wrong person');

        $reg = DB::table('gates_event_registrations')->where('id', $id)->first();
        $this->assertNull($reg->checked_in_at,
            'TicketSelfService refuses a transfer on exactly this column');
    }

    /** A reversal with no stated reason cannot be told from removing somebody quietly. */
    public function test_a_reason_is_required(): void
    {
        $this->ticket('WRONG-5');
        EventTicketService::checkIn('WRONG-5', $this->eventId, 'door');

        $r = EventTicketService::undoCheckIn('WRONG-5', $this->eventId, 'door', null, '   ');

        $this->assertFalse($r['ok']);
        $this->assertSame('NO_REASON', $r['code']);
        $this->assertNotNull($this->checkedInAt('WRONG-5'), 'it reversed anyway');
    }

    public function test_reversing_something_never_admitted_is_refused_honestly(): void
    {
        $this->ticket('WRONG-6');

        $r = EventTicketService::undoCheckIn('WRONG-6', $this->eventId, 'door', null, 'oops');

        $this->assertFalse($r['ok']);
        $this->assertSame('NOT_IN', $r['code']);
    }

    /** Same non-answer the admission path gives — the door must not become an oracle. */
    public function test_a_code_for_another_event_is_indistinguishable_from_no_code(): void
    {
        $other = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Other', 'slug' => 'rev-other', 'status' => 'published',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
        $this->ticket('WRONG-7');
        EventTicketService::checkIn('WRONG-7', $this->eventId, 'door');

        $r = EventTicketService::undoCheckIn('WRONG-7', $other, 'door', null, 'oops');

        $this->assertFalse($r['ok']);
        $this->assertSame('NOT_HERE', $r['code']);
        $this->assertNotNull($this->checkedInAt('WRONG-7'));
    }

    /** Two stewards pressing undo produce one reversal and one honest answer. */
    public function test_a_second_reversal_does_not_write_a_second_log_row(): void
    {
        $this->ticket('WRONG-8');
        EventTicketService::checkIn('WRONG-8', $this->eventId, 'door');

        EventTicketService::undoCheckIn('WRONG-8', $this->eventId, 'door', null, 'wrong person');
        $r = EventTicketService::undoCheckIn('WRONG-8', $this->eventId, 'door', null, 'wrong person');

        $this->assertFalse($r['ok']);
        $this->assertSame(1, DB::table('gates_event_checkin_log')
            ->where('action', 'undo')->count());
    }

    // ══ 2 · the log ══════════════════════════════════════════════════════════

    /**
     * A reversal APPENDS. Setting the column back to NULL and stopping there would erase
     * that somebody was scanned in at 19:42 and un-scanned at 19:43 — which is exactly what
     * gets asked about afterwards.
     */
    public function test_the_log_keeps_both_the_admission_and_the_reversal(): void
    {
        $this->ticket('LOG-1');
        EventTicketService::checkIn('LOG-1', $this->eventId, 'door: Main gate');
        EventTicketService::undoCheckIn('LOG-1', $this->eventId, 'door: Main gate', null, 'wrong person');

        $log = EventArrivals::recent($this->eventId);

        $this->assertCount(2, $log, 'the admission was erased rather than corrected');
        $this->assertSame('undo', $log[0]['action']);
        $this->assertSame('wrong person', $log[0]['reason']);
        $this->assertSame('admit', $log[1]['action']);
        $this->assertSame('door: Main gate', $log[1]['via'],
            'which gate admitted somebody is the question asked after a disputed entry');
    }

    /** A re-scan is ordinary and must not appear twice in the record of who came through. */
    public function test_a_duplicate_scan_is_not_logged_as_a_second_arrival(): void
    {
        $this->ticket('LOG-2');
        EventTicketService::checkIn('LOG-2', $this->eventId, 'door');
        EventTicketService::checkIn('LOG-2', $this->eventId, 'door');

        $this->assertCount(1, EventArrivals::recent($this->eventId));
    }

    /** Seats as they were. A later transfer must not rewrite last night's door. */
    public function test_the_log_keeps_the_seat_count_it_admitted(): void
    {
        $id = $this->ticket('LOG-3', 4);
        EventTicketService::checkIn('LOG-3', $this->eventId, 'door');
        DB::table('gates_event_registrations')->where('id', $id)->update(['quantity' => 1]);

        $this->assertSame(4, (int) EventArrivals::recent($this->eventId)[0]['seats']);
    }

    // ══ 3 · the headcount ════════════════════════════════════════════════════

    /** THE OTHER ONE THAT MATTERS. Guests of honour are people in the room. */
    public function test_the_headcount_includes_guests_of_honour(): void
    {
        $this->ticket('ROOM-1', 2);
        EventTicketService::checkIn('ROOM-1', $this->eventId, 'door');
        $this->invite('AGI-AAAA1111', true);
        $this->invite('AGI-BBBB2222', true);
        $this->invite('AGI-CCCC3333', false);   // invited, not yet arrived

        $this->assertSame(4, EventArrivals::inTheRoom($this->eventId),
            'two seats on a ticket plus two nominees who have walked in');
    }

    public function test_the_expected_count_includes_invitations_that_were_sent(): void
    {
        $this->ticket('ROOM-2', 3);
        $this->invite('AGI-DDDD4444', false, true);
        $this->invite('AGI-EEEE5555', false, false);   // minted, never sent

        $this->assertSame(4, EventArrivals::expected($this->eventId),
            'a hall nobody was invited to should not be counted as expecting them');
    }

    /** The sandbox writes real rows with real flags. It must never reach a real headcount. */
    public function test_a_sample_invitation_is_not_a_person_in_the_room(): void
    {
        $this->invite('AGI-SAMPLE0', true);

        $this->assertSame(0, EventArrivals::inTheRoom($this->eventId));
        $this->assertSame(0, EventArrivals::expected($this->eventId));
    }

    /** The split, so a screen can say which door the people came through. */
    public function test_the_summary_names_both_halves(): void
    {
        $this->ticket('ROOM-3', 2);
        EventTicketService::checkIn('ROOM-3', $this->eventId, 'door');
        $this->invite('AGI-FFFF6666', true);

        $s = EventArrivals::summary($this->eventId);

        $this->assertSame(3, $s['in']);
        $this->assertSame(2, $s['tickets_in']);
        $this->assertSame(1, $s['honoured_in']);
    }

    /** A reversal takes the seats back out of the room. */
    public function test_a_reversal_lowers_the_headcount(): void
    {
        $this->ticket('ROOM-4', 2);
        EventTicketService::checkIn('ROOM-4', $this->eventId, 'door');
        $this->assertSame(2, EventArrivals::inTheRoom($this->eventId));

        EventTicketService::undoCheckIn('ROOM-4', $this->eventId, 'door', null, 'wrong person');

        $this->assertSame(0, EventArrivals::inTheRoom($this->eventId),
            'the room still counts somebody who was never in it');
    }
}
