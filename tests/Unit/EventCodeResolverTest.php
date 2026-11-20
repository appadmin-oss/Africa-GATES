<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\EventCodeResolver;
use AfricaGates\Services\ReferralService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * One input box taking either a discount or a referral, and a referral that may instead
 * have arrived by link.
 *
 * The buyer holds a string of letters and does not know which kind it is. Every case here
 * is a way that could go wrong in a way the buyer would not understand.
 */
class EventCodeResolverTest extends TestCase
{
    private int $eventId = 1;
    private int $tierId  = 1;

    protected function setUp(): void
    {
        parent::setUp();
        // Only gates_referral_codes is required. EventDiscount reads gates_event_codes,
        // which is migration-created rather than part of the base test schema — and its
        // ABSENCE is a valid state to test through: a code that is not a discount because
        // there are no discounts is still not a discount, and the resolver must fall
        // through to the referral branch rather than fail.
        if (!DB::schema()->hasTable('gates_referral_codes')) {
            $this->markTestSkipped('gates_referral_codes not in the test schema');
        }
        DB::table('gates_users')->insert([
            ['id' => 1, 'name' => 'Amara', 'email' => 'amara@example.com'],
            ['id' => 2, 'name' => 'Chidi', 'email' => 'chidi@example.com'],
        ]);
    }

    private function resolve(string $typed, string $linked = '', ?int $userId = null,
                            string $email = 'buyer@example.com'): array
    {
        return EventCodeResolver::resolve($typed, $linked, $this->eventId, $this->tierId,
                                          10000, $email, 1, $userId);
    }

    public function test_an_empty_box_and_no_link_is_simply_nothing(): void
    {
        $r = $this->resolve('');
        $this->assertSame('none', $r['kind']);
        $this->assertFalse($r['ok']);
        $this->assertSame('', $r['referral']);
        $this->assertSame('', $r['discount']);
    }

    public function test_a_referral_code_typed_into_the_discount_box_is_understood(): void
    {
        // The whole point of one field: a buyer typing a referral code into the box
        // labelled "discount" must not be told their perfectly valid code is unrecognised.
        $code = (string) ReferralService::codeFor(1);
        $r = $this->resolve($code, '', 2);

        $this->assertTrue($r['ok']);
        $this->assertSame('referral', $r['kind']);
        $this->assertSame($code, $r['referral']);
        $this->assertSame(0, $r['off'], 'a referral must not change the price');
        $this->assertStringContainsString('does not change your price', $r['message']);
    }

    public function test_a_link_applies_with_the_box_left_empty(): void
    {
        // The primary path: the referrer shares a URL and the buyer does nothing.
        $code = (string) ReferralService::codeFor(1);
        $r = $this->resolve('', $code, 2);

        $this->assertTrue($r['ok']);
        $this->assertSame('referral', $r['kind']);
        $this->assertSame($code, $r['referral']);
    }

    public function test_the_link_wins_over_a_referral_typed_in_the_box(): void
    {
        // "Prioritise the link." And say so, rather than silently ignoring what was typed —
        // a referral credited to the wrong person is how a support ticket starts.
        $alice = (string) ReferralService::codeFor(1);
        DB::table('gates_referral_codes')->insert(['user_id' => 2, 'code' => 'AGBOBBOB']);

        $r = $this->resolve('AGBOBBOB', $alice, null, 'buyer@example.com');

        $this->assertSame($alice, $r['referral'], 'the link should win');
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('link', $r['message'],
            'the buyer typed something that did not win and must be told');
    }

    public function test_a_self_referring_link_is_ignored_rather_than_shown_as_an_error(): void
    {
        // The buyer did not type it and cannot act on it, so it is dropped quietly — but
        // it must NOT be credited.
        $code = (string) ReferralService::codeFor(1);
        $r = $this->resolve('', $code, 1);

        $this->assertSame('', $r['referral']);
        $this->assertSame('none', $r['kind']);
        $this->assertSame('', $r['message'], 'nothing the buyer can act on should be said');
    }

    public function test_a_self_referring_typed_code_is_refused_out_loud(): void
    {
        // They typed it, so they get told why it did not work.
        $code = (string) ReferralService::codeFor(1);
        $r = $this->resolve($code, '', 1);

        $this->assertFalse($r['ok']);
        $this->assertSame('', $r['referral']);
        $this->assertStringContainsString('your own', $r['message']);
    }

    public function test_an_unrecognised_code_says_so(): void
    {
        $r = $this->resolve('TOTALNONSENSE');
        $this->assertFalse($r['ok']);
        $this->assertSame('none', $r['kind']);
        $this->assertNotSame('', $r['message'], 'a refusal with no reason is a dead end');
    }

    public function test_a_link_still_earns_alongside_a_discount_code(): void
    {
        // These are not alternatives: the referrer brought the buyer, and the buyer may
        // separately hold a promotion. Both apply.
        $code = (string) ReferralService::codeFor(1);
        $r = $this->resolve('', $code, 2);
        $this->assertSame($code, $r['referral']);

        // With a discount typed as well, the discount is passed through AND the link keeps
        // its credit. (No discount fixture here, so assert the shape the resolver returns
        // for the referral half, which is the part this feature owns.)
        $r2 = $this->resolve('NOTADISCOUNT', $code, 2);
        $this->assertSame($code, $r2['referral'], 'the link must survive an unrecognised typed code');
    }

    public function test_whitespace_and_case_do_not_defeat_a_code(): void
    {
        $code = (string) ReferralService::codeFor(1);
        $r = $this->resolve('  ' . strtolower($code) . ' ', '', 2);
        $this->assertTrue($r['ok']);
        $this->assertSame($code, $r['referral']);
    }
}
