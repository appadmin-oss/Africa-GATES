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
            // Content-Security-Policy is deliberately NOT in SHARED, and is the one
            // header allowed here without a counterpart there. SHARED holds the fixed
            // strings duplicated between PHP and Apache; the CSP is generated per
            // request, so PHP sends Csp::policy() (with a nonce) while this file sends
            // Csp::staticPolicy() (without one) to displace the policy the host injects.
            // That pairing is checked by CspStaticFallbackTest, which is its proper
            // home — asserting it here would compare a nonce to a file on disk.
            if (strcasecmp($name, 'Content-Security-Policy') === 0) continue;
            if (!isset(SecurityHeadersMiddleware::SHARED[$name])) {
                $mismatch[] = "{$name}: in .htaccess but not in SHARED — static files and PHP would differ";
            }
        }

        $this->assertSame([], $mismatch,
            'public/.htaccess protects the static files PHP never sees, and Apache '
            . 'overrides PHP where both apply — so they must be identical');
    }

    /**
     * The static-file CSP lives in PER-DIRECTORY .htaccess files, and public/.htaccess
     * must contain no CSP directive at all.
     *
     * Both halves of this are scar tissue from a real outage.
     *
     * A `Header set Content-Security-Policy` in public/.htaccess would REPLACE the
     * nonce-bearing policy on every HTML page with a nonce-free one, and every inline
     * script on the site — Alpine, the nav, the cart, the ballot — would stop with
     * nothing in any log.
     *
     * `Header setifempty` avoids that by construction, and it is what the first version
     * of this change used. It 500'd the deployment: `setifempty` is Apache 2.4.7+ and is
     * not supported by LiteSpeed's mod_headers emulation, which a large share of cPanel
     * hosts run — and an unsupported directive in .htaccess takes down the whole site.
     * Wrapping it in `<IfVersion>` does not help, because `<IfVersion>` is itself a
     * directive the server has to understand.
     *
     * Scoping by DIRECTORY needs no exotic directive: PHP serves nothing from
     * public/assets/ or public/uploads/, so a plain `Header set` there is safe because
     * of where it is, not because of what it is.
     */
    public function test_the_static_csp_is_scoped_by_directory_not_by_directive(): void
    {
        $root = dirname(__DIR__, 2);
        // Comments stripped FIRST. public/.htaccess documents both banned directives by
        // name — that documentation is the point — and scanning raw text reported the
        // prose explaining why `setifempty` is forbidden as an instance of it being used.
        // Same trap as the session-limiter ordering check above: only executable
        // configuration can be wrong.
        $rootHtaccess = (string) preg_replace('~^\s*#.*$~m', '',
            (string) file_get_contents($root . '/public/.htaccess'));

        // A CSP `set` here DOES replace the nonce policy — that has not changed, and it
        // is why this test forbade it. What changed is the finding that the host injects
        // its own policy account-wide, so the alternative to replacing the nonce policy
        // ourselves is the host replacing it with one that has no allowlist and no
        // gateways. Displacing it is now deliberate, and it is only safe while it is
        // paired with the `unset` and generated from Csp::staticPolicy() — both asserted
        // by CspStaticFallbackTest. Remove all of it when the host stops injecting.
        $this->assertMatchesRegularExpression(
            '~^\s*Header\s+always\s+unset\s+Content-Security-Policy~mi',
            $rootHtaccess,
            'the CSP `set` in public/.htaccess must be preceded by an `unset`, or the '
            . 'host-injected policy remains on the response and is enforced alongside ours'
        );
        $this->assertDoesNotMatchRegularExpression(
            '~setifempty~i',
            $rootHtaccess,
            'setifempty is Apache 2.4.7+ and unsupported by LiteSpeed — it 500s the site'
        );
        $this->assertDoesNotMatchRegularExpression(
            '~<IfVersion~i',
            $rootHtaccess,
            '<IfVersion> is itself a directive the server must understand, so it cannot guard one'
        );

        // …and the coverage it replaces must actually exist, in both directories.
        foreach (['public/assets/.htaccess', 'public/uploads/.htaccess'] as $rel) {
            $this->assertFileExists($root . '/' . $rel);
            // Comments stripped here too, for the same reason: these files explain at
            // length why a static nonce would be wrong, and the phrase "nonce-bearing" in
            // that explanation read as a nonce being present. Twice in one test file is
            // enough to call it a pattern — a config guard must look at configuration.
            $conf = (string) preg_replace('~^\s*#.*$~m', '',
                (string) file_get_contents($root . '/' . $rel));
            $this->assertMatchesRegularExpression('~Header\s+set\s+Content-Security-Policy~i', $conf, $rel);
            $this->assertStringContainsString("default-src 'none'", $conf,
                $rel . ": default-src 'none' is what stops a navigated-to SVG executing script");
            $this->assertStringNotContainsString('nonce-', $conf,
                $rel . ': a static nonce is a constant every visitor and every attacker receives');
        }
    }

    /**
     * Every .htaccess this project ships uses only directives that are safe in a
     * per-directory context on a restricted shared host.
     *
     * The outage was not one mistake, it was four in one file: `Options -ExecCGI` and
     * `php_flag` both need `AllowOverride Options` (commonly granted only as a restricted
     * subset, or not at all), an unguarded `RemoveHandler` is an unknown command without
     * mod_mime, and `setifempty` does not exist on older Apache or LiteSpeed. Each one is
     * a 500 for the entire site, and none of them fails on a developer machine.
     */
    public function test_no_htaccess_uses_a_directive_that_needs_allowoverride_options(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach ([
            '.htaccess',
            'public/.htaccess',
            'public/assets/.htaccess',
            'public/uploads/.htaccess',
        ] as $rel) {
            $path = $root . '/' . $rel;
            if (!is_file($path)) continue;
            // Strip comments — these files document the banned directives at length.
            $conf = (string) preg_replace('~^\s*#.*$~m', '', (string) file_get_contents($path));

            // php_flag / php_value need AllowOverride Options even under mod_php, so an
            // <IfModule> guard does not make them safe.
            if (preg_match('~^\s*php_(flag|value|admin_flag|admin_value)\s~mi', $conf)) {
                $offenders[] = "{$rel}: php_flag/php_value needs AllowOverride Options";
            }
            // setifempty / IfVersion: unsupported on older Apache and on LiteSpeed.
            if (preg_match('~setifempty~i', $conf)) {
                $offenders[] = "{$rel}: Header setifempty is Apache 2.4.7+ / not on LiteSpeed";
            }
            if (preg_match('~<IfVersion~i', $conf)) {
                $offenders[] = "{$rel}: <IfVersion> cannot guard anything — it needs mod_version itself";
            }
            // RemoveHandler / RemoveType / AddType are mod_mime; unknown command → 500.
            //
            // Tracked by BLOCK NESTING, not by "does the file contain a mod_mime guard
            // somewhere". The first version asked the weaker question and, verified
            // directly, did not catch a RemoveHandler appended outside the guard in a file
            // that happened to have one elsewhere — which is exactly the shape of the
            // mistake that caused the outage.
            $open = [];
            foreach (preg_split('~\R~', $conf) ?: [] as $line) {
                if (preg_match('~<IfModule\s+(!?)\s*([A-Za-z0-9_]+\.c)\s*>~i', $line, $m) === 1) {
                    $open[] = ($m[1] === '!' ? '!' : '') . strtolower($m[2]);
                    continue;
                }
                if (preg_match('~</IfModule\s*>~i', $line) === 1) { array_pop($open); continue; }
                foreach (['RemoveHandler', 'RemoveType', 'AddType', 'AddHandler'] as $mime) {
                    if (preg_match('~^\s*' . $mime . '\s~i', $line) === 1
                        && !in_array('mod_mime.c', $open, true)) {
                        $offenders[] = "{$rel}: {$mime} must be inside <IfModule mod_mime.c>";
                    }
                }
            }
            // ExecCGI is the Options token most often outside a host's granted subset.
            if (preg_match('~^\s*Options\b[^\n]*ExecCGI~mi', $conf)) {
                $offenders[] = "{$rel}: Options ...ExecCGI is commonly outside AllowOverride's granted subset";
            }
        }

        $this->assertSame([], $offenders,
            "these directives 500 a restricted shared host, and none of them fails locally:\n  "
            . implode("\n  ", $offenders));
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

        // Untrusted bytes get the hard sandbox, and here plain `set` IS correct — nothing
        // under /uploads/ is ever served by PHP, so there is no nonce policy to overwrite.
        $this->assertMatchesRegularExpression('~Header\s+set\s+Content-Security-Policy~i', $guard);
        $this->assertStringContainsString('sandbox', $guard,
            'an opaque origin, so a navigated-to SVG cannot reach the site DOM or cookies');
        $this->assertStringContainsString("default-src 'none'", $guard);
        $this->assertStringContainsString('nosniff', $guard,
            'a "PNG" that is really HTML is the classic stored-XSS route');
        $this->assertMatchesRegularExpression('~RemoveHandler~i', $guard, 'never execute anything here');
        $this->assertMatchesRegularExpression('~<IfModule\s+mod_mime\.c>~i', $guard,
            'RemoveHandler is a mod_mime directive — unguarded it is a 500 without mod_mime');

        // And the ignore rule must permit exactly this one path. `public/uploads/` (the
        // directory form) makes the negation impossible; `public/uploads/*` does not.
        $ignore = (string) file_get_contents($root . '/.gitignore');
        $this->assertStringContainsString('public/uploads/*', $ignore,
            'the directory form of the ignore makes the negation below inoperative');
        $this->assertStringContainsString('!public/uploads/.htaccess', $ignore);
    }

    public function test_the_dead_floc_directive_is_gone(): void
    {
        // interest-cohort was withdrawn in 2022. An unrecognised token in
        // Permissions-Policy denies nothing, so keeping it read as protection that
        // was not there while the features worth denying went unlisted.
        $pp = SecurityHeadersMiddleware::SHARED['Permissions-Policy'];
        $this->assertStringNotContainsString('interest-cohort', $pp);
        // `microphone=()` was in this list and had to come out of it: a nominee may answer
        // a question out loud, and denying the feature here forbade that on every page
        // while this line read as protection. It is the third capability a hand-written
        // denial list has taken away from a shipped feature — camera, autoplay, then this.
        // What remains is denied because NOTHING in this codebase asks for it, which is the
        // only reason a denial is ever right; the sweep below is what keeps that true.
        foreach (['geolocation=()', 'payment=()', 'usb=()', 'midi=()'] as $denied) {
            $this->assertStringContainsString($denied, $pp);
        }
    }

    /**
     * THE DOOR NEEDS THE CAMERA, AND THIS TEST USED TO FORBID IT.
     *
     * `camera=()` was in the denied list above, asserted by name — so the header denied
     * the camera on every page of the site, the door's ticket scanner could never call
     * getUserMedia, and this test kept it that way. The scanner had never worked in
     * production on any device since the day it shipped.
     *
     * It failed silently, which is what made it survive: the page catches the rejection
     * and writes "Camera unavailable — type the code", indistinguishable from a refused
     * prompt or a phone with no lens. Nothing anywhere pointed at a header.
     *
     * `self` and not `*`: our own door may open a camera, an embedded third party may
     * not. Asserted as an exact token so a later tidy-up cannot quietly widen it to `*`
     * or narrow it back to `()`.
     */
    public function test_the_door_may_open_a_camera_and_nobody_else_may(): void
    {
        $pp = SecurityHeadersMiddleware::SHARED['Permissions-Policy'];

        $this->assertStringContainsString('camera=(self)', $pp,
            'the door scanner cannot open a camera — getUserMedia is refused by the browser');
        $this->assertStringNotContainsString('camera=()', $pp);
        $this->assertStringNotContainsString('camera=*', $pp,
            'an embedded third party may not open the venue phone\'s camera');
    }

    /**
     * THE DOOR SPEAKS, AND THIS HEADER HAD IT MUTE — the same fault as the camera, again.
     *
     * `autoplay=()` denies the feature to our own documents, so the browser refused every
     * `play()` on every page of the site. The door greets each guest by name from a clip a
     * maintenance sweep rendered hours earlier; the refusal arrives as a rejected promise
     * the page swallows on purpose (a door with no sound is a working door), so there was
     * no error, no console line and nothing anywhere pointing at a header. Every guest at
     * every event since the voice shipped was met in silence.
     *
     * `self` and not `*`: our own door may speak, an embedded third party may not start
     * making noise in a venue. Asserted as exact tokens, in both directions, because the
     * way this shipped the first time was a test naming the denial as though it were the
     * protection.
     */
    public function test_the_door_may_speak_and_nobody_else_may(): void
    {
        $pp = SecurityHeadersMiddleware::SHARED['Permissions-Policy'];

        $this->assertStringContainsString('autoplay=(self)', $pp,
            'the door cannot greet a guest — the browser refuses play() before the page runs');
        $this->assertStringNotContainsString('autoplay=()', $pp);
        $this->assertStringNotContainsString('autoplay=*', $pp,
            'an embedded third party may not autoplay audio at a venue');
    }

    /**
     * THE QUESTION THAT WOULD HAVE CAUGHT BOTH, ASKED OF EVERY FEATURE AT ONCE.
     *
     * Two capabilities have now been denied to this site's own pages by this one header,
     * each for months, each invisible from the page — `camera=()` killed the ticket
     * scanner and `autoplay=()` killed the door's voice. Both were found by a person
     * noticing a feature did not work, not by anything here, and both were then pinned by
     * name in a test that could only ever hold the case already known.
     *
     * So this asks the general form: FOR EVERY CAPABILITY THIS SITE'S OWN JAVASCRIPT
     * ACTUALLY CALLS, is the header letting it? A denial is only correct for a capability
     * nothing here uses. The list is derived from the shipped code on every run, so a
     * screen added next year that reaches for the wake lock or full screen fails here on
     * the day it is written rather than on the night it is needed.
     */
    public function test_no_capability_the_site_actually_uses_is_denied_by_the_header(): void
    {
        // The call a browser gates, and the Permissions-Policy feature that gates it.
        $gated = [
            'new Audio('               => 'autoplay',
            '.requestFullscreen('      => 'fullscreen',
            'requestPictureInPicture'  => 'picture-in-picture',
            'navigator.geolocation'    => 'geolocation',
            'wakeLock.request'         => 'screen-wake-lock',
        ];

        $pp    = SecurityHeadersMiddleware::SHARED['Permissions-Policy'];
        $found = [];

        foreach ($this->shippedPageCode() as $path => $src) {
            foreach ($gated as $call => $feature) {
                if (str_contains($src, $call)) $found[$feature][] = basename($path);
            }
            // getUserMedia is two features wearing one name, and only the CONSTRAINT says
            // which. Mapping it to `camera` alone is how the nominee's dictation stayed
            // invisible here while `microphone=()` refused it — the door asks for
            // `audio: false` and would have vouched for a header that muted somebody else.
            foreach ($this->mediaConstraints($src) as $feature) {
                $found[$feature][] = basename($path);
            }
        }

        // A POSITIVE CONTROL, because the comment stripping below could silently reduce
        // every file to nothing and this whole sweep would pass by finding no capability
        // at all — which is the failure mode of every scanner in this repository that has
        // needed fixing twice.
        $this->assertArrayHasKey('camera', $found,
            'the sweep found no getUserMedia anywhere: it is reading nothing, not passing');
        $this->assertArrayHasKey('autoplay', $found,
            'the sweep found no audio player anywhere: it is reading nothing, not passing');
        $this->assertArrayHasKey('microphone', $found,
            'the sweep found nothing recording: the constraint reader is reading nothing');

        foreach ($found as $feature => $files) {
            $this->assertStringNotContainsString($feature . '=()', $pp,
                $feature . ' is denied to our own pages by Permissions-Policy, and '
                . implode(', ', array_unique($files)) . ' calls it. The browser refuses it '
                . 'before a line of the page runs, and the page cannot tell that apart from '
                . 'the user saying no.');
        }
    }

    /**
     * Which of camera / microphone the getUserMedia calls in this source actually ask for.
     *
     * Read from the constraint object rather than from the call, because `audio: false` and
     * `audio: true` are the same call and opposite requirements.
     *
     * @return list<string>
     */
    private function mediaConstraints(string $src): array
    {
        if (!preg_match_all('~getUserMedia\s*\(~', $src, $m, PREG_OFFSET_CAPTURE)) return [];

        $out = [];
        foreach ($m[0] as [, $at]) {
            // The constraint object, generously bounded — these are always written inline
            // and none in this codebase runs past a couple of lines.
            $arg = substr($src, $at, 320);

            // The value is CAPTURED and compared, not excluded by a lookahead. `\s*` before
            // a `(?!false)` backtracks to zero width and the lookahead then reads the space
            // — so `audio: false` matched, and the door, which asks for no microphone at
            // all, vouched for the microphone. It passed the sweep's own positive control
            // while doing that, which is what makes the shape worth naming here.
            if (preg_match('~\bvideo\s*:\s*([A-Za-z0-9_$]+|\{)~', $arg, $v) && $v[1] !== 'false') {
                $out[] = 'camera';
            }
            if (preg_match('~\baudio\s*:\s*([A-Za-z0-9_$]+|\{)~', $arg, $a) && $a[1] !== 'false') {
                $out[] = 'microphone';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The page code this site actually ships, with comments blanked.
     *
     * Comments are stripped because this repository has four times shipped a scanner
     * fooled by the comment explaining the bug it was written to find — and the door's
     * player now carries a comment that names `autoplay=()` in so many words.
     *
     * @return array<string,string> path => source
     */
    private function shippedPageCode(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $dirs = [$root . '/templates', $root . '/public/assets/js'];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,
                \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                if (!in_array($f->getExtension(), ['twig', 'js'], true)) continue;
                // Vendored libraries are somebody else's feature detection, not ours: jsQR
                // and Plyr both name capabilities this site never asks for.
                if (str_contains($f->getPathname(), '/vendor/')) continue;
                $out[$f->getPathname()] = self::blankComments((string) file_get_contents($f->getPathname()));
            }
        }

        return $out;
    }

    /** Twig and JavaScript comments blanked. Offsets are not preserved; nothing needs them. */
    private static function blankComments(string $src): string
    {
        $src = (string) preg_replace('~\{#.*?#\}~s', ' ', $src);
        $src = (string) preg_replace('~/\*.*?\*/~s', ' ', $src);
        // `(?<!:)` keeps https:// intact, which is the only // this codebase has in a
        // string that a blunter rule would eat along with the rest of the line.
        return (string) preg_replace('~(?<!:)//[^
]*~', ' ', $src);
    }

    /**
     * And the scanner must not be gated on an API half the venue's phones do not have.
     *
     * `BarcodeDetector` does not exist on ANY iOS browser — they are all WebKit — nor on
     * Firefox. Gating the button's existence on it meant a steward holding an iPhone saw
     * no camera at all, which at a Nigerian gala is a large share of the people working
     * the gate. The fallback decoder is vendored rather than fetched from a CDN, because
     * a door on venue wifi cannot depend on a third party at the moment a queue forms.
     */
    public function test_the_scanner_has_a_decoder_for_phones_without_barcodedetector(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertFileExists($root . '/public/assets/vendor/jsqr.min.js',
            'no fallback decoder: the camera works on Chrome and nowhere else');

        $door = (string) file_get_contents($root . '/templates/pages/events/door.twig');
        $this->assertStringContainsString('jsqr.min.js', $door);
        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/jsqr', $door,
            'the decoder is fetched from a third party at the door');

        // ── OPENING THE CAMERA MUST NOT DEPEND ON BarcodeDetector ───────────
        //
        // Only WHICH decoder runs may. The redesign made the camera the ground state
        // rather than a button, so the gate is now the span from entering startCamera()
        // to the point where a stream exists and a decoder is chosen — that span IS the
        // gate. A blunter check for the name anywhere in the file would fail on the
        // decoder-selection function, which is allowed to ask.
        $from = strpos($door, 'async function startCamera()');
        $to   = strpos($door, 'native = await nativeDecoder();');
        $this->assertIsInt($from, 'the camera opener moved; this test must follow it');
        $this->assertIsInt($to);
        $this->assertGreaterThan($from, $to);

        $gate = substr($door, $from, $to - $from);
        $this->assertStringNotContainsString('BarcodeDetector', $gate,
            'the camera is refused outright on every iOS browser and on Firefox');
        $this->assertStringContainsString('getUserMedia', $gate,
            'the camera is opened where one cannot actually open');
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
