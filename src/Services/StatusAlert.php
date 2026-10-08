<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "Get status updates" on /status (Phase 9, StatusPageV2 — GAPS §3.14).
 *
 * The old page refused a subscribe button with nothing behind it. This is what is behind
 * it, and it has the same four verbs and the same rules as {@see AwardAlert} and
 * {@see EventSaleAlert}, so the site keeps ONE answer to "how does it ask before it mails
 * you":
 *
 *   want()     records the address and sends ONE confirmation, at most once a day; the reply
 *              is the same sentence whatever happened, so the form is not a lookup.
 *   confirm()  the signed link in that mail — double opt-in, because the form takes
 *              anybody's address. Nothing else is ever sent to an unconfirmed row.
 *   sweep()    every maintenance tick: for each problem the RECORD measured
 *              (SystemStatus::timeline() — the same incidents the page lists), one message
 *              when it starts and one when it is seen to recover, per confirmed address,
 *              each CLAIMED in gates_broadcast_log before it is sent (BroadcastLog), so two
 *              overlapping ticks cannot mail anybody twice.
 *   stop()     the token in every message. One click, no account.
 *
 * ── WHAT IT WILL NOT MAIL ABOUT ──────────────────────────────────────────────
 * A single failing check (a blip the record saw once) — an incident must have been seen
 * on {@see MIN_CHECKS} checks before it is news; and anything older than
 * {@see FRESH_HOURS}, so the first tick after a deploy does not mail a fortnight of history.
 * "Not checked" is never an incident (SystemStatus already holds that).
 *
 * ── AND IT IS OFFERED ONLY WHEN SOMETHING CAN SEND ───────────────────────────
 * {@see senderReady()} asks the transport (OtpService::canSend()): a form
 * promising mail from a platform with no login and no API key would be a promise nobody
 * could keep (§8.15: "Subscribe is shown only when an email sender is configured").
 *
 * The messages carry an unsubscribe link, so the transport treats them as announcements
 * (Mail\SendPolicy): the opt-out list, bounces and the daily cap apply there.
 */
final class StatusAlert
{
    public const RESEND_HOURS = 24;
    public const MIN_CHECKS   = 2;
    public const FRESH_HOURS  = 2;
    public const BATCH        = 200;

    /**
     * May the page offer the form at all? The transport's own answer — OtpService::canSend(),
     * the routes it would actually try — so the page and the sender cannot disagree about
     * whether mail can leave.
     */
    public static function senderReady(?OtpService $mailer): bool
    {
        try {
            return $mailer !== null && $mailer->canSend();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param callable(string $to, string $subject, string $html, string $plain): bool|null $send
     * @return array{ok:bool, message:string, state?:string}
     */
    public static function want(string $email, string $ipHash, string $site, ?callable $send): array
    {
        $email = EmailOptOut::normalise($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter your email address, like name@example.com.'];
        }
        $said = 'Check your inbox: we have sent a link to confirm. Nothing is sent until you press it.';
        $hash = EmailOptOut::hash($email);
        $now  = Carbon::now()->toDateTimeString();
        $row  = DB::table('gates_status_alerts')->where('email_hash', $hash)->first();

        if ($row === null) {
            try {
                DB::table('gates_status_alerts')->insert([
                    'email' => $email, 'email_hash' => $hash, 'token' => bin2hex(random_bytes(16)),
                    'ip_hash' => $ipHash, 'created_at' => $now,
                ]);
            } catch (\Throwable) {
                // A concurrent submit won the UNIQUE key. Same person, same outcome.
            }
            $row = DB::table('gates_status_alerts')->where('email_hash', $hash)->first();
        } elseif ($row->cancelled_at !== null) {
            // Asking again after stopping is a new request, and needs a new yes.
            DB::table('gates_status_alerts')->where('id', (int) $row->id)
                ->update(['cancelled_at' => null, 'confirmed_at' => null]);
            $row = DB::table('gates_status_alerts')->where('id', (int) $row->id)->first();
        }
        if (!$row) return ['ok' => true, 'message' => $said, 'state' => 'raced'];
        if ($row->confirmed_at !== null) return ['ok' => true, 'message' => $said, 'state' => 'confirmed'];

        $recent = $row->confirm_sent_at !== null
            && Carbon::parse((string) $row->confirm_sent_at)->gt(Carbon::now()->subHours(self::RESEND_HOURS));
        if (!$recent && $send !== null && $site !== '') {
            [$subject, $html, $plain] = self::confirmMail($row, $site);
            try { $send($email, $subject, $html, $plain); } catch (\Throwable) {}
            // Stamped whether or not the transport took it: a broken transport must not turn
            // "once a day" into a retry loop on a public form.
            DB::table('gates_status_alerts')->where('id', (int) $row->id)->update(['confirm_sent_at' => $now]);
        }
        return ['ok' => true, 'message' => $said, 'state' => $recent ? 'throttled' : 'asked'];
    }

    public static function find(string $token): ?object
    {
        $token = strtolower((string) preg_replace('/[^a-f0-9]/i', '', $token));
        if (strlen($token) !== 32) return null;
        try {
            $row = DB::table('gates_status_alerts')->where('token', $token)->first();
        } catch (\Throwable) {
            return null;
        }
        return $row ?: null;
    }

    public static function confirm(string $token): ?object
    {
        $row = self::find($token);
        if (!$row) return null;
        DB::table('gates_status_alerts')->where('id', (int) $row->id)->update([
            'confirmed_at' => $row->confirmed_at ?? Carbon::now()->toDateTimeString(),
            'cancelled_at' => null,
        ]);
        return DB::table('gates_status_alerts')->where('id', (int) $row->id)->first();
    }

    public static function stop(string $token): ?object
    {
        $row = self::find($token);
        if (!$row) return null;
        DB::table('gates_status_alerts')->where('id', (int) $row->id)
            ->whereNull('cancelled_at')->update(['cancelled_at' => Carbon::now()->toDateTimeString()]);
        return DB::table('gates_status_alerts')->where('id', (int) $row->id)->first();
    }

    /**
     * The news the record holds right now: problems that started, and problems seen to
     * recover, within the freshness window and past the blip threshold.
     *
     * @param list<array<string,mixed>>|null $incidents injectable for the test
     * @return list<array{key:string, kind:string, incident:array<string,mixed>}>
     */
    public static function news(?array $incidents = null): array
    {
        $incidents ??= SystemStatus::timeline()['incidents'];
        $since = Carbon::now()->subHours(self::FRESH_HOURS)->toDateTimeString();
        $out   = [];
        foreach ($incidents as $i) {
            if ((int) ($i['checks'] ?? 0) < self::MIN_CHECKS) continue;
            $id = substr(sha1($i['name'] . '|' . $i['from']), 0, 20);
            // The SECOND check is the moment it became news, so "started" is fresh while
            // that is recent — measured by the run's last check if it is still going.
            if ($i['ongoing'] && strcmp((string) $i['to'], $since) >= 0) {
                $out[] = ['key' => 'status:' . $id . ':start', 'kind' => 'start', 'incident' => $i];
            }
            if (!$i['ongoing'] && strcmp((string) $i['to'], $since) >= 0) {
                $out[] = ['key' => 'status:' . $id . ':fixed', 'kind' => 'fixed', 'incident' => $i];
            }
        }
        return $out;
    }

    /** @return int messages sent (0 = ran, nothing due) */
    public static function sweep(?OtpService $mailer, ?string $site = null, ?array $incidents = null): int
    {
        if ($mailer === null) return 0;
        $news = self::news($incidents);
        if ($news === []) return 0;
        $site = rtrim($site ?? SiteUrl::base(), '/');

        $rows = DB::table('gates_status_alerts')->whereNotNull('confirmed_at')->whereNull('cancelled_at')
            ->orderBy('id')->limit(self::BATCH)->get();
        $sent = 0;
        foreach ($news as $n) {
            foreach ($rows as $r) {
                if (!BroadcastLog::claim($n['key'], (string) $r->email)) continue;   // another tick has it
                [$subject, $html, $plain] = self::newsMail($r, $n, $site);
                $res = [];
                try {
                    $res = $mailer->sendBranded((string) $r->email, $subject, $html, $plain, 'Status', '',
                                                self::stopUrl($site, (string) $r->token));
                } catch (\Throwable $e) {
                    $res = ['success' => false, 'error' => $e->getMessage()];
                }
                $ok = (bool) ($res['success'] ?? false);
                BroadcastLog::settle($n['key'], (string) $r->email, $ok, (string) ($res['error'] ?? ''));
                if ($ok) $sent++;
            }
        }
        return $sent;
    }

    public static function confirmUrl(string $site, string $token): string
    {
        return rtrim($site, '/') . '/status/alerts/' . $token . '/confirm';
    }

    public static function stopUrl(string $site, string $token): string
    {
        return rtrim($site, '/') . '/status/alerts/' . $token . '/stop';
    }

    /** @return array{0:string,1:string,2:string} */
    public static function confirmMail(object $row, string $site): array
    {
        $url  = self::confirmUrl($site, (string) $row->token);
        $e    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $lede = 'Somebody — we hope you — asked for Africa GATES status updates. Confirm, and we will email '
              . 'you when a problem we measure starts and when it is fixed. Nothing else.';
        $after = 'If it was not you, ignore this email. Nothing is sent to an address that has not been confirmed.';
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . Accent::hex('ink') . '">Confirm status updates</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . Accent::hex('ink-2') . '">' . $e($lede) . '</p>'
              . '<p style="text-align:center;margin:24px 0"><a href="' . $e($url) . '" style="display:inline-block;padding:13px 28px;background:' . Accent::hex('green') . ';color:' . Accent::hex('surface') . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">Yes, send me updates</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . Accent::hex('soft') . '">' . $e($after) . '</p>';
        $plain = "Confirm status updates\n\n{$lede}\n\nConfirm here: {$url}\n\n{$after}";
        return ['Confirm: Africa GATES status updates', $html, $plain];
    }

    /**
     * @param array{kind:string, incident:array<string,mixed>} $n
     * @return array{0:string,1:string,2:string}
     */
    public static function newsMail(object $row, array $n, string $site): array
    {
        $i     = $n['incident'];
        $name  = (string) $i['name'];
        $state = mb_strtolower(SystemStatus::LABELS[(string) $i['status']] ?? (string) $i['status']);
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        if ($n['kind'] === 'start') {
            $subject = $name . ' — ' . $state;
            $lede    = 'Our checks show ' . $name . ' as ' . $state . ' since '
                     . Carbon::parse((string) $i['from'])->format('j M, H:i') . '. We will email again when it is fixed.';
        } else {
            $subject = $name . ' is working again';
            $lede    = $name . ' was ' . $state . ' for ' . (string) $i['duration'] . ', and our checks show it working again.';
        }
        $url  = $site . '/status';
        $stop = self::stopUrl($site, (string) $row->token);
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . Accent::hex('ink') . '">' . $e($subject) . '</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . Accent::hex('ink-2') . '">' . $e($lede) . '</p>'
              . '<p style="text-align:center;margin:24px 0"><a href="' . $e($url) . '" style="display:inline-block;padding:13px 28px;background:' . Accent::hex('ink') . ';color:' . Accent::hex('surface') . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">See the status page</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . Accent::hex('soft') . '"><a href="' . $e($stop) . '" style="color:' . Accent::hex('ink-2') . '">Stop status updates</a>.</p>';
        $plain = "{$subject}\n\n{$lede}\n\n{$url}\n\nStop status updates: {$stop}";
        return [$subject, $html, $plain];
    }
}
