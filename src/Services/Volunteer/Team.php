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
 * whoever holds a volunteer grant at that same place. So the team changes when the roles do,
 * and a lead whose grant has ended leads nobody — with nothing to tidy up.
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
            foreach (DB::table('gates_grants as g')->join('gates_volunteers as v', 'v.user_id', '=', 'g.actor_id')
                         ->where('g.actor_kind', 'member')->where('g.scope_kind', $kind)->where('g.scope_id', $id)
                         ->whereNull('g.revoked_at')->where('g.expires_at', '>', date('Y-m-d H:i:s'))
                         ->pluck('v.id') as $vid) $ids[(int) $vid] = true;
        }
        unset($ids[(int) $lead->id]);
        return array_keys($ids);
    }

    /** @return list<object> the team, each with ->name, ->level, ->hours and ->tasks (open tasks they have taken) */
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
            $v->tasks = count(Tasks::mine($v));
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

    /** May this lead confirm this volunteer's hours? Only their own team's. */
    public static function leads(object $lead, int $volunteerId): bool
    {
        return in_array($volunteerId, self::memberIds($lead), true);
    }
}
