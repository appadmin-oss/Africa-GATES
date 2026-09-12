<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GoogleMeetService;
use Tests\TestCase;

/**
 * The calendar sync contract, on both sides of the wire.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS TEST IS SHAPED LIKE THIS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The Apps Script runs on Google's servers and cannot be executed here. So what is testable
 * is the CONTRACT: that PHP sends the action names and field names the script branches on,
 * and that the script contains the branches PHP calls. A mismatch between them is silent —
 * Apps Script answers `{"success":false,"message":"Unknown action"}` with a 200, and DRF-
 * style key dropping means a renamed FIELD produces a success with nothing done.
 *
 * That is not hypothetical here. `meet.create` shipped sending `webhook_url` to Attendee,
 * a field that does not exist; every call succeeded and no callback was ever registered.
 * This test exists so the same class of drift cannot happen between these two files.
 */
final class CalendarSyncContractTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/config/AfricaGATES_AppScript.gs');
    }

    private function php(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/GoogleMeetService.php');
    }

    // ══ both sides agree on the actions ══════════════════════════════════════

    public function test_every_action_php_calls_is_a_branch_in_the_script(): void
    {
        $php    = $this->php();
        $script = $this->script();

        preg_match_all("~\\\$this->call\('([a-z.]+)'~", $php, $m);
        $called = array_values(array_unique($m[1]));

        $this->assertNotSame([], $called, 'the matcher stopped matching — fix it, do not delete it');

        foreach ($called as $action) {
            $this->assertStringContainsString("action === '{$action}'", $script,
                "PHP calls '{$action}' and the script has no branch for it — Apps Script answers "
                . '"Unknown action" with a 200, so this fails silently');
        }
    }

    public function test_the_four_calendar_actions_are_all_wired(): void
    {
        $script = $this->script();

        foreach (['calendar.sync' => 'calendarSync',
                  'calendar.cancel' => 'calendarCancel',
                  'calendar.freebusy' => 'calendarFreeBusy',
                  'calendar.slots' => 'calendarSlots'] as $action => $fn) {
            $this->assertStringContainsString("action === '{$action}'", $script);
            $this->assertStringContainsString("function {$fn}(", $script,
                "{$action} is routed to {$fn}() which does not exist");
        }
    }

    /** The privileged actions must sit behind the shared secret, not beside it. */
    public function test_the_calendar_actions_are_inside_the_secret_gate(): void
    {
        $script = $this->script();

        $gate = strpos($script, "if(body.token !== SECRET) return respond(false,'Bad token');");
        $sync = strpos($script, "action === 'calendar.sync'");

        $this->assertNotFalse($gate, 'the token check moved — this test is now checking nothing');
        $this->assertNotFalse($sync);
        $this->assertLessThan($sync, $gate,
            'booking events in somebody\'s calendar must not be reachable without the secret');
    }

    // ══ both sides agree on the field names ══════════════════════════════════

    /**
     * The script reads camelCase (`startIso`, `withMeet`); PHP is snake_case everywhere
     * else. Every one of these is a field a rename would silently drop.
     */
    public function test_the_sync_payload_fields_are_the_ones_the_script_reads(): void
    {
        $script = $this->script();

        foreach (['d.key', 'd.startIso', 'd.endIso', 'd.timezone', 'd.guests',
                  'd.notify', 'd.withMeet', 'd.calendarId'] as $field) {
            $this->assertStringContainsString($field, $script,
                "calendarSync() does not read {$field}, but PHP sends it");
        }

        $php = $this->php();
        foreach (["'key'", "'startIso'", "'endIso'", "'withMeet'", "'calendarId'"] as $field) {
            $this->assertStringContainsString($field, $php);
        }
    }

    public function test_the_slots_payload_fields_are_the_ones_the_script_reads(): void
    {
        $script = $this->script();

        foreach (['d.fromIso', 'd.toIso', 'd.minutes', 'd.gapMinutes', 'd.dayStart',
                  'd.dayEnd', 'd.days', 'd.leadMinutes', 'd.max'] as $field) {
            $this->assertStringContainsString($field, $script,
                "calendarSlots() does not read {$field}, but PHP sends it");
        }
    }

    // ══ the properties the design rests on ═══════════════════════════════════

    /**
     * THE ONE THAT MATTERS. Idempotency comes from a stable key stamped on the event, not
     * from the conference uuid — which must stay unique or Google reuses the last Meet.
     */
    public function test_sync_finds_by_a_stable_key_before_inserting(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('agatesKey', $script,
            'without a stable key on the event, a second run duplicates the sitting');
        $this->assertStringContainsString("privateExtendedProperty: 'agatesKey='", $script,
            'the key has to be SEARCHABLE, or storing it achieves nothing');
        $this->assertStringContainsString('const existing = findByKey(', $script,
            'sync must look before it inserts');
        $this->assertStringContainsString('Utilities.getUuid()', $script,
            'the conference requestId must stay unique per request');
    }

    /**
     * PATCH and not update(): update() replaces the whole resource, dropping the
     * conferenceData a previous run created — so every guest gets a new Meet link for a
     * meeting already in their diary.
     */
    public function test_an_existing_event_is_patched_rather_than_replaced(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('Calendar.Events.patch(', $script);
        $this->assertStringNotContainsString('Calendar.Events.update(', $script,
            'update() would hand every guest a new Meet link for the same meeting');
    }

    /** A cancelled event that matched the search would be resurrected by a re-run. */
    public function test_the_key_search_ignores_deleted_events(): void
    {
        $this->assertStringContainsString('showDeleted: false', $this->script(),
            'a cancelled sitting that returns should be a new event with a new invitation, '
            . 'not a resurrection nobody was told about');
    }

    /** A search failure must not fall through into an insert. */
    public function test_a_failed_key_search_throws_rather_than_duplicating(): void
    {
        $script = $this->script();
        $fn = substr($script, strpos($script, 'function findByKey(') ?: 0);
        $fn = substr($fn, 0, strpos($fn, "\n}\n") ?: strlen($fn));

        $this->assertStringContainsString('throw new Error(', $fn,
            'swallowing a search failure means the next line inserts a duplicate');
    }

    /**
     * Free/busy errors are per-calendar and arrive inside a 200. Treating that as "no busy
     * blocks" would offer every slot in a fully booked week.
     */
    public function test_a_per_calendar_freebusy_error_is_not_read_as_a_free_calendar(): void
    {
        $script = $this->script();
        $fn = substr($script, strpos($script, 'function calendarFreeBusy(') ?: 0);
        $fn = substr($fn, 0, strpos($fn, "\n}\n") ?: strlen($fn));

        $this->assertStringContainsString('cal.errors', $fn,
            'a wrong calendar id returns 200 with an errors array, and no busy blocks');
    }

    /**
     * Day-of-week and time-of-day must be read in the TARGET zone. A script set to one zone
     * offering slots for a calendar in another is how a 9am slot lands at 4am.
     */
    public function test_slot_hours_are_computed_in_the_target_timezone(): void
    {
        $script = $this->script();
        $fn = substr($script, strpos($script, 'function calendarSlots(') ?: 0);

        $this->assertStringContainsString('Utilities.formatDate(start, tz', $fn,
            'using the script timezone would shift every slot for a calendar elsewhere');
        $this->assertStringContainsString("'u'", $fn, 'day-of-week, in the target zone');
    }

    /** A slot fifteen minutes away is one nobody can attend, and it is the one they book. */
    public function test_a_lead_time_is_enforced_and_defaults_to_something_usable(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('leadMinutes', $script);
        $this->assertStringContainsString('Date.now() + lead', $script,
            'the lead time has to be applied to now, not to the window start');
        $this->assertStringContainsString('120', $script, 'two hours is the default');
    }

    // ══ honesty about what this is not ═══════════════════════════════════════

    /**
     * Appointment Schedules have no API at any scope. The code must not imply it reads one,
     * because somebody WILL compare this against their booking page and need to know why
     * they differ.
     */
    public function test_the_appointment_schedule_limitation_is_stated_in_both_files(): void
    {
        // Matched on the CLAIM, not on one phrasing of it — an assertion that breaks when
        // somebody rewords a comment teaches people to delete the assertion.
        foreach ([$this->script(), $this->php()] as $src) {
            $this->assertMatchesRegularExpression(
                '~Appointment Schedule[s]?.{0,120}?(no|NO) API~su', $src,
                'the limitation must be stated where somebody reading this code will see it'
            );
        }

        $this->assertStringContainsString('note:', $this->script(),
            'the response itself should carry the caveat, not only the source — somebody '
            . 'comparing this against their booking page needs to know why they differ');
    }

    // ══ scopes ═══════════════════════════════════════════════════════════════

    /** Freebusy.query needs a read scope; calendar.events alone is not enough for it. */
    public function test_the_manifest_carries_the_scopes_these_actions_need(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/config/appsscript.json'), true
        );

        $this->assertIsArray($manifest);
        $scopes = (array) ($manifest['oauthScopes'] ?? []);

        $this->assertContains('https://www.googleapis.com/auth/calendar.events', $scopes);
        $this->assertContains('https://www.googleapis.com/auth/calendar.readonly', $scopes,
            'Freebusy.query cannot run on calendar.events alone');

        // The advanced service has to be declared, or `Calendar` is undefined at runtime.
        $advanced = array_column((array) ($manifest['dependencies']['enabledAdvancedServices'] ?? []), 'serviceId');
        $this->assertContains('calendar', $advanced);
    }

    // ══ the PHP guards ═══════════════════════════════════════════════════════

    public function test_php_refuses_a_sync_with_no_key_before_making_a_call(): void
    {
        $svc = new GoogleMeetService('', '');
        $r = $svc->syncEvent(['start' => '2026-12-01 10:00:00']);

        $this->assertFalse($r['ok']);
        $this->assertNotSame('', $r['message']);
    }

    public function test_php_refuses_an_unreadable_or_inverted_window(): void
    {
        $svc = new GoogleMeetService('https://script.example/exec', 'secret');

        foreach ([['from' => 'nonsense', 'to' => 'also nonsense'],
                  ['from' => '2026-12-10', 'to' => '2026-12-01'],
                  ['from' => '2026-01-01', 'to' => '2027-06-01']] as $window) {
            $r = $svc->slots($window);
            $this->assertFalse($r['ok'], 'accepted ' . json_encode($window));
            $this->assertSame([], $r['slots']);
        }
    }
}
