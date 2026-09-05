<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\SiteUrl;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The base URL every payment callback is built from.
 *
 * ── WHY THIS IS A PAYMENT TEST, NOT A URL TEST ───────────────────────────────
 *
 * Four controllers each had their own copy of
 * `rtrim((string) Env::get('APP_URL', ''), '/')`, which returns `''` when APP_URL is
 * unset. Every URL built from it was then RELATIVE — including the one handed to the
 * gateway:
 *
 *     $callbackUrl = $this->base() . '/vote/paid/callback?provider=…&ref=…'
 *                  → '/vote/paid/callback?provider=…&ref=…'
 *
 * That is not a URL Paystack or Flutterwave can redirect a browser to. The buyer pays,
 * never comes back, and the order sits PENDING with their money taken. Nothing logs it;
 * the only symptom is "the payment redirect does not work".
 *
 * APP_URL is also the single most likely setting to be wrong, because it is the first
 * line of `.env` and a deploy that copies `.env.example` inherits the example host —
 * which is worse than blank, since it silently points somewhere else.
 */
class SiteUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAppUrl();
    }

    protected function tearDown(): void
    {
        $this->clearAppUrl();
        parent::tearDown();
    }

    private function clearAppUrl(): void
    {
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
        putenv('APP_URL');
    }

    private function request(string $host, string $scheme = 'https', array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest('POST', $scheme . '://' . $host . '/vote/paid/start');
        foreach ($headers as $k => $v) $r = $r->withHeader($k, $v);
        return $r->withHeader('Host', $host);
    }

    // ── The bug ─────────────────────────────────────────────────────────────

    public function test_it_is_never_empty(): void
    {
        // The whole point. An empty base is a relative gateway callback.
        $this->assertNotSame('', SiteUrl::base(null));
        $this->assertNotSame('', SiteUrl::base($this->request('afg.afrovanguard.org.ng')));
    }

    public function test_with_no_app_url_it_derives_the_origin_from_the_request(): void
    {
        $this->assertSame(
            'https://afg.afrovanguard.org.ng',
            SiteUrl::base($this->request('afg.afrovanguard.org.ng')),
            'a relative callback URL is what broke the payment redirect'
        );
    }

    public function test_a_callback_url_built_from_it_is_always_absolute(): void
    {
        // Exactly the concatenation PaidVoteController performs.
        foreach ([null, $this->request('afg.afrovanguard.org.ng')] as $req) {
            $callback = SiteUrl::base($req) . '/vote/paid/callback?provider=paystack&ref=AFG-PVOTE-x';
            $this->assertMatchesRegularExpression('~^https?://[^/]+/vote/paid/callback~', $callback,
                'a gateway cannot redirect a browser to a relative URL');
            $this->assertNotFalse(filter_var($callback, FILTER_VALIDATE_URL));
        }
    }

    // ── APP_URL stays authoritative ─────────────────────────────────────────

    public function test_app_url_wins_over_the_request(): void
    {
        // It has to: behind a TLS-terminating proxy it is the only correct value, and a
        // Host header is client-supplied.
        $_ENV['APP_URL'] = 'https://afg.afrovanguard.org.ng';
        $this->assertSame('https://afg.afrovanguard.org.ng', SiteUrl::base($this->request('attacker.test')));
    }

    public function test_a_trailing_slash_is_stripped(): void
    {
        $_ENV['APP_URL'] = 'https://afg.afrovanguard.org.ng/';
        // Otherwise every URL gains a double slash, which some gateways reject outright.
        $this->assertSame('https://afg.afrovanguard.org.ng', SiteUrl::base(null));
    }

    public function test_a_configured_value_missing_its_scheme_is_repaired(): void
    {
        // An easy thing to type, and `afg.afrovanguard.org.ng/vote/paid/callback` is not a
        // URL a gateway can use either — so the operator's intent is honoured rather than
        // silently producing something broken.
        $_ENV['APP_URL'] = 'afg.afrovanguard.org.ng';
        $this->assertSame('https://afg.afrovanguard.org.ng', SiteUrl::base(null));
        $this->assertFalse(SiteUrl::isConfigured(), 'but it is still reported as not properly configured');
    }

    public function test_is_configured_reports_the_state_doctor_warns_on(): void
    {
        $this->assertFalse(SiteUrl::isConfigured());
        $_ENV['APP_URL'] = 'https://afg.afrovanguard.org.ng';
        $this->assertTrue(SiteUrl::isConfigured());
    }

    // ── The Host header is attacker-controlled ──────────────────────────────

    /**
     * A Host header carrying a path, a second host or CRLF must be REFUSED, not
     * interpolated. With APP_URL unset this value would otherwise land in a payment
     * callback URL — the worst possible destination for an attacker-chosen string, since
     * the gateway would redirect the buyer there after taking their money.
     */
    public function test_a_malformed_host_header_is_refused(): void
    {
        // CRLF is deliberately absent: Slim's PSR-7 refuses to construct a header value
        // containing it at all ("Header values must be RFC 7230 compatible strings"), so
        // that vector is closed a layer before SiteUrl sees it. Asserting it here would be
        // testing the framework, and would fail for the wrong reason.
        foreach (['a.test/evil', 'a.test evil.test', 'a.test?x=1', 'a.test#f', 'a.test:notaport'] as $bad) {
            $req = (new ServerRequestFactory())
                ->createServerRequest('POST', 'https://good.test/vote/paid/start')
                ->withHeader('Host', $bad);
            $base = SiteUrl::base($req);

            $this->assertSame(SiteUrl::FALLBACK, $base,
                "a malformed Host must fall back, not be interpolated: {$bad}");
            $this->assertNotFalse(filter_var($base, FILTER_VALIDATE_URL));
        }
    }

    public function test_a_host_with_a_port_is_accepted(): void
    {
        // Local and staging deployments legitimately carry one.
        $this->assertSame('http://127.0.0.1:8080', SiteUrl::base($this->request('127.0.0.1:8080', 'http')));
    }

    // ── Forwarded scheme, only when a proxy is trusted ──────────────────────

    public function test_the_forwarded_scheme_is_honoured_only_behind_a_trusted_proxy(): void
    {
        $req = $this->request('afg.afrovanguard.org.ng', 'http', ['X-Forwarded-Proto' => 'https']);

        $_ENV['TRUST_PROXY'] = 'false';
        $this->assertSame('http://afg.afrovanguard.org.ng', SiteUrl::base($req),
            'untrusted: a client-chosen scheme must be ignored');

        $_ENV['TRUST_PROXY'] = 'true';
        $this->assertSame('https://afg.afrovanguard.org.ng', SiteUrl::base($req),
            'trusted: the proxy terminated TLS, so https is the real scheme');

        unset($_ENV['TRUST_PROXY']);
    }

    public function test_a_bogus_forwarded_scheme_is_ignored(): void
    {
        $_ENV['TRUST_PROXY'] = 'true';
        $req = $this->request('afg.afrovanguard.org.ng', 'https', ['X-Forwarded-Proto' => 'javascript']);
        $this->assertSame('https://afg.afrovanguard.org.ng', SiteUrl::base($req));
        unset($_ENV['TRUST_PROXY']);
    }

    // ── No controller may keep its own copy ─────────────────────────────────

    /**
     * Four independent copies of the same expression is how the bug survived: fixing one
     * checkout path would have left the other three broken, with no test able to tell.
     */
    public function test_no_controller_reimplements_the_base_url(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Controllers/*.php') ?: [] as $file) {
            $src = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', (string) file_get_contents($file));
            if (preg_match("~Env::get\(\s*'APP_URL'~", $src) === 1) {
                $offenders[] = basename($file);
            }
        }
        sort($offenders);
        $this->assertSame([], $offenders,
            "these build the base URL themselves instead of using Support\\SiteUrl, so a "
            . "blank APP_URL produces a relative gateway callback:\n  " . implode("\n  ", $offenders));
    }
}
