<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CheckoutMailer;
use AfricaGates\Services\PaymentReconciler;
use AfricaGates\Services\PaymentService;
use AfricaGates\Services\PaymentTriage;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Some payments are already marked failed by Paystack. The site still says pending."
 * (owner, 10 Nov 2026, over a console reading "Payments 101").
 *
 * Paystack sends no webhook for a failed charge, so a row learns it failed only by being
 * ASKED. Three faults kept the answer from landing, and each is held here:
 *
 *  1. The hourly sweep asked about paid votes and, on `failed`, did nothing — "not paid
 *     yet" — so the row stayed pending until the three-day ceiling wrote it off as
 *     abandoned. Orders and tickets were always marked failed; paid votes, the bulk of the
 *     money, never were.
 *  2. It asked the newest twenty-five each hour. Abandoned checkouts stay pending for three
 *     days, so on a busy night the same twenty-five were asked every hour and the rows
 *     behind them never were. It now takes turns, least recently asked first.
 *  3. The Payments screen's "ask the gateway" button heard `failed` and counted the row
 *     `clean`, so an operator could watch Paystack declare them failed and nothing change.
 *
 * And the one consequence that had to be carried: the "you didn't finish" email read
 * `pending` only, so recording a decline as failed would have silently stopped it for the
 * people whose bank said no.
 */
final class PaymentGatewayFailedTest extends TestCase
{
    /** @var list<string> references asked about, in order */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();
        $this->asked = [];
    }

    /** @param array<string,array<string,mixed>> $answers */
    private function gateway(array $answers, string $default = 'pending'): PaymentService
    {
        $asked = &$this->asked;
        return new class ($answers, $default, $asked) extends PaymentService {
            /** @param array<string,array<string,mixed>> $answers */
            public function __construct(private array $answers, private string $default, private array &$asked) { parent::__construct(); }
            public function isKnownProvider(string $p): bool { return $p === 'paystack'; }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            {
                $this->asked[] = $reference;
                return ($this->answers[$reference] ?? ['ok' => true, 'status' => $this->default, 'amount' => 0])
                    + ['currency' => 'NGN', 'meta' => [], 'message' => ''];
            }
        };
    }

    private function pending(string $ref, int $minutesAgo = 60, string $status = 'pending'): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Kwame Mensah', 'donor_email' => 'k' . strtolower($ref) . '@mail.example.org',
            'amount_naira' => 1000, 'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'payment_ref' => $ref, 'status' => $status, 'provider' => 'paystack',
            'created_at' => Carbon::now()->subMinutes($minutesAgo)->toDateTimeString(),
        ]);
    }

    private function row(string $ref): object
    {
        return DB::table('gates_donations')->where('payment_ref', $ref)->first();
    }

    public function test_the_sweep_records_a_declined_paid_vote_as_failed_with_no_write_off_stamp(): void
    {
        $this->pending('AFG-DECLINED');
        $this->pending('AFG-OPEN');

        $r = (new PaymentReconciler($this->gateway(['AFG-DECLINED' => ['ok' => true, 'status' => 'failed', 'amount' => 0]])))
            ->run(apply: true);

        $this->assertSame(1, $r['failed']);
        $this->assertSame('failed', (string) $this->row('AFG-DECLINED')->status, 'Paystack said failed; the site must too');
        $this->assertNull($this->row('AFG-DECLINED')->expired_at ?? null, 'the gateway\'s verdict, not our write-off');
        $this->assertSame('pending', (string) $this->row('AFG-OPEN')->status, 'an abandoned checkout may still complete');

        // Triage files it as the gateway's verdict, not as a row we gave up on.
        $b = PaymentTriage::buckets()['buckets'];
        $this->assertSame(['AFG-DECLINED'], array_map(static fn ($o) => (string) $o->payment_ref, $b['failed']));
    }

    public function test_a_check_only_run_writes_nothing(): void
    {
        $this->pending('AFG-PREVIEW');
        (new PaymentReconciler($this->gateway(['AFG-PREVIEW' => ['ok' => true, 'status' => 'failed', 'amount' => 0]])))->run(apply: false);
        $this->assertSame('pending', (string) $this->row('AFG-PREVIEW')->status);
        $this->assertNull($this->row('AFG-PREVIEW')->swept_at ?? null);
    }

    public function test_the_sweep_takes_turns_so_a_backlog_past_the_limit_is_still_reached(): void
    {
        // 30 abandoned checkouts, oldest first; the sweep reads 25 per tick.
        for ($i = 1; $i <= 30; $i++) $this->pending(sprintf('AFG-Q%02d', $i), 600 - $i);

        (new PaymentReconciler($this->gateway([])))->run(apply: true, limit: 25);
        $first = $this->asked;
        $this->assertCount(25, $first);
        $this->assertSame('AFG-Q30', $first[0], 'never-asked rows, newest first');

        $this->asked = [];
        (new PaymentReconciler($this->gateway([])))->run(apply: true, limit: 25);
        $this->assertSame(['AFG-Q05', 'AFG-Q04', 'AFG-Q03', 'AFG-Q02', 'AFG-Q01'], array_slice($this->asked, 0, 5),
            'the five never reached by the first tick are asked first by the second — before, the same 25 were asked again');
    }

    public function test_the_payments_screen_button_marks_what_the_gateway_says_failed_and_never_demotes_a_confirmed_row(): void
    {
        $this->pending('AFG-BTN-F', 120);
        $this->pending('AFG-BTN-A', 120);
        $t = new PaymentTriage($this->gateway(['AFG-BTN-F' => ['ok' => true, 'status' => 'failed', 'amount' => 0]]));
        $r = $t->askGateway(PaymentTriage::recoverable(30));
        $this->assertSame(['AFG-BTN-F'], array_map(static fn ($o) => (string) $o->payment_ref, $r['failed']),
            'a failed answer is its own result, not "clean"');
        $this->assertSame(1, $r['clean']);

        $this->assertTrue(PaymentReconciler::gatewayFailed('AFG-BTN-F'));
        $this->assertSame('failed', (string) $this->row('AFG-BTN-F')->status);

        $this->pending('AFG-PAID', 120, 'confirmed');
        $this->assertFalse(PaymentReconciler::gatewayFailed('AFG-PAID'), 'a confirmed row is never demoted');
        $this->assertSame('confirmed', (string) $this->row('AFG-PAID')->status);
    }

    public function test_a_declined_buyer_still_gets_the_did_not_finish_email_and_a_written_off_one_does_not(): void
    {
        $declined = $this->pending('AFG-NUDGE-F', 180, 'failed');
        $expired  = $this->pending('AFG-NUDGE-X', 180, 'failed');
        DB::table('gates_donations')->where('id', $expired)->update(['expired_at' => Carbon::now()->toDateTimeString()]);

        $r = CheckoutMailer::sweepAbandoned(50, dryRun: true);
        $this->assertSame(1, $r['considered'], 'the decline is nudged; our own write-off is not');
    }
}
