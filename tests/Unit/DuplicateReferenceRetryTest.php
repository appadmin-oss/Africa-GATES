<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EventTicketService, PaymentService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A RESUMED BOOKING COULD NOT BE PAID FOR.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THE PRODUCTION LOG SHOWED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *     CheckoutCouldNotStart: paystack refused AFG-EVT-1F815CF56103C5B9
 *                            — Duplicate Transaction Reference
 *
 * Three times, across four days. `AFG-EVT-` references are 16 hex characters from
 * `random_bytes(8)`, so a duplicate is not a collision — it is the SAME reference being
 * submitted twice.
 *
 * {@see EventTicketService::hold()} does that on purpose. A buyer who presses the button
 * twice, or comes back to a tab they left open, must not end up holding two lots of seats
 * out of a limited tier, so a live pending hold for the same tier, quantity and discount
 * code is resumed and its existing reference handed back. Right for the seats. Wrong for
 * the gateway, which has already opened a transaction against that reference and will not
 * open a second — so the controller cancelled the hold and told the buyer "we could not
 * start the payment", for something they did nothing to cause.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY ROTATING IS SAFE HERE AND NOWHERE ELSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The obvious fear is an orphaned payment: money taken against the old reference and a
 * webhook that then matches no row. It cannot happen at this point. A reference is refused
 * as a duplicate because a transaction was OPENED against it, and a transaction that had
 * been PAID would have marked this registration paid — so a row still `pending` cannot
 * have money attached to the reference being replaced.
 *
 * That is why the rotation is behind the refusal rather than applied on every resume.
 */
final class DuplicateReferenceRetryTest extends TestCase
{
    // ══ classifying the refusal ══════════════════════════════════════════════

    public function test_the_gateways_duplicate_refusal_is_recognised(): void
    {
        foreach (['Duplicate Transaction Reference',
                  'duplicate transaction reference',
                  'This reference is a duplicate'] as $msg) {
            $this->assertTrue(PaymentService::isDuplicateReference($msg), $msg);
        }
    }

    /**
     * AND NOTHING ELSE IS.
     *
     * A false positive costs one wasted retry with a fresh reference, which is cheap — but
     * a match on "the gateway is unreachable" would rotate a reference for a fault that has
     * nothing to do with it, and the row would carry a name nobody had seen fail.
     */
    public function test_no_other_refusal_is_mistaken_for_it(): void
    {
        foreach (['"email" must be a valid email',
                  'An error occurred',
                  'Could not reach the payment provider.',
                  'Invalid amount.',
                  'Invalid reference'] as $msg) {
            $this->assertFalse(PaymentService::isDuplicateReference($msg), $msg);
        }
    }

    // ══ rotating the reference ═══════════════════════════════════════════════

    /**
     * A registration row and nothing else.
     *
     * `rotateReference()` touches one table, so the fixture builds one row. (`gates_events`
     * in this schema is the audit-event log, not the ticketed event — a fact this test
     * learned the expensive way.)
     */
    private function registration(string $status, string $ref): int
    {
        return (int) DB::table('gates_event_registrations')->insertGetId([
            'event_id' => 1, 'name' => 'Uzordinma Edeh',
            'email' => 'u@example.test', 'quantity' => 2,
            'status' => $status, 'reference' => $ref,
            'amount_naira' => 20000,
        ]);
    }

    public function test_a_pending_booking_gets_a_reference_the_gateway_has_not_seen(): void
    {
        $old = EventTicketService::REF_PREFIX . 'AAAAAAAAAAAAAAAA';
        $id  = $this->registration('pending', $old);

        $new = EventTicketService::rotateReference($id);

        $this->assertNotSame('', $new);
        $this->assertNotSame($old, $new);
        $this->assertStringStartsWith(EventTicketService::REF_PREFIX, $new);
        $this->assertSame($new, (string) DB::table('gates_event_registrations')
            ->where('id', $id)->value('reference'));

        // The booking itself is untouched — same seats, same price. Rotating a payment
        // reference must not quietly become re-pricing or re-holding.
        $row = DB::table('gates_event_registrations')->where('id', $id)->first();
        $this->assertSame(2, (int) $row->quantity);
        $this->assertSame(20000, (int) $row->amount_naira);
        $this->assertSame('pending', (string) $row->status);
    }

    /**
     * A PAID ROW IS NEVER ROTATED.
     *
     * This is the whole safety argument, so it is asserted rather than reasoned about: if
     * a registration has already been paid, its reference is the name the money is filed
     * under and changing it orphans a real payment. The `status = pending` clause in the
     * UPDATE is what prevents that, and it is load-bearing.
     */
    public function test_a_paid_booking_is_left_alone(): void
    {
        $old = EventTicketService::REF_PREFIX . 'BBBBBBBBBBBBBBBB';
        $id  = $this->registration('paid', $old);

        $this->assertSame('', EventTicketService::rotateReference($id),
            'a paid registration was given a new reference, orphaning its payment');
        $this->assertSame($old, (string) DB::table('gates_event_registrations')
            ->where('id', $id)->value('reference'));
    }

    /** A row that is not there cannot be rotated, and says so rather than inventing one. */
    public function test_a_missing_booking_reports_failure(): void
    {
        $this->assertSame('', EventTicketService::rotateReference(999999));
    }

    /**
     * AND THE CONTROLLER ACTUALLY RETRIES.
     *
     * The two pieces above are inert without the call site: a classifier nothing consults
     * and a rotation nothing triggers is the shape of bug this codebase has shipped seven
     * times. Asserted from source because the paid branch needs a live gateway to reach.
     */
    public function test_the_checkout_retries_once_with_the_fresh_reference(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Controllers/EventsController.php');
        // Comments stripped: the note explaining this fix quotes the refusal it fixes.
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ', $src);

        $this->assertStringContainsString('PaymentService::isDuplicateReference', $code,
            'the checkout does not recognise the refusal, so a resumed booking still dies');
        $this->assertStringContainsString('EventTicketService::rotateReference', $code,
            'the refusal is recognised and nothing is done about it');

        // ONCE. A loop here would hammer the gateway on any refusal whose message happened
        // to match, and the retry is only ever justified by a reference the gateway has
        // already seen — which a fresh one, by construction, has not.
        $this->assertSame(1, substr_count($code, 'EventTicketService::rotateReference'));

        // And the callback URL is rebuilt with the new reference. Retrying with the old
        // callback would send a paid buyer to a reference the row no longer carries, which
        // is the orphaned payment this whole design exists to avoid.
        $at = (int) strpos($code, 'EventTicketService::rotateReference');
        $this->assertStringContainsString('$callback  =', substr($code, $at, 400),
            'the retry reuses the callback built for the old reference');
    }
}
