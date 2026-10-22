<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Ics;
use Tests\TestCase;

/**
 * The calendar file.
 *
 * Every assertion here is a failure mode that shows up in somebody's calendar and nowhere
 * else — no exception, no log line, just an entry on the wrong day or with half a venue
 * name. That is why the format details are asserted rather than trusted.
 */
final class IcsTest extends TestCase
{
    /** @return list<string> the unfolded logical lines of a built calendar */
    private function lines(string $ics): array
    {
        // Unfold first: a value split across two physical lines is still one value, and a
        // test that greps the raw text finds nothing when folding kicks in.
        $flat = str_replace("\r\n ", '', $ics);
        return array_values(array_filter(explode("\r\n", $flat), static fn ($l) => $l !== ''));
    }

    private function value(string $ics, string $prop): ?string
    {
        foreach ($this->lines($ics) as $line) {
            if (str_starts_with($line, $prop . ':') || str_starts_with($line, $prop . ';')) {
                return substr($line, (int) strpos($line, ':') + 1);
            }
        }
        return null;
    }

    private function base(): array
    {
        return [
            'uid'       => 'event-1@afg.test',
            'title'     => 'Africa GATES Summit',
            'starts_at' => '2026-09-18 17:30:00',
            'ends_at'   => '2026-09-18 21:00:00',
            'location'  => 'Eko Hotel, Lagos',
        ];
    }

    // ══ 1. the envelope ══════════════════════════════════════════════════════

    public function test_it_is_a_well_formed_single_entry_calendar(): void
    {
        $ics = Ics::event($this->base());
        $this->assertIsString($ics);

        $lines = $this->lines($ics);
        $this->assertSame('BEGIN:VCALENDAR', $lines[0]);
        $this->assertSame('END:VCALENDAR', $lines[count($lines) - 1]);
        $this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertSame(1, substr_count($ics, 'END:VEVENT'));
        $this->assertNotNull($this->value($ics, 'VERSION'));
        $this->assertNotNull($this->value($ics, 'PRODID'));
        $this->assertNotNull($this->value($ics, 'DTSTAMP'));
    }

    public function test_every_line_ends_crlf(): void
    {
        // Not cosmetic. Outlook refuses a calendar file with bare LF line endings, so this
        // is the difference between "add to calendar" working and doing nothing at all.
        $ics = (string) Ics::event($this->base());
        $this->assertStringEndsWith("\r\n", $ics);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $ics),
            'a line ends with a bare LF, which Outlook rejects');
    }

    public function test_it_publishes_rather_than_inviting(): void
    {
        // METHOD:REQUEST turns the file into a meeting invitation: clients then offer to
        // RSVP, and send acceptances to an address that is not listening for them.
        $ics = (string) Ics::event($this->base());
        $this->assertSame('PUBLISH', $this->value($ics, 'METHOD'));
        $this->assertStringNotContainsString('ORGANIZER', $ics);
        $this->assertStringNotContainsString('ATTENDEE', $ics);
    }

    // ══ 2. the times ═════════════════════════════════════════════════════════

    public function test_times_are_converted_to_utc_and_marked_as_such(): void
    {
        // A naked local timestamp is read as the READER's local time, which puts a 17:30
        // Lagos ceremony at 17:30 in whatever city the attendee is in.
        $ics = (string) Ics::event($this->base());
        $this->assertSame('20260918T173000Z', $this->value($ics, 'DTSTART'));
        $this->assertSame('20260918T210000Z', $this->value($ics, 'DTEND'));
    }

    public function test_an_event_with_no_end_gets_a_sensible_duration(): void
    {
        $spec = $this->base();
        unset($spec['ends_at']);
        $ics = (string) Ics::event($spec);

        $this->assertSame('20260918T173000Z', $this->value($ics, 'DTSTART'));
        $this->assertSame('20260918T203000Z', $this->value($ics, 'DTEND'),
            'default duration changed — an entry that ends too early gets buried');
    }

    public function test_an_end_before_the_start_is_treated_as_missing(): void
    {
        // Data entry, not a zero-length event. A DTEND before DTSTART is invalid and some
        // clients drop the entry silently.
        $spec = ['ends_at' => '2026-09-18 09:00:00'] + $this->base();
        $ics  = (string) Ics::event($spec);
        $this->assertSame('20260918T203000Z', $this->value($ics, 'DTEND'));
    }

    public function test_a_date_with_no_time_becomes_an_all_day_entry(): void
    {
        // An event whose time is still to be confirmed must not claim to start at midnight.
        foreach (['2026-09-18', '2026-09-18 00:00:00'] as $stored) {
            $ics = (string) Ics::event(['starts_at' => $stored, 'title' => 'X', 'uid' => 'u@t']);
            $this->assertStringContainsString('DTSTART;VALUE=DATE:20260918', $ics, "for {$stored}");
        }
    }

    public function test_an_all_day_end_date_is_exclusive(): void
    {
        // The classic one-day-short bug: DTEND equal to DTSTART shows as zero-length, or is
        // dropped, in Google Calendar.
        $ics = (string) Ics::event(['starts_at' => '2026-09-18', 'title' => 'X', 'uid' => 'u@t']);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260919', $ics);

        $two = (string) Ics::event([
            'starts_at' => '2026-09-18', 'ends_at' => '2026-09-19',
            'title' => 'X', 'uid' => 'u@t',
        ]);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260920', $two);
    }

    public function test_an_all_day_entry_carries_no_alarm(): void
    {
        // "Two hours before" a date with no time means two hours before midnight, which
        // fires the night before for no reason anybody can see.
        $ics = (string) Ics::event(['starts_at' => '2026-09-18', 'title' => 'X', 'uid' => 'u@t']);
        $this->assertStringNotContainsString('BEGIN:VALARM', $ics);
    }

    public function test_a_timed_entry_carries_a_reminder_that_can_be_turned_off(): void
    {
        $ics = (string) Ics::event($this->base());
        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('TRIGGER:-PT' . Ics::REMIND_MINUTES . 'M', $ics);

        $quiet = (string) Ics::event(['remind' => false] + $this->base());
        $this->assertStringNotContainsString('BEGIN:VALARM', $quiet);
    }

    // ══ 3. escaping and folding ══════════════════════════════════════════════

    public function test_a_comma_in_a_venue_survives(): void
    {
        // Unescaped, "12 Awolowo Road, Ikoyi" arrives as "12 Awolowo Road" — the value ends
        // at the comma and the rest is read as another parameter.
        $ics = (string) Ics::event(['location' => '12 Awolowo Road, Ikoyi'] + $this->base());
        $this->assertSame('12 Awolowo Road\\, Ikoyi', $this->value($ics, 'LOCATION'));
    }

    public function test_semicolons_and_backslashes_are_escaped(): void
    {
        $ics = (string) Ics::event(['location' => 'Hall A; Gate 3 \\ West'] + $this->base());
        $this->assertSame('Hall A\\; Gate 3 \\\\ West', $this->value($ics, 'LOCATION'));
    }

    public function test_newlines_in_a_description_become_escaped_breaks(): void
    {
        $ics = (string) Ics::event(['description' => "Park at the back.\n\nAsk for Ada."] + $this->base());
        $this->assertSame('Park at the back.\\n\\nAsk for Ada.', $this->value($ics, 'DESCRIPTION'));
        // And the raw file must not contain a real break inside the value, which would end it.
        $this->assertStringNotContainsString("Park at the back.\r\nAsk", $ics);
    }

    public function test_control_characters_are_stripped(): void
    {
        // A stray \x00 or \x7F inside a value makes some parsers stop reading mid-entry.
        // Asserted on DESCRIPTION rather than SUMMARY because SUMMARY is also collapsed as
        // one line, and a form feed is legitimately whitespace — it becomes a space there
        // rather than vanishing, which is the right answer for a title.
        $ics = (string) Ics::event(['description' => "Ga\x00te\x7F 3"] + $this->base());
        $this->assertSame('Gate 3', $this->value($ics, 'DESCRIPTION'));

        // And nothing anywhere in the finished file, whatever a caller passed in.
        $wild = (string) Ics::event([
            'title'    => "Sum\x0Cmit\x7F",
            'location' => "Hall\x01A",
        ] + $this->base());
        $this->assertSame(0, preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $wild),
            'a control character survived into the file');
    }

    public function test_a_title_is_collapsed_to_one_line(): void
    {
        $ics = (string) Ics::event(['title' => "Africa   GATES\n  Summit "] + $this->base());
        $this->assertSame('Africa GATES Summit', $this->value($ics, 'SUMMARY'));
    }

    public function test_long_lines_are_folded_at_seventy_five_octets(): void
    {
        $ics = (string) Ics::event([
            'description' => str_repeat('Parking is behind the hall. ', 20),
        ] + $this->base());

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line),
                'an unfolded line over 75 octets is silently truncated by some clients');
        }
        // And every continuation is marked with the single leading space, or the unfold on
        // the other side produces one run-together word.
        $this->assertStringContainsString("\r\n ", $ics);
    }

    public function test_folding_never_splits_a_multibyte_character(): void
    {
        // Naira signs and accented venue names make this a certainty, not a curiosity: a
        // fold inside a UTF-8 sequence is mojibake in the middle of an address.
        $long = str_repeat('Àdìrẹ Hall — ₦5,000 · ', 12);
        $ics  = (string) Ics::event(['description' => $long] + $this->base());

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
        // Unfolding must give back exactly what went in, escaping aside.
        $got = $this->value($ics, 'DESCRIPTION');
        $this->assertSame(str_replace(',', '\\,', trim($long)), trim((string) $got));
        $this->assertTrue((bool) preg_match('//u', $ics), 'the file is no longer valid UTF-8');
    }

    // ══ 4. identity and refusals ═════════════════════════════════════════════

    public function test_the_uid_is_stable_across_downloads(): void
    {
        // Re-downloading with the same UID UPDATES a client's entry. With a fresh one it adds
        // a second, and the attendee now holds two entries and cannot tell which is current.
        $a = $this->value((string) Ics::event($this->base()), 'UID');
        $b = $this->value((string) Ics::event($this->base()), 'UID');
        $this->assertSame($a, $b);
        $this->assertNotSame('', (string) $a);
    }

    public function test_a_uid_is_host_qualified_and_stripped_of_anything_odd(): void
    {
        $uid = Ics::uid('ticket-AFG EVT/0001');
        $this->assertStringContainsString('@', $uid);
        $this->assertSame(1, substr_count($uid, '@'));
        $this->assertMatchesRegularExpression('/^[a-z0-9._-]+@[A-Za-z0-9.\-]+$/', $uid);
    }

    public function test_no_start_means_no_file(): void
    {
        // An entry with no date imports as "today", which is worse than no link at all.
        $this->assertNull(Ics::event(['title' => 'X']));
        $this->assertNull(Ics::event(['title' => 'X', 'starts_at' => '']));
        $this->assertNull(Ics::event(['title' => 'X', 'starts_at' => 'not a date']));
    }

    public function test_an_untitled_event_still_produces_a_usable_entry(): void
    {
        $ics = (string) Ics::event(['starts_at' => '2026-09-18 17:30:00', 'uid' => 'u@t']);
        $this->assertSame('Africa GATES event', $this->value($ics, 'SUMMARY'));
    }

    public function test_empty_optional_fields_are_omitted_rather_than_emitted_blank(): void
    {
        // `remind` off so the assertion means what it says: a VALARM carries its own
        // DESCRIPTION (the title, so the reminder is legible on a lock screen), and a naive
        // grep for DESCRIPTION finds that one instead of the event's.
        $ics = (string) Ics::event([
            'starts_at' => '2026-09-18 17:30:00', 'title' => 'X', 'uid' => 'u@t',
            'location' => '  ', 'description' => '', 'url' => '', 'remind' => false,
        ]);
        $this->assertNull($this->value($ics, 'LOCATION'));
        $this->assertNull($this->value($ics, 'DESCRIPTION'));
        $this->assertNull($this->value($ics, 'URL'));

        // The alarm's own description is still there when the reminder is on — that is not
        // the event's DESCRIPTION leaking, and it is deliberately not omitted.
        $withAlarm = (string) Ics::event([
            'starts_at' => '2026-09-18 17:30:00', 'title' => 'X', 'uid' => 'u@t',
        ]);
        $this->assertStringContainsString("BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:X", $withAlarm);
    }

    public function test_only_an_absolute_web_url_is_emitted(): void
    {
        // A relative URL is meaningless in a file opened by a mail client, and a javascript:
        // or file: URL is a scheme a calendar has no business launching.
        foreach (['/events/x', 'javascript:alert(1)', 'file:///etc/passwd', 'mailto:a@b.c'] as $bad) {
            $ics = (string) Ics::event(['url' => $bad] + $this->base());
            $this->assertNull($this->value($ics, 'URL'), "emitted a URL for {$bad}");
        }
        $ok = (string) Ics::event(['url' => 'https://afg.test/events/x'] + $this->base());
        $this->assertSame('https://afg.test/events/x', $this->value($ok, 'URL'));
    }

    // ══ 5. the filename ══════════════════════════════════════════════════════

    public function test_the_filename_cannot_break_out_of_the_header(): void
    {
        // A Content-Disposition carrying a quote or a newline is header injection.
        $name = Ics::filename("gates\r\nX-Evil: 1\"summit");
        $this->assertStringEndsWith('.ics', $name);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.ics$/', $name);
    }

    public function test_the_filename_always_ends_ics(): void
    {
        // Without the extension, Windows opens it in a text editor rather than the calendar.
        $this->assertSame('event.ics', Ics::filename('   '));
        $this->assertSame('event.ics', Ics::filename('!!!'));
        $this->assertSame('gates-summit-2026.ics', Ics::filename('GATES Summit 2026'));
        $this->assertLessThanOrEqual(84, strlen(Ics::filename(str_repeat('long', 60))));
    }
}
