<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{PaymentDestination, PlatformTip};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE DONOR'S VOLUNTARY GIFT TO AFRICA GATES — ADDED, NEVER TAKEN.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE PLATFORM NEEDED ONE AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Africa GATES is a project of a Nigerian non-profit and has to fund itself. Two
 * mechanisms already did: `platform_fee_bps`, the agreed cut that comes OUT of a partner's
 * gift, and the shop, tickets and stands. The obvious third was missing entirely — a donor
 * giving to a charity through our rails who would also have given something to the rails,
 * and had no way to.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE RULE THAT MAKES IT DEFENSIBLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * **The tip is added to the charge, never taken out of the gift.** The organisation
 * receives exactly the number the donor typed for them whether the tip is zero or the
 * largest we allow. Every assertion in the first block is that one sentence, because the
 * alternative — funding ourselves by giving the charity less — is a thing a donor would
 * have to read the code to discover, and is not what the form says.
 *
 * That is also why it rides to the gateway as a flat `transaction_charge` rather than
 * through the subaccount's percentage: the percentage splits the gift, the flat charge sits
 * beside it.
 */
final class PlatformTipTest extends TestCase
{
    private function org(int $feeBps = 500, string $status = 'approved'): object
    {
        $id = (int) DB::table('gates_partner_orgs')->insertGetId([
            'name' => 'Borehole Trust', 'slug' => 'borehole-trust-' . bin2hex(random_bytes(3)),
            'status' => $status, 'platform_fee_bps' => $feeBps,
            'subaccount_code' => 'ACCT_' . bin2hex(random_bytes(6)),
        ]);
        return (object) ['id' => $id];
    }

    // ══ the arithmetic ═══════════════════════════════════════════════════════

    public function test_a_tip_is_a_percentage_of_the_gift(): void
    {
        $org = $this->org();

        $this->assertSame(500,  PlatformTip::naira(10000, 5,  $org));
        $this->assertSame(1000, PlatformTip::naira(10000, 10, $org));
        $this->assertSame(1500, PlatformTip::naira(10000, 15, $org));
    }

    /**
     * ZERO IS A REAL ANSWER AND IT IS THE DEFAULT.
     *
     * A pre-ticked box is not consent in any jurisdiction that has looked at one, and under
     * the NDPA 2023 and the GDPR line a default the user did not choose is not a choice. It
     * is also bad for the thing it funds: a donor who finds at the bank that they gave us
     * something they did not intend disputes the whole payment, and the organisation loses
     * their gift along with our tip.
     */
    public function test_nothing_is_added_by_default(): void
    {
        $this->assertSame(0, PlatformTip::DEFAULT_PCT);
        $this->assertSame(0, PlatformTip::naira(10000, PlatformTip::DEFAULT_PCT, $this->org()));
        $this->assertSame(0, PlatformTip::naira(10000, 0, $this->org()));
        $this->assertSame(0, PlatformTip::naira(10000, '', $this->org()));
    }

    /**
     * AND ZERO IS OFFERED RATHER THAN BEING THE ABSENCE OF THE CONTROL.
     *
     * A donor who is asked and says no has declined. One who was never asked has not, and
     * only the first is a record anybody can stand behind.
     */
    public function test_declining_is_one_of_the_offered_answers(): void
    {
        $this->assertContains(0, PlatformTip::options());
        $this->assertSame(PlatformTip::options(), array_values(array_unique(PlatformTip::options())));

        $sorted = PlatformTip::options(); sort($sorted);
        $this->assertSame($sorted, PlatformTip::options(), 'the ladder must read low to high');
    }

    /**
     * A PERCENTAGE WE DID NOT OFFER IS NOT CHARGED.
     *
     * Not clamped to the nearest offered one: a value we did not render is a form we did
     * not show, and charging somebody the closest thing to what their browser sent is not a
     * consent anybody could defend.
     */
    public function test_a_percentage_that_was_not_offered_is_declined(): void
    {
        $org = $this->org();

        $this->assertSame(0, PlatformTip::naira(10000, 7,   $org), '7% is not on the ladder');
        $this->assertSame(0, PlatformTip::naira(10000, 999, $org));
        $this->assertSame(0, PlatformTip::naira(10000, -5,  $org));
        $this->assertSame(0, PlatformTip::naira(10000, 'ten', $org));
    }

    /** And it is capped, so a mistype cannot outweigh the gift. */
    public function test_it_is_capped(): void
    {
        (new \AfricaGates\Services\RuleEngine());   // no-op; keeps the intent readable
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => PlatformTip::KEY_OPTIONS], ['value' => '0,50']);

        $org = $this->org();
        $this->assertSame(PlatformTip::MAX_NAIRA,
            PlatformTip::naira(100_000_000, 50, $org),
            'an uncapped flat charge above the transaction is refused by the gateway, so it '
            . 'is a failed checkout rather than a windfall');
    }

    /** Not offered on a gift to Africa GATES itself — that is a second field for one thing. */
    public function test_it_is_not_offered_on_our_own_page(): void
    {
        $this->assertFalse(PlatformTip::offeredFor(null));
        $this->assertSame(0, PlatformTip::naira(10000, 10, null));
    }

    public function test_an_operator_can_switch_it_off(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => PlatformTip::KEY_ENABLED], ['value' => '0']);

        $this->assertFalse(PlatformTip::enabled());
        $this->assertFalse(PlatformTip::offeredFor($this->org()));
        $this->assertSame(0, PlatformTip::naira(10000, 10, $this->org()));
    }

    // ══ and it reaches the gateway as a flat charge ══════════════════════════

    /**
     * THE SPLIT IS WHAT MAKES THE PROMISE TRUE.
     *
     * `transaction_charge` is a fixed amount in KOBO that settles to the main account
     * before the remainder goes to the subaccount. Kobo matters: a naira figure here
     * settles a hundredth of the intended tip, silently, and the first anybody notices is a
     * reconciliation that is 99% short.
     */
    public function test_the_tip_rides_as_a_flat_charge_in_kobo(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'paystack_subaccount_donation'], ['value' => 'ACCT_x']);
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'subaccounts_enabled'], ['value' => '1']);

        $org = $this->org();
        $fields = PaymentDestination::initFieldsForPartner((int) $org->id, 1500);

        if ($fields === []) {
            $this->markTestSkipped('subaccount routing is off in this environment');
        }

        $this->assertSame('150000', $fields['transaction_charge'] ?? null,
            'the tip must reach the gateway in kobo — naira here settles a hundredth of it');
        $this->assertArrayHasKey('subaccount', $fields,
            'the organisation must still be the one receiving the gift');
    }

    /** No tip, no field. An empty `transaction_charge` is a value the gateway may reject. */
    public function test_no_tip_sends_no_charge_field(): void
    {
        $org = $this->org();
        $fields = PaymentDestination::initFieldsForPartner((int) $org->id, 0);

        if ($fields !== []) {
            $this->assertArrayNotHasKey('transaction_charge', $fields);
        } else {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * AND A TIP IS NEVER ROUTED FOR A PAYMENT THAT IS NOT A PARTNER GIFT.
     *
     * Read off the row, like the org id, because it decides how somebody else's money
     * settles and a figure a caller passes in is a figure a browser could choose.
     */
    public function test_the_tip_is_read_from_the_row_and_only_for_a_partner_gift(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'A Supporter', 'donor_email' => 'x@example.test',
            'amount_naira' => 11000, 'tier' => 'donation', 'bonus_votes' => 0,
            'payment_ref' => 'AFG-GIVE-abc123', 'status' => 'pending',
            'platform_tip_naira' => 1000,
        ]);

        $this->assertSame(1000, PaymentDestination::platformTipForReference('AFG-GIVE-abc123'));
        $this->assertSame(0, PaymentDestination::platformTipForReference('AFG-PVOTE-abc123'),
            'a paid-vote reference is not a partner gift and has no tip to route');
        $this->assertSame(0, PaymentDestination::platformTipForReference(''));
    }
}
