<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaymentLookup;
use AfricaGates\Services\VoteProof;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Finding a payment from the number the supporter actually has.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Seven places did `where('payment_ref', $ref)` — the verify page, the support
 * assistant, admin triage, the refund path, the reconciler. All of them matched exactly
 * one thing: the reference WE minted and handed to the gateway.
 *
 * That is not the number a supporter has. Paystack's receipt email, its dashboard and
 * its SMS show ITS transaction id and ITS own reference. The old error message admitted
 * the whole problem out loud:
 *
 *     "No payment with that reference is on record. If you paid inside a bank or wallet
 *      app, that app shows its own different number — ours begins with AFG-."
 *
 * So the platform's answer to its commonest support question depended on the supporter
 * having kept an email from us rather than the one the gateway sent them.
 */
final class PaymentLookupTest extends TestCase
{
    private const OURS = 'AFG-PVOTE-a1b2c3d4e5f6';

    private function order(array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId($over + [
            'donor_name' => 'Ada Okonkwo', 'donor_email' => 'ada@example.com',
            'amount_naira' => 1000, 'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 5,
            'payment_ref' => self::OURS, 'status' => 'confirmed',
            'created_at' => '2026-08-01 10:00:00',
        ]);
    }

    // ── our own reference still works exactly as before ──────────────────────

    public function test_our_own_reference_resolves(): void
    {
        $this->order();

        $r = PaymentLookup::resolve(self::OURS);

        $this->assertTrue($r['found']);
        $this->assertSame('ours', $r['kind']);
        $this->assertSame(self::OURS, $r['reference']);
        $this->assertFalse($r['asked_gateway'], 'a local hit must never cost a network call');
    }

    // ── the gateway's own numbers ────────────────────────────────────────────

    /**
     * THE ONE THAT MATTERS. This is what is printed on the supporter's receipt, and it
     * used to match nothing anywhere on the platform.
     */
    public function test_a_paystack_transaction_id_resolves(): void
    {
        $id = $this->order();
        DB::table('gates_donations')->where('id', $id)->update([
            'gateway_txn_id' => '4738291042', 'gateway_ref' => 'T845762930123456',
        ]);

        foreach (['4738291042', 'T845762930123456'] as $pasted) {
            $r = PaymentLookup::resolve($pasted);
            $this->assertTrue($r['found'], 'could not find the order from ' . $pasted);
            $this->assertSame('gateway-stored', $r['kind']);
            // And it tells them OUR reference, because that is the one that works
            // everywhere else — including on the phone to us.
            $this->assertSame(self::OURS, $r['reference']);
            $this->assertStringContainsString(self::OURS, $r['say']);
        }
    }

    // ── the shop, whose reference column is not called payment_ref ───────────

    /**
     * gates_orders keys its reference by `reference`; gates_donations by `payment_ref`. The
     * first draft of PaymentLookup queried `payment_ref` on both, so every shop order threw,
     * the throw was swallowed by the catch that exists for unmigrated databases, and an
     * entire product's payments became unfindable with no error logged anywhere.
     */
    public function test_a_shop_order_resolves_by_our_reference_and_by_the_gateways(): void
    {
        $ref = 'AFG-SHP-9f8e7d6c5b4a';
        $id = (int) DB::table('gates_orders')->insertGetId([
            'reference' => $ref, 'email' => 'ada@example.com', 'name' => 'Ada Okonkwo',
            'items_json' => '[]', 'subtotal_naira' => 7500, 'status' => 'paid',
            'created_at' => '2026-08-02 09:00:00',
        ]);
        DB::table('gates_orders')->where('id', $id)->update(['gateway_txn_id' => '7001234567']);

        $mine = PaymentLookup::resolve($ref);
        $this->assertTrue($mine['found'], 'a shop order cannot be found by our own reference');
        $this->assertNotNull($mine['order']);
        $this->assertNull($mine['donation']);
        $this->assertSame($ref, $mine['reference']);

        $theirs = PaymentLookup::resolve('7001234567');
        $this->assertTrue($theirs['found'], 'a shop order cannot be found by its receipt number');
        $this->assertSame($ref, $theirs['reference'], 'it must report OUR reference, read from `reference`');
    }

    /** Every prefix we actually mint has to be in the fuzzy list, or half-pastes fail. */
    public function test_every_reference_prefix_the_platform_mints_is_restorable(): void
    {
        // Copied from the four controllers that mint references. A prefix missing from
        // PaymentLookup::PREFIXES is a supporter whose pasted reference goes unfound.
        foreach ([
            'AFG-PVOTE-' => 'gates_donations', 'AFG-GIVE-' => 'gates_donations',
            'AFG-' => 'gates_donations', 'AFG-SHP-' => 'gates_orders',
        ] as $prefix => $table) {
            $tail = 'aa' . substr(md5($prefix), 0, 10);
            if ($table === 'gates_donations') {
                $this->order(['payment_ref' => $prefix . $tail, 'donor_email' => 'x@example.com']);
            } else {
                DB::table('gates_orders')->insert([
                    'reference' => $prefix . $tail, 'email' => 'x@example.com', 'name' => 'X',
                    'items_json' => '[]', 'subtotal_naira' => 100, 'status' => 'paid',
                    'created_at' => '2026-08-02 09:00:00',
                ]);
            }

            // Pasted as a double-click leaves it: the tail alone, prefix gone.
            $this->assertTrue(PaymentLookup::resolve($tail)['found'],
                'a reference beginning ' . $prefix . ' cannot be found from its tail alone');
        }
    }

    // ── ours, typed by a human ───────────────────────────────────────────────

    /**
     * A double-click on a reference in an email frequently selects only the last
     * hyphen-separated word, so the "AFG-PVOTE-" never makes it into the paste.
     */
    public function test_a_reference_pasted_without_its_prefix_resolves(): void
    {
        $this->order();

        $r = PaymentLookup::resolve('a1b2c3d4e5f6');

        $this->assertTrue($r['found'], 'the commonest copy-paste failure still fails');
        $this->assertSame('ours-fuzzy', $r['kind']);
        $this->assertSame(self::OURS, $r['reference']);
    }

    public function test_case_whitespace_and_trailing_punctuation_are_forgiven(): void
    {
        $this->order();

        foreach ([
            '  ' . self::OURS . '  ',
            self::OURS . '.',
            '"' . self::OURS . '"',
            strtoupper(self::OURS),
            'AFG-PVOTE- a1b2c3d4e5f6',
        ] as $pasted) {
            $this->assertTrue(PaymentLookup::resolve($pasted)['found'],
                'failed on ' . var_export($pasted, true));
        }
    }

    // ── misses ───────────────────────────────────────────────────────────────

    /**
     * Every miss reads the same. Splitting "no such reference" from "that one is not
     * yours" would make this a probe for walking the reference space — the ownership
     * rule belongs to the callers that hand data back, and it is unchanged.
     */
    public function test_a_miss_names_all_three_things_that_would_work(): void
    {
        $this->order();

        $r = PaymentLookup::resolve('AFG-PVOTE-doesnotexist99');

        $this->assertFalse($r['found']);
        $this->assertSame('none', $r['kind']);
        $this->assertStringContainsString('AFG-', $r['say']);
        $this->assertStringContainsString('receipt', $r['say']);
        $this->assertStringContainsString('email address', $r['say']);
    }

    public function test_junk_is_refused_without_a_database_round_trip(): void
    {
        foreach (['', '   ', str_repeat('x', 200)] as $junk) {
            $r = PaymentLookup::resolve($junk);
            $this->assertFalse($r['found']);
            $this->assertFalse($r['asked_gateway']);
        }
    }

    /** No gateway configured (the CLI, the test suite) must not throw. */
    public function test_it_degrades_quietly_with_no_gateway_configured(): void
    {
        $r = PaymentLookup::resolve('T999999999999999');

        $this->assertFalse($r['found']);
        $this->assertFalse($r['asked_gateway']);
    }

    // ── storing what the gateway told us ────────────────────────────────────

    public function test_it_records_the_gateway_ids_it_is_given(): void
    {
        $id = $this->order();

        PaymentLookup::remember('gates_donations', $id, [
            'ok' => true, 'status' => 'success',
            'gateway_id' => '99887766', 'gateway_ref' => 'T111222333444555',
        ]);

        $row = DB::table('gates_donations')->where('id', $id)->first();
        $this->assertSame('99887766', (string) $row->gateway_txn_id);
        $this->assertSame('T111222333444555', (string) $row->gateway_ref);

        // And the next lookup is local.
        $this->assertSame('gateway-stored', PaymentLookup::resolve('99887766')['kind']);
    }

    public function test_remembering_nothing_is_a_no_op(): void
    {
        $id = $this->order();

        PaymentLookup::remember('gates_donations', $id, ['ok' => true]);

        $this->assertNull(DB::table('gates_donations')->where('id', $id)->value('gateway_txn_id'));
    }

    // ── and the surface a supporter actually uses ────────────────────────────

    /**
     * /vote/verify is the page the "where are my votes" population lands on. It has to
     * accept the gateway's number, because that is the one in their hand.
     */
    public function test_the_verify_page_accepts_a_gateway_receipt_number(): void
    {
        $id = $this->order();
        DB::table('gates_donations')->where('id', $id)->update(['gateway_txn_id' => '5566778899']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 4242, 'category_id' => 1, 'name' => 'Amara Okonkwo', 'status' => 'approved',
        ]);
        DB::table('gates_donations')->where('id', $id)->update(['intent_nominee_id' => 4242]);

        $proof = VoteProof::forReference('5566778899');

        $this->assertTrue($proof['found'], 'the verify page still cannot find a payment by its receipt number');
    }

    public function test_the_verify_page_still_finds_our_own_reference(): void
    {
        $this->order();

        $this->assertTrue(VoteProof::forReference(self::OURS)['found']);
    }

    /** And an unknown one no longer apologises for the platform's own limitation. */
    public function test_the_verify_page_no_longer_blames_the_bank_app(): void
    {
        $proof = VoteProof::forReference('AFG-PVOTE-nothinghere');

        $this->assertFalse($proof['found']);
        $this->assertStringNotContainsString('different number', $proof['say'],
            'the message still tells the supporter their receipt number is the wrong one');
    }
}
