<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\VoteRecoveryService as Recover;
use AfricaGates\Services\NomineeScoringService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Giving back votes the platform dropped — without opening a way to add votes it
 * never had.
 *
 * Every test here is an attempt to get a vote onto the tally that should not be
 * there. The controls are only worth their weight if each one, removed, lets one
 * of these through.
 */
class VoteRecoveryTest extends TestCase
{
    private const ADMIN_A = 11;   // prepares
    private const ADMIN_B = 22;   // approves

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'judging',
            'voting_open'  => Carbon::now()->subDays(20)->toDateTimeString(),
            'voting_close' => Carbon::now()->subDays(2)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 1, 'name' => 'Ada',  'status' => 'approved', 'vote_count' => 100, 'organic_vote_count' => 100],
            ['id' => 2, 'category_id' => 1, 'name' => 'Bala', 'status' => 'approved', 'vote_count' => 100, 'organic_vote_count' => 100],
        ]);
    }

    /** A vote attempt the platform recorded. $state is what happened to the code. */
    private function attempt(string $who, int $nomineeId = 1, string $state = 'failed', array $over = []): int
    {
        return (int) DB::table('gates_otp_tokens')->insertGetId(array_merge([
            'email_hash' => hash('sha256', $who),
            'token_hash' => hash('sha256', '000000'),
            'purpose'    => 'vote',
            'nominee_id' => $nomineeId,
            'award_id'   => 1,
            'attempts'   => 0,
            'is_used'    => 0,
            'delivery_state' => $state,
            'delivery_error' => $state === 'failed' ? 'SMTP 421 relay unavailable' : null,
            'expires_at' => Carbon::now()->subDays(5)->toDateTimeString(),
            'created_at' => Carbon::now()->subDays(6)->toDateTimeString(),
        ], $over));
    }

    private function window(): array
    {
        return [Carbon::now()->subDays(7)->toDateTimeString(), Carbon::now()->subDays(5)->toDateTimeString()];
    }

    private function draft(string $note = 'Brevo relay rejected every send for two hours.'): array
    {
        [$f, $t] = $this->window();
        return Recover::open(1, $f, $t, $note, self::ADMIN_A);
    }

    private function fullyApprove(int $batchId): array
    {
        Recover::submit($batchId, self::ADMIN_A);
        $a = Recover::approve($batchId, self::ADMIN_B);
        $this->assertTrue($a['ok'], (string) ($a['message'] ?? ''));
        return Recover::apply($batchId, self::ADMIN_B);
    }

    // ── Who is eligible ──────────────────────────────────────────────────────

    /**
     * THE WHOLE BASIS OF THE FEATURE. Only attempts whose code WE failed to deliver.
     * Somebody who got their code and did not use it made a choice, and their choice
     * is not ours to overturn.
     */
    public function test_only_undelivered_codes_are_candidates(): void
    {
        $this->attempt('failed@x.io',  1, 'failed');
        $this->attempt('gotit@x.io',   1, 'sent');
        $this->attempt('legacy@x.io',  1, 'unknown');

        [$f, $t] = $this->window();
        $c = Recover::candidates(1, $f, $t);

        $this->assertCount(1, $c);
        $this->assertSame(hash('sha256', 'failed@x.io'), (string) $c[0]->email_hash);
    }

    /**
     * Rows written before the delivery columns existed say 'unknown' forever, and
     * are never recoverable. We do not know we failed those people, and being unable
     * to rule it out is not evidence. This is a deliberate cost, not an oversight.
     */
    public function test_history_we_did_not_record_is_not_recoverable(): void
    {
        $this->attempt('legacy@x.io', 1, 'unknown');

        $r = $this->draft();
        $this->assertFalse($r['ok']);
        $this->assertSame('NO_CANDIDATES', $r['code']);
    }

    /** A code already redeemed is not a dropped vote. */
    public function test_a_used_code_is_not_a_candidate(): void
    {
        $this->attempt('used@x.io', 1, 'failed', ['is_used' => 1]);

        [$f, $t] = $this->window();
        $this->assertCount(0, Recover::candidates(1, $f, $t));
    }

    /** Attempts outside the declared window belong to a different incident. */
    public function test_the_window_bounds_the_claim(): void
    {
        $this->attempt('inside@x.io', 1, 'failed');
        $this->attempt('outside@x.io', 1, 'failed', [
            'created_at' => Carbon::now()->subDays(19)->toDateTimeString(),
        ]);

        [$f, $t] = $this->window();
        $this->assertCount(1, Recover::candidates(1, $f, $t));
    }

    // ── While the ballot is open, this is the wrong tool ─────────────────────

    /**
     * A vote the person casts themselves is better in every way that matters. If
     * re-sending can still help them, minting on their behalf must not be offered.
     */
    public function test_recovery_refuses_while_voting_is_still_open(): void
    {
        DB::table('gates_award_cycles')->where('id', 1)->update([
            'status' => 'voting', 'voting_close' => Carbon::now()->addDays(3)->toDateTimeString(),
        ]);
        $this->attempt('failed@x.io');

        $r = $this->draft();
        $this->assertFalse($r['ok']);
        $this->assertSame('STILL_OPEN', $r['code']);
        $this->assertStringContainsString('re-send', $r['message']);
        $this->assertTrue(Recover::resendable(1));
    }

    // ── What a recovered vote is ─────────────────────────────────────────────

    /**
     * An ordinary organic vote: it moves the public tally AND the CPI community
     * signal, because the person asked for it and only our outage stopped it.
     */
    public function test_a_recovered_vote_counts_exactly_like_a_real_one(): void
    {
        // Ada behind on verified support, so a change in her share is visible. (At
        // the top of the cohort she is already normalised to 1.0 and three more
        // votes move nothing — true of ordinary votes too, which is the point.)
        DB::table('gates_nominees')->where('id', 1)->update(['vote_count' => 80, 'organic_vote_count' => 80]);
        $this->attempt('a@x.io'); $this->attempt('b@x.io'); $this->attempt('c@x.io');

        $before = (new NomineeScoringService())->scoreCategory(1);
        $r = $this->fullyApprove($this->draft()['batch_id']);
        $after = (new NomineeScoringService())->scoreCategory(1);

        $this->assertSame(3, $r['applied']);
        $n = DB::table('gates_nominees')->where('id', 1)->first();
        $this->assertSame(83, (int) $n->vote_count);
        $this->assertSame(83, (int) $n->organic_vote_count, 'the CPI community signal, not a side bucket');
        $this->assertGreaterThan($before[1]['cpi_score'], $after[1]['cpi_score']);
    }

    /** And it carries its own provenance in the ledger, not in a side table. */
    public function test_each_recovered_vote_points_at_the_attempt_that_proves_it(): void
    {
        $tok = $this->attempt('a@x.io');
        $b = $this->draft();
        $this->fullyApprove($b['batch_id']);

        $v = DB::table('gates_votes')->first();
        $this->assertSame($tok, (int) $v->otp_token_id, 'the token is the evidence and travels with the vote');
        $this->assertSame((int) $b['batch_id'], (int) $v->recovery_batch_id);
        $this->assertSame('standard', (string) $v->vote_type);
        $this->assertSame(0, (int) DB::table('gates_votes')->whereNull('recovery_batch_id')->count(),
            'and an ordinary vote carries no batch, so the two are always separable');
    }

    /** The token is burned, so the same dropped attempt cannot be recovered twice. */
    public function test_a_recovered_attempt_cannot_be_recovered_again(): void
    {
        $tok = $this->attempt('a@x.io');
        $this->fullyApprove($this->draft()['batch_id']);

        $this->assertSame(1, (int) DB::table('gates_otp_tokens')->where('id', $tok)->value('is_used'));
        [$f, $t] = $this->window();
        $this->assertCount(0, Recover::candidates(1, $f, $t));
    }

    /** Two overlapping batches cannot both claim the same attempt. */
    public function test_two_batches_cannot_claim_the_same_attempt(): void
    {
        $this->attempt('a@x.io');

        $b1 = $this->draft();
        $b2 = $this->draft();

        $this->assertSame(1, $b1['candidates']);
        $this->assertSame(0, $b2['candidates'], 'the second batch finds the attempt already spoken for');
    }

    // ── Two people ───────────────────────────────────────────────────────────

    public function test_the_preparer_cannot_approve_their_own_batch(): void
    {
        $this->attempt('a@x.io');
        $b = $this->draft();
        Recover::submit($b['batch_id'], self::ADMIN_A);

        $r = Recover::approve($b['batch_id'], self::ADMIN_A);
        $this->assertFalse($r['ok']);
        $this->assertSame('SELF_APPROVAL', $r['code']);
        $this->assertSame(0, (int) DB::table('gates_votes')->count());
    }

    public function test_an_unapproved_batch_writes_nothing(): void
    {
        $this->attempt('a@x.io');
        $b = $this->draft();
        Recover::submit($b['batch_id'], self::ADMIN_A);

        $r = Recover::apply($b['batch_id'], self::ADMIN_B);
        $this->assertFalse($r['ok']);
        $this->assertSame('NOT_APPROVED', $r['code']);
        $this->assertSame(0, (int) DB::table('gates_votes')->count());
    }

    public function test_an_incident_must_be_described(): void
    {
        $this->attempt('a@x.io');
        $r = $this->draft('   ');
        $this->assertFalse($r['ok']);
        $this->assertSame('NO_INCIDENT', $r['code']);
    }

    // ── The fraud surface the derivation does not close ──────────────────────

    /**
     * A farm looks like a crowd one row at a time. During an outage every send
     * fails, including sends to addresses somebody typed in bad faith, so the one
     * thing the derivation cannot see — that a hundred "supporters" share one
     * network — has to be checked separately.
     */
    public function test_a_single_source_flooding_the_window_blocks_approval(): void
    {
        for ($i = 0; $i < 8; $i++) $this->attempt("bot$i@x.io");

        // The ballot recorded where those attempts came from.
        for ($i = 0; $i < 8; $i++) {
            DB::table('gates_funnel_events')->insert([
                'session_id' => "s$i", 'step' => 'otp_request', 'nominee_id' => 1, 'award_id' => 1,
                'ip_hash' => hash('sha256', 'one-and-the-same'),
                'created_at' => Carbon::now()->subDays(6)->toDateTimeString(),
            ]);
        }

        $b = $this->draft();
        Recover::submit($b['batch_id'], self::ADMIN_A);
        $r = Recover::approve($b['batch_id'], self::ADMIN_B);

        $this->assertFalse($r['ok']);
        $this->assertSame('BLOCKED', $r['code']);
        $this->assertStringContainsString('recovering a farm',
            implode(' ', array_column($r['findings'], 'text')));
    }

    /** A repair must never be a large fraction of a nominee's support. */
    public function test_a_batch_over_the_cap_is_blocked(): void
    {
        DB::table('gates_nominees')->where('id', 1)->update(['vote_count' => 20, 'organic_vote_count' => 20]);
        for ($i = 0; $i < 12; $i++) $this->attempt("v$i@x.io");   // cap at organic 20 is 10

        $b = $this->draft();
        Recover::submit($b['batch_id'], self::ADMIN_A);
        $r = Recover::approve($b['batch_id'], self::ADMIN_B);

        $this->assertFalse($r['ok']);
        $this->assertSame('BLOCKED', $r['code']);
        $this->assertStringContainsString('over the cap', implode(' ', array_column($r['findings'], 'text')));
        $this->assertSame(0, (int) DB::table('gates_votes')->count());
    }

    // ── The world moves between approval and application ─────────────────────

    public function test_somebody_who_got_through_later_is_not_counted_twice(): void
    {
        $this->attempt('came.back@x.io');
        $this->attempt('never.did@x.io');

        $b = $this->draft();
        Recover::submit($b['batch_id'], self::ADMIN_A);
        Recover::approve($b['batch_id'], self::ADMIN_B);

        // They retried an hour later and it worked.
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 1,
            'voter_email_hash' => hash('sha256', 'came.back@x.io'),
            'voted_at' => Carbon::now()->subDays(5)->toDateTimeString(),
        ]);

        $r = Recover::apply($b['batch_id'], self::ADMIN_B);
        $this->assertSame(1, $r['applied']);
        $this->assertSame(1, $r['rejected']);
    }

    public function test_a_nominee_merged_away_after_approval_is_not_credited(): void
    {
        $this->attempt('a@x.io', 1);
        $this->attempt('b@x.io', 2);

        $b = $this->draft();
        Recover::submit($b['batch_id'], self::ADMIN_A);
        Recover::approve($b['batch_id'], self::ADMIN_B);

        DB::table('gates_nominees')->where('id', 2)->update(['merged_into' => 1]);

        $r = Recover::apply($b['batch_id'], self::ADMIN_B);
        $this->assertSame(1, $r['applied']);
        $this->assertSame(1, $r['rejected']);
    }

    /** If the token stops saying we failed, the licence to recover it is gone. */
    public function test_a_token_no_longer_marked_failed_is_refused_at_apply_time(): void
    {
        $tok = $this->attempt('a@x.io');
        $b = $this->draft();
        Recover::submit($b['batch_id'], self::ADMIN_A);
        Recover::approve($b['batch_id'], self::ADMIN_B);

        DB::table('gates_otp_tokens')->where('id', $tok)->update(['delivery_state' => 'sent']);

        $r = Recover::apply($b['batch_id'], self::ADMIN_B);
        $this->assertSame(0, $r['applied']);
        $this->assertSame(1, $r['rejected']);
    }

    // ── Reversal and disclosure ──────────────────────────────────────────────

    public function test_voiding_removes_the_votes_and_keeps_the_record(): void
    {
        $this->attempt('a@x.io'); $this->attempt('b@x.io');
        $b = $this->draft();
        $this->fullyApprove($b['batch_id']);
        $this->assertSame(102, (int) DB::table('gates_nominees')->where('id', 1)->value('organic_vote_count'));

        $r = Recover::void($b['batch_id'], self::ADMIN_B, 'The outage window was wrong by a day.');

        $this->assertSame(2, $r['reversed']);
        $this->assertSame(100, (int) DB::table('gates_nominees')->where('id', 1)->value('organic_vote_count'));
        $this->assertSame(100, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
        $this->assertSame(0, (int) DB::table('gates_votes')->count());
        $this->assertSame('voided', (string) Recover::batch($b['batch_id'])->status);
        $this->assertSame(2, (int) DB::table('gates_vote_recovery_rows')
            ->where('batch_id', $b['batch_id'])->where('status', 'voided')->count());
    }

    public function test_a_reversal_needs_a_reason(): void
    {
        $this->attempt('a@x.io');
        $b = $this->draft();
        $this->fullyApprove($b['batch_id']);

        $r = Recover::void($b['batch_id'], self::ADMIN_B, '  ');
        $this->assertFalse($r['ok']);
        $this->assertSame('NO_REASON', $r['code']);
    }

    /**
     * Disclosure is the control that cannot be worked around. Everything else here
     * yields to a determined insider; being unable to do it quietly does not.
     */
    public function test_every_recovered_vote_is_publicly_attributable(): void
    {
        $this->attempt('a@x.io'); $this->attempt('b@x.io');
        $b = $this->draft('Brevo relay rejected every send between 14:00 and 16:00.');
        $this->fullyApprove($b['batch_id']);

        $d = Recover::disclosureFor(1);
        $this->assertSame(2, $d['total']);
        $this->assertSame($b['reference'], $d['batches'][0]['reference']);
        $this->assertStringContainsString('Brevo relay rejected', $d['batches'][0]['incident']);
    }

    public function test_a_voided_batch_stops_being_disclosed_as_support(): void
    {
        $this->attempt('a@x.io');
        $b = $this->draft();
        $this->fullyApprove($b['batch_id']);
        Recover::void($b['batch_id'], self::ADMIN_B, 'withdrawn');

        $this->assertSame(0, Recover::disclosureFor(1)['total']);
    }

    // ── The number that should be falling ────────────────────────────────────

    public function test_delivery_health_reports_the_failure_rate(): void
    {
        DB::table('gates_otp_tokens')->truncate();
        for ($i = 0; $i < 9; $i++) $this->attempt("ok$i@x.io", 1, 'sent',
            ['created_at' => Carbon::now()->subHours(2)->toDateTimeString()]);
        $this->attempt('bad@x.io', 1, 'failed', ['created_at' => Carbon::now()->subHours(2)->toDateTimeString()]);

        $h = Recover::deliveryHealth(7);
        $this->assertSame(9, $h['sent']);
        $this->assertSame(1, $h['failed']);
        $this->assertSame(10.0, $h['pct']);
    }

    public function test_the_reference_round_trips(): void
    {
        $this->attempt('a@x.io');
        $b = $this->draft();

        $this->assertMatchesRegularExpression('/^AGR-[0-9A-HJKMNP-TV-Z]{6}-.$/', $b['reference']);
        $this->assertSame((int) $b['batch_id'], \AfricaGates\Support\Reference::parseRecoveryId($b['reference']));
        $this->assertNull(\AfricaGates\Support\Reference::parseRecoveryId('AGR-000000-0'));
    }
}
