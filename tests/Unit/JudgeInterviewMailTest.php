<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{InterviewService, OtpService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The message that asks a judge to join a video call with a stranger.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ONE MATTERED MORE THAN IT LOOKED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It went out through `sendCustom()` — plain text, no wrapper, no mark. Every other
 * message this platform sends is branded, so the single email whose entire purpose is to
 * get somebody to click a meeting link was the one that looked least like it came from us.
 * That is the exact shape of a message people do not click.
 *
 * And it was true about one sitting while being silent about the other five. A judge on a
 * six-interview panel got six emails and no answer to the question they actually have,
 * which is what they are doing next week. The attachment is that answer.
 */
final class JudgeInterviewMailTest extends TestCase
{
    /** @var list<array{to:string, subject:string, html:string, plain:string, files:array}> */
    private array $sent = [];
    private int $ivId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];

        DB::table('gates_judges')->insert([
            ['id' => 1, 'name' => 'Amina Bello', 'email' => 'amina@example.com', 'is_active' => 1],
            ['id' => 2, 'name' => 'Tunde Cole',  'email' => 'tunde@example.com', 'is_active' => 1],
        ]);
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p', 'title' => 'P']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'music', 'title' => 'Music', 'sort_order' => 1]);
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);

        $this->ivId = (int) DB::table('gates_interviews')->insertGetId([
            'nominee_id'   => 1,
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('+3 days')),
            'timezone'     => 'Africa/Lagos',
            'status'       => 'scheduled',
            'meet_url'     => 'https://meet.google.com/abc-defg-hij',
            'panel_json'   => json_encode([1, 2]),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** A mailer that records the whole message, attachments included. */
    private function mailer(): OtpService
    {
        return new class($this->sent) extends OtpService {
            /** @param list<array<string,mixed>> $sink */
            public function __construct(private array &$sink) { parent::__construct([]); }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody,
                                 'plain' => $plainBody, 'files' => $attachments, 'branded' => true];
                return ['success' => true];
            }

            public function sendCustom(string $to, string $subject, string $body): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'html' => '',
                                 'plain' => $body, 'files' => [], 'branded' => false];
                return ['success' => true];
            }
        };
    }

    /** @return list<array<string,mixed>> the messages that went to the panel */
    private function invite(): array
    {
        InterviewService::invite($this->ivId, $this->mailer());

        return array_values(array_filter($this->sent, static fn (array $m): bool
            => str_contains((string) $m['to'], '@example.com')));
    }

    // ════════════════════════════════════════════════════════════════════════

    public function test_the_panel_invitation_is_branded(): void
    {
        $to = $this->invite();

        $this->assertCount(2, $to, 'both judges on the panel should hear');
        foreach ($to as $m) {
            $this->assertTrue($m['branded'], 'a judge was sent unbranded plain text');
            $this->assertNotSame('', $m['html']);
        }
    }

    /** The meeting link is the point of the message, so it is in it — as a link. */
    public function test_the_meeting_link_is_in_the_message(): void
    {
        $m = $this->invite()[0];

        $this->assertStringContainsString('https://meet.google.com/abc-defg-hij', $m['html']);
        $this->assertStringContainsString('Join the interview', $m['html']);
        // And in the plain part too. A text-only client is not a reason to be sent to a
        // meeting with no way of reaching it.
        $this->assertStringContainsString('https://meet.google.com/abc-defg-hij', $m['plain']);
    }

    /**
     * The judge's whole run, attached.
     *
     * Not a list inside the message: a judge sitting six interviews would get six of
     * those, each true about one and silent about the rest.
     */
    public function test_the_judges_whole_schedule_is_attached(): void
    {
        $m = $this->invite()[0];

        // TWO files. The spreadsheet is the one a judge reads on a laptop; the calendar is
        // the one that reminds them on the morning. See the test below for why the second
        // is not optional.
        $this->assertCount(2, $m['files']);

        $csvFile = $this->fileOfMime($m, 'text/csv');
        $this->assertNotNull($csvFile, 'the readable schedule is gone');
        $this->assertStringEndsWith('.csv', (string) $csvFile['name']);

        $csv = (string) $csvFile['body'];
        $this->assertStringContainsString('Ada Obi', $csv);
        $this->assertStringContainsString('Music', $csv);
        $this->assertStringContainsString('https://meet.google.com/abc-defg-hij', $csv);
        $this->assertStringContainsString('/run', $csv, 'the console link is what a judge opens on the day');
        // Excel on Windows is the commonest reader, and without a BOM it renders a
        // nominee's name in the wrong encoding.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    /** @param array<string,mixed> $m @return array<string,mixed>|null */
    private function fileOfMime(array $m, string $needle): ?array
    {
        foreach ((array) $m['files'] as $f) {
            if (str_contains((string) $f['mime'], $needle)) return (array) $f;
        }
        return null;
    }

    /**
     * A SPREADSHEET IS NOT "ADD TO CALENDAR".
     *
     * The CSV answers "what am I doing next week" and a judge can read it on a laptop. It
     * cannot be added to a calendar — so on the morning of the call nothing reminds anybody
     * and the joining link has to be found again in a three-day-old email. That is exactly
     * the failure `Support\Ics` exists for on the ticket side: a seat that goes unused is
     * usually not a change of mind, it is somebody who meant to put it in their calendar.
     */
    public function test_the_schedule_can_actually_be_added_to_a_calendar(): void
    {
        $m   = $this->invite()[0];
        $cal = $this->fileOfMime($m, 'text/calendar');

        $this->assertNotNull($cal, 'a judge was sent a spreadsheet and nothing a calendar can read');
        $this->assertStringEndsWith('.ics', (string) $cal['name']);

        $ics = (string) $cal['body'];
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('Ada Obi', $ics);
        // The joining link is the LOCATION, because that is what a calendar app turns into
        // a tappable "join" at one minute to the hour.
        $this->assertStringContainsString('meet.google.com/abc-defg-hij', $ics);
        // Every line CRLF-terminated: every LF is part of a CRLF and there are no bare
        // ones. Outlook rejects a file with bare LF outright, and the entry simply never
        // appears — no error anybody sees.
        $this->assertSame(substr_count($ics, "\n"), substr_count($ics, "\r\n"),
            'a bare LF is a calendar file Outlook will not open');
    }

    /**
     * ONE calendar, with the sittings inside it.
     *
     * Concatenating single-event files gives a client several calendars in one attachment,
     * and none of them handles that as the person pressing the button meant. A judge on two
     * sittings should import two entries from one file.
     */
    public function test_several_sittings_arrive_as_one_calendar(): void
    {
        DB::table('gates_nominees')->insert([
            'id' => 2, 'category_id' => 1, 'name' => 'Chinelo Umeh', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
        DB::table('gates_interviews')->insert([
            'nominee_id'   => 2,
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('+5 days')),
            'timezone'     => 'Africa/Lagos', 'status' => 'scheduled',
            'meet_url'     => 'https://meet.google.com/zzz-yyyy-xxx',
            'panel_json'   => json_encode([1, 2]),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $ics = (string) $this->fileOfMime($this->invite()[0], 'text/calendar')['body'];

        $this->assertSame(1, substr_count($ics, 'BEGIN:VCALENDAR'),
            'several calendars in one file is not what any client does something useful with');
        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('Chinelo Umeh', $ics);
    }

    /**
     * And it is THEIR schedule.
     *
     * The panel is a JSON array on each sitting, so this filters in PHP — a LIKE against
     * the column would match judge 1 inside judge 13, and hand one judge another's diary.
     */
    public function test_a_judge_only_gets_their_own_sittings(): void
    {
        DB::table('gates_nominees')->insert([
            'id' => 2, 'category_id' => 1, 'name' => 'Chidi Eze', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
        DB::table('gates_interviews')->insert([
            'nominee_id' => 2, 'scheduled_at' => date('Y-m-d H:i:s', strtotime('+5 days')),
            'timezone' => 'Africa/Lagos', 'status' => 'scheduled',
            'panel_json' => json_encode([2]), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $mine   = InterviewService::scheduleCsv(1);
        $theirs = InterviewService::scheduleCsv(2);

        $this->assertStringNotContainsString('Chidi Eze', $mine,
            'a judge was handed a sitting they are not on');
        $this->assertStringContainsString('Chidi Eze', $theirs);
        $this->assertStringContainsString('Ada Obi', $theirs, 'this judge is on both');
    }

    /** A judge on nothing gets a header and no rows, not a broken file. */
    public function test_a_judge_with_no_sittings_gets_an_empty_sheet(): void
    {
        $csv = InterviewService::scheduleCsv(999);

        $this->assertStringContainsString('Nominee', $csv);
        $this->assertStringNotContainsString('Ada Obi', $csv);
    }

    /** A cancelled sitting is not something to turn up to. */
    public function test_cancelled_sittings_are_left_off(): void
    {
        DB::table('gates_interviews')->where('id', $this->ivId)->update(['status' => 'cancelled']);

        $this->assertStringNotContainsString('Ada Obi', InterviewService::scheduleCsv(1));
    }
}
