<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * How effective a volunteer is (owner, 30 Oct 2026: "we need to be able to know how effective the
 * volunteers are"). The one answer, read by the volunteer's own record, the lead's team and the
 * staff list, so the three cannot disagree about the same person.
 *
 * Every figure is counted from what happened, over the last six months, and none is typed:
 *   hours        verified hours, and the number of weeks they were given in
 *   tasks        finished (accepted by a lead), handed back, and taken but past their due date
 *   on time      of finished tasks that had a due date, how many were in by it
 *   turned up    of past events they held a role at, how many they checked in to
 *
 * RELIABILITY is the one summary: what they finished or turned up to, out of what they took on.
 * It is never shown on fewer than three commitments — two is a coin, and a person judged on two
 * has been judged on luck. Hours are not in it: somebody giving two careful hours a month is not
 * less reliable than somebody giving twenty, and a figure that says so pushes people to log time.
 */
final class Effect
{
    public const DAYS = 182;
    private const MIN = 3;

    /**
     * @return array{hours:float, weeks:int, per_week:float, done:int, dropped:int, late:int, open:int,
     *               on_time:?int, on_time_of:int, roles:int, shown:int, reliability:?int, word:string, tone:string}
     */
    public static function of(object $v): array
    {
        $since = date('Y-m-d', time() - self::DAYS * 86400);
        $sinceAt = $since . ' 00:00:00';
        $now = date('Y-m-d H:i:s');
        $vid = (int) $v->id;

        $hourRows = DB::table('gates_vol_hours')->where('volunteer_id', $vid)->where('status', 'verified')->where('worked_on', '>=', $since)->get(['worked_on', 'hours']);
        $hours = 0.0;
        $weeks = [];
        foreach ($hourRows as $h) {
            if ((float) $h->hours <= 0) continue;
            $hours += (float) $h->hours;
            $weeks[date('o-W', (int) strtotime((string) $h->worked_on))] = true;
        }

        $takes = DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')
            ->where('k.volunteer_id', $vid)->where('k.taken_at', '>=', $sinceAt)->get(['k.status', 'k.done_at', 't.due_at']);
        $done = $dropped = $late = $open = $onTime = $dated = 0;
        foreach ($takes as $k) {
            if ($k->status === 'done') {
                $done++;
                if ($k->due_at) { $dated++; if ($k->done_at && $k->done_at <= $k->due_at) $onTime++; }
            } elseif ($k->status === 'dropped') {
                $dropped++;
            } elseif ($k->due_at && $k->due_at < $now) {
                $late++;
            } else {
                $open++;
            }
        }

        // Past events they held a role at (a role that was revoked was taken away, not missed).
        $roles = $shown = 0;
        $events = DB::table('gates_grants as g')->join('gates_site_events as e', 'e.id', '=', 'g.scope_id')
            ->where('g.actor_kind', 'member')->where('g.actor_id', $v->user_id)->where('g.scope_kind', 'event')->where('g.capability', 'like', 'vol.%')
            ->whereNull('g.revoked_at')->where('e.event_date', '>=', $sinceAt)->where('e.event_date', '<', $now)->distinct()->pluck('e.id')->all();
        $roles = count($events);
        if ($roles && SchemaHas::table('gates_vol_attendance')) {
            $shown = (int) DB::table('gates_vol_attendance')->where('volunteer_id', $vid)->whereIn('event_id', $events)->distinct()->count('event_id');
        }

        $of = $done + $dropped + $late + $roles;
        $rel = $of >= self::MIN ? (int) round(100 * ($done + $shown) / $of) : null;
        [$word, $tone] = match (true) {
            $rel === null => ['Too early to say', 'mute'],
            $rel >= 80 => ['Reliable', 'ok'],
            $rel >= 50 => ['Mostly reliable', 'info'],
            default => ['Needs a word', 'warn'],
        };
        return ['hours' => round($hours, 2), 'weeks' => count($weeks), 'per_week' => $weeks ? round($hours / count($weeks), 1) : 0.0,
                'done' => $done, 'dropped' => $dropped, 'late' => $late, 'open' => $open,
                'on_time' => $dated >= self::MIN ? (int) round(100 * $onTime / $dated) : null, 'on_time_of' => $dated,
                'roles' => $roles, 'shown' => $shown, 'reliability' => $rel, 'word' => $word, 'tone' => $tone];
    }

    /** The chip a list shows: "Reliable · 92%", or "Too early to say". @return array{0:string,1:string} */
    public static function chip(array $e): array
    {
        return [$e['word'] . ($e['reliability'] !== null ? ' · ' . $e['reliability'] . '%' : ''), $e['tone']];
    }

    /** One line for a list, beside the chip: "14h in 5 weeks · 6 tasks finished". */
    public static function line(array $e): string
    {
        return ($e['hours'] > 0 ? Level::h($e['hours']) . ' in ' . $e['weeks'] . ($e['weeks'] === 1 ? ' week' : ' weeks') : 'No hours yet')
            . ' · ' . $e['done'] . ($e['done'] === 1 ? ' task' : ' tasks') . ' finished';
    }

    /** How the reliability figure is counted, in words, from the same numbers. */
    public static function explain(array $e): string
    {
        $parts = [];
        $parts[] = $e['done'] . ' ' . ($e['done'] === 1 ? 'task' : 'tasks') . ' finished';
        if ($e['dropped']) $parts[] = $e['dropped'] . ' handed back';
        if ($e['late']) $parts[] = $e['late'] . ' past due';
        if ($e['roles']) $parts[] = 'checked in at ' . $e['shown'] . ' of ' . $e['roles'] . ' ' . ($e['roles'] === 1 ? 'event' : 'events');
        $s = 'Last six months: ' . implode(', ', $parts) . '.';
        return $e['reliability'] === null ? $s . ' Reliability shows after three tasks or events.' : $s;
    }
}
