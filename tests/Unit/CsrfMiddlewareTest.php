<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Middleware\CsrfMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Message\ResponseInterface as Res;

class CsrfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_URL'] = 'https://afg.local';
        $_SESSION['csrf_token'] = 'sessiontoken';
    }

    private function handler(): Handler
    {
        return new class implements Handler {
            public function handle(Req $r): Res { return new Response(200); }
        };
    }

    private function req(string $method, string $path, array $headers = [], array $body = []): Req
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, 'https://afg.local' . $path);
        foreach ($headers as $k => $v) { $r = $r->withHeader($k, $v); }
        if ($body) { $r = $r->withParsedBody($body); }
        return $r;
    }

    public function test_get_passes_through(): void
    {
        $res = (new CsrfMiddleware())($this->req('GET', '/anything'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_otp_gated_api_route_is_exempt(): void
    {
        $res = (new CsrfMiddleware())($this->req('POST', '/api/vote'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_other_api_route_blocks_cross_origin(): void
    {
        $res = (new CsrfMiddleware())(
            $this->req('POST', '/api/register', ['Origin' => 'https://evil.example']),
            $this->handler()
        );
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_other_api_route_allows_same_origin(): void
    {
        $res = (new CsrfMiddleware())(
            $this->req('POST', '/api/register', ['Origin' => 'https://afg.local']),
            $this->handler()
        );
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_non_api_post_requires_csrf_token(): void
    {
        $bad = (new CsrfMiddleware())($this->req('POST', '/nominate', [], ['_token' => 'wrong']), $this->handler());
        $this->assertSame(403, $bad->getStatusCode());

        $ok = (new CsrfMiddleware())($this->req('POST', '/nominate', [], ['_token' => 'sessiontoken']), $this->handler());
        $this->assertSame(200, $ok->getStatusCode());
    }

    public function test_payment_webhook_is_csrf_exempt(): void
    {
        // Server-to-server, signature-verified inside the controller — no token.
        $res = (new CsrfMiddleware())($this->req('POST', '/pay/webhook'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    // ══ the nominee's own pages ══════════════════════════════════════════════

    /**
     * ── THE BUG THIS CLOSES ──────────────────────────────────────────────────
     *
     * The session cookie is set to seven days, but PHP's server-side
     * `session.gc_maxlifetime` defaults to 1440 seconds and shared hosts leave it there.
     * So the cookie survives and the session DATA does not: after about twenty-four idle
     * minutes `$_SESSION` is empty, `public/index.php` mints a fresh `csrf_token`, and the
     * token already rendered into the nominee's form stops matching. "CSRF validation
     * failed."
     *
     * That lands on exactly the people the form was written for —
     * `QuestionnaireService::saveDraft()`: "the population here is filling this in on a
     * phone, between other work, over several days." Each of those pauses was a refused
     * save, reported to a nominee as the site rejecting their life's work for no reason.
     *
     * The exemption is sound for the same reason `/door/<64 hex>/check` is: the token in
     * the path is the whole credential, a nominee has no account and no session for an
     * attack to ride, and anybody holding the token can already POST here directly.
     */
    public function test_the_nominee_questionnaire_saves_without_a_session(): void
    {
        $t = str_repeat('a', 32);
        // No $_SESSION['csrf_token'] at all — the session PHP collected.
        unset($_SESSION['csrf_token']);

        // `/chat` is absent: the guided-chat endpoint was retired with the feature.
        // `/summary` and `/interview/resume` are present and were missing — both are posted
        // from the same page by the same tokenless nominee, so both were being refused
        // after the same idle pause this exemption exists for.
        foreach (['', '/upload', '/speak', '/listen', '/ready', '/coach', '/intro', '/summary',
                  '/interview', '/interview/switch', '/interview/resume', '/interview/phase',
                  '/interview/amend', '/interview/outcome'] as $verb) {
            $res = (new CsrfMiddleware())($this->req('POST', '/my-work/' . $t . $verb), $this->handler());
            $this->assertSame(200, $res->getStatusCode(),
                "/my-work/{token}{$verb} was refused — a nominee cannot save their work");
        }
    }

    public function test_the_nominees_own_interview_page_posts_without_a_session(): void
    {
        unset($_SESSION['csrf_token']);

        $res = (new CsrfMiddleware())($this->req('POST', '/interview/' . str_repeat('b', 32)), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    /**
     * The exemption is anchored end to end with the token's exact shape, so it cannot be
     * widened by a crafted path — the same discipline the door exemption uses. Each of
     * these must still be challenged.
     */
    public function test_the_exemption_cannot_be_widened_by_a_crafted_path(): void
    {
        unset($_SESSION['csrf_token']);
        $t = str_repeat('a', 32);

        foreach ([
            '/my-work/' . $t . '/evil',            // an action that is not on the list
            '/my-work/' . $t . '/../../admin',     // traversal
            '/x/my-work/' . $t,                    // a prefix
            '/my-work/' . $t . 'a',                // 33 characters
            '/my-work/short',                      // not a token
            '/interview/' . $t . '/extra',         // a suffix on the interview page
            '/admin/interviews/1/bot/send',        // an admin route that merely looks similar
        ] as $path) {
            $res = (new CsrfMiddleware())($this->req('POST', $path), $this->handler());
            $this->assertSame(403, $res->getStatusCode(), "{$path} slipped through the exemption");
        }
    }

    public function test_payment_init_still_requires_csrf_token(): void
    {
        // First-party form post must carry the synchronizer token.
        $bad = (new CsrfMiddleware())($this->req('POST', '/pay/init', [], ['_token' => 'wrong']), $this->handler());
        $this->assertSame(403, $bad->getStatusCode());

        $ok = (new CsrfMiddleware())($this->req('POST', '/pay/init', [], ['_token' => 'sessiontoken']), $this->handler());
        $this->assertSame(200, $ok->getStatusCode());
    }
}
