<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EventTicketService, PaymentService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The refunds an organiser has to do something about.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS SCREEN EXISTS AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Cancelling releases the seat BEFORE the gateway is asked — deliberately, because the other
 * order loses money with nothing on the platform knowing. The cost of that choice is a state
 * the platform can reach and could not previously show anybody: seat gone, ticket code dead,
 * money still with the organiser, and the only record an alert email and a log line on a host
 * with no shell.
 *
 * So the properties under test here are not "the list renders". They are:
 *
 * 1 · A DEBT SORTS ABOVE A FACT. Failed rows first, then pending, then settled. A list
 *     ordered purely by date buries the one row costing somebody money under a week of rows
 *     costing nobody anything — and the organiser reads the top of it.
 *
 * 2 · `owed` COUNTS ONLY WHAT HAS NOT MOVED. A pending refund is money already committed.
 *     Adding it to the outstanding figure would overstate the debt and make the number
 *     useless for the one decision it exists for: how much do I still have to send.
 *
 * 3 · TWO ORGANISERS PRESSING "TRY AGAIN" TOGETHER SEND ONE REFUND. The row is claimed
 *     before the network call, not after it. On a refund endpoint the difference between
 *     claim-first and claim-after is the difference between paying somebody once and twice.
 *
 * 4 · "PAID ANOTHER WAY" NEVER TOUCHES A PENDING ROW. It closes a debt without moving money;
 *     applied to a refund that is merely in flight it would hide a real refund behind a
 *     manual one and produce a double payment nobody is looking for.
 */
final class RefundQueueTest extends TestCase
{
    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_registrations')->delete();

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Gala', 'slug' => 'refund-queue-' . bin2hex(random_bytes(3)),
            'status' => 'published',
            'event_date' => Carbon::now()->addDays(20)->toDateTimeString(),
        ]);
    }

    /**
     * One cancelled registration in a given refund state.
     *
     * @param array<string,mixed> $extra
     */
    private function reg(string $refundStatus, int $paid = 10000, ?int $back = null,
                        string $cancelledDaysAgo = '-1 hour', array $extra = []): int
    {
        $ref = 'AFG-EVT-' . strtoupper(bin2hex(random_bytes(4)));
        DB::table('gates_event_registrations')->insert(array_merge([
            'event_id' => $this->eventId, 'tier' => 'Regular',
            'name' => 'Ada Obi', 'email' => 'ada@example.test',
            'quantity' => 1, 'amount_naira' => $paid, 'reference' => $ref,
            'status' => 'cancelled', 'provider' => 'paystack',
            'refund_status' => $refundStatus,
            'refund_naira'  => $back ?? $paid,
            'cancelled_by'  => 'attendee',
            'cancelled_at'  => Carbon::parse($cancelledDaysAgo)->toDateTimeString(),
            'created_at'    => Carbon::now()->subDays(30)->toDateTimeString(),
        ], $extra));

        return (int) DB::table('gates_event_registrations')->where('reference', $ref)->value('id');
    }

    /** A gateway that answers from a script instead of the network. */
    private function gateway(string $answer = 'refunded'): PaymentService
    {
        return new class ($answer) extends PaymentService {
            /** @var list<array{ref:string, amount:?int}> */
            public array $asked = [];
            /** What the ROW said at the moment the gateway was called. See the claim test. */
            public array $statusWhenAsked = [];
            public function __construct(private string $answer) { parent::__construct(); }
            public function isEnabled(string $p): bool { return true; }
            public function refund(string $provider, string $reference, ?int $amountNaira = null): array
            {
                $this->asked[] = ['ref' => $reference, 'amount' => $amountNaira];
                $this->statusWhenAsked[] = (string) DB::table('gates_event_registrations')
                    ->where('reference', $reference)->value('refund_status');
                return ['ok' => $this->answer === 'refunded', 'status' => $this->answer,
                        'message' => $this->answer === 'failed' ? 'Insufficient balance' : 'ok',
                        'provider_ref' => 'RFND_1', 'retryable' => null, 'amount_naira' => $amountNaira];
            }
        };
    }

    // ══ 1 · what the list is for ═════════════════════════════════════════════

    /**
     * ── A DEBT SORTS ABOVE A FACT ────────────────────────────────────────────
     *
     * The single property this list lives or dies on. A pending refund needs nobody; a failed
     * one is an attendee with no seat and no money. Sorting by date alone — the obvious
     * implementation — puts the urgent row wherever it happens to fall.
     */
    public function test_failed_refunds_sort_above_pending_and_settled(): void
    {
        // Inserted deliberately in the WRONG order, and with the failed one OLDEST, so a
        // sort by id or by date would produce a different answer than the one asserted.
        $this->reg('refunded', 5000, null, '-10 days');
        $this->reg('pending',  6000, null, '-2 hours');
        $failed = $this->reg('failed', 7000, null, '-30 days');

        $rows = EventTicketService::refunds($this->eventId);

        $this->assertCount(3, $rows);
        $this->assertSame($failed, $rows[0]['id'], 'the money nobody has is not at the top');
        $this->assertSame('failed',   $rows[0]['status']);
        $this->assertSame('pending',  $rows[1]['status']);
        $this->assertSame('refunded', $rows[2]['status']);
    }

    /** Within one state, longest-outstanding first — that is the one to chase. */
    public function test_within_a_state_the_oldest_comes_first(): void
    {
        $recent = $this->reg('failed', 1000, null, '-1 hour');
        $old    = $this->reg('failed', 1000, null, '-9 days');

        $rows = EventTicketService::refunds($this->eventId);

        $this->assertSame($old, $rows[0]['id']);
        $this->assertSame($recent, $rows[1]['id']);
    }

    /** Everything else on the event stays out of it — this is not the attendee list. */
    public function test_registrations_with_no_refund_never_appear(): void
    {
        DB::table('gates_event_registrations')->insert([
            'event_id' => $this->eventId, 'name' => 'Nobody', 'email' => 'n@example.test',
            'quantity' => 1, 'amount_naira' => 10000, 'reference' => 'AFG-EVT-PLAIN',
            'status' => 'confirmed', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->reg('failed');

        $this->assertCount(1, EventTicketService::refunds($this->eventId));
    }

    /** Another organiser's event is another organiser's problem. */
    public function test_the_list_is_scoped_to_one_event(): void
    {
        $this->reg('failed');
        $other = $this->eventId;
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Other', 'slug' => 'other-' . bin2hex(random_bytes(3)),
            'status' => 'published', 'event_date' => Carbon::now()->addDays(5)->toDateTimeString(),
        ]);
        $this->reg('failed');

        $this->assertCount(1, EventTicketService::refunds($other));
        $this->assertCount(1, EventTicketService::refunds($this->eventId));
    }

    // ══ 2 · stale, which is the only reason a pending row needs a person ═════

    /**
     * Paystack settles a refund in hours. Three days means it quietly did not, and the
     * organiser is the only party who can chase it — so at that point it stops being
     * information and becomes a task.
     */
    public function test_a_pending_refund_older_than_three_days_is_marked_stale(): void
    {
        $fresh = $this->reg('pending', 1000, null, '-4 hours');
        $stuck = $this->reg('pending', 1000, null, '-5 days');

        $by = [];
        foreach (EventTicketService::refunds($this->eventId) as $r) $by[$r['id']] = $r;

        $this->assertFalse($by[$fresh]['stale']);
        $this->assertTrue($by[$stuck]['stale']);
    }

    /** A failed refund is never "stale" — it is worse than that, and already sorted first. */
    public function test_failed_refunds_are_not_also_flagged_stale(): void
    {
        $id = $this->reg('failed', 1000, null, '-20 days');
        $this->assertFalse(EventTicketService::refunds($this->eventId)[0]['stale']);
        $this->assertSame($id, EventTicketService::refunds($this->eventId)[0]['id']);
    }

    // ══ 3 · the figure ═══════════════════════════════════════════════════════

    /**
     * ── `owed` IS WHAT HAS NOT MOVED ─────────────────────────────────────────
     *
     * A pending refund is money already committed and on its way. Counting it as outstanding
     * would overstate the debt, and an organiser deciding how much to transfer would send it
     * twice.
     */
    public function test_the_tally_counts_only_failed_money_as_owed(): void
    {
        $this->reg('failed',   7000);
        $this->reg('failed',   3000);
        $this->reg('pending', 50000);
        $this->reg('refunded', 9000);

        $t = EventTicketService::refundTally($this->eventId);

        $this->assertSame(2, $t['failed']);
        $this->assertSame(1, $t['pending']);
        $this->assertSame(1, $t['refunded']);
        $this->assertSame(10000, $t['owed'], 'a refund already with the gateway is not a debt');
    }

    /** A partial refund is owed at the partial figure, not at the ticket price. */
    public function test_a_partial_refund_is_owed_at_what_was_agreed(): void
    {
        $this->reg('failed', 10000, 4000);

        $rows = EventTicketService::refunds($this->eventId);
        $this->assertSame(4000, $rows[0]['naira']);
        $this->assertSame(10000, $rows[0]['paid'], 'the screen has to be able to show both');
        $this->assertSame(4000, EventTicketService::refundTally($this->eventId)['owed']);
    }

    public function test_an_event_with_no_cancellations_tallies_to_nothing(): void
    {
        $t = EventTicketService::refundTally($this->eventId);
        $this->assertSame([0, 0, 0, 0, 0],
            [$t['failed'], $t['pending'], $t['refunded'], $t['owed'], $t['stale']]);
        $this->assertSame([], EventTicketService::refunds($this->eventId));
    }

    // ══ 4 · trying again ═════════════════════════════════════════════════════

    /**
     * ── THE ROW IS CLAIMED BEFORE THE NETWORK CALL ───────────────────────────
     *
     * Proven from inside the gateway: at the moment `refund()` runs, the row must ALREADY
     * read `pending`. If the claim happened after the response, two organisers pressing
     * together would both find `failed`, both call the gateway, and one attendee would be
     * paid twice.
     */
    public function test_the_row_is_claimed_before_the_gateway_is_called(): void
    {
        $id = $this->reg('failed');
        $gw = $this->gateway('refunded');

        EventTicketService::retryRefund($id, $this->eventId, $gw);

        $this->assertSame(['pending'], $gw->statusWhenAsked);
    }

    /** The same guard, from the outside: the second press finds nothing to claim. */
    public function test_a_second_press_does_not_send_a_second_refund(): void
    {
        $id = $this->reg('failed');
        $gw = $this->gateway('pending');

        $first  = EventTicketService::retryRefund($id, $this->eventId, $gw);
        $second = EventTicketService::retryRefund($id, $this->eventId, $gw);

        $this->assertCount(1, $gw->asked, 'the gateway was asked twice for one refund');
        $this->assertSame('pending', $first['status']);
        $this->assertFalse($second['ok']);
        // And it says WHY in the organiser's terms. "Not in a failed state" is the shape of
        // a message that sends somebody to support to ask what it means.
        $this->assertStringContainsString('already with the gateway', $second['message']);
    }

    /** Pressing "try again" on one that has already gone through says so, plainly. */
    public function test_retrying_a_settled_refund_says_it_is_already_done(): void
    {
        $id = $this->reg('refunded');
        $gw = $this->gateway();

        $r = EventTicketService::retryRefund($id, $this->eventId, $gw);

        $this->assertFalse($r['ok']);
        $this->assertCount(0, $gw->asked);
        $this->assertStringContainsString('already been refunded', $r['message']);
    }

    /** A whole-price refund goes as a full one; the gateway is told nothing about amounts. */
    public function test_a_full_refund_is_sent_without_an_amount(): void
    {
        $id = $this->reg('failed', 10000, 10000);
        $gw = $this->gateway();

        EventTicketService::retryRefund($id, $this->eventId, $gw);

        $this->assertNull($gw->asked[0]['amount']);
    }

    /**
     * And a partial one carries the figure FROM THE ROW. The policy may have been edited
     * since the attendee cancelled; what is owed is what was agreed at the time.
     */
    public function test_a_partial_refund_is_sent_at_the_recorded_amount(): void
    {
        $id = $this->reg('failed', 10000, 2500);
        $gw = $this->gateway();

        EventTicketService::retryRefund($id, $this->eventId, $gw);

        $this->assertSame(2500, $gw->asked[0]['amount']);
    }

    public function test_a_successful_retry_settles_the_row_and_keeps_the_gateway_reference(): void
    {
        $id = $this->reg('failed');
        $r  = EventTicketService::retryRefund($id, $this->eventId, $this->gateway('refunded'));

        $this->assertTrue($r['ok']);
        $row = DB::table('gates_event_registrations')->where('id', $id)->first();
        $this->assertSame('refunded', $row->refund_status);
        $this->assertSame('RFND_1', $row->refund_ref, 'a disputed refund needs the gateway id');
        $this->assertNotEmpty($row->refunded_at);
    }

    /**
     * A retry that fails again lands back on `failed` — NOT stuck on the claim. Otherwise
     * one press would move a debt permanently out of the only list that shows it.
     */
    public function test_a_retry_that_fails_again_returns_to_failed(): void
    {
        $id = $this->reg('failed');
        $r  = EventTicketService::retryRefund($id, $this->eventId, $this->gateway('failed'));

        $this->assertFalse($r['ok']);
        $this->assertSame('failed', $r['status']);
        $this->assertSame('failed',
            DB::table('gates_event_registrations')->where('id', $id)->value('refund_status'));
        $this->assertSame(1, EventTicketService::refundTally($this->eventId)['failed']);
    }

    /**
     * A gateway that accepted but has not finished leaves the row pending and, crucially,
     * does NOT stamp `refunded_at`. A settle time on an unsettled refund is the field an
     * organiser would later quote at an attendee who never got the money.
     */
    public function test_a_pending_gateway_answer_is_not_recorded_as_settled(): void
    {
        $id = $this->reg('failed');
        $r  = EventTicketService::retryRefund($id, $this->eventId, $this->gateway('pending'));

        $this->assertSame('pending', $r['status']);
        $row = DB::table('gates_event_registrations')->where('id', $id)->first();
        $this->assertSame('pending', $row->refund_status);
        $this->assertEmpty($row->refunded_at);
    }

    /** Only failed rows are retryable. A pending one is already in flight. */
    public function test_a_pending_refund_cannot_be_retried(): void
    {
        $id = $this->reg('pending');
        $gw = $this->gateway();

        $r = EventTicketService::retryRefund($id, $this->eventId, $gw);

        $this->assertFalse($r['ok']);
        $this->assertCount(0, $gw->asked);
    }

    /** And neither event ids nor row ids are trusted from a form field. */
    public function test_a_row_on_another_event_cannot_be_retried_through_this_one(): void
    {
        $id = $this->reg('failed');
        $gw = $this->gateway();

        $r = EventTicketService::retryRefund($id, $this->eventId + 999, $gw);

        $this->assertFalse($r['ok']);
        $this->assertCount(0, $gw->asked);
        $this->assertSame('failed',
            DB::table('gates_event_registrations')->where('id', $id)->value('refund_status'));
    }

    /** Nothing recorded as owed means nothing to send — and no call to make. */
    public function test_a_zero_amount_is_not_sent_to_the_gateway(): void
    {
        $id = $this->reg('failed', 10000, 0);
        $gw = $this->gateway();

        $r = EventTicketService::retryRefund($id, $this->eventId, $gw);

        $this->assertFalse($r['ok']);
        $this->assertCount(0, $gw->asked);
        $this->assertSame('failed',
            DB::table('gates_event_registrations')->where('id', $id)->value('refund_status'));
    }

    // ══ 5 · paying somebody another way ══════════════════════════════════════

    /**
     * The escape hatch, and why it is needed: a gateway refund can be permanently impossible
     * — past the refundable age, card gone, balance never covers it. Without this the row
     * sits red forever and a list that is always red is a list nobody checks.
     */
    public function test_settling_by_hand_clears_a_failed_refund_and_names_who_said_so(): void
    {
        $id = $this->reg('failed', 10000);

        $this->assertTrue(EventTicketService::settleRefundByHand($id, $this->eventId, 7));

        $row = DB::table('gates_event_registrations')->where('id', $id)->first();
        $this->assertSame('refunded', $row->refund_status);
        $this->assertNotEmpty($row->refunded_at);
        // 'by-hand' rather than a gateway-shaped reference: six months later the two must
        // still be tellable apart, because only one of them can be looked up anywhere.
        $this->assertStringStartsWith('by-hand:', (string) $row->refund_ref);
        $this->assertStringContainsString('7', (string) $row->refund_ref);

        $this->assertSame(0, EventTicketService::refundTally($this->eventId)['owed']);
    }

    /**
     * ── AND IT MUST NOT TOUCH A PENDING ROW ──────────────────────────────────
     *
     * A pending refund is on its way. Marking it "paid by hand" and then sending a transfer
     * pays the attendee twice — and the platform's own books would show one payment.
     */
    public function test_settling_by_hand_refuses_a_refund_that_is_still_in_flight(): void
    {
        $id = $this->reg('pending');

        $this->assertFalse(EventTicketService::settleRefundByHand($id, $this->eventId, 7));
        $this->assertSame('pending',
            DB::table('gates_event_registrations')->where('id', $id)->value('refund_status'));
    }

    public function test_settling_by_hand_is_scoped_to_the_event_in_the_url(): void
    {
        $id = $this->reg('failed');

        $this->assertFalse(EventTicketService::settleRefundByHand($id, $this->eventId + 999, 7));
        $this->assertSame('failed',
            DB::table('gates_event_registrations')->where('id', $id)->value('refund_status'));
    }

    /** Pressing it twice does not double-record anything. */
    public function test_settling_an_already_settled_refund_changes_nothing(): void
    {
        $id = $this->reg('failed');
        EventTicketService::settleRefundByHand($id, $this->eventId, 7);
        $first = DB::table('gates_event_registrations')->where('id', $id)->value('refunded_at');

        $this->assertFalse(EventTicketService::settleRefundByHand($id, $this->eventId, 9));
        $this->assertSame($first,
            DB::table('gates_event_registrations')->where('id', $id)->value('refunded_at'));
    }

    // ══ 6 · the screen the organiser actually opens ══════════════════════════

    /** The real tickets page, rendered through the real container. */
    private function screen(): string
    {
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'superadmin';

        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get(\AfricaGates\Admin\Controllers\EventsController::class);

        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/admin/events/' . $this->eventId . '/tickets');

        return (string) $ctrl->tickets($req, new \Slim\Psr7\Response(),
                                       ['id' => (string) $this->eventId])->getBody();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'],
              $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    /**
     * ── THE PANEL IS DRAWN ONLY WHEN THERE IS SOMETHING IN IT ────────────────
     *
     * A permanently-present empty section headed "Refunds" trains an organiser to scroll past
     * the word — on every event except the one where it matters.
     */
    public function test_an_event_with_no_refunds_shows_no_refund_panel(): void
    {
        $this->assertStringNotContainsString('id="refunds"', $this->screen());
    }

    /**
     * The join between service and screen. Everything above can be correct and the organiser
     * still never see it: what is asserted here is that the money owed reaches the page, the
     * two controls are drawn, and each control posts to a route that exists.
     */
    public function test_a_failed_refund_reaches_the_screen_with_both_ways_out(): void
    {
        $this->reg('failed', 10000, 4000);

        $html = $this->screen();

        $this->assertStringContainsString('id="refunds"', $html);
        $this->assertStringContainsString('Ada Obi', $html);
        // The figure, in the form the organiser has to act on: what is owed, and against what.
        $this->assertStringContainsString('₦4,000', $html);
        $this->assertStringContainsString('of ₦10,000 paid', $html);
        // WCAG 1.4.1: the state is a word first — this is read in greyscale and by people
        // who do not separate the red tag from the amber one.
        $this->assertStringContainsString('did not go through', $html);
        // Both ways out of a failed refund, and no third.
        $this->assertStringContainsString('/admin/events/' . $this->eventId . '/refunds/retry', $html);
        $this->assertStringContainsString('/admin/events/' . $this->eventId . '/refunds/settle', $html);
        $this->assertStringContainsString('I paid this another way', $html);
    }

    /**
     * Each control names the person it acts on. A column of buttons all reading "Try again"
     * to a screen reader is a list nobody can act on without counting rows — and the row this
     * one gets wrong is a refund sent to the wrong attendee.
     */
    public function test_each_refund_control_names_who_it_is_for(): void
    {
        $this->reg('failed', 10000, null, '-1 hour', ['name' => 'Bola Ade']);

        $html = $this->screen();

        $this->assertStringContainsString('aria-label="Try the refund for Bola Ade again"', $html);
        $this->assertStringContainsString('Record that Bola Ade was paid another way', $html);
        // Bound, not fixed: the visible words change on the first press, so the announced
        // ones have to change with them.
        $this->assertStringContainsString(':aria-label=', $html);
    }

    /** A pending refund is shown, and explicitly offers nothing to press. */
    public function test_a_pending_refund_is_shown_without_controls(): void
    {
        $this->reg('pending', 5000);

        $html = $this->screen();

        $this->assertStringContainsString('with the gateway', $html);
        $this->assertStringContainsString('nothing to do', $html);
        $this->assertStringNotContainsString('/refunds/retry', $html);
    }

    /** Waiting too long is the one thing about a pending refund that needs a person. */
    public function test_a_stale_pending_refund_says_so_on_the_page(): void
    {
        $this->reg('pending', 5000, null, '-6 days');

        $html = $this->screen();

        $this->assertStringContainsString('3+ days', $html);
        $this->assertStringContainsString('worth chasing', $html);
    }

    /**
     * Settled refunds are folded away rather than dropped. They answer "did that ever go
     * back?" weeks later — but they are not work, and mixing them into the same list is what
     * makes the debts hard to see.
     */
    public function test_settled_refunds_are_folded_away_not_listed_with_the_debts(): void
    {
        EventTicketService::settleRefundByHand($this->reg('failed'), $this->eventId, 7);

        $html = $this->screen();

        $this->assertStringContainsString('1 refund already settled', $html);
        $this->assertStringContainsString('<details', $html);
        // And a hand-settled one is marked as such: only one of the two references can be
        // looked up anywhere, and six months later they must still be tellable apart.
        $this->assertStringContainsString('paid outside the platform', $html);
        $this->assertStringNotContainsString('did not go through', $html);
    }

    /**
     * Both controls are POST. A refund you can trigger by following a link is a refund a
     * prefetching browser or a link scanner can trigger on an organiser's behalf.
     */
    public function test_the_refund_actions_are_post_only(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        $this->assertMatchesRegularExpression(
            '~\$a->post\(\'/events/\{id:\[0-9\]\+\}/refunds/retry~', $routes);
        $this->assertMatchesRegularExpression(
            '~\$a->post\(\'/events/\{id:\[0-9\]\+\}/refunds/settle~', $routes);
        $this->assertDoesNotMatchRegularExpression('~\$a->get\([^)]*refunds/(retry|settle)~', $routes);
    }
}
