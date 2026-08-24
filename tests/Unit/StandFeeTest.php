<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{LegalSeeder, PartnerOrg, StandApplication, StandCall, StandFee, StandType};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The stand fee: what is owed, the link that reaches it, the terms, and the payment.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT ACCEPTANCE USED TO DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `StandApplication::accept()` flipped a column and returned the sentence "Accepted. You
 * will be invoiced for the stand fee."
 *
 * Nothing invoiced anybody. There was no amount on the row, nothing the vendor could see, no
 * way to pay, and no way for an organiser to tell a paid pitch from an unpaid one on the
 * morning of the market. The published price beside the published quota is what makes the
 * allocation defensible — and "we will send you an invoice" is where a defensible allocation
 * turns back into a WhatsApp message and a bank transfer nobody can reconcile.
 */
final class StandFeeTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    // ───────────────────────────────── fixtures ─────────────────────────────

    private function event(): object
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Lagos Market Day', 'slug' => 'mkt-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+60 days')), 'status' => 'published',
        ]);
        return DB::table('gates_site_events')->where('id', $id)->first();
    }

    private function vendor(): int
    {
        $id = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'ada-' . bin2hex(random_bytes(4)),
            'name' => 'Adaeze Foods', 'legal_name' => 'Adaeze Foods Limited',
            'kind' => PartnerOrg::KIND_VENDOR, 'entity_type' => PartnerOrg::ENTITY_BUSINESS,
            'cac_number' => 'BN9988', 'status' => PartnerOrg::STATUS_APPROVED,
            'contact_email' => 'ada@example.test', 'contact_phone' => '08031234567',
        ]);
        foreach (array_keys(PartnerOrg::requiredDocuments($id)) as $kind) {
            DB::table('gates_org_documents')->insert([
                'org_id' => $id, 'kind' => $kind, 'stored_path' => 'uploads/org-docs/x.pdf',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return $id;
    }

    /** @return array{app_id:int, org_id:int, event:object} an offered application */
    private function offered(int $price = 50000, int $deposit = 10000): array
    {
        $event = $this->event();
        $t = StandType::save((int) $event->id, [
            'name' => 'Food pitch', 'category' => 'food',
            'price_naira' => (string) $price, 'deposit_naira' => (string) $deposit,
            'quota' => '2', 'size_preset' => '3x3',
        ]);
        $this->assertTrue($t['ok'], $t['message'] ?? '');

        $c = StandCall::save((int) $event->id, [
            'closes_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
        ]);
        StandCall::open($c['id'], 1);

        $orgId = $this->vendor();
        $app   = StandApplication::submit($orgId, (int) $t['id'], ['what_they_sell' => 'Jollof.']);
        StandApplication::checkEligibility((int) $app['id']);
        $r = StandApplication::offer((int) $app['id'], 1);
        $this->assertTrue($r['ok'], $r['message'] ?? '');

        return ['app_id' => (int) $app['id'], 'org_id' => $orgId, 'event' => $event];
    }

    private function app(int $id): object
    {
        return DB::table('gates_stand_applications')->where('id', $id)->first();
    }

    private function ctrl(): \AfricaGates\Controllers\StandOfferController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Controllers\StandOfferController::class);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE FEE IS STAMPED, AND STAMPED AT OFFER
    // ════════════════════════════════════════════════════════════════════════

    public function test_offering_stamps_the_price_and_mints_a_link(): void
    {
        $f   = $this->offered();
        $app = $this->app($f['app_id']);

        $this->assertSame(50000, (int) $app->fee_naira);
        $this->assertSame(10000, (int) $app->deposit_naira);
        $this->assertMatchesRegularExpression('~^[a-f0-9]{48}$~', (string) $app->access_token);
    }

    /**
     * The fee cannot move after somebody accepted at the old one.
     *
     * Same reason StandCall::open() snapshots the criteria: a term that can change after you
     * agreed to it is not a term. A live read off the stand type would let an organiser raise
     * a price on a vendor who had already committed.
     */
    public function test_a_later_price_change_does_not_alter_what_was_offered(): void
    {
        $f = $this->offered();

        $typeId = (int) $this->app($f['app_id'])->stand_type_id;
        DB::table('gates_stand_types')->where('id', $typeId)->update(['price_naira' => 90000]);

        $this->assertSame(50000, (int) $this->app($f['app_id'])->fee_naira,
            'the price moved under a vendor who had already been offered it');
        $this->assertSame(50000, StandFee::owing($this->app($f['app_id']))['fee']);
    }

    /** A deposit larger than the fee is a data error, not a demand. */
    public function test_a_deposit_larger_than_the_fee_is_clamped_rather_than_charged(): void
    {
        $f = $this->offered(20000, 20000);
        $typeId = (int) $this->app($f['app_id'])->stand_type_id;
        DB::table('gates_stand_types')->where('id', $typeId)->update(['deposit_naira' => 99999]);

        StandFee::stamp($f['app_id']);
        $app = $this->app($f['app_id']);
        $this->assertLessThanOrEqual((int) $app->fee_naira, (int) $app->deposit_naira);
    }

    // ════════════════════════════════════════════════════════════════════════
    // WHAT IS OWED
    // ════════════════════════════════════════════════════════════════════════

    public function test_a_deposit_is_what_is_due_first_and_the_balance_is_named(): void
    {
        $f = $this->offered(50000, 10000);
        $owing = StandFee::owing($this->app($f['app_id']));

        $this->assertSame(10000, $owing['due']);
        $this->assertFalse($owing['settled']);
        $this->assertStringContainsString('₦50,000', $owing['label'],
            'somebody paying a deposit needs the total in the same sentence');
    }

    public function test_once_the_deposit_is_paid_the_balance_is_what_is_owed(): void
    {
        $f = $this->offered(50000, 10000);
        StandApplication::accept($f['app_id'], $f['org_id']);
        StandFee::beginPayment($f['app_id'], 'AFG-STAND-X1', 'paystack');
        StandFee::confirm('AFG-STAND-X1', 10000, 'paystack');

        $owing = StandFee::owing($this->app($f['app_id']));
        $this->assertSame('balance', $owing['stage']);
        $this->assertSame(40000, $owing['due']);
    }

    /**
     * A free pitch is a real thing and must not render as an unpaid ₦0 invoice.
     *
     * A community market or a sponsored row genuinely charges nothing, and a Pay button
     * beside "₦0 due" is a control that can only fail.
     */
    public function test_a_free_stand_is_settled_rather_than_owing_nothing(): void
    {
        $f = $this->offered(0, 0);
        $owing = StandFee::owing($this->app($f['app_id']));

        $this->assertTrue($owing['settled']);
        $this->assertSame('free', $owing['stage']);
        $this->assertSame(0, $owing['due']);
        $this->assertStringNotContainsString('₦0', $owing['label']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE PAYMENT IS CREDITED FROM THE GATEWAY, NOT FROM THE BROWSER
    // ════════════════════════════════════════════════════════════════════════

    public function test_a_payment_is_credited_once_however_many_times_it_is_reported(): void
    {
        $f = $this->offered(50000, 0);
        StandApplication::accept($f['app_id'], $f['org_id']);
        StandFee::beginPayment($f['app_id'], 'AFG-STAND-DUP', 'paystack');

        $first = StandFee::confirm('AFG-STAND-DUP', 50000, 'paystack');
        $this->assertTrue($first['ok']);
        $this->assertSame(50000, $first['credited']);

        // The callback, the webhook and the reconciliation sweep may all report the same
        // payment. Crediting it three times would mark a deposit as a fee paid three over.
        foreach ([1, 2] as $_) {
            $again = StandFee::confirm('AFG-STAND-DUP', 50000, 'paystack');
            $this->assertTrue($again['ok'], 'a repeat report of a real payment is not an error');
            $this->assertSame(0, $again['credited']);
        }

        $this->assertSame(50000, (int) $this->app($f['app_id'])->paid_naira);
    }

    public function test_a_reference_we_never_issued_is_refused(): void
    {
        $r = StandFee::confirm('AFG-STAND-NOT-OURS', 50000);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['credited']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE TOKEN LINK
    // ════════════════════════════════════════════════════════════════════════

    public function test_the_token_reaches_exactly_one_application(): void
    {
        $a = $this->offered();
        $b = $this->offered();

        $tokenA = (string) $this->app($a['app_id'])->access_token;
        $this->assertNotSame($tokenA, (string) $this->app($b['app_id'])->access_token);
        $this->assertSame($a['app_id'], (int) StandFee::byToken($tokenA)->id);
    }

    public function test_a_malformed_token_resolves_to_nothing_without_a_query(): void
    {
        foreach (['', 'short', str_repeat('z', 48), '../../etc/passwd'] as $bad) {
            $this->assertNull(StandFee::byToken($bad), "'{$bad}' resolved to an application");
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE TERMS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Accepting without ticking the box changes nothing.
     *
     * A trader is about to be charged for a pitch under rules covering insurance,
     * cancellation and what they may sell. An organiser enforcing a clause the trader never
     * saw is the same failure as a rejection with no reason.
     */
    public function test_accepting_without_agreeing_to_the_terms_is_refused(): void
    {
        $f     = $this->offered();
        $token = (string) $this->app($f['app_id'])->access_token;

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/stand/' . $token . '/accept')
            ->withParsedBody([]);

        $res = $this->ctrl()->accept($req, new Response(), ['token' => $token]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(StandApplication::DECISION_OFFERED,
                          (string) $this->app($f['app_id'])->decision,
                          'the pitch was accepted without the terms being agreed to');
        $this->assertStringContainsString('trading terms',
                                          (string) ($_SESSION['flash_error'] ?? ''));
    }

    public function test_accepting_with_the_box_ticked_records_which_terms_were_agreed(): void
    {
        LegalSeeder::install();

        $f     = $this->offered();
        $token = (string) $this->app($f['app_id'])->access_token;

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/stand/' . $token . '/accept')
            ->withParsedBody(['agree_terms' => '1']);

        $this->ctrl()->accept($req, new Response(), ['token' => $token]);

        $app = $this->app($f['app_id']);
        $this->assertSame(StandApplication::DECISION_ACCEPTED, (string) $app->decision);
        $this->assertNotSame('', trim((string) $app->terms_agreed_at));
        // The VERSION as well as the moment: "they accepted the terms" is worth nothing if
        // nobody can say which terms those were, and the document is admin-editable.
        $this->assertNotSame('', trim((string) $app->terms_version));
        $this->assertTrue(StandFee::hasAgreed($app));
    }

    public function test_the_trading_terms_document_exists_and_answers_the_market_questions(): void
    {
        LegalSeeder::install();
        $doc = \AfricaGates\Services\LegalService::get(StandFee::TERMS_SLUG);

        $this->assertIsArray($doc, 'a vendor agrees to a document that does not exist');
        $body = strtolower((string) ($doc['body_html'] ?? ''));

        // The questions that actually come up at a market. Each was answered nowhere before.
        foreach (['insur', 'cancel', 'refund', 'transfer'] as $topic) {
            $this->assertStringContainsString($topic, $body,
                'the trading terms do not cover: ' . $topic);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // PAYING IS GATED ON ACCEPTING
    // ════════════════════════════════════════════════════════════════════════

    public function test_an_unaccepted_offer_cannot_be_paid_for(): void
    {
        $f     = $this->offered();
        $token = (string) $this->app($f['app_id'])->access_token;

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/stand/' . $token . '/pay')->withParsedBody([]);
        $res = $this->ctrl()->pay($req, new Response(), ['token' => $token]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('Accept the offer first',
                                          (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame(0, (int) $this->app($f['app_id'])->paid_naira);
    }

    /** And a settled stand offers nothing to pay, rather than a ₦0 checkout. */
    public function test_a_settled_stand_has_nothing_to_pay(): void
    {
        $f     = $this->offered(50000, 0);
        $token = (string) $this->app($f['app_id'])->access_token;
        StandApplication::accept($f['app_id'], $f['org_id']);
        StandFee::beginPayment($f['app_id'], 'AFG-STAND-PAID', 'paystack');
        StandFee::confirm('AFG-STAND-PAID', 50000, 'paystack');

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/stand/' . $token . '/pay')->withParsedBody([]);
        $this->ctrl()->pay($req, new Response(), ['token' => $token]);

        $this->assertStringContainsString('nothing left to pay',
                                          (string) ($_SESSION['flash_ok'] ?? ''));
    }

    // ════════════════════════════════════════════════════════════════════════
    // OPENING AND REOPENING
    // ════════════════════════════════════════════════════════════════════════
    //
    // Closing a call was a one-way door, and so was every decision on an application. A call
    // shut a week early by accident, or one that closed on schedule with four pitches
    // unfilled, had no route back except a SECOND call for the same event — competing with
    // the first on the same page, with its own quotas that the capacity check double-counts.

    public function test_a_closed_call_can_be_reopened_on_a_new_date(): void
    {
        $f      = $this->offered();
        $callId = (int) StandCall::forEvent((int) $f['event']->id)->id;

        StandCall::close($callId);
        $this->assertFalse(StandCall::isAccepting(StandCall::find($callId)));

        $r = StandCall::reopen($callId, date('Y-m-d\TH:i', strtotime('+10 days')));
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertTrue(StandCall::isAccepting(StandCall::find($callId)));
    }

    /**
     * Reopening unlocks NOTHING.
     *
     * The governing rule is that the criteria, the quotas and the prices are fixed and
     * published before anybody knows who applied. If reopening moved them it would be a
     * route around exactly that.
     */
    public function test_reopening_does_not_unlock_the_published_terms(): void
    {
        $f      = $this->offered();
        $callId = (int) StandCall::forEvent((int) $f['event']->id)->id;

        $before = StandCall::find($callId);
        StandCall::close($callId);
        StandCall::reopen($callId, date('Y-m-d\TH:i', strtotime('+10 days')));
        $after = StandCall::find($callId);

        $this->assertSame((string) $before->criteria_json, (string) $after->criteria_json,
            'reopening rewrote the published criteria');
        $this->assertSame((string) ($before->locked_at ?? ''), (string) ($after->locked_at ?? ''));

        // And save() still refuses, because the call is open again.
        $this->assertFalse(StandCall::save((int) $f['event']->id,
            ['closes_at' => date('Y-m-d H:i:s', strtotime('+30 days'))])['ok']);
    }

    /** A past closing date reopens a call that is instantly shut again. Refused, with why. */
    public function test_reopening_onto_a_date_that_has_passed_is_refused(): void
    {
        $f      = $this->offered();
        $callId = (int) StandCall::forEvent((int) $f['event']->id)->id;
        StandCall::close($callId);

        $r = StandCall::reopen($callId, date('Y-m-d\TH:i', strtotime('-2 days')));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('closing date', strtolower($r['message']));
        $this->assertFalse(StandCall::isAccepting(StandCall::find($callId)));
    }

    public function test_a_draft_call_is_published_rather_than_reopened(): void
    {
        $event = $this->event();
        StandType::save((int) $event->id, ['name' => 'Pitch', 'category' => 'food',
                                           'price_naira' => '1000', 'quota' => '1']);
        $c = StandCall::save((int) $event->id,
                             ['closes_at' => date('Y-m-d H:i:s', strtotime('+9 days'))]);

        $r = StandCall::reopen((int) $c['id']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('never been opened', $r['message']);
    }

    // ── AND ONE APPLICATION AT A TIME ───────────────────────────────────────

    public function test_a_rejected_application_can_be_put_back_in_the_undecided_pile(): void
    {
        $f = $this->offered();
        StandApplication::decide($f['app_id'], StandApplication::DECISION_REJECTED, 1,
                                 'Two other food vendors scored higher on menu range.');

        $r = StandApplication::reopen($f['app_id'], 1);
        $this->assertTrue($r['ok'], $r['message']);

        $app = $this->app($f['app_id']);
        $this->assertSame(StandApplication::DECISION_PENDING, (string) $app->decision);
        // The old verdict goes with it. "Two other food vendors scored higher" attached to an
        // application that is once again undecided is a judgement on a row nobody has judged
        // — and it is the text a rejection notice quotes.
        $this->assertNull($app->decision_reason);
        $this->assertNull($app->offer_expires_at);
    }

    /**
     * An ACCEPTED pitch is not reopenable, because it may have been paid for.
     *
     * Flipping it back would leave money credited against a place nobody holds, and take a
     * pitch off somebody who was told it was theirs. Withdrawing an accepted stand is a
     * different act with a refund attached — and refusing here means this button cannot be
     * the thing that hides the refund.
     */
    public function test_an_accepted_and_paid_stand_cannot_be_quietly_reopened(): void
    {
        $f = $this->offered(50000, 0);
        StandApplication::accept($f['app_id'], $f['org_id']);
        StandFee::beginPayment($f['app_id'], 'AFG-STAND-RO', 'paystack');
        StandFee::confirm('AFG-STAND-RO', 50000, 'paystack');

        $r = StandApplication::reopen($f['app_id'], 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('refund', strtolower($r['message']));
        $this->assertSame(StandApplication::DECISION_ACCEPTED,
                          (string) $this->app($f['app_id'])->decision);
        $this->assertSame(50000, (int) $this->app($f['app_id'])->paid_naira);
    }

    /** A live offer is left alone — its clock is running and the vendor may be reading it. */
    public function test_a_live_offer_is_not_cancelled_by_reopening(): void
    {
        $f = $this->offered();

        $r = StandApplication::reopen($f['app_id'], 1);
        $this->assertFalse($r['ok']);
        $this->assertSame(StandApplication::DECISION_OFFERED,
                          (string) $this->app($f['app_id'])->decision);
    }

    /** Reopening does not hand out a place — the quota is counted when it is offered. */
    public function test_reopening_does_not_bypass_the_published_quota(): void
    {
        $f = $this->offered(50000, 0);
        $eventId = (int) $f['event']->id;
        $typeId  = (int) $this->app($f['app_id'])->stand_type_id;

        StandApplication::decide($f['app_id'], StandApplication::DECISION_REJECTED, 1, 'No room.');
        StandApplication::reopen($f['app_id'], 1);

        // Fill every published place with other vendors.
        DB::table('gates_stand_types')->where('id', $typeId)->update(['quota' => 1]);
        $other = $this->vendor();
        $app2  = StandApplication::submit($other, $typeId, ['what_they_sell' => 'Suya.']);
        StandApplication::checkEligibility((int) $app2['id']);
        $this->assertTrue(StandApplication::offer((int) $app2['id'], 1)['ok']);

        // The reopened one is back in the pool, and offering it is refused on the quota —
        // which is the right moment to find out and the right person to tell.
        $r = StandApplication::offer($f['app_id'], 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('quota', strtolower($r['message']));
        $this->assertSame($eventId, (int) $this->app($f['app_id'])->event_id);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE ORGANISER'S LEDGER
    // ════════════════════════════════════════════════════════════════════════

    /**
     * "Who has paid" is the question on the morning of the market, and it had no answer
     * anywhere in the product.
     */
    public function test_the_ledger_counts_accepted_pitches_and_not_open_offers(): void
    {
        $f = $this->offered(50000, 0);
        $eventId = (int) $f['event']->id;

        // Still only OFFERED — a place being held, not money anybody is owed. Counting it
        // as expected income lets a page report a figure that evaporates when the clock
        // runs out.
        $before = StandFee::ledger($eventId);
        $this->assertSame(0, $before['expected']);
        $this->assertCount(1, $before['rows']);

        StandApplication::accept($f['app_id'], $f['org_id']);
        $after = StandFee::ledger($eventId);
        $this->assertSame(50000, $after['expected']);
        $this->assertSame(0, $after['collected']);
        $this->assertSame(50000, $after['owed']);

        StandFee::beginPayment($f['app_id'], 'AFG-STAND-L1', 'paystack');
        StandFee::confirm('AFG-STAND-L1', 50000, 'paystack');

        $paid = StandFee::ledger($eventId);
        $this->assertSame(50000, $paid['collected']);
        $this->assertSame(0, $paid['owed']);
    }
}
