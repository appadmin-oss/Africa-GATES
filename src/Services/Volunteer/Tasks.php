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
    /**
     * Staff took the work back (release, or reassignment to somebody else). Not DROPPED: that is
     * the volunteer handing it back and counts on their Effect; this is a decision about the task
     * and counts nowhere. Fits the VARCHAR(16) the column has always been.
     */
    public const RELEASED = 'released';
    /** A nudge is at most one message a day per take. */
    public const NUDGE_HOURS = 24;
    /** The Tasks tab's filters over the takes on the go: everything, waiting on staff, behind. */
    public const SHOW = ['all' => 'All on the go', 'waiting' => 'Handed in, waiting', 'overdue' => 'Overdue'];
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
        // An event's task is for that event's team (as the library and take() hold), so the
        // new-task notice must not tell anybody else about it.
        if ((string) ($t->scope_kind ?? '') === 'event' && !Workspace::place($v, 'event', (int) $t->scope_id)) return false;
        return !(int) $t->from_home || (int) ($v->from_home ?? 1) === 1;
    }

    /** @return list<object> open tasks the person has not taken */
    public static function library(object $v): array
    {
        $taken = DB::table('gates_vol_task_takes')->where('volunteer_id', $v->id)->whereIn('status', [...self::ON_THE_GO, self::DONE])->pluck('task_id')->all();
        $q = self::offered();
        if ($taken) $q->whereNotIn('id', $taken);
        $rows = $q->orderBy('due_at')->orderByDesc('id')->limit(100)->get()->all();
        // An EVENT's task is for the people working that event (a lead posts it for their own
        // night's team), so it is offered only to a volunteer holding a role there. Award-wide
        // and unplaced tasks stay open to everybody, as they were.
        $events = [];
        foreach (Workspace::places($v) as $p) if ($p['kind'] === 'event') $events[$p['id']] = true;
        $rows = array_values(array_filter($rows, static fn ($t) => (string) $t->scope_kind !== 'event' || isset($events[(int) $t->scope_id])));
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
        // The same rule as the library: an event's task is taken only by somebody on that event.
        if ((string) $t->scope_kind === 'event' && !Workspace::place($v, 'event', (int) $t->scope_id)) return ['ok' => false, 'error' => 'That task is for the team at another event.'];
        if ($t->module_key && !Training::holds((int) $v->id, (string) $t->module_key)) return ['ok' => false, 'error' => 'This task needs training first.'];
        $have = DB::table('gates_vol_task_takes')->where('task_id', $taskId)->where('volunteer_id', $v->id)->first();
        if ($have) {
            if ($have->status === self::DONE) return ['ok' => false, 'error' => 'You’ve already finished this task. It’s under Tasks › Done.'];
            if (in_array((string) $have->status, [self::DROPPED, self::RELEASED], true)) DB::table('gates_vol_task_takes')->where('id', $have->id)->where('status', $have->status)
                ->update(['status' => self::TAKEN, 'taken_at' => date('Y-m-d H:i:s'), 'handed_in_at' => null, 'nudged_at' => null]);
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
        // The same bounds the event path has always had. Staff could accept any figure: a
        // negative one closed the task and wrote nothing, and 10,000 or more overflows
        // DECIMAL(6,2) on MySQL — after the take was already marked done (AUDIT-AND-PLAN VO-2).
        if ($hours <= 0 || $hours > 24) return false;
        $k = DB::table('gates_vol_task_takes')->where('id', $takeId)->whereIn('status', self::ON_THE_GO)->first();
        if (!$k) return false;
        $t = DB::table('gates_vol_tasks')->where('id', $k->task_id)->first();
        if (!$t) return false;
        // Closing the take and writing its hours are ONE act: a take marked done with no
        // hours row is work nobody can see was ever credited.
        return DB::connection()->transaction(static function () use ($k, $t, $takeId, $hours, $accepted, $byName): bool {
            $moved = DB::table('gates_vol_task_takes')->where('id', $takeId)->whereIn('status', self::ON_THE_GO)->update(['status' => self::DONE, 'done_at' => date('Y-m-d H:i:s'),
                'accepted' => max(0, $accepted), 'progress' => max((int) $k->progress, $accepted)]);
            if ($moved !== 1) return false;
            $k->status = self::DONE;
            Hours::fromTask($k, $t, $hours, $byName);
            return true;
        });
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

    // ── Getting the work finished: the Tasks tab's view of every take on the go ────────────
    //
    // The tab could accept handed-in work and edit or close a task, but could not say who was
    // behind or do anything about it short of closing the whole task (addendum of 19 Nov 2026 to
    // docs/audits/2026-11-02-volunteer-tasks.md). Overdue is ONE definition, here: held (not
    // handed in) past the task's due date — the same rule Effect counts as late, so the tab and
    // the volunteer's reliability chip cannot disagree about who is behind.

    private static function onTheGo(string $show): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')
            ->join('gates_volunteers as v', 'v.id', '=', 'k.volunteer_id')->join('gates_users as u', 'u.id', '=', 'v.user_id');
        return match ($show) {
            'waiting' => $q->where('k.status', self::HANDED_IN),
            'overdue' => $q->where('k.status', self::TAKEN)->whereNotNull('t.due_at')->where('t.due_at', '<', date('Y-m-d H:i:s')),
            default => $q->whereIn('k.status', self::ON_THE_GO),
        };
    }

    /**
     * Every take on the go (or one filter of them), behind first, then by due date, each with its
     * due date and an `overdue` flag. An unknown filter is everything, never an error: a stale
     * bookmark shows the list.
     *
     * @return list<object>
     */
    public static function takes(string $show = 'all', int $limit = 300): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = self::onTheGo(isset(self::SHOW[$show]) ? $show : 'all')
            ->orderByRaw("CASE WHEN k.status = ? AND t.due_at IS NOT NULL AND t.due_at < ? THEN 0 ELSE 1 END", [self::TAKEN, $now])
            ->orderByRaw('CASE WHEN t.due_at IS NULL THEN 1 ELSE 0 END')->orderBy('t.due_at')->orderBy('k.id')->limit($limit)
            ->get(['k.id', 'k.task_id', 'k.volunteer_id', 'k.status', 'k.progress', 'k.taken_at', 'k.handed_in_at', 'k.nudged_at',
                   't.title', 't.target', 't.due_at', 't.module_key', 't.scope_kind', 't.scope_id', 'v.display_name', 'u.name'])->all();
        foreach ($rows as $r) {
            $r->overdue = $r->status === self::TAKEN && $r->due_at !== null && (string) $r->due_at < $now;
            $r->nudgeable = $r->status === self::TAKEN && ($r->nudged_at === null || (string) $r->nudged_at <= date('Y-m-d H:i:s', time() - self::NUDGE_HOURS * 3600));
        }
        return $rows;
    }

    /** @return array{all:int, waiting:int, overdue:int} what each filter would show */
    public static function takeCounts(): array
    {
        $out = [];
        foreach (array_keys(self::SHOW) as $k) $out[$k] = (int) self::onTheGo($k)->count();
        return $out;
    }

    /**
     * Staff nudge the person holding a take: ONE message to them, about this task, with an
     * optional line from staff. Recorded on the take (`nudged_at`, which the tab shows so a second
     * member of staff does not nudge again) and in gates_broadcast_log through Reminders::deliver,
     * the volunteer messages' one mail path. At most once in NUDGE_HOURS: the stamp is a
     * compare-and-set, so two presses cannot both send. A send that fails puts the stamp back,
     * so "nudged" is never shown for a message nobody received.
     *
     * Only a take still `taken`: handed-in work is waiting on staff, not on the volunteer.
     *
     * @param callable(string,string,string,string):bool $send Reminders::sender($mailer)
     * @return array{ok:bool, error?:string}
     */
    public static function nudge(int $takeId, string $note, string $byName, callable $send): array
    {
        $k = DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')->where('k.id', $takeId)
            ->first(['k.id', 'k.status', 'k.volunteer_id', 'k.nudged_at', 'k.progress', 't.title', 't.due_at', 't.target']);
        if (!$k || $k->status !== self::TAKEN) return ['ok' => false, 'error' => 'Only a task somebody holds and hasn’t handed in can be nudged.'];
        $who = Reminders::person((int) $k->volunteer_id);
        if ($who === null) return ['ok' => false, 'error' => 'They can’t be emailed: they are on a break, have left, or have no working address.'];
        $now = date('Y-m-d H:i:s');
        $since = date('Y-m-d H:i:s', time() - self::NUDGE_HOURS * 3600);
        // `nudged_at = now` always differs from what it replaces, so MySQL's changed-rows count
        // and SQLite's matched-rows count agree.
        $claimed = DB::table('gates_vol_task_takes')->where('id', $takeId)->where('status', self::TAKEN)
            ->where(static fn ($w) => $w->whereNull('nudged_at')->orWhere('nudged_at', '<=', $since))->update(['nudged_at' => $now]);
        if ($claimed !== 1) return ['ok' => false, 'error' => 'They were already nudged about this in the last day. Give them a little time.'];

        $late = $k->due_at && (string) $k->due_at < $now;
        $lines = ['Hello ' . $who[1] . ',',
                  $late ? 'Your task ' . $k->title . ' was due on ' . Workspace::due((string) $k->due_at, true) . ' and is still with you.'
                        : 'A quick word about your task ' . $k->title . ($k->due_at ? ', due on ' . Workspace::due((string) $k->due_at, true) : '') . '.'];
        if ($k->target) $lines[] = 'So far: ' . (int) $k->progress . ' of ' . (int) $k->target . '.';
        $note = trim(mb_substr($note, 0, 500));
        if ($note !== '') $lines[] = $byName . ' says: ' . $note;
        $lines[] = 'When it is finished, hand it in from Tasks › Mine so a lead can accept it. If you can’t finish it, drop it there so somebody else can, or reply and tell us what you need.';
        // The once-a-day rule is the stamp above; the log key only has to be unique per message.
        $ok = Reminders::deliver('vol-nudge:' . $takeId . ':' . bin2hex(random_bytes(6)), $who[0], ($late ? 'Overdue: ' : 'About your task: ') . $k->title, $lines, '/volunteer/tasks/mine', $send);
        if (!$ok) {
            DB::table('gates_vol_task_takes')->where('id', $takeId)->where('nudged_at', $now)->update(['nudged_at' => $k->nudged_at]);
            return ['ok' => false, 'error' => 'The message could not be sent, so nothing is recorded. Try again in a minute.'];
        }
        return ['ok' => true];
    }

    /**
     * Staff take a held task back: it leaves the volunteer's Mine, is offered again, and counts
     * against nobody (RELEASED, not DROPPED). Handed-in work can be released too — staff decide
     * that, with the reason the confirm dialog asks for.
     *
     * @return ?object the take as it was, or null if it was not on the go
     */
    public static function release(int $takeId): ?object
    {
        $k = DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')->where('k.id', $takeId)
            ->whereIn('k.status', self::ON_THE_GO)->first(['k.id', 'k.task_id', 'k.volunteer_id', 'k.status', 't.title']);
        if (!$k) return null;
        $moved = DB::table('gates_vol_task_takes')->where('id', $takeId)->whereIn('status', self::ON_THE_GO)->update(['status' => self::RELEASED]);
        return $moved === 1 ? $k : null;
    }

    /**
     * Staff give a held task to somebody else: the first holder's take is released and the new
     * person holds it as if they had taken it. The new person must be somebody who COULD take it
     * — approved, trained for it, on that event's team for an event task — because a
     * reassignment is not a way round the rules the library keeps. Its due date is not a bar:
     * staff may pass on overdue work, and change the date on the task.
     *
     * @return array{ok:bool, error?:string, from?:object, to?:object, title?:string}
     */
    public static function reassign(int $takeId, int $toVolunteerId): array
    {
        $k = DB::table('gates_vol_task_takes')->where('id', $takeId)->whereIn('status', self::ON_THE_GO)->first();
        if (!$k) return ['ok' => false, 'error' => 'That task isn’t held by anybody now, so there is nothing to reassign.'];
        if ((int) $k->volunteer_id === $toVolunteerId) return ['ok' => false, 'error' => 'That is the same person who has it now.'];
        $t = DB::table('gates_vol_tasks')->where('id', $k->task_id)->first();
        $to = DB::table('gates_volunteers')->where('id', $toVolunteerId)->first();
        if (!$t || !$to) return ['ok' => false, 'error' => 'Pick a volunteer to give it to.'];
        if (!in_array(Volunteers::role($to), ['new', 'active', 'lead'], true)) return ['ok' => false, 'error' => 'Only an approved volunteer who isn’t on a break can be given a task.'];
        if ($t->module_key && !Training::holds((int) $to->id, (string) $t->module_key)) return ['ok' => false, 'error' => 'They need the training for this task first.'];
        if ((string) $t->scope_kind === 'event' && !Workspace::place($to, 'event', (int) $t->scope_id)) return ['ok' => false, 'error' => 'That task is for the team at its event, and they aren’t on it.'];
        $have = DB::table('gates_vol_task_takes')->where('task_id', $t->id)->where('volunteer_id', $toVolunteerId)->first();
        if ($have && !in_array((string) $have->status, [self::DROPPED, self::RELEASED], true)) return ['ok' => false, 'error' => 'They already have this task, or have finished it.'];
        $ok = DB::connection()->transaction(static function () use ($k, $t, $have, $toVolunteerId): bool {
            if (DB::table('gates_vol_task_takes')->where('id', $k->id)->whereIn('status', self::ON_THE_GO)->update(['status' => self::RELEASED]) !== 1) return false;
            $row = ['status' => self::TAKEN, 'taken_at' => date('Y-m-d H:i:s'), 'handed_in_at' => null, 'nudged_at' => null, 'progress' => 0, 'accepted' => 0];
            if ($have) DB::table('gates_vol_task_takes')->where('id', $have->id)->update($row);
            else DB::table('gates_vol_task_takes')->insert($row + ['task_id' => $t->id, 'volunteer_id' => $toVolunteerId]);
            return true;
        });
        if (!$ok) return ['ok' => false, 'error' => 'Somebody changed that take a moment ago. Look again.'];
        return ['ok' => true, 'from' => DB::table('gates_volunteers')->where('id', $k->volunteer_id)->first(), 'to' => $to, 'title' => (string) $t->title, 'task' => $t];
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
        if (!in_array((string) $k->status, self::ON_THE_GO, true)) return ['ok' => false, 'error' => 'That has already been accepted, or was dropped.'];
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
                ->where('k.task_id', $t->id)->whereIn('k.status', [...self::ON_THE_GO, self::DONE])->orderBy('k.taken_at')
                // The volunteer's columns FIRST: both tables have `status` (and `id`), and the later
                // column wins, so the take's own status must come after `v.*` or every take reads
                // as the volunteer's 'active'.
                ->get(['v.*', 'k.id as take_id', 'k.status', 'k.taken_at', 'k.handed_in_at', 'k.done_at'])->all();
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
