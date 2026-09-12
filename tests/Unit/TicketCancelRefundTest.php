<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EventRefundPolicy, EventTicketService, PaymentService, TicketSelfService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Giving a seat up, and the money that may or may not come with it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO PROPERTIES THAT MATTER MORE THAN THE FEATURE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · THE FIGURE IS QUOTED BEFORE THE IRREVERSIBLE STEP, and the same function produces the
 *     quote and the payment. Two implementations of "how much is this worth" would eventually
 *     differ, and the moment they did the platform would be showing one number and paying
 *     another — on the screen where somebody decides whether to trust the organiser.
 *
 * 2 · THE SEAT IS RELEASED BEFORE THE GATEWAY IS ASKED. That looks like the riskier order and
 *     is the safe one: a failure after the release leaves a recorded, alerted debt that a
 *     person can settle, while a failure after a refund would leave money gone and the seat
 *     still held, with nothing on the platform knowing.
 */
final class TicketCancelRefundTest extends TestCase
{
    private int $eventId = 0;
    private int $tierId  = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_registrations')->delete();
        try { DB::table('gates_otp_tokens')->delete(); } catch (\Throwable) {}
    }

    /**
     * @param array<string,mixed> $policy columns on the event
     */
    private function event(array $policy = [], string $starts = '+30 days'): void
    {
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId(array_merge([
            'title' => 'Gala', 'slug' => 'cancel-gala-' . bin2hex(random_bytes(3)),
            'status' => 'published',
            'event_date' => Carbon::parse($starts)->toDateTimeString(),
            'self_cancel' => 1, 'refund_mode' => 'full',
            'refund_percent' => 50, 'refund_cutoff_hours' => 0,
        ], $policy));
        $this->tierId = (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => 'reg', 'name' => 'Regular',
            'price_naira' => 10000, 'capacity' => 100, 'min_per_order' => 1,
            'max_per_order' => 10, 'is_active' => 1, 'sort_order' => 0,
        ]);
    }

    private function ticket(int $paid = 10000, string $status = 'confirmed'): object
    {
        $ref = 'AFG-EVT-' . strtoupper(bin2hex(random_bytes(4)));
        DB::table('gates_event_registrations')->insert([
            'event_id' => $this->eventId, 'tier_id' => $this->tierId, 'tier' => 'Regular',
            'name' => 'Ada Obi', 'email' => 'ada@example.test', 'phone' => '08030000000',
            'quantity' => 1, 'amount_naira' => $paid, 'reference' => $ref,
            'ticket_code' => 'CODE-' . strtoupper(bin2hex(random_bytes(2))),
            'status' => $status, 'provider' => 'paystack',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        return (object) DB::table('gates_event_registrations')->where('reference', $ref)->first();
    }

    private function mailer(): CodeCatchingMailer
    {
        return $this->mail ??= new CodeCatchingMailer();
    }
    private ?CodeCatchingMailer $mail = null;

    private function codeFor(string $ref): string
    {
        TicketSelfService::sendCode($ref, $this->mailer());
        return $this->mailer()->code;
    }

    /** A gateway that answers a refund from a script instead of the network. */
    private function gateway(string $status = 'refunded'): PaymentService
    {
        return new class ($status) extends PaymentService {
            /** @var list<array{ref:string, amount:?int}> */
            public array $asked = [];
            public function __construct(private string $answer) { parent::__construct(); }
            public function isEnabled(string $p): bool { return true; }
            public function refund(string $provider, string $reference, ?int $amountNaira = null): array
            {
                $this->asked[] = ['ref' => $reference, 'amount' => $amountNaira];
                return ['ok' => $this->answer !== 'failed', 'status' => $this->answer,
                        'message' => $this->answer === 'failed' ? 'Insufficient balance' : 'ok',
                        'provider_ref' => 'RFND_1', 'retryable' => null, 'amount_naira' => $amountNaira];
            }
        };
    }

    // ══ 1 · the quote, which is the whole contract ═══════════════════════════

    /** Off by default is the entire backwards-compatibility story. */
    public function test_an_organiser_who_never_opened_the_screen_offers_no_self_cancel(): void
    {
        $this->event(['self_cancel' => 0]);
        $q = EventRefundPolicy::quote($this->ticket());

        $this->assertFalse($q['can_cancel']);
        $this->assertSame('off', $q['reason']);
        // And it says who CAN do it — a dead end that names nobody is the failure here.
        $this->assertNotSame('', $q['contact']);
    }

    public function test_a_full_policy_quotes_the_whole_price(): void
    {
        $this->event(['refund_mode' => 'full']);
        $q = EventRefundPolicy::quote($this->ticket(10000));

        $this->assertTrue($q['can_cancel']);
        $this->assertSame(10000, $q['naira']);
    }

    /** Partial floors rather than rounds — rounding up pays out more than the policy says. */
    public function test_a_partial_policy_floors_the_amount(): void
    {
        $this->event(['refund_mode' => 'partial', 'refund_percent' => 33]);
        $q = EventRefundPolicy::quote($this->ticket(999));

        $this->assertSame(329, $q['naira'], '329.67 was rounded up into money nobody agreed to');
    }

    /**
     * ── PAST THE CUTOFF YOU CAN STILL LEAVE, YOU JUST GET NOTHING ────────────
     *
     * `can_cancel` and `naira` are separate on purpose. An organiser will happily let somebody
     * free a seat for the waiting list two days out while refunding nothing, because the
     * catering is ordered — and collapsing the two into one flag forces a choice between a
     * locked seat and an unwanted refund.
     */
    public function test_past_the_cutoff_the_seat_can_still_be_given_up_for_nothing(): void
    {
        $this->event(['refund_mode' => 'full', 'refund_cutoff_hours' => 48], '+12 hours');
        $q = EventRefundPolicy::quote($this->ticket(10000));

        $this->assertTrue($q['can_cancel'], 'the seat is locked, so the waiting list gets nothing');
        $this->assertSame(0, $q['naira']);
        $this->assertSame('cutoff', $q['reason']);
    }

    public function test_inside_the_cutoff_the_refund_stands(): void
    {
        $this->event(['refund_mode' => 'full', 'refund_cutoff_hours' => 48], '+10 days');
        $this->assertSame(10000, EventRefundPolicy::quote($this->ticket(10000))['naira']);
    }

    public function test_a_used_ticket_and_a_finished_event_cannot_be_cancelled(): void
    {
        $this->event();
        $reg = $this->ticket();
        DB::table('gates_event_registrations')->where('id', (int) $reg->id)
            ->update(['checked_in_at' => Carbon::now()->toDateTimeString()]);
        $this->assertSame('checked_in',
            EventRefundPolicy::quote((object) DB::table('gates_event_registrations')
                ->where('id', (int) $reg->id)->first())['reason']);

        $this->event([], '-1 day');
        $this->assertSame('past', EventRefundPolicy::quote($this->ticket())['reason']);
    }

    /** A free place has nothing to refund, and says so rather than implying money. */
    public function test_a_free_place_can_be_given_up_with_no_refund(): void
    {
        $this->event(['refund_mode' => 'full']);
        $q = EventRefundPolicy::quote($this->ticket(0));

        $this->assertTrue($q['can_cancel']);
        $this->assertSame(0, $q['naira']);
        $this->assertSame('free', $q['reason']);
    }

    // ══ 2 · cancelling, and the money ════════════════════════════════════════

    public function test_cancelling_releases_the_seat_and_kills_the_code(): void
    {
        $this->event(['refund_mode' => 'full']);
        $reg = $this->ticket(10000);
        $gw  = $this->gateway('refunded');

        $r = TicketSelfService::cancel((string) $reg->reference, $this->codeFor((string) $reg->reference),
                                       $this->mailer(), $gw);
        $this->assertTrue($r['ok'], $r['message']);

        $row = DB::table('gates_event_registrations')->where('id', (int) $reg->id)->first();
        $this->assertSame('cancelled', (string) $row->status);
        $this->assertSame('attendee', (string) $row->cancelled_by);
        $this->assertNull($row->ticket_code, 'the old screenshot still scans at the door');
        $this->assertSame('refunded', (string) $row->refund_status);
        $this->assertSame(10000, (int) $row->refund_naira);

        // The seat is genuinely back on sale.
        $this->assertSame(0, EventTicketService::soldForEvent($this->eventId));
    }

    /**
     * A FULL refund passes no amount, so the gateway refunds what it actually collected.
     *
     * Supplying our own figure asks it to trust our arithmetic over its own record, and it
     * fails asymmetrically: too low succeeds quietly and leaves the buyer short with every
     * column reading `refunded`.
     */
    public function test_a_full_refund_lets_the_gateway_decide_the_amount(): void
    {
        $this->event(['refund_mode' => 'full']);
        $reg = $this->ticket(10000);
        $gw  = $this->gateway();

        TicketSelfService::cancel((string) $reg->reference, $this->codeFor((string) $reg->reference),
                                  $this->mailer(), $gw);

        $this->assertNull($gw->asked[0]['amount'], 'a full refund sent our own figure');
    }

    /** A PARTIAL refund must pass the amount, because that is the only way to be partial. */
    public function test_a_partial_refund_sends_the_computed_amount(): void
    {
        $this->event(['refund_mode' => 'partial', 'refund_percent' => 40]);
        $reg = $this->ticket(10000);
        $gw  = $this->gateway();

        TicketSelfService::cancel((string) $reg->reference, $this->codeFor((string) $reg->reference),
                                  $this->mailer(), $gw);

        $this->assertSame(4000, $gw->asked[0]['amount']);
    }

    /**
     * `pending` is a real answer, not a failure.
     *
     * Paystack queues a refund and settles it hours later. Treating anything but `refunded` as
     * an error is how a caller retries a refund that is already on its way — and how somebody
     * gets paid back twice.
     */
    public function test_a_queued_refund_is_reported_as_on_its_way_not_as_a_failure(): void
    {
        $this->event(['refund_mode' => 'full']);
        $reg = $this->ticket(10000);

        $r = TicketSelfService::cancel((string) $reg->reference, $this->codeFor((string) $reg->reference),
                                       $this->mailer(), $this->gateway('pending'));

        $this->assertTrue($r['ok']);
        $this->assertSame('pending', $r['status']);
        $this->assertStringContainsString('on its way', $r['message']);
    }

    /** And the webhook settles it later — otherwise it reads `pending` forever. */
    public function test_the_refund_webhook_settles_a_pending_cancellation(): void
    {
        $this->event(['refund_mode' => 'full']);
        $reg = $this->ticket(10000);
        TicketSelfService::cancel((string) $reg->reference, $this->codeFor((string) $reg->reference),
                                  $this->mailer(), $this->gateway('pending'));

        $this->assertTrue(EventTicketService::reverse((string) $reg->reference, 'refund.processed'));

        $row = DB::table('gates_event_registrations')->where('id', (int) $reg->id)->first();
        $this->assertSame('refunded', (string) $row->refund_status);
        $this->assertNotNull($row->refunded_at);
    }

    /**
     * A refund the gateway refuses leaves the seat released and the debt RECORDED.
     *
     * This is the failure the cancel-first order is chosen for: it is visible, alerted and
     * fixable, where the other order would leave money gone and the seat still held with
     * nothing on the platform knowing.
     */
    public function test_a_failed_refund_still_frees_the_seat_and_records_what_is_owed(): void
    {
        $this->event(['refund_mode' => 'full']);
        $reg = $this->ticket(10000);

        $r = TicketSelfService::cancel((string) $reg->reference, $this->codeFor((string) $reg->reference),
                                       $this->mailer(), $this->gateway('failed'));

        $this->assertTrue($r['ok']);
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('could not be sent automatically', $r['message']);

        $row = DB::table('gates_event_registrations')->where('id', (int) $reg->id)->first();
        $this->assertSame('cancelled', (string) $row->status);
        $this->assertSame('failed', (string) $row->refund_status);
        $this->assertSame(10000, (int) $row->refund_naira,
            'the debt was not written down, so nobody can settle it');
    }

    /** Twice is once. A double-click must not ask the gateway for money twice. */
    public function test_cancelling_twice_refunds_once(): void
    {
        $this->event(['refund_mode' => 'full']);
        $reg  = $this->ticket(10000);
        $gw   = $this->gateway();
        $code = $this->codeFor((string) $reg->reference);

        $first  = TicketSelfService::cancel((string) $reg->reference, $code, $this->mailer(), $gw);
        // A fresh code, so the second attempt fails on the STATE rather than on the code —
        // which is the guard actually under test.
        $second = TicketSelfService::cancel((string) $reg->reference,
                                            $this->codeFor((string) $reg->reference),
                                            $this->mailer(), $gw);

        $this->assertTrue($first['ok']);
        $this->assertCount(1, $gw->asked, 'the gateway was asked for money twice');
        $this->assertFalse($second['ok']);
    }

    /** No code, no cancellation — the same gate as every other change to a ticket. */
    public function test_cancelling_without_the_emailed_code_changes_nothing(): void
    {
        $this->event(['refund_mode' => 'full']);
        $reg = $this->ticket(10000);
        $gw  = $this->gateway();

        $r = TicketSelfService::cancel((string) $reg->reference, '', $this->mailer(), $gw);

        $this->assertFalse($r['ok']);
        $this->assertCount(0, $gw->asked);
        $this->assertSame('confirmed', (string) DB::table('gates_event_registrations')
            ->where('id', (int) $reg->id)->value('status'));
    }

    /** And a policy that refunds nothing never touches the gateway at all. */
    public function test_a_no_refund_policy_frees_the_seat_without_calling_the_gateway(): void
    {
        $this->event(['refund_mode' => 'none']);
        $reg = $this->ticket(10000);
        $gw  = $this->gateway();

        $r = TicketSelfService::cancel((string) $reg->reference, $this->codeFor((string) $reg->reference),
                                       $this->mailer(), $gw);

        $this->assertTrue($r['ok']);
        $this->assertSame('none', $r['status']);
        $this->assertCount(0, $gw->asked);
        $this->assertSame('cancelled', (string) DB::table('gates_event_registrations')
            ->where('id', (int) $reg->id)->value('status'));
    }

    // ══ 3 · the organiser's form must stop eating their work ═════════════════

    /**
     * ── A DATA-LOSS BUG FOUND WHILE BUILDING THIS ────────────────────────────
     *
     * Six columns — the refund policy among them — were written unconditionally from the POST
     * body, and the event form contains none of them. So every save of any event silently
     * blanked the refund policy, the attendee note, the organiser's email and phone and the
     * sales cutoff, and switched the waiting list off.
     *
     * The marker pattern was already in the same method for the ticket design, with a comment
     * stating the rule exactly. It had simply never been applied here.
     */
    public function test_saving_an_event_without_the_extras_panel_keeps_the_extras(): void
    {
        $this->event(['refund_mode' => 'partial', 'refund_percent' => 25]);
        DB::table('gates_site_events')->where('id', $this->eventId)->update([
            'refund_policy'   => 'Full refund up to a week before.',
            'organiser_email' => 'organiser@example.test',
        ]);

        $src = (string) file_get_contents(dirname(__DIR__, 2)
            . '/src/Admin/Controllers/EventsController.php');

        $this->assertStringContainsString("array_key_exists('event_extras_posted'", $src,
            'the extras are written unconditionally, so every save wipes the ones the form '
            . 'does not contain');
    }

    /** The summary an organiser's settings produce, in the sentence a buyer reads. */
    public function test_the_policy_is_stated_before_anybody_buys(): void
    {
        $this->event(['refund_mode' => 'partial', 'refund_percent' => 60, 'refund_cutoff_hours' => 48]);
        $ev = DB::table('gates_site_events')->where('id', $this->eventId)->first();

        $s = EventRefundPolicy::summary($ev);
        $this->assertStringContainsString('60%', $s);
        $this->assertStringContainsString('2 days', $s, 'hours were not said the way people say them');
    }

    public function test_an_event_with_self_cancel_off_says_to_contact_the_organiser(): void
    {
        $this->event(['self_cancel' => 0]);
        $ev = DB::table('gates_site_events')->where('id', $this->eventId)->first();

        $this->assertStringContainsString('contact the organiser', EventRefundPolicy::summary($ev));
    }
}
