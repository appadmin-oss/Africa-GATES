<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MergeJournal;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ONE WHERE CLAUSE FOR EVERY MERGE QUERY.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A merge narrows its reassignments with a scope: `[column, value]`. The value may
 * legitimately be a LIST — the nominee merge scopes `gates_otp_tokens` to an allowlist of
 * purposes, because rewriting every token's `nominee_id` corrupted the merge journal
 * itself, which is the record used to review and undo a merge ({@see OtpSubjectScopeTest}).
 *
 * `MergeService::reassignPlain()` grew a `whereIn` branch for that list. The other three
 * sites — its own `reassignDedup()`, and both of `MergeJournal`'s — kept a bare
 * `where($col, $value)`. That is not an error and it does not throw. Laravel's
 * two-argument `where()` treats an array as the VALUE of an `=` comparison, PDO binds it,
 * and the driver takes the first element:
 *
 *     where('purpose', ['vote','claim'])  →  where "purpose" = ?   bound to 'vote'
 *
 * So the rows for every purpose after the first stay pointed at a nominee that no longer
 * exists, the journal records only what moved, and a later `restore()` reports a clean
 * unmerge of a merge that was never clean. Nothing raises anything.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS WORTH A FILE WHEN IT WAS NOT YET LIVE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `MergeJournal`'s own docblock invites the nominee path onto this engine, and the nominee
 * path is the one that passes a list. It was one call site from being a live, silent,
 * partial rewrite inside a destructive operation — and the tests of both merge services
 * would have gone on passing, because each exercises its own copy.
 *
 * The clause has one implementation now. The list case is asserted here on the shared
 * engine, and on the nominee path by `OtpSubjectScopeTest`, which reaches the same
 * function.
 */
final class MergeScopeTest extends TestCase
{
    private const LOG = 'gates_profile_merge_log';

    private function token(string $purpose, int $subject): void
    {
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => hash('sha256', $purpose . $subject . '@x.test'),
            'token_hash' => hash('sha256', '000000'),
            'purpose'    => $purpose,
            'nominee_id' => $subject,
            'award_id'   => 0,
            'attempts'   => 0,
            'is_used'    => 0,
            'expires_at' => Carbon::now()->addMinutes(15)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @return array<string,int> purpose => the subject id the row now points at */
    private function subjects(): array
    {
        $out = [];
        foreach (DB::table('gates_otp_tokens')->get(['purpose', 'nominee_id']) as $r) {
            $out[(string) $r->purpose] = (int) $r->nominee_id;
        }
        return $out;
    }

    private function seed(): void
    {
        $this->token('vote', 7);
        $this->token('claim', 7);
        $this->token('preflight', 7);
        $this->token('judge_login', 7);   // judge 7, nothing to do with nominee 7
    }

    // ══ the shared engine, with a list scope ═════════════════════════════════

    /**
     * EVERY NAMED VALUE MOVES, NOT JUST THE FIRST.
     *
     * The assertion that matters is `preflight`: it is third in the allowlist, so a bound
     * array would leave it behind while `vote` moved and the test still looked right if it
     * only checked one row.
     */
    public function test_a_list_scope_reassigns_every_value_it_names(): void
    {
        $this->seed();

        $log = [];
        MergeJournal::reassignPlain(self::LOG, 'gates_otp_tokens', 'nominee_id', 7, 9,
            'batch-1', $log, ['purpose', ['vote', 'claim', 'preflight']]);

        $this->assertSame(
            ['vote' => 9, 'claim' => 9, 'preflight' => 9, 'judge_login' => 7],
            $this->subjects(),
            'a bare where() binds the array and matches only its first element, so the '
            . 'rows after the first stay pointed at a nominee that no longer exists');
    }

    /**
     * AND THE JOURNAL NAMES EXACTLY WHAT MOVED.
     *
     * This is the half that makes the fault survivable-looking: an under-selecting query
     * writes a journal consistent with itself, so an undo replays cleanly and reports
     * success over rows it never touched.
     */
    public function test_the_journal_records_every_row_the_scope_moved(): void
    {
        $this->seed();

        $log = [];
        MergeJournal::reassignPlain(self::LOG, 'gates_otp_tokens', 'nominee_id', 7, 9,
            'batch-1', $log, ['purpose', ['vote', 'claim', 'preflight']]);

        $moved = array_column($log, 'row_pk');
        $purposes = DB::table('gates_otp_tokens')->whereIn('id', $moved)
            ->pluck('purpose')->all();
        sort($purposes);

        $this->assertSame(['claim', 'preflight', 'vote'], $purposes);
    }

    /** A scalar scope still narrows to exactly that one value. */
    public function test_a_scalar_scope_is_unchanged(): void
    {
        $this->seed();

        $log = [];
        MergeJournal::reassignPlain(self::LOG, 'gates_otp_tokens', 'nominee_id', 7, 9,
            'batch-1', $log, ['purpose', 'claim']);

        $this->assertSame(
            ['vote' => 7, 'claim' => 9, 'preflight' => 7, 'judge_login' => 7],
            $this->subjects());
    }

    /** No scope reassigns the lot, which is what an unscoped table wants. */
    public function test_no_scope_moves_everything_on_the_column(): void
    {
        $this->seed();

        $log = [];
        MergeJournal::reassignPlain(self::LOG, 'gates_otp_tokens', 'nominee_id', 7, 9,
            'batch-1', $log);

        $this->assertSame([9, 9, 9, 9], array_values($this->subjects()));
    }

    // ══ the dedup path, which had the bare clause in BOTH classes ════════════

    /**
     * THE DEDUP PATH TAKES THE SAME LIST.
     *
     * Neither class handled a list here — `MergeService`'s `whereIn` branch was only in
     * `reassignPlain`, so the same argument meant two different things inside one class.
     * Nothing passes a list to dedup today; the asymmetry is the trap, because the next
     * caller has no way to know which of the two forms it is talking to.
     */
    public function test_the_dedup_path_also_honours_a_list_scope(): void
    {
        $this->seed();
        // A row already on the survivor under one of the scoped purposes, so the
        // collision branch runs too.
        $this->token('claim', 9);

        $log = [];
        MergeJournal::reassignDedup(self::LOG, 'gates_otp_tokens', 'nominee_id', 7, 9,
            ['purpose'], 'batch-1', $log, ['purpose', ['vote', 'claim', 'preflight']]);

        $left = DB::table('gates_otp_tokens')->where('nominee_id', 7)
            ->pluck('purpose')->all();
        sort($left);

        $this->assertSame(['judge_login'], $left,
            'only the row outside the scope should still point at the merged nominee');

        // `claim` collided with the survivor's own row and was snapshotted away; the
        // other two moved.
        $this->assertSame(1, (int) DB::table('gates_otp_tokens')
            ->where('purpose', 'claim')->count(),
            'the colliding row is dropped after being snapshotted, not duplicated');
        $this->assertContains('delete', array_column($log, 'op'),
            'the dropped duplicate must be journaled so an undo can re-insert it');
    }

    // ══ and the clause itself ════════════════════════════════════════════════

    /**
     * Asserted on the query rather than on rows, so the failure names the SQL.
     *
     * `where "purpose" = ?` for a list is the whole bug in one line, and it is the line
     * nobody would look at twice.
     */
    public function test_the_clause_is_an_IN_for_a_list_and_an_equality_for_a_scalar(): void
    {
        $q = DB::table('gates_otp_tokens');
        MergeJournal::applyScope($q, ['purpose', ['vote', 'claim']]);
        $this->assertStringContainsString('in (?, ?)', strtolower($q->toSql()));

        $q = DB::table('gates_otp_tokens');
        MergeJournal::applyScope($q, ['purpose', 'claim']);
        $this->assertStringContainsString('"purpose" = ?', $q->toSql());

        // Null and empty narrow nothing — an absent scope is not an empty IN, which
        // matches no row at all and would silently reassign nothing.
        $q = DB::table('gates_otp_tokens')->where('nominee_id', 7);
        $before = $q->toSql();
        MergeJournal::applyScope($q, null);
        MergeJournal::applyScope($q, []);
        $this->assertSame($before, $q->toSql());
    }
}
