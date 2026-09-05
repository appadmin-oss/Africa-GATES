<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\BallotGuard;
use AfricaGates\Services\PhaseError;
use AfricaGates\Services\VoteService;

/**
 * The one gate, and the invariant that matters most: voting closes on schedule
 * even when no scheduler has ever run. Every test here leaves the stored
 * `gates_award_cycles.status` column at 'voting' — exactly the production state
 * that kept voting open indefinitely — and asserts the write is refused anyway.
 */
class BallotGuardTest extends TestCase
{
    /** A cycle whose column says 'voting' but whose window has CLOSED. */
    private function seedStaleVotingCycle(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'),
            'status'       => 'voting',                                       // never updated
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-2 days')),       // closed
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 10, 'cycle_id' => 1, 'slug' => 'c', 'title' => 'Category']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 1, 'category_id' => 10, 'name' => 'Nominee', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
    }

    private function seedOpenVotingCycle(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'),
            'status'       => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 10, 'cycle_id' => 1, 'slug' => 'c', 'title' => 'Category']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 1, 'category_id' => 10, 'name' => 'Nominee', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
    }

    private function seedOtp(string $email, string $code): void
    {
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => hash('sha256', strtolower($email)),
            'token_hash' => hash('sha256', $code),
            'purpose' => 'vote', 'nominee_id' => 1, 'award_id' => 0,
            'is_used' => 0, 'attempts' => 0,
            'expires_at' => Carbon::now()->addMinutes(10)->toDateTimeString(),
        ]);
    }

    public function test_a_stale_voting_column_does_not_keep_the_ballot_open(): void
    {
        $this->seedStaleVotingCycle();

        $this->assertFalse(BallotGuard::isVotable(10), 'the close date must bind regardless of the column');
    }

    public function test_the_vote_transaction_refuses_after_the_close_date(): void
    {
        $this->seedStaleVotingCycle();
        $this->seedOtp('v@x.io', '123456');

        $r = (new VoteService())->castVote('v@x.io', '123456', 1, 0, '1.2.3.4');

        $this->assertFalse($r['success']);
        $this->assertSame('VOTING_CLOSED', $r['code']);
        $this->assertSame(0, DB::table('gates_votes')->count());
        // The OTP must survive a refusal — a closed ballot is not the voter's fault.
        $this->assertSame(0, (int) DB::table('gates_otp_tokens')->value('is_used'));
    }

    public function test_the_vote_transaction_accepts_inside_the_window(): void
    {
        $this->seedOpenVotingCycle();
        $this->seedOtp('v@x.io', '123456');

        $r = (new VoteService())->castVote('v@x.io', '123456', 1, 0, '1.2.3.4');

        $this->assertTrue($r['success'], (string) ($r['message'] ?? ''));
        $this->assertSame(1, DB::table('gates_votes')->count());
    }

    public function test_a_divergence_is_recorded_for_the_operator(): void
    {
        $this->seedStaleVotingCycle();

        BallotGuard::isVotable(10);

        $drift = DB::table('gates_phase_drift')->where('cycle_id', 1)->first();
        $this->assertNotNull($drift, 'a mis-phased live cycle must surface, not fail silently');
        $this->assertSame('judging', (string) $drift->computed_phase);
        $this->assertSame('voting', (string) $drift->stored_status);
        $this->assertSame(1, (int) $drift->would_allow, 'the column would have allowed it');
        $this->assertSame(0, (int) $drift->phase_allows, 'the computed phase did not');
        $this->assertSame('strict', (string) $drift->mode);
    }

    public function test_no_divergence_is_recorded_when_the_two_agree(): void
    {
        $this->seedOpenVotingCycle();

        $this->assertTrue(BallotGuard::isVotable(10));
        $this->assertSame(0, DB::table('gates_phase_drift')->count(), 'agreement is not an event');
    }

    public function test_shadow_mode_allows_the_write_but_still_records_the_divergence(): void
    {
        // The migration safety net: observe which live cycles are mis-phased
        // before enforcement starts refusing real traffic.
        $this->seedStaleVotingCycle();
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'phase_enforcement'], ['value' => 'shadow']);

        $this->assertTrue(BallotGuard::isVotable(10), 'shadow mode defers to the stored column');
        $drift = DB::table('gates_phase_drift')->where('cycle_id', 1)->first();
        $this->assertNotNull($drift);
        $this->assertSame('shadow', (string) $drift->mode);
    }

    public function test_enforcement_defaults_to_strict(): void
    {
        $this->assertSame('strict', BallotGuard::mode(), 'the safe default must not depend on a settings row existing');

        DB::table('gates_settings')->updateOrInsert(['key_name' => 'phase_enforcement'], ['value' => 'nonsense']);
        $this->assertSame('strict', BallotGuard::mode(), 'an unrecognised value must not disable enforcement');
    }

    public function test_the_guard_fails_closed_on_an_orphaned_category(): void
    {
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 77, 'cycle_id' => 9999, 'slug' => 'x', 'title' => 'Orphan']);

        $this->assertFalse(BallotGuard::isVotable(77));
        $this->assertFalse(BallotGuard::isVotable(12345), 'a category that does not exist is not votable');
    }

    public function test_refusal_codes_distinguish_not_yet_open_from_closed(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 2, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'upcoming',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('+10 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+40 days')),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 20, 'cycle_id' => 2, 'slug' => 'f', 'title' => 'Future']);

        try {
            BallotGuard::assertVotable(20);
            $this->fail('a future window must refuse');
        } catch (PhaseError $e) {
            $this->assertSame('VOTING_NOT_OPEN_YET', $e->errorCode);
        }

        $this->seedStaleVotingCycle();
        try {
            BallotGuard::assertVotable(10);
            $this->fail('a past window must refuse');
        } catch (PhaseError $e) {
            $this->assertSame('VOTING_CLOSED', $e->errorCode);
        }
    }

    public function test_an_archived_cycle_is_never_votable(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 3, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'archived',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 30, 'cycle_id' => 3, 'slug' => 'a', 'title' => 'Archived']);

        try {
            BallotGuard::assertVotable(30);
            $this->fail('archived must refuse even inside a voting window');
        } catch (PhaseError $e) {
            $this->assertSame('CYCLE_ARCHIVED', $e->errorCode);
        }
    }

    public function test_an_explicit_now_lets_the_gate_be_evaluated_at_any_instant(): void
    {
        // This is what the old design could not do at all: ask the question
        // "would this vote have been allowed last Tuesday?"
        $this->seedStaleVotingCycle();

        $duringWindow = Carbon::parse(date('Y-m-d H:i:s', strtotime('-10 days')));
        $this->assertTrue(BallotGuard::isVotable(10, $duringWindow));
        $this->assertFalse(BallotGuard::isVotable(10, Carbon::now()));
    }

    public function test_the_programme_resolver_prefers_an_in_flight_cycle(): void
    {
        DB::table('gates_award_cycles')->insert([
            ['id' => 40, 'programme_id' => 7, 'year' => (int) date('Y') + 1, 'status' => 'upcoming'],
            ['id' => 41, 'programme_id' => 7, 'year' => (int) date('Y'),     'status' => 'nominations'],
        ]);

        $cycle = BallotGuard::currentCycleForProgramme(7);

        $this->assertNotNull($cycle);
        $this->assertSame(41, (int) $cycle->id, 'an in-flight cycle beats a newer idle one');
    }
}
