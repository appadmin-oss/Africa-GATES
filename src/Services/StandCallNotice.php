<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Telling the people who asked that the call has opened.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The call page for an unpublished event says the terms are not up yet and asks for an
 * address. Without this, that address goes into a list nobody reads and the button is a
 * promise the platform cannot keep — which is worse than the grey sentence it replaced,
 * because the grey sentence promised nothing.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * QUEUED, NOT SENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Opening a call is one press of one button, and the press must not become as slow as the
 * list is long. Four hundred interested vendors would hang the request, time out behind a
 * proxy, and leave an operator pressing it again against a call that is already open.
 *
 * So each message is a job with a dedupe key of (call, address). Pressing twice queues
 * nothing the second time; a maintenance tick that dies halfway resumes where it stopped;
 * and a call re-opened after being closed does not write to everybody a second time.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT IS AN ANNOUNCEMENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Somebody typed their address into a box that said "email me when it opens", so this is
 * the one thing they asked for — and it is still a broadcast. It honours the opt-out list
 * and it carries a way out, for the same reason the supporter fan-out does: what the
 * unsubscribe page promises is the platform's word, not a per-feature preference.
 */
final class StandCallNotice
{
    public const JOB_NOTICE = 'stand.call_opened';

    /** The `source` a call page writes when somebody asks to be told. */
    public static function source(string $eventSlug): string
    {
        // Truncated to what the column holds, because a long slug silently becoming a
        // different string is a list that can never be found again.
        return mb_substr('stands:' . trim($eventSlug), 0, 50);
    }

    /**
     * Queue one message per interested address.
     *
     * @return array{queued:int, skipped:int, unsubscribed:int}
     */
    public static function queueForCall(int $callId, ?QueueService $q = null): array
    {
        $out = ['queued' => 0, 'skipped' => 0, 'unsubscribed' => 0];

        try {
            $call = DB::table('gates_stand_calls')->where('id', $callId)->first(['id', 'event_id']);
            if (!$call) return $out;

            $slug = (string) (DB::table('gates_site_events')
                ->where('id', (int) $call->event_id)->value('slug') ?? '');
            if ($slug === '') return $out;

            $rows = DB::table('gates_newsletter')
                ->where('source', self::source($slug))
                ->whereNull('unsubscribed_at')
                ->get(['email']);

            // Read once. Failing OPEN here would write to everybody who ever asked not to
            // be written to, in one burst, on the day a call opens.
            $suppressed = EmailOptOut::suppressedHashes();
        } catch (\Throwable $e) {
            error_log('[stand-call-notice] could not build the list for call ' . $callId
                    . ': ' . $e->getMessage());
            return $out;
        }

        $queue = $q ?? new QueueService();

        foreach ($rows as $r) {
            $email = strtolower(trim((string) $r->email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

            if (isset($suppressed[EmailOptOut::hash($email)])) { $out['unsubscribed']++; continue; }

            try {
                $id = $queue->push(
                    self::JOB_NOTICE,
                    ['call_id' => $callId, 'email' => $email],
                    0,
                    // Per CALL and per address. Keyed on the event alone, a market that runs
                    // twice a year would tell its vendors once and never again.
                    self::JOB_NOTICE . ':' . $callId . ':' . EmailOptOut::hash($email)
                );
            } catch (\Throwable $e) {
                error_log('[stand-call-notice] could not queue for call ' . $callId . ': ' . $e->getMessage());
                continue;
            }

            // push() returns 0 when the key already existed — the second press doing
            // nothing, which is the point of the key.
            $id > 0 ? $out['queued']++ : $out['skipped']++;
        }

        return $out;
    }

    /**
     * Send one queued message.
     *
     * Re-reads the call rather than trusting the payload: between the press and the tick a
     * call can be closed again, and a message announcing an open call to somebody who then
     * follows the link to a closed one is worse than silence.
     */
    public static function deliver(array $p, ?OtpService $mailer = null): bool
    {
        // A throw and not a false, for the reason StandNotice documents: returning false
        // marks the job DONE, so a deployment with briefly broken SMTP would consume every
        // pending message and log them all as handled. Throwing puts the job back.
        if ($mailer === null) {
            throw new \RuntimeException('stand call notice: no mailer available');
        }

        $callId = (int) ($p['call_id'] ?? 0);
        $email  = strtolower(trim((string) ($p['email'] ?? '')));
        if ($callId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

        try {
            $call = DB::table('gates_stand_calls')->where('id', $callId)->first();
            if (!$call || (string) $call->status !== StandCall::STATUS_OPEN) return false;

            $event = DB::table('gates_site_events')->where('id', (int) $call->event_id)
                ->first(['slug', 'title', 'location', 'event_date']);
            if (!$event) return false;

            // Checked again here and not only at queue time. Somebody who unsubscribed in
            // the minutes between the two should not receive the message that was already
            // in the queue when they did it.
            if (EmailOptOut::suppressed($email)) return false;
        } catch (\Throwable $e) {
            throw new \RuntimeException('stand call notice: ' . $e->getMessage());
        }

        $base  = rtrim(\AfricaGates\Support\SiteUrl::base(), '/');
        $url   = $base . '/events/' . (string) $event->slug . '/stands';
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $title = (string) $event->title;

        $closes = trim((string) ($call->closes_at ?? ''));
        $when   = $closes !== '' ? date('l j F Y', (int) strtotime($closes)) : '';

        $html = '<p>You asked to be told when stand applications opened for <strong>'
              . $e($title) . '</strong>. They are open.</p>'
              . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0;'
              . 'background:#f6f2e6;border-left:4px solid #c9a24b;border-radius:0 8px 8px 0;padding:14px 18px">'
              . '<tr><td style="font-size:14px;color:#5b4a1f;line-height:1.8">'
              . ($when !== '' ? 'Applications close: <strong>' . $e($when) . '</strong><br>' : '')
              . 'Applying costs nothing, and no account is needed first.'
              . '</td></tr></table>'
              // The reassurance is the point of the message as much as the news is. Somebody
              // who reads "it is open" and believes places go to whoever is quickest fills
              // in an empty form badly, and the page's whole promise is the opposite.
              . '<p style="font-size:15px;line-height:1.7">Nothing is allocated first-come. The '
              . 'prices, the pitch sizes, the number of places in each category and the closing '
              . 'date are all on the page now and none of them change — so reading it carefully '
              . 'is worth more than reaching it early.</p>'
              . '<p style="text-align:center;margin:22px 0"><a href="' . $e($url)
              . '" style="display:inline-block;padding:13px 30px;background:#12481d;color:#fff;'
              . 'border-radius:999px;font-weight:600;text-decoration:none;font-size:15px">'
              . 'Read the terms &rarr;</a></p>';

        $plain = "You asked to be told when stand applications opened for {$title}. They are open.\n\n"
               . ($when !== '' ? "Applications close: {$when}\n" : '')
               . "Applying costs nothing, and no account is needed first.\n\n"
               . "Nothing is allocated first-come. The prices, the pitch sizes, the number of places "
               . "in each category and the closing date are all on the page now and none of them "
               . "change — so reading it carefully is worth more than reaching it early.\n\n"
               . $url . "\n\n— Africa GATES\n\n"
               . 'No more announcement emails: ' . EmailOptOut::url($base, $email);

        $r = $mailer->sendBranded($email, 'Stand applications are open — ' . $title,
                                  $html, $plain, 'Vendor stands', '',
                                  EmailOptOut::url($base, $email));

        // False marks it done; a send that reported failure has to come back, so it throws.
        if (!($r['success'] ?? false)) {
            throw new \RuntimeException('stand call notice: send failed for call ' . $callId);
        }

        return true;
    }
}
