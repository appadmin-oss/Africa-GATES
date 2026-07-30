<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Middleware\SecurityHeadersMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The response headers, and the two places that have to agree about them.
 *
 * A production `curl -I` showed three caching headers that contradicted each other:
 *
 *     Expires: Thu, 19 Nov 1981 08:52:00 GMT     PHP session cache limiter
 *     Pragma: no-cache                           PHP session cache limiter
 *     Cache-Control: private                     this middleware
 *
 * `Pragma` and a 1981 `Expires` say never store this; `private` says a browser may.
 * Which one wins depends on how old the intermediary is, which is not a policy.
 *
 * It was made worse by the fix that preceded it. `session.cache_limiter` defaults to
 * `nocache`, so `session_start()` had already sent
 * `Cache-Control: no-store, no-cache, must-revalidate` — correct for a page carrying
 * a CSP nonce. That goes out via `header()` and is invisible to a PSR-7 response, so
 * the `!hasHeader('Cache-Control')` guard was true on EVERY page, not just the
 * session-less ones it was written for. Slim's emitter replaced the strong policy
 * with the weaker `private` and orphaned the two HTTP/1.0 relics.
 *
 * The other half of this file is the .htaccess agreement. Both files are required:
 * Apache serves real files under public/ without touching PHP, so only .htaccess
 * covers a stylesheet or an upload; and .htaccess is not read at all on nginx. But
 * `Header always set` REPLACES, so where both apply the middleware's value is
 * discarded — the code a developer reads is not the header a visitor receives. The
 * two were independent copies with nothing checking them.
 */
class SecurityHeadersTest extends TestCase
{
    private function respond(?string $contentType = 'text/html', ?string $cacheControl = null): ResponseInterface
    {
        $handler = new class ($contentType, $cacheControl) implements RequestHandlerInterface {
            public function __construct(private readonly ?string $type, private readonly ?string $cc) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $r = new Response();
                if ($this->type !== null) $r = $r->withHeader('Content-Type', $this->type);
                if ($this->cc !== null)   $r = $r->withHeader('Cache-Control', $this->cc);
                return $r;
            }
        };
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/awards');
        return (new SecurityHeadersMiddleware())($req, $handler);
    }

    // ── The caching policy ───────────────────────────────────────────────────

    public function test_html_gets_exactly_one_coherent_caching_directive(): void
    {
        $res = $this->respond();

        $this->assertSame('private, no-cache, must-revalidate', $res->getHeaderLine('Cache-Control'));
        // `private` keeps a nonce-bearing page out of every SHARED cache, which is the
        // actual requirement: a CDN or corporate appliance holding one hands an
        // attacker a nonce the page keeps accepting.
        $this->assertStringContainsString('private', $res->getHeaderLine('Cache-Control'));
    }

    public function test_no_store_is_deliberately_not_used(): void
    {
        // `no-store` adds nothing against a shared cache that `private` does not
        // already forbid, and it disables the back/forward cache — an instant
        // back-navigation becomes a full round trip. On a slow mobile connection that
        // round trip is the expensive part, so the cost is real and the benefit nil.
        $this->assertStringNotContainsString('no-store', $this->respond()->getHeaderLine('Cache-Control'));
    }

    public function test_the_http_1_0_relics_are_not_emitted(): void
    {
        // Any cache written this century ignores Pragma and Expires when
        // Cache-Control is present, so sending them can only create the contradiction
        // that was observed in production.
        $res = $this->respond();
        $this->assertFalse($res->hasHeader('Pragma'));
        $this->assertFalse($res->hasHeader('Expires'));
    }

    public function test_php_is_told_not_to_send_its_own_caching_headers(): void
    {
        // The mechanism that makes the middleware authoritative. Without it the
        // session limiter's Expires and Pragma survive whatever the middleware does,
        // because they are already on the wire before any PSR-7 object exists.
        $this->assertTrue(method_exists(\AfricaGates\Support\Http::class, 'disableSessionCacheLimiter'));

        // Comments stripped first. The call site EXPLAINS itself with "Before
        // session_start(), or PHP sends its own …", so scanning raw text found
        // `session_start(` in the very comment that documents the ordering and
        // reported the order as wrong. Only executable code can be out of order.
        $bootstrap = (string) preg_replace(
            ['~/\*.*?\*/~s', '~//[^\n]*~'],
            '',
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php')
        );
        $disable = strpos($bootstrap, 'disableSessionCacheLimiter');
        $start   = strpos($bootstrap, 'session_start(');
        $this->assertNotFalse($disable, 'index.php must disable the session cache limiter');
        $this->assertNotFalse($start);
        $this->assertLessThan($start, $disable,
            'it must run BEFORE session_start() — afterwards the headers are already sent');
    }

    public function test_a_route_that_sets_its_own_policy_is_left_alone(): void
    {
        // The JSON and setup routes send no-store, and MediaController sends
        // far-future caching for an immutable asset. Overriding either would be a
        // regression dressed as a fix.
        $this->assertSame('no-store', $this->respond('application/json', 'no-store')->getHeaderLine('Cache-Control'));
        $this->assertSame('public, max-age=31536000, immutable',
            $this->respond('image/webp', 'public, max-age=31536000, immutable')->getHeaderLine('Cache-Control'));
    }

    public function test_a_route_that_sets_its_own_csp_is_not_overridden(): void
    {
        // `withHeader` REPLACES, so the unconditional site policy was discarding a
        // deliberately tighter one. Found on the flier's SVG endpoint, which sends
        // `default-src 'none'; … sandbox` because an SVG is a document the browser
        // EXECUTES and its text contains a public-submitted nominee name. The site
        // policy that replaced it permits `script-src 'self' 'nonce-…'`, so the
        // hardening was inert while reading in the code as present.
        $own = "default-src 'none'; img-src 'self'; sandbox";
        $handler = new class ($own) implements RequestHandlerInterface {
            public function __construct(private readonly string $csp) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return (new Response())
                    ->withHeader('Content-Type', 'image/svg+xml')
                    ->withHeader('Content-Security-Policy', $this->csp);
            }
        };
        $res = (new SecurityHeadersMiddleware())(
            (new ServerRequestFactory())->createServerRequest('GET', '/x.svg'),
            $handler
        );

        $this->assertSame($own, $res->getHeaderLine('Content-Security-Policy'));
        $this->assertStringNotContainsString('nonce-', $res->getHeaderLine('Content-Security-Policy'));
        // The rest of the hardening still applies.
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('max-age=', $res->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_html_still_gets_the_site_policy_with_its_nonce(): void
    {
        // The other half: opting out must be opt-IN. A page that sets no CSP of its own
        // gets the nonce-bearing site policy, and every inline script depends on it.
        $csp = $this->respond()->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString('style-src-elem', $csp);
    }

    public function test_a_non_html_response_gets_no_caching_policy_imposed(): void
    {
        $this->assertFalse($this->respond('application/json')->hasHeader('Cache-Control'));
    }

    public function test_a_response_with_no_content_type_is_treated_as_html(): void
    {
        // Slim has not always set Content-Type by the time this middleware runs, and
        // a nonce-bearing page that slipped through with no policy is the case the
        // whole thing exists for.
        $this->assertSame('private, no-cache, must-revalidate',
            $this->respond(null)->getHeaderLine('Cache-Control'));
    }

    // ── The shared header set ────────────────────────────────────────────────

    public function test_every_shared_header_is_present_on_a_response(): void
    {
        $res = $this->respond();
        foreach (SecurityHeadersMiddleware::SHARED as $name => $value) {
            $this->assertSame($value, $res->getHeaderLine($name), $name);
        }
        $this->assertNotSame('', $res->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString('max-age=', $res->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_the_htaccess_and_the_middleware_agree(): void
    {
        // The regression that matters. Apache's `Header always set` replaces, so a
        // divergence does not show up as a conflict — the middleware's value simply
        // never reaches a visitor, and the code stops describing the deployment.
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');

        $inApache = [];
        preg_match_all('~^\s*Header\s+always\s+set\s+(\S+)\s+"([^"]*)"~mi', $htaccess, $m, PREG_SET_ORDER);
        foreach ($m as $row) $inApache[$row[1]] = $row[2];

        $this->assertNotSame([], $inApache, 'the .htaccess header block must be parseable');

        $mismatch = [];
        foreach (SecurityHeadersMiddleware::SHARED as $name => $value) {
            if (!isset($inApache[$name])) {
                $mismatch[] = "{$name}: missing from .htaccess";
            } elseif ($inApache[$name] !== $value) {
                $mismatch[] = "{$name}: .htaccess has “{$inApache[$name]}”, PHP has “{$value}”";
            }
        }
        foreach ($inApache as $name => $value) {
            if (!isset(SecurityHeadersMiddleware::SHARED[$name])) {
                $mismatch[] = "{$name}: in .htaccess but not in SHARED — static files and PHP would differ";
            }
        }

        $this->assertSame([], $mismatch,
            'public/.htaccess protects the static files PHP never sees, and Apache '
            . 'overrides PHP where both apply — so they must be identical');
    }

    /**
     * The static CSP fallback, and the one mistake that would take the site down.
     *
     * A nonce cannot live in a static file, so `Csp::policy()` could never be duplicated
     * into .htaccess the way the six shared headers are — which left every real file
     * under public/ (`/assets/**`, the logo, everything under `/uploads/`) with NO CSP,
     * because Apache serves those without touching PHP.
     *
     * The fix must use `setifempty`. `Header always set Content-Security-Policy` would
     * REPLACE the nonce-bearing policy on every HTML page with a nonce-free one, and
     * every inline script on the site — Alpine, the nav, the cart, the ballot — would
     * stop running, with nothing in any log. That is a far worse outcome than the gap it
     * closes, and it is a one-word edit away, so it is pinned here.
     */
    public function test_the_htaccess_csp_fallback_can_never_replace_the_nonce_policy(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');

        $this->assertMatchesRegularExpression(
            '~Header\s+always\s+setifempty\s+Content-Security-Policy\s+"~i',
            $htaccess,
            'static files need a CSP fallback — Apache serves them without touching PHP'
        );

        // The killer. `set` (not `setifempty`) would overwrite the per-request policy.
        $this->assertDoesNotMatchRegularExpression(
            '~Header\s+always\s+set\s+Content-Security-Policy~i',
            $htaccess,
            'use setifempty: `set` replaces the nonce-bearing policy and kills every '
            . 'inline script on the site'
        );

        // A nonce in a static file is a fixed string every visitor gets, i.e. a value an
        // attacker can read from the header and reuse — worse than no nonce at all.
        preg_match('~Header\s+always\s+setifempty\s+Content-Security-Policy\s+"([^"]*)"~i', $htaccess, $m);
        $fallback = $m[1] ?? '';
        $this->assertStringNotContainsString('nonce-', $fallback,
            'a static nonce is a constant every visitor and every attacker receives');
        // default-src 'none' is what stops a directly-navigated SVG executing script,
        // since script-src inherits from it. This is the whole point of the fallback.
        $this->assertStringContainsString("default-src 'none'", $fallback);

        // Version-gated: setifempty is Apache 2.4.7+, and on anything older an unknown
        // directive is a syntax error that 500s the entire site. Losing the backup on an
        // old Apache is acceptable; taking the site down to add one is not.
        $this->assertMatchesRegularExpression('~<IfVersion\s*>=\s*2\.4\.7>~i', $htaccess,
            'setifempty must be guarded — it does not exist before Apache 2.4.7');
        $this->assertMatchesRegularExpression('~<IfModule\s+mod_version\.c>~i', $htaccess,
            'IfVersion itself needs mod_version, or the guard is the crash');
    }

    /**
     * The uploads directory's own hardening exists AND is committable.
     *
     * `public/.htaccess` referred to a "Layer 2: public/uploads/.htaccess" that did not
     * exist in any deployment — `.gitignore` excluded the whole `public/uploads/`
     * directory, and git cannot re-include a file whose parent directory is excluded, so
     * the file could never be committed. A comment describing protection that is not
     * there is worse than no comment.
     */
    public function test_the_uploads_directory_is_hardened_and_the_file_can_ship(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertFileExists($root . '/public/uploads/.htaccess',
            'the layer public/.htaccess points at must actually exist');
        $guard = (string) file_get_contents($root . '/public/uploads/.htaccess');

        // Untrusted bytes: the hard sandbox, and here `set` IS correct — nothing under
        // /uploads/ is ever served by PHP, so there is no nonce policy to overwrite.
        $this->assertMatchesRegularExpression('~Header\s+always\s+set\s+Content-Security-Policy~i', $guard);
        $this->assertStringContainsString('sandbox', $guard,
            'an opaque origin, so a navigated-to SVG cannot reach the site DOM or cookies');
        $this->assertStringContainsString("default-src 'none'", $guard);
        $this->assertStringContainsString('nosniff', $guard,
            'a "PNG" that is really HTML is the classic stored-XSS route');
        $this->assertMatchesRegularExpression('~RemoveHandler~i', $guard, 'never execute anything here');

        // And the ignore rule must permit exactly this one path. `public/uploads/` (the
        // directory form) makes the negation impossible; `public/uploads/*` does not.
        $ignore = (string) file_get_contents($root . '/.gitignore');
        $this->assertStringContainsString('public/uploads/*', $ignore,
            'the directory form of the ignore makes the negation below inoperative');
        $this->assertStringContainsString('!public/uploads/.htaccess', $ignore);
        $this->assertStringNotContainsString("\npublic/uploads/\n", "\n" . $ignore . "\n",
            'the bare directory form must be gone, or the negation cannot take effect');
    }

    public function test_the_dead_floc_directive_is_gone(): void
    {
        // interest-cohort was withdrawn in 2022. An unrecognised token in
        // Permissions-Policy denies nothing, so keeping it read as protection that
        // was not there while the features worth denying went unlisted.
        $pp = SecurityHeadersMiddleware::SHARED['Permissions-Policy'];
        $this->assertStringNotContainsString('interest-cohort', $pp);
        foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'usb=()'] as $denied) {
            $this->assertStringContainsString($denied, $pp);
        }
    }

    public function test_the_opener_policy_still_allows_a_popup_checkout(): void
    {
        // Plain `same-origin` would work today, because the gateways redirect in the
        // same tab. It would break the moment a popup checkout is added, and that
        // breakage gets diagnosed as a gateway fault rather than as a header.
        $this->assertSame('same-origin-allow-popups',
            SecurityHeadersMiddleware::SHARED['Cross-Origin-Opener-Policy']);
    }

    public function test_the_legacy_xss_auditor_is_switched_off_not_enabled(): void
    {
        // "1; mode=block" is the value people expect to see. It is the wrong one: the
        // auditor introduced its own vulnerabilities and browsers removed it. 0 is an
        // explicit off rather than a browser default.
        $this->assertSame('0', SecurityHeadersMiddleware::SHARED['X-XSS-Protection']);
    }
}
