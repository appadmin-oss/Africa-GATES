<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ReferralService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * What the platform owes its referrers, which nothing could previously read.
 *
 * `HANDOFF.md` §4: commission accrues to `gates_referral_credits` with
 * `paid_out_at IS NULL` meaning owed, and no screen anywhere added it up — the member's
 * own page showed their balance and that was all. A liability nobody can read is one that
 * gets discovered by the person chasing it.
 *
 * The arithmetic worth holding is that **the threshold is per member, so the total is not
 * one sum.** Earnings unlock at THRESHOLD referrals, so what is payable today and what is
 * owed eventually are different numbers, and reporting either alone misleads in a
 * direction that matters to whoever reads it.
 */
final class ReferralLiabilityTest extends TestCase
{
    private function credit(int $userId, int $paid, int $commission, ?string $paidOut = null): void
    {
        // `source_type`/`source_id` carry the idempotency guarantee since credits stopped
        // being event-only — the unique index moved onto the pair. Set to match what
        // ReferralService actually writes, so the fixture is the shape production is.
        $reg = random_int(1, 1_000_000);
        DB::table('gates_referral_credits')->insert([
            'code_id' => 1, 'user_id' => $userId, 'registration_id' => $reg,
            'source_type' => 'registration', 'source_id' => $reg,
            'event_id' => 1, 'paid_naira' => $paid, 'commission_naira' => $commission,
            'rate_bps' => ReferralService::RATE_BPS, 'paid_out_at' => $paidOut,
            'created_at' => '2026-08-01 10:00:00',
        ]);
    }

    /** N credits for one member, so the threshold can be crossed or not on purpose. */
    private function credits(int $userId, int $count, int $paid = 10000): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->credit($userId, $paid, (int) ($paid * ReferralService::RATE_BPS / 10000));
        }
    }

    public function test_nothing_owed_reads_as_zero_not_as_an_error(): void
    {
        $l = ReferralService::liability();

        $this->assertSame(0, $l['payable_naira']);
        $this->assertSame(0, $l['owed_members']);
        $this->assertSame([], $l['rows']);
        // The gate and the rate travel with the numbers, so a template never hardcodes them.
        $this->assertSame(ReferralService::THRESHOLD, $l['threshold']);
        $this->assertSame(10.0, $l['rate_pct']);
    }

    /**
     * The distinction the panel exists to make. A member one referral short is owed
     * money eventually and nothing today; folding them into one total would either
     * overstate the debt or hide it.
     */
    public function test_a_member_below_the_threshold_is_locked_not_payable(): void
    {
        $this->credits(1, ReferralService::THRESHOLD - 1);

        $l = ReferralService::liability();

        $this->assertSame(0, $l['payable_naira'], 'a locked balance must not be reported as payable');
        $this->assertGreaterThan(0, $l['locked_naira']);
        $this->assertSame(0, $l['owed_members']);
        // Still listed, because somebody reading the page needs to see them coming.
        $this->assertCount(1, $l['rows']);
        $this->assertFalse($l['rows'][0]['unlocked']);
        $this->assertSame(1, $l['rows'][0]['remaining']);
    }

    public function test_a_member_at_the_threshold_becomes_payable(): void
    {
        $this->credits(1, ReferralService::THRESHOLD);

        $l = ReferralService::liability();

        $this->assertSame(0, $l['locked_naira']);
        $this->assertSame(ReferralService::THRESHOLD * 1000, $l['payable_naira']);
        $this->assertSame(1, $l['owed_members']);
        $this->assertTrue($l['rows'][0]['unlocked']);
    }

    public function test_what_was_already_paid_is_deducted_and_not_owed_twice(): void
    {
        $this->credits(1, ReferralService::THRESHOLD);
        // One of them settled.
        DB::table('gates_referral_credits')->where('user_id', 1)->limit(1)
          ->update(['paid_out_at' => '2026-08-15 09:00:00']);

        $l = ReferralService::liability();

        $this->assertSame(1000, $l['paid_out_naira']);
        $this->assertSame((ReferralService::THRESHOLD - 1) * 1000, $l['payable_naira'],
            'a settled credit is still being counted as owed');
    }

    public function test_a_fully_settled_member_drops_off_the_list_but_stays_in_the_totals(): void
    {
        $this->credits(1, ReferralService::THRESHOLD);
        DB::table('gates_referral_credits')->where('user_id', 1)
          ->update(['paid_out_at' => '2026-08-15 09:00:00']);

        $l = ReferralService::liability();

        $this->assertSame(0, $l['payable_naira']);
        $this->assertSame([], $l['rows'], 'a member owed nothing should not be on a list of debts');
        $this->assertSame(1, $l['earners'], 'they still earned — the history does not vanish');
        $this->assertSame(ReferralService::THRESHOLD * 1000, $l['paid_out_naira']);
    }

    /** Two members, one over the gate and one under: both totals must be right at once. */
    public function test_locked_and_payable_are_summed_separately_across_members(): void
    {
        $this->credits(1, ReferralService::THRESHOLD);       // payable
        $this->credits(2, 2);                                // locked

        $l = ReferralService::liability();

        $this->assertSame(ReferralService::THRESHOLD * 1000, $l['payable_naira']);
        $this->assertSame(2000, $l['locked_naira']);
        $this->assertSame(2, $l['earners']);
        $this->assertSame(1, $l['owed_members'], 'only the unlocked member is owed today');
    }

    public function test_the_biggest_debt_is_listed_first(): void
    {
        $this->credits(1, ReferralService::THRESHOLD);            // ₦10,000 owed
        $this->credits(2, ReferralService::THRESHOLD, 50000);     // ₦50,000 owed

        $rows = ReferralService::liability()['rows'];

        $this->assertSame(2, $rows[0]['user_id'], 'settling works largest-first');
        $this->assertGreaterThan($rows[1]['owed'], $rows[0]['owed']);
    }

    public function test_rows_carry_a_name_even_when_the_member_row_is_gone(): void
    {
        $this->credits(77, ReferralService::THRESHOLD);

        $rows = ReferralService::liability()['rows'];

        // No gates_users row for 77 — the debt is the point, so it must still render.
        $this->assertSame('Member #77', $rows[0]['name']);
        $this->assertSame('', $rows[0]['email']);
    }

    /**
     * The panel itself, rendered. A Twig mistake in it is a 500 on the page somebody
     * opens to check whether money arrived, and no unit test of the arithmetic would
     * catch a mistyped filter or a variable the controller never passes.
     */
    public function test_the_finance_page_renders_the_liability_panel(): void
    {
        $this->credits(1, ReferralService::THRESHOLD, 25000);
        DB::table('gates_users')->insert([
            'id' => 1, 'name' => 'Ada Nwosu', 'email' => 'ada@example.test',
            'points' => 0, 'email_verified' => 1,
        ]);
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get(\AfricaGates\Admin\Controllers\FinanceController::class)->index(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/admin/finance'),
            (new \Slim\Psr7\Factory\ResponseFactory())->createResponse()
        );

        $this->assertSame(200, $res->getStatusCode(), 'the finance page did not render');
        $html = (string) $res->getBody();

        $this->assertStringContainsString('fi-p-ref', $html, 'the referrals panel is not on the page');
        $this->assertStringContainsString('Payable now', $html);
        // ₦25,000 owed: ten referrals at 10% of ₦25,000.
        $this->assertStringContainsString('25,000', $html, 'the payable figure is not rendered');
        $this->assertStringContainsString('Ada Nwosu', $html);

        // And no payout control, deliberately — see the panel's own comment.
        $this->assertStringNotContainsString('Mark as paid', $html);
    }

    public function test_the_limit_caps_the_list_without_distorting_the_totals(): void
    {
        for ($u = 1; $u <= 4; $u++) $this->credits($u, ReferralService::THRESHOLD);

        $l = ReferralService::liability(2);

        $this->assertCount(2, $l['rows'], 'the list is capped');
        $this->assertSame(4, $l['owed_members'], 'but the totals still count everybody');
        $this->assertSame(4 * ReferralService::THRESHOLD * 1000, $l['payable_naira']);
    }
}
