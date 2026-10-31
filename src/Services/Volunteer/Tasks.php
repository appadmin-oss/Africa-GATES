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
        $k = DB::table('gates_vol_task_takes')->where('id', $takeId)->where('status', 'taken')->first();
        if (!$k) return false;
        $t = DB::table('gates_vol_tasks')->where('id', $k->task_id)->first();
        if (!$t) return false;
        DB::table('gates_vol_task_takes')->where('id', $takeId)->update(['status' => 'done', 'done_at' => date('Y-m-d H:i:s'),
            'accepted' => max(0, $accepted), 'progress' => max((int) $k->progress, $accepted)]);
        $k->status = 'done';
        Hours::fromTask($k, $t, $hours, $byName);
        return true;
    }

    /** Staff record progress on a take ("41 of 58 verified"). */
    public static function progress(int $takeId, int $done, int $accepted): bool
    {
        return DB::table('gates_vol_task_takes')->where('id', $takeId)->where('status', 'taken')
            ->update(['progress' => max(0, $done), 'accepted' => max(0, min($done, $accepted))]) > 0;
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
