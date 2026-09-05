<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\PaidVoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The deadline. Whose clock decides whether a paid vote counts.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THESE TESTS EXIST FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * mint() used to ask "is voting open RIGHT NOW", where *now* was whenever the
 * gateway's webhook happened to land. Somebody pays at 23:58 while the ballot is
 * plainly open; 3-D Secure takes a minute; the webhook is retried once after a
 * 502; it arrives at 00:02. The gate said closed, the money had already left
 * their account, and no votes appeared. They watched a nominee they had paid to
 * support finish without their votes, and were told days later that a refund was
 * coming.
 *
 * The order's own timestamp is the answer. start() refuses to create a paid-vote
 * order unless voting is open, so `created_at` is a record the platform stamped
 * on a check it performed itself: the ballot WAS open when we took the money. We
 * sold them a vote; we owe them the vote. The lag is our infrastructure's.
 *
 * Two properties, and BOTH must hold — the second is why the first is safe:
 *   • a payment STARTED before the close mints, however late the webhook is;
 *   • a webhook that arrives absurdly late does NOT move a settled tally.
 */
final class PaidVoteDeadlineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();
        DB::table('gates_votes')->delete();
        DB::table('gates_settings')->where('key_name', 'like', 'paid_vote%')->delete();
    }

    /** A cycle that closed `$closedHoursAgo` hours ago, with a nominee on it. */
    private function seedClosedCycle(float $closedHoursAgo): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', (int) (time() - $closedHoursAgo * 3600)),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(
            ['id' => 10, 'cycle_id' => 1, 'slug' => 'c', 'title' => 'Category']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 1, 'category_id' => 10, 'name' => 'Nominee', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
    }

    private function seedOpenCycle(string $closesIn = '+7 days'): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime($closesIn)),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(
            ['id' => 10, 'cycle_id' => 1, 'slug' => 'c', 'title' => 'Category']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 1, 'category_id' => 10, 'name' => 'Nominee', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
    }

    /** A confirmed paid-vote order placed at `$placedAt`. */
    private function order(string $placedAt, int $qty = 20): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Okun Alimosho', 'donor_email' => 'okun@example.test',
            'amount_naira' => $qty * 100, 'tier' => 'paid-vote',
            'bonus_votes' => $qty, 'votes_used' => 0, 'intent_nominee_id' => 1,
            'payment_ref' => 'AFG-PVOTE-' . bin2hex(random_bytes(6)),
            'status' => 'confirmed',
            'created_at' => date('Y-m-d H:i:s', strtotime($placedAt)),
        ]);
    }

    private function setting(string $key, string $value): void
    {
        DB::table('gates_settings')->insert(['key_name' => $key, 'value' => $value]);
    }

    // ── the fix ──────────────────────────────────────────────────────────────

    public function test_a_payment_started_before_the_close_mints_even_though_the_webhook_is_late(): void
    {
        // Closed an hour ago. They paid two hours ago, while it was open.
        $this->seedClosedCycle(1);
        $id = $this->order('-2 hours');

        $r = PaidVoteService::mint($id);

        $this->assertTrue($r['ok'],
            'they bought a vote while the ballot was open — the gateway being slow is our problem, not theirs');
        $this->assertSame(20, (int) DB::table('gates_donations')->where('id', $id)->value('votes_used'));
        $this->assertSame(20, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
    }

    public function test_the_minute_either_side_of_midnight_is_the_case_that_made_people_angry(): void
    {
        // Closed 4 minutes ago; paid 2 minutes before that.
        $this->seedClosedCycle(4 / 60);
        $id = $this->order('-6 minutes');

        $this->assertTrue(PaidVoteService::mint($id)['ok'],
            'paid at 23:58, confirmed at 00:02 — this is the exact complaint');
    }

    public function test_a_payment_started_after_the_close_is_still_refused(): void
    {
        // Closed two hours ago; they somehow started an order an hour ago.
        $this->seedClosedCycle(2);
        $id = $this->order('-1 hour');

        $r = PaidVoteService::mint($id);

        $this->assertFalse($r['ok']);
        $this->assertSame('VOTING_CLOSED', $r['code']);
        $this->assertSame(0, (int) DB::table('gates_donations')->where('id', $id)->value('votes_used'),
            'votes_used stays 0 — the queryable "paid but never minted, refund owed" signal');
    }

    // ── and the bound that makes the fix safe ────────────────────────────────

    public function test_a_webhook_that_arrives_days_late_does_not_move_a_settled_tally(): void
    {
        // Closed three days ago. The order was placed in time, but nobody can
        // reopen a published result because a webhook was stuck for 72 hours.
        $this->seedClosedCycle(72);
        $id = $this->order('-73 hours');

        $r = PaidVoteService::mint($id);

        $this->assertFalse($r['ok']);
        $this->assertSame('CONFIRMED_TOO_LATE', $r['code']);
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'),
            'a winner that has been announced must stay announced');
    }

    public function test_the_grace_window_is_configurable(): void
    {
        $this->seedClosedCycle(20);          // closed 20 hours ago
        $id = $this->order('-21 hours');

        $this->assertFalse(PaidVoteService::mint($id)['ok'], 'default grace is 6 hours');

        $this->setting('paid_vote_grace_hours', '48');
        $this->assertTrue(PaidVoteService::mint($id)['ok'],
            'an operator running a slow gateway can widen the window without a deploy');
    }

    public function test_the_grace_window_is_capped_however_it_is_configured(): void
    {
        // A fat-fingered setting must not turn into an open-ended right to
        // rewrite old tallies.
        $this->setting('paid_vote_grace_hours', '100000');
        $this->assertSame(168, PaidVoteService::lateMintGraceHours());
    }

    // ── the prevention half ─────────────────────────────────────────────────

    public function test_checkout_closes_before_voting_does(): void
    {
        $this->seedOpenCycle('+5 minutes');   // ballot shuts in 5, cutoff is 10

        $this->assertTrue(PaidVoteService::votingOpenFor(10),
            'the free ballot runs to the bell — it mints inside the request');
        $this->assertFalse(PaidVoteService::checkoutOpenFor(10),
            'card payment stops early, because it has to reach a bank and come back');
    }

    public function test_checkout_is_open_with_room_to_spare(): void
    {
        $this->seedOpenCycle('+3 hours');

        $this->assertTrue(PaidVoteService::checkoutOpenFor(10));
    }

    public function test_the_cutoff_is_configurable_and_can_be_switched_off(): void
    {
        $this->seedOpenCycle('+5 minutes');

        $this->setting('paid_vote_cutoff_minutes', '0');
        $this->assertTrue(PaidVoteService::checkoutOpenFor(10),
            'zero means sell to the bell — an operator may choose that now the '
            . 'grace window catches what it lets through');
    }

    public function test_a_cycle_with_no_published_close_does_not_lose_paid_voting(): void
    {
        // A null close time must not read as "the cutoff has passed" and quietly
        // disable checkout — that would be an outage caused by a missing date.
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-1 day')), 'voting_close' => null,
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(
            ['id' => 10, 'cycle_id' => 1, 'slug' => 'c', 'title' => 'Category']);

        $this->assertNull(PaidVoteService::checkoutClosesAt(10));
        $this->assertSame(PaidVoteService::votingOpenFor(10), PaidVoteService::checkoutOpenFor(10),
            'with no close published, checkout tracks the ballot exactly');
    }

    // ── unchanged guarantees ────────────────────────────────────────────────

    public function test_minting_is_still_idempotent_across_the_new_path(): void
    {
        // The browser callback and the webhook both call mint(). Widening the
        // window must not widen the number of times votes land.
        $this->seedClosedCycle(1);
        $id = $this->order('-2 hours');

        PaidVoteService::mint($id);
        PaidVoteService::mint($id);

        $this->assertSame(20, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
        $this->assertSame(1, (int) DB::table('gates_votes')->where('donation_id', $id)->count());
    }

    public function test_an_unconfirmed_order_never_mints_however_the_clock_reads(): void
    {
        $this->seedOpenCycle();
        $id = $this->order('-1 hour');
        DB::table('gates_donations')->where('id', $id)->update(['status' => 'pending']);

        $this->assertFalse(PaidVoteService::mint($id)['ok']);
    }

    // ── the hole the wider window would otherwise have opened ───────────────

    public function test_an_order_already_refunded_never_mints(): void
    {
        // Before this change the phase gate stayed shut forever once a cycle
        // closed, so a refunded order could not mint by construction. The window
        // now reopens for hours — long enough for a reconciler retry to credit
        // votes for money that has already gone back. Free votes, paid for by us.
        $this->seedClosedCycle(1);
        $id = $this->order('-2 hours');
        DB::table('gates_donations')->where('id', $id)
            ->update(['refunded_at' => date('Y-m-d H:i:s')]);

        $r = PaidVoteService::mint($id);

        $this->assertFalse($r['ok']);
        $this->assertSame('ALREADY_REFUNDED', $r['code']);
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
    }

    public function test_an_order_with_a_refund_in_flight_never_mints(): void
    {
        // refund_requested_at is the CLAIM stamp, written BEFORE the gateway is
        // called. Waiting for refunded_at would mint during the seconds a refund
        // is in flight, which is the same money paid out twice.
        $this->seedClosedCycle(1);
        $id = $this->order('-2 hours');
        DB::table('gates_donations')->where('id', $id)
            ->update(['refund_requested_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('ALREADY_REFUNDED', PaidVoteService::mint($id)['code']);
    }

    public function test_the_refund_sweep_leaves_an_order_that_can_still_mint(): void
    {
        // The other side of the same race. An order placed before the close, on a
        // cycle that closed an hour ago, is still deliverable — refunding it now
        // would hand somebody their money back at 01:00 and their votes at 03:00.
        $this->seedClosedCycle(1);
        $this->order('-2 hours');

        $svc = new \AfricaGates\Services\RefundService();
        $ref = new \ReflectionMethod($svc, 'terminallyUnminted');
        $don = DB::table('gates_donations')->first();

        $this->assertFalse($ref->invoke($svc, $don),
            'votes are what the person wanted; the refund is the consolation, and it goes second');
    }

    public function test_the_refund_sweep_still_moves_fast_on_a_hopeless_order(): void
    {
        // Placed AFTER the close, so no mint will ever succeed. Making this wait
        // for the mint window would delay exactly the people who are owed money.
        $this->seedClosedCycle(2);
        $this->order('-1 hour');

        $svc = new \AfricaGates\Services\RefundService();
        $ref = new \ReflectionMethod($svc, 'terminallyUnminted');

        $this->assertTrue($ref->invoke($svc, DB::table('gates_donations')->first()));
    }
}
