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

    /**
     * A take's life. `handed_in` is the volunteer saying "finished": until it existed only staff
     * could end a take, so finished work sat as `taken` — counted LATE by Effect once its due
     * date passed, and measured on time by the day staff accepted it rather than the day it was
     * done. Every reader of the status goes through these, so a new state cannot fall out of a
     * list that spelled the old ones by hand.
     */
    public const TAKEN = 'taken';
    public const HANDED_IN = 'handed_in';
    public const DONE = 'done';
    public const DROPPED = 'dropped';
    /** Held by the volunteer and not yet accepted: what "Mine" and every "on the go" count mean. */
    public const ON_THE_GO = [self::TAKEN, self::HANDED_IN];

    /**
     * Tasks anybody may still take: open, and not past their due date. The ONE definition — the
     * library, the place page and the new-task notice all ask it, because the place page listing
     * past-due tasks with "Take it" (while the library hid them) is how two lists came to
     * disagree about the same task.
     */
    public static function offered(): \Illuminate\Database\Query\Builder
    {
        return DB::table('gates_vol_tasks')->where('status', 'open')
            ->where(static fn ($w) => $w->whereNull('due_at')->orWhere('due_at', '>', date('Y-m-d H:i:s')));
    }

    /**
     * Does this task fit this person: trained for it (or it needs none), and if it is work from
     * home, they said they take work from home. One answer for the "Fits you" chip and for who is
     * told a new task was posted, so the mail cannot offer what the page calls a poor fit.
     */
    public static function fits(object $v, object $t): bool
    {
        if ($t->module_key && !Training::holds((int) $v->id, (string) $t->module_key)) return false;
        return !(int) $t->from_home || (int) ($v->from_home ?? 1) === 1;
    }

    /** @return list<object> open tasks the person has not taken */
    public static function library(object $v): array
    {
        $taken = DB::table('gates_vol_task_takes')->where('volunteer_id', $v->id)->whereIn('status', [...self::ON_THE_GO, self::DONE])->pluck('task_id')->all();
        $q = self::offered();
        if ($taken) $q->whereNotIn('id', $taken);
        $rows = $q->orderBy('due_at')->orderByDesc('id')->limit(100)->get()->all();
        foreach ($rows as $t) {
            $t->trained = $t->module_key ? Training::holds((int) $v->id, (string) $t->module_key) : true;
            $t->fits = self::fits($v, $t);
        }
        return $rows;
    }

    /** @param 'taken'|'done' $status 'taken' means on the go: taken or handed in @return list<object> */
    public static function mine(object $v, string $status = 'taken'): array
    {
        return DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')
            ->where('k.volunteer_id', $v->id)->whereIn('k.status', $status === self::DONE ? [self::DONE] : self::ON_THE_GO)->orderByDesc('k.taken_at')
            ->get(['t.*', 'k.id as take_id', 'k.status as take_status', 'k.progress', 'k.accepted', 'k.taken_at', 'k.handed_in_at', 'k.done_at'])->all();
    }

    /** @return array{ok:bool, error?:string} */
    public static function take(object $v, int $taskId): array
    {
        if (!in_array(Volunteers::role($v), ['new', 'active', 'lead'], true)) return ['ok' => false, 'error' => 'Tasks open once your application is approved.'];
        $t = DB::table('gates_vol_tasks')->where('id', $taskId)->where('status', 'open')->first();
        if (!$t) return ['ok' => false, 'error' => 'That task is not open.'];
        if ($t->due_at && (string) $t->due_at <= date('Y-m-d H:i:s')) return ['ok' => false, 'error' => 'That task’s due date has passed, so it can’t be taken now.'];
        if ($t->module_key && !Training::holds((int) $v->id, (string) $t->module_key)) return ['ok' => false, 'error' => 'This task needs training first.'];
        $have = DB::table('gates_vol_task_takes')->where('task_id', $taskId)->where('volunteer_id', $v->id)->first();
        if ($have) {
            if ($have->status === self::DONE) return ['ok' => false, 'error' => 'You’ve already finished this task. It’s under Tasks › Done.'];
            if ($have->status === self::DROPPED) DB::table('gates_vol_task_takes')->where('id', $have->id)->where('status', self::DROPPED)
                ->update(['status' => self::TAKEN, 'taken_at' => date('Y-m-d H:i:s'), 'handed_in_at' => null]);
            return ['ok' => true];
        }
        DB::table('gates_vol_task_takes')->insert(['task_id' => $taskId, 'volunteer_id' => $v->id, 'status' => self::TAKEN, 'taken_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true];
    }

    /**
     * The volunteer says the work is finished. It moves to `handed_in` and waits for staff to
     * accept it; the time it was handed in is what "on time" is measured by, not the day it was
     * accepted. A take already handed in, accepted or dropped is not handed in again.
     */
    public static function handIn(object $v, int $taskId): bool
    {
        return DB::table('gates_vol_task_takes')->where('task_id', $taskId)->where('volunteer_id', $v->id)->where('status', self::TAKEN)
            ->update(['status' => self::HANDED_IN, 'handed_in_at' => date('Y-m-d H:i:s')]) > 0;
    }

    public static function drop(object $v, int $taskId): bool
    {
        return DB::table('gates_vol_task_takes')->where('task_id', $taskId)->where('volunteer_id', $v->id)->where('status', self::TAKEN)
            ->update(['status' => self::DROPPED]) > 0;
    }

    /**
     * Staff accept a take as done, with the hours it earned. The hours are verified at once:
     * accepting IS the check.
     *
     * A COMPARE-AND-SET: the update carries the status it expects, and only the call that moved
     * the row writes hours. Read-then-update let two presses both see `taken` and both pay
     * (Hours::fromTask checked-then-inserted as well; the UNIQUE on gates_vol_hours(ref_kind,
     * ref_id) is the second lock). `done` always differs from what it replaces, so MySQL's
     * changed-rows count and SQLite's matched-rows count agree.
     */
    public static function accept(int $takeId, float $hours, int $accepted, string $byName): bool
    {
        $k = DB::table('gates_vol_task_takes')->where('id', $takeId)->whereIn('status', self::ON_THE_GO)->first();
        if (!$k) return false;
        $t = DB::table('gates_vol_tasks')->where('id', $k->task_id)->first();
        if (!$t) return false;
        $moved = DB::table('gates_vol_task_takes')->where('id', $takeId)->whereIn('status', self::ON_THE_GO)->update(['status' => self::DONE, 'done_at' => date('Y-m-d H:i:s'),
            'accepted' => max(0, $accepted), 'progress' => max((int) $k->progress, $accepted)]);
        if ($moved !== 1) return false;
        $k->status = self::DONE;
        Hours::fromTask($k, $t, $hours, $byName);
        return true;
    }

    /**
     * Staff record progress on a take ("41 of 58 verified"), from the Tasks tab. Existence is
     * checked first and the update's count is not trusted: MySQL reports rows CHANGED, so saving
     * the figures a take already holds would read as "not found" on production only.
     */
    public static function progress(int $takeId, int $done, int $accepted): bool
    {
        $q = static fn () => DB::table('gates_vol_task_takes')->where('id', $takeId)->whereIn('status', self::ON_THE_GO);
        if (!$q()->exists()) return false;
        $q()->update(['progress' => max(0, $done), 'accepted' => max(0, min(max(0, $done), $accepted))]);
        return true;
    }

    /**
     * Staff correct an open task: its name, what to do, how it is checked in words, how many and
     * when it is due. Takes already made are untouched. `points` is deliberately not offered: no
     * code pays a task's points (rewards are per verified hour, Rewards::perHour), so a figure
     * typed here would be a promise with nothing behind it.
     *
     * @return array{ok:bool, errors?:list<string>, id?:int}
     */
    public static function update(int $taskId, array $f): array
    {
        $t = DB::table('gates_vol_tasks')->where('id', $taskId)->where('status', 'open')->first();
        if (!$t) return ['ok' => false, 'errors' => ['That task is not open, so it can’t be changed.']];
        $title = trim((string) ($f['title'] ?? ''));
        $e = [];
        if ($title === '') $e[] = 'Give the task a name.';
        [$due, $err] = self::due((string) ($f['due_at'] ?? ''));
        if ($err) $e[] = $err;
        if ($e !== []) return ['ok' => false, 'errors' => $e];
        DB::table('gates_vol_tasks')->where('id', $taskId)->update([
            'title' => mb_substr($title, 0, 190), 'body' => mb_substr(trim((string) ($f['body'] ?? '')), 0, 4000) ?: null,
            'checked_note' => mb_substr(trim((string) ($f['checked_note'] ?? '')), 0, 190) ?: null,
            'target' => (int) ($f['target'] ?? 0) ?: null, 'due_at' => $due,
        ]);
        return ['ok' => true, 'id' => $taskId];
    }

    /**
     * Staff close a task: it leaves the library, the place pages and the new-task notices. Takes
     * already made stay where they are, so work somebody is part-way through can still be handed
     * in and accepted.
     */
    public static function close(int $taskId): bool
    {
        return DB::table('gates_vol_tasks')->where('id', $taskId)->where('status', 'open')->update(['status' => 'closed']) > 0;
    }

    /**
     * Work volunteers have handed in that nobody has accepted yet, oldest first: what the Tasks
     * tab and Home's "needs a person" list show staff.
     *
     * @return list<object>
     */
    public static function handedIn(int $limit = 200): array
    {
        return DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')
            ->join('gates_volunteers as v', 'v.id', '=', 'k.volunteer_id')->join('gates_users as u', 'u.id', '=', 'v.user_id')
            ->where('k.status', self::HANDED_IN)->orderBy('k.handed_in_at')->limit($limit)
            ->get(['k.id', 'k.task_id', 'k.progress', 'k.handed_in_at', 't.title', 't.target', 't.due_at', 'v.display_name', 'u.name'])->all();
    }

    /** How many takes are handed in and waiting: the Tasks tab's count and Home's card. */
    public static function handedInCount(): int
    {
        return (int) DB::table('gates_vol_task_takes')->where('status', self::HANDED_IN)->count();
    }

    /**
     * A due date as staff typed it — in the DISPLAY zone, from a datetime-local field — to the
     * stored (UTC) string, through DisplayTime, the one conversion each way. The edit form fills
     * the field with |when_input, so opening a task and saving it unchanged moves nothing; a
     * hand-rolled strtotime() here was an hour out the moment the two zones differ.
     *
     * @return array{0:?string,1:?string} the stored due date (or null for none), and an error
     */
    private static function due(string $raw): array
    {
        if (trim($raw) === '') return [null, null];
        $at = \AfricaGates\Support\DisplayTime::toStored($raw);
        if ($at === null) return [null, 'That due date isn’t a date.'];
        if ($at <= date('Y-m-d H:i:s')) return [null, 'A due date has to be in the future: nobody could take the task.'];
        return [$at, null];
    }

    /** @return array{ok:bool, errors?:list<string>, id?:int} */
    public static function create(array $f, int $adminId): array
    {
        $title = trim((string) ($f['title'] ?? ''));
        $by = (string) ($f['checked_by'] ?? 'lead');
        $e = [];
        if ($title === '') $e[] = 'Give the task a name.';
        if (!isset(self::CHECKED_BY[$by])) $e[] = 'Say how the task is checked.';
        [$due, $err] = self::due((string) ($f['due_at'] ?? ''));
        if ($err) $e[] = $err;
        if ($e !== []) return ['ok' => false, 'errors' => $e];
        $id = (int) DB::table('gates_vol_tasks')->insertGetId([
            'title' => mb_substr($title, 0, 190), 'body' => mb_substr(trim((string) ($f['body'] ?? '')), 0, 4000) ?: null,
            'scope_kind' => in_array($f['scope_kind'] ?? '', ['event', 'cycle'], true) ? $f['scope_kind'] : null,
            'scope_id' => (int) ($f['scope_id'] ?? 0) ?: null, 'checked_by' => $by,
            'checked_note' => mb_substr(trim((string) ($f['checked_note'] ?? '')), 0, 190) ?: null,
            'module_key' => trim((string) ($f['module_key'] ?? '')) ?: null, 'est_minutes' => (int) ($f['est_minutes'] ?? 0) ?: null,
            'points' => max(0, (int) ($f['points'] ?? 0)), 'target' => (int) ($f['target'] ?? 0) ?: null,
            'from_home' => empty($f['from_home']) ? 0 : 1, 'due_at' => $due,
            'status' => 'open', 'created_by' => $adminId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id];
    }
}
