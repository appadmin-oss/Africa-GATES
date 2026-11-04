<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Time on site: arriving at and leaving an event the volunteer holds a role at
 * (owner, 30 Oct 2026: "the hours should still be calculated").
 *
 * Shifts carried the only observed hours on the platform — their check-in and check-out — so
 * removing them left every hour a task or a hand-logged claim. A visit replaces them without
 * bringing the rota back: a role at an event is the permission, the event's own times are the
 * window, and the two taps are the evidence.
 *
 * Who tapped decides what the hours are worth, and it is stored per end:
 *   · a lead (or staff) recorded either end  → verified at once, as a scan always was;
 *   · the volunteer recorded both ends       → in review, confirmed by a lead like hours by hand.
 * A visit over 16 hours is somebody who forgot to leave; it is capped and always reviewed.
 */
final class Attendance
{
    public const SELF = 'self';
    public const LEAD = 'lead';
    private const CAP = 16.0;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_vol_attendance');
    }

    /** The volunteer holds a live role at this event. */
    public static function holdsRole(object $v, int $eventId): bool
    {
        return DB::table('gates_grants')->where('actor_kind', 'member')->where('actor_id', $v->user_id)->where('scope_kind', 'event')
            ->where('scope_id', $eventId)->where('capability', 'like', 'vol.%')->whereNull('revoked_at')
            ->where(static fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s')))->exists();
    }

    /**
     * When arriving can be recorded: from six hours before the event starts to six hours after it
     * ends (twelve hours after the start when no end is set). Set-up and clear-down are work too.
     *
     * @return array{0:int,1:int}|null
     */
    public static function window(int $eventId): ?array
    {
        $e = DB::table('gates_site_events')->where('id', $eventId)->first(['event_date', 'end_date']);
        if (!$e) return null;
        $start = (int) strtotime((string) $e->event_date);
        $end = $e->end_date ? (int) strtotime((string) $e->end_date) : $start + 12 * 3600;
        return [$start - 6 * 3600, max($end, $start) + 6 * 3600];
    }

    public static function isOpenNow(int $eventId): bool
    {
        $w = self::window($eventId);
        return $w !== null && time() >= $w[0] && time() <= $w[1];
    }

    /** The visit still open (arrived, not left), if any. */
    public static function open(int $volunteerId, int $eventId): ?object
    {
        return DB::table('gates_vol_attendance')->where('volunteer_id', $volunteerId)->where('event_id', $eventId)->whereNull('out_at')->orderByDesc('id')->first();
    }

    /** @return array{ok:bool, error?:string} */
    public static function arrive(object $v, int $eventId, string $by, string $byName): array
    {
        if (!self::holdsRole($v, $eventId)) return ['ok' => false, 'error' => 'Only somebody with a role at this event can be checked in.'];
        if (!self::isOpenNow($eventId)) return ['ok' => false, 'error' => 'Check-in opens six hours before the event and closes six hours after it.'];
        if (self::open((int) $v->id, $eventId)) return ['ok' => false, 'error' => 'Already checked in.'];
        DB::table('gates_vol_attendance')->insert(['volunteer_id' => $v->id, 'event_id' => $eventId, 'in_at' => date('Y-m-d H:i:s'),
            'in_by' => $by === self::LEAD ? self::LEAD : self::SELF, 'in_name' => mb_substr($byName, 0, 190), 'created_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string, hours?:float, review?:bool} */
    public static function leave(object $v, int $eventId, string $by, string $byName): array
    {
        $a = self::open((int) $v->id, $eventId);
        if (!$a) return ['ok' => false, 'error' => 'Not checked in.'];
        $by = $by === self::LEAD ? self::LEAD : self::SELF;
        $now = time();
        $raw = max(0.0, ($now - (int) strtotime((string) $a->in_at)) / 3600);
        $hours = round(min($raw, self::CAP) * 4) / 4;
        $review = ($a->in_by !== self::LEAD && $by !== self::LEAD) || $raw > self::CAP;
        $title = (string) (DB::table('gates_site_events')->where('id', $eventId)->value('title') ?? 'An event');
        $witness = $a->in_by === self::LEAD ? (string) $a->in_name : ($by === self::LEAD ? $byName : '');
        $hid = (int) DB::table('gates_vol_hours')->insertGetId([
            'volunteer_id' => $v->id, 'source' => 'scan', 'ref_kind' => 'attend', 'ref_id' => $a->id,
            'worked_on' => date('Y-m-d', (int) strtotime((string) $a->in_at)), 'what' => mb_substr('On site: ' . $title . ($raw > self::CAP ? ' (left without checking out; capped at 16 hours)' : ''), 0, 255), 'hours' => $hours,
            'status' => $review ? 'review' : 'verified', 'witness' => $witness !== '' ? mb_substr($witness, 0, 190) : null,
            'confirmed_kind' => $review ? null : 'member', 'confirmed_name' => $review ? null : mb_substr($witness, 0, 190),
            'confirmed_at' => $review ? null : date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('gates_vol_attendance')->where('id', $a->id)->update(['out_at' => date('Y-m-d H:i:s', $now), 'out_by' => $by,
            'out_name' => mb_substr($byName, 0, 190), 'hours_id' => $hid]);
        return ['ok' => true, 'hours' => $hours, 'review' => $review];
    }

    /**
     * Everybody with a role at the event and how their visit stands, for the lead's page.
     *
     * @return list<object> each with ->vol, ->name, ->role, ->here (bool), ->since, ->hours (closed visits)
     */
    public static function board(int $eventId): array
    {
        $out = [];
        foreach (DB::table('gates_grants as g')->join('gates_volunteers as v', 'v.user_id', '=', 'g.actor_id')
                     ->where('g.actor_kind', 'member')->where('g.scope_kind', 'event')->where('g.scope_id', $eventId)->where('g.capability', 'like', 'vol.%')
                     ->whereNull('g.revoked_at')->where(static fn ($w) => $w->whereNull('g.expires_at')->orWhere('g.expires_at', '>', date('Y-m-d H:i:s')))
                     ->get(['v.*', 'g.capability']) as $v) {
            if (isset($out[(int) $v->id])) continue;
            $open = self::ready() ? self::open((int) $v->id, $eventId) : null;
            $out[(int) $v->id] = (object) ['vol' => $v, 'name' => Volunteers::name($v), 'role' => \AfricaGates\Services\Grants::CAPABILITIES[$v->capability] ?? 'Volunteer',
                'here' => $open !== null, 'since' => $open?->in_at, 'hours' => self::ready() ? self::hoursAt((int) $v->id, $eventId) : 0.0];
        }
        return array_values($out);
    }

    /** Hours from closed visits to one event, verified or in review (declined ones do not count). */
    public static function hoursAt(int $volunteerId, int $eventId): float
    {
        return round((float) DB::table('gates_vol_attendance as a')->join('gates_vol_hours as h', 'h.id', '=', 'a.hours_id')
            ->where('a.volunteer_id', $volunteerId)->where('a.event_id', $eventId)->whereIn('h.status', ['verified', 'review'])->sum('h.hours'), 2);
    }
}
