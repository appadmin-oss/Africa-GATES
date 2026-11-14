<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\OtpService;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What a volunteer is told without asking (docs/architecture/WORKSPACE-COMPARISON.md §3): the
 * reminder every volunteer tool sends, and which this workspace did not.
 *
 *   training  a passed module lapses within a week
 *   meeting   the day before each meeting in a series (Meetings::due)
 *   task due  the day before a task they hold falls due, unless they have handed it in
 *   new task  a task that FITS them (Tasks::fits) was posted — as it is posted, or once a week,
 *             as they chose under "New tasks that fit me" (gates_volunteers.notify_fit). That
 *             setting was saved, and the page promised "We tell you when one is posted", while
 *             nothing read it: the promise was a sentence with no sender behind it.
 *
 * ── ONE PERSON, ABOUT THEIR OWN WORK ─────────────────────────────────────────
 *
 * Each message goes to one volunteer about their own training, a meeting they are in, a task they
 * hold, or work that fits them which they asked to hear about. Nothing here sends to
 * a group, and nothing lets a volunteer or a lead send — the owner was explicit that the
 * workspace has no broadcast. So these are service messages, not announcements: no
 * unsubscribe link, never held by the bulk cap, and stated on the Notifications page as always
 * on, because a reminder somebody could switch off is a missed meeting nobody chose.
 *
 * ── NEVER TWICE ──────────────────────────────────────────────────────────────
 *
 * Every message is claimed in gates_broadcast_log BEFORE it is sent (BroadcastLog), keyed by
 * what it is about — a signup, a pass — so overlapping ticks and a re-run mail nobody twice.
 * A claim that dies mid-send is never retried: a missed reminder is recoverable, the same
 * reminder every fifteen minutes is not.
 *
 * Plain words, short lines, the facts in the order a person needs them, and the link last:
 * these are read aloud by screen readers and on small phones as often as they are seen.
 */
final class Reminders
{
    public const BATCH = 200;
    /** How far ahead a meeting is reminded: inside the next day, never one already started. */
    public const MEETING_HOURS = 26;
    public const TRAINING_DAYS = 7;
    /** How far ahead a held task's due date is reminded: the day before, never one already past. */
    public const TASK_DUE_HOURS = 26;
    /**
     * A task is announced "as posted" only while it is this new. Bounds the first run after this
     * shipped (it must not mail every volunteer about every open task ever posted) and a volunteer
     * who switches to "When posted" months in.
     */
    public const NEW_TASK_DAYS = 7;

    /**
     * @param ?callable(string $to, string $subject, string $html, string $plain): bool $send for tests
     * @return int messages sent
     */
    public static function sweep(?OtpService $mailer, ?callable $send = null, ?string $site = null): int
    {
        if (!Volunteers::ready()) return 0;
        $send ??= static function (string $to, string $subject, string $html, string $plain) use ($mailer): bool {
            if ($mailer === null) return false;
            $r = $mailer->sendBranded($to, $subject, $html, $plain, 'Volunteering');
            return ($r['success'] ?? false) === true;
        };
        $site = rtrim($site ?? SiteUrl::base(), '/');
        $sent = 0;
        foreach (self::due() as [$key, $email, $subject, $lines, $href]) {
            if ($sent >= self::BATCH) break;
            if (!BroadcastLog::claim($key, $email)) continue;
            $plain = implode("\n", $lines) . "\n\n" . $site . $href;
            $html = implode('', array_map(static fn (string $l): string => '<p>' . htmlspecialchars($l) . '</p>', $lines))
                  . '<p><a href="' . htmlspecialchars($site . $href) . '">Open it in your volunteering</a></p>';
            $ok = false;
            try { $ok = $send($email, $subject, $html, $plain); } catch (\Throwable) {}
            BroadcastLog::settle($key, $email, $ok);
            if ($ok) $sent++;
        }
        return $sent;
    }

    /**
     * Everything due now, as [claim key, email, subject, lines, link]. Public so a test and an
     * operator can see what the next tick would send without sending it.
     *
     * @return list<array{0:string,1:string,2:string,3:list<string>,4:string}>
     */
    public static function due(): array
    {
        $now = date('Y-m-d H:i:s');
        $out = [];
        $person = static function (int $volId): ?array {
            $r = DB::table('gates_volunteers as v')->join('gates_users as u', 'u.id', '=', 'v.user_id')->where('v.id', $volId)
                ->first(['v.*', 'u.email', 'u.name as user_name']);
            if (!$r || !filter_var((string) $r->email, FILTER_VALIDATE_EMAIL)) return null;
            // On a break, or gone: nothing is sent, as the break promises.
            if (in_array((string) $r->status, ['paused', 'left', 'declined'], true)) return null;
            $first = trim((string) strtok(Volunteers::name($r), ' ')) ?: 'there';
            return [(string) $r->email, $first];
        };

        // A meeting tomorrow, to the organiser and everybody who hasn't said no.
        array_push($out, ...Meetings::due(self::MEETING_HOURS));

        // Training that lapses within a week, unless a newer pass replaced it.
        $until = date('Y-m-d H:i:s', time() + self::TRAINING_DAYS * 86400);
        foreach (DB::table('gates_vol_passes as p')->join('gates_vol_modules as m', 'm.id', '=', 'p.module_id')
                     ->whereNotNull('p.expires_at')->where('p.expires_at', '>', $now)->where('p.expires_at', '<=', $until)
                     ->limit(self::BATCH)->get(['p.id as pid', 'p.volunteer_id', 'p.module_id', 'p.expires_at', 'm.title', 'm.slug']) as $r) {
            $newer = DB::table('gates_vol_passes')->where('volunteer_id', $r->volunteer_id)->where('module_id', $r->module_id)
                ->where(static fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', $r->expires_at))->exists();
            if ($newer || !$p = $person((int) $r->volunteer_id)) continue;
            $lines = ['Hello ' . $p[1] . ',', 'Your ' . $r->title . ' training runs out on ' . Workspace::day((string) $r->expires_at) . '.'];
            $lines[] = 'It takes a few minutes to renew.';
            $out[] = ['vol-train:' . $r->pid, $p[0], 'Renew your ' . $r->title . ' training', $lines, '/volunteer/training/take/' . $r->slug];
        }

        // A task they hold falls due within a day, and they have not handed it in.
        $soon = date('Y-m-d H:i:s', time() + self::TASK_DUE_HOURS * 3600);
        foreach (DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')
                     ->where('k.status', Tasks::TAKEN)->whereNotNull('t.due_at')->where('t.due_at', '>', $now)->where('t.due_at', '<=', $soon)
                     ->limit(self::BATCH)->get(['k.id as take_id', 'k.volunteer_id', 't.title', 't.due_at']) as $r) {
            if (!$p = $person((int) $r->volunteer_id)) continue;
            $lines = ['Hello ' . $p[1] . ',', 'Your task ' . $r->title . ' is due on ' . Workspace::day((string) $r->due_at) . ' at ' . date('H:i', (int) strtotime((string) $r->due_at)) . '.',
                      'When it is finished, hand it in from Tasks › Mine so a lead can accept it. If you can’t finish it, drop it so somebody else can.'];
            $out[] = ['vol-taskdue:' . $r->take_id, $p[0], 'Due tomorrow: ' . $r->title, $lines, '/volunteer/tasks/mine'];
        }

        array_push($out, ...self::newTasks());
        return $out;
    }

    /**
     * New tasks that fit, to the volunteers who asked (notify_fit): `posted` hears about each task
     * once, while it is new; `weekly` hears once a week about the tasks posted the week before.
     * The claim keys make each exactly-once — per task for `posted`, per ISO week for `weekly` —
     * and what was already claimed is left out here, so a tick does not try every address again.
     *
     * @return list<array{0:string,1:string,2:string,3:list<string>,4:string}>
     */
    private static function newTasks(): array
    {
        $out = [];
        $weekStart = strtotime('monday this week 00:00');
        $lastWeek = date('o-W', $weekStart - 86400);
        $fresh = Tasks::offered()->where('created_at', '>=', date('Y-m-d H:i:s', min(time() - self::NEW_TASK_DAYS * 86400, $weekStart - 7 * 86400)))
            ->orderBy('id')->limit(self::BATCH)->get()->all();
        if ($fresh === []) return [];
        $people = DB::table('gates_volunteers as v')->join('gates_users as u', 'u.id', '=', 'v.user_id')->where('v.status', 'active')
            ->whereIn('v.notify_fit', ['posted', 'weekly'])->orderBy('v.id')->limit(2000)->get(['v.*', 'u.email', 'u.name as user_name'])->all();
        $weekKey = 'vol-taskweek:' . $lastWeek;
        $weekDone = BroadcastLog::handled($weekKey);
        $seen = [];
        foreach ($people as $v) {
            if (!filter_var((string) $v->email, FILTER_VALIDATE_EMAIL)) continue;
            $hash = EmailOptOut::hash((string) $v->email);
            $first = trim((string) strtok(Volunteers::name($v), ' ')) ?: 'there';
            $held = array_map('intval', DB::table('gates_vol_task_takes')->where('volunteer_id', $v->id)->pluck('task_id')->all());
            $fit = array_values(array_filter($fresh, static fn ($t) => !in_array((int) $t->id, $held, true) && Tasks::fits($v, $t)));
            if ((string) $v->notify_fit === 'posted') {
                foreach ($fit as $t) {
                    if ((string) $t->created_at < date('Y-m-d H:i:s', time() - self::NEW_TASK_DAYS * 86400)) continue;
                    $key = 'vol-task:' . $t->id;
                    $seen[$key] ??= BroadcastLog::handled($key);
                    if (isset($seen[$key][$hash])) continue;
                    $lines = ['Hello ' . $first . ',', 'A new task fits you: ' . $t->title . '.'];
                    if ($t->due_at) $lines[] = 'Due: ' . Workspace::day((string) $t->due_at) . '.';
                    $lines[] = 'You can change how often we tell you about new tasks in your volunteering, under Notifications.';
                    $out[] = [$key, (string) $v->email, 'A new task for you: ' . $t->title, $lines, '/volunteer/tasks/library'];
                }
                continue;
            }
            if (isset($weekDone[$hash])) continue;
            $week = array_values(array_filter($fit, static fn ($t) => (int) strtotime((string) $t->created_at) >= $weekStart - 7 * 86400 && (int) strtotime((string) $t->created_at) < $weekStart));
            if ($week === []) continue;
            $lines = ['Hello ' . $first . ',', (count($week) === 1 ? 'One new task fits you' : count($week) . ' new tasks fit you') . ' from last week:'];
            foreach (array_slice($week, 0, 10) as $t) $lines[] = '· ' . $t->title . ($t->due_at ? ', due ' . Workspace::day((string) $t->due_at) : '');
            $lines[] = 'You can change how often we tell you about new tasks in your volunteering, under Notifications.';
            $out[] = [$weekKey, (string) $v->email, 'New tasks that fit you', $lines, '/volunteer/tasks/library'];
        }
        return $out;
    }
}
