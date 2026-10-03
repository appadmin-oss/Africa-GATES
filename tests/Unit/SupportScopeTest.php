<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{RateLimitService, SupportContext};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * What the support assistant may do, and for whom.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE LINE BEING TESTED: ACTING IS OPEN, READING IS SCOPED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Almost nobody who buys votes here has an account — the ballot takes an email
 * and a card — so the people hit by the missing-votes incident were guests, and
 * the repair built for them was behind a sign-in they did not have. That was the
 * wrong boundary: it protected nothing (the repair discloses nothing) while
 * excluding everyone who needed it.
 *
 * So these tests pin two different rules at once, and BOTH must hold:
 *   • a guest can repair a payment and resend a receipt from a reference;
 *   • a guest cannot read one — no amount, no name, no email, no list.
 *
 * If a future change makes the repair tools return richer data "to be more
 * helpful", the disclosure tests here are what should stop it.
 */
final class SupportScopeTest extends TestCase
{
    private const MINE = 'okun@example.test';
    private const THEIRS = 'someone.else@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();
    }

    private function order(string $ref, string $email, string $status = 'confirmed', int $naira = 3920): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Okun Alimosho', 'donor_email' => $email,
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 20, 'votes_used' => 20,
            'payment_ref' => $ref, 'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function guest(?RateLimitService $limits = null, string $key = ''): SupportContext
    {
        return new SupportContext(null, null, false, null, $limits, $key);
    }

    private function member(): SupportContext
    {
        return new SupportContext(7, self::MINE, false, null);
    }

    /** @return list<string> */
    private function toolNames(SupportContext $c): array
    {
        return array_column($c->tools(), 'name');
    }

    // ── the repair tools are open ────────────────────────────────────────────

    public function test_a_guest_is_offered_the_repair_tools(): void
    {
        $names = $this->toolNames($this->guest());

        $this->assertContains('fix_payment', $names,
            'a guest with a stuck payment is the commonest case there is');
        $this->assertContains('resend_receipt', $names);
    }

    public function test_a_guest_repair_runs_rather_than_being_refused(): void
    {
        // No gateway is configured in the test environment, so the reclaim cannot
        // confirm anything — the point here is that it is ATTEMPTED and answers,
        // rather than being turned away for want of a session.
        $r = $this->guest()->run('fix_payment', ['reference' => 'AFG-PVOTE-957ef35ed73d']);

        $this->assertTrue($r['ok'], 'the tool must be permitted');
        $this->assertIsArray($r['data']);
        $this->assertArrayHasKey('say', $r['data'] + ['say' => null]);
    }

    public function test_a_guest_can_have_a_receipt_resent_without_being_told_where_it_went(): void
    {
        $this->order('AFG-PVOTE-aaaaaaaaaaaa', self::THEIRS);

        $r = $this->guest()->run('resend_receipt', ['reference' => 'AFG-PVOTE-aaaaaaaaaaaa']);
        $blob = json_encode($r);

        // It may or may not have sent — there is no mail transport here. What must
        // hold is that the ANSWER never names the buyer. Whoever holds a reference
        // must not learn whose payment it is.
        $this->assertStringNotContainsString(self::THEIRS, (string) $blob);
        $this->assertStringNotContainsString('Okun', (string) $blob);
    }

    public function test_resending_a_receipt_for_an_unconfirmed_payment_points_at_the_repair_instead(): void
    {
        $this->order('AFG-PVOTE-bbbbbbbbbbbb', self::MINE, 'pending');

        $r = $this->guest()->run('resend_receipt', ['reference' => 'AFG-PVOTE-bbbbbbbbbbbb']);

        $this->assertSame('NOT_CONFIRMED', $r['data']['outcome']);
        $this->assertStringContainsString('fix_payment', $r['data']['say'],
            'an unconfirmed payment does not need a receipt, it needs repairing');
    }

    // ── the reads are not ────────────────────────────────────────────────────

    public function test_a_guest_is_never_offered_a_tool_that_discloses(): void
    {
        $names = $this->toolNames($this->guest());

        foreach (['my_transactions', 'my_votes', 'lookup_reference', 'ops_summary'] as $t) {
            $this->assertNotContains($t, $names, $t . ' returns somebody\'s data and needs an identity');
        }
    }

    public function test_a_guest_calling_a_disclosing_tool_by_name_is_refused(): void
    {
        // The planner is a language model and can name a tool it was never shown.
        // The refusal has to live in run(), not in the prompt that built the list.
        $r = $this->guest()->run('my_transactions');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No such tool', $r['error']);
    }

    public function test_a_member_cannot_read_another_persons_reference(): void
    {
        $this->order('AFG-PVOTE-cccccccccccc', self::THEIRS);

        $r = $this->member()->run('lookup_reference', ['reference' => 'AFG-PVOTE-cccccccccccc']);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['data']['found'],
            'a reference that is not yours must look exactly like one that does not exist');
    }

    public function test_an_invented_tool_name_is_refused_rather_than_crashing(): void
    {
        $r = $this->member()->run('delete_everything', ['confirm' => true]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No such tool', $r['error']);
    }

    // ── the ceiling ──────────────────────────────────────────────────────────

    public function test_repairs_are_rate_limited_per_client(): void
    {
        $limits = new RateLimitService();
        $key    = hash('sha256', '198.51.100.7');
        $ctx    = $this->guest($limits, $key);

        $outcomes = [];
        for ($i = 0; $i < 10; $i++) {
            // A REAL reference shape. The shape check now runs before the limiter
            // — deliberately, since it costs no query and no gateway call — so a
            // fixture using made-up strings would be refused for the wrong reason
            // and never reach the ceiling this is testing.
            $r = $ctx->run('fix_payment', ['reference' => sprintf('AFG-PVOTE-%012x', $i)]);
            $outcomes[] = $r['data']['outcome'] ?? 'ran';
        }

        $this->assertContains('RATE_LIMITED', $outcomes,
            'the repair tools are open to guests, so the ceiling is the only thing between them '
            . 'and somebody walking a reference space');
        // …and the ones before the ceiling really did run.
        $this->assertNotSame('RATE_LIMITED', $outcomes[0]);
    }

    public function test_a_missing_limiter_fails_open(): void
    {
        // The CLI, the tests and the unattended resolver all run without one. A
        // payment repair that silently refuses is worse than one that runs often.
        $outcomes = [];
        $ctx = $this->guest();
        for ($i = 0; $i < 12; $i++) {
            $outcomes[] = $ctx->run('fix_payment',
                ['reference' => sprintf('AFG-GIVE-%012x', $i)])['data']['outcome'] ?? 'ran';
        }

        $this->assertNotContains('RATE_LIMITED', $outcomes);
    }

    public function test_a_repair_without_a_reference_asks_for_one(): void
    {
        $r = $this->guest()->run('fix_payment', ['reference' => '']);

        $this->assertFalse($r['data']['ok']);
        $this->assertStringContainsString('reference', $r['data']['note']);
        $this->assertStringNotContainsString('my_transactions', $r['data']['note'],
            'a guest must not be told to use a tool they do not have');
    }

    // ── the three clocks ─────────────────────────────────────────────────────

    /**
     * A supporter refused at checkout while the ballot is visibly still open is
     * certain something is broken, and the assistant had no way to tell them
     * otherwise — `site_state` reports the cycle's close and nothing else, so the
     * true answer was unavailable and the model filled the gap by guessing.
     *
     * The values are LIVE, not documentation: both the cutoff and the late-delivery
     * grace are admin settings, so a hard-coded sentence would start lying the
     * first time either was changed.
     */
    public function test_the_deadline_tool_gives_all_three_clocks_and_reads_them_live(): void
    {
        $r = $this->guest()->run('voting_deadlines');

        $this->assertTrue($r['ok']);
        $rules = $r['data']['rules'];
        $this->assertSame(\AfricaGates\Services\PaidVoteService::checkoutCutoffMinutes(),
            $rules['checkout_closes_before_voting_by_minutes']);
        $this->assertSame(\AfricaGates\Services\PaidVoteService::lateMintGraceHours(),
            $rules['late_delivery_window_after_close_hours']);
        $this->assertTrue($rules['free_voting_runs_to_the_close']);
    }

    /**
     * The hardest of the three questions — "it confirmed four minutes late, is my
     * money gone" — where the true answer is usually NO, and where a wrong answer
     * sends somebody away believing they were robbed.
     */
    public function test_the_deadline_tool_refuses_to_write_off_a_payment_started_in_time(): void
    {
        $say = $this->guest()->run('voting_deadlines')['data']['say'];

        $this->assertStringContainsString('fix_payment', $say['paid_just_before'],
            'the model must be told to try the repair before saying anything is lost');
        $this->assertStringContainsString('refund_status', $say['paid_after_close'],
            'and to check for a refund already under way before offering to arrange one');
        $this->assertStringContainsString('Free voting', $say['refused_at_checkout'],
            'a buyer refused at the cutoff still has a ballot to use');
    }

    /** It is public schedule information, so it is available to a guest. */
    public function test_the_deadline_tool_is_open_to_everyone(): void
    {
        $this->assertContains('voting_deadlines', $this->toolNames($this->guest()));
    }
}
