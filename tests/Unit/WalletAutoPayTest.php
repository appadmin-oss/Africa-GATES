<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnService as Ret;
use AfricaGates\Services\MemberWallet as W;
use AfricaGates\Services\PaymentService;
use AfricaGates\Services\ReferralService;
use AfricaGates\Services\RuleEngine;
use AfricaGates\Services\UserAccountService;
use AfricaGates\Services\WalletAutoPay as Auto;
use AfricaGates\Services\WalletRisk;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Automatic withdrawals: sent without a person when every check passes, held for one when
 * any doubts, and never paid twice.
 *
 * Almost every case here is an attempt to get money out that should not leave on its own:
 * to an account in somebody else's name, to an account another member uses, straight after
 * a password reset or a change of bank, above the limits, against fraud-flagged money — and
 * the ways a gateway can make a careless engine pay twice: a lost response, a retried
 * request, a webhook delivered twice or after the sweep already settled it.
 */
final class WalletAutoPayTest extends TestCase
{
    private const BANK = ['bank' => 'GTBank', 'bank_code' => '058', 'account_name' => 'Ada Obi', 'account_number' => '0123456789'];

    /** @var object{resolve:array, transfers:list<array>, recipients:int, verify:array, initiate:?array} */
    private object $gw;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => Carbon::now()->subDays(5)->toDateTimeString(),
            'voting_close' => Carbon::now()->addDays(5)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved']);
        foreach ([7 => 'Ada Obi', 8 => 'Bola Ade'] as $id => $name) {
            DB::table('gates_users')->insert(['id' => $id, 'name' => $name, 'email' => strtolower(explode(' ', $name)[0]) . '@example.com',
                'status' => 'active', 'email_verified' => 1, 'created_at' => Carbon::now()->subDays(30)->toDateTimeString()]);
        }
        // An ESTABLISHED member: Ada saved these details a month ago. A first save counts as a
        // change of destination now (review, 20 Oct 2026), so a member with none on file is a
        // takeover case of its own — held below, in its own test.
        DB::table('gates_users')->where('id', 7)->update(['payout_account_number' => self::BANK['account_number'],
            'payout_bank_code' => self::BANK['bank_code'], 'payout_bank_changed_at' => Carbon::now()->subDays(30)->toDateTimeString()]);
        // Automatic payouts are OFF by default now; every test here is about the engine.
        (new RuleEngine())->merge('global', null, ['wallet_auto_payout' => true]);
        $this->gw = (object) ['resolve' => ['ok' => true, 'name' => 'OBI ADA CHIOMA', 'reachable' => true, 'message' => ''],
                              'transfers' => [], 'recipients' => 0, 'verify' => [], 'initiate' => null];
    }

    private function ps(): PaymentService
    {
        $gw = $this->gw;
        return new class ($gw) extends PaymentService {
            public function __construct(private object $gw) { parent::__construct(); }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function resolveAccount(string $n, string $c): array { return $this->gw->resolve; }
            public function createTransferRecipient(string $name, string $n, string $c): array
            {
                $this->gw->recipients++;
                return ['ok' => true, 'code' => 'RCP_' . $n, 'message' => ''];
            }
            public function initiateTransferKobo(int $k, string $r, string $ref, string $why = ''): array
            {
                $this->gw->transfers[] = ['kobo' => $k, 'recipient' => $r, 'ref' => $ref];
                return $this->gw->initiate ?? ['ok' => true, 'status' => 'pending', 'transfer_code' => 'TRF_' . count($this->gw->transfers),
                                               'transfer_id' => '1', 'message' => 'Transfer has been queued'];
            }
            public function verifyTransfer(string $ref): array
            {
                return $this->gw->verify[$ref] ?? ['ok' => false, 'status' => '', 'reachable' => true, 'absent' => true, 'message' => 'Transfer not found'];
            }
        };
    }

    /** Ada holds the page, ₦1,000 of return released (10% of ₦10,000), terms published. */
    private function returnAvailable(int $naira = 10000): void
    {
        DB::table('gates_nominee_claims')->insert([
            'nominee_id' => 1, 'user_id' => 7, 'status' => 'active', 'active_nominee_id' => 1,
            'claimed_at' => Carbon::now()->subDays(20)->toDateTimeString(),
            'activated_at' => Carbon::now()->subDays(20)->toDateTimeString(),
        ]);
        $this->assertTrue(Milestone::saveEditionRules(1, ['model' => 'milestone', 'full_pct' => 10, 'locked_pct' => 10,
                                                          'milestone_naira' => $naira])['ok']);
        Ret::accrue((int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Chidi', 'donor_email' => 'chidi@example.com', 'amount_naira' => $naira,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 10, 'intent_nominee_id' => 1,
            'payment_ref' => 'PV1', 'status' => 'confirmed', 'created_at' => Carbon::now()->subMinute()->toDateTimeString(),
        ]));
        $this->assertTrue(Milestone::confirm(1, 1)['ok']);
        $this->rules(['community_return_terms_published' => true, 'community_return_tax_note' => 'No tax is withheld.',
                      'community_return_min_withdrawal_naira' => 100]);
    }

    private function rules(array $r): void
    {
        (new RuleEngine())->merge('global', null, $r);
    }

    private function withdraw(int $userId = 7, ?int $kobo = 60000, array $bank = self::BANK): array
    {
        $r = W::withdraw($userId, 'page-1', $kobo, $bank, true, $this->ps());
        $this->assertTrue($r['ok'], $r['message']);
        return $r;
    }

    private function row(): object
    {
        return DB::table('gates_return_withdrawals')->orderByDesc('id')->first();
    }

    private function codes(): array
    {
        return array_column(Auto::findings($this->row()), 'code');
    }

    // ══ the pass ═══════════════════════════════════════════════════════════════

    public function test_a_clean_withdrawal_is_sent_at_once_and_settles_on_the_gateways_word(): void
    {
        $this->returnAvailable();
        $r = $this->withdraw();
        $this->assertSame(Auto::A_SENDING, $r['auto']);
        $this->assertStringContainsString('on its way', $r['message']);

        $this->assertCount(1, $this->gw->transfers);
        $this->assertSame(60000 - 1000, $this->gw->transfers[0]['kobo'], 'the amount less the fee, in kobo');
        $row = $this->row();
        $this->assertSame('approved', $row->status);
        $this->assertSame($this->gw->transfers[0]['ref'], $row->transfer_ref, 'the reference committed is the one sent');
        $this->assertSame('OBI ADA CHIOMA', $row->resolved_name);
        $this->assertSame(0, (int) DB::table('gates_community_returns')->where('entry_type', 'payout')->count(),
            'the ledger does not move on our call returning');
        $this->assertSame(W::ST_PROCESSING, W::transaction(7, $r['key'])['status']);

        $this->assertTrue(Auto::applyStatus($row->transfer_ref, 'success', 'Transfer successful'));
        $this->assertSame('paid', $this->row()->status);
        $this->assertSame(Auto::A_DONE, $this->row()->auto_state);
        $this->assertSame(-60000, (int) DB::table('gates_community_returns')->where('entry_type', 'payout')->sum('amount_kobo'));
        $this->assertSame(40000, Ret::wallet(1)['available_kobo']);

        $this->assertFalse(Auto::applyStatus($row->transfer_ref, 'success'), 'a second delivery changes nothing');
        $this->assertFalse(Auto::applyStatus($row->transfer_ref, 'failed'), 'nor does a late failure reopen it');
        $this->assertSame(1, (int) DB::table('gates_community_returns')->where('entry_type', 'payout')->count());
    }

    public function test_the_recipient_is_made_once_and_reused(): void
    {
        $this->returnAvailable();
        $this->withdraw(7, 20000);
        Auto::applyStatus($this->row()->transfer_ref, 'success');
        $this->withdraw(7, 20000);
        $this->assertSame(1, $this->gw->recipients);
        $this->assertCount(2, $this->gw->transfers);
    }

    // ══ every doubt holds it ═══════════════════════════════════════════════════

    public function test_an_account_in_somebody_elses_name_is_held_and_the_member_told_why(): void
    {
        $this->returnAvailable();
        $this->gw->resolve['name'] = 'MUSA IBRAHIM';
        $r = $this->withdraw();
        $this->assertSame(Auto::A_HELD, $r['auto']);
        $this->assertStringContainsString('MUSA IBRAHIM', $r['message']);
        $this->assertSame([], $this->gw->transfers);
        $this->assertSame(['account'], $this->codes());
        $this->assertSame('requested', $this->row()->status, 'held is the same request a person always reviewed');
    }

    public function test_an_account_another_member_uses_is_held(): void
    {
        $this->returnAvailable();
        DB::table('gates_users')->where('id', 8)->update(['payout_account_number' => '0123456789']);
        $this->assertSame(Auto::A_HELD, $this->withdraw()['auto']);
        $this->assertContains('shared', $this->codes());
        $this->assertSame([], $this->gw->transfers);
    }

    public function test_new_bank_details_or_a_password_reset_just_before_is_held(): void
    {
        $this->returnAvailable();
        \AfricaGates\Services\ReferralPayout::saveBank(7, 'Access', 'Ada Obi', '9999999999', '044');
        $this->assertSame(Auto::A_HELD, $this->withdraw()['auto'], 'saved details replaced by these');
        $this->assertContains('takeover', $this->codes());

        // A password reset, on otherwise established details.
        DB::table('gates_return_withdrawals')->delete();
        DB::table('gates_users')->where('id', 7)->update(['payout_account_number' => self::BANK['account_number'],
            'payout_bank_code' => self::BANK['bank_code'], 'payout_bank_changed_at' => Carbon::now()->subDays(30)->toDateTimeString()]);
        // The row issuePasswordReset() writes, spent by consumePasswordReset() three hours ago.
        DB::table('gates_otp_tokens')->insert(['email_hash' => hash('sha256', 'ada@example.com'), 'token_hash' => 'y',
            'purpose' => UserAccountService::RESET_PURPOSE, 'nominee_id' => 7, 'award_id' => 0, 'attempts' => 0, 'is_used' => 1,
            'expires_at' => Carbon::now()->subHours(2)->toDateTimeString(),
            'created_at' => Carbon::now()->subHours(3)->toDateTimeString()]);
        $this->assertSame(Auto::A_HELD, $this->withdraw()['auto']);
        $this->assertSame(['takeover'], $this->codes());
    }

    public function test_the_limits_hold_what_is_above_them(): void
    {
        $this->returnAvailable(100000);   // ₦10,000 available
        $this->rules(['wallet_auto_max_naira' => 5000]);
        $this->withdraw(7, 600000);
        $this->assertContains('amount', $this->codes());

        DB::table('gates_return_withdrawals')->delete();
        $this->rules(['wallet_auto_max_naira' => 100000, 'wallet_auto_new_account_naira' => 2000]);
        $this->withdraw(7, 600000);
        $this->assertSame(['new'], $this->codes(), 'a first payment to a new account above its limit');

        DB::table('gates_return_withdrawals')->delete();
        $this->rules(['wallet_auto_new_account_naira' => 50000, 'wallet_auto_platform_daily_naira' => 5000]);
        DB::table('gates_referral_payouts')->insert(['user_id' => 8, 'amount_naira' => 4000, 'status' => 'requested',
            'auto_state' => Auto::A_SENDING, 'sent_at' => Carbon::now()->subHour()->toDateTimeString()]);
        $this->withdraw(7, 200000);
        $this->assertSame(['platform'], $this->codes(), 'the platform circuit breaker counts every member');
    }

    public function test_fraud_flagged_money_behind_the_balance_is_held(): void
    {
        $this->returnAvailable();
        // The donation's REAL id, never a literal 1: MySQL does not rewind the counter
        // between tests, so on the parity run the return's donation is not row 1 and a
        // flag on row 1 flagged nothing (the test then failed on the only database that
        // matters, while passing on SQLite).
        $donation = (int) DB::table('gates_donations')->where('payment_ref', 'PV1')->value('id');
        DB::table('gates_votes')->insert(['nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => 'paidvote:1:x',
            'vote_type' => 'paid', 'weight' => 10, 'donation_id' => $donation, 'fraud_flag' => 1]);
        $this->withdraw();
        $this->assertContains('valid', $this->codes());
        $this->assertSame([], $this->gw->transfers);
    }

    public function test_a_bank_typed_by_hand_cannot_be_sent_and_is_held(): void
    {
        $this->returnAvailable();
        $this->withdraw(7, 60000, ['bank_code' => ''] + self::BANK);
        $this->assertSame(['account'], $this->codes());
    }

    public function test_a_bank_nobody_could_reach_is_not_a_finding_and_is_tried_again(): void
    {
        $this->returnAvailable();
        $this->gw->resolve = ['ok' => false, 'name' => '', 'reachable' => false, 'message' => 'timeout'];
        $r = $this->withdraw();
        $this->assertSame('', $r['auto']);
        $this->assertNull($this->row()->auto_state, 'released, not held');

        $this->gw->resolve = ['ok' => true, 'name' => 'ADA OBI', 'reachable' => true, 'message' => ''];
        DB::table('gates_return_withdrawals')->update(['requested_at' => Carbon::now()->subMinutes(5)->toDateTimeString()]);
        $this->assertSame(1, Auto::sweep($this->ps())['processed']);
        $this->assertSame(Auto::A_SENDING, $this->row()->auto_state);
    }

    public function test_switched_off_it_is_a_person_s_as_before(): void
    {
        $this->returnAvailable();
        $this->rules(['wallet_auto_payout' => false]);
        $r = $this->withdraw();
        $this->assertSame('', $r['auto']);
        $this->assertNull($this->row()->auto_state);
        $this->assertSame([], $this->gw->transfers);
    }

    // ══ never twice ════════════════════════════════════════════════════════════

    public function test_a_request_cannot_be_processed_twice(): void
    {
        $this->returnAvailable();
        $this->withdraw();
        $id = (int) $this->row()->id;
        $this->assertSame('', Auto::process($this->ps(), Auto::KIND_RETURN, $id)['state']);
        $this->assertCount(1, $this->gw->transfers);
    }

    public function test_a_lost_response_is_asked_about_and_resent_under_the_same_reference_then_held(): void
    {
        $this->returnAvailable();
        $this->gw->initiate = ['ok' => false, 'status' => '', 'transfer_code' => '', 'transfer_id' => null, 'message' => 'timeout'];
        $this->assertSame(Auto::A_SENDING, $this->withdraw()['auto'], 'not "it did not happen"');
        $ref = $this->row()->transfer_ref;

        DB::table('gates_return_withdrawals')->update(['sent_at' => Carbon::now()->subMinutes(30)->toDateTimeString()]);
        Auto::sweep($this->ps());
        $this->assertCount(2, $this->gw->transfers);
        $this->assertSame([$ref, $ref], array_column($this->gw->transfers, 'ref'), 'the same reference, never a fresh one');

        DB::table('gates_return_withdrawals')->update(['transfer_attempts' => Auto::MAX_ATTEMPTS]);
        Auto::sweep($this->ps());
        $this->assertSame(Auto::A_HELD, $this->row()->auto_state);
        $this->assertContains('stuck', $this->codes());
        $this->assertCount(2, $this->gw->transfers);
    }

    public function test_the_sweep_settles_what_the_webhook_never_said(): void
    {
        $this->returnAvailable();
        $this->withdraw();
        $ref = $this->row()->transfer_ref;
        DB::table('gates_return_withdrawals')->update(['sent_at' => Carbon::now()->subMinutes(5)->toDateTimeString()]);
        $this->gw->verify[$ref] = ['ok' => true, 'status' => 'success', 'reachable' => true, 'message' => ''];
        $this->assertSame(1, Auto::sweep($this->ps())['changed']);
        $this->assertSame('paid', $this->row()->status);
    }

    public function test_nobody_can_settle_or_refuse_a_transfer_in_flight_by_hand(): void
    {
        $this->returnAvailable();
        $this->withdraw();
        $id = (int) $this->row()->id;
        $this->assertFalse(\AfricaGates\Services\CommunityReturnWithdrawals::reject($id, 'changed my mind', 1)['ok'],
            'refusing would put the money back while the bank pays it');
        $this->assertFalse(\AfricaGates\Services\CommunityReturnWithdrawals::markPaid($id, 'MANUAL-1', 1)['ok'],
            'marking it paid by hand would record a second payment');
        $this->assertSame(Auto::A_SENDING, $this->row()->auto_state);
        $this->assertSame(0, (int) DB::table('gates_community_returns')->where('entry_type', 'payout')->count());
    }

    // ══ when the bank says no ═════════════════════════════════════════════════

    public function test_a_failed_transfer_is_refused_and_the_money_stays_theirs(): void
    {
        $this->returnAvailable();
        $this->withdraw();
        Auto::applyStatus($this->row()->transfer_ref, 'failed', 'Account dormant');
        $this->assertSame('rejected', $this->row()->status);
        $this->assertStringContainsString('Account dormant', (string) $this->row()->review_note);
        $this->assertSame(100000, Ret::wallet(1)['available_kobo']);
    }

    public function test_a_transfer_returned_after_success_is_put_back_on_the_record(): void
    {
        $this->returnAvailable();
        $this->withdraw();
        $ref = $this->row()->transfer_ref;
        Auto::applyStatus($ref, 'success');
        $this->assertSame(40000, Ret::wallet(1)['available_kobo']);

        $this->assertTrue(Auto::applyStatus($ref, 'reversed', 'Beneficiary bank refused'));
        $this->assertSame(100000, Ret::wallet(1)['available_kobo'], 'the money is the member’s again');
        $this->assertSame('rejected', $this->row()->status);
        $this->assertFalse(Auto::applyStatus($ref, 'reversed'), 'and only once');
        $this->assertSame(100000, Ret::wallet(1)['available_kobo']);
    }

    // ══ the referral path, and a person's override ═════════════════════════════

    public function test_a_referral_payout_goes_the_same_way_and_clears_its_credits(): void
    {
        DB::table('gates_referral_codes')->insert(['user_id' => 8, 'code' => 'AG8', 'created_at' => '2026-08-01 10:00:00']);
        for ($i = 1; $i <= ReferralService::THRESHOLD; $i++) {
            DB::table('gates_referral_credits')->insert(['code_id' => 1, 'user_id' => 8, 'registration_id' => $i,
                'source_type' => 'registration', 'source_id' => $i, 'event_id' => 1, 'paid_naira' => 10000,
                'commission_naira' => 1000, 'rate_bps' => 1000, 'created_at' => '2026-08-01 10:00:00']);
        }
        $this->gw->resolve['name'] = 'ADE BOLA';
        DB::table('gates_users')->where('id', 8)->update(['payout_account_number' => '1111111111',
            'payout_bank_code' => self::BANK['bank_code'], 'payout_bank_changed_at' => Carbon::now()->subDays(30)->toDateTimeString()]);
        $r = W::withdraw(8, 'ref', null, ['account_number' => '1111111111'] + self::BANK, true, $this->ps());
        $this->assertSame(Auto::A_SENDING, $r['auto'], $r['message']);
        $p = DB::table('gates_referral_payouts')->first();
        $this->assertSame('requested', $p->status, 'a referral payout stays requested until the gateway confirms');
        $this->assertSame(W::ST_PROCESSING, W::transaction(8, $r['key'])['status']);
        // Still `requested` while in flight, so the status cannot stop a second run: the
        // claim on auto_state is the only thing that does.
        $this->assertSame('', Auto::process($this->ps(), Auto::KIND_REFERRAL, (int) $p->id)['state']);
        $this->assertSame(0, Auto::sweep($this->ps())['processed'], 'the sweep leaves it alone too');
        $this->assertCount(1, $this->gw->transfers);

        Auto::applyStatus($p->transfer_ref, 'success');
        $this->assertSame('paid', DB::table('gates_referral_payouts')->value('status'));
        $this->assertSame(0, (int) DB::table('gates_referral_credits')->whereNull('paid_out_at')->count());
    }

    public function test_a_person_can_release_a_held_request_to_be_sent(): void
    {
        $this->returnAvailable();
        $this->rules(['wallet_auto_max_naira' => 100]);
        $this->withdraw();
        $this->assertSame(Auto::A_HELD, $this->row()->auto_state);

        $r = Auto::release($this->ps(), Auto::KIND_RETURN, (int) $this->row()->id, 1);
        $this->assertSame(Auto::A_SENDING, $r['state'], $r['message']);
        $this->assertSame(1, (int) $this->row()->reviewed_by, 'the person who released it is on the record');
        $this->assertCount(1, $this->gw->transfers);
        $again = Auto::release($this->ps(), Auto::KIND_RETURN, (int) $this->row()->id, 1);
        $this->assertStringContainsString('already been sent', $again['message'], 'releasing again sends nothing more');
        $this->assertCount(1, $this->gw->transfers);
    }

    // ══ the name rule ══════════════════════════════════════════════════════════

    public function test_names_match_as_sets_and_one_shared_surname_is_two_people(): void
    {
        $this->assertTrue(WalletRisk::nameMatches('YUSUF AMINA BINTA', ['Amina Yusuf']));
        $this->assertTrue(WalletRisk::nameMatches('MR. OBI ADA', ['Ada Obi']));
        $this->assertFalse(WalletRisk::nameMatches('BURNA BOY', ['Burna']),
            'one shared word matches any account containing it — a one-name member is a person\'s to compare');
        $this->assertFalse(WalletRisk::nameMatches('ADA MUSA IBRAHIM', ['Ada']));
        $this->assertFalse(WalletRisk::nameMatches('YUSUF BELLO', ['Amina Yusuf']));
        $this->assertFalse(WalletRisk::nameMatches('', ['Amina Yusuf']));
        $this->assertTrue(WalletRisk::nameMatches('KWAME MENSAH', ['Amina Yusuf', 'Kwame Mensah']), 'or the nominee page’s name');
    }

    // ══ the review of 20 Oct 2026: four ways past the checks, each closed ═══════

    public function test_it_is_off_until_an_operator_switches_it_on(): void
    {
        $this->assertFalse(RuleEngine::DEFAULTS['wallet_auto_payout'], 'moving money must not arrive by deploy');
    }

    public function test_a_first_ever_bank_account_is_a_change_of_destination(): void
    {
        $this->returnAvailable();
        DB::table('gates_users')->where('id', 7)->update(['payout_bank_changed_at' => null, 'payout_account_number' => null,
                                                          'payout_bank_code' => null]);
        $this->assertSame(Auto::A_HELD, $this->withdraw()['auto'], 'somebody in the inbox adding their own account as the first');
        $this->assertSame(['takeover'], $this->codes());
        $this->assertSame([], $this->gw->transfers);
    }

    public function test_a_name_changed_to_match_the_account_is_held(): void
    {
        $this->returnAvailable();
        // The profile form, renaming Ada to the stranger whose account this is.
        $this->assertTrue((new UserAccountService())->updateProfile(7, 'Musa Ibrahim', '')['ok']);
        $this->assertNotNull(DB::table('gates_users')->where('id', 7)->value('name_changed_at'), 'the edit was not recorded');
        $this->gw->resolve['name'] = 'MUSA IBRAHIM';
        $this->assertSame(Auto::A_HELD, $this->withdraw()['auto']);
        // And the page's own name decides the match: the edited account name is not consulted.
        $this->assertContains('account', $this->codes());
        $this->assertContains('takeover', $this->codes());

        // Saving the same name again is not a change.
        DB::table('gates_users')->where('id', 7)->update(['name_changed_at' => null]);
        (new UserAccountService())->updateProfile(7, 'Musa Ibrahim', '');
        $this->assertNull(DB::table('gates_users')->where('id', 7)->value('name_changed_at'));
    }

    public function test_a_code_sign_in_holds_a_payment_to_an_account_never_paid_before_and_only_that(): void
    {
        $this->returnAvailable();
        UserAccountService::markCodeSignIn(7);
        $this->assertSame(Auto::A_HELD, $this->withdraw()['auto'], 'a code proves the inbox, and this account was never paid');
        $this->assertSame(['takeover'], $this->codes());

        // Paid there before: the owner withdrawing to their usual account is the ordinary case.
        DB::table('gates_return_withdrawals')->delete();
        DB::table('gates_return_withdrawals')->insert(['nominee_id' => 1, 'user_id' => 7, 'amount_kobo' => 100, 'fee_kobo' => 0,
            'bank_name' => 'GTBank', 'account_name' => 'Ada Obi', 'account_number' => self::BANK['account_number'],
            'status' => 'paid', 'requested_at' => Carbon::now()->subDays(40)->toDateTimeString()]);
        $this->assertSame(Auto::A_SENDING, $this->withdraw(7, 50000)['auto']);
    }

    public function test_a_stuck_transfer_is_paid_by_hand_only_once_paystack_says_it_never_went(): void
    {
        $this->returnAvailable();
        $this->gw->initiate = ['ok' => false, 'status' => '', 'transfer_code' => '', 'transfer_id' => null, 'message' => 'timeout'];
        $this->withdraw();
        $ref = $this->row()->transfer_ref;
        DB::table('gates_return_withdrawals')->update(['transfer_attempts' => Auto::MAX_ATTEMPTS,
            'sent_at' => Carbon::now()->subMinutes(30)->toDateTimeString()]);
        Auto::sweep($this->ps());
        $this->assertSame(Auto::A_HELD, $this->row()->auto_state);
        $id = (int) $this->row()->id;
        $CR = \AfricaGates\Services\CommunityReturnWithdrawals::class;

        // Paystack cannot be asked: no manual payment, no refusal.
        $this->gw->verify[$ref] = ['ok' => false, 'status' => '', 'reachable' => false, 'absent' => false, 'message' => 'timeout'];
        $this->assertFalse($CR::markPaid($id, 'MANUAL-1', 1, false, $this->ps())['ok'], 'paid by hand with the original unaccounted for');
        $this->assertFalse($CR::reject($id, 'try again', 1, $this->ps())['ok'], 'refunded to the wallet while the bank may pay it');

        // Paystack has it after all: its answer is applied, and nobody pays it twice.
        $this->gw->verify[$ref] = ['ok' => true, 'status' => 'success', 'reachable' => true, 'absent' => false, 'message' => ''];
        $this->assertFalse($CR::markPaid($id, 'MANUAL-1', 1, false, $this->ps())['ok']);
        $this->assertSame('paid', $this->row()->status);
        $this->assertSame($ref, (string) DB::table('gates_return_withdrawals')->where('id', $id)->value('payment_ref'),
            'the payment recorded is the gateway\'s, not the manual one');
    }

    public function test_a_stuck_transfer_paystack_confirms_absent_can_be_settled_by_hand(): void
    {
        $this->returnAvailable();
        $this->gw->initiate = ['ok' => false, 'status' => '', 'transfer_code' => '', 'transfer_id' => null, 'message' => 'timeout'];
        $this->withdraw();
        DB::table('gates_return_withdrawals')->update(['transfer_attempts' => Auto::MAX_ATTEMPTS,
            'sent_at' => Carbon::now()->subMinutes(30)->toDateTimeString()]);
        Auto::sweep($this->ps());
        $id = (int) $this->row()->id;
        $this->assertTrue(\AfricaGates\Services\CommunityReturnWithdrawals::markPaid($id, 'MANUAL-1', 1, false, $this->ps())['ok']);
        $risk = json_decode((string) $this->row()->risk_json, true);
        $this->assertArrayHasKey('unsent_confirmed_at', $risk, 'the evidence a person paid on is not on the record');
    }

    public function test_the_daily_limit_is_asked_again_when_the_transfer_is_committed(): void
    {
        $this->returnAvailable(100000);
        $this->rules(['wallet_auto_daily_count' => 1]);
        // A second withdrawal committing WHILE this one is being checked: the account lookup
        // runs after the first daily check, so the race lands exactly between the two.
        $this->gw->resolve['_side'] = true;
        $gw = $this->gw;
        $ps = new class ($gw) extends PaymentService {
            public function __construct(private object $gw) { parent::__construct(); }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function resolveAccount(string $n, string $c): array
            {
                DB::table('gates_referral_payouts')->insert(['user_id' => 7, 'amount_naira' => 100, 'bank_name' => 'x',
                    'account_name' => 'x', 'account_number' => '0123456789', 'status' => 'requested',
                    'auto_state' => 'sending', 'sent_at' => Carbon::now()->toDateTimeString(),
                    'requested_at' => Carbon::now()->toDateTimeString()]);
                return ['ok' => true, 'name' => 'OBI ADA CHIOMA', 'reachable' => true, 'message' => ''];
            }
            public function createTransferRecipient(string $name, string $n, string $c): array { return ['ok' => true, 'code' => 'R', 'message' => '']; }
            public function initiateTransferKobo(int $k, string $r, string $ref, string $why = ''): array
            {
                $this->gw->transfers[] = $ref;
                return ['ok' => true, 'status' => 'pending', 'transfer_code' => 'T', 'transfer_id' => '1', 'message' => ''];
            }
        };
        $r = W::withdraw(7, 'page-1', 60000, self::BANK, true, $ps);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(Auto::A_HELD, $r['auto'], 'both passed the daily limit because each saw the other as not yet sent');
        $this->assertSame(['velocity'], $this->codes());
        $this->assertSame([], $this->gw->transfers);
    }
}
