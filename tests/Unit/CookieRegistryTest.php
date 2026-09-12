<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CookiePrefs;
use AfricaGates\Support\CookieRegistry;
use Tests\TestCase;

/**
 * Is anything storing something on a visitor's device that the policy does not name?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT THIS SWEEP EXISTS BECAUSE OF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The published cookie policy said, in bold, "We set ONE cookie", and listed `PHPSESSID`
 * in a one-row table. There were three. `ag_region` and `ag_currency` are written by
 * `document.cookie` from the shop's region and currency selects and last a year; they were
 * added long after the policy was written and nothing connected the two events.
 *
 * The failure mode is FORGETTING, so a corrected list is not a fix — the next cookie would
 * do the same thing. This is the fix: a cookie or storage key that appears in the shipped
 * code and not in {@see CookieRegistry} fails the build, by name.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT HAD TO LEARN, AND WHAT IT CANNOT SEE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Storage keys are frequently per-item — `afg_voted_prog_12`, `ag-celebrated:nominee-88`,
 * `coi_declared_{{ programme.id }}` — so the registry declares PREFIXES and a swept key
 * matches if it starts with one. Matching on equality reported four false findings on the
 * first run, every one of them a key that is on the published list.
 *
 * It reads `vendor/` for nothing. Plyr and Lucide both touch storage, and neither is this
 * platform storing anything: a library's own key inside a player the page never
 * instantiates is not something a visitor is owed a policy entry for. The directory is
 * excluded by path, and named here so the exclusion is a decision rather than an accident.
 */
final class CookieRegistryTest extends TestCase
{
    /** Files a visitor's browser actually executes. */
    private function shippedFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        foreach ([$root . '/templates', $root . '/public/assets/js', $root . '/src'] as $dir) {
            if (!is_dir($dir)) continue;

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $path = $f->getPathname();
                // See the class docblock: a library's own storage is not ours to publish.
                if (str_contains($path, '/vendor/')) continue;
                if (!preg_match('/\.(twig|js|php)$/', $path)) continue;

                $out[$path] = (string) file_get_contents($path);
            }
        }

        return $out;
    }

    public function test_every_cookie_the_shipped_code_writes_is_on_the_published_list(): void
    {
        $declared = array_map('strtolower', CookieRegistry::names());
        $found    = [];

        foreach ($this->shippedFiles() as $path => $body) {
            // `document.cookie = 'name=…'` — a literal written straight into the string.
            if (preg_match_all('/document\.cookie\s*=\s*[\'"]([A-Za-z0-9_.-]+)=/', $body, $m)) {
                foreach ($m[1] as $n) $found[strtolower($n)] = $path;
            }

            // The delegated form this codebase actually uses: the name travels in a
            // `data-cookie` attribute and the writer is one generic listener in the
            // layout. A sweep that only understood the literal form would have found
            // NOTHING, which is precisely how these two went unpublished for so long.
            if (preg_match_all('/data-cookie\s*=\s*"([A-Za-z0-9_.-]+)"/', $body, $m)) {
                foreach ($m[1] as $n) $found[strtolower($n)] = $path;
            }

            // PHP's own writer. Nothing uses it today; it is swept so that the day
            // something does, the policy learns about it on the same commit.
            if (preg_match_all('/setcookie\s*\(\s*[\'"]([A-Za-z0-9_.-]+)[\'"]/', $body, $m)) {
                foreach ($m[1] as $n) $found[strtolower($n)] = $path;
            }
        }

        $this->assertNotSame([], $found,
            'the sweep found no cookie writer at all — the patterns have stopped matching, '
            . 'which is the state in which this test passes while proving nothing');

        foreach ($found as $name => $path) {
            $this->assertContains($name, $declared,
                "'{$name}' is set in " . basename($path) . " and is not in CookieRegistry, so "
                . 'the published cookie policy does not mention it');
        }
    }

    public function test_every_browser_storage_key_the_shipped_code_writes_is_published(): void
    {
        $prefixes = array_map(
            static fn (array $s): string => strtolower((string) $s['key']),
            CookieRegistry::storage()
        );

        $found = [];
        foreach ($this->shippedFiles() as $path => $body) {
            if (!preg_match_all(
                '/(?:local|session)Storage\.(?:set|get|remove)Item\s*\(\s*[\'"]([^\'"]+)/',
                $body, $m
            )) continue;

            foreach ($m[1] as $k) {
                // `afg_voted_prog_{{ nominee.programme_id }}` — the Twig half is not part
                // of the name a reader needs, and cutting at the interpolation is what
                // makes the prefix comparison below meaningful.
                $k = strtolower((string) preg_replace('/\{\{.*$/', '', $k));
                if (trim($k) === '') continue;
                $found[$k] = $path;
            }
        }

        $this->assertNotSame([], $found, 'the storage sweep matched nothing');

        foreach ($found as $key => $path) {
            $ok = false;
            foreach ($prefixes as $p) {
                if (str_starts_with($key, $p)) { $ok = true; break; }
            }

            $this->assertTrue($ok,
                "browser storage key '{$key}' is written in " . basename($path)
                . ' and no CookieRegistry::storage() entry covers it');
        }
    }

    public function test_the_sweep_names_a_cookie_nobody_declared(): void
    {
        // ── PROVING THE SWEEP FAILS ──────────────────────────────────────────
        //
        // Every sweep in this repository exists because something shipped, and several of
        // them passed straight over the thing they were written for. So the matcher is run
        // here against a file that offends, and it has to find it. Without this, a regex
        // that quietly stopped matching would leave a green test and no coverage — which
        // is the state the policy was already in.
        $offending = <<<'JS'
        document.cookie = 'ag_experiment=' + v + ';path=/';
        JS;

        preg_match_all('/document\.cookie\s*=\s*[\'"]([A-Za-z0-9_.-]+)=/', $offending, $m);

        $this->assertSame(['ag_experiment'], $m[1],
            'the matcher no longer recognises a plain document.cookie write');
        $this->assertNotContains('ag_experiment', array_map('strtolower', CookieRegistry::names()));

        // And the delegated form, which is the one that actually got past everybody.
        preg_match_all('/data-cookie\s*=\s*"([A-Za-z0-9_.-]+)"/',
            '<select data-ag-do="set-cookie-reload" data-cookie="ag_theme">', $m2);
        $this->assertSame(['ag_theme'], $m2[1]);
    }

    public function test_the_session_cookie_is_described_from_the_live_configuration(): void
    {
        // Typed, these drift. `public/index.php` sets a seven-day lifetime; the policy used
        // to say "Seven days" in prose beside it and there was nothing joining them.
        $this->assertSame(session_name(), CookieRegistry::sessionName());

        $params = session_get_cookie_params();
        if ((int) ($params['lifetime'] ?? 0) > 0) {
            $days = (int) floor(((int) $params['lifetime']) / 86400);
            $this->assertStringContainsString(
                $days === 7 ? 'Seven days' : (string) $days,
                CookieRegistry::sessionLifetime());
        } else {
            $this->assertStringContainsString('close your browser', CookieRegistry::sessionLifetime());
        }
    }

    public function test_nothing_stored_is_refusable_and_the_policy_may_rely_on_that(): void
    {
        // The load-bearing claim on the page: there is no consent banner because the
        // counting stores nothing extra on the device. If a cookie is ever added in a
        // category a visitor could refuse, that argument stops holding and the page has to
        // change — so this fails then, rather than the page quietly becoming untrue.
        $this->assertFalse(CookieRegistry::anyRefusable(),
            'a refusable cookie now exists; /cookies still argues that none does');

        foreach (CookieRegistry::cookies() as $c) {
            $this->assertContains($c['category'],
                [CookieRegistry::ESSENTIAL, CookieRegistry::PREFERENCE, CookieRegistry::ANALYTICS],
                "'{$c['name']}' has a category no part of the page knows how to draw");
            $this->assertNotSame('', trim((string) $c['purpose']),
                "'{$c['name']}' is published with no explanation of why it exists");
        }
    }

    public function test_the_consent_cookie_is_itself_declared(): void
    {
        // The one that records a refusal. A cookie policy that failed to mention the
        // cookie storing the visitor's answer about cookies would be a good joke and a
        // real omission.
        $this->assertContains(CookiePrefs::COOKIE, CookieRegistry::names());
    }
}
