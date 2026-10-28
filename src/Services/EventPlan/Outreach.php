<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteMailer;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\Requests;
use AfricaGates\Support\BroadcastLog;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHAT A LEAD SENDS (consoles v7 §5, Relationships › Letters and Messages).
 *
 *   · LETTERS go through the SAME mailer and the same queue staff use (InviteMailer,
 *     EventInvites::sendQueue): one letter per guest of honour, ever, in batches, both
 *     audiences in turn. A lead pressing Send and staff pressing Send cannot double a letter,
 *     because the queue is "not yet sent" and the letter stamps `sent_at`.
 *   · A BIG SEND NEEDS ITS REQUEST APPROVED FIRST. The lead asks (a `message` request), an
 *     administrator decides, and only an approved request can be sent — once, to the audience
 *     it named. Each address is CLAIMED before it is mailed (BroadcastLog::claim, UNIQUE per
 *     campaign and address), so a second press, a double click or two tabs can never mail
 *     anybody twice; a press sends a batch and says who is left.
 *   · These are about an event the person holds a ticket or an invitation for, so they go
 *     one to one through the transport, which still applies every send rule (reserved
 *     domains, bounces, the daily cap).
 */
final class Outreach
{
    public const BATCH = 60;
    public const AUDIENCES = ['tickets' => 'Ticket holders', 'guests' => 'Guests of honour', 'both' => 'Ticket holders and guests of honour'];

    /** Send the next batch of invitation letters for the lead's event. @return array{ok:bool, error?:string, sent?:int, failed?:int, left?:int, event?:int} */
    public static function sendLetters(int $eventId, int $adminId, ?OtpService $mailer = null): array
    {
        if (!Plan::isLead($adminId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can send its letters.'];
        $event = Plan::event($eventId);
        if (!$event) return ['ok' => false, 'error' => 'That event is not there.'];
        $mailer ??= OtpService::boot();
        if (!$mailer->canSend()) return ['ok' => false, 'error' => 'Email is not set up yet, so nothing was sent. An administrator sets it in Settings › Email.'];
        $sent = $failed = 0;
        foreach (EventInvites::sendQueue($eventId) as $invite) {
            if ($sent + $failed >= 15) break;   // the staff page's batch: a shared host's relay drops a burst
            $r = InviteMailer::send($invite, $event, $mailer);
            if ($r['skipped'] ?? false) continue;
            ($r['ok'] ?? false) ? $sent++ : $failed++;
            usleep(250000);
        }
        return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'left' => count(EventInvites::sendQueue($eventId)), 'event' => $eventId];
    }

    /** The lead's approved message requests for this event, with what has gone. @return list<object> */
    public static function approvedMessages(int $eventId): array
    {
        if (!Requests::ready()) return [];
        $rows = DB::table('gates_requests')->where('scope_kind', 'event')->where('scope_id', $eventId)->where('type', 'message')
            ->where('status', 'approved')->orderByDesc('id')->get()->all();
        foreach ($rows as $r) $r->tally = BroadcastLog::tally(self::campaign((int) $r->id));
        return $rows;
    }

    /**
     * Send the next batch of an approved message to its audience.
     *
     * @param ?callable(string $to, string $subject, string $html, string $plain): bool $send for tests
     * @return array{ok:bool, error?:string, sent?:int, failed?:int, left?:int, event?:int}
     */
    public static function sendMessage(int $requestId, string $audience, string $subject, string $body, int $adminId, string $adminName, ?callable $send = null): array
    {
        $r = Requests::find($requestId);
        if (!$r || $r->type !== 'message' || $r->scope_kind !== 'event') return ['ok' => false, 'error' => 'That is not a message request for an event.'];
        $eventId = (int) $r->scope_id;
        if (!Plan::isLead($adminId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can send its messages.'];
        if ($r->status !== 'approved') return ['ok' => false, 'error' => 'A big send needs its request approved first.'];
        if (!isset(self::AUDIENCES[$audience])) return ['ok' => false, 'error' => 'Pick who it goes to.'];
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') return ['ok' => false, 'error' => 'Write the subject and the message.'];
        $event = Plan::event($eventId);
        if ($send === null) {
            $mailer = OtpService::boot();
            if (!$mailer->canSend()) return ['ok' => false, 'error' => 'Email is not set up yet, so nothing was sent.'];
            $send = static function (string $to, string $subj, string $html, string $plain) use ($mailer): bool {
                $res = $mailer->sendBranded($to, $subj, $html, $plain, 'event');
                return ($res['success'] ?? false) === true;
            };
        }
        $campaign = self::campaign($requestId);
        $html = '<p>' . nl2br(htmlspecialchars($body)) . '</p><p>' . htmlspecialchars((string) ($event->title ?? '')) . '</p>';
        $sent = $failed = 0;
        $all = self::recipients($eventId, $audience);
        foreach ($all as $email) {
            if ($sent + $failed >= self::BATCH) break;
            if (!BroadcastLog::claim($campaign, $email)) continue;   // already sent, or another press owns it
            $ok = $send($email, $subject, $html, $body);
            BroadcastLog::settle($campaign, $email, $ok);
            $ok ? $sent++ : $failed++;
        }
        $done = BroadcastLog::handled($campaign);
        $left = count(array_filter($all, static fn ($e) => !isset($done[\AfricaGates\Services\EmailOptOut::hash($e)])));
        Requests::recordSent($requestId, 'admin', $adminId, $adminName, $sent . ' sent' . ($failed ? ', ' . $failed . ' failed' : '') . ' to ' . strtolower(self::AUDIENCES[$audience]) . ': ' . $subject);
        return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'left' => $left, 'event' => $eventId];
    }

    /** @return list<string> distinct, valid addresses */
    public static function recipients(int $eventId, string $audience): array
    {
        $out = [];
        if (in_array($audience, ['tickets', 'both'], true)) {
            try {
                foreach (DB::table('gates_event_registrations')->where('event_id', $eventId)->where('status', 'confirmed')->pluck('email') as $e) $out[] = (string) $e;
            } catch (\Throwable) {
            }
        }
        if (in_array($audience, ['guests', 'both'], true)) {
            foreach (DB::table('gates_event_invites')->where('event_id', $eventId)->whereNotNull('sent_at')->where('reference', 'not like', 'AGI-SAMPLE%')->pluck('email') as $e) $out[] = (string) $e;
        }
        $seen = [];
        foreach ($out as $e) {
            $k = mb_strtolower(trim($e));
            if ($k !== '' && filter_var($k, FILTER_VALIDATE_EMAIL)) $seen[$k] = $k;
        }
        return array_values($seen);
    }

    private static function campaign(int $requestId): string
    {
        return 'lead-message-' . $requestId;
    }
}
