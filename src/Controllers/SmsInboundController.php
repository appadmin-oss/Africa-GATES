<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\SmsOptOut;
use AfricaGates\Services\SmsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Somebody replying STOP, and the platform actually stopping.
 *
 * ── WHY THIS HAD TO EXIST BEFORE THE CHECK-IN TEXT COULD SHIP ────────────────
 *
 * {@see \AfricaGates\Services\CheckInThanks} ends every message with "Reply STOP to end
 * texts." Without this endpoint that sentence is a promise nothing keeps — §18 of the
 * codebase index, a mechanism with no route in, and the worst possible version of it
 * because the promise is made to the public in writing.
 *
 * Twilio does honour STOP at its own level and will refuse later sends to that number
 * with error 21610. So the RECIPIENT is protected either way. What breaks without this is
 * the platform's own knowledge: it keeps queueing jobs for a number that can never be
 * delivered to, forever, and every one of them fails and retries.
 *
 * ── ALWAYS 204, WHATEVER HAPPENS ─────────────────────────────────────────────
 *
 * Twilio retries a webhook that errors and disables one that keeps erroring. There is
 * nothing a 500 here could usefully tell it — the sender is a carrier, not a person — so
 * every path answers 204 and anything worth knowing goes to the log. A rejected signature
 * answers 204 as well, deliberately: a distinguishable rejection is an oracle telling an
 * attacker when they have got the URL right.
 */
final class SmsInboundController
{
    /**
     * The words a carrier and a person actually use.
     *
     * Twilio's own list plus the ones people type instead. Matched on the FIRST word only
     * — "stop sending me these please" is a stop, and "please do not stop inviting me" is
     * not, which is exactly the sentence a whole-body `str_contains` gets wrong.
     */
    private const STOP_WORDS = ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit', 'stopp'];

    /** The words that undo it. Twilio uses START and UNSTOP; people write both. */
    private const START_WORDS = ['start', 'unstop', 'yes', 'subscribe'];

    public function receive(Request $req, Response $res): Response
    {
        $done = static fn (): Response => $res->withStatus(204)
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');

        $params = (array) $req->getParsedBody();
        $sms    = SmsService::boot();

        // No token means no way to tell a carrier from anybody else. Refusing outright is
        // the only safe answer: an endpoint that records opt-outs from unverified POSTs is
        // a way to silence somebody else's security alerts.
        if (!$sms->canValidateWebhook()) {
            error_log('[sms-inbound] refused: no Twilio auth token configured to verify against');
            return $done();
        }

        $signature = $req->getHeaderLine('X-Twilio-Signature');
        if (!$sms->validateWebhook(self::url($req), $params, $signature)) {
            error_log('[sms-inbound] refused: signature did not verify');
            return $done();
        }

        $from = trim((string) ($params['From'] ?? ''));
        $word = strtolower(trim((string) ($params['Body'] ?? '')));
        // First word only, punctuation off. "STOP." and "Stop!" are stops.
        $word = trim((string) (preg_split('/\s+/', $word)[0] ?? ''), ".,!?;:'\"");

        if ($from === '' || $word === '') return $done();

        if (in_array($word, self::STOP_WORDS, true)) {
            SmsOptOut::record($from, 'stop-reply');
        } elseif (in_array($word, self::START_WORDS, true)) {
            // The other direction matters as much. Somebody who stopped and then asked to
            // start again has asked twice to be listened to, and a one-way list makes the
            // second request impossible to honour without a shell.
            SmsOptOut::remove($from);
        }

        return $done();
    }

    /**
     * The absolute URL Twilio signed.
     *
     * Rebuilt from the request rather than from SiteUrl: the signature covers the exact
     * string configured in the Twilio console, and a host or scheme that disagrees by one
     * character rejects every genuine request. Behind a TLS-terminating proxy the scheme
     * on the request is `http`, which is the commonest reason a correct implementation
     * of this rejects everything — so `X-Forwarded-Proto` wins when it is present.
     */
    private static function url(Request $req): string
    {
        $uri   = $req->getUri();
        $proto = trim(explode(',', $req->getHeaderLine('X-Forwarded-Proto'))[0] ?? '');
        if ($proto !== '') $uri = $uri->withScheme($proto);

        return (string) $uri;
    }
}
