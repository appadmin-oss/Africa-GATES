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
        $opens = trim((string) ($call->opens_at  ?? ''));
        $close = trim((string) ($call->closes_at ?? ''));

        if ($opens !== '' && $opens > $now) return false;
        if ($close !== '' && $close < $now) return false;
        return true;
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

        $closes = trim((string) ($in['closes_at'] ?? ''));
        $opens  = trim((string) ($in['opens_at'] ?? ''));
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
     * @return array<int,array{type:object,quota:int,taken:int,left:int}>
     */
    public static function capacity(int $eventId): array
    {
        $out = [];
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
            $out[] = ['type' => $t, 'quota' => $quota, 'taken' => $taken, 'left' => max(0, $quota - $taken)];
        }
        return $out;
    }
}
