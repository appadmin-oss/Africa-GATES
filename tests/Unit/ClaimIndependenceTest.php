<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ClaimIndependence;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The nominator must not be able to claim the page they created.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ATTACK
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The email on a nomination was typed by the NOMINATOR. It is a claim about the
 * nominee, not proof from them. So:
 *
 *   I nominate a well-known choral director and type MY OWN address in the nominee
 *   email field. The nomination is genuine, a moderator approves it, her page goes
 *   live. I "claim" it with a code sent to my own inbox. I have proved I can read
 *   my own email; the system records it as proof of her identity.
 *
 * No amount of OTP rigour fixes an input the attacker supplied, which is why this
 * check exists and why it runs BEFORE a code is ever sent.
 *
 * ── AND THE OPPOSITE MISTAKE, WHICH WOULD BE WORSE ───────────────────────────
 *
 * The commonest reason a nominee's contact matches their nominator's is that a
 * customer, a daughter or a church secretary filled the form in for them using the
 * only address they had — their own. That person is the MOST likely to be the real
 * nominee. So this class never refuses; it reports what matched, and the caller
 * holds the claim for a human.
 *
 * Half the tests below are therefore about NOT holding honest claims. A check that
 * catches every attacker and half the genuine nominees is not a security control,
 * it is exclusion with a security justification.
 */
final class ClaimIndependenceTest extends TestCase
{
    private int $nomineeId = 0;
    private const CAT = 8800;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_nominations')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 88, 'title' => 'P', 'slug' => 'p-88']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 8800, 'programme_id' => 88, 'year' => 2026,
            'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::CAT, 'cycle_id' => 8800,
            'title' => 'Choral', 'slug' => 'choral-8800']);

        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => self::CAT, 'name' => 'Adaeze Okonkwo',
            'status' => 'approved', 'vote_count' => 0]);
    }

    /** An approved nomination naming this nominee. */
    private function nomination(array $over = []): int
    {
        return (int) DB::table('gates_nominations')->insertGetId($over + [
            'cycle_id' => 8800, 'category_id' => self::CAT,
            'nominee_name'  => 'Adaeze Okonkwo',
            'nominee_email' => 'adaeze@example.test',
            'nominee_phone' => '08031234567',
            'nominator_name'  => 'Chidi Obi',
            'nominator_email' => 'chidi@example.test',
            'nominator_phone' => '08099998888',
            'reference' => 'AFG-NOM-' . bin2hex(random_bytes(4)),
            'reason'    => 'She stayed after every rehearsal for a term.',
            'status'    => 'approved',
            'ip_hash'   => hash('sha256', 'nominator-ip'),
            'device_fp' => 'nominator-device-fp',
        ]);
    }

    // ══ the attack ═══════════════════════════════════════════════════════════

    /** THE CASE THIS EXISTS FOR: the nominator typed their own address. */
    public function test_the_nominators_own_address_is_not_independent(): void
    {
        $this->nomination(['nominee_email' => 'chidi@example.test']);

        $v = ClaimIndependence::check($this->nomineeId, email: 'chidi@example.test');

        $this->assertFalse($v['independent']);
        $this->assertContains('email', $v['matched']);
    }

    /**
     * Plus-addressing and Gmail dots are one inbox, and must not be a bypass.
     *
     * The cheapest possible defeat of an exact-match check: nominate with
     * chidi@gmail.com, claim with chidi+gates@gmail.com. Both deliver to the same
     * person.
     */
    public function test_an_alias_of_the_nominators_inbox_is_not_independent(): void
    {
        $this->nomination(['nominator_email' => 'chidi.obi@gmail.com']);

        foreach ([
            'chidi.obi+gates@gmail.com' => 'plus-addressing',
            'chidiobi@gmail.com'        => 'gmail ignores dots',
            'c.h.i.d.i.o.b.i@gmail.com' => 'dots anywhere',
        ] as $address => $why) {
            $v = ClaimIndependence::check($this->nomineeId, email: $address);
            $this->assertFalse($v['independent'], "{$address} ({$why}) reaches the nominator's inbox.");
            $this->assertContains('email-alias', $v['matched']);
        }
    }

    /** The same phone in a different format is the same phone. */
    public function test_the_same_number_written_differently_is_not_independent(): void
    {
        $this->nomination(['nominator_phone' => '08099998888']);

        foreach (['+2348099998888', '2348099998888', '0809 999 8888', '08099998888'] as $written) {
            $v = ClaimIndependence::check($this->nomineeId, phone: $written);
            $this->assertFalse($v['independent'], "{$written} is the nominator's number.");
            $this->assertContains('phone', $v['matched']);
        }
    }

    /**
     * The device and network are compared whatever channel is claimed.
     *
     * These are the signals an attacker cannot simply re-type. Somebody claiming
     * from the same browser that submitted the nomination is the textbook case, and
     * it is caught without reference to any address at all.
     */
    public function test_claiming_from_the_nominators_own_device_is_caught(): void
    {
        $this->nomination();

        $v = ClaimIndependence::check($this->nomineeId,
            email: 'someone-else@example.test', deviceFp: 'nominator-device-fp');

        $this->assertFalse($v['independent']);
        $this->assertContains('device', $v['matched']);
    }

    public function test_claiming_from_the_nominators_network_is_caught(): void
    {
        $this->nomination();

        $v = ClaimIndependence::check($this->nomineeId,
            email: 'someone-else@example.test', ipHash: hash('sha256', 'nominator-ip'));

        $this->assertFalse($v['independent']);
        $this->assertContains('ip', $v['matched']);
    }

    /**
     * EVERY nominator is checked, not only the one being claimed against.
     *
     * A nominee with three nominations has three nominators. An address matching any
     * of them is an address a nominator can read, so checking only the claimed row
     * would let whoever submitted the second nomination claim through the first.
     */
    public function test_a_second_nominators_address_is_also_disqualifying(): void
    {
        $this->nomination();
        $this->nomination(['nominator_email' => 'blessing@example.test',
                           'nominator_phone' => '08055556666']);

        $v = ClaimIndependence::check($this->nomineeId, email: 'blessing@example.test');

        $this->assertFalse($v['independent']);
        $this->assertSame(2, $v['checked'], 'Both nominations must be compared.');
    }

    // ══ and not holding honest people ════════════════════════════════════════

    /** THE ORDINARY CASE. Her own address, nothing shared. Straight through. */
    public function test_the_nominees_own_independent_address_passes(): void
    {
        $this->nomination();

        $v = ClaimIndependence::check($this->nomineeId,
            email: 'adaeze@example.test', deviceFp: 'her-phone', ipHash: hash('sha256', 'her-ip'));

        $this->assertTrue($v['independent']);
        $this->assertSame([], $v['matched']);
    }

    /**
     * A shared DOMAIN is not a shared inbox.
     *
     * Colleagues at one organisation, or two people on the same free provider, are
     * different people. Holding on the domain would hold most of Nigeria — everybody
     * is @gmail.com.
     */
    public function test_the_same_domain_is_not_the_same_person(): void
    {
        $this->nomination(['nominator_email' => 'chidi@stmarys.org.ng']);

        $v = ClaimIndependence::check($this->nomineeId, email: 'adaeze@stmarys.org.ng');

        $this->assertTrue($v['independent'],
            'Two people at one organisation are two people.');
    }

    /**
     * Dot-stripping is a GMAIL rule and must not be applied elsewhere.
     *
     * At a provider where dots are significant, a.okonkwo@ and aokonkwo@ are
     * different mailboxes belonging to different people. Treating them as one would
     * hold an innocent claim on a rule that provider does not follow.
     */
    public function test_dots_are_only_ignored_where_the_provider_ignores_them(): void
    {
        $this->nomination(['nominator_email' => 'a.okonkwo@stmarys.org.ng']);

        $v = ClaimIndependence::check($this->nomineeId, email: 'aokonkwo@stmarys.org.ng');

        $this->assertTrue($v['independent'],
            'Only Gmail ignores dots; assuming it everywhere holds real people.');
    }

    /** A blank channel matches nothing — absence is not a collision. */
    public function test_empty_values_never_match(): void
    {
        $this->nomination(['nominator_phone' => '', 'device_fp' => '', 'ip_hash' => '']);

        $v = ClaimIndependence::check($this->nomineeId,
            email: 'adaeze@example.test', phone: '', deviceFp: '', ipHash: '');

        $this->assertTrue($v['independent'],
            'Two empty strings are not a match; that would hold every claim.');
    }

    // ══ failing safe ════════════════════════════════════════════════════════

    /**
     * NO readable nomination is NOT independence.
     *
     * A merged nominee, a renamed column or a failed read would otherwise sail
     * through the one check that stops the commonest attack. Absence of evidence is
     * not evidence.
     */
    public function test_an_unreadable_nomination_holds_rather_than_passes(): void
    {
        // Nominee exists; no nomination rows at all.
        $v = ClaimIndependence::check($this->nomineeId, email: 'anyone@example.test');

        $this->assertFalse($v['independent']);
        $this->assertContains('no-nomination', $v['matched']);
        $this->assertSame(0, $v['checked']);
    }

    /** A pending nomination cannot put anyone on a ballot, so it is not a source. */
    public function test_only_approved_nominations_are_compared(): void
    {
        $this->nomination(['status' => 'pending', 'nominator_email' => 'lurker@example.test']);

        $v = ClaimIndependence::check($this->nomineeId, email: 'lurker@example.test');

        $this->assertFalse($v['independent'], 'No approved nomination exists — this holds.');
        $this->assertContains('no-nomination', $v['matched'],
            'It holds for want of a nomination, not because the pending row matched.');
    }

    // ══ what the held person is told ════════════════════════════════════════

    /**
     * A hold must never read as an accusation.
     *
     * The likeliest explanation is that somebody helped them fill the form in. The
     * wording has to leave that open, say a person will sort it out, and say it is
     * free — §7 of the fairness doc, and the difference between a bar and a barrier.
     */
    public function test_a_hold_is_explained_without_accusing_anyone(): void
    {
        $this->nomination(['nominee_email' => 'chidi@example.test']);

        $say = ClaimIndependence::check($this->nomineeId, email: 'chidi@example.test')['say'];

        $this->assertStringContainsString('one more thing', $say);
        $this->assertStringContainsString('filled the form in for you', $say);
        $this->assertStringContainsString('nothing to pay', $say);

        foreach (['denied', 'refused', 'rejected', 'fraud', 'failed'] as $word) {
            $this->assertStringNotContainsString($word, strtolower($say),
                "\"{$word}\" turns a held claim into an accusation against the likeliest "
                . 'genuine nominee.');
        }
    }

    // ══ the channel picker ══════════════════════════════════════════════════

    /**
     * Channels are offered with their verdict ALREADY known.
     *
     * Sending a code and only then saying "this one will be held" wastes an SMS and
     * reads as a trap.
     */
    public function test_channels_are_offered_with_independence_precomputed(): void
    {
        $this->nomination();

        $ch = ClaimIndependence::channelsFor($this->nomineeId);

        $this->assertCount(2, $ch, 'Her email and her phone.');
        foreach ($ch as $c) {
            $this->assertTrue($c['independent'], 'Both of her own channels are independent.');
        }
    }

    /** Independent channels are offered first, so the fast path is the default. */
    public function test_a_compromised_channel_is_offered_last_not_hidden(): void
    {
        // Her phone is her own; the email on the nomination is the nominator's.
        $this->nomination(['nominee_email' => 'chidi@example.test']);

        $ch = ClaimIndependence::channelsFor($this->nomineeId);

        $this->assertTrue($ch[0]['independent'], 'The usable one comes first.');
        $this->assertSame('phone', $ch[0]['channel']);
        $this->assertFalse($ch[1]['independent']);
        $this->assertCount(2, $ch,
            'The held channel is still OFFERED — it routes to a human, and hiding it '
            . 'would leave somebody with no way forward at all.');
    }

    /**
     * The page must never disclose the nominee's actual contact details.
     *
     * An unclaimed page is public. If this returned real addresses it would be a
     * contact-harvesting endpoint for every nominee on the platform.
     */
    public function test_channel_hints_are_masked(): void
    {
        $this->nomination();

        foreach (ClaimIndependence::channelsFor($this->nomineeId) as $c) {
            $this->assertStringContainsString('•', $c['hint']);
            $this->assertStringNotContainsString('adaeze@example.test', $c['hint']);
            $this->assertStringNotContainsString('08031234567', $c['hint']);
        }
    }

    /** The masked hint still has to be recognisable to its owner. */
    public function test_a_masked_hint_is_still_recognisable(): void
    {
        $this->nomination();

        $byKind = [];
        foreach (ClaimIndependence::channelsFor($this->nomineeId) as $c) $byKind[$c['channel']] = $c['hint'];

        $this->assertStringStartsWith('a', $byKind['email']);
        $this->assertStringEndsWith('@example.test', $byKind['email'],
            'The domain is what lets somebody recognise their own address.');
        $this->assertStringEndsWith('567', $byKind['phone'],
            'The last digits are what lets somebody recognise their own number.');
    }

    /** One nominee, three nominations, the same address on each: offered once. */
    public function test_duplicate_channels_are_not_offered_twice(): void
    {
        $this->nomination();
        $this->nomination(['nominator_email' => 'other@example.test']);

        $emails = array_filter(ClaimIndependence::channelsFor($this->nomineeId),
            static fn($c) => $c['channel'] === 'email');

        $this->assertCount(1, $emails);
    }
}
