<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Support\Csp;

/**
 * Every external resource the site loads must be permitted by the directive that
 * actually governs it.
 *
 * WHERE THIS CAME FROM. A production console report: eighteen resources refused on
 * a single nominee ballot page — jQuery, GSAP, ScrollTrigger, split-type, Popper,
 * Tippy, Leaflet, Swiper, Splide, Plyr and the Turnstile widget as scripts; Swiper,
 * Splide, Plyr and Leaflet again as stylesheets — plus the paid-vote form
 * submission itself. The deployed policy was
 * `script-src 'self' 'unsafe-inline' 'unsafe-eval'` with no host list at all, and
 * `form-action 'self'` with no payment gateways.
 *
 * WHY A TEST AND NOT A LONGER ALLOWLIST. An allowlist drifts. A template gains a
 * CDN dependency, the policy is not updated, and the failure is INVISIBLE to the
 * server: the browser refuses the resource, the page half-works, and nothing is
 * logged anywhere the team looks. Every one of the eighteen refusals above is that
 * exact mechanism. So the allowlist is derived from — and checked against — what
 * the templates and scripts actually reference.
 *
 * The `form-action` case is the one worth reading twice. The blocked URL was
 * `https://afg.afrovanguard.org.ng/vote/paid/start` — same origin, apparently
 * satisfying `'self'`. It was refused because Chrome applies `form-action` to the
 * REDIRECT a form submission lands on, and `POST /vote/paid/start` ends in a 302 to
 * the gateway's checkout page. A policy without the gateway hosts therefore blocks
 * every paid vote, and reports it against the same-origin URL, which reads like a
 * browser bug rather than a missing host.
 */
class CspHostCoverageTest extends TestCase
{
    /**
     * The exact resources the production console reported as refused.
     *
     * Kept verbatim as a fixture, in the form `[url, directive]`, because a
     * regression test built from a real incident is worth more than one built from
     * imagination — these are the URLs a visitor's browser actually asked for.
     */
    private const REFUSED_IN_PRODUCTION = [
        ['https://code.jquery.com/jquery-3.7.1.slim.min.js', 'script-src'],
        ['https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js', 'script-src'],
        ['https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js', 'script-src'],
        ['https://cdn.jsdelivr.net/gh/timothydesign/script/split-type.js', 'script-src'],
        ['https://unpkg.com/@popperjs/core@2', 'script-src'],
        ['https://unpkg.com/tippy.js@6', 'script-src'],
        ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', 'script-src'],
        ['https://unpkg.com/swiper@8/swiper-bundle.min.js', 'script-src'],
        ['https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/js/splide.min.js', 'script-src'],
        ['https://cdn.plyr.io/3.7.8/plyr.polyfilled.js', 'script-src'],
        ['https://challenges.cloudflare.com/turnstile/v0/api.js', 'script-src'],
        ['https://unpkg.com/swiper@8/swiper-bundle.min.css', 'style-src-elem'],
        ['https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/css/splide.min.css', 'style-src-elem'],
        ['https://cdn.plyr.io/3.7.8/plyr.css', 'style-src-elem'],
        ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', 'style-src-elem'],
    ];

    /** Directive value as a list of sources, following CSP's fallback chain. */
    private function sources(string $directive): array
    {
        $policy = Csp::policy();
        foreach (explode(';', $policy) as $part) {
            $tokens = preg_split('/\s+/', trim($part)) ?: [];
            if (($tokens[0] ?? '') === $directive) {
                return array_slice($tokens, 1);
            }
        }
        return [];
    }

    /** Would $url be permitted by $directive? Host matching only, incl. `*.` wildcards. */
    private function permits(string $directive, string $url): bool
    {
        $sources = $this->sources($directive);
        // `style-src-elem` and `script-src-elem` fall back to `style-src`/`script-src`
        // when absent, which is precisely the fallback the production report quoted
        // ("Note that 'style-src-elem' was not explicitly set").
        if ($sources === [] && str_contains($directive, '-src-')) {
            $sources = $this->sources(explode('-src-', $directive)[0] . '-src');
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        foreach ($sources as $src) {
            if ($src === 'https:' || $src === '*') return true;
            $srcHost = (string) parse_url($src, PHP_URL_HOST);
            if ($srcHost === '') continue;
            if ($srcHost === $host) return true;
            if (str_starts_with($srcHost, '*.') && str_ends_with($host, substr($srcHost, 1))) return true;
        }
        return false;
    }

    public function test_every_resource_refused_in_production_is_now_permitted(): void
    {
        $stillBlocked = [];
        foreach (self::REFUSED_IN_PRODUCTION as [$url, $directive]) {
            if (!$this->permits($directive, $url)) {
                $stillBlocked[] = "{$directive}: {$url}";
            }
        }

        $this->assertSame([], $stillBlocked,
            'these were refused on a live nominee ballot page and must not be again');
    }

    public function test_the_paid_vote_form_may_redirect_to_the_gateways(): void
    {
        // The revenue path. POST /vote/paid/start validates, writes a pending order,
        // then 302s to the gateway's checkout URL — and Chrome checks form-action
        // against that redirect. Without the gateway hosts, every paid vote is
        // blocked in the browser after the pending row is already written.
        foreach ([
            'https://checkout.paystack.com/abc123',
            'https://api.paystack.co/transaction/initialize',
            'https://checkout.flutterwave.com/v3/hosted/pay/xyz',
            'https://flutterwave.com/pay/xyz',
        ] as $url) {
            $this->assertTrue($this->permits('form-action', $url),
                "form-action must allow the checkout redirect to {$url}");
        }

        // Same-origin posts obviously still work, and an unrelated host does not.
        $this->assertContains("'self'", $this->sources('form-action'));
        $this->assertFalse($this->permits('form-action', 'https://evil.example/collect'));
    }

    public function test_every_script_host_referenced_by_a_template_is_allowed(): void
    {
        $missing = [];
        foreach ($this->referencedHosts('script') as $host => $where) {
            if (!$this->permits('script-src', "https://{$host}/")) {
                $missing[] = "{$host} (in {$where})";
            }
        }
        sort($missing);
        $this->assertSame([], $missing,
            'a <script src> host absent from script-src is refused by the browser with '
            . 'nothing logged server-side — add it to Csp::SCRIPT_HOSTS');
    }

    public function test_every_stylesheet_host_referenced_by_a_template_is_allowed(): void
    {
        $missing = [];
        foreach ($this->referencedHosts('style') as $host => $where) {
            // style-src-elem governs <link rel=stylesheet>; style-src is its fallback
            // for browsers without the CSP3 split, so BOTH must permit the host.
            if (!$this->permits('style-src-elem', "https://{$host}/")
                || !$this->permits('style-src', "https://{$host}/")) {
                $missing[] = "{$host} (in {$where})";
            }
        }
        sort($missing);
        $this->assertSame([], $missing,
            'a stylesheet host must be in Csp::STYLE_HOSTS, which feeds both '
            . 'style-src-elem and its style-src fallback');
    }

    public function test_the_scan_finds_the_hosts_the_templates_really_use(): void
    {
        // Negative control. A scan that matches nothing passes forever — and "the
        // allowlist is complete" is exactly the false comfort this file exists to
        // deny. These hosts are in the templates today.
        $scripts = $this->referencedHosts('script');
        $styles  = $this->referencedHosts('style');

        $this->assertArrayHasKey('unpkg.com', $scripts);
        $this->assertArrayHasKey('cdn.jsdelivr.net', $scripts);
        $this->assertArrayHasKey('challenges.cloudflare.com', $scripts);
        $this->assertArrayHasKey('fonts.googleapis.com', $styles);
        $this->assertGreaterThanOrEqual(4, count($scripts));
        $this->assertGreaterThanOrEqual(3, count($styles));
    }

    public function test_a_host_not_in_the_allowlist_is_actually_refused(): void
    {
        // The other half of the control: `permits()` must be capable of saying no,
        // or every assertion above is vacuous.
        $this->assertFalse($this->permits('script-src', 'https://evil.example/x.js'));
        $this->assertFalse($this->permits('style-src-elem', 'https://evil.example/x.css'));
        $this->assertTrue($this->permits('img-src', 'https://evil.example/x.png'),
            'img-src keeps https: on purpose — nominee photos come from arbitrary hosts');
    }

    /**
     * Hosts referenced as $kind ('script' | 'style') across templates and shipped JS.
     *
     * @return array<string,string> host => first file it was seen in
     */
    private function referencedHosts(string $kind): array
    {
        $patterns = $kind === 'script'
            ? ['~<script\b[^>]*\bsrc\s*=\s*["\'](https://[^"\']+)~i']
            : ['~<link\b[^>]*\bhref\s*=\s*["\'](https://[^"\']+)[^>]*\brel\s*=\s*["\']stylesheet~i',
               '~<link\b[^>]*\brel\s*=\s*["\']stylesheet["\'][^>]*\bhref\s*=\s*["\'](https://[^"\']+)~i'];

        $out = [];
        foreach ($this->sourceFiles() as $rel => $raw) {
            foreach ($patterns as $re) {
                if (!preg_match_all($re, $raw, $m)) continue;
                foreach ($m[1] as $url) {
                    $host = (string) parse_url($url, PHP_URL_HOST);
                    // Twig interpolation inside the URL means the host may be data —
                    // skip it rather than assert about a fixture.
                    if ($host === '' || str_contains($host, '{')) continue;
                    $out[$host] ??= $rel;
                }
            }
        }
        return $out;
    }

    /** @return array<string,string> relative path => contents */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['templates', 'public/assets/js'] as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || !in_array($f->getExtension(), ['twig', 'js'], true)) continue;
                $out[str_replace($root . '/', '', $f->getPathname())] = (string) file_get_contents($f->getPathname());
            }
        }
        ksort($out);
        return $out;
    }
}
