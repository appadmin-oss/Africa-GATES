<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "Notify me" on an award that has not opened yet (AwardsPage view=soon, §8.3, GAPS §3.3).
 *
 * The same four verbs, and the same rules, as {@see EventSaleAlert} — deliberately, so the
 * site has one answer to "how does it ask before it mails you":
 *
 *   want()     records (programme, address) and sends ONE confirmation, at most once a day
 *              per row; the reply to the person is identical whatever happened, so the form
 *              is not a lookup of who is waiting for what.
 *   confirm()  the signed link in that mail — double opt-in, because the form takes
 *              anybody's address. Nothing else is ever sent to an unconfirmed row.
 *   sweep()    every maintenance tick: for each programme whose current edition has left
 *              `upcoming` (nominations are open, or anything after), CLAIM each confirmed
 *              unsent row by a guarded UPDATE and send it (Support\BroadcastLog's rule), so
 *              overlapping ticks cannot mail one person twice.
 *   stop()     the token in either mail. One click, no account.
 *
 * The notice carries an unsubscribe link, so the transport treats it as an announcement
 * (`Mail\SendPolicy`): the opt-out list, bounces and the daily cap apply there and nothing
 * here re-implements them.
 *
 * The DC also offers SMS and WhatsApp. Only email is built: a phone number needs its own
 * double opt-in by text message, and none of this platform's SMS paths confirm a number
 * before using it (docs/handoff/PHASE-5.md, deviations).
 */
final class AwardAlert
{
    public const RESEND_HOURS = 24;
    public const BATCH = 100;

    /**
     * @param callable(string $to, string $subject, string $html, string $plain): bool|null $send
     * @return array{ok:bool, message:string, state?:string}
     */
    public static function want(int $programmeId, string $email, string $ipHash, string $site, ?callable $send): array
    {
        $email = EmailOptOut::normalise($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter your email address, like name@example.com.'];
        }
        $prog = DB::table('gates_award_programmes')->where('id', $programmeId)->where('is_active', 1)->first();
        if (!$prog) return ['ok' => false, 'message' => 'That award is no longer listed.'];

        $said = 'Check your inbox: we have sent a link to confirm. Nothing else is sent until you press it.';
        $hash = EmailOptOut::hash($email);
        $now  = Carbon::now()->toDateTimeString();
        $row  = DB::table('gates_award_alerts')->where('programme_id', $programmeId)->where('email_hash', $hash)->first();

        if ($row === null) {
            try {
                DB::table('gates_award_alerts')->insert([
                    'programme_id' => $programmeId, 'email' => $email, 'email_hash' => $hash,
                    'token' => bin2hex(random_bytes(16)), 'ip_hash' => $ipHash, 'created_at' => $now,
                ]);
            } catch (\Throwable) {
                // A concurrent submit won the UNIQUE key. Same person, same outcome.
            }
            $row = DB::table('gates_award_alerts')->where('programme_id', $programmeId)->where('email_hash', $hash)->first();
        } elseif ($row->cancelled_at !== null) {
            // Asking again after stopping is a new request, and needs a new yes.
            DB::table('gates_award_alerts')->where('id', (int) $row->id)
                ->update(['cancelled_at' => null, 'confirmed_at' => null, 'notified_at' => null]);
            $row = DB::table('gates_award_alerts')->where('id', (int) $row->id)->first();
        }
        if (!$row) return ['ok' => true, 'message' => $said, 'state' => 'raced'];
        if ($row->confirmed_at !== null) return ['ok' => true, 'message' => $said, 'state' => 'confirmed'];

        $recent = $row->confirm_sent_at !== null
            && Carbon::parse((string) $row->confirm_sent_at)->gt(Carbon::now()->subHours(self::RESEND_HOURS));
        if (!$recent && $send !== null && $site !== '') {
            [$subject, $html, $plain] = self::confirmMail($row, $prog, $site);
            try { $send($email, $subject, $html, $plain); } catch (\Throwable) {}
            // Stamped whether or not the transport took it: a broken transport must not turn
            // "once a day" into a retry loop on a public form.
            DB::table('gates_award_alerts')->where('id', (int) $row->id)->update(['confirm_sent_at' => $now]);
        }
        return ['ok' => true, 'message' => $said, 'state' => $recent ? 'throttled' : 'asked'];
    }

    public static function find(string $token): ?object
    {
        $token = strtolower((string) preg_replace('/[^a-f0-9]/i', '', $token));
        if (strlen($token) !== 32) return null;
        try {
            $row = DB::table('gates_award_alerts')->where('token', $token)->first();
        } catch (\Throwable) {
            return null;
        }
        return $row ?: null;
    }

    public static function confirm(string $token): ?object
    {
        $row = self::find($token);
        if (!$row) return null;
        DB::table('gates_award_alerts')->where('id', (int) $row->id)->update([
            'confirmed_at' => $row->confirmed_at ?? Carbon::now()->toDateTimeString(),
            'cancelled_at' => null,
        ]);
        return DB::table('gates_award_alerts')->where('id', (int) $row->id)->first();
    }

    public static function stop(string $token): ?object
    {
        $row = self::find($token);
        if (!$row) return null;
        DB::table('gates_award_alerts')->where('id', (int) $row->id)
            ->whereNull('cancelled_at')->update(['cancelled_at' => Carbon::now()->toDateTimeString()]);
        return DB::table('gates_award_alerts')->where('id', (int) $row->id)->first();
    }

    /** Confirmed and still waiting — "N people are waiting", never a name. */
    public static function waiting(int $programmeId): int
    {
        try {
            return (int) DB::table('gates_award_alerts')->where('programme_id', $programmeId)
                ->whereNotNull('confirmed_at')->whereNull('notified_at')->whereNull('cancelled_at')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return int notices sent (0 = ran, nothing due) */
    public static function sweep(?OtpService $mailer, ?string $site = null): int
    {
        if ($mailer === null) return 0;
        $site = rtrim($site ?? SiteUrl::base(), '/');

        $ids = DB::table('gates_award_alerts')
            ->whereNotNull('confirmed_at')->whereNull('notified_at')->whereNull('cancelled_at')
            ->distinct()->pluck('programme_id')->all();

        $sent = 0;
        foreach ($ids as $pid) {
            $prog = DB::table('gates_award_programmes')->where('id', (int) $pid)->where('is_active', 1)->first();
            if (!$prog) continue;
            $cycle = BallotGuard::currentCycleForProgramme((int) $pid);
            if (!$cycle) continue;
            $phase = CyclePolicy::phaseFor($cycle)->value;
            // Still upcoming: nothing to announce. Archived: the moment passed — the honest
            // record is "never told", and a notice about an award that has finished is worse
            // than none.
            if (in_array($phase, ['upcoming', 'archived'], true)) continue;

            $rows = DB::table('gates_award_alerts')->where('programme_id', (int) $pid)
                ->whereNotNull('confirmed_at')->whereNull('notified_at')->whereNull('cancelled_at')
                ->orderBy('id')->limit(self::BATCH - $sent)->get();
            foreach ($rows as $r) {
                $mine = DB::table('gates_award_alerts')->where('id', (int) $r->id)->whereNull('notified_at')
                    ->update(['notified_at' => Carbon::now()->toDateTimeString()]);
                if ($mine !== 1) continue;
                [$subject, $html, $plain] = self::openMail($r, $prog, $phase, $site);
                try {
                    $mailer->sendBranded((string) $r->email, $subject, $html, $plain, 'Awards', '',
                                         EmailOptOut::url($site, (string) $r->email));
                    $sent++;
                } catch (\Throwable $e) {
                    error_log('[award-alert] send failed for alert ' . (int) $r->id . ': ' . $e->getMessage());
                }
                if ($sent >= self::BATCH) return $sent;
            }
        }
        return $sent;
    }

    public static function confirmUrl(string $site, string $token): string
    {
        return rtrim($site, '/') . '/awards/alerts/' . $token . '/confirm';
    }

    public static function stopUrl(string $site, string $token): string
    {
        return rtrim($site, '/') . '/awards/alerts/' . $token . '/stop';
    }

    /** @return array{0:string,1:string,2:string} */
    public static function confirmMail(object $row, object $prog, string $site): array
    {
        $title = (string) $prog->title;
        $url   = self::confirmUrl($site, (string) $row->token);
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        [$ink, $ink2, $soft, $go, $on] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('soft'),
                                          Accent::hex('green'), Accent::hex('surface')];
        $lede  = 'Somebody — we hope you — asked to be emailed when ' . $title
               . ' opens. Confirm, and we will send one email on the day nominations open.';
        $after = 'If it was not you, ignore this email. Nothing is sent to an address that has not been confirmed.';

        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">Confirm your award alert</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($lede) . '</p>'
              . '<p style="text-align:center;margin:24px 0"><a href="' . $e($url) . '" style="display:inline-block;padding:13px 28px;background:' . $go . ';color:' . $on . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">Yes, email me</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . $soft . '">' . $e($after) . '</p>';
        $plain = "Confirm your award alert\n\n{$lede}\n\nConfirm here: {$url}\n\n{$after}";

        return ['Confirm: tell me when ' . $title . ' opens', $html, $plain];
    }

    /** @return array{0:string,1:string,2:string} */
    public static function openMail(object $row, object $prog, string $phase, string $site): array
    {
        $title = (string) $prog->title;
        $url   = $site . '/awards/' . rawurlencode((string) $prog->slug);
        $what  = $phase === 'nominations' ? 'Nominations are open.' : 'It has opened.';
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        [$ink, $ink2, $go, $on] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('green'), Accent::hex('surface')];

        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">' . $e($title) . ' is open</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">You asked to be told when <strong>'
              . $e($title) . '</strong> opened. ' . $e($what) . '</p>'
              . '<p style="text-align:center;margin:24px 0"><a href="' . $e($url) . '" style="display:inline-block;padding:13px 28px;background:' . $go . ';color:' . $on . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">See the award</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . Accent::hex('soft') . '">This is the only email this alert sends. <a href="' . $e(self::stopUrl($site, (string) $row->token)) . '" style="color:' . $ink2 . '">Stop alerts for this award</a>.</p>';
        $plain = "{$title} is open\n\nYou asked to be told when {$title} opened. {$what}\n\n{$url}\n\n"
               . 'No more emails about this award: ' . self::stopUrl($site, (string) $row->token);

        return [$title . ' is open', $html, $plain];
    }
}
