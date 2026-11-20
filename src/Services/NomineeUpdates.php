<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\NomineeUrl;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A NOMINEE'S OWN UPDATES TO THE PEOPLE BACKING THEM (owner, 16 Nov 2026: "build them all").
 *
 * A short note in their own words — "we opened the second classroom on Saturday", "thank you for
 * 300 of you" — shown on their vote page and emailed to the backers who asked to hear how they
 * are doing (gates_nominee_follows, NomineeMilestones::follow). It turns a vote into a relationship
 * with the person, which is what makes backing them feel worthwhile rather than dutiful.
 *
 * Kept small on purpose, because a nominee with a mailing list is a spam cannon:
 *   · one update every {@see COOLDOWN_HOURS} hours;
 *   · it goes out {@see GRACE_MINUTES} minutes after posting, and can be changed or taken down
 *     until then (after, taking it down removes it from the page; the mail has gone);
 *   · only to followers — addresses that VOTED for them and asked — never to every backer;
 *   · each message carries the unsubscribe link, so SendPolicy treats it as an announcement.
 * Tags stripped; nothing rendered as HTML.
 */
final class NomineeUpdates
{
    public const MIN = 10;
    public const MAX = 500;
    public const GRACE_MINUTES = 15;
    public const COOLDOWN_HOURS = 72;
    public const SHOWN = 3;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_nominee_updates');
    }

    /** @return array{ok:bool, error?:string} */
    public static function post(int $nomineeId, int $userId, string $body, ?Carbon $now = null): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'Updates are not switched on yet.'];
        $now ??= Carbon::now();
        $text = self::clean($body);
        if (mb_strlen($text) < self::MIN) return ['ok' => false, 'error' => 'Write a little more: at least ' . self::MIN . ' characters.'];
        if (mb_strlen($text) > self::MAX) return ['ok' => false, 'error' => 'Keep it to ' . self::MAX . ' characters.'];
        $next = self::nextAt($nomineeId);
        if ($next !== null && $next->greaterThan($now)) {
            return ['ok' => false, 'error' => 'You can post your next update from ' . $next->format('j M, H:i') . '. One every ' . (self::COOLDOWN_HOURS / 24) . ' days keeps people reading them.'];
        }
        try {
            DB::table('gates_nominee_updates')->insert(['nominee_id' => $nomineeId, 'user_id' => $userId, 'body' => $text, 'created_at' => $now->toDateTimeString()]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'It could not be saved. Please try again.'];
        }
        return ['ok' => true];
    }

    /** Change an update still inside its grace. @return array{ok:bool, error?:string} */
    public static function edit(int $nomineeId, int $updateId, string $body, ?Carbon $now = null): array
    {
        $u = self::one($nomineeId, $updateId);
        if ($u === null || !self::editable($u, $now)) return ['ok' => false, 'error' => 'That update has already gone out, so it can’t be changed now.'];
        $text = self::clean($body);
        if (mb_strlen($text) < self::MIN || mb_strlen($text) > self::MAX) return ['ok' => false, 'error' => 'Between ' . self::MIN . ' and ' . self::MAX . ' characters.'];
        DB::table('gates_nominee_updates')->where('id', $updateId)->update(['body' => $text]);
        return ['ok' => true];
    }

    /** Take an update down. Before it goes, nothing is sent; after, it leaves the page. */
    public static function remove(int $nomineeId, int $updateId, ?Carbon $now = null): bool
    {
        $u = self::one($nomineeId, $updateId);
        if ($u === null) return false;
        $set = ['removed_at' => ($now ?? Carbon::now())->toDateTimeString()];
        // Removed before it went: it never goes — and it does not hold up the next one.
        return DB::table('gates_nominee_updates')->where('id', $updateId)->whereNull('removed_at')->update($set) > 0;
    }

    public static function editable(object $u, ?Carbon $now = null): bool
    {
        if ($u->sent_at !== null || $u->removed_at !== null) return false;
        return Carbon::parse((string) $u->created_at)->addMinutes(self::GRACE_MINUTES)->greaterThan($now ?? Carbon::now());
    }

    /** When the next update may be posted, or null for now. */
    public static function nextAt(int $nomineeId): ?Carbon
    {
        if (!self::ready()) return null;
        try {
            // A post taken down inside its grace never went anywhere, so it does not count.
            $last = DB::table('gates_nominee_updates')->where('nominee_id', $nomineeId)
                ->where(static fn ($q) => $q->whereNull('removed_at')->orWhereNotNull('sent_at'))
                ->max('created_at');
        } catch (\Throwable) {
            return null;
        }
        return $last ? Carbon::parse((string) $last)->addHours(self::COOLDOWN_HOURS) : null;
    }

    /**
     * For the nominee's own page: their updates, newest first, each saying whether it can still
     * be changed. @return list<object>
     */
    public static function mine(int $nomineeId, ?Carbon $now = null): array
    {
        if (!self::ready()) return [];
        try {
            $rows = DB::table('gates_nominee_updates')->where('nominee_id', $nomineeId)->whereNull('removed_at')->orderByDesc('id')->limit(10)->get()->all();
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $r) $r->editable = self::editable($r, $now);
        return $rows;
    }

    /** For the public vote page: what has gone out (past its grace), newest first. @return list<object> */
    public static function shown(int $nomineeId, ?Carbon $now = null): array
    {
        if (!self::ready() || $nomineeId < 1) return [];
        $cut = ($now ?? Carbon::now())->copy()->subMinutes(self::GRACE_MINUTES)->toDateTimeString();
        try {
            return DB::table('gates_nominee_updates')->where('nominee_id', $nomineeId)->whereNull('removed_at')
                ->where('created_at', '<=', $cut)->orderByDesc('id')->limit(self::SHOWN)->get(['id', 'body', 'created_at'])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** How many addresses an update reaches today. */
    public static function followers(int $nomineeId): int
    {
        if (!SchemaHas::table('gates_nominee_follows')) return 0;
        try {
            return (int) DB::table('gates_nominee_follows')->where('nominee_id', $nomineeId)->whereNull('stopped_at')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Send what is past its grace to the followers. Returns how many messages went. */
    public static function sweep(?OtpService $mailer, ?Carbon $now = null, ?string $site = null, ?callable $send = null): int
    {
        if (($mailer === null && $send === null) || !self::ready()) return 0;
        $now ??= Carbon::now();
        $site = rtrim($site ?? SiteUrl::base(), '/');
        try {
            $due = DB::table('gates_nominee_updates')->whereNull('sent_at')->whereNull('removed_at')
                ->where('created_at', '<=', $now->copy()->subMinutes(self::GRACE_MINUTES)->toDateTimeString())->orderBy('id')->get()->all();
        } catch (\Throwable) {
            return 0;
        }
        $sent = 0;
        foreach ($due as $u) {
            $nid = (int) $u->nominee_id;
            $n = DB::table('gates_nominees')->where('id', $nid)->first(['id', 'name']);
            $reached = 0;
            $follows = $n && SchemaHas::table('gates_nominee_follows')
                ? DB::table('gates_nominee_follows')->where('nominee_id', $nid)->whereNull('stopped_at')->get(['email'])->all() : [];
            foreach ($follows as $f) {
                $email = trim((string) $f->email);
                $key = 'nominee-update:' . (int) $u->id;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !BroadcastLog::claim($key, $email, $nid)) continue;
                [$subject, $html, $plain] = self::compose((string) $n->name, (string) $u->body, $site . NomineeUrl::path($nid), $site, $email);
                $ok = false;
                try {
                    $ok = $send ? (bool) $send($email, $subject, $html, $plain)
                                : (($mailer->sendBranded($email, $subject, $html, $plain, 'Awards')['success'] ?? false) === true);
                } catch (\Throwable $e) {
                    error_log('[nominee-updates] send failed: ' . $e->getMessage());
                }
                BroadcastLog::settle($key, $email, $ok);
                if ($ok) { $sent++; $reached++; }
            }
            DB::table('gates_nominee_updates')->where('id', $u->id)->update(['sent_at' => $now->toDateTimeString(), 'reached' => $reached]);
        }
        return $sent;
    }

    /** @return array{0:string,1:string,2:string} */
    public static function compose(string $name, string $body, string $page, string $site, string $email): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $subject = 'An update from ' . $name;
        $lead = 'You backed ' . $name . ' and asked to hear how they are doing. They wrote:';
        $off = EmailOptOut::url($site, $email);
        [$ink, $ink2, $line, $soft] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('line'), Accent::hex('soft')];
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">' . $e($subject) . '</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($lead) . '</p>'
              . '<blockquote style="margin:16px 0 0;padding:4px 0 4px 16px;border-left:3px solid ' . $line . ';font-size:17px;line-height:1.6;color:' . $ink . '">'
              . nl2br($e($body)) . '<br><span style="font-size:15px;color:' . $ink2 . '">— ' . $e($name) . '</span></blockquote>'
              . '<p style="margin:20px 0 0;font-size:15px"><a href="' . $e($page) . '" style="color:' . $ink . '">See ' . $e($name) . '’s page</a></p>'
              . '<p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:' . $soft . '">You are receiving this because you asked to hear how ' . $e($name) . ' is doing. <a href="' . $e($off) . '" style="color:' . $ink2 . '">Unsubscribe</a>.</p>';
        $plain = $subject . "\n\n" . $lead . "\n\n" . $body . "\n— " . $name . "\n\n" . $name . '’s page: ' . $page
               . "\n\nYou are receiving this because you asked to hear how " . $name . ' is doing. Unsubscribe: ' . $off;
        return [$subject, $html, $plain];
    }

    private static function one(int $nomineeId, int $updateId): ?object
    {
        if (!self::ready()) return null;
        try {
            return DB::table('gates_nominee_updates')->where('id', $updateId)->where('nominee_id', $nomineeId)->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function clean(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", strip_tags($s));
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? '';
        return trim(preg_replace("/\n{3,}/", "\n\n", $s) ?? '');
    }
}
