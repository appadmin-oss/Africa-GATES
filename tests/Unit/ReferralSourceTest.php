<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ReferralService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Referrals stop being event-only — and the two things they must never pay on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE OMISSIONS ARE THE FEATURE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "Extend referrals to everything the platform sells" is the obvious version of this and it
 * would be a serious mistake in two specific places. Both are refused in code rather than
 * left to configuration, and both have a test here, because a future reader looking at a
 * whitelist will otherwise assume the missing entries were an oversight and add them.
 *
 *  · PAID VOTES. Commission on vote purchases is a standing offer to pay somebody for
 *    bringing in money that moves an award result. The fraud scoring, the collusion scan,
 *    the separate organic_vote_count and the shortlist's organic-only switch all exist to
 *    keep bought support distinguishable from real support. Paying a percentage of it puts
 *    the platform on the other side of its own defences.
 *
 *  · DONATIONS. A cut of a charitable gift paid to whoever forwarded the link is not what
 *    the donor believed they were funding.
 */
final class ReferralSourceTest extends TestCase
{
    private int $userId = 0;
    private int $codeId = 0;
    private string $code = '';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_referral_credits')->delete();
        DB::table('gates_referral_codes')->delete();

        $this->userId = (int) DB::table('gates_users')->insertGetId([
            'email' => 'ref-' . bin2hex(random_bytes(4)) . '@example.test',
            'name'  => 'Referring Member',
        ]);
        $this->code   = 'AG' . strtoupper(bin2hex(random_bytes(3)));
        $this->codeId = (int) DB::table('gates_referral_codes')->insertGetId([
            'user_id' => $this->userId, 'code' => $this->code,
            'created_at' => '2026-08-01 10:00:00',
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function credits(): array
    {
        return DB::table('gates_referral_credits')->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    // ══ what earns ═══════════════════════════════════════════════════════════

    public function test_a_shop_order_earns_the_referrer_a_share(): void
    {
        // The point of the whole change: a member's link used to earn on event tickets and
        // nothing else, so sharing it to the shop was worth nothing to them.
        $this->assertTrue(
            ReferralService::creditSale('shop_order', 4242, $this->code, 25_000)
        );

        $c = $this->credits();
        $this->assertCount(1, $c);
        $this->assertSame('shop_order', $c[0]['source_type']);
        $this->assertSame(4242, (int) $c[0]['source_id']);
        $this->assertSame($this->userId, (int) $c[0]['user_id']);
        $this->assertSame(25_000, (int) $c[0]['paid_naira']);
        $this->assertSame(2_500, (int) $c[0]['commission_naira']);
    }

    public function test_an_event_ticket_still_earns_exactly_as_it_did(): void
    {
        ReferralService::credit((object) [
            'id' => 77, 'referral_code' => $this->code,
            'amount_naira' => 10_000, 'event_id' => 5,
        ]);

        $c = $this->credits();
        $this->assertCount(1, $c);
        $this->assertSame('registration', $c[0]['source_type']);
        $this->assertSame(77, (int) $c[0]['source_id']);
        // The legacy column keeps its value, so reports written against it still work.
        $this->assertSame(77, (int) $c[0]['registration_id']);
        $this->assertSame(5, (int) $c[0]['event_id']);
    }

    // ══ what must never earn ═════════════════════════════════════════════════

    public function test_a_paid_vote_cannot_earn_a_referral(): void
    {
        // Refused because the source is not declared, and it is not declared because paying
        // a member a percentage of vote purchases is a standing offer to be paid for
        // bringing in money that moves an award result.
        $this->assertFalse(
            ReferralService::creditSale('paid_vote', 1, $this->code, 50_000)
        );
        $this->assertSame([], $this->credits());
        $this->assertArrayNotHasKey('paid_vote', ReferralService::SOURCES);
    }

    public function test_a_donation_cannot_earn_a_referral(): void
    {
        $this->assertFalse(
            ReferralService::creditSale('donation', 1, $this->code, 100_000)
        );
        $this->assertSame([], $this->credits());
        $this->assertArrayNotHasKey('donation', ReferralService::SOURCES);
    }

    public function test_the_whitelist_is_in_code_and_not_read_from_settings(): void
    {
        // A whitelist an operator can extend from a form is not a safeguard. Asserted
        // structurally so somebody cannot quietly make it configurable later.
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Services/ReferralService.php');

        $this->assertStringContainsString('public const SOURCES', $src);
        $this->assertMatchesRegularExpression(
            '~if \(!isset\(self::SOURCES\[\$sourceType\]\)\)~', $src
        );
    }

    public function test_an_undeclared_source_is_logged_rather_than_silently_ignored(): void
    {
        // A programming error here means somebody does not get paid, which produces no
        // exception and no complaint until the referrer notices months later.
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Services/ReferralService.php');
        $this->assertStringContainsString('refusing to credit an undeclared source', $src);
    }

    // ══ paid once, whatever the gateway does ═════════════════════════════════

    public function test_the_same_sale_cannot_be_credited_twice(): void
    {
        // A callback and a webhook confirming the same payment is the NORMAL case, not an
        // edge one. The unique index on (source_type, source_id) is the guarantee.
        $this->assertTrue(ReferralService::creditSale('shop_order', 9, $this->code, 5_000));
        $this->assertFalse(ReferralService::creditSale('shop_order', 9, $this->code, 5_000));

        $this->assertCount(1, $this->credits());
    }

    public function test_two_different_shop_orders_both_earn(): void
    {
        // The failure this guards is specific and was live for one commit: registration_id
        // is NOT NULL on SQLite, so a non-registration credit writes 0 — and while the old
        // UNIQUE(registration_id) still existed, the SECOND shop order the platform ever
        // sold was rejected as a duplicate of the first. Silently, because a duplicate key
        // is swallowed on purpose.
        $this->assertTrue(ReferralService::creditSale('shop_order', 11, $this->code, 5_000));
        $this->assertTrue(ReferralService::creditSale('shop_order', 12, $this->code, 5_000));

        $this->assertCount(2, $this->credits());
    }

    public function test_a_ticket_and_a_shop_order_with_the_same_id_are_separate_sales(): void
    {
        // Order #30 and registration #30 are unrelated rows in unrelated tables. Keyed on
        // the id alone, one of them would silently not pay.
        $this->assertTrue(ReferralService::creditSale('registration', 30, $this->code, 4_000));
        $this->assertTrue(ReferralService::creditSale('shop_order',   30, $this->code, 6_000));

        $this->assertCount(2, $this->credits());
    }

    // ══ the ordinary refusals ════════════════════════════════════════════════

    public function test_a_free_sale_earns_nothing(): void
    {
        $this->assertFalse(ReferralService::creditSale('shop_order', 1, $this->code, 0));
        $this->assertSame([], $this->credits());
    }

    public function test_an_unknown_code_earns_nobody_anything(): void
    {
        $this->assertFalse(ReferralService::creditSale('shop_order', 1, 'AGNOTREAL', 5_000));
        $this->assertSame([], $this->credits());
    }

    public function test_the_rate_is_stamped_on_the_credit_and_not_looked_up_later(): void
    {
        // A rate change must not retroactively rewrite what somebody already earned.
        ReferralService::creditSale('shop_order', 1, $this->code, 10_000);

        $c = $this->credits();
        $this->assertSame(ReferralService::RATE_BPS, (int) $c[0]['rate_bps']);
        $this->assertSame(1_000, (int) $c[0]['commission_naira']);
    }

    public function test_commission_never_rounds_up_beyond_the_rate(): void
    {
        // intdiv, so a 10% share of 999 is 99 and not 100. Over thousands of sales the
        // difference is the platform paying out more than it said it would.
        ReferralService::creditSale('shop_order', 1, $this->code, 999);

        $this->assertSame(99, (int) $this->credits()[0]['commission_naira']);
    }

    // ══ the link works anywhere ══════════════════════════════════════════════

    public function test_a_link_followed_on_any_page_is_remembered(): void
    {
        // It used to be captured only on /events pages, so a member who shared their link
        // to the shop or the home page earned nothing — the code was dropped on the first
        // navigation. Indistinguishable, from the referrer's side, from not being paid.
        $_SESSION = [];
        ReferralService::capture($this->code);

        $this->assertSame($this->code, ReferralService::fromSession());
    }

    public function test_the_most_recent_link_wins(): void
    {
        // The link somebody actually followed to reach this purchase is the one that
        // brought them, not the first one they ever clicked.
        $_SESSION = [];
        ReferralService::capture('AGFIRST1');
        ReferralService::capture('AGSECOND');

        $this->assertSame('AGSECOND', ReferralService::fromSession());
    }

    public function test_a_session_created_before_the_rename_still_works(): void
    {
        // A session created minutes before a deploy belongs to a real person mid-purchase.
        $_SESSION = ['event_ref' => $this->code];

        $this->assertSame($this->code, ReferralService::fromSession());
    }

    public function test_rubbish_in_the_url_is_not_stored(): void
    {
        $_SESSION = [];
        ReferralService::capture('   ');
        ReferralService::capture('');

        $this->assertSame('', ReferralService::fromSession());
    }

    public function test_the_capture_runs_on_every_page_and_only_on_get(): void
    {
        // Middleware because the failure mode is FORGETTING: a call at the top of each
        // controller is a rule somebody must remember on the next page they add — which is
        // exactly where a link ends up being shared.
        //
        // GET only: a `?ref=` on a POST is a form action that happens to carry a query
        // string, and honouring it would let a crafted form re-attribute a purchase already
        // in progress.
        $mw = file_get_contents(dirname(__DIR__, 2) . '/src/Middleware/ReferralCaptureMiddleware.php');
        $this->assertStringContainsString("=== 'GET'", $mw);

        $idx = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $this->assertStringContainsString('ReferralCaptureMiddleware', $idx);
    }

    public function test_the_shop_stamps_the_code_on_the_order_not_the_session(): void
    {
        // A webhook confirming a payment made on a phone that has since been closed has no
        // session to read, so a referral held only in one fails on exactly the slow
        // payments where the referrer waited longest.
        $this->assertTrue(DB::schema()->hasColumn('gates_orders', 'referral_code'));

        $co = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/ShopCheckoutController.php');
        $this->assertStringContainsString("'referral_code'  => \\AfricaGates\\Services\\ReferralService::fromSession()", $co);

        $svc = file_get_contents(dirname(__DIR__, 2) . '/src/Services/ShopOrderService.php');
        $this->assertStringContainsString("ReferralService::creditSale(", $svc);
        $this->assertStringContainsString("'shop_order'", $svc);
    }

    public function test_the_shop_credits_only_inside_the_single_writer(): void
    {
        // A concurrent callback and webhook would otherwise both reach it. The unique index
        // would refuse the second — but relying on a constraint to catch what the control
        // flow should is how the one case it does not cover eventually pays twice.
        $svc = file_get_contents(dirname(__DIR__, 2) . '/src/Services/ShopOrderService.php');

        $branch = strpos($svc, 'if ($changed > 0) {');
        $credit = strpos($svc, 'ReferralService::creditSale(');
        $this->assertNotFalse($branch);
        $this->assertNotFalse($credit);
        $this->assertGreaterThan($branch, $credit, 'the credit must sit inside the transition');
    }
}
