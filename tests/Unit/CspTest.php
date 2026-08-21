<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use AfricaGates\Support\Csp;

/**
 * The Content-Security-Policy, and the nonce it depends on.
 *
 * WHAT WAS WRONG. `script-src 'self' 'unsafe-inline' 'unsafe-eval' https:`.
 * `'unsafe-inline'` lets an injected `<script>` run; `https:` lets a script come
 * from any https host on the internet. Together they meant the policy offered no
 * protection against script injection at all, on a platform that takes card
 * payments and runs a public ballot. The other directives were doing real work;
 * script-src was decoration.
 *
 * WHY THESE TESTS EXIST RATHER THAN A MANUAL CHECK. Once a nonce is present the
 * browser IGNORES `'unsafe-inline'` for scripts — so a single inline `<script>`
 * missing its nonce is silently dead, with no error on the server and nothing in the
 * test suite to notice. 47 inline scripts across 37 templates had to be updated. A
 * render-level assertion is the only honest way to know they all were.
 */
class CspTest extends TestCase
{
    /** Render a real page through the real Twig, as a request would. */
    private function render(string $class, string $method, string $path): string
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get($class);
        $req  = (new ServerRequestFactory())->createServerRequest('GET', $path);
        return (string) $ctrl->$method($req, new Response())->getBody();
    }

    /** @return list<string> every inline <script> open tag in $html */
    private function inlineScriptTags(string $html): array
    {
        preg_match_all('/<script\b[^>]*>/i', $html, $m);
        return array_values(array_filter($m[0], static fn (string $t) => !preg_match('/\ssrc=/i', $t)));
    }

    // ── The policy itself ────────────────────────────────────────────────────

    public function test_script_src_no_longer_permits_inline_or_any_https_host(): void
    {
        $policy = Csp::policy();
        preg_match("/script-src ([^;]+);/", $policy, $m);
        $scriptSrc = $m[1] ?? '';

        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc,
            "'unsafe-inline' in script-src means an injected <script> runs");
        $this->assertDoesNotMatchRegularExpression('/(^|\s)https:(\s|$)/', $scriptSrc,
            'a bare https: scheme permits a script from any host on the internet');
        $this->assertStringContainsString("'nonce-", $scriptSrc);
    }

    public function test_the_hosts_that_serve_script_are_named_explicitly(): void
    {
        // Each is a supply-chain dependency. Listing them makes the exposure
        // countable, which `https:` did not.
        foreach (['unpkg.com', 'code.jquery.com', 'challenges.cloudflare.com'] as $host) {
            $this->assertStringContainsString($host, Csp::policy(), "{$host} is loaded by a template");
        }

        // And the list got SHORTER, which is the direction it should move in. Everything
        // jsdelivr and plyr.io used to serve is vendored under public/assets now, so
        // neither may execute script here any more. Asserted rather than assumed, because
        // one library added back "temporarily" from a CDN would put that whole origin's
        // output back in scope, and the policy is the only place that would show it.
        preg_match('/script-src ([^;]+);/', Csp::policy(), $ss);
        foreach (['cdn.jsdelivr.net', 'cdn.plyr.io'] as $retired) {
            $this->assertStringNotContainsString($retired, $ss[1] ?? '',
                "{$retired} can execute script again — its assets are supposed to be vendored");
        }
    }

    public function test_connect_src_is_tight_because_it_is_the_exfiltration_path(): void
    {
        preg_match("/connect-src ([^;]+);/", Csp::policy(), $m);

        $this->assertDoesNotMatchRegularExpression('/(^|\s)https:(\s|$)/', $m[1] ?? '',
            'connect-src https: lets injected script POST anywhere it likes');
        $this->assertStringContainsString("'self'", $m[1] ?? '');
    }

    public function test_the_directives_that_were_already_right_are_kept(): void
    {
        // These were doing the real work before and must not be lost in the rewrite.
        $policy = Csp::policy();
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString('paystack.com', $policy,
            'form-action must allow the gateways or every checkout redirect is blocked');
        $this->assertStringContainsString('flutterwave.com', $policy);
    }

    public function test_unsafe_eval_is_still_present_and_that_is_deliberate(): void
    {
        // Alpine 3 compiles x-data/@click with new Function. Removing this needs
        // Alpine's CSP build and a rewrite of every directive in the templates —
        // a project, not a tightening. Asserted so the compromise is explicit
        // rather than an oversight someone "fixes" and breaks the nav with.
        $this->assertStringContainsString("'unsafe-eval'", Csp::policy());
    }

    public function test_the_nonce_is_stable_within_a_request_and_random_looking(): void
    {
        // Stable, because the header and every script tag must agree. If it were
        // regenerated per call, every page would ship dead scripts.
        $this->assertSame(Csp::nonce(), Csp::nonce());
        $this->assertMatchesRegularExpression('~^[A-Za-z0-9+/]{20,}={0,2}$~', Csp::nonce());
        $this->assertStringContainsString("'nonce-" . Csp::nonce() . "'", Csp::policy());
    }

    // ── The rendered pages ──────────────────────────────────────────────────

    public function test_every_inline_script_on_a_rendered_page_carries_the_nonce(): void
    {
        // THE TEST THAT MATTERS. A missed nonce is a silently dead script.
        DB::table('gates_profiles')->insert(['slug' => 'ada', 'display_name' => 'Ada Obi', 'email' => 'ada@example.com']);

        foreach ([
            [\AfricaGates\Controllers\HomeController::class, 'index', '/'],
            [\AfricaGates\Controllers\RegistryController::class, 'index', '/registry'],
            [\AfricaGates\Controllers\LeaderboardController::class, 'index', '/leaderboard'],
            [\AfricaGates\Controllers\AwardsController::class, 'index', '/awards'],
        ] as [$class, $method, $path]) {
            $html = $this->render($class, $method, $path);
            $tags = $this->inlineScriptTags($html);

            $this->assertNotSame([], $tags, "{$path} rendered no inline scripts — check the fixture, not the CSP");
            foreach ($tags as $tag) {
                $this->assertStringContainsString('nonce=', $tag,
                    "{$path} has an inline <script> with no nonce, which the browser will refuse to run: {$tag}");
            }
        }
    }

    public function test_the_rendered_nonce_matches_the_one_the_header_advertises(): void
    {
        // Two generators would be the obvious way to break this, so one holder
        // serves both and this asserts they agree.
        $html = $this->render(\AfricaGates\Controllers\HomeController::class, 'index', '/');

        $this->assertStringContainsString('nonce="' . Csp::nonce() . '"', $html);
    }

    public function test_no_rendered_page_uses_an_inline_event_handler(): void
    {
        // Inline handlers require 'unsafe-inline' in script-src, which is exactly
        // what was removed. Ten of them were converted to delegated data-ag-do
        // attributes; this stops the eleventh being added.
        DB::table('gates_profiles')->insert(['slug' => 'ada', 'display_name' => 'Ada Obi', 'email' => 'ada@example.com']);

        foreach ([
            [\AfricaGates\Controllers\HomeController::class, 'index', '/'],
            [\AfricaGates\Controllers\RegistryController::class, 'index', '/registry'],
            [\AfricaGates\Controllers\LeaderboardController::class, 'index', '/leaderboard'],
        ] as [$class, $method, $path]) {
            $html = $this->render($class, $method, $path);

            $this->assertDoesNotMatchRegularExpression(
                '/\son(?:click|change|load|error|submit|input|focus|blur|mouseover)\s*=\s*["\']/i',
                $html,
                "{$path} contains an inline event handler; convert it to data-ag-do"
            );
        }
    }

    public function test_no_template_source_contains_an_un_nonced_inline_script(): void
    {
        // Source-level backstop for the pages the render tests do not reach —
        // admin, judge, shop, account. 47 inline scripts across 37 files had to be
        // updated and a missed one fails silently in a browser, not here.
        $offenders = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            // Comments stripped, like the handler scan below: the layout explains
            // this trap in prose and would otherwise flag itself.
            $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file->getPathname()));
            preg_match_all('/<script\b[^>]*>/i', $body, $m);
            foreach ($m[0] as $tag) {
                if (preg_match('/\ssrc=/i', $tag)) continue;
                if (!str_contains($tag, 'nonce=')) {
                    $offenders[] = basename($file->getPathname()) . ': ' . $tag;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_no_template_source_contains_an_inline_event_handler(): void
    {
        $offenders = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            // Comments stripped: the layout explains the trap in prose, and the
            // scan must judge markup rather than documentation.
            $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file->getPathname()));
            if (preg_match('/\son(?:click|change|load|error|submit|input|focus|blur|mouseover)\s*=\s*["\']/i', $body, $m)) {
                $offenders[] = basename($file->getPathname()) . ': ' . trim($m[0]);
            }
        }

        $this->assertSame([], $offenders,
            "inline handlers need 'unsafe-inline' in script-src — use data-ag-do instead");
    }

    // ── Styles ──────────────────────────────────────────────────────────────

    public function test_style_blocks_are_nonce_protected_via_the_element_directive(): void
    {
        preg_match("/style-src-elem ([^;]+);/", Csp::policy(), $m);
        $elem = $m[1] ?? '';

        $this->assertStringContainsString("'nonce-", $elem);
        $this->assertStringNotContainsString("'unsafe-inline'", $elem,
            'a <style> block can overlay the page, fake UI and exfiltrate values through '
            . 'attribute selectors — it is the style vector worth protecting');
    }

    public function test_plain_style_src_carries_no_nonce_and_that_is_the_whole_trick(): void
    {
        // THE TRAP THIS GUARDS. A nonce anywhere in `style-src` makes browsers ignore
        // 'unsafe-inline' for that directive — and `style-src` governs BOTH <style>
        // elements and style= attributes. Putting the nonce there, which is the
        // obvious move, would have killed all 1,120 inline style attributes site-wide.
        // It is kept nonce-free purely as the fallback for browsers without the
        // CSP3 split.
        preg_match("/style-src ([^;]+);/", Csp::policy(), $m);

        $this->assertStringNotContainsString("'nonce-", $m[1] ?? '',
            'a nonce here nullifies unsafe-inline for style= attributes too, breaking every page');
        $this->assertStringContainsString("'unsafe-inline'", $m[1] ?? '');
    }

    public function test_style_attributes_are_still_permitted_because_they_have_to_be(): void
    {
        // 55 of the 1,120 interpolate Twig values — a computed colour or bar width
        // cannot become a static class, and CSP has no per-attribute nonce. This is
        // structural, not laziness, so it is asserted rather than left to rot into a
        // TODO nobody revisits.
        preg_match("/style-src-attr ([^;]+);/", Csp::policy(), $m);

        $this->assertStringContainsString("'unsafe-inline'", $m[1] ?? '');
    }

    public function test_every_style_block_on_a_rendered_page_carries_the_nonce(): void
    {
        // Same silent-failure shape as the scripts: with style-src-elem carrying a
        // nonce, an un-nonced <style> block is dropped and the page renders unstyled.
        DB::table('gates_profiles')->insert(['slug' => 'ada', 'display_name' => 'Ada Obi', 'email' => 'ada@example.com']);

        foreach ([
            [\AfricaGates\Controllers\HomeController::class, 'index', '/'],
            [\AfricaGates\Controllers\RegistryController::class, 'index', '/registry'],
            [\AfricaGates\Controllers\AwardsController::class, 'index', '/awards'],
        ] as [$class, $method, $path]) {
            $html = $this->render($class, $method, $path);
            preg_match_all('/<style\b[^>]*>/i', $html, $m);

            $this->assertNotSame([], $m[0], "{$path} rendered no <style> block — check the fixture");
            foreach ($m[0] as $tag) {
                $this->assertStringContainsString('nonce=', $tag,
                    "{$path} has a <style> with no nonce; the page will render unstyled: {$tag}");
            }
        }
    }

    public function test_no_template_source_contains_an_un_nonced_style_block(): void
    {
        // Backstop for admin, judge, shop and account, which the render tests above
        // do not reach. 42 blocks across 42 files had to be updated.
        //
        // templates/emails/ is excluded, and the exclusion is asserted rather than
        // assumed — see test_email_templates_carry_no_nonce below. Those templates are
        // rendered into a MAIL BODY, never returned as an HTTP response: there is no
        // Content-Security-Policy on a message sitting in an inbox, `csp_nonce` is not
        // in scope when they render, and a nonce attribute there would be a dead
        // attribute that only looks like security.
        $offenders = [];
        foreach (self::templateFiles() as $path) {
            if (self::isEmailTemplate($path)) continue;
            $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));
            preg_match_all('/<style\b[^>]*>/i', $body, $m);
            foreach ($m[0] as $tag) {
                if (!str_contains($tag, 'nonce=')) $offenders[] = basename($path) . ': ' . $tag;
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * The other half of the exclusion above: an email template must carry no nonce.
     *
     * Without this, "skip templates/emails/" is a hole somebody could park a real page
     * in. With it, the rule is symmetric — web templates must have a nonce on every
     * style block, mail templates must have none anywhere — so a page filed in the wrong
     * directory fails one test or the other.
     */
    public function test_email_templates_carry_no_nonce(): void
    {
        $emails = array_values(array_filter(self::templateFiles(), fn($p) => self::isEmailTemplate($p)));
        $this->assertNotSame([], $emails, 'No templates under templates/emails/ — has the directory moved?');

        $offenders = [];
        foreach ($emails as $path) {
            $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));
            if (str_contains($body, 'csp_nonce') || preg_match('/\bnonce=/i', $body) === 1) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders,
            'An email template references a CSP nonce. Inboxes have no CSP; if this file is '
            . 'actually a web page it belongs outside templates/emails/.');
    }

    /** @return list<string> every .twig under templates/ */
    private static function templateFiles(): array
    {
        $out = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') $out[] = $file->getPathname();
        }
        sort($out);

        return $out;
    }

    private static function isEmailTemplate(string $path): bool
    {
        return str_contains(str_replace('\\', '/', $path), '/templates/emails/');
    }

    // ── Nonce reuse ─────────────────────────────────────────────────────────

    /** Run the middleware over a response with the given headers. */
    private function through(array $headers): \Psr\Http\Message\ResponseInterface
    {
        return (new \AfricaGates\Middleware\SecurityHeadersMiddleware())(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/'),
            new class ($headers) implements \Psr\Http\Server\RequestHandlerInterface {
                public function __construct(private readonly array $headers) {}
                public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
                {
                    $res = new \Slim\Psr7\Response();
                    foreach ($this->headers as $k => $v) $res = $res->withHeader($k, $v);
                    return $res;
                }
            }
        );
    }

    public function test_a_nonce_bearing_page_is_not_storable_by_a_shared_cache(): void
    {
        // A nonce is a per-request secret and is worthless if reused. Scope, measured
        // rather than assumed: PHP's session.cache_limiter defaults to `nocache`, so
        // any page that started a session already sends no-store — most pages here do.
        // This covers the remainder: an HTML response rendered WITHOUT a session, which
        // would otherwise carry a nonce and no cache policy at all, leaving a CDN or
        // reverse proxy free to store it and hand it to another user.
        $res = $this->through(['Content-Type' => 'text/html; charset=utf-8']);

        $this->assertStringContainsString('private', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString("'nonce-", $res->getHeaderLine('Content-Security-Policy'),
            'header and body carry the same nonce, which is exactly why reuse matters');
    }

    public function test_a_response_with_no_content_type_is_treated_as_html(): void
    {
        // Slim has not always set Content-Type by the time this middleware runs, and
        // defaulting to "not HTML" would leave the most common case uncovered.
        $this->assertStringContainsString('private', $this->through([])->getHeaderLine('Cache-Control'));
    }

    public function test_a_route_that_sets_its_own_cache_policy_is_not_overridden(): void
    {
        // The JSON and setup routes deliberately send no-store; MediaController sends
        // its own. Replacing those would be a regression dressed as a fix.
        $this->assertSame('no-store',
            $this->through(['Content-Type' => 'text/html', 'Cache-Control' => 'no-store'])->getHeaderLine('Cache-Control'));
        $this->assertSame('private, max-age=300, must-revalidate',
            $this->through(['Content-Type' => 'text/html', 'Cache-Control' => 'private, max-age=300, must-revalidate'])->getHeaderLine('Cache-Control'));
    }

    public function test_a_non_html_response_is_left_alone(): void
    {
        // Images and downloads carry no nonce and may be shared-cacheable; forcing
        // `private` on them would quietly cost every shared cache hit the site gets.
        $this->assertSame('', $this->through(['Content-Type' => 'image/png'])->getHeaderLine('Cache-Control'));
        $this->assertSame('', $this->through(['Content-Type' => 'application/json'])->getHeaderLine('Cache-Control'));
    }

    public function test_the_nonce_carries_128_bits_of_randomness(): void
    {
        // Per the CSP guidance. A short nonce is guessable, which would make the whole
        // exercise decorative — the same failure as the policy it replaced.
        $this->assertSame(16, strlen((string) base64_decode(Csp::nonce(), true)));
    }
}
