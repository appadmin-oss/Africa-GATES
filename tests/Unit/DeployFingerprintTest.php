<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Build;
use AfricaGates\Support\Csp;
use Tests\TestCase;

/**
 * "Is the code I edited the code that is running?"
 *
 * Production has twice been serving code that predates this repository. The proof is in
 * docs/VOTING-NOMINATIONS-STATE-AUDIT.md: `Csp::policy()` was edited ON THE SERVER with
 * a deliberate syntax error and the site kept returning HTTP 200 with the old header. A
 * syntax error in a loaded file is a fatal, not a no-op — so PHP was not loading it.
 *
 * Every CSP refusal reported from production since is that one problem: CDN scripts and
 * stylesheets blocked because the running `script-src`/`style-src` carry no host
 * allowlist, and EVERY PAID VOTE refused by a `form-action` with no gateway hosts. All
 * of it is fixed in this tree. None of it was deployed.
 *
 * It was expensive because it was invisible: the symptoms all read as application bugs,
 * and there was no way to ask the question from a browser. These tests pin the two
 * mechanisms that now answer it — the `/ping` fingerprint (from anywhere, no shell) and
 * `app:doctor`'s live-header comparison (on the server) — and, most importantly, pin
 * that the comparison does not produce a FALSE alarm, because a diagnostic that always
 * fires is one people learn to ignore.
 */
class DeployFingerprintTest extends TestCase
{
    // ── The /ping fingerprint ───────────────────────────────────────────────

    public function test_the_fingerprint_reports_whether_the_policy_is_nonce_based(): void
    {
        $f = Build::fingerprint();

        // The single most diagnostic bit on the endpoint. False in production means the
        // running code predates the CSP rewrite, whatever the repository says.
        $this->assertTrue($f['csp_nonce'],
            'this tree emits a nonce-based policy, so its own fingerprint must say so');
        $this->assertNotSame('absent', $f['csp']);
    }

    public function test_the_fingerprint_carries_a_revision_and_a_root_hash(): void
    {
        $f = Build::fingerprint();

        $this->assertNotSame('', $f['rev']);
        // The root is hashed, not printed: a changed hash is the whole diagnostic value
        // (did DocumentRoot move?), and an absolute filesystem path on a public endpoint
        // is free reconnaissance for no gain.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $f['root']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $f['csp']);
    }

    public function test_the_fingerprint_leaks_nothing(): void
    {
        $f = Build::fingerprint();
        $blob = json_encode($f);

        // No absolute paths, no nonce VALUE, no allowlist. /ping is unauthenticated.
        $this->assertStringNotContainsString(dirname(__DIR__, 2), (string) $blob);
        // A nonce VALUE, not the substring "nonce-". The bare substring matched
        // Build::REV itself — the label is "…-csp-nonce-assets" — so the assertion failed
        // on a payload containing no nonce at all. Fourth time in this branch that a
        // guard has read its own documentation or labelling as the thing it forbids; the
        // lesson is the same each time, which is that the pattern has to describe the
        // hazard rather than mention it.
        $this->assertDoesNotMatchRegularExpression(
            "~'?nonce-[A-Za-z0-9+/]{16,}~", (string) $blob,
            'the fingerprint must never carry a real nonce'
        );
        $this->assertStringNotContainsString('paystack', strtolower((string) $blob));
    }

    public function test_ping_exposes_the_fingerprint_and_is_never_cached(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        $this->assertStringContainsString('Build::fingerprint()', $routes,
            '/ping must carry the deployment facts — it is the only way to ask from a browser');
        // A cached health check answers the PREVIOUS deployment's question, which is
        // precisely the failure this endpoint exists to detect.
        $this->assertMatchesRegularExpression(
            "~/ping.*?Cache-Control.*?no-store~s",
            $routes,
            '/ping must be no-store'
        );
    }

    // ── The live-header comparison, and its false-alarm hazard ──────────────

    /**
     * The nonce is per-request BY DESIGN, so the CLI process and the HTTP response can
     * never share one. The first version of the comparison did a byte equality check and
     * therefore reported MISMATCH against a perfectly healthy deployment — a diagnostic
     * that always fires is worse than none, and this is the one that most needs trusting.
     *
     * Exercised through the command's real normaliser via reflection, because the whole
     * point is that this specific transformation happens before the comparison.
     */
    public function test_two_policies_differing_only_by_nonce_are_treated_as_identical(): void
    {
        $normalise = new \ReflectionMethod(
            \AfricaGates\Console\Commands\DoctorCommand::class, 'normalise'
        );
        $normalise->setAccessible(true);

        // Two real policies from two different requests.
        $a = Csp::policy();
        \Closure::bind(static function () { /* force a fresh nonce */ }, null, Csp::class);
        $b = str_replace(
            substr($a, strpos($a, "'nonce-") + 7, 24),
            'AAAAAAAAAAAAAAAAAAAAAA==',
            $a
        );

        $this->assertNotSame($a, $b, 'precondition: the two differ by their nonce');
        $this->assertSame($normalise->invoke(null, $a), $normalise->invoke(null, $b),
            'a differing nonce must NOT read as a stale deployment');
    }

    /**
     * And it must still catch the policy production is actually serving — verbatim from
     * the console report. No nonce, no host allowlist, no `style-src-elem`, and a
     * `form-action` with no gateways, which is what blocks every paid vote.
     */
    public function test_the_policy_production_actually_serves_is_detected_as_stale(): void
    {
        $normalise = new \ReflectionMethod(
            \AfricaGates\Console\Commands\DoctorCommand::class, 'normalise'
        );
        $normalise->setAccessible(true);

        $live = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
              . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
              . "form-action 'self'; object-src 'none'";

        $this->assertNotSame(
            $normalise->invoke(null, $live),
            $normalise->invoke(null, Csp::policy()),
            'the stale production policy must not normalise to this code\'s policy'
        );
        // And the giveaway the report keys on.
        $this->assertStringNotContainsString("'nonce-", $live);
    }

    /** Every refusal in the report is permitted by THIS tree's policy. */
    public function test_this_trees_policy_permits_everything_production_refused(): void
    {
        $policy = Csp::policy();

        // Scripts that are STILL fetched from a third party. jsdelivr and plyr.io were in
        // this list and are gone on purpose: the resources the production report named are
        // served out of public/assets now, so the fix for those particular refusals is
        // that the request no longer happens at all — strictly better than permitting the
        // host. Their actual disappearance is asserted in CspHostCoverageTest.
        foreach (['https://code.jquery.com', 'https://unpkg.com',
                  'https://challenges.cloudflare.com'] as $host) {
            $this->assertStringContainsString($host, $policy, "script host {$host}");
        }
        // The paid-vote form. Chrome applies form-action to the REDIRECT a submission
        // lands on, so a policy without the gateways blocks every paid vote after the
        // pending order row already exists — and blames the same-origin URL.
        $this->assertMatchesRegularExpression('~form-action[^;]*paystack~', $policy);
        $this->assertMatchesRegularExpression('~form-action[^;]*flutterwave~', $policy);
        // style-src-elem explicitly set, so the browser stops falling back to style-src
        // (which is what every stylesheet refusal in the report noted).
        $this->assertStringContainsString('style-src-elem', $policy);
    }

    // ── Removing the dependency rather than allowlisting it ─────────────────

    /**
     * Popper and Tippy are served from this origin.
     *
     * Both files were committed under public/assets/js/vendor/ all along while the layout
     * loaded them from unpkg — a cross-origin round trip for bytes already on disk, and a
     * `script-src` allowlist entry for a dependency that never needed to be remote.
     * `'self'` is permitted by every policy this site has served, INCLUDING the stale one
     * still live, so these two work regardless of which CSP is in force.
     */
    public function test_vendored_libraries_are_loaded_from_this_origin(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout/gates.twig');
        $root   = dirname(__DIR__, 2) . '/public';

        foreach (['popper-2.11.8.min.js', 'tippy-6.3.7.umd.min.js'] as $file) {
            $this->assertFileExists($root . '/assets/js/vendor/' . $file);
            $this->assertStringContainsString('/assets/js/vendor/' . $file, $layout,
                $file . ' is committed — the layout must not fetch it from a CDN');
        }

        $code = (string) preg_replace('~\{#.*?#\}~s', '', $layout);
        $this->assertStringNotContainsString('unpkg.com/@popperjs', $code);
        $this->assertStringNotContainsString('unpkg.com/tippy.js', $code);
    }

    /**
     * No template may load a script from an UNPINNED CDN URL.
     *
     * `unpkg.com/tippy.js@6` resolves to whatever that major currently points at, which
     * is a third party silently changing code this site executes — a supply-chain
     * exposure that no CSP host allowlist addresses, because the host is allowed.
     */
    public function test_remaining_cdn_scripts_are_version_pinned(): void
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $unpinned = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            $body = (string) preg_replace('~\{#.*?#\}~s', '', (string) file_get_contents($file->getPathname()));

            // `href` as well as `src`: an unpinned CDN STYLESHEET is the same exposure —
            // CSS can load fonts and background images and reposition anything on the page.
            if (!preg_match_all('~(?:src|href)="(https://(?:unpkg\.com|cdn\.jsdelivr\.net)/[^"]+)"~', $body, $m)) continue;
            foreach ($m[1] as $url) {
                // A pinned URL carries a full x.y.z. `@2` or `@6` alone does not.
                if (preg_match('~@\d+\.\d+(\.\d+)?~', $url) !== 1) {
                    $unpinned[] = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname()) . ': ' . $url;
                }
            }
        }

        sort($unpinned);
        $this->assertSame([], $unpinned,
            "an unpinned CDN URL lets a third party change the code this site runs:\n  "
            . implode("\n  ", $unpinned));
    }
}
