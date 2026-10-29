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
 *   training  a passed module lapses within a week
 *   meeting   the day before each meeting in a series (Meetings::due)
 *
 * ── ONE PERSON, ABOUT THEIR OWN WORK ─────────────────────────────────────────
 *
 * Each message goes to one volunteer about their own training or a meeting they are in. Nothing here sends to
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
        return $out;
    }
}
