<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\InterviewBot;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Attendee's callback, and the reason almost nothing depends on it.
 *
 * ── WHAT THIS BUYS, AND WHAT IT DOES NOT ─────────────────────────────────────
 *
 * {@see InterviewBot} polls. Every piece of state — the bot's progress, the transcript,
 * the recording — is recoverable by asking, on the cron tick that already runs, and that
 * is the path an operator can reason about on a cPanel host whose hostname has changed
 * once already.
 *
 * This endpoint exists for ONE thing: latency in 'auto' mode. On the polling path the
 * nominee finishes speaking and the bot's next question arrives up to a cron interval
 * later, which is a conversation with minute-long pauses. With the callback it is a few
 * seconds. That is the difference between usable and a demo.
 *
 * So: nice to have, never load-bearing. If this route is unreachable, every interview
 * still produces a transcript and 'assisted' mode is unaffected — 'auto' just gets slow.
 * {@see InterviewBot::webhookUrl()} declines to register a callback at all unless a shared
 * secret is configured over HTTPS, which is why the degraded path had to be the real one.
 *
 * ── WHY THE SECRET IS COMPARED, NOT TRUSTED ──────────────────────────────────
 *
 * This endpoint appends to a judging transcript. A stranger who can POST here can put
 * words in a nominee's mouth in a document a panel uses to decide an award, which is a
 * more consequential forgery than anything else unauthenticated on this platform.
 *
 * Attendee signs its webhooks, but the signing scheme has moved between versions and a
 * self-hosted instance may be on either side of that change. Verifying a signature this
 * codebase cannot be sure of is worse than not claiming to: it reads as authentication
 * while failing open. So the credential is a shared secret this platform generated,
 * carried in a header, compared in constant time — the same shape as the GAS_SECRET that
 * guards {@see \AfricaGates\Services\GoogleMeetService}, and for the same reason.
 *
 * ── AND IT NEVER TRUSTS THE PAYLOAD'S CONTENTS ───────────────────────────────
 *
 * The body names a bot. It is used to LOOK UP a sitting and for nothing else — no
 * transcript text is read from it, no state is written from it. Everything is then
 * re-fetched from Attendee over an authenticated connection by the same code the cron
 * calls. A forged body with the right secret can therefore cause an early poll of a real
 * bot, and nothing else.
 */
final class InterviewBotController
{
    /** The header Attendee is configured to send. */
    private const HEADER = 'X-Attendee-Secret';

    public function webhook(Request $req, Response $res): Response
    {
        $secret = trim((string) Env::get('ATTENDEE_WEBHOOK_SECRET', ''));
        if ($secret === '') {
            // Not configured is not the same as wrong. 404 rather than 401, so a scanner
            // learns nothing about whether this platform has the feature at all.
            return $this->json($res, ['ok' => false, 'error' => 'Not enabled.'], 404);
        }

        $sent = trim($req->getHeaderLine(self::HEADER));
        if ($sent === '' || !hash_equals($secret, $sent)) {
            return $this->json($res, ['ok' => false, 'error' => 'Unauthorised.'], 401);
        }

        // ── the cap, AFTER the secret and BEFORE the work ────────────────────
        //
        // A valid caller here triggers InterviewBot::poll(), which makes two or three
        // outbound HTTPS calls to the Attendee instance. That is an amplifier: a client
        // holding the secret and looping can spend this platform's whole PHP-FPM pool on
        // waiting for someone else's API.
        //
        // Attendee retries a failed delivery, and a genuine interview produces a callback
        // every few seconds at most, so 120 a minute is far above anything real and far
        // below anything that hurts. Keyed on the endpoint rather than the client IP:
        // there is exactly one legitimate caller, so a per-IP key would just let an
        // attacker rotate addresses.
        if (!(new RateLimitService())->check('attendee-webhook', 'interview_bot_webhook', 120, 60)) {
            return $this->json($res, ['ok' => false, 'error' => 'Too many requests.'], 429);
        }

        $body  = $this->body($req);
        $botId = trim((string) (
            $body['bot_id']
            ?? $body['data']['bot_id']
            ?? $body['bot']['id']
            ?? $body['id']
            ?? ''
        ));
        if ($botId === '' || !preg_match('/^[A-Za-z0-9_\-]{4,120}$/', $botId)) {
            return $this->json($res, ['ok' => false, 'error' => 'No usable bot id.'], 400);
        }

        // The lookup is the authorisation for the sitting: a bot id this platform never
        // dispatched belongs to no interview here and gets nothing.
        try {
            $id = (int) DB::table('gates_interviews')->where('bot_id', $botId)->value('id');
        } catch (\Throwable) {
            $id = 0;
        }
        if ($id <= 0) {
            // 200, deliberately. A provider that receives an error retries, and retrying a
            // bot this platform does not know about will never succeed.
            return $this->json($res, ['ok' => true, 'note' => 'No sitting for that bot.'], 200);
        }

        // Everything real happens in the same call the cron makes, against the provider's
        // API rather than against this request body.
        try {
            $out = InterviewBot::poll($id);
        } catch (\Throwable $e) {
            error_log('[interview-bot] webhook poll ' . $id . ': ' . $e->getMessage());
            return $this->json($res, ['ok' => false, 'error' => 'Could not refresh the sitting.'], 500);
        }

        return $this->json($res, [
            'ok'       => true,
            'state'    => $out['state'],
            'ingested' => $out['ingested'],
        ], 200);
    }

    /** @return array<string,mixed> */
    private function body(Request $req): array
    {
        $parsed = $req->getParsedBody();
        if (is_array($parsed) && $parsed !== []) return $parsed;

        $raw = (string) $req->getBody();
        if ($raw === '' || strlen($raw) > 262144) return [];
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    /** @param array<string,mixed> $data */
    private function json(Response $res, array $data, int $status): Response
    {
        $res->getBody()->write((string) json_encode($data));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
