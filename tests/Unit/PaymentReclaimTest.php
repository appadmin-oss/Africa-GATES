<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{PaymentReconciler, PaymentService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Self-service payment recovery.
 *
 * ── THE INCIDENT THIS WAS WRITTEN FOR ────────────────────────────────────────
 *
 * Two buyers paid for votes through a wallet app and got neither votes nor a
 * receipt. Paying inside a wallet very often means the browser never returns to
 * us, so `/pay/callback` never runs and the gateway WEBHOOK is the only crediting
 * path left. When that webhook does not arrive — a wrong URL or signing secret in
 * the gateway dashboard, and nothing in either system says so — the order sits
 * pending forever and the buyer has no way to do anything about it.
 *
 * `run()` is the sweep that covers this, but it only helps if cron is ticked.
 * `reclaim()` lets the buyer fix it themselves, in seconds.
 *
 * ── WHAT MUST HOLD ───────────────────────────────────────────────────────────
 *
 * Nothing here may take the user's word for anything. The gateway is re-queried
 * and its answer is the only thing that credits votes; the reference is scoped
 * to the caller's own email; and running it twice must credit once.
 */
final class PaymentReclaimTest extends TestCase
{
    private int $nomineeId = 0;
    private const MINE = 'okun@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_votes')->delete();
        DB::table('gates_donations')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 94, 'title' => 'P', 'slug' => 'p-941']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 941, 'programme_id' => 94, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 941, 'cycle_id' => 941, 'title' => 'Cat', 'slug' => 'cat-941']);
        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 941, 'name' => 'Ada Okonkwo', 'status' => 'approved', 'vote_count' => 0,
        ]);
    }

    private function gateway(array $answers): PaymentService
    {
        return new class ($answers) extends PaymentService {
            public function __construct(private array $answers) { parent::__construct(); }
            public function isKnownProvider(string $p): bool { return $p === 'paystack'; }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            {
                return $this->answers[$reference]
                    ?? ['ok' => false, 'status' => 'pending', 'amount' => 0, 'currency' => 'NGN', 'meta' => [], 'message' => 'unknown'];
            }
        };
    }

    private function order(string $ref, int $naira, int $votes, string $email = self::MINE,
                           string $status = 'pending', int $used = 0): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Okun Alimosho', 'donor_email' => $email,
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => $votes, 'votes_used' => $used,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref, 'status' => $status,
            'created_at' => Carbon::now()->subMinutes(10)->toDateTimeString(),
        ]);
    }

    private function reclaimer(array $answers): PaymentReconciler
    {
        return new PaymentReconciler($this->gateway($answers));
    }

    // ── the incident, reproduced and fixed ───────────────────────────────────

    /** ₦3,920 for 20 votes, paid, stuck pending because no webhook arrived. */
    public function test_a_stuck_payment_is_confirmed_and_its_votes_credited(): void
    {
        $this->order('paystack_6413965117_hw8rf', 3920, 20);

        $r = $this->reclaimer(['paystack_6413965117_hw8rf' => ['ok' => true, 'status' => 'success', 'amount' => 3920]])
            ->reclaim('paystack_6413965117_hw8rf', self::MINE);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('CONFIRMED', $r['code']);
        $this->assertSame(20, $r['minted']);
        $this->assertSame('confirmed', (string) DB::table('gates_donations')->where('payment_ref', 'paystack_6413965117_hw8rf')->value('status'));
        $this->assertSame(20, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'),
            'the votes must reach the nominee, not just the order');
    }

    /** Confirmed but never minted — money taken, no votes. Repairable. */
    public function test_a_confirmed_order_that_never_minted_is_repaired(): void
    {
        $this->order('AFG-STUCK', 3920, 20, self::MINE, 'confirmed', 0);

        $r = $this->reclaimer([])->reclaim('AFG-STUCK', self::MINE);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('MINTED', $r['code']);
        $this->assertSame(20, $r['minted']);
        $this->assertSame(20, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
    }

    /** Running it twice must not credit twice. Buyers WILL press it twice. */
    public function test_reclaiming_twice_credits_once(): void
    {
        $this->order('AFG-TWICE', 1000, 7);
        $g = ['AFG-TWICE' => ['ok' => true, 'status' => 'success', 'amount' => 1000]];

        $this->reclaimer($g)->reclaim('AFG-TWICE', self::MINE);
        $second = $this->reclaimer($g)->reclaim('AFG-TWICE', self::MINE);

        $this->assertTrue($second['ok']);
        $this->assertSame('ALREADY', $second['code']);
        $this->assertSame(7, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'),
            'a second reclaim must not double-credit');
    }

    // ── what it must refuse ──────────────────────────────────────────────────

    /** THE scoping test. Someone else's reference is not visible, let alone fixable. */
    public function test_another_persons_reference_cannot_be_reclaimed(): void
    {
        $this->order('AFG-THEIRS', 5000, 30, 'someone.else@example.test');

        $r = $this->reclaimer(['AFG-THEIRS' => ['ok' => true, 'status' => 'success', 'amount' => 5000]])
            ->reclaim('AFG-THEIRS', self::MINE);

        $this->assertFalse($r['ok']);
        $this->assertSame('NOT_FOUND', $r['code']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-THEIRS')->value('status'));
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
    }

    /** A reference nobody paid for credits nothing, however confidently it is typed. */
    public function test_an_unpaid_reference_credits_nothing(): void
    {
        $this->order('AFG-UNPAID', 2000, 10);

        $r = $this->reclaimer([])->reclaim('AFG-UNPAID', self::MINE);

        $this->assertFalse($r['ok']);
        $this->assertSame('NOT_PAID', $r['code']);
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
    }

    /** The gateway saying "paid" for a different amount is not authorisation. */
    public function test_an_amount_mismatch_credits_nothing(): void
    {
        $this->order('AFG-MISMATCH', 3920, 20);

        $r = $this->reclaimer(['AFG-MISMATCH' => ['ok' => true, 'status' => 'success', 'amount' => 100]])
            ->reclaim('AFG-MISMATCH', self::MINE);

        $this->assertFalse($r['ok']);
        $this->assertSame('MISMATCH', $r['code']);
        $this->assertSame('pending', (string) DB::table('gates_donations')->where('payment_ref', 'AFG-MISMATCH')->value('status'));
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
    }

    public function test_a_refunded_payment_is_not_re_credited(): void
    {
        $id = $this->order('AFG-REFUNDED', 3920, 20, self::MINE, 'confirmed', 20);
        DB::table('gates_donations')->where('id', $id)->update(['refunded_at' => Carbon::now()->toDateTimeString()]);

        $r = $this->reclaimer(['AFG-REFUNDED' => ['ok' => true, 'status' => 'success', 'amount' => 3920]])
            ->reclaim('AFG-REFUNDED', self::MINE);

        $this->assertFalse($r['ok']);
        $this->assertSame('REFUNDED', $r['code']);
    }

    public function test_an_empty_or_absurd_reference_is_rejected_before_any_lookup(): void
    {
        foreach (['', '   ', str_repeat('x', 200)] as $bad) {
            $r = $this->reclaimer([])->reclaim($bad, self::MINE);
            $this->assertFalse($r['ok']);
            $this->assertSame('BAD_REF', $r['code']);
        }
    }

    /** Staff (no email) may reclaim any reference — that is the admin path. */
    public function test_staff_scope_is_not_limited_to_one_email(): void
    {
        $this->order('AFG-STAFF', 1500, 3, 'anyone@example.test');

        $r = $this->reclaimer(['AFG-STAFF' => ['ok' => true, 'status' => 'success', 'amount' => 1500]])
            ->reclaim('AFG-STAFF', null);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(3, $r['minted']);
    }
}
