<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * What time an event happens, in the zone the event is actually in.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY AN EVENT NEEDS ITS OWN ZONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see DisplayTime} holds ONE zone for the whole platform, and that is right for a
 * deadline: a cycle closes at a moment, announced once, and every nominee on the continent
 * is measured against the same instant.
 *
 * An event is not a deadline. It is a room, in a city, at a wall-clock time — and the only
 * time that matters is the one on the clock in that room. A platform that calls itself
 * continental cannot print a Nairobi gala's start time in Lagos hours because that is where
 * its settings screen happens to point. The guest reads "19:00", arrives at 19:00, and is
 * an hour late for a ceremony held for them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * STORAGE DOES NOT MOVE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Everything stays UTC, exactly as {@see Clock} and {@see DisplayTime} require. This is a
 * second edge, not a second convention: the same stored instant, rendered against the
 * event's own zone instead of the platform's.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TIME AND ITS LABEL TRAVEL TOGETHER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see zoned()} returns the formatted time WITH its abbreviation, and that is the whole
 * point of the shape. The event page printed `{{ event.event_date|date('H:i') }} WAT` —
 * the time from one source and the letters "WAT" typed by hand — which is correct only
 * while nobody changes the platform zone and no event is held outside Lagos. The moment
 * either is untrue the page states a wrong hour with a confident label on it, which is
 * worse than an unlabelled one.
 *
 * So a caller cannot get the time without the label unless it asks for {@see at()}
 * deliberately, and there is no path that produces the label from anywhere but the same
 * event.
 */
final class EventTime
{
    /**
     * The zone an event runs in.
     *
     * Falls back to the platform's display zone, so every event that existed before this
     * column reads exactly as it did — the fallback is the migration.
     */
    public static function zone(object|array|null $event): string
    {
        $e  = $event === null ? [] : (is_array($event) ? $event : (array) $event);
        $tz = trim((string) ($e['timezone'] ?? ''));

        return ($tz !== '' && Clock::isValid($tz)) ? $tz : DisplayTime::zone();
    }

    /** 'WAT', 'EAT', '+02:00' — whatever this event's zone calls itself on the day. */
    public static function abbr(object|array|null $event, string|\DateTimeInterface|null $stored = null): string
    {
        try {
            // Read AT the event's own moment, not at now: a country that observes a summer
            // offset would otherwise label a July gala with January's letters.
            $dt = self::parse($stored) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return $dt->setTimezone(new \DateTimeZone(self::zone($event)))->format('T');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * A stored (UTC) datetime in the event's zone. '' when there is nothing to show.
     *
     * Use {@see zoned()} unless the label genuinely belongs elsewhere on the screen — a
     * bare time is how a wrong hour comes to look authoritative.
     */
    public static function at(object|array|null $event, string|\DateTimeInterface|null $stored,
                              string $format = 'D j M Y, H:i'): string
    {
        $dt = self::parse($stored);

        return $dt === null ? '' : $dt->setTimezone(new \DateTimeZone(self::zone($event)))->format($format);
    }

    /** The time and the zone it is in, as one string. The default for anything a guest reads. */
    public static function zoned(object|array|null $event, string|\DateTimeInterface|null $stored,
                                 string $format = 'D j M Y, H:i'): string
    {
        $out = self::at($event, $stored, $format);
        if ($out === '') return '';

        $abbr = self::abbr($event, $stored);

        return $abbr === '' ? $out : $out . ' ' . $abbr;
    }

    /**
     * A stored datetime as a `datetime-local` value in the event's zone.
     *
     * Pairs with {@see toStored()}. Seconds included for the reason DisplayTime gives:
     * at minute precision the round trip walks a stored 23:59:59 back by 59 seconds every
     * time somebody opens the form and saves without touching the field.
     */
    public static function forInput(object|array|null $event, string|\DateTimeInterface|null $stored): string
    {
        return self::at($event, $stored, 'Y-m-d\TH:i:s');
    }

    /**
     * What an organiser typed, read as the EVENT's wall clock, stored as UTC.
     *
     * The half that is easy to forget and expensive to get wrong: an organiser setting a
     * Nairobi gala to 19:00 means 19:00 in Nairobi, and interpreting that in the platform's
     * zone starts the evening an hour out for everybody holding a ticket.
     */
    public static function toStored(object|array|null $event, ?string $typed,
                                    string $format = 'Y-m-d H:i:s'): ?string
    {
        $typed = trim((string) $typed);
        if ($typed === '') return null;

        try {
            $dt = new \DateTimeImmutable($typed, new \DateTimeZone(self::zone($event)));
        } catch (\Throwable) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format($format);
    }

    /**
     * Whether this event runs somewhere other than the platform's own zone.
     *
     * For a screen deciding whether to say so. An organiser working entirely in Lagos must
     * not have every date on every page carrying a redundant explanation.
     */
    public static function elsewhere(object|array|null $event): bool
    {
        return self::zone($event) !== DisplayTime::zone();
    }

    /** Stored strings are UTC by this application's convention. See Clock. */
    private static function parse(string|\DateTimeInterface|null $stored): ?\DateTimeImmutable
    {
        if ($stored instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($stored);
        }
        $s = trim((string) $stored);
        // A zero date is a column nobody filled in, and 1 January 1970 on a screen is a
        // fact that was never recorded looking like one that was.
        if ($s === '' || str_starts_with($s, '0000-00-00')) return null;

        try {
            return new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
