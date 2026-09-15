<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaidVoteService;
use AfricaGates\Services\PaymentService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A checkout must not outlive the ballot it was bought against.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE HOLE THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A pending checkout was live for a flat `PaymentService::IN_FLIGHT_MINUTES` (120)
 * from creation, and nothing in that arithmetic knew about voting closing. So an
 * order started twenty minutes before the bell counted as "still at the gateway" for
 * an hour and forty minutes AFTER the ballot had shut — and every reader believed it:
 *
 *   • the abandoned-cart mailer nudged the buyer to go back and finish paying for
 *     votes that could no longer be delivered;
 *   • the reconciler kept re-asking the gateway about a reference whose votes were
 *     already undeliverable;
 *   • a payment landing in that stretch was confirmed, and became a refund.
 *
 * That is the origin of the refunds. Every "voting closed before the payment
 * confirmed" order started as a checkout the ballot had finished with that nothing
 * had told.
 *
 * ── WHAT THIS CANNOT DO, STATED PLAINLY ──────────────────────────────────────
 *
 * Paystack's transaction/initialize has no expiry parameter, so a HOSTED checkout
 * page cannot be told to die at the bell. A buyer who bookmarked the gateway URL can
 * still pay it. Nothing in this file pretends otherwise. What it fixes is everything
 * on OUR side that treated such a checkout as live — and when money does arrive late,
 * the existing mint gate refuses it and the refund path returns it.
 */
final class CheckoutDeadlineTest extends TestCase
{
    private int $closingSoon = 0;   // voting closes in 20 minutes
    private int $openEnded   = 0;   // no published close at all
    private int $wideOpen    = 0;   // closes in 5 days

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 99, 'title' => 'P', 'slug' => 'p-990']);

        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 990, 'programme_id' => 99, 'year' => 2026,
            'status' => 'voting',
            'voting_open'  => Carbon::now()->subDays(5)->toDateTimeString(),
            'voting_close' => Carbon::now()->addMinutes(20)->toDateTimeString()]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 991, 'programme_id' => 99, 'year' => 2027,
            'status' => 'voting',
            'voting_open'  => Carbon::now()->subDays(5)->toDateTimeString(),
            'voting_close' => null]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 992, 'programme_id' => 99, 'year' => 2028,
            'status' => 'voting',
            'voting_open'  => Carbon::now()->subDays(5)->toDateTimeString(),
            'voting_close' => Carbon::now()->addDays(5)->toDateTimeString()]);

        DB::table('gates_award_categories')->insertOrIgnore(['id' => 990, 'cycle_id' => 990, 'title' => 'Soon', 'slug' => 'c-990']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 991, 'cycle_id' => 991, 'title' => 'Open', 'slug' => 'c-991']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 992, 'cycle_id' => 992, 'title' => 'Wide', 'slug' => 'c-992']);

        $this->closingSoon = 990;
        $this->openEnded   = 991;
        $this->wideOpen    = 992;
    }

    // ══ the deadline itself ══════════════════════════════════════════════════

    /**
     * THE CASE THIS EXISTS FOR. Near the bell, the BALLOT wins.
     *
     * Twenty minutes to close, a two-hour patience window. The deadline must be the
     * close, not created+120 — the extra hundred minutes is exactly the stretch in
     * which money got taken for undeliverable votes.
     */
    public function test_near_the_bell_the_deadline_is_the_close_not_our_window(): void
    {
        $placed = Carbon::now();
        $deadline = PaidVoteService::checkoutDeadline($this->closingSoon, $placed);

        $this->assertNotNull($deadline);
        $this->assertLessThan(
            $placed->copy()->addMinutes(PaymentService::IN_FLIGHT_MINUTES)->timestamp,
            $deadline->timestamp,
            'The flat window outlived the ballot — that gap is where the refunds came from.');
        // Within a minute of the cycle's own close.
        $close = Carbon::parse((string) DB::table('gates_award_cycles')->where('id', 990)->value('voting_close'));
        $this->assertLessThanOrEqual(60, abs($deadline->timestamp - $close->timestamp));
    }

    /** Mid-cycle, OUR patience wins — the ballot is nowhere near closing. */
    public function test_mid_cycle_the_deadline_is_our_own_window(): void
    {
        $placed   = Carbon::now();
        $deadline = PaidVoteService::checkoutDeadline($this->wideOpen, $placed);

        $expected = $placed->copy()->addMinutes(PaymentService::IN_FLIGHT_MINUTES);
        $this->assertLessThanOrEqual(60, abs($deadline->timestamp - $expected->timestamp),
            'A buyer mid-cycle gets the full patience window; a slow bank is not their fault.');
    }

    /**
     * An open-ended cycle must NOT lose its checkouts to a missing date.
     *
     * A null close means there is nothing to clip against, not that everything has
     * expired. Getting this backwards would silently disable paid voting on any cycle
     * whose close time had not been filled in.
     */
    public function test_an_open_ended_cycle_falls_back_to_our_window(): void
    {
        $placed   = Carbon::now();
        $deadline = PaidVoteService::checkoutDeadline($this->openEnded, $placed);

        $this->assertNotNull($deadline, 'Null here would read as "already expired" downstream.');
        $expected = $placed->copy()->addMinutes(PaymentService::IN_FLIGHT_MINUTES);
        $this->assertLessThanOrEqual(60, abs($deadline->timestamp - $expected->timestamp));
    }

    /**
     * The deadline uses the CLOSE, not the earlier "stop selling" cutoff.
     *
     * `checkoutClosesAt()` is minutes earlier and governs whether a checkout may
     * START. Once somebody is at the gateway with their card out, the honest deadline
     * is the ballot's own — clipping to the earlier one would kill a payment that was
     * still perfectly deliverable and tell a buyer who did nothing wrong they were
     * too late.
     */
    public function test_the_deadline_is_the_close_not_the_stop_selling_cutoff(): void
    {
        $placed  = Carbon::now();
        $cutoff  = PaidVoteService::checkoutClosesAt($this->closingSoon);
        $dead    = PaidVoteService::checkoutDeadline($this->closingSoon, $placed);

        $this->assertNotNull($cutoff);
        $this->assertGreaterThan($cutoff->timestamp, $dead->timestamp,
            'Refusing to sell and refusing to honour are different decisions.');
    }

    /** It never returns a moment before the order was placed. */
    public function test_the_deadline_is_never_before_the_order(): void
    {
        foreach ([$this->closingSoon, $this->openEnded, $this->wideOpen] as $cat) {
            $placed = Carbon::now();
            $this->assertGreaterThanOrEqual($placed->timestamp,
                PaidVoteService::checkoutDeadline($cat, $placed)->timestamp,
                'A deadline in the past would make every new checkout instantly dead.');
        }
    }

    // ══ the shared predicate every reader uses ══════════════════════════════

    private function pending(string $ref, ?string $expires, string $createdAt): void
    {
        DB::table('gates_donations')->insert(\AfricaGates\Support\OptionalColumn::filter(
            'gates_donations', [
                'donor_name' => 'B', 'donor_email' => 'b@example.test',
                'amount_naira' => 1000, 'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
                'payment_ref' => $ref, 'status' => 'pending',
                'created_at' => $createdAt, 'checkout_expires_at' => $expires,
            ], ['checkout_expires_at']));
    }

    /** @return list<string> references the predicate considers dead */
    private function dead(): array
    {
        $q = DB::table('gates_donations')->where('status', 'pending');
        return PaidVoteService::whereCheckoutDead($q)->pluck('payment_ref')->all();
    }

    /**
     * A checkout past its own deadline is dead EVEN IF it is only minutes old.
     *
     * This is the behaviour change. Under the flat window, a five-minute-old order
     * whose ballot had closed was still "live" for nearly two hours.
     */
    public function test_a_young_checkout_past_the_bell_is_already_dead(): void
    {
        $this->pending('AFG-PVOTE-BELL',
            expires:   Carbon::now()->subMinutes(2)->toDateTimeString(),
            createdAt: Carbon::now()->subMinutes(5)->toDateTimeString());

        $this->assertContains('AFG-PVOTE-BELL', $this->dead(),
            'Five minutes old, but the ballot has shut — nothing should still treat this as live.');
    }

    /** A checkout inside its deadline is left alone, however old. */
    public function test_a_checkout_inside_its_deadline_is_still_live(): void
    {
        $this->pending('AFG-PVOTE-LIVE',
            expires:   Carbon::now()->addHours(3)->toDateTimeString(),
            createdAt: Carbon::now()->subHours(4)->toDateTimeString());

        $this->assertNotContains('AFG-PVOTE-LIVE', $this->dead(),
            'Its own deadline has not passed, so it is not for anyone to declare dead.');
    }

    /**
     * A row with NO recorded deadline falls back to the old global window.
     *
     * Both halves are asserted, because getting this wrong in the "everything with a
     * NULL is expired" direction would void every in-flight checkout the moment the
     * migration ran.
     */
    public function test_rows_without_a_deadline_use_the_old_window(): void
    {
        $this->pending('AFG-PVOTE-OLD-DEAD',  null, Carbon::now()->subHours(4)->toDateTimeString());
        $this->pending('AFG-PVOTE-OLD-FRESH', null, Carbon::now()->subMinutes(5)->toDateTimeString());

        $dead = $this->dead();
        $this->assertContains('AFG-PVOTE-OLD-DEAD', $dead,
            'Four hours with no deadline recorded is past the two-hour fallback.');
        $this->assertNotContains('AFG-PVOTE-OLD-FRESH', $dead,
            'A migration must never declare a live checkout expired.');
    }

    /** The predicate is time-injectable, so the boundary can be pinned exactly. */
    public function test_the_boundary_is_evaluated_against_the_given_moment(): void
    {
        $this->pending('AFG-PVOTE-EDGE',
            expires:   Carbon::now()->addHour()->toDateTimeString(),
            createdAt: Carbon::now()->toDateTimeString());

        $q = DB::table('gates_donations')->where('status', 'pending');
        $later = PaidVoteService::whereCheckoutDead($q, Carbon::now()->addHours(2))
            ->pluck('payment_ref')->all();

        $this->assertContains('AFG-PVOTE-EDGE', $later,
            'Two hours later that deadline has plainly passed.');
    }

    // ══ and it is actually written down ═════════════════════════════════════

    /**
     * The column exists and accepts the value, so the whole thing is not inert.
     *
     * Cheap, and it catches the failure this codebase has hit repeatedly: a column
     * dropped by OptionalColumn on an unmigrated database, leaving every deadline
     * silently NULL and every reader quietly back on the flat window.
     */
    public function test_the_deadline_column_is_present_and_writable(): void
    {
        $this->assertTrue(
            \AfricaGates\Support\OptionalColumn::on('gates_donations', 'checkout_expires_at'),
            'Without the column every checkout falls back to the flat window and this '
            . 'entire change does nothing.');

        $when = Carbon::now()->addMinutes(20)->toDateTimeString();
        $this->pending('AFG-PVOTE-WRITE', $when, Carbon::now()->toDateTimeString());

        $this->assertSame($when, (string) DB::table('gates_donations')
            ->where('payment_ref', 'AFG-PVOTE-WRITE')->value('checkout_expires_at'));
    }
}
