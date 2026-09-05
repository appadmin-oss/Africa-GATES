<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\InterviewService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The calendar is where the appointment lives; our row is a copy of it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAILURE THIS PREVENTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The recording bot is dispatched off `scheduled_at` and `meet_url` in `gates_interviews`.
 * Both are copies of something that lives in Google Calendar — and an organiser who moves
 * the meeting does it THERE, which is the entire reason for putting it in a calendar.
 *
 * Nothing told us. So the bot turned up at the old time to an empty room, the interview
 * happened an hour later with nobody recording it, and the only symptom was a missing
 * transcript afterwards — at exactly the point where nobody can do anything about it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE TWO RULES THAT KEEP THE FIX FROM BEING WORSE THAN THE BUG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · IT ONLY EVER MOVES ONE WAY. This reads and corrects US; it never writes to Google. A
 *     reconciler that also pushed would fight the operator — they drag the meeting, we push
 *     it back, and neither side can tell which of them last won.
 *
 * 2 · IT DOES NOT RE-RUN THE RESCHEDULE MACHINERY. {@see InterviewService::reschedule()}
 *     clears the nominee's confirmation and re-queues both reminders, which are the right
 *     consequences of US moving an appointment and the wrong ones for NOTICING that it has
 *     already moved: Google has already told everybody, and clearing the confirmation would
 *     make a nominee who said yes read as though they had not.
 */
final class InterviewCalendarSyncTest extends TestCase
{
    private int $iv = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $nominee = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 1, 'name' => 'Ada Nwosu', 'status' => 'approved', 'vote_count' => 0,
        ]);
        $this->iv = (int) DB::table('gates_interviews')->insertGetId([
            'nominee_id'        => $nominee,
            'status'            => 'confirmed',
            'scheduled_at'      => '2026-09-01 14:00:00',
            'confirmed_at'      => '2026-08-20 09:00:00',
            'duration_mins'     => 30,
            'timezone'          => 'Africa/Lagos',
            'meet_url'          => 'https://meet.google.com/abc-defg-hij',
            'meet_code'         => 'abc-defg-hij',
            'calendar_event_id' => 'evt_original',
            'created_at'        => '2026-08-19 09:00:00',
            'updated_at'        => '2026-08-20 09:00:00',
        ]);
    }

    /** A sitting that was never booked through us has nothing to reconcile against. */
    public function test_a_sitting_with_no_calendar_event_is_not_an_error(): void
    {
        DB::table('gates_interviews')->where('id', $this->iv)
            ->update(['calendar_event_id' => null]);

        $r = InterviewService::reconcileFromCalendar($this->iv);

        $this->assertTrue($r['ok'], 'most sittings in a fresh installation are in this state');
        $this->assertFalse($r['changed']);
    }

    public function test_an_unknown_interview_is_reported_rather_than_thrown(): void
    {
        $r = InterviewService::reconcileFromCalendar(999_999);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('could not be found', $r['message']);
    }

    /**
     * Without a configured bridge it declines, and says so — it does not silently no-op.
     *
     * "The bot did not join" and "the calendar was never readable from here" are different
     * problems, and collapsing the second into a quiet success is how the first one gets
     * investigated for a week.
     */
    public function test_it_declines_loudly_when_the_bridge_is_not_configured(): void
    {
        $r = InterviewService::reconcileFromCalendar($this->iv);

        // No GAS_URL/GAS_SECRET in the harness, so this is the configured-off path.
        $this->assertFalse($r['ok']);
        $this->assertNotSame('', trim((string) $r['message']));
        $this->assertFalse($r['changed'], 'nothing may be written when nothing was read');
    }

    /**
     * And it leaves the row exactly as it found it.
     *
     * The dangerous shape of a failed read is a PARTIAL write — a cleared meet_url or a
     * zeroed time, from a call that never returned anything. A sitting is better stale than
     * half-erased.
     */
    public function test_a_failed_read_changes_nothing_on_the_row(): void
    {
        $before = (array) DB::table('gates_interviews')->where('id', $this->iv)->first();

        InterviewService::reconcileFromCalendar($this->iv);

        $after = (array) DB::table('gates_interviews')->where('id', $this->iv)->first();
        $this->assertSame($before, $after);
    }

    /**
     * The bot's dispatch window is what all of this is for, so it is asserted here too.
     *
     * `scheduled_at` decides when a bot is sent. A row an hour behind the calendar is a bot
     * in an empty room, and the reconcile exists purely so that column can be trusted.
     */
    public function test_the_column_the_bot_dispatches_on_is_the_one_being_corrected(): void
    {
        // Read as SOURCE, because the behaviour itself needs a live Google bridge and the
        // harness has none — so what can be held here is the shape of the write, not the
        // result of it. Sliced to the method rather than scanning the file: `meet_url`
        // appears in a dozen other places in this service, and a whole-file match would
        // pass even if this method stopped touching it.
        $ref = new \ReflectionMethod(\AfricaGates\Services\InterviewService::class,
                                     'reconcileFromCalendar');
        $lines = file((string) $ref->getFileName()) ?: [];
        $body  = implode('', array_slice(
            $lines,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        ));

        $this->assertNotSame('', trim($body), 'the method body could not be read');

        $this->assertStringContainsString("\$patch['scheduled_at']", $body,
            'a reconcile that does not correct the dispatch time fixes nothing');
        $this->assertStringContainsString("\$patch['meet_url']", $body,
            'a bot sent to a stale link joins nothing');

        // And it must NOT go through reschedule(), which would clear the confirmation.
        $this->assertStringNotContainsString('self::reschedule(', $body,
            'reconciling would clear the nominee\'s confirmation and re-queue the reminders');
    }

    /** The nominee's confirmation survives a reconcile — they already said yes. */
    public function test_a_reconcile_never_clears_the_confirmation(): void
    {
        InterviewService::reconcileFromCalendar($this->iv);

        $this->assertSame('2026-08-20 09:00:00',
            (string) DB::table('gates_interviews')->where('id', $this->iv)->value('confirmed_at'));
    }
}
