<?php
declare(strict_types=1);

namespace Tests\Feature;

use AfricaGates\Services\PaymentDestination as D;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Which subaccount each kind of money settles into.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO FAILURES WORTH FEARING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · A TYPO TAKES A REVENUE STREAM OFFLINE. Paystack rejects an initialise carrying a
 *     subaccount it does not recognise, and a rejected initialise is a buyer who cannot pay. So a
 *     malformed code must be refused at the form rather than stored — refusing to route is
 *     recoverable, refusing to sell is not, and the admin has nothing on screen to tell them
 *     which one happened.
 *
 * 2 · AN UNCONFIGURED PLATFORM CHANGES BEHAVIOUR. If "no subaccount" produced anything other than
 *     the exact request that went out before, every operator who never opens the new screen would
 *     have their settlements altered by an upgrade. Asserted directly below.
 *
 * The third thing asserted is that attribution is written once and never derived, because the
 * screen it feeds — the Paystack-versus-us comparison — exists precisely because those two
 * records had drifted. A figure that moves when somebody edits a setting is worse than no figure.
 */
final class PaymentDestinationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(D::STREAMS) as $s) {
            DB::table('gates_settings')->where('key_name', 'paystack_sub_' . $s)->delete();
            DB::table('gates_settings')->where('key_name', 'paystack_bearer_' . $s)->delete();
        }
    }

    // ══ 1. unconfigured is invisible ═════════════════════════════════════════

    public function test_nothing_configured_adds_nothing_to_the_request(): void
    {
        // The whole of rule 2. Merging an empty array changes nothing about the payload that
        // goes to Paystack, so an operator who never opens the screen sees no change at all.
        foreach (array_keys(D::STREAMS) as $stream) {
            $this->assertSame([], D::initFields($stream));
            $this->assertSame('', D::forStream($stream));
        }
        $this->assertFalse(D::anyRouted());
    }

    public function test_an_unknown_stream_is_never_routed(): void
    {
        // A caller passing a stream nobody configured must not accidentally inherit another
        // stream's account. Silence, not a guess.
        $this->assertSame('', D::forStream('donations'));
        $this->assertSame([], D::initFields('donations'));
        $this->assertSame('account', D::bearerFor('donations'));
    }

    // ══ 2. the code, and what is refused ═════════════════════════════════════

    public function test_a_real_code_is_accepted_as_it_is(): void
    {
        // Case is PRESERVED beyond the prefix: Paystack's codes are case-sensitive, and
        // "helpfully" upper-casing one is how a valid code becomes invalid.
        $this->assertSame('ACCT_8f4s1eq7ml6rlzj', D::code('ACCT_8f4s1eq7ml6rlzj'));
        $this->assertSame('ACCT_8f4s1eq7ml6rlzj', D::code('  ACCT_8f4s1eq7ml6rlzj  '));
    }

    public function test_a_pasted_dashboard_url_yields_the_code(): void
    {
        // Copying the URL is the likeliest thing somebody does, and the code inside it is
        // unambiguous — so taking it is better than refusing and being unhelpfully correct.
        $this->assertSame('ACCT_8f4s1eq7ml6rlzj',
            D::code('https://dashboard.paystack.com/#/subaccounts/ACCT_8f4s1eq7ml6rlzj'));
    }

    public function test_a_code_typed_without_the_prefix_is_completed(): void
    {
        // Some dashboard views show it without ACCT_. Adding it is not a guess about intent.
        $this->assertSame('ACCT_8f4s1eq7ml6rlzj', D::code('8f4s1eq7ml6rlzj'));
    }

    public function test_anything_that_is_not_a_code_is_refused(): void
    {
        // Every one of these is a real paste accident, and every one of them would make Paystack
        // reject the transaction — i.e. stop that stream selling — if it were stored.
        foreach ([
            '',                          // cleared
            '   ',
            '0123456789',                // a bank account number
            'GTBank',                    // a bank name
            'ACCT_',                     // the prefix alone
            'ACCT_ab',                   // too short to be real
            'my subaccount',             // a label
            '<script>alert(1)</script>',
            'ACCT_abc; DROP TABLE x',
        ] as $bad) {
            $this->assertSame('', D::code($bad), "accepted “{$bad}” as a subaccount code");
            $this->assertFalse(D::looksValid($bad));
        }
    }

    public function test_a_refused_code_keeps_the_previous_setting(): void
    {
        // Losing a working code because somebody mistyped over it would take the stream offline
        // in the very act of trying to correct it.
        D::save(['shop' => 'ACCT_goodcode123']);
        $this->assertSame('ACCT_goodcode123', D::forStream('shop'));

        $r = D::save(['shop' => '0123456789']);
        $this->assertArrayHasKey('shop', $r['refused']);
        $this->assertSame('ACCT_goodcode123', D::forStream('shop'),
            'the working code was overwritten by a rejected one');
    }

    public function test_clearing_a_field_stops_routing_that_stream(): void
    {
        // A legitimate act — winding down a subaccount — so blank must mean blank rather than
        // "leave it alone", or the routing could never be turned off.
        D::save(['events' => 'ACCT_eventacct01'], ['events' => 'subaccount']);
        $this->assertTrue(D::anyRouted());

        D::save(['events' => '']);
        $this->assertSame('', D::forStream('events'));
        $this->assertSame('account', D::bearerFor('events'), 'the bearer must reset with the code');
        $this->assertFalse(D::anyRouted());
    }

    public function test_a_stream_absent_from_the_form_is_left_alone(): void
    {
        // A save from another settings section posts none of these fields, and reading that as
        // "clear everything" would wipe the routing every time somebody edited the site title.
        D::save(['shop' => 'ACCT_shopacct001', 'events' => 'ACCT_eventacct01']);
        D::save(['shop' => 'ACCT_shopacct002']);          // events not submitted

        $this->assertSame('ACCT_shopacct002', D::forStream('shop'));
        $this->assertSame('ACCT_eventacct01', D::forStream('events'),
            'a stream missing from the submitted form was cleared');
    }

    // ══ 3. the fields that reach Paystack ════════════════════════════════════

    public function test_a_routed_stream_sends_its_subaccount(): void
    {
        D::save(['shop' => 'ACCT_shopacct001']);
        $this->assertSame(['subaccount' => 'ACCT_shopacct001'], D::initFields('shop'));
    }

    public function test_the_bearer_is_only_sent_alongside_a_subaccount(): void
    {
        // Paystack rejects `bearer` on its own, and a rejected initialise is a buyer who cannot
        // pay — so it can never travel without the account it refers to.
        D::save([], ['votes' => 'subaccount']);
        $this->assertSame([], D::initFields('votes'));

        D::save(['votes' => 'ACCT_voteacct001'], ['votes' => 'subaccount']);
        $this->assertSame(['subaccount' => 'ACCT_voteacct001', 'bearer' => 'subaccount'],
            D::initFields('votes'));
    }

    public function test_the_main_account_bearing_the_fee_is_left_implicit(): void
    {
        // It is Paystack's default, so sending it says nothing — and the fewer fields on a
        // payment request, the fewer ways it can be rejected.
        D::save(['shop' => 'ACCT_shopacct001'], ['shop' => 'account']);
        $this->assertArrayNotHasKey('bearer', D::initFields('shop'));
    }

    public function test_an_invented_bearer_falls_back_to_the_main_account(): void
    {
        D::save(['shop' => 'ACCT_shopacct001'], ['shop' => 'whoever']);
        $this->assertSame('account', D::bearerFor('shop'));
        $this->assertArrayNotHasKey('bearer', D::initFields('shop'));
    }

    // ══ 4. which stream a payment belongs to ═════════════════════════════════

    public function test_the_stream_is_read_from_the_reference_itself(): void
    {
        // From the reference rather than passed down through five call sites, so the recorded
        // attribution cannot disagree with the reference the gateway knows the payment by.
        $this->assertSame('events', D::streamForReference('AFG-EVT-1122AABBCC'));
        $this->assertSame('shop',   D::streamForReference('AFG-SHP-000000000005'));
        $this->assertSame('votes',  D::streamForReference('AFG-PV-9F2C1A44B8'));
        $this->assertSame('votes',  D::streamForReference('AFG-DON-771122'));
    }

    public function test_a_reference_that_is_not_ours_belongs_to_no_stream(): void
    {
        // Paystack's own references and wallet ids turn up in the ledger comparison. Attributing
        // one of them to a stream would put somebody else's money in our figures.
        foreach (['', 'T123456789', 'ref_abc', 'AFG', 'xAFG-EVT-1'] as $foreign) {
            $this->assertSame('', D::streamForReference($foreign), "claimed “{$foreign}”");
        }
    }

    public function test_the_prefix_match_is_case_insensitive(): void
    {
        // References are echoed back by gateways and by humans, and case is not preserved by
        // either reliably.
        $this->assertSame('events', D::streamForReference('afg-evt-1122aabbcc'));
    }

    // ══ 5. the screen ════════════════════════════════════════════════════════

    public function test_the_settings_screen_gets_every_stream_resolved(): void
    {
        D::save(['shop' => 'ACCT_shopacct001'], ['shop' => 'subaccount']);
        $all = D::all();

        $this->assertCount(count(D::STREAMS), $all);
        $byStream = array_column($all, null, 'stream');

        $this->assertTrue($byStream['shop']['routed']);
        $this->assertSame('ACCT_shopacct001', $byStream['shop']['code']);
        $this->assertSame('subaccount', $byStream['shop']['bearer']);

        $this->assertFalse($byStream['events']['routed']);
        $this->assertSame('', $byStream['events']['code']);
        // Every stream carries a human label, because the screen names them and a bare slug
        // ("votes") is not what somebody accounting for money calls it.
        foreach ($all as $row) {
            $this->assertNotSame('', $row['label']);
        }
    }

    // ══ 6. attribution is recorded, not derived ══════════════════════════════

    public function test_a_route_is_recorded_against_the_reference(): void
    {
        // The table exists so the answer to "which account did this settle to" cannot change
        // when somebody edits a setting.
        $this->assertTrue(DB::schema()->hasTable('gates_payment_routes'),
            'the route table is missing — run 2026_09_12_payment_destination');

        DB::table('gates_payment_routes')->updateOrInsert(
            ['reference' => 'AFG-EVT-TESTROUTE1'],
            ['revenue_stream' => 'events', 'subaccount' => 'ACCT_eventacct01',
             'fee_bearer' => 'subaccount', 'amount_naira' => 80000]
        );

        $row = DB::table('gates_payment_routes')->where('reference', 'AFG-EVT-TESTROUTE1')->first();
        $this->assertSame('events', (string) $row->revenue_stream);
        $this->assertSame('ACCT_eventacct01', (string) $row->subaccount);

        // Editing the setting afterwards must not change what that payment says.
        D::save(['events' => 'ACCT_differentone9']);
        $again = DB::table('gates_payment_routes')->where('reference', 'AFG-EVT-TESTROUTE1')->first();
        $this->assertSame('ACCT_eventacct01', (string) $again->subaccount,
            'history re-attributed itself when the setting changed — the bank would stop matching');
    }

    public function test_re_initialising_the_same_reference_does_not_double_the_attribution(): void
    {
        // A buyer who abandons checkout and starts again re-initialises the same reference, and
        // two rows for one payment would make a per-stream total double.
        for ($i = 0; $i < 3; $i++) {
            DB::table('gates_payment_routes')->updateOrInsert(
                ['reference' => 'AFG-SHP-TESTROUTE2'],
                ['revenue_stream' => 'shop', 'subaccount' => 'ACCT_shopacct001',
                 'fee_bearer' => 'account', 'amount_naira' => 18500]
            );
        }
        $this->assertSame(1, DB::table('gates_payment_routes')
            ->where('reference', 'AFG-SHP-TESTROUTE2')->count());
    }
}
