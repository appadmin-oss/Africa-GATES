<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\StockAlert as A;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Tell me when it's back" — the shop's answer to a sold-out page.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS THERE BEFORE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A greyed-out button. A sold-out product page's entire answer to the person most likely to buy
 * one was nothing at all — and stock comes back far more often than an event seat does, so this
 * is the same dead end the waitlist closed, on the side where it happens more.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. PER VARIANT. Somebody who wants L does not want to be told M is back.
 *   2. THE ANSWER NEVER REVEALS WHETHER AN ADDRESS IS ALREADY ON THE LIST. This list is a
 *      record of what somebody wanted to buy, and "you are already signed up" is a way to
 *      test addresses against it.
 *   3. NOBODY IS EMAILED TWICE ABOUT ONE RESTOCK, and nobody is emailed at all about something
 *      that is still sold out — that would spend the single message they agreed to.
 *   4. THE ROW IS STAMPED BEFORE THE MAIL IS ATTEMPTED. A bounce must cost one message, not
 *      leave a row pending for the next save to write to the same person again.
 *   5. ONE CLICK OFF, WITH NO ACCOUNT. Token-as-whole-credential, exactly like a ticket.
 */
final class StockAlertTest extends TestCase
{
    private int $productId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_stock_alerts')->delete();
        DB::table('gates_product_variants')->delete();
        DB::table('gates_products')->delete();

        $this->productId = (int) DB::table('gates_products')->insertGetId([
            'slug' => 'tee', 'name' => 'The Tee', 'category' => 'Apparel',
            'price_naira' => 18500, 'stock' => 0, 'is_active' => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @param array<string,mixed> $over */
    private function variant(array $over = []): int
    {
        return (int) DB::table('gates_product_variants')->insertGetId(array_merge([
            'product_id' => $this->productId, 'label' => 'L', 'axis' => 'Size',
            'price_delta_naira' => 0, 'stock' => 0, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    /** A mailer that records rather than sends. */
    private function mailer(): object
    {
        return new class extends \AfricaGates\Services\OtpService {
            /** @var list<array{to:string,subject:string}> */
            public array $sent = [];
            public function __construct() {}
            public function sendBranded(string $to, string $subject, string $htmlBody,
                                        string $plainBody = '', string $category = '',
                                        string $hero = '', string $unsubscribeUrl = '', array $attachments = []): array
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody];
                return ['ok' => true];
            }
        };
    }

    // ══ 1. asking ════════════════════════════════════════════════════════════

    public function test_asking_records_a_request_against_the_exact_option(): void
    {
        $l = $this->variant(['label' => 'L']);

        $r = A::want($this->productId, $l, 'ada@example.test', 'Ada Obi');

        $this->assertTrue($r['ok']);
        $row = DB::table('gates_stock_alerts')->first();
        $this->assertSame($l, (int) $row->variant_id);
        $this->assertSame('ada@example.test', (string) $row->email);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $row->token);
        $this->assertNull($row->notified_at);
    }

    public function test_a_product_with_no_options_is_recorded_against_variant_zero(): void
    {
        $r = A::want($this->productId, 0, 'ada@example.test');

        $this->assertTrue($r['ok']);
        // 0, never NULL: MySQL treats NULLs as distinct in a unique index, so NULL would let the
        // same person be recorded twice and emailed twice about one restock.
        $this->assertSame(0, (int) DB::table('gates_stock_alerts')->value('variant_id'));
    }

    public function test_asking_twice_answers_identically_and_stores_one_row(): void
    {
        $l = $this->variant();

        $first  = A::want($this->productId, $l, 'ada@example.test');
        $second = A::want($this->productId, $l, 'ADA@example.test');

        $this->assertTrue($second['ok']);
        // Word for word the same. "You are already signed up" would be a way to test which
        // addresses are on a list of what people wanted to buy.
        $this->assertSame($first['message'], $second['message']);
        $this->assertSame(1, DB::table('gates_stock_alerts')->count());
    }

    public function test_asking_again_after_being_told_reopens_the_same_row(): void
    {
        $l = $this->variant();
        A::want($this->productId, $l, 'ada@example.test');
        DB::table('gates_stock_alerts')->update(['notified_at' => Carbon::now()->toDateTimeString()]);

        // It sold out again and they want to know about THIS restock. A second row would email
        // them twice.
        $this->assertTrue(A::want($this->productId, $l, 'ada@example.test')['ok']);
        $this->assertSame(1, DB::table('gates_stock_alerts')->count());
        $this->assertNull(DB::table('gates_stock_alerts')->value('notified_at'));
        $this->assertSame(1, A::waiting($this->productId, $l));
    }

    public function test_something_in_stock_is_answered_with_the_better_news(): void
    {
        $l = $this->variant(['stock' => 4]);

        $r = A::want($this->productId, $l, 'ada@example.test');

        // "You can buy it now" is more useful than a promise to email later.
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('in stock', $r['message']);
        $this->assertSame(0, DB::table('gates_stock_alerts')->count());
    }

    public function test_an_untracked_product_is_never_out_of_stock(): void
    {
        DB::table('gates_products')->where('id', $this->productId)->update(['stock' => null]);

        // NULL means nobody counts these, which is a legitimate answer and not zero.
        $this->assertFalse(A::want($this->productId, 0, 'ada@example.test')['ok']);
    }

    public function test_a_bad_email_is_refused(): void
    {
        $this->assertFalse(A::want($this->productId, 0, 'not-an-address')['ok']);
        $this->assertSame(0, DB::table('gates_stock_alerts')->count());
    }

    public function test_a_variant_from_another_product_is_refused(): void
    {
        $other = (int) DB::table('gates_products')->insertGetId([
            'slug' => 'cap', 'name' => 'Cap', 'category' => 'Apparel', 'price_naira' => 9000,
            'stock' => 0, 'is_active' => 1, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $theirs = (int) DB::table('gates_product_variants')->insertGetId([
            'product_id' => $other, 'label' => 'One size', 'price_delta_naira' => 0,
            'stock' => 0, 'is_active' => 1, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        // The pairing arrives from a form, so it is verified rather than believed — exactly as
        // ShopCatalogue::pick() does.
        $this->assertFalse(A::want($this->productId, $theirs, 'ada@example.test')['ok']);
    }

    public function test_an_inactive_product_takes_no_requests(): void
    {
        DB::table('gates_products')->where('id', $this->productId)->update(['is_active' => 0]);
        $this->assertFalse(A::want($this->productId, 0, 'ada@example.test')['ok']);
    }

    // ══ 2. counting ══════════════════════════════════════════════════════════

    public function test_waiting_counts_only_the_option_asked_about(): void
    {
        $l = $this->variant(['label' => 'L']);
        $m = $this->variant(['label' => 'M', 'sort_order' => 1]);

        A::want($this->productId, $l, 'a@x.test');
        A::want($this->productId, $l, 'b@x.test');
        A::want($this->productId, $m, 'c@x.test');

        $this->assertSame(2, A::waiting($this->productId, $l));
        $this->assertSame(1, A::waiting($this->productId, $m));
        // -1 means "every option of this product".
        $this->assertSame(3, A::waiting($this->productId));
        $this->assertSame([$l => 2, $m => 1], A::waitingByVariant($this->productId));
    }

    public function test_a_notified_or_cancelled_request_stops_counting(): void
    {
        $l = $this->variant();
        A::want($this->productId, $l, 'a@x.test');
        A::want($this->productId, $l, 'b@x.test');
        A::want($this->productId, $l, 'c@x.test');

        DB::table('gates_stock_alerts')->whereRaw('LOWER(email) = ?', ['a@x.test'])
            ->update(['notified_at' => Carbon::now()->toDateTimeString()]);
        DB::table('gates_stock_alerts')->whereRaw('LOWER(email) = ?', ['b@x.test'])
            ->update(['cancelled_at' => Carbon::now()->toDateTimeString()]);

        $this->assertSame(1, A::waiting($this->productId, $l));
    }

    // ══ 3. telling ═══════════════════════════════════════════════════════════

    public function test_a_restock_emails_everybody_waiting_for_that_option(): void
    {
        $l = $this->variant(['label' => 'L']);
        $m = $this->variant(['label' => 'M', 'sort_order' => 1]);
        A::want($this->productId, $l, 'wants-l@x.test');
        A::want($this->productId, $m, 'wants-m@x.test');

        DB::table('gates_product_variants')->where('id', $l)->update(['stock' => 6]);
        $mailer = $this->mailer();

        $r = A::release($this->productId, $l, $mailer, 'https://afg.test');

        $this->assertSame(1, $r['sent']);
        $this->assertSame(['wants-l@x.test'], array_column($mailer->sent, 'to'));
        $this->assertStringContainsString('The Tee (L)', $mailer->sent[0]['subject']);
        // The person waiting for M hears nothing: they do not want to be told L is back.
        $this->assertSame(1, A::waiting($this->productId, $m));
    }

    public function test_the_email_carries_a_one_click_unsubscribe(): void
    {
        $l = $this->variant();
        A::want($this->productId, $l, 'ada@x.test');
        DB::table('gates_product_variants')->where('id', $l)->update(['stock' => 3]);
        $token = (string) DB::table('gates_stock_alerts')->value('token');

        $mailer = $this->mailer();
        A::release($this->productId, $l, $mailer, 'https://afg.test');

        // No account required to STOP receiving mail — the worst possible place for a
        // registration wall.
        $this->assertStringContainsString('/shop/back-in-stock/stop/' . $token, $mailer->sent[0]['html']);
    }

    public function test_nothing_is_sent_about_something_still_sold_out(): void
    {
        $l = $this->variant(['stock' => 0]);
        A::want($this->productId, $l, 'ada@x.test');

        $mailer = $this->mailer();
        $r = A::release($this->productId, $l, $mailer);

        // Sending somebody to a page that says "sold out" is worse than never writing: it
        // spends the one message they agreed to receive.
        $this->assertSame(0, $r['sent']);
        $this->assertSame([], $mailer->sent);
        $this->assertSame(1, A::waiting($this->productId, $l));
        $this->assertStringContainsStringIgnoringCase('still sold out', $r['message']);
    }

    public function test_nobody_is_told_twice_about_one_restock(): void
    {
        $l = $this->variant();
        A::want($this->productId, $l, 'ada@x.test');
        DB::table('gates_product_variants')->where('id', $l)->update(['stock' => 5]);

        $first  = $this->mailer();
        $second = $this->mailer();
        A::release($this->productId, $l, $first);
        A::release($this->productId, $l, $second);

        $this->assertCount(1, $first->sent);
        $this->assertSame([], $second->sent, 'the same person was emailed twice');
    }

    public function test_the_row_is_stamped_even_when_the_mail_cannot_be_sent(): void
    {
        $l = $this->variant();
        A::want($this->productId, $l, 'ada@x.test');
        DB::table('gates_product_variants')->where('id', $l)->update(['stock' => 5]);

        // No mailer at all — the worst case, and the row must still be stamped. Otherwise the
        // next save writes to the same person again, and the record of whether we have used up
        // their single message is wrong.
        $r = A::release($this->productId, $l, null);

        $this->assertSame(1, $r['sent']);
        $this->assertNotNull(DB::table('gates_stock_alerts')->value('notified_at'));
        $this->assertSame(0, A::waiting($this->productId, $l));
    }

    public function test_a_batch_limit_is_reported_rather_than_hidden(): void
    {
        $l = $this->variant();
        for ($i = 0; $i < A::BATCH + 3; $i++) A::want($this->productId, $l, 'p' . $i . '@x.test');
        DB::table('gates_product_variants')->where('id', $l)->update(['stock' => 500]);

        $r = A::release($this->productId, $l, null);

        $this->assertSame(A::BATCH, $r['sent']);
        $this->assertSame(3, $r['left']);
        // A batch limit that looked like completion is how half a list quietly never hears
        // anything at all.
        $this->assertStringContainsString('3 still waiting', $r['message']);
    }

    public function test_releasing_with_nobody_waiting_says_so(): void
    {
        $l = $this->variant(['stock' => 9]);
        $this->assertSame(0, A::release($this->productId, $l, null)['sent']);
    }

    // ══ 4. stopping ══════════════════════════════════════════════════════════

    public function test_a_token_takes_somebody_off_the_list(): void
    {
        A::want($this->productId, 0, 'ada@x.test');
        $token = (string) DB::table('gates_stock_alerts')->value('token');

        $this->assertTrue(A::stop($token));
        $this->assertSame(0, A::waiting($this->productId, 0));
        $this->assertNotNull(DB::table('gates_stock_alerts')->value('cancelled_at'));
    }

    public function test_a_cancelled_person_is_never_emailed(): void
    {
        $l = $this->variant();
        A::want($this->productId, $l, 'ada@x.test');
        A::stop((string) DB::table('gates_stock_alerts')->value('token'));
        DB::table('gates_product_variants')->where('id', $l)->update(['stock' => 5]);

        $mailer = $this->mailer();
        A::release($this->productId, $l, $mailer);
        $this->assertSame([], $mailer->sent);
    }

    public function test_a_malformed_or_unknown_token_is_refused_without_erroring(): void
    {
        $this->assertFalse(A::stop('nope'));
        $this->assertFalse(A::stop(''));
        $this->assertFalse(A::stop(str_repeat('a', 32)));
    }

    public function test_stopping_twice_is_not_an_error_but_changes_nothing(): void
    {
        A::want($this->productId, 0, 'ada@x.test');
        $token = (string) DB::table('gates_stock_alerts')->value('token');
        $was = A::stop($token);
        $again = A::stop($token);

        $this->assertTrue($was);
        // Already cancelled: nothing to do, and the controller answers the reader identically
        // either way so the difference cannot be used to test tokens.
        $this->assertFalse($again);
    }

    // ══ 5. the restock order ═════════════════════════════════════════════════

    public function test_demand_is_grouped_and_ordered_by_how_many_are_waiting(): void
    {
        $l = $this->variant(['label' => 'L']);
        $m = $this->variant(['label' => 'M', 'sort_order' => 1]);
        foreach (['a', 'b', 'c'] as $who) A::want($this->productId, $m, $who . '@x.test');
        A::want($this->productId, $l, 'd@x.test');

        $d = A::demand();

        $this->assertCount(2, $d);
        // A restock order written by the people who wanted to pay, biggest first.
        $this->assertSame('M', $d[0]['variant']);
        $this->assertSame(3, $d[0]['waiting']);
        $this->assertSame('L', $d[1]['variant']);
        $this->assertSame('The Tee', $d[0]['name']);
    }

    public function test_demand_ignores_requests_already_met(): void
    {
        $l = $this->variant();
        A::want($this->productId, $l, 'a@x.test');
        DB::table('gates_stock_alerts')->update(['notified_at' => Carbon::now()->toDateTimeString()]);

        $this->assertSame([], A::demand());
    }
}
