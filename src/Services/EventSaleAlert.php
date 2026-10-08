<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\EventTime;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "Email me when tickets go on sale" — the coming-soon state's one action (owner, 4 Oct 2026).
 *
 * ── ASKING · CONFIRMING · TELLING · STOPPING ─────────────────────────────────
 *
 *   want()     records (event, address) and sends ONE confirmation, at most once a day per
 *              row, whatever happened — the reply to the person is identical either way, so
 *              the form is not a lookup of who asked about what (StockAlert's rule).
 *   confirm()  the signed link in that mail. Nothing else is ever sent to an unconfirmed
 *              row: double opt-in, because the form takes anybody's address.
 *   sweep()    every maintenance tick: for each event whose tickets are now on sale
 *              (EventSales says `open`, `waitlist` or `soldout` — anything but `soon`), claim
 *              each confirmed, unsent row and send it. CLAIM FIRST (`notified_at` by a
 *              guarded UPDATE, affected rows decide the owner — Support\BroadcastLog's rule):
 *              two overlapping ticks cannot mail one person twice.
 *   stop()     the token in either mail. One click, no account (StockAlert's rule).
 *
 * ── IT IS AN ANNOUNCEMENT, SO THE TRANSPORT DECIDES WHO MAY RECEIVE IT ───────
 *
 * The on-sale notice carries an unsubscribe link, which is what makes it an announcement to
 * `Mail\SendPolicy::decide()` inside `OtpService::dispatch()`: the opt-out list, recorded
 * bounces and the daily cap apply there, at the one road every message takes, and nothing
 * here re-implements them. The confirmation is one message to one person who asked, so — like
 * the newsletter's — it carries no list headers; SendPolicy still refuses a reserved domain.
 * A held send (`refused`/`deferred`) is not retried by this class: the row stays claimed,
 * because "the transport decided not to mail this address" is an answer, not a fault.
 */
final class EventSaleAlert
{
    public const RESEND_HOURS = 24;
    /** Per tick, so a hall of interest does not outlive a shared host's execution limit. */
    public const BATCH = 100;

    // ══ asking ═══════════════════════════════════════════════════════════════

    /**
     * @param callable(string $to, string $subject, string $html, string $plain): bool|null $send
     * @return array{ok:bool, message:string, state?:string}
     */
    public static function want(int $eventId, string $email, string $ipHash, string $site, ?callable $send): array
    {
        $email = EmailOptOut::normalise($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter your email address, like name@example.com.'];
        }
        $event = DB::table('gates_site_events')->where('id', $eventId)->where('status', 'published')->first();
        if (!$event) return ['ok' => false, 'message' => 'That event is no longer listed.'];

        $said = 'Check your inbox: we have sent a link to confirm. Nothing else is sent until you press it.';

        $hash = EmailOptOut::hash($email);
        $now  = Carbon::now()->toDateTimeString();
        $row  = DB::table('gates_event_sale_alerts')->where('event_id', $eventId)->where('email_hash', $hash)->first();

        if ($row === null) {
            try {
                DB::table('gates_event_sale_alerts')->insert([
                    'event_id' => $eventId, 'email' => $email, 'email_hash' => $hash,
                    'token' => bin2hex(random_bytes(16)), 'ip_hash' => $ipHash, 'created_at' => $now,
                ]);
            } catch (\Throwable) {
                // A concurrent submit won the UNIQUE key. Same person, same outcome.
            }
            $row = DB::table('gates_event_sale_alerts')->where('event_id', $eventId)->where('email_hash', $hash)->first();
        } elseif ($row->cancelled_at !== null) {
            // Asking again after stopping is a new request, and needs a new yes.
            DB::table('gates_event_sale_alerts')->where('id', (int) $row->id)
                ->update(['cancelled_at' => null, 'confirmed_at' => null, 'notified_at' => null]);
            $row = DB::table('gates_event_sale_alerts')->where('id', (int) $row->id)->first();
        }
        if (!$row) return ['ok' => true, 'message' => $said, 'state' => 'raced'];

        if ($row->confirmed_at !== null) return ['ok' => true, 'message' => $said, 'state' => 'confirmed'];

        $recent = $row->confirm_sent_at !== null
            && Carbon::parse((string) $row->confirm_sent_at)->gt(Carbon::now()->subHours(self::RESEND_HOURS));
        if (!$recent && $send !== null && $site !== '') {
            [$subject, $html, $plain] = self::confirmMail($row, $event, $site);
            try { $send($email, $subject, $html, $plain); } catch (\Throwable) {}
            // Stamped whether or not the transport took it — a broken transport must not
            // turn "once a day" into a retry loop on a public form.
            DB::table('gates_event_sale_alerts')->where('id', (int) $row->id)->update(['confirm_sent_at' => $now]);
        }
        return ['ok' => true, 'message' => $said, 'state' => $recent ? 'throttled' : 'asked'];
    }

    public static function find(string $token): ?object
    {
        $token = strtolower((string) preg_replace('/[^a-f0-9]/i', '', $token));
        if (strlen($token) !== 32) return null;
        $row = DB::table('gates_event_sale_alerts')->where('token', $token)->first();
        return $row ?: null;
    }

    /** The yes. Lifts a global opt-out for the address, as the newsletter's confirm does. */
    public static function confirm(string $token): ?object
    {
        $row = self::find($token);
        if (!$row) return null;
        DB::table('gates_event_sale_alerts')->where('id', (int) $row->id)->update([
            'confirmed_at' => $row->confirmed_at ?? Carbon::now()->toDateTimeString(),
            'cancelled_at' => null,
        ]);
        return DB::table('gates_event_sale_alerts')->where('id', (int) $row->id)->first();
    }

    public static function stop(string $token): ?object
    {
        $row = self::find($token);
        if (!$row) return null;
        DB::table('gates_event_sale_alerts')->where('id', (int) $row->id)
            ->whereNull('cancelled_at')->update(['cancelled_at' => Carbon::now()->toDateTimeString()]);
        return DB::table('gates_event_sale_alerts')->where('id', (int) $row->id)->first();
    }

    /** Confirmed and still waiting, for the page's "N people are waiting" — never a name. */
    public static function waiting(int $eventId): int
    {
        try {
            return (int) DB::table('gates_event_sale_alerts')->where('event_id', $eventId)
                ->whereNotNull('confirmed_at')->whereNull('notified_at')->whereNull('cancelled_at')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ══ telling ══════════════════════════════════════════════════════════════

    /** @return int how many notices were sent (0 = ran, nothing due) */
    public static function sweep(?OtpService $mailer, ?string $site = null): int
    {
        if ($mailer === null) return 0;
        $site = rtrim($site ?? SiteUrl::base(), '/');

        $eventIds = DB::table('gates_event_sale_alerts')
            ->whereNotNull('confirmed_at')->whereNull('notified_at')->whereNull('cancelled_at')
            ->distinct()->pluck('event_id')->all();

        $sent = 0;
        foreach ($eventIds as $eid) {
            $event = DB::table('gates_site_events')->where('id', (int) $eid)->where('status', 'published')->first();
            if (!$event) continue;
            $state = EventSales::state((array) $event, EventTicketService::tiers((int) $eid));
            // Still not on sale, or already over: nothing to announce. An ended event's
            // waiting rows are left as they are — the honest record is "never told", and
            // a message about tickets to something that has happened is worse than none.
            if (in_array($state, ['soon', 'ended'], true)) continue;

            $rows = DB::table('gates_event_sale_alerts')->where('event_id', (int) $eid)
                ->whereNotNull('confirmed_at')->whereNull('notified_at')->whereNull('cancelled_at')
                ->orderBy('id')->limit(self::BATCH - $sent)->get();
            foreach ($rows as $r) {
                // CLAIM FIRST. Affected rows decide who owns this address on this tick.
                $mine = DB::table('gates_event_sale_alerts')->where('id', (int) $r->id)->whereNull('notified_at')
                    ->update(['notified_at' => Carbon::now()->toDateTimeString()]);
                if ($mine !== 1) continue;
                [$subject, $html, $plain] = self::onSaleMail($r, $event, $site);
                try {
                    // The platform's own unsubscribe as the list header (the StandCallNotice
                    // convention: what that page promises is the platform's word), and this
                    // alert's own stop link in the body.
                    $mailer->sendBranded((string) $r->email, $subject, $html, $plain, 'Events', '',
                                         EmailOptOut::url($site, (string) $r->email));
                    $sent++;
                } catch (\Throwable $e) {
                    error_log('[event-sale-alert] send failed for alert ' . (int) $r->id . ': ' . $e->getMessage());
                }
                if ($sent >= self::BATCH) return $sent;
            }
        }
        return $sent;
    }

    // ══ the words ════════════════════════════════════════════════════════════

    public static function confirmUrl(string $site, string $token): string
    {
        return rtrim($site, '/') . '/events/alerts/' . $token . '/confirm';
    }

    public static function stopUrl(string $site, string $token): string
    {
        return rtrim($site, '/') . '/events/alerts/' . $token . '/stop';
    }

    /** @return array{0:string,1:string,2:string} */
    public static function confirmMail(object $row, object $event, string $site): array
    {
        $title = (string) $event->title;
        $url   = self::confirmUrl($site, (string) $row->token);
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        [$ink, $ink2, $soft, $go, $on] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('soft'),
                                          Accent::hex('green'), Accent::hex('surface')];
        $lede = 'Somebody — we hope you — asked to be emailed when tickets for ' . $title
              . ' go on sale. Confirm, and we will send one email on the day they do.';
        $after = 'If it was not you, ignore this email. Nothing is sent to an address that has not been confirmed.';

        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">Confirm your ticket alert</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($lede) . '</p>'
              . '<p style="text-align:center;margin:24px 0"><a href="' . $e($url) . '" style="display:inline-block;padding:13px 28px;background:' . $go . ';color:' . $on . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">Yes, email me</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . $soft . '">' . $e($after) . '</p>';
        $plain = "Confirm your ticket alert\n\n{$lede}\n\nConfirm here: {$url}\n\n{$after}";

        return ['Confirm: tell me when tickets for ' . $title . ' go on sale', $html, $plain];
    }

    /** @return array{0:string,1:string,2:string} */
    public static function onSaleMail(object $row, object $event, string $site): array
    {
        $title = (string) $event->title;
        $url   = $site . '/events/' . rawurlencode((string) $event->slug);
        $when  = EventTime::zoned($event, (string) $event->event_date, 'l j F Y, H:i');
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        [$ink, $ink2, $go, $on] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('green'), Accent::hex('surface')];

        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">Tickets are on sale</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">You asked to be told when tickets for <strong>'
              . $e($title) . '</strong> went on sale. They are on sale now.</p>'
              . ($when !== '' ? '<p style="margin:10px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($when) . '</p>' : '')
              . '<p style="text-align:center;margin:24px 0"><a href="' . $e($url) . '" style="display:inline-block;padding:13px 28px;background:' . $go . ';color:' . $on . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">See the tickets</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . Accent::hex('soft') . '">This is the only email this alert sends. <a href="' . $e(self::stopUrl($site, (string) $row->token)) . '" style="color:' . $ink2 . '">Stop alerts for this event</a>.</p>';
        $plain = "Tickets are on sale\n\nYou asked to be told when tickets for {$title} went on sale. They are on sale now.\n"
               . ($when !== '' ? $when . "\n" : '') . "\n{$url}\n\nNo more emails about this event: " . self::stopUrl($site, (string) $row->token);

        return ['Tickets are on sale — ' . $title, $html, $plain];
    }
}
