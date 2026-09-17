<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventAgenda as A;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The agenda: sessions as rows, grouped into the day somebody reads.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACES, AND WHY IT IS NOT A REWRITE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_site_events.schedule` is a JSON list of {time,title,body} the detail page prints.
 * That is a perfectly good run of show for a two-hour webinar and it STAYS — {@see A::legacy()}
 * — because an event whose agenda is three lines should not need a second screen to say so.
 *
 * It stops working the moment an event has two rooms: a blob cannot be grouped by day,
 * filtered by track, or attached to, and two parallel items at 14:00 read as a contradiction
 * rather than a choice.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. GROUPING HAPPENS IN PHP, NOT TWIG. An undated session in a template silently becomes
 *      1 January 1970, and a multi-day conference printed as one flat list is unreadable.
 *   2. AN UNDATED SESSION IS KEPT, LAST. An organiser types the titles first and the times
 *      later; losing the titles in between makes the editor useless while it is being used.
 *   3. IDS SURVIVE A SAVE. They are what a future feature attaches to, and reissuing them on
 *      every save would silently reassign whatever had been attached.
 *   4. A BLANK TITLE IS AN EMPTY ROW, NOT A SESSION. The editor always renders one spare.
 */
final class EventAgendaTest extends TestCase
{
    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_sessions')->delete();
        DB::table('gates_site_events')->delete();

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'summit-2026', 'title' => 'The Summit',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'status' => 'published', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function save(array $rows): array
    {
        return A::save($this->eventId, $rows);
    }

    private function row(string $title, array $over = []): array
    {
        return array_merge([
            'id' => 0, 'title' => $title, 'description' => '', 'starts_at' => '',
            'ends_at' => '', 'room' => '', 'track' => '', 'speakers' => '',
            'sort_order' => 0, 'is_published' => 1,
        ], $over);
    }

    // ══ 1. grouping ══════════════════════════════════════════════════════════

    public function test_sessions_are_grouped_into_days_in_order(): void
    {
        $this->save([
            $this->row('Closing', ['starts_at' => '2026-03-02 16:00', 'sort_order' => 30]),
            $this->row('Keynote', ['starts_at' => '2026-03-01 09:00', 'sort_order' => 10]),
            $this->row('Workshop', ['starts_at' => '2026-03-01 14:00', 'sort_order' => 20]),
        ]);

        $days = A::days($this->eventId);

        $this->assertCount(2, $days);
        $this->assertSame('2026-03-01', $days[0]['key']);
        $this->assertSame(['Keynote', 'Workshop'], array_column($days[0]['sessions'], 'title'));
        $this->assertSame('2026-03-02', $days[1]['key']);
        $this->assertSame(['Closing'], array_column($days[1]['sessions'], 'title'));
    }

    public function test_a_day_label_is_a_date_a_person_reads(): void
    {
        $this->save([$this->row('Keynote', ['starts_at' => '2026-03-01 09:00'])]);
        // 1 March 2026 is a Sunday.
        $this->assertSame('Sunday 1 March 2026', A::days($this->eventId)[0]['label']);
    }

    public function test_an_undated_session_is_kept_and_sorted_last(): void
    {
        $this->save([
            $this->row('To be confirmed'),
            $this->row('Keynote', ['starts_at' => '2026-03-01 09:00']),
        ]);

        $days = A::days($this->eventId);

        $this->assertCount(2, $days);
        // '' sorts FIRST by string comparison and belongs last: "we have not said when yet" is
        // a footnote to a schedule, not its opening.
        $this->assertSame('2026-03-01', $days[0]['key']);
        $this->assertSame('', $days[1]['key']);
        $this->assertStringContainsStringIgnoringCase('to be confirmed', $days[1]['label']);
        $this->assertSame(['To be confirmed'], array_column($days[1]['sessions'], 'title'));
    }

    public function test_the_time_range_is_one_date_within_a_day_and_two_across_one(): void
    {
        $this->save([
            $this->row('Panel', ['starts_at' => '2026-03-01 14:00', 'ends_at' => '2026-03-01 15:30']),
            $this->row('Vigil', ['starts_at' => '2026-03-01 23:00', 'ends_at' => '2026-03-02 01:00',
                                 'sort_order' => 5]),
            $this->row('Open', ['starts_at' => '2026-03-01 09:00', 'sort_order' => 1]),
        ]);

        $by = [];
        foreach (A::sessions($this->eventId) as $s) $by[$s['title']] = $s['when'];

        $this->assertSame('14:00 – 15:30', $by['Panel']);
        // Across midnight both dates appear, or the reader cannot tell which day it ends on.
        $this->assertSame('1 Mar 23:00 – 2 Mar 01:00', $by['Vigil']);
        $this->assertSame('09:00', $by['Open']);
    }

    // ══ 2. what a session carries ════════════════════════════════════════════

    public function test_speakers_are_split_from_the_line_an_organiser_types(): void
    {
        $this->save([$this->row('Panel', ['speakers' => 'Ada Obi, Chidi Nwosu ,, Ngozi Eze'])]);

        $this->assertSame(['Ada Obi', 'Chidi Nwosu', 'Ngozi Eze'],
                          A::sessions($this->eventId)[0]['speakers']);
    }

    public function test_tracks_are_the_distinct_ones_in_use(): void
    {
        $this->save([
            $this->row('A', ['track' => 'Policy']),
            $this->row('B', ['track' => 'Craft']),
            $this->row('C', ['track' => 'Policy']),
            $this->row('D'),
        ]);

        // Exactly the tracks that exist: a filter with an option that matches nothing is a
        // control that cannot do anything.
        $this->assertSame(['Policy', 'Craft'], A::tracks($this->eventId));
    }

    public function test_an_unpublished_session_is_hidden_from_the_public_and_shown_to_the_editor(): void
    {
        $this->save([
            $this->row('Announced', ['starts_at' => '2026-03-01 09:00']),
            $this->row('Draft', ['starts_at' => '2026-03-01 10:00', 'is_published' => 0]),
        ]);

        $this->assertSame(['Announced'], array_column(A::sessions($this->eventId), 'title'));
        $this->assertSame(['Announced', 'Draft'],
                          array_column(A::sessions($this->eventId, true), 'title'));
    }

    // ══ 3. saving ════════════════════════════════════════════════════════════

    public function test_a_blank_title_is_an_empty_row_not_a_session(): void
    {
        $out = $this->save([$this->row('Keynote'), $this->row('   '), $this->row('')]);

        $this->assertSame(1, $out['saved']);
        $this->assertCount(1, A::sessions($this->eventId, true));
    }

    public function test_ids_survive_a_save_so_anything_attached_stays_attached(): void
    {
        $this->save([$this->row('Keynote'), $this->row('Panel')]);
        $before = A::sessions($this->eventId, true);
        $ids = array_column($before, 'id');

        // The same two rows come back edited, carrying their ids.
        $this->save([
            $this->row('Keynote — revised', ['id' => $ids[0]]),
            $this->row('Panel', ['id' => $ids[1], 'room' => 'Hall B']),
        ]);

        $after = A::sessions($this->eventId, true);
        $this->assertSame($ids, array_column($after, 'id'), 'ids were reissued on save');
        $this->assertSame('Keynote — revised', $after[0]['title']);
        $this->assertSame('Hall B', $after[1]['room']);
    }

    public function test_a_row_the_form_no_longer_lists_is_removed(): void
    {
        $this->save([$this->row('Keynote'), $this->row('Panel')]);
        $ids = array_column(A::sessions($this->eventId, true), 'id');

        $out = $this->save([$this->row('Keynote', ['id' => $ids[0]])]);

        $this->assertSame(1, $out['saved']);
        $this->assertSame(1, $out['removed']);
        $this->assertSame(['Keynote'], array_column(A::sessions($this->eventId, true), 'title'));
    }

    public function test_an_id_from_another_event_cannot_be_hijacked(): void
    {
        $other = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'other', 'title' => 'Other', 'status' => 'published',
            'event_date' => Carbon::now()->addDays(60)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        A::save($other, [$this->row('Theirs')]);
        $theirs = (int) A::sessions($other, true)[0]['id'];

        // Passing their id here must create a NEW row on this event rather than rewriting
        // somebody else's session.
        $this->save([$this->row('Mine', ['id' => $theirs])]);

        $this->assertSame(['Theirs'], array_column(A::sessions($other, true), 'title'));
        $this->assertSame(['Mine'], array_column(A::sessions($this->eventId, true), 'title'));
    }

    public function test_an_unparseable_time_becomes_no_time_rather_than_a_wrong_one(): void
    {
        $this->save([$this->row('Keynote', ['starts_at' => 'sometime next week'])]);

        $s = A::sessions($this->eventId, true)[0];
        $this->assertNull($s['starts_at']);
        $this->assertSame('', $s['when']);
    }

    public function test_a_datetime_local_value_is_accepted_as_typed(): void
    {
        // The T-separated form a browser posts, not a database timestamp.
        $this->save([$this->row('Keynote', ['starts_at' => '2026-03-01T09:30'])]);

        $this->assertSame('2026-03-01 09:30:00', A::sessions($this->eventId, true)[0]['starts_at']);
    }

    // ══ 4. the fallback ══════════════════════════════════════════════════════

    public function test_the_legacy_run_of_show_is_still_read(): void
    {
        $event = (object) ['schedule' => json_encode([
            ['time' => '18:00', 'title' => 'Doors', 'body' => 'Arrival drinks'],
        ])];

        $legacy = A::legacy($event);

        $this->assertCount(1, $legacy);
        $this->assertSame('Doors', $legacy[0]['title']);
    }

    public function test_a_broken_schedule_blob_is_an_empty_agenda_not_an_error(): void
    {
        $this->assertSame([], A::legacy((object) ['schedule' => 'not json at all']));
        $this->assertSame([], A::legacy((object) []));
        $this->assertSame([], A::legacy(null));
    }
}
