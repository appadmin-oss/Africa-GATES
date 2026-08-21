<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\InterviewBot;
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
 * ── TWO CREDENTIALS, EITHER OF WHICH IS ENOUGH ───────────────────────────────
 *
 * This endpoint appends to a judging transcript. A stranger who can POST here can put
 * words in a nominee's mouth in a document a panel uses to decide an award, which is a
 * more consequential forgery than anything else unauthenticated on this platform.
 *
 * Attendee's real scheme is now pinned rather than guessed at. It sends
 * `X-Webhook-Signature`: base64(HMAC-SHA256(canonical JSON, secret)), where the secret
 * is the project's webhook secret from Settings → Webhooks — base64 in the dashboard,
 * raw bytes in the HMAC — and canonical JSON means keys sorted at every level, no
 * separator spaces, slashes and non-ASCII left unescaped. That is ATTENDEE_WEBHOOK_
 * SIGNING_SECRET, and it is the credential to prefer.
 *
 * ONE HONEST LIMIT. The signature is computed over a re-serialisation of the payload,
 * not over the bytes on the wire — Attendee posts with `requests(json=...)`, whose
 * spacing and key order differ from what it signed, so the raw body cannot be hashed
 * directly. PHP and Python agree on every payload shape this endpoint has been tested
 * against (unicode, escaped slashes, empty objects, nested sorting, big integers,
 * nulls) but NOT on floats: Python writes 1.0 where PHP writes 1. Attendee's own
 * payload fields are integers, though a transcription provider's blob is passed through
 * verbatim and could contain one. Such a delivery fails the check and is rejected,
 * which costs the latency this endpoint exists to save and nothing else — the cron
 * re-fetches the same transcript minutes later. Failing closed on an unverifiable
 * request is the correct end of that trade.
 *
 * The shared-secret header is kept as the second path, for a deployment that injects
 * one at a reverse proxy — the same shape as the GAS_SECRET guarding
 * {@see \AfricaGates\Services\GoogleMeetService}. Either credential passing is enough;
 * with neither configured the route reports itself absent.
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
    /** A shared secret injected by whatever fronts this host, if one is. */
    private const HEADER = 'X-Attendee-Secret';

    /** Attendee's own signature over the canonical payload. */
    private const SIG_HEADER = 'X-Webhook-Signature';

    public function webhook(Request $req, Response $res): Response
    {
        $signing = trim((string) Env::get('ATTENDEE_WEBHOOK_SIGNING_SECRET', ''));
        $shared  = trim((string) Env::get('ATTENDEE_WEBHOOK_SECRET', ''));
        if ($signing === '' && $shared === '') {
            // Not configured is not the same as wrong. 404 rather than 401, so a scanner
            // learns nothing about whether this platform has the feature at all.
            return $this->json($res, ['ok' => false, 'error' => 'Not enabled.'], 404);
        }

        // Read the body ONCE. A PSR-7 stream that has been cast to string is at its end,
        // and reading it again yields '' — which would look like an empty payload rather
        // than a spent stream.
        $raw = $this->raw($req);

        $authed = false;
        if ($signing !== '') {
            $authed = self::signatureOk($raw, $req->getHeaderLine(self::SIG_HEADER), $signing);
        }
        if (!$authed && $shared !== '') {
            $sent   = trim($req->getHeaderLine(self::HEADER));
            $authed = $sent !== '' && hash_equals($shared, $sent);
        }
        if (!$authed) {
            return $this->json($res, ['ok' => false, 'error' => 'Unauthorised.'], 401);
        }

        $body  = $this->body($req, $raw);
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
    private function body(Request $req, ?string $raw = null): array
    {
        $parsed = $req->getParsedBody();
        if (is_array($parsed) && $parsed !== []) return $parsed;

        $raw ??= $this->raw($req);
        if ($raw === '' || strlen($raw) > 262144) return [];
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    /** The request body as sent, rewinding first so a second reader is not handed ''. */
    private function raw(Request $req): string
    {
        $stream = $req->getBody();
        if ($stream->isSeekable()) $stream->rewind();
        return (string) $stream;
    }

    /**
     * Does `$signature` match what Attendee would have produced for this body?
     *
     * Mirrors bots/webhook_utils.py::sign_payload — keys sorted at every level, no
     * separator spaces, slashes and non-ASCII unescaped, HMAC-SHA256 over the raw
     * secret bytes, result base64'd. The dashboard shows that secret base64-encoded,
     * so it is decoded before use; a value that is not valid base64 is treated as
     * misconfiguration and fails rather than being hashed as text.
     */
    private static function signatureOk(string $raw, string $signature, string $secretB64): bool
    {
        $signature = trim($signature);
        if ($signature === '' || $raw === '' || strlen($raw) > 262144) return false;

        $secret = base64_decode($secretB64, true);
        if ($secret === false || $secret === '') return false;

        $canonical = self::canonicalJson($raw);
        if ($canonical === null) return false;

        $expected = base64_encode(hash_hmac('sha256', $canonical, $secret, true));
        return hash_equals($expected, $signature);
    }

    /** Python's json.dumps(sort_keys=True, ensure_ascii=False, separators=(',',':')). */
    private static function canonicalJson(string $raw): ?string
    {
        // Decoded to objects, NOT arrays: json_decode(assoc) turns {} into [], which
        // re-encodes as [] and changes the bytes being signed.
        $decoded = json_decode($raw, false);
        if ($decoded === null && strtolower(trim($raw)) !== 'null') return null;

        $out = json_encode(self::sortDeep($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $out === false ? null : $out;
    }

    private static function sortDeep(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $a = (array) $v;
            ksort($a, SORT_STRING);
            $o = new \stdClass();
            foreach ($a as $k => $vv) $o->{$k} = self::sortDeep($vv);
            return $o;
        }
        if (is_array($v)) return array_map([self::class, 'sortDeep'], $v);
        return $v;
    }

    /** @param array<string,mixed> $data */
    private function json(Response $res, array $data, int $status): Response
    {
        $res->getBody()->write((string) json_encode($data));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
