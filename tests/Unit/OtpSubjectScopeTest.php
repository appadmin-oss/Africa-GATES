<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\MergeService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * gates_otp_tokens.nominee_id does not always hold a nominee id.
 *
 * Voting reached this table first and the columns were named for it. Later flows
 * reused the row shape and put their own subject in the same column: judge sign-in
 * stores a gates_judges id, account sign-in a gates_users id. Three independent
 * auto-increment sequences of small integers means "judge 7" and "nominee 7"
 * collide as a matter of course, not as an edge case.
 *
 * So an unscoped `UPDATE gates_otp_tokens SET nominee_id = survivor WHERE
 * nominee_id = merged` rewrote live sign-in tokens belonging to people who had
 * nothing to do with the merge, and wrote those phantom moves into the merge
 * journal — the record used to review and undo a merge in the first place.
 *
 * Nothing reads the column back on those paths today, so nothing broke; what was
 * damaged was the audit trail, quietly, on every merge.
 */
class OtpSubjectScopeTest extends TestCase
{
    /** Two nominees to merge, plus tokens whose `nominee_id` = 7 means three things. */
    private function seed(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        DB::table('gates_nominees')->insert([
            ['id' => 7, 'category_id' => 1, 'name' => 'Duplicate', 'status' => 'approved', 'vote_count' => 1],
            ['id' => 9, 'category_id' => 1, 'name' => 'Survivor',  'status' => 'approved', 'vote_count' => 1],
        ]);

        $tok = static fn (string $purpose, int $subject): array => [
            'email_hash' => hash('sha256', $purpose . '@x.io'),
            'token_hash' => hash('sha256', '000000'),
            'purpose'    => $purpose,
            'nominee_id' => $subject,
            'award_id'   => 0,
            'attempts'   => 0,
            'is_used'    => 0,
            'expires_at' => \Illuminate\Support\Carbon::now()->addMinutes(15)->toDateTimeString(),
            'created_at' => \Illuminate\Support\Carbon::now()->toDateTimeString(),
        ];

        DB::table('gates_otp_tokens')->insert([
            $tok('vote', 7),          // really nominee 7 — must move
            $tok('claim', 7),         // really nominee 7 — must move
            $tok('judge_login', 7),   // JUDGE 7 — must not be touched
            $tok('user_login', 7),    // USER  7 — must not be touched
        ]);
    }

    private function subjectOf(string $purpose): int
    {
        return (int) DB::table('gates_otp_tokens')->where('purpose', $purpose)->value('nominee_id');
    }

    public function test_a_merge_moves_only_the_tokens_whose_subject_is_the_nominee(): void
    {
        $this->seed();

        $r = MergeService::mergeNominees(9, [7]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));

        $this->assertSame(9, $this->subjectOf('vote'),  'a voting code follows the nominee it was issued for');
        $this->assertSame(9, $this->subjectOf('claim'), 'a claim code follows the nominee being claimed');

        $this->assertSame(7, $this->subjectOf('judge_login'),
            "judge 7's sign-in token has nothing to do with nominee 7");
        $this->assertSame(7, $this->subjectOf('user_login'),
            "member 7's sign-in token has nothing to do with nominee 7");
    }

    /**
     * The journal is the point. It is what an operator reads to understand a merge
     * and what an undo would replay, so a phantom entry is not cosmetic — it is a
     * false statement in the record of a destructive operation.
     */
    public function test_the_merge_journal_does_not_claim_to_have_moved_them(): void
    {
        $this->seed();
        MergeService::mergeNominees(9, [7]);

        $moved = DB::table('gates_merge_log')
            ->where('tbl', 'gates_otp_tokens')->where('op', 'reassign')
            ->pluck('row_pk')->all();

        $purposes = DB::table('gates_otp_tokens')->whereIn('id', $moved)
            ->pluck('purpose')->all();
        sort($purposes);

        $this->assertSame(['claim', 'vote'], $purposes,
            'the journal must name exactly the rows the merge was entitled to move');
    }
}
