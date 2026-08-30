<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\JudgeAssist;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A judge saying the dossier map misread the dossier.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS WORTH HAVING AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The map is written ABOVE a nominee's evidence and read before it, so it frames
 * everything after. That placement is deliberate and it is the whole risk: a map is
 * rarely wrong about what it read, it is wrong about what it is a map OF, and only the
 * reader can tell the difference.
 *
 * The judge holding the map and the dossier side by side is the only person positioned to
 * notice — and had no way to say so. A model artefact that shapes a judging decision, is
 * sometimes wrong, and collects no correction is the §17 fault from the other direction:
 * a signal nothing gathers rather than one nothing reads.
 */
final class JudgeMapDisputeTest extends TestCase
{
    private int $nomineeId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => 2026, 'status' => 'judging',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cid, 'slug' => 'excellence', 'title' => 'Academic Excellence', 'sort_order' => 1,
        ]);
        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => 'Ada Obi', 'status' => 'approved',
        ]);
    }

    // ══ RECORDING IT ════════════════════════════════════════════════════════

    public function test_a_judge_can_say_the_map_is_wrong(): void
    {
        $this->assertTrue(JudgeAssist::flag(7, $this->nomineeId, 'It describes an award she did not win.'));

        $this->assertSame([$this->nomineeId => true], JudgeAssist::flaggedBy(7));
    }

    /** Pressing it twice is one complaint, and must not look like a failure. */
    public function test_flagging_twice_is_one_complaint(): void
    {
        $this->assertTrue(JudgeAssist::flag(7, $this->nomineeId, 'first'));
        $this->assertTrue(JudgeAssist::flag(7, $this->nomineeId, 'second, on reflection'));

        $this->assertSame(1, (int) DB::table('gates_judge_map_flags')->count());
        $this->assertSame('second, on reflection',
            (string) DB::table('gates_judge_map_flags')->value('reason'));
    }

    /** A reason is optional — a required box mostly produces empty complaints. */
    public function test_a_reason_is_optional_and_stored_as_null_when_absent(): void
    {
        JudgeAssist::flag(7, $this->nomineeId, '   ');

        $this->assertNull(DB::table('gates_judge_map_flags')->value('reason'));
    }

    /**
     * One judge's objection does not decide for the panel.
     *
     * The map is cached and shared, so flagging hides it for the judge who disputed it and
     * leaves the other four to read it. Withdrawing a map from a whole panel is a person's
     * decision, which is why the audit screen exists rather than an automatic threshold.
     */
    public function test_one_judges_objection_is_theirs_alone(): void
    {
        JudgeAssist::flag(7, $this->nomineeId, 'wrong');

        $this->assertSame([$this->nomineeId => true], JudgeAssist::flaggedBy(7));
        $this->assertSame([], JudgeAssist::flaggedBy(8),
            'the other judges on the panel still see the map');
    }

    public function test_nonsense_ids_are_refused_rather_than_written(): void
    {
        $this->assertFalse(JudgeAssist::flag(0, $this->nomineeId));
        $this->assertFalse(JudgeAssist::flag(7, 0));
        $this->assertSame(0, (int) DB::table('gates_judge_map_flags')->count());
    }

    // ══ THE READER ══════════════════════════════════════════════════════════

    /**
     * A flag nobody looks at is worse than no button.
     *
     * It teaches a judge their objection was heard, when all that happened is that it was
     * filed. The count is the signal an operator acts on — one judge disagreeing with a
     * map is a judge, three is a map — so the list is ordered by it.
     */
    public function test_the_audit_ranks_by_how_many_judges_disputed_it(): void
    {
        $second = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => (int) DB::table('gates_award_categories')->value('id'),
            'name' => 'Chidi Eze', 'status' => 'approved',
        ]);

        JudgeAssist::flag(1, $second, 'reads like a different person');
        JudgeAssist::flag(2, $second, '');
        JudgeAssist::flag(3, $second, 'the figures are not in the dossier');
        JudgeAssist::flag(1, $this->nomineeId, 'one line is off');

        $rows = JudgeAssist::disputed();

        $this->assertSame('Chidi Eze', $rows[0]['nominee'], 'three judges outrank one');
        $this->assertSame(3, $rows[0]['flags']);
        $this->assertSame(1, $rows[1]['flags']);

        // The reasons say WHICH way it was wrong, which is what an operator acts on. The
        // judge who gave none is counted and simply contributes no sentence.
        $this->assertCount(2, $rows[0]['reasons']);
        $this->assertContains('the figures are not in the dossier', $rows[0]['reasons']);
    }

    /** A deleted nominee must not make the audit unreadable. */
    public function test_a_flag_whose_nominee_is_gone_still_reports(): void
    {
        JudgeAssist::flag(1, 999999, 'about somebody no longer here');

        $rows = JudgeAssist::disputed();

        $this->assertSame('Nominee #999999', $rows[0]['nominee']);
    }

    public function test_nothing_disputed_is_an_empty_list_not_a_failure(): void
    {
        $this->assertSame([], JudgeAssist::disputed());
    }

    // ══ THE WIRING ══════════════════════════════════════════════════════════

    /**
     * The whole point is that the map stops being shown to the judge who rejected it.
     *
     * Continuing to render it above the evidence after its reader has told us it is wrong
     * is the exact harm the map risks, delivered deliberately.
     */
    public function test_the_ballot_hides_a_map_from_the_judge_who_disputed_it(): void
    {
        $svc = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Judge/Services/JudgeService.php');

        $this->assertStringContainsString('JudgeAssist::flaggedBy(', $svc,
            'the ballot must ask which maps this judge has disputed');
        $this->assertStringContainsString("\$n['map_flagged']", $svc,
            'and say so, rather than offering to write the same map again');
    }

    /** The endpoint carries the same guard as the map endpoint, not a weaker one. */
    public function test_the_flag_endpoint_cannot_be_used_to_enumerate_entries(): void
    {
        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Judge/Controllers/BallotController.php');

        $from = (int) strpos($ctl, 'function flagMap');
        $body = substr($ctl, $from, (int) strpos($ctl, 'function saveScore') - $from);

        $this->assertStringContainsString('mayJudgeNominee', $body,
            'a write endpoint that confirms which nominees exist is a cheaper enumeration '
            . 'than the read one, because no cache can rate-limit it');
        $this->assertStringContainsString('not on your ballot', $body,
            'and it must answer exactly as it does for a nominee that does not exist');
    }

    /** Routed, or the button posts into nothing. */
    public function test_the_correction_is_routed_and_read_by_the_audit(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $this->assertStringContainsString("/orient/{nomineeId:[0-9]+}/flag", $routes);

        $audit = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/JudgingAuditController.php');
        $this->assertStringContainsString('JudgeAssist::disputed(', $audit,
            'a complaint that is collected and never read is worse than no button at all');
    }
}
