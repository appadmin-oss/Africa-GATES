<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\VoteService;

/**
 * Characterization tests — pin the CURRENT correct behavior of the vote core
 * before any Phase 0 change touches it. These should pass against the existing
 * implementation; if a later task breaks one, that is a regression signal.
 */
class VoteServiceTest extends TestCase
{
    private function seedNominee(int $id = 1, int $cat = 10, string $cc = 'NG', string $cycleStatus = 'voting'): void
    {
        // A nominee is only votable inside an OPEN cycle, so seed the
        // category → cycle chain the vote gate now requires.
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'),
            'status' => $cycleStatus,
            'voting_close' => Carbon::now()->addDays(7)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => $cat, 'cycle_id' => 1, 'slug' => 'cat-' . $cat, 'title' => 'Category',
        ]);
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => $cat, 'name' => 'Nominee', 'country_code' => $cc,
            'status' => 'approved', 'vote_count' => 0,
        ]);
    }

    private function seedOtp(string $email, string $code, string $purpose = 'vote', int $minutes = 10): void
    {
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => hash('sha256', strtolower($email)),
            'token_hash' => hash('sha256', $code),
            'purpose'    => $purpose, 'nominee_id' => 1, 'award_id' => 0,
            'attempts'   => 0, 'is_used' => 0,
            'expires_at' => Carbon::now()->addMinutes($minutes)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    public function test_happy_path_records_vote_and_consumes_otp(): void
    {
        $this->seedNominee();
        $this->seedOtp('v@x.io', '123456');

        $r = (new VoteService())->castVote('v@x.io', '123456', 1, 0, '1.2.3.4');

        $this->assertTrue($r['success']);
        $this->assertSame(1, DB::table('gates_votes')->count());
        $this->assertSame(1, (int) DB::table('gates_otp_tokens')->value('is_used'));
        $this->assertSame(1, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
    }

    public function test_standard_vote_increments_organic_vote_count(): void
    {
        // The CPI community signal lives in organic_vote_count — every organic OTP
        // vote must bump it (paid bonus votes must NOT).
        $this->seedNominee();
        $this->seedOtp('v@x.io', '123456');

        (new VoteService())->castVote('v@x.io', '123456', 1, 0, '1.2.3.4');

        $this->assertSame(1, (int) DB::table('gates_nominees')->where('id', 1)->value('organic_vote_count'));
    }

    public function test_wrong_code_does_not_consume_token(): void
    {
        $this->seedNominee();
        $this->seedOtp('v@x.io', '123456');

        $r = (new VoteService())->castVote('v@x.io', '000000', 1, 0, '');

        $this->assertFalse($r['success']);
        $this->assertSame('INVALID_OTP', $r['code']);
        $this->assertSame(0, (int) DB::table('gates_otp_tokens')->value('is_used'));
    }

    public function test_expired_token_rejected(): void
    {
        $this->seedNominee();
        $this->seedOtp('v@x.io', '123456', 'vote', -1); // already expired

        $r = (new VoteService())->castVote('v@x.io', '123456', 1, 0, '');

        $this->assertFalse($r['success']);
        $this->assertSame('INVALID_OTP', $r['code']);
    }

    public function test_duplicate_vote_blocked(): void
    {
        $this->seedNominee();
        $this->seedOtp('v@x.io', '123456');
        (new VoteService())->castVote('v@x.io', '123456', 1, 0, '');

        $this->seedOtp('v@x.io', '654321'); // fresh code, same email+category
        $r = (new VoteService())->castVote('v@x.io', '654321', 1, 0, '');

        $this->assertFalse($r['success']);
        $this->assertSame('ALREADY_VOTED', $r['code']);
    }

    public function test_vote_stores_nominee_country_not_voter_country(): void
    {
        $this->seedNominee(1, 10, 'GH'); // nominee is from Ghana
        $this->seedOtp('v@x.io', '123456');

        (new VoteService())->castVote('v@x.io', '123456', 1, 0, '1.2.3.4');

        // The column now honestly reflects the nominee's country.
        $this->assertSame('GH', DB::table('gates_votes')->value('nominee_country'));
    }

    public function test_attempt_cap_burns_token_after_five(): void
    {
        $this->seedNominee();
        $this->seedOtp('v@x.io', '123456');
        $svc = new VoteService();

        for ($i = 0; $i < 5; $i++) {
            $svc->castVote('v@x.io', '000000', 1, 0, ''); // five wrong guesses
        }
        $r = $svc->castVote('v@x.io', '123456', 1, 0, ''); // sixth attempt, correct code

        $this->assertFalse($r['success']);
        $this->assertSame('TOO_MANY_ATTEMPTS', $r['code']);
    }

    public function test_device_hash_is_persisted(): void
    {
        // The device fingerprint must reach the vote row so fraud signals can use it.
        $this->seedNominee();
        $this->seedOtp('v@x.io', '123456');

        (new VoteService())->castVote('v@x.io', '123456', 1, 0, '1.2.3.4', 'devhash123');

        $this->assertSame('devhash123', DB::table('gates_votes')->value('device_hash'));
    }

    public function test_idempotent_replay_returns_original_not_error(): void
    {
        $this->seedNominee();
        $this->seedOtp('v@x.io', '123456');
        $svc = new VoteService();

        $r1 = $svc->castVote('v@x.io', '123456', 1, 0, '1.2.3.4', null, 'idem-1');
        $this->assertTrue($r1['success']);
        $this->assertSame(1, DB::table('gates_votes')->count());

        // A retry with the SAME key — even though the code is now consumed — returns
        // the original success, not INVALID_OTP / ALREADY_VOTED, and adds no vote.
        $r2 = $svc->castVote('v@x.io', '999999', 1, 0, '1.2.3.4', null, 'idem-1');
        $this->assertTrue($r2['success']);
        $this->assertSame('VOTE_CAST', $r2['code']);
        $this->assertSame(1, DB::table('gates_votes')->count());
    }

    public function test_two_voters_may_share_an_idempotency_key(): void
    {
        // A shared/buggy client key must NOT let one voter's request block another's.
        // Idempotency is scoped per-voter, so two different people can carry the same key.
        $this->seedNominee();
        $this->seedOtp('a@x.io', '111111');
        $this->seedOtp('b@x.io', '222222');
        $svc = new VoteService();

        $ra = $svc->castVote('a@x.io', '111111', 1, 0, '1.1.1.1', null, 'shared-key');
        $rb = $svc->castVote('b@x.io', '222222', 1, 0, '2.2.2.2', null, 'shared-key');

        $this->assertTrue($ra['success']);
        $this->assertTrue($rb['success'], 'second voter with the same idempotency key must still be able to vote');
        $this->assertSame(2, DB::table('gates_votes')->count());
    }

    public function test_vote_blocked_when_cycle_not_voting(): void
    {
        // Nominee is approved, but its cycle is in 'judging' — voting is closed.
        $this->seedNominee(1, 10, 'NG', 'judging');
        $this->seedOtp('v@x.io', '123456');

        $r = (new VoteService())->castVote('v@x.io', '123456', 1, 0, '');

        $this->assertFalse($r['success']);
        $this->assertSame('VOTING_CLOSED', $r['code']);
        $this->assertSame(0, DB::table('gates_votes')->count());
    }

    public function test_voter_name_and_phone_are_stored(): void
    {
        // Voting now captures the voter's full name + phone alongside the hashed email.
        $this->seedNominee();
        $this->seedOtp('v@x.io', '123456');

        (new VoteService())->castVote('v@x.io', '123456', 1, 0, '1.2.3.4', null, null, 'Ada Obi', '+234 801 234 5678');

        $row = DB::table('gates_votes')->first();
        $this->assertSame('Ada Obi', $row->voter_name);
        $this->assertSame('+234 801 234 5678', $row->voter_phone);
    }
}
