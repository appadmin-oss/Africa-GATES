<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The reminders a guest of honour gets between the invitation and the evening.
 *
 * ── WHY A SEPARATE MESSAGE AND NOT A RE-SEND ─────────────────────────────────
 *
 * The invitation is a formal letter: a PDF attached, artwork, a salutation, the whole
 * case for why somebody is being honoured. It is sent once, deliberately, by an operator
 * pressing a button. Sending it four more times would not read as a reminder — it would
 * read as a system that has lost track of what it has already said, and the attachment
 * alone makes it too heavy to repeat.
 *
 * A reminder has one job: the day is close, and here is your pass. So it is its own
 * short body, no attachment, and the countdown is the subject line.
 *
 * ── WHEN THEY GO ─────────────────────────────────────────────────────────────
 *
 * The operator sets MARKS — "30, 14, 7, 1" — days before the evening. The largest is the
 * answer to "how long before the event do these start"; the rest are the cadence. One
 * reminder per invitee per run, never two.
 *
 * A mark is due for the whole span down to the next mark below it, NOT only on the exact
 * day. Scheduled work here runs through one orchestrator on a shared host with no shell
 * ({@see \AfricaGates\Support\Maintenance}), and a missed 06:00 must not silently swallow
 * a reminder — an event fourteen days out that the cron did not see until day twelve
 * should still be reminded.
 *
 * Which is exactly why the MESSAGE never names the mark. It says how many days are
 * actually left, computed when it is sent. A "your 14-day reminder" that arrives on day
 * twelve is a message that tells the reader the sender is not paying attention, and it is
 * the failure this design removes rather than papers over.
 *
 * ── AND WHY NOBODY IS REMINDED OF SOMETHING THEY WERE NEVER TOLD ─────────────
 *
 * Only invitations with `sent_at` are reminded. A row that was minted and never sent is a
 * person who has heard nothing from us, and "the celebration is in seven days" as the
 * FIRST thing they receive is worse than silence: they have no pass, no reference, no
 * letter, and no idea what they are being reminded about.
 */
final class InviteReminders
{
    /**
     * Marks used when the operator has set none.
     *
     * The last five days, one letter a day — {@see InviteSequence}, whose arc is written
     * for exactly these marks. Referenced rather than repeated: a schedule that defaulted
     * to 30/14/7/1 while the letters were written for 5/4/3/2/1 would ship a feature whose
     * two halves disagree about when it runs, and nothing on any screen would say so.
     */
    public const DEFAULT_MARKS = InviteSequence::DAYS;

    /**
     * Reminders per sweep, across all events.
     *
     * Lower than the invitation batch of fifteen because this is unattended: nobody is
     * watching a page and pressing again, so the cap is what one shared-host cron tick can
     * finish rather than what an operator will sit through. What is left waits for the
     * next tick, which is the same day.
     */
    public const CAP = 40;

    /** The send log key. Per event AND per mark, so four reminders are four campaigns. */
    public static function campaignKey(int $eventId, int $mark): string
    {
        // Well inside gates_broadcast_log.campaign's VARCHAR(60): a 10-digit event id and a
        // 3-digit mark is 30 characters. Worth stating, because a key that overflows would
        // be TRUNCATED by MySQL into a collision with another mark's key and the second
        // reminder would be recorded as already sent.
        return 'invite-remind:' . $eventId . ':' . $mark;
    }

    /** Whether the sweep may send anything at all. */
    public static function enabled(): bool
    {
        $v = trim((string) (self::settings()['invite_reminder_enabled'] ?? ''));

        // Default ON, and it is safe to be: nothing is reminded unless an operator has
        // already built a list AND sent the invitations, which is two deliberate acts. A
        // reminder feature that ships off is one nobody discovers until they ask why no
        // reminder went out.
        return $v === '' || $v === '1' || $v === 'on' || $v === 'yes';
    }

    /**
     * The day-marks, largest first.
     *
     * Clamped and de-duplicated rather than trusted: this is a free-text field, and a 0
     * would mean "remind them on the morning of" (which the eve mark already covers better)
     * while a four-figure typo would start reminding people about a ceremony three years
     * out.
     *
     * @return list<int>
     */
    public static function marks(): array
    {
        $raw = trim((string) (self::settings()['invite_reminder_days'] ?? ''));
        if ($raw === '') return self::DEFAULT_MARKS;

        $out = [];
        foreach (preg_split('/[^0-9]+/', $raw) ?: [] as $piece) {
            if ($piece === '') continue;
            $n = (int) $piece;
            // 1..365. A year is the outer bound of a thing anybody plans a hall for.
            if ($n >= 1 && $n <= 365) $out[$n] = true;
        }
        if ($out === []) return self::DEFAULT_MARKS;

        $marks = array_keys($out);

        // Six is plenty, and WHICH six matters. Every mark is another message to the same
        // person, and a list of twenty would turn a field nobody thinks of as a mailing
        // frequency into a month of daily post — so it is capped.
        //
        // The six NEAREST the evening, not the six furthest: sorted ascending and trimmed
        // from that end. Keeping the largest instead would drop the eve reminder off a long
        // list, which is the one nobody would choose to lose — "tomorrow" is worth more to
        // somebody arranging a journey than "in thirty days", and a cap that silently
        // discards the most useful mark is worse than no cap at all.
        sort($marks);
        $marks = array_slice($marks, 0, 6);

        rsort($marks);

        return $marks;
    }

    /** The hour reminders begin going out when the operator has set none. */
    public const DEFAULT_TIME = '09:00';

    /**
     * The time of day reminders begin, as [hour, minute].
     *
     * ── WHY A SCHEDULE NEEDS A CLOCK AND NOT ONLY A CALENDAR ─────────────────
     *
     * The marks say WHICH DAY. Without a time they also, silently, said "whenever the
     * cron happens to reach this task" — which on a shared host is whatever quarter hour
     * the tick lands on, and an operator asking "what time do these go out?" had no answer
     * and no way to change it. A reminder is the only mail on this platform whose moment
     * is CHOSEN rather than triggered by something the recipient did, so it is the only
     * one where that question has a real answer to give.
     *
     * Parsed rather than trusted, and defaulted rather than refused: an unreadable value
     * must not silently stop every reminder on the platform.
     *
     * @return array{0:int,1:int}
     */
    public static function sendTime(): array
    {
        $raw = trim((string) (self::settings()['invite_reminder_time'] ?? ''));

        // `<input type="time">` posts 'HH:MM', and 'HH:MM:SS' when a step is set. Both are
        // accepted, and so is a bare hour, because a person typing into this field types
        // "9" at least as readily as "09:00".
        if (preg_match('/^\s*(\d{1,2})(?::(\d{2}))?/', $raw, $m) === 1) {
            $h = (int) $m[1];
            $i = (int) ($m[2] ?? 0);
            if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) return [$h, $i];
        }

        [$dh, $di] = array_map('intval', explode(':', self::DEFAULT_TIME));

        return [$dh, $di];
    }

    /** '09:00' — the send time as an operator reads and types it. */
    public static function sendTimeLabel(): string
    {
        [$h, $i] = self::sendTime();

        return sprintf('%02d:%02d', $h, $i);
    }

    /**
     * Whether the clock has reached the send time today.
     *
     * ── A START, NOT A SLOT ──────────────────────────────────────────────────
     *
     * True from the set time until the end of the day, not only in the quarter hour that
     * matches it. Two reasons, and both are about not losing people quietly:
     *
     *   • The sweep is capped per tick, so a large hall takes several ticks to drain. A
     *     one-slot window would send the first forty and leave the rest until tomorrow —
     *     by which time the mark may have moved.
     *   • A cron on a shared host is not a guarantee. If the tick that matches 09:00 never
     *     happens, a window that had already closed would swallow the whole day's
     *     reminders and nothing would say so.
     *
     * So the set time is when reminders BEGIN. Nothing is sent before it, which is the
     * half an operator actually cares about — and nothing rolls into tomorrow's small
     * hours either, because a new day re-opens the window at the time they chose rather
     * than continuing yesterday's.
     */
    public static function dueNow(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        [$h, $i] = self::sendTime();

        return $now->hour > $h || ($now->hour === $h && $now->minute >= $i);
    }

    /**
     * Whole days from today to the evening, in the site's own timezone.
     *
     * CALENDAR days, not 24-hour blocks. "Tomorrow" has to mean tomorrow's date to somebody
     * reading it over breakfast — an event at 18:00 tomorrow is 26 hours away, and a
     * duration-based count would call that two days and be wrong in the one place the
     * reader can check it against a wall calendar.
     *
     * Negative once the evening has passed.
     */
    public static function daysUntil(string $eventDate, ?Carbon $now = null): ?int
    {
        $when = trim($eventDate);
        if ($when === '') return null;

        try {
            // The stored value may be 'Y-m-d H:i:s' or T-separated depending on which
            // driver wrote it — MySQL normalises a TIMESTAMP, SQLite keeps the string
            // verbatim. Carbon reads both; a string comparison would not.
            $target = Carbon::parse($when)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        // Rounded and cast, in that order. Carbon returns a FLOAT here, and across a DST
        // boundary two startOfDay values are 23 or 25 hours apart — so a bare (int) cast
        // truncates 0.958333 to 0 and the day before the clocks change reads as "today".
        return (int) round(($now ?? Carbon::now())->copy()->startOfDay()->diffInDays($target, false));
    }

    /**
     * The mark that is due at this distance, or null when none is.
     *
     * The smallest mark at or above the days remaining — so on day 12 with marks
     * 30/14/7/1, the 14 is due and the 30 is behind us. Returns null past the largest mark
     * (too early to be reminding anybody) and null once the evening has gone.
     */
    public static function dueMark(int $daysUntil, ?array $marks = null): ?int
    {
        if ($daysUntil < 0) return null;

        $marks ??= self::marks();
        $due    = null;
        foreach ($marks as $m) {
            if ($daysUntil <= $m && ($due === null || $m < $due)) $due = $m;
        }

        return $due;
    }

    /**
     * "in 9 days" / "tomorrow" / "today".
     *
     * The one string the whole message hangs on, so it is built once here and used by the
     * subject, the body and the plain-text part alike. Three copies of this phrasing is
     * three chances for the subject to say "tomorrow" over a body that says "in 1 days".
     */
    public static function countdown(int $daysUntil): string
    {
        return match (true) {
            $daysUntil <= 0 => 'today',
            $daysUntil === 1 => 'tomorrow',
            default => 'in ' . $daysUntil . ' days',
        };
    }

    /**
     * Everything one audience's reminder says, resolved from settings with a default.
     *
     * Mirrors {@see InviteAudience::spec()} exactly — the operator's own sentence, the
     * default beside it, and the settings key — because the settings screen renders both
     * cards and an operator should not meet two shapes of the same idea.
     *
     * @return array{key:string, line:string, line_setting:string, line_default:string, headline:string}
     */
    public static function copy(string $audience): array
    {
        if (!InviteAudience::isValid($audience)) $audience = InviteAudience::NOMINEE;

        $defaults = [
            InviteAudience::NOMINEE => [
                // The framing the whole feature exists for: this is not a diary alert, it
                // is a celebration of a person's name and what they built. Written as a
                // COMPLETE sentence for the same reason InviteAudience::$witness is — half
                // a sentence in a settings box and half in a Twig file is two authors for
                // one paragraph, and this codebase has already sent that mistake out.
                'headline' => 'a Grand Celebration of your name and your legacy',
                'line'     => 'The hall is being made ready, and your seat is in it. This is a '
                            . 'celebration of your name, the work behind it, and the legacy it '
                            . 'is building — and the people who know that work best will be '
                            . 'there to see it honoured.',
            ],
            InviteAudience::JUDGE => [
                'headline' => 'a Grand Celebration of the names you weighed',
                'line'     => 'The hall is being made ready, and your seat is in it. The names '
                            . 'you weighed so carefully will be read aloud that evening, and '
                            . 'the legacies behind them honoured in front of the people they '
                            . 'belong to.',
            ],
        ];

        $d   = $defaults[$audience];
        $key = 'invite_reminder_line_' . $audience;
        $set = trim((string) (self::settings()[$key] ?? ''));

        return [
            'key'          => $audience,
            'line'         => $set !== '' ? $set : $d['line'],
            'line_setting' => $key,
            'line_default' => $d['line'],
            'headline'     => $d['headline'],
        ];
    }

    /**
     * What the reminder run has done and will do for one ceremony.
     *
     * ── WHY THE SCREEN NEEDS THIS AT ALL ─────────────────────────────────────
     *
     * The invitation run is a button an operator presses and watches. This one is not:
     * it happens on a cron, to a list they cannot see, on a schedule set on another
     * screen. Without this panel the whole feature is a setting with no reader — the
     * most expensive kind of bug available in this codebase — and the first question
     * anybody asks about it ("did they go? when is the next one?") has no answer that
     * does not need a shell this host does not have.
     *
     * @return array{
     *   enabled:bool, marks:list<int>, time:string, zone:string, open:bool,
     *   days:?int, due:?int, next:?int, audience_count:int,
     *   sent:list<array{mark:int,letter:string,letter_judge:bool,count:int,due:bool,past:bool}>
     * }
     */
    public static function status(int $eventId, string $eventDate): array
    {
        $marks = self::marks();
        $days  = self::daysUntil($eventDate);
        $due   = $days === null ? null : self::dueMark($days, $marks);

        // The mark AFTER the one due now — the largest one strictly below it, because
        // marks count DOWN and the 2 follows the 3. Strictly below, and that is the whole
        // subtlety: "the largest mark at or below the days remaining" is the mark that is
        // due, not the one after it, so the panel would have answered its own question
        // with the number it had just printed.
        //
        // Null on the eve, which is the honest answer: there is nothing after it. When
        // nothing is due yet, this is instead the FIRST mark that will fire, which is what
        // "reminders start N days out" needs.
        $next = null;
        if ($days !== null && $days >= 0) {
            $ceiling = $due ?? ($days + 1);
            foreach ($marks as $m) {
                if ($m < $ceiling && $m <= $days && ($next === null || $m > $next)) $next = $m;
            }
        }

        $sent = [];
        foreach ($marks as $m) {
            $sent[] = [
                'mark'  => $m,
                // WHICH letter this mark sends. "Day 3" says when and nothing says what,
                // and an operator deciding whether to rewrite one needs to know that day
                // three is the one about their own street before they open it. Empty for
                // a mark outside the arc, where the ordinary reminder goes instead.
                'letter' => InviteSequence::has($m) ? InviteSequence::label($m) : '',
                // Whether the panel gets one too. It does now, and saying so on the
                // schedule is the only place an operator can see that a judge is no
                // longer receiving the short reminder at this mark.
                'letter_judge' => InviteSequence::has($m, InviteAudience::JUDGE),
                'count' => self::sentCount($eventId, $m),
                'due'   => $m === $due,
                // Behind us: the evening is nearer than this mark, so it will not fire
                // again. Distinguished from "not yet" because they look identical as a
                // zero and are opposite problems.
                'past'  => $days !== null && $days < $m && $m !== $due,
            ];
        }

        return [
            'enabled'        => self::enabled(),
            'marks'          => $marks,
            'time'           => self::sendTimeLabel(),
            // Named, because a bare '09:00' on a screen an organiser reads from another
            // country is a time in nobody's particular day.
            'zone'           => DisplayTime::abbr(),
            // Whether today's window has opened yet. The difference between "these have
            // not gone" and "these have not gone YET" is the whole of what an operator
            // wants to know at 08:40, and both render as a zero without it.
            'open'           => self::dueNow(),
            'days'           => $days,
            'due'            => $due,
            'next'           => $next,
            'audience_count' => self::remindableCount($eventId),
            'sent'           => $sent,
        ];
    }

    /** How many were actually written to at one mark. */
    private static function sentCount(int $eventId, int $mark): int
    {
        try {
            return (int) DB::table('gates_broadcast_log')
                ->where('campaign', self::campaignKey($eventId, $mark))
                ->where('status', 'sent')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * How many guests of honour a reminder could reach.
     *
     * The SENT invitations, not the minted ones — the same rule {@see pending()} applies,
     * so the number on the screen is the number the sweep will work from. A count of
     * everybody minted would promise reminders to people the run will correctly skip.
     */
    private static function remindableCount(int $eventId): int
    {
        try {
            return (int) DB::table('gates_event_invites')
                ->where('event_id', $eventId)
                ->whereNotNull('sent_at')
                ->whereRaw("LOWER(COALESCE(email, '')) NOT LIKE ?", ['%@' . DemoSeeder::MAIL_DOMAIN])
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Send every reminder that is due, across every ceremony, up to the cap.
     *
     * Returns the number SENT, per the Maintenance contract: 0 means "ran, nothing was
     * due", and a throw is caught by the task wrapper and reported as TASK_FAILED. Never
     * returns a count of people it merely considered — a task that reports work it did not
     * do is a task that makes a broken cron look healthy.
     */
    public static function sweep(?OtpService $mailer = null, ?int $cap = null): int
    {
        if (!self::enabled()) return 0;

        $cap ??= self::CAP;
        $marks = self::marks();
        if ($marks === [] || $cap < 1) return 0;

        $mailer ??= OtpService::boot();
        // Not an error and not a failure: an unconfigured mailer is a deployment that has
        // not finished setting up email, and the invitation send says so on its own screen.
        // Reporting a failure every fifteen minutes for it would bury the real ones.
        if (!$mailer->smtpConfigured()) return 0;

        $sent = 0;

        foreach (self::eventsInWindow(max($marks)) as $event) {
            if ($sent >= $cap) break;

            $days = self::daysUntil((string) $event->event_date);
            if ($days === null) continue;

            $mark = self::dueMark($days, $marks);
            if ($mark === null) continue;

            foreach (self::pending((int) $event->id, $mark) as $invite) {
                if ($sent >= $cap) break;

                $r = self::send($invite, $event, $days, $mark, $mailer);
                if ($r['ok']) $sent++;

                // A quarter-second between sends, the same pacing the invitation run and
                // the nominee broadcast use: a shared host's relay drops a burst and
                // reports success for every message in it.
                usleep(250000);
            }
        }

        return $sent;
    }

    /**
     * Send one reminder.
     *
     * @return array{ok:bool, error:string, skipped:bool}
     */
    public static function send(
        object $invite,
        object $event,
        int $daysUntil,
        int $mark,
        ?OtpService $mailer = null
    ): array {
        $email = (string) ($invite->email ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'No usable address.', 'skipped' => false];
        }
        if (EmailOptOut::suppressed($email)) {
            return ['ok' => false, 'error' => 'This address has opted out.', 'skipped' => true];
        }
        if (self::alreadySent((int) $event->id, $mark, $email)) {
            return ['ok' => false, 'error' => 'Already reminded at this mark.', 'skipped' => true];
        }

        $mailer ??= OtpService::boot();
        $m = self::compose($invite, $event, $daysUntil, $mark);

        $r = $mailer->sendBranded(
            $email,
            $m['subject'],
            $m['html'],
            $m['plain'],
            'Reminder',
            $m['hero'],
            EmailOptOut::url(rtrim(SiteUrl::base(), '/'), $email),
            // NO ATTACHMENT. The formal letter went with the invitation; repeating a PDF
            // four times is weight in somebody's inbox for a document they already have.
            [],
            $m['preheader'],
            self::HERO_H
        );

        $ok    = (bool) ($r['success'] ?? false);
        $error = (string) ($r['error'] ?? '');
        self::log((int) $event->id, $mark, $invite, $ok, $error);

        return ['ok' => $ok, 'error' => $error, 'skipped' => false];
    }

    /** The rendered reminder, for the admin's preview. Sends nothing, logs nothing. */
    public static function preview(object $invite, object $event, int $daysUntil, ?OtpService $mailer = null): string
    {
        // No mark passed: let compose() resolve the one the schedule would have used at
        // this distance, so a preview shows the letter that would actually go rather than
        // the generic fallback.
        $m = self::compose($invite, $event, $daysUntil);

        // WRAPPED, because the recipient's copy is wrapped — the same reason
        // InviteMailer::preview() is. A preview that is not the message is a preview of
        // nothing, and this platform has already shipped one.
        return ($mailer ?? OtpService::boot())->brandWrap(
            $m['subject'],
            $m['html'],
            'Reminder',
            $m['hero'],
            '',
            $m['preheader'],
            self::HERO_H
        );
    }

    /** The height the shell reserves for the hero band. Matches the invitation's 2.3:1. */
    private const HERO_H = 260;

    /**
     * The whole message, composed once.
     *
     * One builder for the send, the preview and the plain-text part, for the reason
     * {@see InviteMailer::compose()} carries the scar of: the invitation's "why" sentence
     * was once assembled in the template AND in the plain-text builder, and fixing one
     * left the other sending the mangled version.
     *
     * @return array{subject:string, html:string, plain:string, preheader:string, hero:string}
     */
    private static function compose(object $invite, object $event, int $daysUntil, ?int $mark = null): array
    {
        $view = self::view($invite, $event, $daysUntil, $mark);

        return [
            'subject'   => (string) $view['subject'],
            'html'      => self::html($view),
            'plain'     => self::plain($view),
            'preheader' => (string) $view['preheader'],
            'hero'      => (string) $view['cover_url'],
        ];
    }

    /**
     * Everything the template renders, resolved once.
     *
     * @return array<string,mixed>
     */
    private static function view(object $invite, object $event, int $daysUntil, ?int $mark = null): array
    {
        $audience = (string) ($invite->audience ?? InviteAudience::NOMINEE);
        $spec     = InviteAudience::spec(InviteAudience::isValid($audience) ? $audience : InviteAudience::NOMINEE);
        $copy     = self::copy($audience);
        $base     = rtrim(SiteUrl::base(), '/');
        $tier     = EventInvites::lowestTier((int) $event->id);
        $count    = self::countdown($daysUntil);

        $where = trim(implode(', ', array_filter([
            trim((string) ($event->venue ?? '')),
            trim((string) ($event->location ?? '')),
        ])));

        // ── WHICH LETTER IS THIS? ────────────────────────────────────────────
        //
        // Inside the final week, each audience gets ITS OWN letter for that day —
        // a nominee is honoured for work they did and a judge for a decision they made
        // about somebody else's, so the arc is the same and the sentences are not.
        // Outside the week, and for anything with no letter written, the ordinary
        // reminder. Resolved from the mark rather than from the days remaining, because
        // the mark is what the schedule actually chose and the two differ the moment a
        // cron misses a morning.
        $seqDay = null;
        $m = $mark ?? self::dueMark($daysUntil);
        if ($m !== null && InviteSequence::has($m, $audience)) $seqDay = $m;

        $tokens = InviteSequence::tokens($invite, $event, $daysUntil);

        if ($seqDay !== null) {
            $letter     = InviteSequence::day($seqDay, $audience);
            $subject    = InviteSequence::fill($letter['subject'], $tokens);
            $bodyText   = InviteSequence::fill($letter['body'], $tokens);
            $paragraphs = self::paragraphs($bodyText);
            // "With appreciation" is how these letters sign off, and it is not
            // interchangeable with the reminder's "With respect": one is a nudge from an
            // organiser, the other is five letters asking somebody what they believe.
            $signOff    = 'With appreciation';
        } else {
            $subject  = trim((string) $invite->name) . ', ' . $count . ': ' . $copy['headline'];
            $bodyText = (string) $copy['line'] . "\n\n"
                      . 'Nothing is needed from you — your seat at ' . trim((string) $event->title)
                      . ' is arranged and there is no ticket for you to buy.';
            $paragraphs = self::paragraphs($bodyText);
            $signOff    = 'With respect';
        }

        return [
            // ── NAME, COUNTDOWN, THEN THE FRAMING ────────────────────────────
            //
            // In that order because an inbox shows about sixty characters and a phone
            // fewer. The countdown is the entire content of a reminder, so putting it
            // after the celebration phrase — which is the natural way to write the
            // sentence — pushed the one fact this message exists to carry past the
            // truncation on every mobile client. Name and countdown are both inside the
            // first twenty characters now, and the framing is the part that may fall off.
            'subject'   => $subject,
            // The ask, not a repeat of the subject: the subject already says when, so the
            // one line beside it in the inbox carries what to DO about it.
            'preheader' => 'Your pass is ready, and your guests still have '
                         . InviteAudience::discountPercent() . '% off.',

            'name'         => trim((string) $invite->name),
            'salutation'   => (string) $spec['salutation'],
            'headline'     => (string) $copy['headline'],
            // BLOCKS, not one string. The letters are long-form and their rhythm is the
            // argument — a single line standing alone is a beat, and a wall of text with
            // <br> between the beats is not the same message.
            'paragraphs'   => $paragraphs,
            'body_text'    => $bodyText,
            'sign_off'     => $signOff,
            'team'         => (string) $tokens['team'],
            'sequence_day' => $seqDay,
            'countdown'    => $count,
            // Capitalised for the panel, which opens with it. Done here rather than with a
            // Twig filter because the plain-text part needs the same string and `|capitalize`
            // in one of two places is how the two halves drift.
            'countdown_up' => ucfirst($count),
            'days'         => $daysUntil,

            'event_title' => trim((string) $event->title),
            'when'        => DisplayTime::showZoned((string) $event->event_date, 'l j F Y \a\t H:i'),
            'when_day'    => DisplayTime::show((string) $event->event_date, 'l'),
            'when_date'   => DisplayTime::show((string) $event->event_date, 'j F Y'),
            // ── ZONED, AND ONLY ONCE ─────────────────────────────────────────
            //
            // `showZoned()` APPENDS the abbreviation itself — that is the whole difference
            // between it and `show()`. Adding one here read "19:00 WAT WAT" in a real
            // inbox, and it survived a test asserting the zone was present, because the
            // zone WAS present: it always had been. An assertion that something appears is
            // not an assertion about how many times.
            'when_time'   => DisplayTime::showZoned((string) $event->event_date, 'H:i'),
            'where'       => $where,
            'cover_url'   => self::coverUrl($event, $base),

            'reference' => trim((string) $invite->reference),
            'quota'     => (int) ($invite->guest_quota ?? 0),
            'discount'  => InviteAudience::discountPercent(),
            'tier_line' => $tier !== null
                ? ', from ₦' . number_format((int) $tier->price_naira)
                  . ' (' . trim((string) $tier->name) . ') upwards'
                : '',

            'id_url'          => EventInvites::idUrl((string) $invite->reference, $base),
            'events_url'      => $base . '/events/' . rawurlencode((string) $event->slug),
            'unsubscribe_url' => EmailOptOut::url($base, (string) $invite->email),
        ];
    }

    /**
     * A body as the blocks a reader sees.
     *
     * Split on BLANK lines only. A single newline inside a block is kept and rendered as a
     * line break, because that is how the values list reaches day two's letter — five
     * short lines that have to stay five short lines. Splitting on every newline would
     * turn it into five paragraphs with the spacing of five arguments.
     *
     * @return list<string>
     */
    private static function paragraphs(string $body): array
    {
        $out = [];
        foreach (preg_split('/(?:\r\n|\r|\n){2,}/', trim($body)) ?: [] as $block) {
            $block = trim($block);
            if ($block !== '') $out[] = $block;
        }

        return $out;
    }

    /** @param array<string,mixed> $view */
    private static function html(array $view): string
    {
        static $twig = null;
        $twig ??= new Environment(
            new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'),
            ['autoescape' => 'html']
        );

        return $twig->render('emails/invite-reminder.twig', $view);
    }

    /**
     * The plain-text part, WRITTEN rather than stripped.
     *
     * `strip_tags()` over a table layout produces a column of orphaned words. This is what
     * a plain-text client shows, what a screen reader may be handed, and what every spam
     * filter reads to decide whether the HTML half is worth trusting.
     *
     * @param array<string,mixed> $view
     */
    private static function plain(array $view): string
    {
        return implode("\n", [
            'AFRICA GATES — An Afrovanguard Initiative',
            '',
            ucfirst((string) $view['headline']) . ' — ' . (string) $view['countdown'] . '.',
            '',
            $view['salutation'] . ' ' . $view['name'] . ',',
            '',
            // The body as WRITTEN, blank lines and all. This is a letter, and the shape of
            // it is half the message — collapsing the beats into one block is the same
            // damage `strip_tags()` over a table does, arrived at a different way.
            (string) $view['body_text'],
            '',
            strtoupper((string) $view['countdown_up']),
            $view['event_title'],
            $view['when'] . ($view['where'] !== '' ? ' · ' . $view['where'] : ''),
            '',
            'Your pass: ' . $view['id_url'],
            'It is a live page, not a ticket — open it on your phone at the door.',
            '',
            'Bring your people: ' . $view['reference'],
            $view['discount'] . '% off, for up to ' . $view['quota'] . ' guests'
                . (string) $view['tier_line'] . '.',
            'Tickets: ' . $view['events_url'],
            '',
            (string) $view['sign_off'] . ',',
            (string) $view['team'],
            '',
            'To stop receiving email from us: ' . $view['unsubscribe_url'],
        ]);
    }

    /**
     * The hero image, absolute.
     *
     * Shared shape with {@see InviteMailer}: a stored cover may be a full URL (Cloudinary)
     * or a path on this disk, and an inbox can only fetch the first.
     */
    private static function coverUrl(object $event, string $base): string
    {
        $cover = trim((string) ($event->cover_image ?? ''));
        if ($cover === '') return '';

        return str_starts_with($cover, 'http') ? $cover : $base . '/' . ltrim($cover, '/');
    }

    /**
     * The ceremonies close enough to be reminding anybody about.
     *
     * Bounded at BOTH ends. The far end is the largest mark, so a gala eighteen months out
     * is not walked every fifteen minutes; the near end is now, because an evening that has
     * happened is not something to remind anybody about — and `daysUntil` going negative is
     * the ordinary state of every ceremony this platform has ever run, forever.
     *
     * @return list<object>
     */
    private static function eventsInWindow(int $furthestMark): array
    {
        try {
            $now = Carbon::now();

            return DB::table('gates_site_events')
                ->where('status', 'published')
                ->whereNotNull('event_date')
                // From the start of today: an event at 18:00 is still ahead of somebody
                // reading this at 09:00, and a bare `>= now` would drop the reminder on the
                // one morning it matters most.
                ->where('event_date', '>=', $now->copy()->startOfDay()->toDateTimeString())
                ->where('event_date', '<=', $now->copy()->addDays($furthestMark + 1)->endOfDay()->toDateTimeString())
                ->orderBy('event_date')
                ->get(['id', 'slug', 'title', 'event_date', 'venue', 'location', 'cover_image'])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Who still needs this mark's reminder for one event.
     *
     * The two conditions that matter are both here rather than in the caller: the
     * invitation must have been SENT (see the class note — nobody is reminded of something
     * they were never told), and this mark must not already have gone to them.
     *
     * The sandbox is excluded the way every public reader excludes it. `DemoSeeder` makes
     * real rows with real flags because the sandbox exists to be walked through for real,
     * which means a rehearsal invitation is a real row that would be really emailed.
     *
     * @return list<object>
     */
    private static function pending(int $eventId, int $mark): array
    {
        try {
            $done = DB::table('gates_broadcast_log')
                ->where('campaign', self::campaignKey($eventId, $mark))
                ->where('status', 'sent')
                ->pluck('email_hash')->all();

            $rows = DB::table('gates_event_invites')
                ->where('event_id', $eventId)
                ->whereNotNull('sent_at')
                ->whereRaw("LOWER(COALESCE(email, '')) NOT LIKE ?", ['%@' . DemoSeeder::MAIL_DOMAIN])
                ->orderBy('id')
                ->get()->all();

            if ($done === []) return $rows;

            // Filtered in PHP against the hash list rather than as a NOT IN subquery: the
            // log stores a hash of the address and the invite stores the address, so there
            // is no column the two tables can be joined on.
            $skip = array_fill_keys($done, true);

            return array_values(array_filter(
                $rows,
                static fn (object $r): bool => !isset($skip[EmailOptOut::hash((string) $r->email)])
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    private static function alreadySent(int $eventId, int $mark, string $email): bool
    {
        try {
            return DB::table('gates_broadcast_log')
                ->where('campaign', self::campaignKey($eventId, $mark))
                ->where('email_hash', EmailOptOut::hash($email))
                ->where('status', 'sent')
                ->exists();
        } catch (\Throwable) {
            // Cannot prove it was NOT sent, so do not send. The same call this codebase
            // makes for the invitation itself: a second copy of a message somebody already
            // has is worse than a missing one an operator can send by hand.
            return true;
        }
    }

    private static function log(int $eventId, int $mark, object $invite, bool $ok, string $error): void
    {
        try {
            DB::table('gates_broadcast_log')->updateOrInsert(
                ['campaign'   => self::campaignKey($eventId, $mark),
                 'email_hash' => EmailOptOut::hash((string) $invite->email)],
                ['email'      => (string) $invite->email,
                 // NULL for a judge, not 0 — the column is a nullable foreign key and a 0
                 // points at a nominee that does not exist.
                 'nominee_id' => ($invite->nominee_id ?? null) ? (int) $invite->nominee_id : null,
                 'status'     => $ok ? 'sent' : 'failed',
                 'error'      => $error === '' ? null : mb_substr($error, 0, 300),
                 'sent_at'    => Carbon::now()->toDateTimeString()]
            );
        } catch (\Throwable) {}
    }

    /** @return array<string,string> */
    private static function settings(): array
    {
        try {
            return DB::table('gates_settings')->pluck('value', 'key_name')->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
