<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\BonusVoteService;
use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnPool as Pool;
use AfricaGates\Services\CommunityReturnService as Ret;
use AfricaGates\Services\CommunityReturnWithdrawals as Wd;
use AfricaGates\Services\MemberWallet as W;
use AfricaGates\Services\PaymentService;
use AfricaGates\Services\RuleEngine;
use AfricaGates\Services\WalletAutoPay as Auto;
use AfricaGates\Services\WalletHistory;
use AfricaGates\Services\WalletVotes;
use AfricaGates\Services\MergeService;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The community return audit (owner, 3 Nov 2026: "you'll see a money sharing feature. Audit
 * it"). Each case is a way money reached the wrong place, or was locked away from the right
 * one, while every screen looked ordinary:
 *
 *   · a refund or chargeback by webhook left the share in place, so a pool paid money the
 *     payer already had back to whoever reached the target;
 *   · a refund after a pool settled — absorbed by the platform, by design — then failed the
 *     nominee's audit for ever, blocking every withdrawal they would ever ask for;
 *   · a vote paid from a wallet carried its nominee over the pool's target;
 *   · under the threshold rule, fraud-flagged votes and one member's points redemptions
 *     counted as that many different people;
 *   · a pool settled inside the late-payment grace, so a vote paid for in time put nothing in;
 *   · a request approved, recorded and sent after its claim had passed to somebody else;
 *   · "Record as paid" landing on a row the automatic sender had just claimed: paid twice;
 *   · a new holder of a page seeing the previous holder's withdrawals and bank details;
 *   · a pool's settlement shown in the wallet as two "corrections by our team".
 */
final class CommunityReturnAuditTest extends TestCase
{
    private const BANK = ['bank' => 'GTBank', 'bank_code' => '058', 'account_name' => 'Ada Obi', 'account_number' => '0123456789'];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => Carbon::now()->subDays(5)->toDateTimeString(), 'voting_close' => Carbon::now()->addDays(5)->toDateTimeString()]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        foreach ([1 => 'Ada Obi', 2 => 'Bola Ade', 3 => 'Chuka Eze'] as $id => $n) {
            DB::table('gates_nominees')->insert(['id' => $id, 'category_id' => 1, 'name' => $n, 'status' => 'approved']);
        }
        foreach ([7 => 'Ada Obi', 8 => 'Bola Ade'] as $id => $name) {
            DB::table('gates_users')->insert(['id' => $id, 'name' => $name, 'email' => strtolower(explode(' ', $name)[0]) . '@example.com',
                'status' => 'active', 'email_verified' => 1, 'created_at' => Carbon::now()->subDays(30)->toDateTimeString()]);
        }
        DB::table('gates_nominee_claims')->insert(['nominee_id' => 1, 'user_id' => 7, 'status' => 'active', 'active_nominee_id' => 1,
            'claimed_at' => Carbon::now()->subDays(20)->toDateTimeString(), 'activated_at' => Carbon::now()->subDays(20)->toDateTimeString()]);
        (new RuleEngine())->merge('global', null, ['community_return_terms_published' => true, 'community_return_tax_note' => 'No tax is withheld.',
            'community_return_min_withdrawal_naira' => 100]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    private function pool(): void
    {
        $r = Milestone::saveEditionRules(1, ['model' => 'pool', 'full_pct' => 20, 'milestone_naira' => 30000]);   // 100 votes each
        $this->assertTrue($r['ok'], $r['message']);
    }

    private function released(): void
    {
        $this->assertTrue(Milestone::saveEditionRules(1, ['model' => 'milestone', 'full_pct' => 10, 'locked_pct' => 10, 'milestone_naira' => 10000])['ok']);
        $this->contribution(1, 10000);
        $this->assertTrue(Milestone::confirm(1, 1)['ok']);
    }

    private function votes(int $nominee, int $n, string $type = 'standard', string $hash = ''): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => 1, 'voter_email_hash' => $hash !== '' ? $hash : bin2hex(random_bytes(8)),
            'vote_type' => $type, 'weight' => $n, 'fraud_flag' => 0]);
    }

    private function contribution(int $nominee, int $naira): int
    {
        $id = (int) DB::table('gates_donations')->insertGetId(['donor_name' => 'Chidi', 'donor_email' => 'chidi@example.com',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 0, 'votes_used' => 1, 'intent_nominee_id' => $nominee,
            'payment_ref' => 'R' . bin2hex(random_bytes(4)), 'status' => 'confirmed', 'created_at' => Carbon::now()->subMinute()->toDateTimeString()]);
        $this->assertTrue(Ret::accrue($id)['ok']);
        return $id;
    }

    private function close(int $hoursAgo = 24): void
    {
        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'results',
            'voting_close' => Carbon::now()->subHours($hoursAgo)->toDateTimeString()]);
    }

    private function handOver(): void
    {
        DB::table('gates_nominee_claims')->where('user_id', 7)->update(['status' => 'revoked', 'active_nominee_id' => null]);
        DB::table('gates_nominee_claims')->insert(['nominee_id' => 1, 'user_id' => 8, 'status' => 'active', 'active_nominee_id' => 1,
            'claimed_at' => Carbon::now()->subDays(10)->toDateTimeString(), 'activated_at' => Carbon::now()->subDays(10)->toDateTimeString()]);
    }

    private function request(): int
    {
        $r = W::withdraw(7, 'page-1', 60000, self::BANK, true, $this->ps());
        $this->assertTrue($r['ok'], $r['message']);
        return (int) explode('-', $r['key'])[1];
    }

    /** A gateway that records what it was asked to send, and can act during a verify. */
    private object $gw;
    private function ps(): PaymentService
    {
        $this->gw ??= (object) ['transfers' => [], 'onVerify' => null];
        return new class ($this->gw) extends PaymentService {
            public function __construct(private object $gw) { parent::__construct(); }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function resolveAccount(string $n, string $c): array { return ['ok' => true, 'name' => 'OBI ADA', 'reachable' => true, 'message' => '']; }
            public function createTransferRecipient(string $name, string $n, string $c): array { return ['ok' => true, 'code' => 'RCP', 'message' => '']; }
            public function initiateTransferKobo(int $k, string $r, string $ref, string $why = ''): array {
                $this->gw->transfers[] = $ref;
                return ['ok' => true, 'status' => 'pending', 'transfer_code' => 'TRF', 'transfer_id' => '1', 'message' => 'queued'];
            }
            public function verifyTransfer(string $ref): array {
                if ($this->gw->onVerify) { $f = $this->gw->onVerify; $this->gw->onVerify = null; $f(); }
                return ['ok' => false, 'status' => '', 'reachable' => true, 'absent' => true, 'message' => 'not found'];
            }
        };
    }

    // ── earning ──────────────────────────────────────────────────────────────────

    public function test_a_refund_by_webhook_takes_the_share_out_of_the_pool(): void
    {
        $this->pool();
        $this->votes(2, 100);
        $don = $this->contribution(1, 10000);                       // 20% → ₦2,000 into the pool
        $this->assertSame(200000, Pool::poolKobo(1));
        $this->assertTrue(BonusVoteService::clawbackDonation($don, null, 'webhook:paystack:refund.processed')['ok']);
        $this->assertSame(0, Pool::poolKobo(1), 'the pool still holds money the payer has had back');
        $this->close();
        $this->assertTrue(Pool::settle(1, 1)['ok']);
        $this->assertSame(0, (int) DB::table('gates_community_returns')->where('nominee_id', 2)->sum('amount_kobo'),
            'a refunded contribution was paid to the nominee who reached the target');
    }

    public function test_a_refund_after_the_pool_settled_is_absorbed_and_blocks_nobody(): void
    {
        $this->pool();
        $this->votes(1, 100);
        $don = $this->contribution(1, 10000);
        $this->close();
        $this->assertTrue(Pool::settle(1, 1)['ok']);
        $this->assertTrue(BonusVoteService::clawbackDonation($don)['ok']);
        $this->assertSame('POOL_SETTLED', Ret::reverse($don, 'again')['code'], 'a settled share is final');
        $this->assertSame([], Ret::audit(null, 1), 'an absorbed refund failed the nominee’s audit, which blocks every withdrawal');
        $valid = array_values(array_filter(Wd::checks(1, 7, true, self::BANK)['checks'], static fn ($c) => $c['key'] === 'valid'))[0];
        $this->assertTrue($valid['ok'], $valid['detail']);
        // Not a blanket pass: under a rule with no pool, an unreversed refund is still a fault.
        DB::table('gates_award_cycles')->insert(['id' => 2, 'programme_id' => 1, 'year' => (int) date('Y') + 1, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 2, 'cycle_id' => 2, 'slug' => 'c2', 'title' => 'Dance']);
        DB::table('gates_nominees')->insert(['id' => 9, 'category_id' => 2, 'name' => 'Dayo', 'status' => 'approved']);
        $this->assertTrue(Milestone::saveEditionRules(2, ['model' => 'flat', 'full_pct' => 10])['ok']);
        $d2 = $this->contribution(9, 5000);
        DB::table('gates_donations')->where('id', $d2)->update(['refunded_at' => Carbon::now()->toDateTimeString()]);
        $this->assertNotSame([], Ret::audit(null, 9));
    }

    public function test_a_vote_paid_from_a_wallet_carries_nobody_to_the_pool_target(): void
    {
        $this->pool();
        $this->votes(1, 99);
        $this->votes(1, 1, 'paid', WalletVotes::HASH_PREFIX . '7:' . bin2hex(random_bytes(6)));
        $this->votes(2, 100, 'paid', 'paidvote:12:' . bin2hex(random_bytes(4)));   // a card-bought vote still counts
        $rows = array_column(Pool::progress(1)['rows'], null, 'id');
        $this->assertFalse($rows[1]['reached'], 'last edition’s share bought this edition’s target');
        $this->assertSame(99, $rows[1]['votes']);
        $this->assertTrue($rows[2]['reached']);
    }

    public function test_the_threshold_counts_people_not_rows(): void
    {
        // Five redemptions by one member, three fraud-flagged votes, one wallet vote, two people.
        for ($i = 0; $i < 5; $i++) $this->votes(3, 1, 'bonus', 'points:7:' . bin2hex(random_bytes(4)));
        for ($i = 0; $i < 3; $i++) DB::table('gates_votes')->insert(['nominee_id' => 3, 'category_id' => 1, 'voter_email_hash' => bin2hex(random_bytes(8)),
            'vote_type' => 'standard', 'weight' => 1, 'fraud_flag' => 1]);
        $this->votes(3, 1, 'paid', WalletVotes::HASH_PREFIX . '8:' . bin2hex(random_bytes(6)));
        $this->votes(3, 1);
        $this->votes(3, 1);
        $this->assertSame(3, Ret::supporterCount(3), 'one member redeeming points is one person, and a flagged vote is nobody');
    }

    public function test_the_pool_is_not_settled_while_a_late_payment_can_still_arrive(): void
    {
        $this->pool();
        $this->votes(1, 100);
        $this->contribution(1, 10000);
        $this->close(1);                                            // closed an hour ago; grace is six
        $r = Pool::settle(1, 1);
        $this->assertSame('GRACE', $r['code'], $r['message']);
        $this->assertNull(Pool::settlement(1));
        $this->close(7);
        $this->assertTrue(Pool::settle(1, 1)['ok']);
    }

    // ── paying out ───────────────────────────────────────────────────────────────

    public function test_a_request_is_not_paid_to_somebody_who_no_longer_holds_the_page(): void
    {
        $this->released();
        $id = $this->request();
        $this->handOver();
        $a = Wd::approve($id, 1);
        $this->assertFalse($a['ok'], 'approved for the former holder');
        $this->assertStringContainsString('no longer holds', $a['message']);

        // Approved before the handover: neither recording nor sending may go ahead.
        DB::table('gates_return_withdrawals')->where('id', $id)->update(['status' => Wd::WD_APPROVED]);
        $this->assertFalse(Wd::markPaid($id, 'MANUAL-1', 1, false, $this->ps())['ok'], 'recorded as paid to the former holder');
        $s = Auto::release($this->ps(), Auto::KIND_RETURN, $id, 1);
        $this->assertSame([], $this->gw->transfers, 'sent by transfer to the former holder: ' . $s['message']);
        $this->assertSame(Wd::WD_APPROVED, (string) DB::table('gates_return_withdrawals')->where('id', $id)->value('status'));

        // And the account that asked must still be verified.
        DB::table('gates_nominee_claims')->where('user_id', 8)->delete();
        DB::table('gates_nominee_claims')->where('user_id', 7)->update(['status' => 'active', 'active_nominee_id' => 1]);
        DB::table('gates_users')->where('id', 7)->update(['email_verified' => 0]);
        $this->assertStringContainsString('verified', (string) Wd::whyNotPayable($id));
    }

    public function test_recording_by_hand_never_lands_on_a_row_the_sender_has_claimed(): void
    {
        $this->released();
        $id = $this->request();
        $this->assertTrue(Wd::approve($id, 1)['ok']);
        // Held after one attempt, so a person recording it by hand asks Paystack first — and
        // while that call is out, "Send by transfer" claims the row.
        DB::table('gates_return_withdrawals')->where('id', $id)->update(['auto_state' => Auto::A_HELD, 'transfer_ref' => 'agwd-x', 'transfer_attempts' => 1]);
        $this->ps();
        $this->gw->onVerify = static fn () => DB::table('gates_return_withdrawals')->where('id', $id)->update(['auto_state' => Auto::A_CHECKING]);
        $r = Wd::markPaid($id, 'MANUAL-1', 1, false, $this->ps());
        $this->assertFalse($r['ok'], 'recorded by hand while the sender was paying it');
        $this->assertSame(Wd::WD_APPROVED, (string) DB::table('gates_return_withdrawals')->where('id', $id)->value('status'));
        $this->assertSame(0, (int) DB::table('gates_community_returns')->where('entry_type', 'payout')->count());
    }

    public function test_a_withdrawal_is_shown_to_the_member_who_asked_and_nobody_else(): void
    {
        $this->released();
        $id = $this->request();
        $this->handOver();
        $this->assertContains('crw-' . $id, array_column(W::transactions(7), 'key'), 'the person who asked lost their own withdrawal');
        $this->assertNotContains('crw-' . $id, array_column(W::transactions(8), 'key'), 'the new holder sees the old holder’s bank details');
    }

    // ── what the wallet says ─────────────────────────────────────────────────────

    public function test_the_wallet_tells_a_pool_nominee_what_decides_their_money(): void
    {
        $this->pool();
        $this->votes(1, 60);
        $this->contribution(1, 10000);
        $p = array_values(array_filter(W::pockets(7), static fn ($x) => $x['kind'] === 'return'))[0];
        $this->assertSame(['target' => 100, 'votes' => 60, 'to_go' => 40, 'reached' => false], $p['pool']);
        $this->assertStringNotContainsString('milestone', $p['coming_note'], 'a pool has no milestone to confirm');
        $this->assertTrue($p['in_pool'], 'the card calls money that may go to somebody else "Coming"');

        $this->votes(1, 40);
        $this->close();
        $this->assertTrue(Pool::settle(1, 1)['ok']);
        $titles = array_column(W::transactions(7), 'title');
        $this->assertContains('Into the edition pool', $titles);
        $this->assertContains('Your share of the edition pool', $titles);
        $this->assertNotContains('Correction by our team', $titles, 'settling read as our team taking money and handing some back');
    }

    // ── the second pass ──────────────────────────────────────────────────────────

    public function test_a_merge_takes_the_return_with_it(): void
    {
        $this->assertTrue(Milestone::saveEditionRules(1, ['model' => 'flat', 'full_pct' => 10])['ok']);
        DB::table('gates_nominees')->insert(['id' => 4, 'category_id' => 1, 'name' => 'Ada O.', 'status' => 'approved']);
        $this->contribution(4, 10000);                                   // ₦1,000 on the duplicate
        $r = MergeService::mergeNominees(1, [4], 1);
        $this->assertTrue($r['ok'] ?? false, json_encode($r));
        $this->assertSame(100000, (int) DB::table('gates_community_returns')->where('nominee_id', 1)->sum('amount_kobo'),
            'the merged-away page kept the money, where nobody can withdraw it');
        $this->assertSame([], Ret::audit(null, 1), 'credited to a different nominee');
        MergeService::unmerge(4, 1);
        $this->assertSame(100000, (int) DB::table('gates_community_returns')->where('nominee_id', 4)->sum('amount_kobo'), 'an unmerge must put it back');
    }

    public function test_the_pool_target_keeps_the_price_it_was_set_at(): void
    {
        $this->pool();                                                    // ₦30,000 at ₦100 → 100 each
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'vote_price_naira'], ['value' => '300']);
        $this->assertSame(100, Pool::target(1)['each'], 'a price change in Settings moved every nominee’s target mid-edition');
    }

    public function test_winners_are_told_their_share_when_the_pool_settles(): void
    {
        $this->pool();
        $this->votes(1, 100);
        $this->contribution(1, 10000);
        $this->close();
        $this->assertTrue(Pool::settle(1, 1)['ok']);
        $mail = new class extends OtpService {
            public array $sent = [];
            public function __construct() { parent::__construct(['username' => 'u', 'password' => 'p']); }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = '', string $unsubscribeUrl = '', array $attachments = [], string $preheader = '', int $heroHeight = 0, string $why = ''): array
            { $this->sent[] = [$to, $subject, $plainBody]; return ['success' => true]; }
        };
        $this->assertSame(1, Pool::notifyWinners(1, $mail));
        $this->assertSame('ada@example.com', $mail->sent[0][0]);
        $this->assertStringContainsString('₦2,000.00', $mail->sent[0][2]);
    }

    public function test_the_statement_counts_everything_that_left_the_balance(): void
    {
        $this->pool();
        $this->votes(1, 100);
        $this->votes(2, 100);
        $this->contribution(1, 10000);                                    // ₦2,000 in, then into the pool
        $this->close();
        $this->assertTrue(Pool::settle(1, 1)['ok']);                      // ₦1,000 back as Ada's share
        $all = W::transactions(7);
        $q = WalletHistory::query($all, WalletHistory::params(['month' => 'all'], $all));
        $this->assertSame((int) Ret::wallet(1)['available_kobo'], $q['in_kobo'] - $q['out_kobo'],
            'in minus out is not the balance: "Into the edition pool" was never counted out');
    }
}
