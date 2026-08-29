<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CheckInThanks;
use AfricaGates\Services\EventTicketService;
use AfricaGates\Services\SmsOptOut;
use AfricaGates\Services\SmsService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The text somebody gets for walking in.
 *
 * ── WHAT THESE HOLD ──────────────────────────────────────────────────────────
 *
 * Almost every rule here exists because of where this fires: a person standing at a door
 * with a queue behind them. Nothing about a thank-you may change whether somebody who has
 * paid gets through, and nothing about it may make a steward wait.
 *
 * The other half is consent. This is the first message this platform sends that nobody
 * individually asked for — they bought a ticket and walked through a door — so it is the
 * first one that can be wrong to send at all.
 */
final class CheckInThanksTest extends TestCase
{
    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_settings')->where('key_name', 'like', 'checkin_sms%')->delete();
        DB::table('gates_opportunities')->delete();

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-' . bin2hex(random_bytes(3)), 'title' => 'Africa GATES Gala 2026',
            'event_date' => '2026-12-12 18:00:00', 'status' => 'published',
            'venue' => 'Eko Convention Centre', 'location' => 'Lagos',
        ]);
    }

    private function on(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => CheckInThanks::K_ENABLED], ['value' => '1']
        );
    }

    private function opportunity(string $slug, ?string $deadline, string $status = 'active'): void
    {
        // `provider` is NOT NULL with no default. Read off the schema rather than the
        // happy path — CLAUDE.md names this exact trap, and a fixture that omits it passes
        // only until somebody runs it.
        DB::table('gates_opportunities')->insert([
            'slug' => $slug, 'title' => ucfirst($slug), 'provider' => 'Africa GATES',
            'status' => $status, 'deadline' => $deadline,
        ]);
    }

    /** A confirmed ticket, ready to be scanned. */
    private function ticket(string $phone = '+2348012345678', string $name = 'Ada Obi'): object
    {
        $code = strtoupper(bin2hex(random_bytes(4)));
        DB::table('gates_event_registrations')->insert([
            'event_id' => $this->eventId, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '.' . $code . '@example.test',
            'phone' => $phone, 'status' => 'confirmed', 'quantity' => 1,
            'ticket_code' => $code, 'created_at' => '2026-11-01 09:00:00',
        ]);

        return DB::table('gates_event_registrations')->where('ticket_code', $code)->first();
    }

    /** @return list<array<string,mixed>> queued SMS jobs */
    private function queued(): array
    {
        try {
            return DB::table('gates_jobs')->where('type', SmsService::JOB_SMS)
                ->get()->map(static fn (object $r): array => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ══ the door ═════════════════════════════════════════════════════════════

    /** Checking in queues one text. */
    public function test_walking_in_queues_the_thank_you(): void
    {
        $this->on();
        $this->opportunity('fellowship', '2027-01-01');
        $reg = $this->ticket();

        $r = EventTicketService::checkIn((string) $reg->ticket_code, $this->eventId, 'door');

        $this->assertSame('admit', $r['verdict']);
        $this->assertCount(1, $this->queued(), 'the thank-you was not queued');
    }

    /**
     * A SECOND scan does not text again.
     *
     * A steward scanning a code twice is ordinary — a handset that did not beep, two
     * scanners racing on the same queue. EventTicketService answers `duplicate` for those,
     * and this must never be reached from that branch.
     */
    public function test_a_second_scan_does_not_text_again(): void
    {
        $this->on();
        $this->opportunity('fellowship', null);
        $reg = $this->ticket();

        EventTicketService::checkIn((string) $reg->ticket_code, $this->eventId, 'door');
        $second = EventTicketService::checkIn((string) $reg->ticket_code, $this->eventId, 'door');

        $this->assertSame('duplicate', $second['verdict']);
        $this->assertCount(1, $this->queued(), 'a re-scan texted the same person twice');
    }

    /** A refused ticket is not thanked for anything. */
    public function test_a_refused_ticket_is_not_texted(): void
    {
        $this->on();
        $this->opportunity('fellowship', null);

        $reg = $this->ticket();
        DB::table('gates_event_registrations')->where('id', $reg->id)->update(['status' => 'pending']);

        $r = EventTicketService::checkIn((string) $reg->ticket_code, $this->eventId, 'door');

        $this->assertSame('refuse', $r['verdict']);
        $this->assertSame([], $this->queued());
    }

    /**
     * The door still admits when the queue is gone.
     *
     * The whole point of the try/catch around it: a person who has paid and travelled must
     * get through a door whatever is wrong with the messaging stack.
     */
    public function test_the_door_admits_even_with_no_queue_to_write_to(): void
    {
        $this->on();
        $reg = $this->ticket();
        DB::schema()->drop('gates_jobs');

        $r = EventTicketService::checkIn((string) $reg->ticket_code, $this->eventId, 'door');

        $this->assertSame('admit', $r['verdict'],
            'a broken queue turned a paid ticket away at the door');
    }

    // ══ consent ══════════════════════════════════════════════════════════════

    /** Off by default. An upgrade must not start texting an existing event's attendees. */
    public function test_nothing_is_sent_until_an_operator_turns_it_on(): void
    {
        $this->opportunity('fellowship', null);
        $reg = $this->ticket();

        EventTicketService::checkIn((string) $reg->ticket_code, $this->eventId, 'door');

        $this->assertSame([], $this->queued(),
            'shipping this feature started texting people nobody asked');
    }

    /** Somebody who said STOP is not texted, and no job is written for them. */
    public function test_an_opted_out_number_is_not_queued_at_all(): void
    {
        $this->on();
        $this->opportunity('fellowship', null);
        SmsOptOut::record('+2348012345678', 'stop-reply');

        $reg = $this->ticket('+2348012345678');
        EventTicketService::checkIn((string) $reg->ticket_code, $this->eventId, 'door');

        $this->assertSame([], $this->queued(),
            'a queue full of jobs that can only be discarded is a queue nobody trusts');
    }

    /** And a ticket with no number simply has nowhere to send to. */
    public function test_a_ticket_with_no_phone_number_is_not_an_error(): void
    {
        $this->on();
        $reg = $this->ticket('');

        $r = EventTicketService::checkIn((string) $reg->ticket_code, $this->eventId, 'door');

        $this->assertSame('admit', $r['verdict']);
        $this->assertSame([], $this->queued());
    }

    // ══ the message ══════════════════════════════════════════════════════════

    /**
     * The way out cannot be edited away.
     *
     * An operator rewriting the copy must not be able to remove the only thing that makes
     * the message stoppable — and a text with no way out is how a sending number gets
     * blocked for every message the platform sends.
     */
    public function test_the_stop_line_survives_an_operator_rewriting_everything(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => CheckInThanks::K_TEMPLATE],
            ['value' => 'Buy more tickets.']
        );

        $body = CheckInThanks::compose($this->ticket());

        $this->assertStringContainsString('Buy more tickets.', $body);
        $this->assertStringContainsString('Reply STOP', $body,
            'the operator deleted the way out of the message');
    }

    /** Only opportunities somebody can actually still apply for are counted. */
    public function test_the_count_is_what_is_really_open_today(): void
    {
        $this->opportunity('open-now', null);
        $this->opportunity('closes-later', '2099-01-01');
        $this->opportunity('deadline-passed', '2020-01-01');
        $this->opportunity('not-published', null, 'draft');

        $this->assertSame(2, CheckInThanks::openCount(),
            'a count that includes closed rows sends somebody to a page with fewer things '
            . 'on it than the text promised');
    }

    /** With nothing open, the message does not announce a zero. */
    public function test_a_night_with_nothing_open_does_not_say_zero(): void
    {
        $body = CheckInThanks::compose($this->ticket());

        $this->assertStringNotContainsString('0 opportunities', $body);
        $this->assertStringContainsString('/opportunities', $body,
            'the link is the point even when the count is not');
    }

    /** First name only, and never a title. */
    public function test_it_uses_the_name_a_person_is_called_by(): void
    {
        $this->assertStringContainsString('here, Ada.',
            CheckInThanks::compose($this->ticket('+2348012345678', 'Ada Obi')));

        $this->assertStringContainsString('here, Adaeze.',
            CheckInThanks::compose($this->ticket('+2348012345679', 'Dr Adaeze Nwosu')),
            '"Thank you for being here, Dr" is worse than using no name at all');

        $this->assertStringContainsString('here, friend.',
            CheckInThanks::compose($this->ticket('+2348012345670', '')));
    }
}
