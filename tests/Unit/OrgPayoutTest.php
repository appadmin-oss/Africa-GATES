<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{OrgAuth, OrgPayout, PartnerOrg, PaymentService};
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Payouts: the half of this feature where being wrong sends a charity's money twice.
 *
 * Every test here is about one of two questions — how much may be asked for, and what a
 * gateway event is allowed to do to a payout that already settled.
 */
class OrgPayoutTest extends TestCase
{
    /** A PaymentService that never reaches the network. */
    private function offlinePayments(): PaymentService
    {
        return new class extends PaymentService {
            public array $sent = [];
            public function isEnabled(string $provider): bool { return true; }
            protected function request(string $method, string $url, ?array $jsonBody, array $headers): array
            {
                $this->sent[] = [$method, $url, $jsonBody];
                return ['ok' => false, 'code' => 0, 'json' => [], 'raw' => ''];
            }
        };
    }

    /**
     * A slug unique to each call.
     *
     * Not for tidiness: the MySQL parity run isolates tests by transaction rollback, and any
     * test anywhere in the suite that issues DDL implicitly COMMITs — so rows can survive
     * into a later file for reasons that have nothing to do with either test. A fixture that
     * cannot collide does not care.
     */
    private function uniqueSlug(string $stem = 'bright-futures'): string
    {
        return $stem . '-' . bin2hex(random_bytes(4));
    }

    private function makeOrg(array $over = []): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug' => $this->uniqueSlug(), 'name' => 'Bright Futures Initiative',
            'cac_number' => 'IT/1', 'scuml_number' => 'SC-1',
            'status' => PartnerOrg::STATUS_APPROVED, 'subaccount_code' => 'ACCT_x',
            'platform_fee_bps' => 0, 'settlement_bank' => '058',
        ]);
    }

    private function confirmedGift(int $orgId, int $amount, int $fee = 0): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'D', 'donor_email' => 'd@example.com', 'amount_naira' => $amount,
            'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0,
            'payment_ref' => 'AFG-GIVE-' . bin2hex(random_bytes(4)), 'status' => 'confirmed',
            'recipient_org_id' => $orgId, 'platform_fee_naira' => $fee,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function payout(int $orgId, int $amount, string $status): string
    {
        $ref = OrgPayout::mintReference($orgId);
        DB::table('gates_org_payouts')->insert([
            'org_id' => $orgId, 'reference' => $ref, 'amount_naira' => $amount,
            'status' => $status, 'requested_at' => date('Y-m-d H:i:s'),
        ]);
        return $ref;
    }

    // ──────────────────────────── what may be asked for ─────────────────────

    public function test_available_is_net_donations_less_what_is_already_out(): void
    {
        $id = $this->makeOrg();
        $this->confirmedGift($id, 100000, 5000);      // net 95,000
        $this->payout($id, 20000, OrgPayout::ST_PENDING);

        $this->assertSame(75000, OrgPayout::available($id));
    }

    /**
     * A payout that conclusively failed must give the money back to the balance. A charity
     * whose transfer bounced and whose funds stayed held has money it can see and cannot ask
     * for, which is indistinguishable from us having taken it.
     */
    public function test_a_failed_or_reversed_payout_releases_the_balance_again(): void
    {
        foreach ([OrgPayout::ST_FAILED, OrgPayout::ST_REVERSED, OrgPayout::ST_ABANDONED] as $dead) {
            // delete(), never truncate(). TRUNCATE is DDL and DDL implicitly COMMITs in MySQL,
            // which breaks the per-test transaction the harness rolls back — rows leak into
            // whichever test runs next, in a different file, for no visible reason. TestCase
            // documents this trap; the MySQL parity run is what found that I had walked into it.
            DB::table('gates_org_payouts')->delete();
            DB::table('gates_donations')->delete();
            DB::table('gates_partner_orgs')->delete();

            $id = $this->makeOrg();
            $this->confirmedGift($id, 50000);
            $this->payout($id, 50000, $dead);

            $this->assertSame(50000, OrgPayout::available($id), "$dead should release the hold");
        }
    }

    public function test_a_successful_payout_keeps_holding_the_balance(): void
    {
        $id = $this->makeOrg();
        $this->confirmedGift($id, 50000);
        $this->payout($id, 30000, OrgPayout::ST_SUCCESS);

        $this->assertSame(20000, OrgPayout::available($id));
    }

    public function test_a_request_larger_than_the_balance_is_refused(): void
    {
        $id = $this->makeOrg();
        $this->confirmedGift($id, 10000);

        $r = OrgPayout::request($this->offlinePayments(), $id, 50000, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('more than is available', $r['message']);
        $this->assertSame(0, DB::table('gates_org_payouts')->count(), 'Nothing should be recorded.');
    }

    public function test_a_suspended_organisation_cannot_withdraw(): void
    {
        $id = $this->makeOrg(['status' => PartnerOrg::STATUS_SUSPENDED]);
        $this->confirmedGift($id, 90000);

        $r = OrgPayout::request($this->offlinePayments(), $id, 5000, 1);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, DB::table('gates_org_payouts')->count());
    }

    public function test_below_the_minimum_is_refused(): void
    {
        $id = $this->makeOrg();
        $this->confirmedGift($id, 90000);
        $this->assertFalse(OrgPayout::request($this->offlinePayments(), $id, 10, 1)['ok']);
    }

    // ─────────────────────────── the reference itself ───────────────────────

    /**
     * Transfer references are stricter than transaction references — Paystack accepts only
     * lowercase alphanumerics, hyphen and underscore — and ours is also the idempotency key,
     * so it has to be right at mint rather than corrected once it is written on a row.
     */
    public function test_references_are_transfer_safe_and_unique(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $ref = OrgPayout::mintReference(7);
            $this->assertMatchesRegularExpression('/^[a-z0-9._-]+$/', $ref, $ref);
            $this->assertArrayNotHasKey($ref, $seen, 'Reference collision.');
            $seen[$ref] = true;
        }
    }

    /** The row exists before the gateway is called, or a lost response loses the payout. */
    public function test_the_row_is_committed_before_the_gateway_is_called(): void
    {
        $id = $this->makeOrg();
        $this->confirmedGift($id, 50000);

        $r = OrgPayout::request($this->offlinePayments(), $id, 20000, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, DB::table('gates_org_payouts')->where('reference', $r['reference'])->count());
    }

    // ───────────────────────── gateway state transitions ────────────────────

    /**
     * Webhook ordering is not guaranteed and deliveries can repeat. A late `pending`
     * arriving after a `success` must be a no-op, not a settled payout reopening itself.
     */
    public function test_a_terminal_payout_cannot_be_moved_by_a_late_event(): void
    {
        $id  = $this->makeOrg();
        $ref = $this->payout($id, 10000, OrgPayout::ST_SUCCESS);

        $this->assertFalse(OrgPayout::applyStatus($ref, OrgPayout::ST_PENDING));
        $this->assertSame(
            OrgPayout::ST_SUCCESS,
            DB::table('gates_org_payouts')->where('reference', $ref)->value('status')
        );
    }

    public function test_a_duplicate_success_event_is_harmless(): void
    {
        $id  = $this->makeOrg();
        $ref = $this->payout($id, 10000, OrgPayout::ST_PENDING);

        $this->assertTrue(OrgPayout::applyStatus($ref, OrgPayout::ST_SUCCESS));
        $this->assertFalse(OrgPayout::applyStatus($ref, OrgPayout::ST_SUCCESS), 'Second delivery must be a no-op.');
        $this->assertSame(1, DB::table('gates_org_payouts')->where('reference', $ref)->count());
    }

    public function test_an_unknown_reference_is_ignored_rather_than_creating_a_payout(): void
    {
        $this->assertFalse(OrgPayout::applyStatus('agpo-99-deadbeef', OrgPayout::ST_SUCCESS));
        $this->assertSame(0, DB::table('gates_org_payouts')->count());
    }

    public function test_success_stamps_a_settlement_time(): void
    {
        $id  = $this->makeOrg();
        $ref = $this->payout($id, 10000, OrgPayout::ST_PENDING);
        OrgPayout::applyStatus($ref, OrgPayout::ST_SUCCESS);

        $this->assertNotNull(DB::table('gates_org_payouts')->where('reference', $ref)->value('settled_at'));
    }

    // ───────────────────────────── tenant isolation ─────────────────────────

    /**
     * The whole multi-tenant risk in one test. A signed-in user's organisation comes from
     * the session and must match the row; a session pointing at somebody else's organisation
     * is worth nothing.
     */
    public function test_a_session_pointing_at_another_organisation_is_rejected(): void
    {
        $a = $this->makeOrg();
        $b = $this->makeOrg(['slug' => 'hope-alive', 'name' => 'Hope Alive']);

        OrgAuth::createUser($a, 'treasurer@bright.example', 'a-very-long-password', 'T', 'owner');
        $uid = (int) DB::table('gates_org_users')->where('email', 'treasurer@bright.example')->value('id');

        $_SESSION['org_user_id'] = $uid;
        $_SESSION['org_id']      = $a;
        $this->assertNotNull(OrgAuth::user(), 'A correct session should resolve.');

        // Tamper: same user, another organisation.
        $_SESSION['org_id'] = $b;
        $this->assertNull(OrgAuth::user(), 'A session naming another organisation must not resolve.');

        unset($_SESSION['org_user_id'], $_SESSION['org_id']);
    }

    public function test_a_deactivated_user_loses_access_immediately(): void
    {
        $a = $this->makeOrg();
        OrgAuth::createUser($a, 'gone@bright.example', 'a-very-long-password', 'G', 'owner');
        $uid = (int) DB::table('gates_org_users')->where('email', 'gone@bright.example')->value('id');

        $_SESSION['org_user_id'] = $uid;
        $_SESSION['org_id']      = $a;
        $this->assertNotNull(OrgAuth::user());

        DB::table('gates_org_users')->where('id', $uid)->update(['is_active' => 0]);
        $this->assertNull(OrgAuth::user(), 'Deactivation must take effect without a re-login.');

        unset($_SESSION['org_user_id'], $_SESSION['org_id']);
    }

    /** A viewer reads every figure and moves nothing. */
    public function test_only_an_owner_may_request_a_payout(): void
    {
        $a = $this->makeOrg();
        OrgAuth::createUser($a, 'viewer@bright.example', 'a-very-long-password', 'V', 'viewer');
        $viewer = OrgAuth::findByEmail('viewer@bright.example');

        $this->assertFalse(OrgAuth::canRequestPayout($viewer));
        $this->assertFalse(OrgAuth::canRequestPayout(null));
    }

    /** This login can move money, so it does not accept a short password. */
    public function test_a_dashboard_login_requires_a_long_password(): void
    {
        $a = $this->makeOrg();
        $r = OrgAuth::createUser($a, 'short@bright.example', 'password1', 'S', 'owner');
        $this->assertFalse($r['ok']);
        $this->assertSame(0, DB::table('gates_org_users')->count());
    }

    public function test_one_email_cannot_hold_two_dashboard_logins(): void
    {
        $a = $this->makeOrg();
        $b = $this->makeOrg(['slug' => 'hope-alive', 'name' => 'Hope Alive']);

        $this->assertTrue(OrgAuth::createUser($a, 'dup@bright.example', 'a-very-long-password')['ok']);
        $this->assertFalse(OrgAuth::createUser($b, 'dup@bright.example', 'a-very-long-password')['ok']);
    }

    // ──────────────────────────────── the mode ──────────────────────────────

    /**
     * Transfer mode is the only mode where Africa GATES holds somebody else's charitable
     * money. It must never be what a deployment gets by accident.
     */
    public function test_settlement_is_the_default_mode(): void
    {
        $this->assertSame('settlement', OrgPayout::mode());
    }
}
