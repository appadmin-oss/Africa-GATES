<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Csp;
use Tests\TestCase;

/**
 * The .htaccess policy and Csp::staticPolicy() are one value in two files.
 *
 * WHY THE VALUE IS IN .htaccess AT ALL. The host injects its own
 * Content-Security-Policy on every response for this cPanel account —
 * `script-src 'self' 'unsafe-inline' 'unsafe-eval'` with no host list and
 * `form-action 'self'` with no gateways. Removing it needs `Header always unset`,
 * which also drops PHP's policy (mod_headers runs after the content handler), so the
 * value has to be restated on disk. A file cannot hold a per-request nonce, hence a
 * separate nonce-free variant rather than reusing policy().
 *
 * WHY A TEST. Two copies of a long string in different languages, one of which is
 * only exercised on a production Apache, is precisely the pairing that drifts — and
 * the drift is invisible: the site keeps serving A WORKING page with a policy that
 * quietly no longer matches what the templates load. SecurityHeadersTest already does
 * this for the six shared headers, for the same reason and after the same kind of
 * incident. This is the same guard for the CSP.
 */
class CspStaticFallbackTest extends TestCase
{
    private function htaccess(): string
    {
        $path = dirname(__DIR__, 2) . '/public/.htaccess';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /** The exact policy string Apache sends, pulled out of the directive. */
    private function policyInHtaccess(): string
    {
        $lines = preg_grep(
            '/^\s*Header\s+always\s+set\s+Content-Security-Policy\s+"/i',
            preg_split('/\R/', $this->htaccess()) ?: []
        );
        $this->assertCount(
            1,
            $lines,
            'Expected exactly one active `Header always set Content-Security-Policy` in '
            . 'public/.htaccess. Two would mean the second silently wins; none would mean '
            . 'the host-injected policy is back in force.'
        );
        $line = trim((string) reset($lines));
        $this->assertSame(1, preg_match('/"(.*)"\s*$/', $line, $m), 'Could not parse the quoted policy value.');
        return $m[1];
    }

    public function test_the_htaccess_policy_is_exactly_the_static_policy(): void
    {
        $this->assertSame(
            Csp::staticPolicy(),
            $this->policyInHtaccess(),
            'public/.htaccess and Csp::staticPolicy() have drifted. Regenerate the directive: '
            . 'php -r \'require "vendor/autoload.php"; echo AfricaGates\\Support\\Csp::staticPolicy();\''
        );
    }

    /** The unset must be there, or the injected header is simply appended to. */
    public function test_the_injected_header_is_unset_first(): void
    {
        $h = $this->htaccess();
        $this->assertMatchesRegularExpression(
            '/^\s*Header\s+always\s+unset\s+Content-Security-Policy\s*$/mi',
            $h,
            'Without `Header always unset`, the host-injected CSP stays on the response '
            . 'alongside ours — and multiple CSP headers are enforced as their INTERSECTION, '
            . 'so the injected one still wins every directive it narrows.'
        );
        $unsetAt = strpos($h, 'Header always unset Content-Security-Policy');
        $setAt   = strpos($h, 'Header always set Content-Security-Policy');
        $this->assertNotFalse($unsetAt);
        $this->assertNotFalse($setAt);
        $this->assertLessThan($setAt, $unsetAt, 'The unset must come BEFORE the set or it removes our own value.');
    }

    /**
     * The whole point of the exercise: the two directives that were broken in
     * production must be right in the value that actually ships.
     */
    public function test_the_static_policy_carries_the_hosts_production_was_missing(): void
    {
        $p = Csp::staticPolicy();

        preg_match('/form-action[^;]*/', $p, $fa);
        $this->assertStringContainsString('paystack.com', $fa[0] ?? '', 'form-action without the gateways refuses every paid vote.');
        $this->assertStringContainsString('flutterwave.com', $fa[0] ?? '');

        preg_match('/script-src[^;]*/', $p, $ss);
        foreach (['cdn.jsdelivr.net', 'unpkg.com', 'code.jquery.com', 'challenges.cloudflare.com'] as $host) {
            $this->assertStringContainsString($host, $ss[0] ?? '', "script-src is missing $host — the CDN blocks come back.");
        }
    }

    /**
     * It is nonce-free BY DESIGN — a file on disk cannot carry a per-request value.
     * A nonce here would be a fixed, public string, which is worse than none: it would
     * also make browsers ignore 'unsafe-inline' and kill every inline script.
     */
    public function test_the_static_policy_contains_no_nonce(): void
    {
        $this->assertStringNotContainsString('nonce-', Csp::staticPolicy());
        $this->assertStringContainsString("'unsafe-inline'", Csp::staticPolicy());
    }

    /** The nonce policy is untouched — this is a fallback, not a replacement. */
    public function test_the_real_policy_still_uses_a_nonce(): void
    {
        $this->assertStringContainsString('nonce-', Csp::policy());
    }
}
