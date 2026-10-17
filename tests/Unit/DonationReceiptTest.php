<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\DonationController;
use AfricaGates\Controllers\PaymentController;
use AfricaGates\Services\CheckoutMailer;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\PaymentService;
use AfricaGates\Services\QueueService;
use AfricaGates\Services\RecurringGiving;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * A GIFT IS RECEIPTED HOWEVER IT WAS CONFIRMED — AND CONFIRMED BY ONE RULE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RECEIPT RODE ON THE BROWSER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `DonationController::callback()` sent the gift receipt (and the office's alert, and —
 * for a monthly gift — the ONLY copy of the link that stops it) when, and only when, the
 * callback was the call that flipped the row. An `AFG-GIVE-` reference is confirmed by the
 * gateway's webhook too, through `PaymentController::confirmByReference()`, whose delivery
 * step acted on paid votes alone; and the reconcile sweep's `CheckoutMailer::receipt()`
 * answered `not_paid_vote`. So a donor whose webhook landed first, or who paid inside a
 * banking app and never came back, got nothing — and a monthly donor had no way to stop.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE CALLBACK CONFIRMED BY A RULE THE WEBHOOK HAD ALREADY RETIRED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Its private `confirm()` demanded the verified amount EQUAL the row — the strict `!==`
 * the shared path replaced with `<` after the gateway's "customer bears the fee" toggle
 * refused every payment on the platform — and never checked the currency. One gift could
 * be refused by the browser and accepted by the webhook a second later.
 */
final class DonationReceiptTest extends TestCase
{
    /** @var OtpService&object{sent: list<array{to:string,subject:string,html:string}>} */
    private OtpService $mail;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();
        DB::table('gates_jobs')->delete();

        // The real class, subclassed, so the production signature of sendBranded() is the
        // one exercised.
        $this->mail = new class extends OtpService {
            public array $sent = [];
            public function __construct() { parent::__construct(['username' => 'u', 'password' => 'p']); }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = '', string $unsubscribeUrl = '', array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody];
                return ['success' => true];
            }
        };
        CheckoutMailer::using($this->mail);
    }

    protected function tearDown(): void
    {
        CheckoutMailer::using(null);
        parent::tearDown();
    }

    /** @param array<string,array{amount:int,currency?:string}> $paid keyed by reference */
    private function gateway(array $paid): PaymentService
    {
        return new class ($paid) extends PaymentService {
            public function __construct(private array $paid) { parent::__construct(); }
            public function isKnownProvider(string $p): bool { return $p === 'paystack'; }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function verify(string $provider, string $reference): array
            {
                $a = $this->paid[$reference] ?? null;
                if ($a === null) {
                    return ['ok' => false, 'status' => 'pending', 'amount' => 0, 'currency' => 'NGN', 'meta' => []];
                }
                return ['ok' => true, 'status' => 'success', 'amount' => (int) $a['amount'],
                        'currency' => (string) ($a['currency'] ?? 'NGN'), 'meta' => []];
            }
        };
    }

    /** A pending gift exactly as DonationController::start() writes it. */
    private function gift(string $ref, int $naira = 5000): object
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'Amara Okonkwo', 'donor_email' => 'amara@example.org',
            'amount_naira' => $naira, 'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0,
            'payment_ref' => $ref, 'status' => 'pending', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        return DB::table('gates_donations')->where('payment_ref', $ref)->first();
    }

    /** What Maintenance does with the queue, so a queued receipt is a sent one. */
    private function drain(): void
    {
        $q = new QueueService();
        $q->on(CheckoutMailer::JOB_RECEIPT, static function (array $p): void {
            CheckoutMailer::receipt((int) ($p['donation_id'] ?? 0));
        });
        $q->work(50);
    }

    /** @return list<array{to:string,subject:string,html:string}> */
    private function toDonor(): array
    {
        return array_values(array_filter($this->mail->sent, static fn (array $m): bool => $m['to'] === 'amara@example.org'));
    }

    private function webhook(PaymentService $gw, string $ref): string
    {
        $don = DB::table('gates_donations')->where('payment_ref', $ref)->first();
        return (new PaymentController($gw, new \Slim\Views\Twig(new \Twig\Loader\ArrayLoader([]))))
            ->confirmByReference('paystack', $ref, $don, 'webhook');
    }

    private function browserReturn(PaymentService $gw, string $ref): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/donate/callback')
            ->withQueryParams(['ref' => $ref, 'provider' => 'paystack']);
        $res = (new DonationController($gw, new \Slim\Views\Twig(new \Twig\Loader\ArrayLoader([]))))
            ->callback($req, new Response());
        return $res->getHeaderLine('Location');
    }

    // ══ the receipt ══════════════════════════════════════════════════════════

    /**
     * The webhook confirms, the browser never comes back — and the monthly donor still gets
     * the receipt, with the link that stops the gift in it.
     */
    public function test_a_webhook_confirmed_monthly_gift_is_receipted_with_its_stop_link(): void
    {
        $ref = 'AFG-GIVE-' . bin2hex(random_bytes(4));
        $this->gift($ref);
        RecurringGiving::start('amara@example.org', 'Amara Okonkwo', 5000, 'PLN_5k', $ref);
        $token = (string) DB::table('gates_donation_subscriptions')->where('first_ref', $ref)->value('manage_token');

        $this->assertSame('confirmed', $this->webhook($this->gateway([$ref => ['amount' => 5000]]), $ref));
        $this->drain();

        $mails = $this->toDonor();
        $this->assertCount(1, $mails, 'a gift confirmed by the webhook was never receipted');
        $this->assertStringContainsString($token, $mails[0]['html'],
            'the monthly donor\'s receipt carries no way to stop the gift');
        $this->assertStringContainsString('monthly', $mails[0]['html']);
        $this->assertNotNull(DB::table('gates_donations')->where('payment_ref', $ref)->value('receipt_sent_at'));
    }

    /** And the browser arriving afterwards sends nothing more. */
    public function test_the_callback_after_the_webhook_does_not_send_a_second_receipt(): void
    {
        $ref = 'AFG-GIVE-' . bin2hex(random_bytes(4));
        $this->gift($ref);
        $gw = $this->gateway([$ref => ['amount' => 5000]]);

        $this->webhook($gw, $ref);
        $this->drain();
        $this->assertStringContainsString('/success', $this->browserReturn($gw, $ref));
        $this->drain();
        // And the reconcile sweep's own call, later still.
        CheckoutMailer::receipt((int) DB::table('gates_donations')->where('payment_ref', $ref)->value('id'));

        $this->assertCount(1, $this->toDonor(), 'one gift, more than one receipt');
        $this->assertCount(2, $this->mail->sent, 'exactly one receipt and one office alert');
    }

    /** A one-off gift confirmed by the callback alone is receipted, without a stop link. */
    public function test_a_callback_confirmed_one_off_gift_is_receipted_without_a_stop_link(): void
    {
        $ref = 'AFG-GIVE-' . bin2hex(random_bytes(4));
        $this->gift($ref);

        $this->browserReturn($this->gateway([$ref => ['amount' => 5000]]), $ref);
        $this->drain();

        $mails = $this->toDonor();
        $this->assertCount(1, $mails);
        $this->assertStringNotContainsString('/giving/manage/', $mails[0]['html'],
            'a one-off gift was offered a way to stop a monthly charge it does not have');
    }

    // ══ the confirmation rule ════════════════════════════════════════════════

    /**
     * "Customer bears the fee": the charge arrives above the gift. Refusing it left the
     * row pending with the donor's money taken.
     */
    public function test_the_callback_confirms_a_gift_paid_with_the_fee_on_top(): void
    {
        $ref = 'AFG-GIVE-' . bin2hex(random_bytes(4));
        $this->gift($ref, 5000);

        $loc = $this->browserReturn($this->gateway([$ref => ['amount' => 5075]]), $ref);

        $this->assertStringContainsString('/success', $loc, 'an overpaid gift was refused');
        $row = DB::table('gates_donations')->where('payment_ref', $ref)->first();
        $this->assertSame('confirmed', (string) $row->status);
        $this->assertNotNull($row->confirmed_at, 'the shared path stamps when the money arrived');
    }

    /** ₦5,000 and $5,000 are the same integer. */
    public function test_the_callback_refuses_a_gift_paid_in_another_currency(): void
    {
        $ref = 'AFG-GIVE-' . bin2hex(random_bytes(4));
        $this->gift($ref, 5000);

        $loc = $this->browserReturn($this->gateway([$ref => ['amount' => 5000, 'currency' => 'USD']]), $ref);

        $this->assertStringNotContainsString('/success', $loc);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', $ref)->value('status'),
            'a payment in dollars confirmed a gift priced in naira');
    }

    /** And short of the gift is still refused, whichever side asks. */
    public function test_an_underpaid_gift_is_still_refused(): void
    {
        $ref = 'AFG-GIVE-' . bin2hex(random_bytes(4));
        $this->gift($ref, 5000);

        $this->browserReturn($this->gateway([$ref => ['amount' => 4999]]), $ref);

        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', $ref)->value('status'));
        $this->drain();
        $this->assertSame([], $this->toDonor());
    }

    // ══ the month that used not to have a tier ═══════════════════════════════

    /**
     * A recurring instalment is a gift to the fund, so it is written as one — and a
     * redelivery of the same charge mints nothing.
     */
    public function test_a_recurring_instalment_is_a_donation_and_is_minted_once(): void
    {
        RecurringGiving::start('amara@example.org', 'Amara Okonkwo', 5000, 'PLN_5k', 'AFG-GIVE-first');

        $id = RecurringGiving::chargeArrived('PLN_5k', 'amara@example.org', 'PSK_month2', 5000, '2026-11-04T09:00:00.000Z');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(0, RecurringGiving::chargeArrived('PLN_5k', 'amara@example.org', 'PSK_month2', 5000));

        $this->assertSame('donation', (string) DB::table('gates_donations')->where('id', $id)->value('tier'),
            'the instalment carries no tier, so nothing reading gifts by tier counts it');
        $this->assertSame(1, DB::table('gates_donations')->where('payment_ref', 'PSK_month2')->count());
        $this->assertSame(1, (int) DB::table('gates_donation_subscriptions')->where('first_ref', 'AFG-GIVE-first')->value('charges'));
    }

    /**
     * The check-then-insert is serialised. SQLite cannot race two writers, so what is held
     * here is the SHAPE: the arrangement row is taken FOR UPDATE inside a transaction before
     * the existence check, in the same method — the same standard OtpAttemptCapTest applies
     * to VoteService::verifyAndVote().
     */
    public function test_the_instalment_claim_is_serialised_on_the_arrangement(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/RecurringGiving.php');
        $src  = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ', $src);
        $at   = (int) strpos($src, 'function chargeArrived(');
        $next = (int) strpos($src, 'function ', $at + 25);
        // The closure is a `function` too; step past it to the next method.
        while ($next !== 0 && preg_match('~\G\s*\(~', $src, $m, 0, $next + 8) === 1) {
            $next = (int) strpos($src, 'function ', $next + 9);
        }
        $body = substr($src, $at, max(0, $next - $at));

        $tx   = strpos($body, '->transaction(');
        $lock = strpos($body, '->lockForUpdate()');
        $chk  = strpos($body, "where('payment_ref'");
        $this->assertIsInt($tx, 'chargeArrived() no longer runs in a transaction');
        $this->assertIsInt($lock, 'chargeArrived() no longer locks the arrangement');
        $this->assertIsInt($chk);
        $this->assertTrue($tx < $lock && $lock < $chk,
            'the existence check must run after the lock, inside the transaction, or two '
            . 'deliveries of one charge each see no row and each mint one');
    }
}
