<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MemberActivityService as M;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A member seeing what they bought.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS THERE BEFORE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The account dashboard showed votes, nominations, points, share links and community activity —
 * an accurate picture of everything a member had CONTRIBUTED, and nothing whatsoever about
 * anything they had PAID FOR. So the only route to "has my order shipped" or "where is my
 * ticket" was the reference link in an email, and a member who lost that email had to ask a
 * human who looked it up by hand.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. MATCHED CASE-INSENSITIVELY ON EMAIL. There is no `user_id` on an order — checkout does
 *      not require an account — so email is the only join, and an exact match would show
 *      nothing to somebody who typed `Ada@Example.com` at a checkout.
 *   2. AND ONLY ON THEIR OWN EMAIL. This is somebody's purchase history; the query must not be
 *      loosenable into a prefix or a partial.
 *   3. A PENDING ORDER IS SHOWN, A FAILED ONE IS NOT. Pending is the one they are most likely
 *      asking about; failed took no money and is not coming.
 *   4. A HELD WAITLIST OFFER IS DISTINGUISHED FROM AN ORDINARY UNPAID ROW. One has a clock on
 *      it and the seat passes to the next person; the other is an abandoned checkout.
 */
final class MemberPurchasesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_orders')->delete();
        DB::table('gates_event_registrations')->delete();
        DB::table('gates_site_events')->delete();
    }

    /** @param array<string,mixed> $over */
    private function order(array $over = []): void
    {
        static $n = 0;
        DB::table('gates_orders')->insert(array_merge([
            'reference' => 'AFG-SHP-' . str_pad((string) ++$n, 12, '0', STR_PAD_LEFT),
            'email' => 'ada@example.test', 'name' => 'Ada Obi',
            'items_json' => json_encode([['slug' => 'tee', 'name' => 'The Tee', 'qty' => 2]]),
            'subtotal_naira' => 37000, 'status' => 'paid',
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    private function event(string $title = 'The Gala'): int
    {
        return (int) DB::table('gates_site_events')->insertGetId([
            'slug' => \AfricaGates\Support\Slug::make($title), 'title' => $title,
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'location' => 'Lagos', 'status' => 'published',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @param array<string,mixed> $over */
    private function ticket(int $eventId, array $over = []): void
    {
        DB::table('gates_event_registrations')->insert(array_merge([
            'event_id' => $eventId, 'name' => 'Ada Obi', 'email' => 'ada@example.test',
            'phone' => '08031234567', 'tier' => 'Standard', 'quantity' => 1,
            'amount_naira' => 80000, 'status' => 'confirmed', 'ticket_code' => 'ABCD-2468',
            'reference' => 'AFG-EVT-AABBCCDDEE',
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    // ══ 1. orders ════════════════════════════════════════════════════════════

    public function test_an_order_is_found_and_described_by_what_is_in_it(): void
    {
        $this->order();

        $out = M::ordersFor('ada@example.test');

        $this->assertCount(1, $out);
        // Named, not just referenced: "Order AFG-SHP-9f2c…" tells a member nothing about which
        // of their orders they are looking at.
        $this->assertSame('The Tee', $out[0]['what']);
        $this->assertSame(2, $out[0]['items']);
        $this->assertSame(37000, $out[0]['total']);
        $this->assertStringStartsWith('/shop/order/', $out[0]['url']);
    }

    public function test_more_than_one_line_is_summarised_rather_than_listed(): void
    {
        $this->order(['items_json' => json_encode([
            ['slug' => 'tee', 'name' => 'The Tee', 'qty' => 1],
            ['slug' => 'mug', 'name' => 'Mug', 'qty' => 1],
            ['slug' => 'pin', 'name' => 'Pin', 'qty' => 3],
        ])]);

        $out = M::ordersFor('ada@example.test');
        $this->assertSame('The Tee + 2 more', $out[0]['what']);
        $this->assertSame(5, $out[0]['items']);
    }

    public function test_the_email_match_ignores_case(): void
    {
        $this->order(['email' => 'Ada@Example.Test']);

        // An order typed at a checkout and an account registered in lower case are the same
        // person, and an exact match would silently show them nothing.
        $this->assertCount(1, M::ordersFor('ada@example.test'));
        $this->assertCount(1, M::ordersFor('  ADA@EXAMPLE.TEST '));
    }

    public function test_somebody_elses_order_is_never_returned(): void
    {
        $this->order(['email' => 'chidi@example.test']);

        $this->assertSame([], M::ordersFor('ada@example.test'));
        // And a partial must not match either — this is a purchase history, not a search.
        $this->assertSame([], M::ordersFor('example.test'));
        $this->assertSame([], M::ordersFor('ada'));
        $this->assertSame([], M::ordersFor(''));
    }

    public function test_a_pending_order_is_shown_and_a_failed_one_is_not(): void
    {
        $this->order(['status' => 'pending', 'reference' => 'AFG-SHP-PENDING00001']);
        $this->order(['status' => 'failed',  'reference' => 'AFG-SHP-FAILED000001']);

        $out = M::ordersFor('ada@example.test');

        // Pending is the order somebody is most likely asking about; hiding it leaves a person
        // who was charged looking at an empty list. Failed took nothing and is not coming.
        $this->assertSame(['pending'], array_column($out, 'status'));
    }

    public function test_the_delivery_state_and_tracking_note_come_through(): void
    {
        $this->order(['fulfilment' => 'shipped', 'tracking_note' => 'GIG · TRK-88210']);

        $out = M::ordersFor('ada@example.test');
        $this->assertSame('shipped', $out[0]['fulfilment']);
        $this->assertSame('GIG · TRK-88210', $out[0]['tracking']);
    }

    public function test_an_order_from_before_fulfilment_existed_reads_as_unfulfilled(): void
    {
        $this->order(['fulfilment' => null]);

        // NULL and '' both mean the work has not started. A blank badge would be worse than a
        // wrong one, because a member cannot tell it from a missing feature.
        $this->assertSame('unfulfilled', M::ordersFor('ada@example.test')[0]['fulfilment']);
    }

    public function test_orders_come_back_newest_first_and_are_capped(): void
    {
        for ($i = 0; $i < 5; $i++) $this->order();
        // Read back rather than hard-coded: the fixture's counter is process-wide, so an
        // absolute reference here would depend on which other tests ran first.
        $newest = (string) DB::table('gates_orders')->orderByDesc('id')->value('reference');

        $out = M::ordersFor('ada@example.test', 3);
        $this->assertCount(3, $out);
        // Newest first: the order somebody is asking about is almost always the last one.
        $this->assertSame($newest, $out[0]['reference']);
    }

    // ══ 2. tickets ═══════════════════════════════════════════════════════════

    public function test_a_confirmed_ticket_carries_the_code_that_opens_the_door(): void
    {
        $this->ticket($this->event());

        $out = M::ticketsFor('ada@example.test');

        $this->assertCount(1, $out);
        $this->assertSame('The Gala', $out[0]['event']);
        $this->assertSame('confirmed', $out[0]['state']);
        $this->assertSame('ABCD-2468', $out[0]['code']);
        $this->assertSame('Lagos', $out[0]['where']);
        $this->assertStringStartsWith('/events/ticket/', $out[0]['url']);
    }

    public function test_a_held_waitlist_offer_is_not_the_same_as_an_abandoned_checkout(): void
    {
        $id = $this->event();
        $this->ticket($id, [
            'status' => 'pending', 'ticket_code' => null, 'reference' => 'AFG-EVT-OFFERED001',
            'offered_at' => Carbon::now()->toDateTimeString(),
            'offer_expires_at' => Carbon::now()->addHours(48)->toDateTimeString(),
        ]);
        $this->ticket($id, [
            'status' => 'pending', 'ticket_code' => null, 'reference' => 'AFG-EVT-ABANDONED1',
            'email' => 'ada@example.test',
        ]);

        $out = M::ticketsFor('ada@example.test');
        $states = array_column($out, 'state');

        // One has a clock on it and the seat passes to the next person waiting; the other is
        // somebody who closed a tab. Showing both as "pending" hides the urgent one.
        $this->assertContains('offered', $states);
        $this->assertContains('unpaid', $states);
        $offer = $out[array_search('offered', $states, true)];
        $this->assertNotSame('', $offer['expires']);
    }

    public function test_a_waitlisted_row_links_to_the_event_because_it_has_no_ticket(): void
    {
        $id = $this->event();
        $this->ticket($id, ['status' => 'waitlisted', 'ticket_code' => null,
                            'reference' => null, 'amount_naira' => 0]);

        $out = M::ticketsFor('ada@example.test');
        $this->assertSame('waiting', $out[0]['state']);
        $this->assertSame('/events/the-gala', $out[0]['url']);
    }

    public function test_a_cancelled_registration_is_not_shown(): void
    {
        $id = $this->event();
        $this->ticket($id, ['status' => 'cancelled', 'reference' => 'AFG-EVT-CANCELLED1']);

        $this->assertSame([], M::ticketsFor('ada@example.test'));
    }

    public function test_somebody_elses_ticket_is_never_returned(): void
    {
        $this->ticket($this->event(), ['email' => 'chidi@example.test']);

        $this->assertSame([], M::ticketsFor('ada@example.test'));
        $this->assertSame([], M::ticketsFor('example.test'));
    }

    public function test_a_ticket_survives_its_event_being_deleted(): void
    {
        $id = $this->event();
        $this->ticket($id);
        DB::table('gates_site_events')->where('id', $id)->delete();

        // A LEFT join, not an inner one: the registration is the record that somebody paid, and
        // an orphaned row disappearing from their account is how a receipt becomes deniable.
        $out = M::ticketsFor('ada@example.test');
        $this->assertCount(1, $out);
        $this->assertSame('An event', $out[0]['event']);
        $this->assertSame('ABCD-2468', $out[0]['code']);
    }

    public function test_multiple_seats_are_reported(): void
    {
        $this->ticket($this->event(), ['quantity' => 4]);
        $this->assertSame(4, M::ticketsFor('ada@example.test')[0]['seats']);
    }
}
