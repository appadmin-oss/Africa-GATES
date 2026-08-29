<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{JudgeSchedule, OtpService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Where a judge is expected, and whether the calendar agrees.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ROUND EXISTED AND NOBODY COULD SEE IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_interviews` holds a time, a panel, a joining link and a calendar event id per
 * sitting. Every screen that reads any of it reads ONE sitting — `/admin/interviews/{id}`.
 * So an operator running a panel of ten across forty entries had the whole round in the
 * database and no way to answer "what is Tuesday", "does Dr Achebe know about Thursday",
 * or "did the calendar actually take it".
 *
 * And a judge could see their ballots and not their calls. The only place a sitting reached
 * them was an email three days old, so on the morning of a call the link had to be found
 * again in an inbox.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO THINGS MOST WORTH HOLDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *  · A CSV is not "add to calendar". The spreadsheet answers "what am I doing next week";
 *    it cannot remind anybody on the morning, which is the entire failure `Support\Ics`
 *    was written for on the ticket side.
 *
 *  · DRIFT is silent. A missing calendar event is loud — nobody gets an invitation and
 *    somebody complains. An event dragged to a new time in Google sends nothing: the
 *    invitations already went with the old time, our reminders keep sending the old time,
 *    and the panel arrives an hour after the nominee.
 */
final class JudgeScheduleTest extends TestCase
{
    private int $catId = 0;
    private int $progId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_interviews')->delete();
        DB::table('gates_judges')->delete();

        DB::table('gates_judges')->insert([
            ['id' => 1, 'name' => 'Amina Bello', 'email' => 'amina@example.com', 'is_active' => 1],
            ['id' => 2, 'name' => 'Tunde Cole',  'email' => 'tunde@example.com', 'is_active' => 1],
            ['id' => 3, 'name' => 'Idle Judge',  'email' => 'idle@example.com',  'is_active' => 1],
        ]);

        $this->progId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'sched-' . bin2hex(random_bytes(3)), 'title' => 'Music', 'sort_order' => 5,
        ]);
        $cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->progId, 'year' => 2026, 'status' => 'judging',
        ]);
        $this->catId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycleId, 'slug' => 'song-' . bin2hex(random_bytes(3)), 'title' => 'Song',
        ]);
    }

    private function nominee(string $name): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->catId, 'name' => $name, 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
    }

    /** @param list<int> $panel */
    private function sitting(string $when, array $panel, array $over = []): int
    {
        return (int) DB::table('gates_interviews')->insertGetId($over + [
            'nominee_id'   => $this->nominee('Nominee ' . bin2hex(random_bytes(2))),
            'scheduled_at' => date('Y-m-d H:i:s', strtotime($when)),
            'duration_mins'=> 30,
            'timezone'     => 'Africa/Lagos',
            'status'       => 'scheduled',
            'meet_url'     => 'https://meet.google.com/aaa-bbbb-ccc',
            'panel_json'   => json_encode($panel),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    // ══ the round ════════════════════════════════════════════════════════════

    public function test_the_round_is_ordered_soonest_first(): void
    {
        $this->sitting('+5 days', [1]);
        $this->sitting('+1 day',  [1]);
        $this->sitting('+3 days', [1]);

        $when = array_column(JudgeSchedule::upcoming(), 'when');

        $sorted = $when;
        sort($sorted);
        $this->assertSame($sorted, $when, 'a schedule out of order is a schedule nobody reads');
    }

    /**
     * The recent past is in the window, deliberately.
     *
     * "Was that call yesterday actually held" is the question an operator asks when a
     * transcript has not appeared, and a schedule that only looks forward cannot answer it.
     */
    public function test_the_recent_past_is_still_shown(): void
    {
        $this->sitting('-1 day', [1]);

        $this->assertCount(1, JudgeSchedule::upcoming());
    }

    /** But not the whole history — that is the interview queue's job, not a schedule's. */
    public function test_the_distant_past_drops_off(): void
    {
        $this->sitting('-' . (JudgeSchedule::PAST_DAYS + 4) . ' days', [1]);

        $this->assertSame([], JudgeSchedule::upcoming());
    }

    /** A cancelled sitting is not on anybody's calendar. */
    public function test_a_cancelled_sitting_is_not_in_the_schedule(): void
    {
        $this->sitting('+2 days', [1], ['status' => 'cancelled']);

        $this->assertSame([], JudgeSchedule::upcoming());
    }

    /** An unscheduled sitting has no place on a calendar and is not given one. */
    public function test_a_sitting_with_no_date_is_not_in_the_schedule(): void
    {
        DB::table('gates_interviews')->insert([
            'nominee_id' => $this->nominee('Undated'), 'status' => 'scheduled',
            'panel_json' => json_encode([1]), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], JudgeSchedule::upcoming());
    }

    // ══ whose sitting is it ══════════════════════════════════════════════════

    public function test_a_judge_sees_only_their_own_sittings(): void
    {
        $mine   = $this->sitting('+1 day',  [1, 2]);
        $theirs = $this->sitting('+2 days', [2]);

        $ids = array_column(JudgeSchedule::forJudge(1), 'id');

        $this->assertSame([$mine], $ids);
        $this->assertContains($theirs, array_column(JudgeSchedule::forJudge(2), 'id'));
    }

    /**
     * A judge with nothing scheduled is absent from the reminder list.
     *
     * Not an edge case — it is what happens when somebody presses "remind everyone" on a
     * round that is half scheduled. A reminder about no meetings is the fastest way to
     * teach a panel to ignore our email.
     */
    public function test_a_judge_with_nothing_scheduled_is_not_offered_for_reminding(): void
    {
        $this->sitting('+1 day', [1]);

        $ids = array_column(JudgeSchedule::judgesWithSittings(), 'id');

        $this->assertSame([1], $ids);
        $this->assertNotContains(3, $ids, 'the idle judge must not be mailed about nothing');
    }

    public function test_the_reminder_list_counts_each_judges_sittings(): void
    {
        $this->sitting('+1 day',  [1, 2]);
        $this->sitting('+2 days', [1]);

        $byId = [];
        foreach (JudgeSchedule::judgesWithSittings() as $j) $byId[$j['id']] = $j;

        $this->assertSame(2, $byId[1]['sittings']);
        $this->assertSame(1, $byId[2]['sittings']);
    }

    /** Narrowing to a programme narrows both the list and what each judge is told. */
    public function test_the_round_can_be_narrowed_to_one_programme(): void
    {
        $this->sitting('+1 day', [1]);

        $this->assertCount(1, JudgeSchedule::upcoming($this->progId));
        $this->assertSame([], JudgeSchedule::upcoming($this->progId + 9999));
    }

    // ══ add to calendar ══════════════════════════════════════════════════════

    /**
     * ONE calendar file with every sitting in it.
     *
     * A judge with six sittings should press one thing. Concatenating single-event files
     * gives a client six calendars in one attachment and none of them does something useful
     * with that.
     */
    public function test_the_whole_schedule_is_one_calendar_with_many_entries(): void
    {
        $this->sitting('+1 day',  [1]);
        $this->sitting('+2 days', [1]);
        $this->sitting('+3 days', [1]);

        $ics = JudgeSchedule::icsFor(JudgeSchedule::forJudge(1), 'Amina Bello');

        $this->assertSame(1, substr_count($ics, 'BEGIN:VCALENDAR'));
        $this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
    }

    /**
     * The UID is derived from the sitting, so a re-import UPDATES rather than duplicates.
     *
     * That is the whole difference between a calendar file that is useful twice and one
     * somebody has to clean up by hand after every reschedule.
     */
    public function test_re_importing_after_a_reschedule_updates_the_same_entry(): void
    {
        $id = $this->sitting('+1 day', [1]);

        $before = JudgeSchedule::icsFor(JudgeSchedule::forJudge(1));
        preg_match('/^UID:(.+)$/m', $before, $m);
        $uid = trim((string) ($m[1] ?? ''));
        $this->assertNotSame('', $uid);

        DB::table('gates_interviews')->where('id', $id)
            ->update(['scheduled_at' => date('Y-m-d H:i:s', strtotime('+4 days'))]);

        $after = JudgeSchedule::icsFor(JudgeSchedule::forJudge(1));

        $this->assertStringContainsString('UID:' . $uid, $after,
            'a fresh UID gives the judge two conflicting entries and no way to tell which is current');
        $this->assertNotSame($before, $after, 'and the time really did change');
    }

    /** The joining link is the location, which is what a phone turns into a tappable join. */
    public function test_the_joining_link_is_the_calendar_entrys_location(): void
    {
        $this->sitting('+1 day', [1]);

        $ics = JudgeSchedule::icsFor(JudgeSchedule::forJudge(1));

        $this->assertStringContainsString('LOCATION:https://meet.google.com/aaa-bbbb-ccc', $ics);
        $this->assertStringContainsString('URL:https://meet.google.com/aaa-bbbb-ccc', $ics);
    }

    /** A judge with nothing scheduled gets an empty calendar, not a broken file. */
    public function test_an_empty_schedule_is_an_empty_calendar(): void
    {
        $ics = JudgeSchedule::icsFor(JudgeSchedule::forJudge(3));

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertSame(0, substr_count($ics, 'BEGIN:VEVENT'));
    }

    // ══ the reminder ═════════════════════════════════════════════════════════

    /** @param list<array<string,mixed>> $sink */
    private function mailer(array &$sink): OtpService
    {
        return new class($sink) extends OtpService {
            /** @param list<array<string,mixed>> $sink */
            public function __construct(private array &$sink) { parent::__construct([]); }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody,
                                 'plain' => $plainBody, 'files' => $attachments];
                return ['success' => true];
            }
        };
    }

    public function test_a_reminder_carries_the_link_and_a_calendar_file(): void
    {
        $this->sitting('+1 day', [1]);
        $sent = [];

        $r = JudgeSchedule::remind([1], null, $this->mailer($sent));

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['sent']);
        $this->assertCount(1, $sent);
        $this->assertSame('amina@example.com', $sent[0]['to']);

        // The link is the point of the message, in both parts — a text-only client is not a
        // reason to be sent to a meeting with no way of reaching it.
        $this->assertStringContainsString('meet.google.com/aaa-bbbb-ccc', $sent[0]['html']);
        $this->assertStringContainsString('meet.google.com/aaa-bbbb-ccc', $sent[0]['plain']);

        $this->assertCount(1, $sent[0]['files']);
        $this->assertStringContainsString('text/calendar', (string) $sent[0]['files'][0]['mime']);
        $this->assertStringEndsWith('.ics', (string) $sent[0]['files'][0]['name']);
    }

    /** Every sitting the judge has, not just one — that was the old per-sitting email's fault. */
    public function test_one_reminder_covers_all_of_a_judges_sittings(): void
    {
        $this->sitting('+1 day',  [1]);
        $this->sitting('+2 days', [1]);
        $sent = [];

        JudgeSchedule::remind([1], null, $this->mailer($sent));

        $this->assertCount(1, $sent, 'one message, not one per sitting');
        $this->assertSame(2, substr_count((string) $sent[0]['files'][0]['body'], 'BEGIN:VEVENT'));
    }

    public function test_a_judge_with_nothing_scheduled_is_skipped_not_mailed(): void
    {
        $this->sitting('+1 day', [1]);
        $sent = [];

        $r = JudgeSchedule::remind([1, 3], null, $this->mailer($sent));

        $this->assertSame(1, $r['sent']);
        $this->assertSame(1, $r['skipped']);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('skipped', $r['message'],
            'a partial send must say so — "sent 1" on a panel of two reads as all of them');
    }

    /** Nobody selected is a refusal, not a silent success. */
    public function test_reminding_nobody_says_so(): void
    {
        $r = JudgeSchedule::remind([]);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['sent']);
    }

    /** An inactive judge is off the panel and is not written to. */
    public function test_a_deactivated_judge_is_not_reminded(): void
    {
        $this->sitting('+1 day', [1]);
        DB::table('gates_judges')->where('id', 1)->update(['is_active' => 0]);
        $sent = [];

        $r = JudgeSchedule::remind([1], null, $this->mailer($sent));

        $this->assertSame(0, $r['sent']);
        $this->assertSame([], $sent);
    }

    /** The subject names the next call, because that is what the recipient is deciding about. */
    public function test_the_subject_names_when(): void
    {
        $this->sitting('+1 day', [1]);
        $sent = [];

        JudgeSchedule::remind([1], null, $this->mailer($sent));

        $this->assertStringContainsString('judging call', strtolower((string) $sent[0]['subject']));
    }

    // ══ does the calendar agree? ═════════════════════════════════════════════

    /**
     * A sitting that was never synced says so, and is not reported as deleted.
     *
     * Different fix: press "create the meeting", not "go and look in Google".
     */
    public function test_a_sitting_never_synced_is_reported_as_such(): void
    {
        $id = $this->sitting('+1 day', [1]);

        $r = JudgeSchedule::verify($id);

        $this->assertSame(JudgeSchedule::SYNC_MISSING, $r['state']);
        $this->assertStringContainsString('has been created', $r['message']);
    }

    /**
     * And a script that cannot be reached is UNKNOWN, never MISSING.
     *
     * Telling an operator forty sittings are absent from the calendar because a deployment
     * is misconfigured would send them to recreate forty events that already exist. The
     * suite has no Apps Script configured, so this is the real path.
     */
    public function test_an_unreachable_script_is_not_reported_as_a_missing_event(): void
    {
        $id = $this->sitting('+1 day', [1], ['calendar_event_id' => 'evt_abc123']);

        $this->assertSame(JudgeSchedule::SYNC_UNKNOWN, JudgeSchedule::verify($id)['state']);
    }

    /** A sitting id that does not exist is answered, not thrown. */
    public function test_verifying_something_that_is_not_there_is_an_answer(): void
    {
        $r = JudgeSchedule::verify(987654);

        $this->assertSame(JudgeSchedule::SYNC_UNKNOWN, $r['state']);
        $this->assertNotSame('', $r['message']);
    }

    // ══ the surfaces ═════════════════════════════════════════════════════════

    /** The screen exists, is reachable, and does not verify on render. */
    public function test_the_schedule_screen_is_routed_and_does_not_spend_quota_on_render(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $ctrl   = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/JudgesController.php');

        $this->assertStringContainsString("'/schedule'", $routes);
        $this->assertStringContainsString("'/schedule/remind'", $routes);
        $this->assertStringContainsString('/verify', $routes);

        // Forty sittings is forty round trips to an Apps Script deployment. The listing
        // renders from the rows; verify() is only ever reached from its own action.
        $schedule = substr($ctrl, (int) strpos($ctrl, 'function schedule'),
                          (int) strpos($ctrl, 'function verify') - (int) strpos($ctrl, 'function schedule'));
        $this->assertStringNotContainsString('JudgeSchedule::verify', $schedule,
            'verifying while the page loads is how this screen stops working during the round');
    }

    /**
     * A narrow scope must not widen when its argument is missing.
     *
     * `judgesWithSittings(null)` means "everybody" — the right default for the "all" button
     * and exactly the wrong one for a `scope=programme` post that arrives without a
     * programme. A hand-edited form, a stale page, or a double submit after somebody cleared
     * the filter would otherwise mail the entire panel, and an email cannot be recalled.
     */
    public function test_a_programme_send_with_no_programme_is_refused_not_widened(): void
    {
        $ctrl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/JudgesController.php');

        $this->assertMatchesRegularExpression(
            "/\\\$scope === 'programme' && \\\$prog === null/", $ctrl,
            'the widening case has to be named and refused, not left to the default arm');
        $this->assertStringContainsString('No programme was chosen', $ctrl);
    }

    /** The judge's own calendar download is a GET, because it has to work from an email. */
    public function test_the_judge_can_download_their_own_calendar(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        $this->assertStringContainsString("'/schedule.ics'", $routes);
        $this->assertMatchesRegularExpression('/schedule\.ics.{0,900}no-store/s', $routes,
            'a cached schedule is a judge in the wrong room');
    }

    /** And their calls are on the page they are already signed in to. */
    public function test_the_judge_dashboard_shows_their_calls(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/judge/dashboard.twig');

        $this->assertStringContainsString('Your calls', $tpl);
        $this->assertStringContainsString('/judge/schedule.ics', $tpl);
        // A missing link is somebody's job. A judge who sees an empty space assumes the
        // page is broken and does not mention it.
        $this->assertStringContainsString('Link to follow', $tpl);
    }

    /** The admin CSP has no 'unsafe-inline', so the confirms go through data-confirm. */
    public function test_the_admin_screen_uses_no_inline_handlers(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/judges/schedule.twig');

        $this->assertStringNotContainsString('onclick=', $tpl);
        $this->assertStringNotContainsString('onsubmit=', $tpl);
        $this->assertStringContainsString('data-confirm=', $tpl);
        // Every send states its blast radius before it is pressed. There is no undo on email.
        $this->assertStringContainsString('Remind all', $tpl);
    }

    /**
     * The screen never claims a sitting is synced when it could not ask.
     *
     * All that is known from the row is that an event id was stored when the sitting was
     * created. Whether Google still holds it is precisely the question that cannot be
     * answered while the Apps Script side is unreachable — and asserting health that has
     * not been checked is the fault the status page was rebuilt to stop doing.
     */
    public function test_the_screen_does_not_claim_a_sync_it_could_not_verify(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/judges/schedule.twig');

        $this->assertStringContainsString('Event on file', $tpl);
        $this->assertStringNotContainsString('--grey">Synced', $tpl,
            'that chip asserts a fact about Google that this branch cannot know');
    }
}
