<?php
declare(strict_types=1);
namespace AfricaGates\Middleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * CSRF protection for mutating requests.
 *
 *  - OTP-gated API routes (/api/vote, /api/otp/request) are exempt: an attacker
 *    cannot supply the victim's emailed one-time code, so CSRF adds nothing.
 *  - Payment-gateway webhooks (/pay/webhook) are exempt: they are server-to-server
 *    POSTs that carry no browser session/cookie, so a synchronizer token is
 *    meaningless. They are instead authenticated by a per-provider cryptographic
 *    signature verified inside PaymentController (Paystack HMAC-SHA512 over the raw
 *    body; Flutterwave shared verif-hash). Signature > CSRF token here.
 *  - Other /api/ writes (register, nominations, community) take no CSRF token
 *    (they serve stateless JSON clients) but MUST be same-origin — this blocks
 *    cross-site forced submissions while leaving first-party fetches working
 *    (browsers always send Origin on a cross-site POST).
 *  - Browser form posts (including /pay/init) require the synchronizer CSRF token.
 */
class CsrfMiddleware {
    private const MUTATING   = ['POST','PUT','PATCH','DELETE'];
    // Exact-match paths exempt from CSRF: OTP-gated routes (emailed code is the
    // proof) and signature-verified payment webhooks (no session to protect).
    // Plus the token-gated no-SSH setup endpoint: it is authenticated by the secret
    // SETUP_TOKEN in the query (checked in the route), so a CSRF token adds nothing.
    // /api/agent/gee is server-to-server (Make.com agent → Gee): no Origin
    // header, bearer-key authenticated in the controller — CSRF doesn't apply.
    // The live-interview endpoints are exempt for the same reason /api/agent/gee is: the
    // caller is a browser EXTENSION's service worker, so its Origin is
    // chrome-extension://… and can never be same-origin, it carries no cookie and no
    // session, and it is authenticated by a per-sitting live token checked in
    // InterviewLive. A synchronizer token protects a session; there is no session here to
    // protect, and an attacker holding the live token has no need of CSRF.
    //
    // /email/unsubscribe is exempt for the same reason: the person clicking it arrived
    // from an inbox with no session and no cookie, so there is no token to issue them and
    // nothing for a synchroniser token to protect. The HMAC in the URL is the credential
    // (see EmailOptOut::verify), and the worst a forged POST achieves is unsubscribing an
    // address whose token the forger already had to possess. Refusing the POST instead
    // would mean the unsubscribe link in every bulk email does not work, which is the one
    // failure here with a legal dimension as well as a rudeness one.
    //
    // The admin session cookie is deliberately left SameSite=Lax rather than being
    // loosened to make these work — that would weaken every form in the console to buy
    // one feature.
    /**
     * The nominee's token-gated pages: `/my-work/<32 hex>` and its actions, and the
     * nominee's own interview page. Anchored end to end; every action named explicitly.
     */
    private const NOMINEE_TOKEN_PATHS =
        // `chat` is deliberately absent: the guided chat endpoint was retired with the
        // feature. Exempting a path that no longer routes anywhere is a comment that
        // reads as policy about a thing that does not exist.
        '~^(/my-work/[a-f0-9]{32}(/(upload|speak|listen|ready|coach|intro|summary'
        . '|interview(/(switch|resume|phase|amend|outcome))?))?'
        . '|/interview/[a-f0-9]{32})$~';

    private const OTP_EXEMPT = ['/api/vote', '/api/otp/request', '/api/v1/vote', '/api/v1/otp/request', '/api/agent/gee', '/api/v1/agent/gee', '/pay/webhook', '/__setup/admin',
        '/api/interview/live/hello', '/api/interview/live/say', '/api/interview/live/finish',
        '/api/v1/interview/live/hello', '/api/v1/interview/live/say', '/api/v1/interview/live/finish',
        // The recording bot's callback: server-to-server from the Attendee instance, no
        // cookie and no Origin, guarded by a shared secret compared in the controller.
        '/api/interview/bot/webhook', '/api/v1/interview/bot/webhook',
        '/email/unsubscribe'];

    public function __invoke(Request $req, Handler $handler): Response {
        if (!in_array($req->getMethod(), self::MUTATING, true)) {
            return $handler->handle($req);
        }

        $path = $req->getUri()->getPath();

        foreach (self::OTP_EXEMPT as $p) {
            if ($path === $p) {   // exact match only — never a suffix like /x/api/vote
                return $handler->handle($req);
            }
        }

        // ── THE DOOR ────────────────────────────────────────────────────────
        //
        // The one exemption that cannot be an exact string, because the credential is IN the
        // path. `/door/<64 hex>/check` is worked by volunteers with no account and no session,
        // so there is no CSRF token to hold and nothing a session-riding attack could take
        // over — the 32-byte token is the whole authority, and anybody who has it can already
        // call the endpoint directly.
        //
        // Anchored end to end, with the token's exact shape and length spelled out, so this
        // cannot be widened by a crafted path. `/check` is the only verb it covers: the page
        // itself is a GET and needs no exemption, and no other door route may ever ride this.
        if (preg_match('~^/door/[a-f0-9]{64}/check$~', $path) === 1) {
            return $handler->handle($req);
        }

        // ── THE NOMINEE'S OWN PAGES ─────────────────────────────────────────
        //
        // Same reasoning as the door, and the same shape: the 32-hex token in the path IS
        // the credential. A nominee has no account and no session — somebody else put their
        // name forward — so there is nothing for a session-riding attack to take over, and
        // anybody holding the token can already POST here directly.
        //
        // ── AND WITHOUT THIS THE FORM IS UNUSABLE FOR ITS OWN AUDIENCE ──────
        //
        // The session cookie is set to seven days, but PHP's SERVER-SIDE
        // `session.gc_maxlifetime` defaults to 1440 seconds and shared hosts leave it
        // there. So the cookie survives and the session DATA does not: after about
        // twenty-four idle minutes `$_SESSION` is empty, `public/index.php` mints a fresh
        // `csrf_token`, and the token already rendered into the nominee's form stops
        // matching. The POST is refused with "CSRF validation failed."
        //
        // That lands on exactly the people this form was written for.
        // `QuestionnaireService::saveDraft()` says it plainly: "the population here is
        // filling this in on a phone, between other work, over several days." Every one of
        // those pauses was a broken save, and the failure reads to a nominee as the site
        // rejecting their life's work for no stated reason.
        //
        // Verbs are enumerated rather than wildcarded, and the pattern is anchored end to
        // end with the token's exact shape, so this cannot be widened by a crafted path.
        if (preg_match(self::NOMINEE_TOKEN_PATHS, $path) === 1) {
            return $handler->handle($req);
        }

        if (str_contains($path, '/api/')) {
            return $this->sameOrigin($req)
                ? $handler->handle($req)
                : $this->deny('Cross-origin request blocked.');
        }

        // A MISSING SESSION TOKEN IS A REFUSAL, NOT A COMPARISON.
        //
        // `hash_equals('', '')` is TRUE, so without this an empty session and an absent
        // `_token` matched each other and the request sailed through. `public/index.php`
        // mints a token on every request, so the app never reaches that state in
        // production — but a middleware whose safety depends on another file having run
        // first is one refactor away from being a hole, and it fails OPEN, which is the
        // wrong direction.
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($expected === '') {
            return $this->deny('CSRF validation failed.');
        }

        $token = $req->getHeaderLine('X-CSRF-Token')
            ?: (((array)$req->getParsedBody())['_token'] ?? '');
        if (!hash_equals($expected, $token)) {
            return $this->deny('CSRF validation failed.');
        }
        return $handler->handle($req);
    }

    /**
     * True only if the request's Origin/Referer host matches the host the
     * request targets, OR if neither header is present (browsers always attach
     * Origin on cross-origin fetches, so its absence implies same-origin).
     * Also accepts requests that include X-Requested-With: XMLHttpRequest,
     * which cannot be set by a cross-origin form submission.
     */
    private function sameOrigin(Request $req): bool {
        // X-Requested-With header cannot be forged by a cross-origin form post
        if ($req->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        $host = $req->getUri()->getHost();

        foreach (['Origin', 'Referer'] as $h) {
            $v = $req->getHeaderLine($h);
            if ($v !== '') {
                // 'null' origin (sandboxed iframe) — treat as untrusted
                if (strtolower($v) === 'null') return false;
                $parsed = parse_url($v, PHP_URL_HOST);
                if ($parsed === false || $parsed === null) return false;
                return $host === '' || strcasecmp((string)$parsed, $host) === 0;
            }
        }

        // Neither Origin nor Referer present on a state-changing /api/ write: fail
        // CLOSED. A first-party browser fetch sends Origin on same-origin POSTs (and
        // may set X-Requested-With, handled above); only non-browser clients arrive
        // bare, and those should be challenged rather than trusted.
        return false;
    }

    private function deny(string $msg): Response {
        $res = new \Slim\Psr7\Response(403);
        $res->getBody()->write(json_encode(['success'=>false,'message'=>$msg]));
        return $res->withHeader('Content-Type','application/json');
    }
}
