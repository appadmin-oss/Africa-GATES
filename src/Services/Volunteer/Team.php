<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\Grants;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A TEAM LEAD'S TEAM (handoff §6.1 "Your team", §6.3 "Leads confirming hours").
 *
 * A lead's team is not a list somebody keeps. It is everybody who volunteers WHERE the lead
 * leads: a `vol.team_lead` grant at an event or an award edition makes its holder the lead of
 * whoever holds a shift or a volunteer grant at that same place. So the team changes when the
 * shifts do, and a lead whose grant has ended leads nobody — with nothing to tidy up.
 */
final class Team
{
    /** @return list<array{0:string,1:int}> the places this lead leads, as [kind, id] */
    public static function scopes(object $lead): array
    {
        $out = [];
        foreach (Grants::forActor('member', (int) $lead->user_id, 'vol.team_lead') as $g) {
            if (in_array($g->scope_kind, ['cycle', 'event'], true) && $g->scope_id) $out[] = [(string) $g->scope_kind, (int) $g->scope_id];
        }
        return $out;
    }

    /** @return list<int> volunteer ids in this lead's team, never the lead themselves */
    public static function memberIds(object $lead): array
    {
        $ids = [];
        foreach (self::scopes($lead) as [$kind, $id]) {
            foreach (DB::table('gates_vol_signups as s')->join('gates_vol_shifts as h', 'h.id', '=', 's.shift_id')
                         ->where('h.scope_kind', $kind)->where('h.scope_id', $id)->whereIn('s.status', ['on', 'kept'])
                         ->pluck('s.volunteer_id') as $vid) $ids[(int) $vid] = true;
            foreach (DB::table('gates_grants as g')->join('gates_volunteers as v', 'v.user_id', '=', 'g.actor_id')
                         ->where('g.actor_kind', 'member')->where('g.scope_kind', $kind)->where('g.scope_id', $id)
                         ->whereNull('g.revoked_at')->where('g.expires_at', '>', date('Y-m-d H:i:s'))
                         ->pluck('v.id') as $vid) $ids[(int) $vid] = true;
        }
        unset($ids[(int) $lead->id]);
        return array_keys($ids);
    }

    /** @return list<object> the team, each with ->name, ->level, ->hours, ->next (their next shift or null) */
    public static function roster(object $lead): array
    {
        $ids = self::memberIds($lead);
        if ($ids === []) return [];
        $out = [];
        foreach (DB::table('gates_volunteers')->whereIn('id', $ids)->where('status', 'active')->get() as $v) {
            $v->name = Volunteers::name($v);
            $lv = Level::of($v);
            $v->level = $lv['level'];
            $v->hours = $lv['hours'];
            $mine = Shifts::mine($v);
            $v->next = $mine[0] ?? null;
            $out[] = $v;
        }
        usort($out, static fn ($a, $b) => strcmp((string) $a->name, (string) $b->name));
        return $out;
    }

    /** Hours the team logged by hand, waiting for the lead. @return list<object> */
    public static function waitingHours(object $lead): array
    {
        $ids = self::memberIds($lead);
        return $ids === [] ? [] : Hours::waiting($ids);
    }

    /**
     * Shifts where the lead leads that are not full, soonest first, each with up to two best
     * fits from the team: free at that time and holding the training it needs.
     *
     * @return list<object> each with ->filled, ->short, ->fits (list of names)
     */
    public static function gaps(object $lead): array
    {
        $out = [];
        $team = self::roster($lead);
        foreach (self::scopes($lead) as [$kind, $id]) {
            foreach (DB::table('gates_vol_shifts')->where('scope_kind', $kind)->where('scope_id', $id)->where('status', 'open')
                         ->where('starts_at', '>', date('Y-m-d H:i:s'))->orderBy('starts_at')->get() as $s) {
                $s->filled = (int) DB::table('gates_vol_signups')->where('shift_id', $s->id)->where('status', 'on')->count();
                $s->short = (int) $s->slots - $s->filled;
                if ($s->short <= 0) continue;
                $s->fits = [];
                foreach ($team as $m) {
                    if (count($s->fits) >= 2) break;
                    if ($s->module_key && !Training::holds((int) $m->id, (string) $s->module_key)) continue;
                    $busy = false;
                    foreach (Shifts::mine($m) as $x) if ((int) $x->id === (int) $s->id || Shifts::overlaps($x, $s)) { $busy = true; break; }
                    if (!$busy) $s->fits[] = Volunteers::firstName($m) . ' ' . mb_substr((string) preg_replace('/^\S+\s*/', '', $m->name), 0, 1) . '.';
                }
                $out[] = $s;
            }
        }
        return $out;
    }

    /** May this lead confirm this volunteer's hours? Only their own team's. */
    public static function leads(object $lead, int $volunteerId): bool
    {
        return in_array($volunteerId, self::memberIds($lead), true);
    }
}
