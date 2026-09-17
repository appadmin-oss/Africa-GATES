<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Third-party <script src> tags must be pinned with Subresource Integrity.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS WORTH A TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Found by loading every page in a real browser and listing which hosts they reached.
 * Ten of twelve executable third-party scripts carried no `integrity` attribute, and
 * {@see \AfricaGates\Support\Csp} names those CDNs in `script-src` — so the Content
 * Security Policy, which is what would otherwise refuse them, explicitly permits them.
 *
 * That combination means a compromise or DNS hijack of jsdelivr, unpkg or plyr.io puts
 * attacker-chosen JavaScript on every page of a platform that takes card payments and
 * publishes award results. SRI is the control for exactly this: the browser refuses a
 * file whose hash does not match, so a swapped file fails closed instead of executing.
 *
 * The tell was the INCONSISTENCY, not the idea. jQuery and Leaflet were already pinned
 * with integrity + crossorigin by whoever added them; the other eight went in without,
 * and nothing anywhere would have said so.
 *
 * ── WHAT THIS TEST DOES AND DOES NOT DO ──────────────────────────────────────
 *
 * It cannot generate the missing hashes — those must be computed from the real files, on
 * a machine that can reach the CDNs, and inventing one would be worse than leaving it
 * off: a wrong hash makes the browser refuse a perfectly good file and silently breaks
 * whatever depended on it.
 *
 * So it does the two things a test can do here:
 *
 *   1. FAILS THE MOMENT A NEW BARE SCRIPT IS ADDED. Anything not already known about has
 *      to arrive pinned. This is the part that matters going forward.
 *   2. KEEPS THE EXISTING DEBT NAMED AND SHRINKING. The known-bare list below is a
 *      baseline, not a permission: removing an entry (by pinning it) makes this test
 *      fail until the entry is deleted from the list too. Debt that cannot be quietly
 *      forgotten is debt somebody eventually pays.
 *
 * ── AND THE TWO THAT GENUINELY CANNOT BE PINNED ──────────────────────────────
 *
 * Turnstile and AdSense are versionless endpoints their vendors update in place; there is
 * no stable hash to pin, and pinning one would break the widget on the vendor's next
 * deploy. They are allowed by name rather than by pattern, so the exemption cannot
 * silently widen to cover a third host.
 */
final class ThirdPartyScriptIntegrityTest extends TestCase
{
    /**
     * Versionless vendor endpoints with no stable hash to pin. Allowed by exact URL
     * prefix, so a new third-party script cannot inherit the exemption.
     *
     * @var list<string>
     */
    private const UNPINNABLE = [
        'https://challenges.cloudflare.com/turnstile/',      // Cloudflare updates in place
        'https://pagead2.googlesyndication.com/pagead/',     // AdSense loader, same
    ];

    /**
     * Fixed-version third-party scripts still shipping WITHOUT integrity.
     *
     * EMPTY, and it should stay that way. It briefly held seven entries, each a real
     * exposure listed so it could not be forgotten; all seven were then vendored out of
     * existence rather than pinned, which is the better fix — an SRI hash makes a
     * compromised file fail closed, but a local file cannot be compromised remotely at
     * all, and cannot go down either.
     *
     * If a genuinely unavoidable one ever appears: pin it (`curl -s URL | openssl dgst
     * -sha384 -binary | openssl base64 -A`) rather than adding a line here.
     *
     * @var list<string>
     */
    private const KNOWN_BARE = [];

    /**
     * Every external script tag in templates/, as "relative/path.twig|url".
     *
     * @return list<array{key:string, file:string, url:string, integrity:bool, crossorigin:bool}>
     */
    private function externalScripts(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            $body = (string) file_get_contents($file->getPathname());
            $rel  = str_replace($root . '/', '', $file->getPathname());

            preg_match_all('~<script\b[^>]*\bsrc\s*=\s*"(https?://[^"]+)"[^>]*>~', $body, $m,
                           PREG_SET_ORDER);
            foreach ($m as $hit) {
                [$tag, $url] = [$hit[0], $hit[1]];
                $out[] = [
                    'key'         => $rel . '|' . $url,
                    'file'        => $rel,
                    'url'         => $url,
                    'integrity'   => str_contains($tag, 'integrity='),
                    'crossorigin' => str_contains($tag, 'crossorigin'),
                ];
            }

            // SCRIPT THAT IS NOT A <script src> TAG.
            //
            // The tag scan above missed three real ones, and the miss is the interesting
            // part: a `<script src>` sweep looks exhaustive and is not. What it skipped
            // was `document.createElement('script'); s.src = 'https://…'` in TWO places
            // (one of them a duplicate of the other, so fixing the partial left the copy
            // in home.twig still calling out) and `await import('https://…/+esm')`.
            //
            // Neither form can carry SRI at all — there is no integrity attribute on an
            // assignment, and dynamic import() has no integrity option — so for these the
            // only fix available is to not use a third party. They are therefore reported
            // as unpinnable-and-unpinned, which is exactly what they are.
            preg_match_all('~\.src\s*=\s*[\'"](https?://[^\'"]+)[\'"]~', $body, $dyn, PREG_SET_ORDER);
            preg_match_all('~\bimport\s*\(\s*[\'"](https?://[^\'"]+)[\'"]~', $body, $imp, PREG_SET_ORDER);
            foreach ([...$dyn, ...$imp] as $hit) {
                $out[] = [
                    'key'         => $rel . '|' . $hit[1],
                    'file'        => $rel,
                    'url'         => $hit[1],
                    'integrity'   => false,   // impossible in this form, by construction
                    'crossorigin' => false,
                ];
            }
        }

        usort($out, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));
        return $out;
    }

    private function isUnpinnable(string $url): bool
    {
        foreach (self::UNPINNABLE as $prefix) {
            if (str_starts_with($url, $prefix)) return true;
        }
        return false;
    }

    /**
     * Sanity: the scanner still sees the externals we know are there.
     *
     * By NAME rather than by count. A count is the wrong guard for a shrinking list — it
     * started at "at least 8", the vendoring took the real number to 4, and a threshold
     * that has to be edited every time the thing it measures improves is a threshold that
     * gets edited without being thought about. Naming the survivors means a broken regex
     * fails loudly while legitimate removals just require deleting a line.
     */
    public function test_the_scanner_still_sees_the_known_remaining_externals(): void
    {
        $urls = array_column($this->externalScripts(), 'url');

        foreach ([
            // code.jquery.com used to be asserted here. It is deliberately gone: jQuery was
            // loaded on every page and called by NOTHING — main.js and the extension each
            // define their own local `$` as querySelector, which is what made it look used.
            // The site paid a cross-origin round trip and 70KB per page load for dead code.
            'https://unpkg.com/leaflet@',          // Leaflet, pinned
            'https://challenges.cloudflare.com/',  // Turnstile, unpinnable
            'https://pagead2.googlesyndication.com/', // AdSense, unpinnable
        ] as $expected) {
            $this->assertNotEmpty(
                array_filter($urls, static fn(string $u): bool => str_starts_with($u, $expected)),
                "The scanner no longer sees {$expected} — either it was removed (delete this "
                . 'line) or the regex is broken and every assertion below passes vacuously.');
        }
    }

    /**
     * No NEW bare third-party script. This is the guard that matters.
     */
    public function test_no_unpinned_third_party_script_beyond_the_known_baseline(): void
    {
        $offenders = [];
        foreach ($this->externalScripts() as $s) {
            if ($s['integrity'] || $this->isUnpinnable($s['url'])) continue;
            if (in_array($s['key'], self::KNOWN_BARE, true)) continue;
            $offenders[] = $s['key'];
        }

        $this->assertSame([], $offenders,
            "A third-party <script> is being loaded with no Subresource Integrity.\n\n"
            . "Because Csp.php lists these CDNs in script-src, the browser will run whatever\n"
            . "they serve — so a compromised CDN executes on every page.\n\n"
            . "Add integrity=\"sha384-…\" crossorigin=\"anonymous\" (compute with:\n"
            . "  curl -s URL | openssl dgst -sha384 -binary | openssl base64 -A),\n"
            . "or vendor the file under public/assets/js/vendor and serve it ourselves.\n\n"
            . "Offending tag(s):\n  " . implode("\n  ", $offenders));
    }

    /**
     * The baseline may only SHRINK.
     *
     * Without this the list would rot into a comment: somebody pins a script, the entry
     * stays, and the next reader cannot tell which lines are real any more.
     */
    public function test_the_known_bare_baseline_has_no_stale_entries(): void
    {
        $bare = [];
        foreach ($this->externalScripts() as $s) {
            if (!$s['integrity'] && !$this->isUnpinnable($s['url'])) $bare[] = $s['key'];
        }

        $stale = array_values(array_diff(self::KNOWN_BARE, $bare));
        $this->assertSame([], $stale,
            "These entries in KNOWN_BARE are fixed (or gone) — delete them from the list "
            . "so it keeps telling the truth:\n  " . implode("\n  ", $stale));
    }

    /**
     * A pinned script must also be able to pass the check it declares.
     *
     * SRI on a cross-origin file requires CORS, so `integrity` without `crossorigin` makes
     * the browser refuse the file outright — a pin that looks stricter and is actually an
     * outage. Worth asserting precisely because it fails in the safe-looking direction.
     */
    public function test_every_pinned_script_also_sets_crossorigin(): void
    {
        $broken = [];
        foreach ($this->externalScripts() as $s) {
            if ($s['integrity'] && !$s['crossorigin']) $broken[] = $s['key'];
        }

        $this->assertSame([], $broken,
            "integrity= without crossorigin= makes the browser REFUSE a cross-origin file, "
            . "so the script never runs:\n  " . implode("\n  ", $broken));
    }

    /**
     * The judge panel serves its own framework.
     *
     * Called out on its own because of what that page is: judges enter the scores that are
     * 55% of every nominee's CPI. Alpine used to come from cdn.jsdelivr.net, unpinned,
     * while the identical 3.13.5 build was already on our disk and being used by the
     * public layout — so an unreachable CDN stopped judging outright and a compromised one
     * would have run on the page that decides the awards.
     */
    public function test_the_judge_panel_does_not_fetch_its_framework_from_a_cdn(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/judge/layout.twig');

        $this->assertStringNotContainsString('alpinejs@', $layout,
            'The judging panel must not load Alpine from a third party — vendor it.');
        $this->assertMatchesRegularExpression('~vendor/alpine-3\.13\.5\.min\.js~', $layout,
            'The judging panel should load the vendored Alpine the public layout uses.');

        $vendored = dirname(__DIR__, 2) . '/public/assets/js/vendor/alpine-3.13.5.min.js';
        $this->assertFileExists($vendored, 'The vendored Alpine build must actually be present.');
        $this->assertStringContainsString('3.13.5', (string) file_get_contents($vendored),
            'The vendored file should be the version the template names.');
    }
}
