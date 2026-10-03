<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\ColumnRange;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Who is in the room, how they got there, and how to take it back.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO FAULTS THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * **A check-in could not be undone.** `checked_in_at` was written by the door and cleared by
 * nothing, and four things gate on it: the door's own duplicate check, a rename, a transfer,
 * and — the expensive one — {@see EventRefundPolicy}, which refuses a refund to anybody
 * marked admitted. So a steward's camera catching the ticket of the person behind in the
 * queue turned that attendee away at the door AND kept their money, with no route to reverse
 * it on a host with no shell.
 *
 * **The headcount omitted every guest of honour.** The door's "N in / M expected" summed
 * `gates_event_registrations` alone. Nominees and judges are admitted on an invitation and
 * have no registration row by design — minting them complimentary tickets would have counted
 * as sales and stopped the hall selling — so the number a steward reads to judge the room,
 * and the number closest to a fire-safety figure, silently excluded them. At an awards gala
 * that can be a large share of the people present.
 *
 * ── ONE RESOLVER, WHICH IS THE WHOLE POINT ───────────────────────────────────
 *
 * {@see inTheRoom()} and {@see expected()} are the only two places either number is derived.
 * The door, the admin screen and the arrivals report all read them. Two readers of one count
 * is how a door and an office come to disagree about how many people are in a building.
 */
final class EventArrivals
{
    /** Written into `via` when nothing better is known. */
    public const VIA_UNKNOWN = 'unrecorded';

    public static function ready(): bool
    {
        try { return DB::schema()->hasTable('gates_event_checkin_log'); }
        catch (\Throwable) { return false; }
    }

    // ══ the record ═══════════════════════════════════════════════════════════

    /**
     * Append one line to the arrivals log.
     *
     * Never throws and never blocks the door: the verdict is the decision, this is the
     * record of it, and a log that could refuse an admission would be worse than no log.
     */
    /**
     * @param string $at WHEN IT HAPPENED, not when this row was written.
     *
     * ── WHY THAT DISTINCTION IS THE POINT OF THE PARAMETER ───────────────────
     *
     * The door records scans while the line is down and flushes them when it comes back.
     * The moment of each scan travels with it — that is the whole design — and lands
     * correctly on the ticket's own `checked_in_at`. This log stamped `now()`
     * unconditionally, so a batch flushed at 21:00 wrote 21:00 against forty people who
     * walked in at 19:05, and the two records of one evening disagreed.
     *
     * That is the worse half of the pair: the log is the durable record an organiser
     * stands behind a week later, when somebody disputes being turned away.
     *
     * Already clamped by the caller — see EventTicketService::stampFor(), which refuses a
     * future stamp and anything more than twelve hours old. A door has the authority to
     * admit; it must not have the authority to write history.
     */
    private static function append(array $row, string $at = ''): void
    {
        if (!self::ready()) return;
        try {
            DB::table('gates_event_checkin_log')->insert($row + [
                'created_at' => $at !== '' ? $at : Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[arrivals] could not log: ' . $e->getMessage());
        }
    }

    /**
     * A ticket holder came through.
     *
     * @param int $seats how many of them THIS time — 0 means the whole ticket. A group
     *                   arriving in two halves is two rows, and a log that recorded the
     *                   ticket's full size on each would count eight people into a room
     *                   holding four.
     */
    public static function admitted(object $reg, string $via, ?int $adminId = null,
                                    int $seats = 0, string $at = ''): void
    {
        self::append([
            'event_id'        => (int) $reg->event_id,
            'registration_id' => (int) $reg->id,
            'invite_id'       => null,
            'action'          => 'admit',
            // Seats AS THEY WERE. A transfer can change a ticket's quantity afterwards, and a
            // headcount recomputed from today's number would rewrite last night's door.
            'seats'           => ColumnRange::clamp(
                                    $seats > 0 ? $seats : max(1, (int) ($reg->quantity ?? 1)),
                                    ColumnRange::SMALLINT_UNSIGNED, 1),
            'who'             => mb_substr((string) ($reg->name ?? ''), 0, 160),
            'via'             => mb_substr($via !== '' ? $via : self::VIA_UNKNOWN, 0, 60),
            'admin_id'        => $adminId ?: null,
            'reason'          => null,
        ], $at);
    }

    /** A guest of honour came through. One person: their guests buy ordinary tickets. */
    public static function honoured(object $invite, string $via, string $at = ''): void
    {
        self::append([
            'event_id'        => (int) $invite->event_id,
            'registration_id' => null,
            'invite_id'       => (int) $invite->id,
            'action'          => 'admit',
            'seats'           => 1,
            'who'             => mb_substr((string) ($invite->name ?? ''), 0, 160),
            'via'             => mb_substr($via !== '' ? $via : self::VIA_UNKNOWN, 0, 60),
            'admin_id'        => null,
            'reason'          => null,
        ], $at);
    }

    /** An admission was taken back. The reason is required and is the point of the row. */
    public static function reversed(object $reg, string $via, ?int $adminId, string $reason): void
    {
        self::append([
            'event_id'        => (int) $reg->event_id,
            'registration_id' => (int) $reg->id,
            'invite_id'       => null,
            'action'          => 'undo',
            'seats'           => ColumnRange::clamp(max(1, (int) ($reg->quantity ?? 1)),
                                                    ColumnRange::SMALLINT_UNSIGNED, 1),
            'who'             => mb_substr((string) ($reg->name ?? ''), 0, 160),
            'via'             => mb_substr($via !== '' ? $via : self::VIA_UNKNOWN, 0, 60),
            'admin_id'        => $adminId ?: null,
            'reason'          => mb_substr($reason, 0, 200),
        ]);
    }

    // ══ the room ═════════════════════════════════════════════════════════════

    /**
     * Seats admitted so far — people through the door, tickets AND guests of honour.
     *
     * Read from the registrations rather than the log, deliberately: the log is a history and
     * a reversal appends to it, so summing it would need the arithmetic of admissions minus
     * undos and would drift the first time a row was written twice. `checked_in_at` is the
     * current state and it is the honest source for "how many are in".
     */
    public static function inTheRoom(int $eventId): int
    {
        if ($eventId < 1) return 0;
        $n = 0;

        try {
            $n += (int) DB::table('gates_event_registrations')
                ->where('event_id', $eventId)
                ->whereNotNull('checked_in_at')
                // SEATS ADMITTED, not the ticket's size. Two of a party of four being in
                // the room is two people, and summing `quantity` here counted the two who
                // have not arrived yet — on the number closest to a fire-safety figure.
                //
                // COALESCE to `quantity` for rows written before the column existed: the
                // migration backfills them, and this is the belt to that brace on a
                // database restored from a backup taken between the two statements.
                ->sum(DB::raw('CASE WHEN COALESCE(checked_in_seats, 0) > 0 '
                            . 'THEN checked_in_seats ELSE COALESCE(quantity, 1) END'));
        } catch (\Throwable) { /* a headcount that cannot be read is 0, not an error page */ }

        $n += self::honouredIn($eventId);

        return $n;
    }

    /** Guests of honour who have been scanned at least once. */
    private static function honouredIn(int $eventId): int
    {
        try {
            return (int) DB::table('gates_event_invites')
                ->where('event_id', $eventId)->where('scans', '>', 0)
                // The sandbox writes real rows with real flags; every public reader excludes
                // them. A sample invite must never appear in a room's headcount.
                ->where('reference', 'not like', 'AGI-SAMPLE%')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Seats the room should expect: confirmed tickets plus invitations actually sent.
     *
     * Minted-but-unsent invitations are excluded — nobody has been told to come, so counting
     * them would show an organiser a hall they never invited anybody to.
     */
    public static function expected(int $eventId): int
    {
        if ($eventId < 1) return 0;

        $n = EventTicketService::attendingForEvent($eventId);

        try {
            $n += (int) DB::table('gates_event_invites')
                ->where('event_id', $eventId)->whereNotNull('sent_at')
                ->where('reference', 'not like', 'AGI-SAMPLE%')
                ->count();
        } catch (\Throwable) { /* tickets alone is still a number */ }

        return $n;
    }

    /**
     * The two halves, named — so a screen can say "412 in, of which 38 guests of honour"
     * rather than one number that hides which door the people came through.
     *
     * @return array{in:int, expected:int, tickets_in:int, honoured_in:int}
     */
    public static function summary(int $eventId): array
    {
        $honoured = self::honouredIn($eventId);
        $in       = self::inTheRoom($eventId);

        return [
            'in'          => $in,
            'expected'    => self::expected($eventId),
            'tickets_in'  => max(0, $in - $honoured),
            'honoured_in' => $honoured,
        ];
    }

    // ══ the history ══════════════════════════════════════════════════════════

    /**
     * The arrivals log, newest first — the first reader `checked_in_via` has ever had.
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $eventId, int $limit = 200): array
    {
        if (!self::ready()) return [];
        try {
            return DB::table('gates_event_checkin_log')
                ->where('event_id', $eventId)
                ->orderByDesc('id')->limit(max(1, min(1000, $limit)))
                ->get(['id', 'registration_id', 'invite_id', 'action', 'seats',
                       'who', 'via', 'admin_id', 'reason', 'created_at'])
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            error_log('[arrivals] could not read the log: ' . $e->getMessage());
            return [];
        }
    }

    // ── reversalsFor() WAS HERE, AND ITS DOCBLOCK WAS NOT TRUE ───────────────
    //
    // It said it "drives the 'taken back' tag on the list". It did not: the tag comes off
    // `action == 'undo'` in the rows {@see recent()} already returns, and it has since the
    // log was written. So this was a SECOND resolver for a question the first one answers —
    // and this codebase has one rule about that, learned from GoogleSheetsService sharing
    // the calendar's setting: two readers of one fact is how the halves of a feature come to
    // disagree about it. A method whose own docblock describes a screen it does not reach is
    // §20's bug exactly, and the fix for a duplicate is deletion, not a caller.
    //
    // Left as a note rather than removed silently, because the next person to want a
    // per-admission reversal tag should know the data is already in recent() rather than
    // write this again.

    /** Housekeeping: a log older than the dispute window is of no interest to anybody. */
    public static function prune(int $graceDays = 400): int
    {
        if (!self::ready()) return 0;
        try {
            return (int) DB::table('gates_event_checkin_log')
                ->where('created_at', '<', Carbon::now()->subDays(max(30, $graceDays))->toDateTimeString())
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

}
