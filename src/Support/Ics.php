<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * An iCalendar (RFC 5545) entry, for "add this to my calendar".
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * A paid seat that goes unused is usually not a change of mind. It is somebody who read
 * the date on a confirmation email, meant to put it in their calendar, and did not — so on
 * the day nothing reminds them and they are somewhere else. That is money already taken
 * and a chair nobody sits in, and the fix is one link.
 *
 * ── WHY IT IS BUILT BY HAND, AND CAREFULLY ───────────────────────────────────
 *
 * There is no calendar library available on the host, and iCalendar is a format where
 * "nearly right" fails in ways that are hard to see:
 *
 *   • LINES MUST END CRLF, and must be FOLDED at 75 octets with a leading space on the
 *     continuation. Outlook rejects a file with bare LF outright. A long description that
 *     is not folded is silently truncated by some clients — the entry appears, so nobody
 *     investigates, and half the joining instructions are missing.
 *   • FOLDING COUNTS OCTETS, NOT CHARACTERS, and a fold placed inside a multi-byte
 *     sequence produces mojibake in the middle of a venue name. Naira signs and accented
 *     names make that a certainty here, not a curiosity.
 *   • AN ALL-DAY DTEND IS EXCLUSIVE. Setting it to the same date as DTSTART yields an
 *     entry that Google Calendar shows as zero-length or omits, which is the classic
 *     one-day-short bug.
 *   • UID MUST BE STABLE. Re-download the file after a detail changes and a client with
 *     the same UID UPDATES its entry; with a fresh UID it adds a SECOND one, and the
 *     attendee now has two conflicting entries and no way to tell which is current.
 *   • TEXT VALUES ESCAPE `\` `;` `,` and newlines. An unescaped comma in a venue address
 *     ends the value early, so "12 Awolowo Road, Ikoyi" arrives as "12 Awolowo Road".
 *
 * ── AND THE TIMES ARE CONVERTED, NOT COPIED ──────────────────────────────────
 *
 * Stored datetimes in this schema carry no offset and are by convention in
 * {@see Clock::timezone()} (UTC unless an operator set APP_TIMEZONE). A calendar client
 * reads a naked `20260815T190000` as the READER's local time, so copying the stored
 * string straight through puts an 7pm Lagos ceremony at 7pm in whatever city the attendee
 * happens to be in. Everything here is emitted as UTC with a trailing `Z`.
 *
 * The one exception is a date with no time at all, which this treats as an all-day entry
 * rather than inventing midnight — an event whose time is still to be confirmed should not
 * claim to start at 00:00.
 */
final class Ics
{
    public const MIME = 'text/calendar; charset=utf-8';

    /**
     * How long an entry lasts when the event has no end date.
     *
     * Three hours, not one: these are ceremonies and summits, and an entry that ends too
     * early is an entry that the attendee's calendar shows something else on top of.
     */
    public const DEFAULT_HOURS = 3;

    /** How far ahead the reminder fires, for clients that honour VALARM. */
    public const REMIND_MINUTES = 120;

    private const CRLF = "\r\n";

    /** RFC 5545 §3.1: 75 octets, excluding the CRLF. */
    private const FOLD_AT = 75;

    /**
     * Build a complete single-entry calendar.
     *
     * @param array{
     *   uid?: string, title?: string, description?: string, location?: string,
     *   url?: string, starts_at?: ?string, ends_at?: ?string, all_day?: bool,
     *   remind?: bool
     * } $spec
     * @return string|null null when there is no usable start — a calendar entry with no
     *                     date is worse than no link, because it imports as "today".
     */
    public static function event(array $spec): ?string
    {
        $start = trim((string) ($spec['starts_at'] ?? ''));
        if ($start === '') {
            return null;
        }

        try {
            $from = new \DateTimeImmutable($start, new \DateTimeZone(Clock::timezone()));
        } catch (\Throwable) {
            return null;
        }

        // A stored value with no time component means "the day is set, the time is not".
        // Honour that as an all-day entry rather than asserting midnight.
        $allDay = (bool) ($spec['all_day'] ?? self::looksLikeADateAlone($start));

        $end = trim((string) ($spec['ends_at'] ?? ''));
        $to  = null;
        if ($end !== '') {
            try {
                $to = new \DateTimeImmutable($end, new \DateTimeZone(Clock::timezone()));
            } catch (\Throwable) {
                $to = null;
            }
        }
        // An end at or before the start is data entry, not a zero-length event.
        if ($to !== null && $to <= $from) {
            $to = null;
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            // Vendor identifier. Required, and it is what appears in a client's "imported
            // from" line, so it names the platform rather than a library.
            'PRODID:-//Africa GATES//Events//EN',
            'CALSCALE:GREGORIAN',
            // PUBLISH, not REQUEST: this is an entry somebody is adding to their own
            // calendar, not an invitation. REQUEST makes clients offer to RSVP to us, and
            // then send acceptances to an address that is not expecting them.
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . self::text((string) ($spec['uid'] ?? self::uid($start))),
            'DTSTAMP:' . self::utcStamp(new \DateTimeImmutable('now', new \DateTimeZone(Clock::timezone()))),
            'SEQUENCE:0',
            'STATUS:CONFIRMED',
            'TRANSP:OPAQUE',
        ];

        if ($allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $from->format('Ymd');
            // EXCLUSIVE. The day after the last day the event covers.
            $last = $to ?? $from;
            $lines[] = 'DTEND;VALUE=DATE:' . $last->modify('+1 day')->format('Ymd');
        } else {
            $lines[] = 'DTSTART:' . self::utcStamp($from);
            $lines[] = 'DTEND:' . self::utcStamp($to ?? $from->modify('+' . self::DEFAULT_HOURS . ' hours'));
        }

        $title = self::oneLine((string) ($spec['title'] ?? ''));
        $lines[] = 'SUMMARY:' . self::text($title !== '' ? $title : 'Africa GATES event');

        $where = self::oneLine((string) ($spec['location'] ?? ''));
        if ($where !== '') {
            $lines[] = 'LOCATION:' . self::text($where);
        }

        $body = trim((string) ($spec['description'] ?? ''));
        if ($body !== '') {
            $lines[] = 'DESCRIPTION:' . self::text($body);
        }

        $url = trim((string) ($spec['url'] ?? ''));
        // Only an absolute http(s) URL. A relative one is meaningless in a file that will
        // be opened by a mail client on a phone, and anything else is a scheme a calendar
        // has no business launching.
        if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
            $lines[] = 'URL:' . self::text($url);
        }

        if (($spec['remind'] ?? true) && !$allDay) {
            // Clients differ on whether they honour an imported alarm; the ones that do
            // save somebody the step they would otherwise forget.
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:' . self::text($title !== '' ? $title : 'Africa GATES event');
            $lines[] = 'TRIGGER:-PT' . self::REMIND_MINUTES . 'M';
            $lines[] = 'END:VALARM';
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        $out = '';
        foreach ($lines as $line) {
            $out .= self::fold($line) . self::CRLF;
        }
        return $out;
    }

    /**
     * A stable, globally unique identifier. Seed it with something that identifies the
     * thing rather than the moment of download — see the note on UID above.
     */
    public static function uid(string $seed): string
    {
        $host = (string) parse_url(SiteUrl::base(), PHP_URL_HOST);
        if ($host === '') {
            $host = 'africa-gates.invalid';
        }
        // Slug::make(), not a negated ASCII class: that expression DELETES accented letters
        // rather than folding them, so an event called "Àdìrẹ Summit" becomes "d-r-summit".
        // SlugTest guards against reintroducing it anywhere in src/, and it is right to —
        // most of the names on this platform have characters in them that a bare
        // `[^A-Za-z0-9]` throws away.
        $slug = Slug::make($seed, 120);
        return ($slug !== '' ? $slug : 'entry') . '@' . $host;
    }

    /**
     * A safe download filename. Not decorative: a Content-Disposition carrying a quote or
     * a newline is a header injection, and a name with no `.ics` opens in a text editor on
     * Windows instead of the calendar.
     */
    public static function filename(string $stem): string
    {
        // Slug::make() folds accents rather than deleting them, so a Lagos event whose slug
        // carries them downloads as `adire-summit.ics` and not `d-r-summit.ics`.
        $slug = Slug::make($stem, 80);
        return ($slug !== '' ? $slug : 'event') . '.ics';
    }

    // ── the fiddly parts ─────────────────────────────────────────────────────

    /** Escape a TEXT value: RFC 5545 §3.3.11. */
    public static function text(string $v): string
    {
        // Control characters first: a stray \r or \t inside a value produces a file that
        // some parsers stop reading at, mid-entry.
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $v = str_replace('\\', '\\\\', $v);
        $v = str_replace([';', ','], ['\\;', '\\,'], $v);
        return str_replace("\n", '\\n', $v);
    }

    /** Collapse whitespace — SUMMARY and LOCATION are single-line values by nature. */
    public static function oneLine(string $v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $v));
    }

    /**
     * Fold to 75 OCTETS per line, never inside a multi-byte character.
     *
     * The continuation marker is a single space, and it counts towards the next line's 75 —
     * hence FOLD_AT - 1 for every line after the first.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_AT) {
            return $line;
        }

        $out   = '';
        $chunk = '';
        $limit = self::FOLD_AT;
        // Split into characters, not bytes, then measure the bytes. mb_str_split keeps
        // each multi-byte sequence intact, which is the whole point.
        $chars = mb_str_split($line, 1, 'UTF-8');

        foreach ($chars as $ch) {
            if (strlen($chunk) + strlen($ch) > $limit) {
                $out  .= ($out === '' ? '' : self::CRLF . ' ') . $chunk;
                $chunk = '';
                $limit = self::FOLD_AT - 1;   // the leading space occupies one octet
            }
            $chunk .= $ch;
        }
        if ($chunk !== '') {
            $out .= ($out === '' ? '' : self::CRLF . ' ') . $chunk;
        }
        return $out;
    }

    /** `20260815T180000Z` — converted to UTC, never the stored string copied through. */
    public static function utcStamp(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * True when a stored value names a day and no time. `2026-08-15` obviously, but also
     * `2026-08-15 00:00:00`, which is what a date-only admin form writes into a DATETIME
     * column — treating that as "starts at midnight" is how an event ends up in the
     * calendar the night before.
     */
    private static function looksLikeADateAlone(string $stored): bool
    {
        $s = trim($stored);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
            return true;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]00:00(:00(\.0+)?)?Z?$/', $s) === 1;
    }
}
