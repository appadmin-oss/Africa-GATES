<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Tasks: work a volunteer can do from anywhere, from a library (plan §7.3 "Tasks come from a
 * library of templates"). Each says HOW IT IS CHECKED, because a task whose checking is
 * unstated is one whose hours nobody can verify — and a self-report alone never counts.
 */
final class Tasks
{
    public const CHECKED_BY = ['lead' => 'A lead confirms it', 'sample' => 'A sample is checked', 'phone' => 'Phone verification', 'scan' => 'Scans at the venue'];

    /** @return list<object> open tasks the person has not taken */
    public static function library(object $v): array
    {
        $taken = DB::table('gates_vol_task_takes')->where('volunteer_id', $v->id)->whereIn('status', ['taken', 'done'])->pluck('task_id')->all();
        $q = DB::table('gates_vol_tasks')->where('status', 'open')
            ->where(static fn ($w) => $w->whereNull('due_at')->orWhere('due_at', '>', date('Y-m-d H:i:s')));
        if ($taken) $q->whereNotIn('id', $taken);
        $rows = $q->orderBy('due_at')->orderByDesc('id')->limit(100)->get()->all();
        // An EVENT's task is for the people working that event (a lead posts it for their own
        // night's team), so it is offered only to a volunteer holding a role there. Award-wide
        // and unplaced tasks stay open to everybody, as they were.
        $events = [];
        foreach (Workspace::places($v) as $p) if ($p['kind'] === 'event') $events[$p['id']] = true;
        $rows = array_values(array_filter($rows, static fn ($t) => (string) $t->scope_kind !== 'event' || isset($events[(int) $t->scope_id])));
        foreach ($rows as $t) $t->trained = $t->module_key ? Training::holds((int) $v->id, (string) $t->module_key) : true;
        return $rows;
    }

    /** @param 'taken'|'done' $status @return list<object> */
    public static function mine(object $v, string $status = 'taken'): array
    {
        return DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')
            ->where('k.volunteer_id', $v->id)->where('k.status', $status)->orderByDesc('k.taken_at')
            ->get(['t.*', 'k.id as take_id', 'k.progress', 'k.accepted', 'k.taken_at', 'k.done_at'])->all();
    }

    /** @return array{ok:bool, error?:string} */
    public static function take(object $v, int $taskId): array
    {
        if (!in_array(Volunteers::role($v), ['new', 'active', 'lead'], true)) return ['ok' => false, 'error' => 'Tasks open once your application is approved.'];
        $t = DB::table('gates_vol_tasks')->where('id', $taskId)->where('status', 'open')->first();
        if (!$t) return ['ok' => false, 'error' => 'That task is not open.'];
        // The same rule as the library: an event's task is taken only by somebody on that event.
        if ((string) $t->scope_kind === 'event' && !Workspace::place($v, 'event', (int) $t->scope_id)) return ['ok' => false, 'error' => 'That task is for the team at another event.'];
        if ($t->module_key && !Training::holds((int) $v->id, (string) $t->module_key)) return ['ok' => false, 'error' => 'This task needs training first.'];
        $have = DB::table('gates_vol_task_takes')->where('task_id', $taskId)->where('volunteer_id', $v->id)->first();
        if ($have) {
            if ($have->status === 'dropped') DB::table('gates_vol_task_takes')->where('id', $have->id)->update(['status' => 'taken', 'taken_at' => date('Y-m-d H:i:s')]);
            return ['ok' => true];
        }
        DB::table('gates_vol_task_takes')->insert(['task_id' => $taskId, 'volunteer_id' => $v->id, 'status' => 'taken', 'taken_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true];
    }

    public static function drop(object $v, int $taskId): bool
    {
        return DB::table('gates_vol_task_takes')->where('task_id', $taskId)->where('volunteer_id', $v->id)->where('status', 'taken')
            ->update(['status' => 'dropped']) > 0;
    }

    /**
     * Staff accept a take as done, with the hours it earned. The hours are verified at once:
     * accepting IS the check.
     */
    public static function accept(int $takeId, float $hours, int $accepted, string $byName): bool
    {
        // The same bounds the event path has always had. Staff could accept any figure: a
        // negative one closed the task and wrote nothing, and 10,000 or more overflows
        // DECIMAL(6,2) on MySQL — after the take was already marked done (AUDIT-AND-PLAN VO-2).
        if ($hours <= 0 || $hours > 24) return false;
        $k = DB::table('gates_vol_task_takes')->where('id', $takeId)->where('status', 'taken')->first();
        if (!$k) return false;
        $t = DB::table('gates_vol_tasks')->where('id', $k->task_id)->first();
        if (!$t) return false;
        // Closing the take and writing its hours are ONE act: a take marked done with no
        // hours row is work nobody can see was ever credited.
        return DB::connection()->transaction(static function () use ($k, $t, $takeId, $hours, $accepted, $byName): bool {
            $n = DB::table('gates_vol_task_takes')->where('id', $takeId)->where('status', 'taken')->update(['status' => 'done', 'done_at' => date('Y-m-d H:i:s'),
                'accepted' => max(0, $accepted), 'progress' => max((int) $k->progress, $accepted)]);
            if ($n === 0) return false;
            $k->status = 'done';
            Hours::fromTask($k, $t, $hours, $byName);
            return true;
        });
    }

    /** Staff record progress on a take ("41 of 58 verified"). */
    public static function progress(int $takeId, int $done, int $accepted): bool
    {
        return DB::table('gates_vol_task_takes')->where('id', $takeId)->where('status', 'taken')
            ->update(['progress' => max(0, $done), 'accepted' => max(0, min($done, $accepted))]) > 0;
    }

    // ── An event's own tasks, set by its lead (owner, 5 Nov 2026) ─────────────────────────
    //
    // A lead posts work for THEIR event from the lead console (EventPages, People › Volunteer
    // tasks); it is live at once. Every one of these takes the event as an argument and refuses
    // a task or a take that is not that event's, so a crafted post about another event's task
    // is answered as if the task did not exist. The caller checks the member may do this at
    // the event (Plan::may 'volunteers.tasks'); these check the task belongs there.

    /** @return array{ok:bool, error?:string, id?:int} */
    public static function postForEvent(int $eventId, array $f, int $memberId, string $memberName): array
    {
        if ($eventId < 1 || !DB::table('gates_site_events')->where('id', $eventId)->exists()) return ['ok' => false, 'error' => 'That event was not found.'];
        // The module must be one that exists, or the task could never be taken by anybody.
        $module = trim((string) ($f['module_key'] ?? ''));
        if ($module !== '' && !isset(Training::titles()[$module])) return ['ok' => false, 'error' => 'That training module does not exist.'];
        $r = self::create(['scope_kind' => 'event', 'scope_id' => $eventId, 'module_key' => $module] + $f, 0);
        if (!$r['ok']) return ['ok' => false, 'error' => implode(' ', $r['errors'] ?? ['The task could not be posted.'])];
        DB::table('gates_vol_tasks')->where('id', $r['id'])->update(['created_by' => null]);
        if (\AfricaGates\Support\SchemaHas::column('gates_vol_tasks', 'posted_by_member')) {
            DB::table('gates_vol_tasks')->where('id', $r['id'])->update(['posted_by_member' => $memberId, 'posted_by_name' => mb_substr($memberName, 0, 120)]);
        }
        return ['ok' => true, 'id' => (int) $r['id']];
    }

    /** Close an event's task: nobody new can take it; takes already running stay theirs to finish. */
    public static function closeForEvent(int $eventId, int $taskId): bool
    {
        return DB::table('gates_vol_tasks')->where('id', $taskId)->where('scope_kind', 'event')->where('scope_id', $eventId)
            ->where('status', 'open')->update(['status' => 'closed']) > 0;
    }

    /** The take must be on one of THIS event's tasks; accepting it is the check, as for staff. */
    public static function acceptForEvent(int $eventId, int $takeId, float $hours, string $byName): array
    {
        $k = DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')
            ->where('k.id', $takeId)->where('t.scope_kind', 'event')->where('t.scope_id', $eventId)->first(['k.id', 'k.status']);
        if (!$k) return ['ok' => false, 'error' => 'That piece of work is not on this event.'];
        if ($k->status !== 'taken') return ['ok' => false, 'error' => 'That has already been accepted, or was dropped.'];
        if ($hours <= 0 || $hours > 24) return ['ok' => false, 'error' => 'Give the hours it took, between a quarter of an hour and 24.'];
        return self::accept($takeId, $hours, 0, $byName) ? ['ok' => true] : ['ok' => false, 'error' => 'That could not be accepted.'];
    }

    /** An event's tasks, open and closed, with who has them. @return list<object> */
    public static function forEvent(int $eventId): array
    {
        $tasks = DB::table('gates_vol_tasks')->where('scope_kind', 'event')->where('scope_id', $eventId)
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderBy('due_at')->orderByDesc('id')->get()->all();
        foreach ($tasks as $t) {
            $t->takes = DB::table('gates_vol_task_takes as k')->join('gates_volunteers as v', 'v.id', '=', 'k.volunteer_id')
                ->where('k.task_id', $t->id)->whereIn('k.status', ['taken', 'done'])->orderBy('k.taken_at')
                // The volunteer's columns FIRST: both tables have `status` (and `id`), and the later
                // column wins, so the take's own status must come after `v.*` or every take reads
                // as the volunteer's 'active'.
                ->get(['v.*', 'k.id as take_id', 'k.status', 'k.taken_at', 'k.done_at'])->all();
        }
        return $tasks;
    }

    /** @return array{ok:bool, errors?:list<string>, id?:int} */
    public static function create(array $f, int $adminId): array
    {
        $title = trim((string) ($f['title'] ?? ''));
        $by = (string) ($f['checked_by'] ?? 'lead');
        $e = [];
        if ($title === '') $e[] = 'Give the task a name.';
        if (!isset(self::CHECKED_BY[$by])) $e[] = 'Say how the task is checked.';
        if ($e !== []) return ['ok' => false, 'errors' => $e];
        $due = strtotime((string) ($f['due_at'] ?? ''));
        $id = (int) DB::table('gates_vol_tasks')->insertGetId([
            'title' => mb_substr($title, 0, 190), 'body' => mb_substr(trim((string) ($f['body'] ?? '')), 0, 4000) ?: null,
            'scope_kind' => in_array($f['scope_kind'] ?? '', ['event', 'cycle'], true) ? $f['scope_kind'] : null,
            'scope_id' => (int) ($f['scope_id'] ?? 0) ?: null, 'checked_by' => $by,
            'checked_note' => mb_substr(trim((string) ($f['checked_note'] ?? '')), 0, 190) ?: null,
            'module_key' => trim((string) ($f['module_key'] ?? '')) ?: null, 'est_minutes' => (int) ($f['est_minutes'] ?? 0) ?: null,
            'points' => max(0, (int) ($f['points'] ?? 0)), 'target' => (int) ($f['target'] ?? 0) ?: null,
            'from_home' => empty($f['from_home']) ? 0 : 1, 'due_at' => $due ? date('Y-m-d H:i:s', $due) : null,
            'status' => 'open', 'created_by' => $adminId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id];
    }
}
