<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The call for vendor stands, and the rules that make its allocation defensible.
 *
 * Implements §5 of docs/VENDOR-STANDS-SPEC.md. There is one governing principle and
 * everything here enforces it:
 *
 *     THE RULE IS FIXED AND PUBLISHED BEFORE ANYBODY KNOWS WHO APPLIED.
 *
 * ── WHICH IS WHY LOCKING IS A ONE-WAY DOOR ───────────────────────────────────
 *
 * {@see open()} copies the criteria onto the call as JSON and stamps `locked_at`. After that
 * the criteria, the quotas and the closing date cannot be edited — not "should not", cannot,
 * because a rule that can be changed once you know who applied is not a rule. Changing them
 * means closing the call and opening a new one, which is visible.
 *
 * The criteria are COPIED rather than referenced. A live reference would let a rejection be
 * justified by a criterion written after the applicant applied, with nothing in the record
 * showing it.
 *
 * ── AND WHY ELIGIBILITY AND SELECTION ARE SEPARATE COLUMNS ───────────────────
 *
 * Eligibility is objective: documents present and in date, right category, not previously
 * removed for cause. A machine could do it and a rejection is one sentence.
 *
 * Selection is judgement, and is the only stage that needs a recorded rationale.
 *
 * Collapsing them into one "we reviewed your application" is what makes rejections feel
 * arbitrary, because an applicant cannot tell whether they failed a rule or a taste.
 */
final class StandCall
{
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_DRAFT  => 'Draft — criteria still editable',
        self::STATUS_OPEN   => 'Open — criteria locked',
        self::STATUS_CLOSED => 'Closed to applications',
    ];

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        return DB::table('gates_stand_calls')->where('id', $id)->first();
    }

    public static function forEvent(int $eventId): ?object
    {
        if ($eventId < 1) return null;
        try {
            return DB::table('gates_stand_calls')->where('event_id', $eventId)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Is this call taking applications right now?
     *
     * Checked against the clock, not against a status somebody has to remember to change. A
     * call still marked open a week after its closing date must not accept an application,
     * because the closing date is one of the published terms.
     */
    public static function isAccepting(?object $call): bool
    {
        if (!$call || (string) ($call->status ?? '') !== self::STATUS_OPEN) return false;

        $now   = date('Y-m-d H:i:s');
        $opens = self::stamp((string) ($call->opens_at  ?? ''));
        $close = self::stamp((string) ($call->closes_at ?? ''));

        if ($opens !== '' && $opens > $now) return false;
        if ($close !== '' && $close < $now) return false;
        return true;
    }

    /**
     * Normalise a stored timestamp before comparing it as a string.
     *
     * ── THE BUG THIS EXISTS BECAUSE OF ──────────────────────────────────────
     *
     * The organiser's form uses `<input type="datetime-local">`, whose value is
     * `2026-09-01T10:00` — a **T** where a datetime has a space, and no seconds. That
     * string was written to the column verbatim and compared, verbatim, against
     * `date('Y-m-d H:i:s')`.
     *
     * MySQL happens to normalise it on the way into a TIMESTAMP column, so it reads back
     * with a space and the comparison works. SQLite stores TEXT exactly as given, so it
     * does not — and `'T'` (0x54) sorts ABOVE `' '` (0x20). A call that opened at 09:00
     * this morning therefore compared as opening in the future for the whole of today, and
     * `isAccepting()` said no. The vendor clicked Apply and was bounced.
     *
     * A comparison that is right on one engine and wrong on the other is the failure mode
     * this codebase keeps hitting, so the fix is to normalise at both ends — here on read,
     * and in {@see save()} on write — rather than to trust the column type.
     */
    private static function stamp(string $raw): string
    {
        $v = trim($raw);
        if ($v === '') return '';

        $v = str_replace('T', ' ', $v);

        // A bare date means the whole of that day. Without this, a call closing "1 Sept"
        // compares as closing at 00:00:00 on the 1st — so its final day is not a day.
        if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $v)) return $v . ' 23:59:59';

        // `2026-09-01 10:00` — the seconds a datetime-local never sends.
        if (preg_match('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$~', $v)) return $v . ':00';

        return $v;
    }

    /**
     * Why this call is not taking applications, in a sentence a vendor can act on.
     *
     * Every caller used to say "Applications for this event are closed" for all four
     * reasons — not opened, opens later, closed on a date, never published — and wrote it
     * to a session key no public template rendered. Both halves of that were the bug.
     */
    public static function whyNotAccepting(?object $call): string
    {
        if (!$call) {
            return 'There is no call for stands at this event yet.';
        }

        $status = (string) ($call->status ?? '');
        $opens  = self::stamp((string) ($call->opens_at  ?? ''));
        $close  = self::stamp((string) ($call->closes_at ?? ''));
        $now    = date('Y-m-d H:i:s');

        if ($status === self::STATUS_DRAFT) {
            return 'Applications have not opened yet. The terms will be published here first.';
        }

        // Closed by hand, before the published closing date. That is a real thing an
        // organiser does — the hall filled — and it needs its own sentence, because
        // "applications closed on the 14th" would be false and the vendor would go looking
        // for a date that has not happened.
        if ($status === self::STATUS_CLOSED) {
            return 'Applications for this event are closed.';
        }

        if ($opens !== '' && $opens > $now) {
            return 'Applications open on ' . date('l j F Y', (int) strtotime($opens))
                 . ' at ' . date('g:i a', (int) strtotime($opens)) . '.';
        }

        if ($close !== '' && $close < $now) {
            return 'Applications closed on ' . date('j F Y', (int) strtotime($close))
                 . '. Everything that was published is still on this page, so you know what '
                 . 'to expect next time.';
        }

        return 'This call is not taking applications at the moment.';
    }

    /** The criteria as published, decoded. Empty for a call that was never opened. */
    public static function criteria(?object $call): array
    {
        $raw = (string) ($call->criteria_json ?? '');
        if ($raw === '') return [];
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    /**
     * Create or edit a call while it is still a draft.
     *
     * @return array{ok:bool,message:string,id:int}
     */
    public static function save(int $eventId, array $in, int $callId = 0): array
    {
        $fail = ['ok' => false, 'id' => $callId];

        $existing = $callId > 0 ? self::find($callId) : self::forEvent($eventId);
        if ($existing && (string) $existing->status !== self::STATUS_DRAFT) {
            return $fail + ['message' => 'This call is already open, so its terms are locked. '
                                       . 'Close it and open a new one if the terms must change.'];
        }

        // Normalised HERE and not just on read: `2026-09-01T10:00` from a datetime-local
        // input is stored verbatim by SQLite, and a column holding two different shapes of
        // the same instant is a column every future comparison has to remember to defend
        // against. See {@see stamp()}.
        $closes = self::stamp((string) ($in['closes_at'] ?? ''));
        $opens  = self::stamp((string) ($in['opens_at'] ?? ''));
        if ($opens !== '' && $closes !== '' && $closes < $opens) {
            return $fail + ['message' => 'The closing date is before the opening date.'];
        }

        $row = [
            'event_id'   => $eventId,
            'intro'      => trim((string) ($in['intro'] ?? '')) ?: null,
            'opens_at'   => $opens !== ''  ? $opens  : null,
            'closes_at'  => $closes !== '' ? $closes : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            DB::table('gates_stand_calls')->where('id', $existing->id)->update($row);
            return ['ok' => true, 'id' => (int) $existing->id, 'message' => 'Saved.'];
        }

        $row['status']     = self::STATUS_DRAFT;
        $row['created_at'] = date('Y-m-d H:i:s');
        return ['ok' => true, 'id' => (int) DB::table('gates_stand_calls')->insertGetId($row),
                'message' => 'Call created as a draft. Publish it when the terms are final — '
                           . 'they cannot be edited afterwards.'];
    }

    /**
     * Record the hall's measurements. Allowed at any time, including after the lock.
     *
     * ── WHY THIS ONE WRITER IS EXEMPT ────────────────────────────────────────
     *
     * The lock exists to stop the RULES changing once you know who applied: the criteria, the
     * quotas, the prices, the closing date. How wide the hall actually is, is not a rule. It
     * is a fact about the world, and somebody may measure it more carefully next week or the
     * venue may confirm a different room.
     *
     * Refusing a better measurement would protect no applicant. It would only guarantee that
     * the floor plan on the screen stays wrong — and a wrong floor plan is worse than none,
     * because the whole point of it is to catch "these stands do not fit" while there is
     * still time to do something about it.
     *
     * The distinction has to be enforced by having a separate method rather than a flag on
     * {@see save()}, because a flag is a thing somebody eventually passes from the wrong form.
     *
     * @return array{ok:bool,message:string}
     */
    public static function savePlan(int $eventId, array $in): array
    {
        $call = self::forEvent($eventId);
        if (!$call) {
            return ['ok' => false, 'message' => 'Set up the call first — the venue is recorded '
                                              . 'against it.'];
        }

        // Metres in, centimetres stored, for the same reason as the pitches: exact sums.
        $w = (int) round(((float) ($in['floor_width_m'] ?? 0)) * 100);
        $d = (int) round(((float) ($in['floor_depth_m'] ?? 0)) * 100);

        if ($w < 0 || $d < 0 || $w > 100000 || $d > 100000) {
            return ['ok' => false, 'message' => 'Those are not plausible hall dimensions.'];
        }
        if (($w > 0) !== ($d > 0)) {
            return ['ok' => false, 'message' => 'Give both the width and the depth, or neither. '
                                              . 'One of the two is not a floor area.'];
        }

        // Clamped, not trusted. A 100% aisle allowance leaves no sellable floor, which turns
        // every percentage downstream into a division by zero.
        $aisle = max(0, min(80, (int) ($in['aisle_pct'] ?? 35)));

        DB::table('gates_stand_calls')->where('id', $call->id)->update([
            'floor_width_cm' => $w > 0 ? $w : null,
            'floor_depth_cm' => $d > 0 ? $d : null,
            'aisle_pct'      => $aisle,
            'floor_note'     => trim((string) ($in['floor_note'] ?? '')) ?: null,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'message' => $w > 0
            ? 'Venue recorded. The floor plan below is an indicative block layout — it knows '
            . 'nothing about fire exits, columns or power, so do not send it to the venue as one.'
            : 'Venue measurements cleared.'];
    }

    /**
     * Publish the call and lock its terms. One way.
     *
     * Refused unless there is something to apply for and a closing date to apply by: a call
     * with no stand types is a form with no options, and one with no deadline is an appeal
     * that never resolves.
     *
     * @return array{ok:bool,message:string}
     */
    public static function open(int $callId, int $adminId): array
    {
        $call = self::find($callId);
        if (!$call) return ['ok' => false, 'message' => 'That call does not exist.'];

        if ((string) $call->status !== self::STATUS_DRAFT) {
            return ['ok' => false, 'message' => 'That call has already been opened.'];
        }
        if (trim((string) ($call->closes_at ?? '')) === '') {
            return ['ok' => false, 'message' => 'Set a closing date first. An application window '
                                              . 'with no deadline is one of the published terms missing.'];
        }

        $types = StandType::forEvent((int) $call->event_id);
        if ($types === []) {
            return ['ok' => false, 'message' => 'Add at least one stand type first — otherwise '
                                              . 'there is nothing for a vendor to apply for.'];
        }

        // The criteria are COPIED here, at the moment of locking, and the quotas with them.
        // A rejection six weeks from now is justified against this snapshot and nothing else.
        $snapshot = [
            'locked_at' => date('Y-m-d H:i:s'),
            'closes_at' => (string) $call->closes_at,
            'types'     => array_map(static fn($t) => [
                'slug'     => (string) $t->slug,
                'name'     => (string) $t->name,
                'category' => (string) $t->category,
                'price'    => (int) $t->price_naira,
                'quota'    => (int) $t->quota,
                // The SIZE is in the snapshot too. A vendor who applied for 3m × 3m and
                // arrives to find 2m × 2m was sold something else, and the only thing that
                // settles that argument is what the call said on the day they applied.
                'width_cm' => (int) $t->width_cm,
                'depth_cm' => (int) $t->depth_cm,
                // In the unit it was SOLD in. The centimetres above settle the argument
                // about area; this string is what the vendor read and agreed to, and
                // "1.83 × 1.83 m" is not what a 6ft pitch was advertised as.
                'size'     => StandPreset::labelForType($t),
            ], $types),
        ];

        DB::table('gates_stand_calls')->where('id', $callId)->update([
            'status'        => self::STATUS_OPEN,
            'criteria_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'locked_at'     => date('Y-m-d H:i:s'),
            'locked_by'     => $adminId,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'message' => 'Open. The criteria, quotas, prices and closing date '
                                         . 'are now locked and cannot be edited.'];
    }

    public static function close(int $callId): array
    {
        if (!self::find($callId)) return ['ok' => false, 'message' => 'That call does not exist.'];
        DB::table('gates_stand_calls')->where('id', $callId)->update([
            'status' => self::STATUS_CLOSED, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'message' => 'Closed to new applications. Scoring can continue.'];
    }

    /**
     * How many places remain in each stand type.
     *
     * Counts OFFERED and ACCEPTED against the quota — an outstanding offer holds a place,
     * because offering more places than exist and hoping some decline is how an organiser
     * ends up with more vendors than pitches on the morning.
     *
     * The size travels with the row because every surface that shows capacity also shows the
     * pitch — a vendor deciding whether to apply needs "3 × 3 m, two left" as one fact, and
     * fetching the second half separately is how the two end up disagreeing.
     *
     * @return array<int,array{type:object,quota:int,taken:int,left:int,index:int,
     *                         size:string,each_sqm:float,total_sqm:float}>
     */
    public static function capacity(int $eventId): array
    {
        $out = [];
        $i   = 0;
        foreach (StandType::forEvent($eventId) as $t) {
            try {
                $taken = (int) DB::table('gates_stand_applications')
                    ->where('stand_type_id', $t->id)
                    ->whereIn('decision', [StandApplication::DECISION_OFFERED, StandApplication::DECISION_ACCEPTED])
                    ->count();
            } catch (\Throwable) {
                $taken = 0;
            }
            $quota = (int) $t->quota;
            $each  = StandType::areaSqm($t);
            $out[] = [
                'type' => $t, 'quota' => $quota, 'taken' => $taken, 'left' => max(0, $quota - $taken),
                // A fixed slot per type, so the colour of a stand type in the floor plan and
                // in the legend never moves when another type is added beside it.
                'index'     => $i++,
                // labelForType and NOT sizeLabel. sizeLabel always renders metres, so a
                // pitch added from the "6 × 6 ft stand" preset appeared in this table as
                // "1.83 × 1.83 m" — losing the unit the whole preset feature exists to
                // keep, and showing an organiser a number no vendor would recognise as
                // the thing they applied for.
                'size'      => StandPreset::labelForType($t),
                'each_sqm'  => $each,
                'total_sqm' => round($each * $quota, 2),
            ];
        }
        return $out;
    }

    // ──────────────────────── telling vendors it exists ─────────────────────

    /**
     * The one-line answer to "can a business trade at this event?", for the event pages.
     *
     * ── WHY THE EVENT PAGE HAS TO ASK AT ALL ─────────────────────────────────
     *
     * The call page is at `/events/{slug}/stands` and nothing linked to it. A vendor found it
     * by being told the address, which means the only businesses applying were the ones the
     * organiser already knew — which is the exact failure a published quota is supposed to
     * prevent. An open call nobody can find is a private invitation with extra steps.
     *
     * ── AND WHY A CLOSED CALL STILL RETURNS SOMETHING ────────────────────────
     *
     * Same reason {@see \AfricaGates\Controllers\StandApplyController::call()} keeps serving a
     * closed call instead of 404ing: a vendor who arrives a week late is owed "this closed on
     * the 14th" rather than silence, and next year they will know when to look. The `state`
     * says which it is so the page can be loud about an open call and quiet about a shut one.
     *
     * A DRAFT call returns null. Its terms are still being written, and half-published quotas
     * are how a number gets quoted before it is decided.
     *
     * @return array{state:string, url:string, closes_at:string, opens_at:string,
     *               left:int, quota:int, kinds:int, from:int}|null
     */
    public static function nudge(int $eventId, string $slug, bool $isPast = false): ?array
    {
        $call = self::forEvent($eventId);
        if (!$call) return null;

        $status = (string) ($call->status ?? '');
        if ($status === self::STATUS_DRAFT || $status === '') return null;

        // A finished event has nothing to apply for, whatever the call still says. `closes_at`
        // is usually before the event and catches this, but it is nullable — and a call with
        // no closing date on a past event would otherwise still read as open.
        if ($isPast) return null;

        $now   = date('Y-m-d H:i:s');
        $opens = self::stamp((string) ($call->opens_at ?? ''));

        $state = match (true) {
            self::isAccepting($call)          => 'open',
            $opens !== '' && $opens > $now    => 'soon',
            default                           => 'closed',
        };

        // Totals across the categories, not a per-category breakdown: the breakdown is the
        // call page's job and repeating it here would be two places to disagree about how many
        // stands are left. `capacity()` costs one query per stand type, which is fine for one
        // event — see openFor() for the list page, which must not pay that per row.
        $left = $quota = $kinds = 0;
        $from = null;
        foreach (self::capacity($eventId) as $c) {
            $kinds++;
            $left  += (int) $c['left'];
            $quota += (int) $c['quota'];
            $price  = (int) ($c['type']->price_naira ?? 0);
            if ($price > 0 && ($from === null || $price < $from)) $from = $price;
        }

        return [
            'state'     => $state,
            'url'       => '/events/' . ltrim($slug, '/') . '/stands',
            'closes_at' => self::stamp((string) ($call->closes_at ?? '')),
            'opens_at'  => $opens,
            'left'      => $left,
            'quota'     => $quota,
            'kinds'     => $kinds,
            // 0 rather than null for "every stand is free", so a template can print the
            // figure without deciding what an absent price means.
            'from'      => (int) ($from ?? 0),
        ];
    }

    /**
     * Which of these events are taking stand applications right now.
     *
     * ONE query for the whole list, and no capacity counting. The events index renders up to a
     * few dozen cards; asking {@see nudge()} per card would be a query per stand type per
     * event, to print a four-word chip. All a card needs is that the door is open.
     *
     * The clock check is done here rather than in SQL so it is the same comparison
     * {@see isAccepting()} makes — two implementations of "is this call open" is how a card
     * ends up advertising a call the apply page then refuses.
     *
     * @param  list<int> $eventIds
     * @return array<int, array{closes_at:string}> keyed by event id; absent = not accepting
     */
    public static function openFor(array $eventIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
        if ($ids === []) return [];

        try {
            $rows = DB::table('gates_stand_calls')
                ->whereIn('event_id', $ids)
                ->where('status', self::STATUS_OPEN)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!self::isAccepting($row)) continue;
            $out[(int) $row->event_id] = ['closes_at' => self::stamp((string) ($row->closes_at ?? ''))];
        }
        return $out;
    }
}
