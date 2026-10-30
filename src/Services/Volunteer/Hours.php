<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Every hour a volunteer gives, with where it came from (plan §7.3; handoff §6.3 "Hours").
 *
 *   scan     check-in and check-out at an event (Attendance): verified when a lead tapped either
 *            end, in review when the volunteer tapped both
 *   signup   a shift a member of staff marked attended: witnessed (rows from before shifts were
 *            removed on 29 Oct 2026; kept and shown, never written again)
 *   task     accepted task work: witnessed by whoever checked it
 *   manual   logged by the person for work nobody saw: IN REVIEW until a lead confirms it
 *
 * Two rules the screens promise, held here so no caller can break them:
 *   · a confirmed hour is never edited — `confirm()` only moves a row out of `review`, and a
 *     correction is a request (Requests type `hours`);
 *   · one piece of evidence is one row — automatic rows carry `ref_kind`/`ref_id`, and writing the
 *     same task twice updates nothing.
 */
final class Hours
{
    public const SOURCES = ['scan' => 'On site', 'signup' => 'Marked attended', 'task' => 'Accepted work', 'manual' => 'By hand'];

    /**
     * Log hours by hand. Goes to review; a lead confirms it.
     *
     * @return array{ok:bool, error?:string, id?:int}
     */
    public static function log(object $v, string $date, string $what, float $hours, string $witness = '', string $proof = ''): array
    {
        $what = trim($what);
        $t = strtotime($date);
        if ($what === '') return ['ok' => false, 'error' => 'Say what you did.'];
        if ($t === false || $t > time()) return ['ok' => false, 'error' => 'Pick the day you did it; it cannot be in the future.'];
        if ($hours <= 0 || $hours > 16) return ['ok' => false, 'error' => 'Hours must be more than 0 and no more than 16 for one day.'];
        $proof = trim($proof);
        if ($proof !== '' && !preg_match('~^https?://~i', $proof)) return ['ok' => false, 'error' => 'A proof link starts with https://'];
        $id = (int) DB::table('gates_vol_hours')->insertGetId([
            'volunteer_id' => $v->id, 'source' => 'manual', 'worked_on' => date('Y-m-d', $t), 'what' => mb_substr($what, 0, 255),
            'hours' => round($hours * 4) / 4, 'status' => 'review', 'witness' => mb_substr(trim($witness), 0, 190) ?: null,
            'proof' => mb_substr($proof, 0, 255) ?: null, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** Add a proof link to an hour still in review. */
    public static function addProof(object $v, int $hourId, string $proof): bool
    {
        $proof = trim($proof);
        if (!preg_match('~^https?://~i', $proof)) return false;
        return DB::table('gates_vol_hours')->where('id', $hourId)->where('volunteer_id', $v->id)->where('status', 'review')
            ->update(['proof' => mb_substr($proof, 0, 255)]) > 0;
    }

    /**
     * A lead or staff confirms or declines hours in review. Declining needs a reason, and the
     * person sees it.
     */
    public static function confirm(int $hourId, bool $ok, string $byKind, int $byId, string $byName, string $reason = ''): array
    {
        $reason = trim($reason);
        if (!$ok && $reason === '') return ['ok' => false, 'error' => 'Say why, so they can fix it.'];
        $n = DB::table('gates_vol_hours')->where('id', $hourId)->where('status', 'review')->update([
            'status' => $ok ? 'verified' : 'declined', 'confirmed_kind' => $byKind, 'confirmed_id' => $byId,
            'confirmed_name' => mb_substr($byName, 0, 190), 'confirmed_at' => date('Y-m-d H:i:s'),
            'reason' => $ok ? null : mb_substr($reason, 0, 255),
        ]);
        return $n > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'Those hours have already been answered.'];
    }


    /** Hours from accepted task work. Idempotent per take. */
    public static function fromTask(object $take, object $task, float $hours, string $byName): void
    {
        if ($hours <= 0 || DB::table('gates_vol_hours')->where('ref_kind', 'take')->where('ref_id', $take->id)->exists()) return;
        DB::table('gates_vol_hours')->insert([
            'volunteer_id' => $take->volunteer_id, 'source' => 'task', 'ref_kind' => 'take', 'ref_id' => $take->id,
            'worked_on' => date('Y-m-d'), 'what' => mb_substr((string) $task->title, 0, 255), 'hours' => round($hours * 4) / 4,
            'status' => 'verified', 'confirmed_name' => mb_substr($byName, 0, 190) ?: null, 'confirmed_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The summary figures, each computed and none typed.
     *
     * @return array{verified:float, year:float, week:float, review:float, review_n:int, by_source:array<string,float>, limit:int}
     */
    public static function summary(object $v): array
    {
        $base = DB::table('gates_vol_hours')->where('volunteer_id', $v->id);
        $year = date('Y') . '-01-01';
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $bySource = [];
        foreach ((clone $base)->where('status', 'verified')->groupBy('source')->selectRaw('source, SUM(hours) as h')->get() as $r) {
            $bySource[(string) $r->source] = round((float) $r->h, 2);
        }
        return [
            'verified' => round((float) (clone $base)->where('status', 'verified')->sum('hours'), 2),
            'year' => round((float) (clone $base)->where('status', 'verified')->where('worked_on', '>=', $year)->sum('hours'), 2),
            'week' => round((float) (clone $base)->whereIn('status', ['verified', 'review'])->where('worked_on', '>=', $weekStart)->sum('hours'), 2),
            'review' => round((float) (clone $base)->where('status', 'review')->sum('hours'), 2),
            'review_n' => (int) (clone $base)->where('status', 'review')->count(),
            'by_source' => $bySource,
            'limit' => (int) ($v->max_hours_week ?: Rules::get('vol_week_hours_default')),
        ];
    }

    /**
     * Verified hours per week for the last `$weeks` weeks, split by source, oldest first.
     *
     * @return list<array{label:string, start:string, scan:float, task:float, manual:float}>
     */
    public static function weekly(object $v, int $weeks = 8): array
    {
        $out = [];
        $start = strtotime('monday this week') - ($weeks - 1) * 7 * 86400;
        for ($i = 0; $i < $weeks; $i++) {
            $a = $start + $i * 7 * 86400;
            $out[] = ['label' => date('j M', $a), 'start' => date('Y-m-d', $a), 'scan' => 0.0, 'task' => 0.0, 'manual' => 0.0];
        }
        $rows = DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('status', 'verified')
            ->where('worked_on', '>=', date('Y-m-d', $start))->get(['worked_on', 'source', 'hours']);
        foreach ($rows as $r) {
            $i = (int) floor((strtotime((string) $r->worked_on) - $start) / (7 * 86400));
            if (!isset($out[$i])) continue;
            $k = in_array($r->source, ['scan', 'signup'], true) ? 'scan' : ((string) $r->source === 'task' ? 'task' : 'manual');
            $out[$i][$k] += (float) $r->hours;
        }
        return $out;
    }

    /**
     * @param 'all'|'auto'|'hand'|'review' $view
     * @return list<object>
     */
    public static function rows(object $v, string $view = 'all', int $limit = 200): array
    {
        $q = DB::table('gates_vol_hours')->where('volunteer_id', $v->id);
        if ($view === 'auto') $q->whereIn('source', ['scan', 'signup', 'task']);
        if ($view === 'hand') $q->where('source', 'manual');
        if ($view === 'review') $q->where('status', 'review');
        return $q->orderByDesc('worked_on')->orderByDesc('id')->limit($limit)->get()->all();
    }

    /** Hours waiting for a person to confirm, for leads and staff. @return list<object> */
    public static function waiting(?array $volunteerIds = null): array
    {
        $q = DB::table('gates_vol_hours as h')->join('gates_volunteers as v', 'v.id', '=', 'h.volunteer_id')
            ->join('gates_users as u', 'u.id', '=', 'v.user_id')->where('h.status', 'review');
        if ($volunteerIds !== null) {
            if ($volunteerIds === []) return [];
            $q->whereIn('h.volunteer_id', $volunteerIds);
        }
        return $q->orderBy('h.id')->limit(200)->get(['h.*', 'v.display_name', 'u.name as user_name'])->all();
    }
}
