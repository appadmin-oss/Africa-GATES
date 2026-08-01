<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Outbound webhooks — lets Africa GATES notify external platforms and AI agents
 * when something happens (an RSVP, a paid order, an approved nominee…). Static,
 * like {@see Notifier}, so any code path can fire an event without DI.
 *
 * Each delivery is a JSON POST signed with the endpoint's secret using
 * HMAC-SHA256 (header `X-AG-Signature: sha256=<hex>`), so receivers can verify
 * authenticity. Delivery is best-effort with short timeouts and is wrapped so a
 * slow or failing endpoint can NEVER break the user-facing action that fired it.
 * Every attempt is logged to gates_webhook_deliveries for an audit trail.
 */
class WebhookService
{
    /** Canonical event types an endpoint can subscribe to (key => human label). */
    public const EVENTS = [
        // Nominations
        'nomination.submitted'      => 'A new nomination is submitted (pending review)',
        'nomination.approved'       => 'A nomination is approved (a nominee goes live)',
        'nomination.rejected'       => 'A nomination is rejected by moderation',
        'share_link.created'        => 'A nominator creates a shareable prefill link',
        'share_link.used'           => 'Someone opens a shared nomination link',
        // Voting
        'vote.cast'                 => 'A vote is cast (post-commit; voter identity stays hashed)',
        'vote.paid'                 => 'Paid votes are minted for a nominee (confirmed order)',
        'points.redeemed'           => 'A member redeems voting points for a vote',
        // People
        'member.registered'         => 'A member account is created',
        'member.verified'           => 'A member verifies their email',
        'profile.registered'        => 'A public registry profile is registered',
        // Money
        'order.paid'                => 'A shop order is paid',
        'donation.confirmed'        => 'A donation payment is confirmed',
        // Money going back OUT. Worth a subscription of its own: this is the only
        // event on the platform that means cash has left the account, and it can
        // fire without any human involvement — see AfricaGates\Services\RefundService.
        'payment.refunded'          => 'A payment was refunded (votes could not be counted)',
        // Community & moderation
        'community.thread_created'  => 'A community thread is created',
        'community.comment_posted'  => 'A community comment/reply is posted',
        'moderation.flagged'        => 'Content is quarantined or rejected by AI moderation',
        'moderation.actioned'       => 'An operator approves/removes quarantined content',
        // Platform
        'cycle.status_changed'      => 'An award cycle changes status (nominations/voting/results…)',
        'event.registration'        => 'Someone registers (RSVPs) for an event',
        'partner.enquiry'           => 'A partnership enquiry is submitted',
        // Support. `support.escalated` is the one to wire to a pager or an SMS
        // gateway: it fires when the assistant has decided a human is needed,
        // which is the only support event that is time-sensitive.
        'support.escalated'         => 'The support assistant escalated a conversation to a human',
        'support.ticket_opened'     => 'A support ticket is opened',
        'support.ticket_replied'    => 'Someone replies on a support ticket',
        'ping'                      => 'Test event (sent from the admin console)',
    ];

    /** Fire an event to every active webhook subscribed to it. Never throws. */
    public static function dispatch(string $event, array $data): void
    {
        try {
            $hooks = DB::table('gates_webhooks')->where('is_active', 1)->get();
        } catch (\Throwable $e) {
            return; // table missing / DB unavailable — must never break the caller
        }
        foreach ($hooks as $hook) {
            $subs = self::subscribed($hook->events ?? '*');
            if ($subs !== ['*'] && !in_array($event, $subs, true)) {
                continue;
            }
            self::deliver($hook, $event, $data);
        }
    }

    /** Send a one-off test 'ping' to a single endpoint (admin "Send test event"). */
    public static function ping(int $webhookId): array
    {
        $hook = DB::table('gates_webhooks')->where('id', $webhookId)->first();
        if (!$hook) {
            return ['ok' => false, 'status' => null, 'error' => 'Webhook not found'];
        }
        return self::deliver($hook, 'ping', ['message' => 'Test event from the Africa GATES admin console.']);
    }

    /** Subscribed event keys for a stored value (JSON array, CSV, or '*' = all). */
    private static function subscribed(?string $events): array
    {
        $events = trim((string) $events);
        if ($events === '' || $events === '*') {
            return ['*'];
        }
        $arr = json_decode($events, true);
        if (is_array($arr)) {
            return $arr;
        }
        return array_values(array_filter(array_map('trim', explode(',', $events))));
    }

    /** Sign + POST + log one delivery. Returns ['ok'=>bool,'status'=>?int,'error'=>?string]. */
    private static function deliver(object $hook, string $event, array $data): array
    {
        $payload = [
            'id'      => bin2hex(random_bytes(12)),
            'event'   => $event,
            'created' => time(),
            'data'    => $data,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sig  = hash_hmac('sha256', (string) $body, (string) $hook->secret);

        $status = null; $ok = false; $err = null;
        try {
            $ch = curl_init((string) $hook->url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'User-Agent: AfricaGates-Webhooks/1',
                    'X-AG-Event: ' . $event,
                    'X-AG-Delivery: ' . $payload['id'],
                    'X-AG-Signature: sha256=' . $sig,
                ],
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch) ?: 'request failed';
            } else {
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $ok = $status >= 200 && $status < 300;
                if (!$ok) {
                    $err = 'HTTP ' . $status;
                }
            }
            // curl_close() is a deprecated no-op since PHP 8.0 — the CurlHandle is
            // released on scope exit.
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }

        try {
            DB::table('gates_webhook_deliveries')->insert([
                'webhook_id'  => (int) $hook->id,
                'event'       => $event,
                'status_code' => $status,
                'ok'          => $ok ? 1 : 0,
                'error'       => $err !== null ? mb_substr($err, 0, 300) : null,
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
            DB::table('gates_webhooks')->where('id', $hook->id)->update([
                'last_status'   => $status,
                'last_event_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) { /* logging is best-effort */ }

        return ['ok' => $ok, 'status' => $status, 'error' => $err];
    }
}
