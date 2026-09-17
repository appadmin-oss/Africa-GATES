<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\InterviewService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Somebody in the meeting who is not on the judging panel.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT COULD NOT BE DONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The Google event's attendee list was built from ONE source — `array_column($d['panel'],
 * 'email')`, the judges assigned to the sitting. So the only way to put a person in the
 * call was to APPOINT THEM TO THE JUDGING PANEL: an integrity decision with a published
 * consequence (they appear on "Meet the Judges", and their scores count toward a result),
 * and a wildly disproportionate thing to do so that an interpreter, a note-taker or the
 * nominee's own support person can attend.
 *
 * There was no field anywhere in the console to type an address into.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND ON "CO-HOST"
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Calendar's Events resource has no co-host field — co-host is a Meet concept the host
 * grants inside the call. What an event CAN express is the part people usually want:
 * invited (so Meet admits them rather than making them knock) and, optionally,
 * `guestsCanModify`. Both are stored here; the screen states the limit rather than
 * implying otherwise.
 */
final class InterviewGuestsTest extends TestCase
{
    private int $iv = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $nominee = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 1, 'name' => 'Ada Nwosu', 'status' => 'approved', 'vote_count' => 0,
        ]);
        $this->iv = (int) DB::table('gates_interviews')->insertGetId([
            'nominee_id' => $nominee, 'status' => 'confirmed',
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('+3 days')),
            'duration_mins' => 30, 'timezone' => 'Africa/Lagos',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE THING THAT WAS IMPOSSIBLE
    // ════════════════════════════════════════════════════════════════════════

    public function test_an_external_address_can_be_added_without_appointing_a_judge(): void
    {
        $before = DB::table('gates_judges')->count();

        $r = InterviewService::setGuests($this->iv, 'interpreter@example.org');

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertSame(['interpreter@example.org'], InterviewService::guests($this->iv));
        $this->assertSame($before, DB::table('gates_judges')->count(),
            'putting somebody in a meeting must not put them on the judging panel');
    }

    /**
     * A list of addresses arrives PASTED far more often than typed one at a time.
     *
     * A field that accepts only one separator silently keeps the first address and drops
     * the rest, and the operator has no way to notice until nobody turns up.
     */
    public function test_addresses_pasted_with_any_separator_are_all_kept(): void
    {
        $r = InterviewService::setGuests($this->iv,
            "one@example.org, two@example.org;three@example.org\nfour@example.org  five@example.org");

        $this->assertTrue($r['ok']);
        $this->assertCount(5, $r['guests']);
    }

    /** Case-folded and deduplicated, so one person is not invited twice. */
    public function test_the_same_person_typed_twice_is_one_guest(): void
    {
        $r = InterviewService::setGuests($this->iv, 'Sam@Example.org, sam@example.org');

        $this->assertSame(['sam@example.org'], $r['guests']);
    }

    /**
     * A rejected address is NAMED, never quietly dropped.
     *
     * Somebody who mistypes one and is told "saved" discovers it when a person does not
     * turn up to the interview — which is the one moment nobody can fix it.
     */
    public function test_a_mistyped_address_is_named_rather_than_silently_dropped(): void
    {
        $r = InterviewService::setGuests($this->iv, 'good@example.org, notanemail');

        $this->assertTrue($r['ok']);
        $this->assertSame(['good@example.org'], $r['guests']);
        $this->assertSame(['notanemail'], $r['rejected']);
        $this->assertStringContainsString('notanemail', $r['message']);
        $this->assertStringContainsString('NOT saved', $r['message']);
    }

    /** An attendee list is not a mailing list. */
    public function test_the_guest_list_is_capped(): void
    {
        $many = [];
        for ($i = 0; $i <= InterviewService::MAX_GUESTS; $i++) $many[] = "p{$i}@example.org";

        $r = InterviewService::setGuests($this->iv, implode(',', $many));

        $this->assertFalse($r['ok']);
        $this->assertSame([], InterviewService::guests($this->iv), 'a rejected list was partly saved');
    }

    public function test_an_empty_list_clears_the_guests(): void
    {
        InterviewService::setGuests($this->iv, 'someone@example.org');

        $r = InterviewService::setGuests($this->iv, '  ');

        $this->assertTrue($r['ok']);
        $this->assertSame([], InterviewService::guests($this->iv));
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND THE NEAREST THING TO A CO-HOST
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Edit rights are OFF unless asked for.
     *
     * An invitation is what everybody in the list needs; the ability to move or delete the
     * appointment is not, and a default that hands it out is the wrong way round.
     */
    public function test_guests_cannot_edit_the_appointment_by_default(): void
    {
        InterviewService::setGuests($this->iv, 'someone@example.org');

        $this->assertFalse((bool) InterviewService::detail($this->iv)['guests_can_edit']);

        InterviewService::setGuests($this->iv, 'someone@example.org', true);
        $this->assertTrue((bool) InterviewService::detail($this->iv)['guests_can_edit']);
    }

    /**
     * Saving guests must not touch the appointment, the confirmation or the reminders.
     *
     * The guest list changes for reasons that have nothing to do with the booking — an
     * interpreter confirmed three days later. Folding it into "reschedule" would clear the
     * nominee's confirmation and re-queue both reminders every time somebody added a
     * note-taker.
     */
    public function test_saving_guests_does_not_disturb_the_appointment(): void
    {
        DB::table('gates_interviews')->where('id', $this->iv)
            ->update(['confirmed_at' => '2026-01-01 09:00:00']);
        $before = DB::table('gates_interviews')->where('id', $this->iv)->first();

        InterviewService::setGuests($this->iv, 'late-addition@example.org');
        $after = DB::table('gates_interviews')->where('id', $this->iv)->first();

        $this->assertSame($before->scheduled_at, $after->scheduled_at);
        $this->assertSame($before->confirmed_at, $after->confirmed_at,
            'adding a note-taker cleared the nominee\'s confirmation');
        $this->assertSame($before->status, $after->status);
    }

    /** And the panel is still separate from the guests — two different lists. */
    public function test_the_panel_and_the_guest_list_stay_separate(): void
    {
        InterviewService::setGuests($this->iv, 'interpreter@example.org');

        $d = InterviewService::detail($this->iv);

        $this->assertSame(['interpreter@example.org'], $d['guests']);
        $this->assertNotContains('interpreter@example.org',
            array_column($d['panel'], 'email'),
            'a guest was recorded as a judge');
    }
}
