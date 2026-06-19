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
    private const OTP_EXEMPT = ['/api/vote', '/api/otp/request', '/pay/webhook'];

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

        if (str_contains($path, '/api/')) {
            return $this->sameOrigin($req)
                ? $handler->handle($req)
                : $this->deny('Cross-origin request blocked.');
        }

        $token = $req->getHeaderLine('X-CSRF-Token')
            ?: (((array)$req->getParsedBody())['_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
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
