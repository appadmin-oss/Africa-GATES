<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\OtpService;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What a volunteer is told without asking (docs/architecture/WORKSPACE-COMPARISON.md §3): the
 * reminder every volunteer tool sends, and which this workspace did not.
 *
 *   shift     the day before a shift: what, when, where, what to bring, who leads it
 *   place     a place opened from a waitlist and they were moved on
 *   training  a passed module lapses within a week, naming the coming shifts that need it
 *
 * ── ONE PERSON, ABOUT THEIR OWN WORK ─────────────────────────────────────────
 *
 * Each message goes to one volunteer about their own shift or training. Nothing here sends to
 * a group, and nothing lets a volunteer or a lead send — the owner was explicit that the
 * workspace has no broadcast. So these are service messages, not announcements: no
 * unsubscribe link, never held by the bulk cap, and stated on the Notifications page as always
 * on, because a shift reminder somebody could switch off is a no-show nobody chose.
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
    /** How far ahead a shift is reminded: inside the next day, never one already started. */
    public const SHIFT_HOURS = 26;
    public const TRAINING_DAYS = 7;

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
        $soon = date('Y-m-d H:i:s', time() + self::SHIFT_HOURS * 3600);
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

        // The day before a shift.
        foreach (DB::table('gates_vol_signups as s')->join('gates_vol_shifts as h', 'h.id', '=', 's.shift_id')
                     ->where('s.status', 'on')->where('h.status', 'open')->where('h.starts_at', '>', $now)->where('h.starts_at', '<=', $soon)
                     ->orderBy('h.starts_at')->limit(self::BATCH)->get(['s.id as sid', 's.volunteer_id', 'h.*']) as $r) {
            if (!$p = $person((int) $r->volunteer_id)) continue;
            $lines = ['Hello ' . $p[1] . ',', 'A reminder of your shift: ' . $r->title . ', ' . Shifts::scopeName((string) $r->scope_kind, (int) $r->scope_id) . '.',
                      'When: ' . Workspace::day((string) $r->starts_at) . ', ' . Workspace::span((string) $r->starts_at, (string) $r->ends_at) . '.',
                      'Where: ' . ($r->from_home ? 'from home' : ((string) $r->location ?: 'see the shift page')) . '.'];
            if ($r->bring) $lines[] = 'Bring: ' . $r->bring . '.';
            if ($r->lead_name) $lines[] = 'Your lead: ' . $r->lead_name . '.';
            $lines[] = 'Can’t make it? Drop the shift now so somebody on the waitlist can take it.';
            $out[] = ['vol-shift:' . $r->sid, $p[0], 'Tomorrow: ' . $r->title, $lines, '/volunteer/shifts/' . (int) $r->id];
        }

        // A place opened from the waitlist, in the last two days, for a shift still to come.
        foreach (DB::table('gates_vol_signups as s')->join('gates_vol_shifts as h', 'h.id', '=', 's.shift_id')
                     ->where('s.status', 'on')->whereNotNull('s.promoted_at')->where('s.promoted_at', '>', date('Y-m-d H:i:s', time() - 2 * 86400))
                     ->where('h.starts_at', '>', $now)->limit(self::BATCH)->get(['s.id as sid', 's.volunteer_id', 'h.*']) as $r) {
            if (!$p = $person((int) $r->volunteer_id)) continue;
            $out[] = ['vol-place:' . $r->sid, $p[0], 'You’re on it: ' . $r->title,
                      ['Hello ' . $p[1] . ',', 'A place opened on a shift you were waiting for, and you’re on it now.',
                       $r->title . ', ' . Workspace::day((string) $r->starts_at) . ', ' . Workspace::span((string) $r->starts_at, (string) $r->ends_at) . '.',
                       'If you can no longer make it, drop it so the next person can have it.'], '/volunteer/shifts/' . (int) $r->id];
        }

        // Training that lapses within a week, unless a newer pass replaced it.
        $until = date('Y-m-d H:i:s', time() + self::TRAINING_DAYS * 86400);
        foreach (DB::table('gates_vol_passes as p')->join('gates_vol_modules as m', 'm.id', '=', 'p.module_id')
                     ->whereNotNull('p.expires_at')->where('p.expires_at', '>', $now)->where('p.expires_at', '<=', $until)
                     ->limit(self::BATCH)->get(['p.id as pid', 'p.volunteer_id', 'p.module_id', 'p.expires_at', 'm.title', 'm.slug']) as $r) {
            $newer = DB::table('gates_vol_passes')->where('volunteer_id', $r->volunteer_id)->where('module_id', $r->module_id)
                ->where(static fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', $r->expires_at))->exists();
            if ($newer || !$p = $person((int) $r->volunteer_id)) continue;
            $needs = DB::table('gates_vol_signups as s')->join('gates_vol_shifts as h', 'h.id', '=', 's.shift_id')
                ->where('s.volunteer_id', $r->volunteer_id)->where('s.status', 'on')->where('h.module_key', $r->slug)
                ->where('h.starts_at', '>', $r->expires_at)->orderBy('h.starts_at')->pluck('h.title')->all();
            $lines = ['Hello ' . $p[1] . ',', 'Your ' . $r->title . ' training runs out on ' . Workspace::day((string) $r->expires_at) . '.'];
            if ($needs) $lines[] = 'You need it for ' . implode(', ', array_slice($needs, 0, 3)) . (count($needs) > 3 ? ' and ' . (count($needs) - 3) . ' more' : '') . '.';
            $lines[] = 'It takes a few minutes to renew.';
            $out[] = ['vol-train:' . $r->pid, $p[0], 'Renew your ' . $r->title . ' training', $lines, '/volunteer/training/take/' . $r->slug];
        }
        return $out;
    }
}
