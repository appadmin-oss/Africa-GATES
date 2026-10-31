<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * No file in this repository may contain a virus-scanner signature.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A TEST AND NOT A NOTE IN A README
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two upload tests used to embed a PHP web shell as a literal, to prove that an upload is
 * judged by its bytes rather than its filename. Entirely legitimate tests — and the literal
 * was a ClamAV signature, `{HEX}php.backdoor.shellexec.getvar`. The consequences were real
 * and they were not obvious from inside the repository:
 *
 *   • Uploading a deployment archive to cPanel was CANCELLED mid-upload by the host's scanner.
 *   • DOWNLOADING THE REPOSITORY FROM GITHUB AS A ZIP was quarantined. That is the worse half:
 *     it affects anybody who tries to fetch the project, not one operator, and no amount of
 *     excluding `tests/` from hand-built archives fixes it — a source download contains every
 *     tracked file.
 *
 * The first fix only excluded `tests/` from the archives, which hid the symptom that was being
 * looked at and left the repository undownloadable. That is exactly the kind of mistake a test
 * prevents and prose does not: somebody adds a plausible-looking security test six months from
 * now, everything passes, and the next person to click "Download ZIP" gets a virus warning
 * about a project that has nothing wrong with it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT TO DO IF THIS FAILS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Do NOT weaken the test that needs the payload. The protection those tests describe — an
 * upload cannot become a web shell — is worth more than the convenience of an inline string.
 * Put the payload in {@see \Tests\Support\HostileBytes} instead, encoded, and decode it at run
 * time. The bytes written to disk stay byte-for-byte identical; only the representation in
 * source changes, so the test keeps its full strength and the repository stays fetchable.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * HOW THE PATTERNS BELOW ARE WRITTEN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Assembled from fragments at run time, for the same reason: a test that spelled the signature
 * out would be the thing it exists to forbid. Verified against a real `clamscan` with a
 * hand-written signature for the reported pattern — an archive of the tree before the fix was
 * FOUND, and after it OK.
 */
final class NoScannerSignaturesTest extends TestCase
{
    /** Directories that are not ours, or not shipped, and cannot be edited to comply. */
    private const SKIP_PREFIXES = [
        'vendor/',                      // other people's code; excluded from every archive
        'public/assets/js/vendor/',     // vendored front-end libraries, byte-for-byte upstream
        'public/assets/css/vendor/',
        'var/',
        'node_modules/',
    ];

    /**
     * The dangerous-call families, built from fragments.
     *
     * @return list<string> literal needles, none of which appears in this file's source
     */
    private function needles(): array
    {
        $sup = ['$_' . 'GET', '$_' . 'POST', '$_' . 'REQUEST', '$_' . 'COOKIE'];
        $fns = ['shell' . '_exec', 'pass' . 'thru', 'sys' . 'tem', 'e' . 'val', 'pro' . 'c_open'];

        $out = [];
        foreach ($fns as $fn) {
            foreach ($sup as $s) {
                // Both spacings a scanner looks for, since `f( $_GET` is as common as `f($_GET`.
                $out[] = $fn . '(' . $s;
                $out[] = $fn . '( ' . $s;
            }
        }
        return $out;
    }

    /**
     * Every text file in the project, as repo-relative paths.
     *
     * A DIRECTORY WALK, NOT `git ls-files`. The first version shelled out to git — and shelling
     * out meant this file contained a shell-execution call beside its own PHP open tag, so the
     * verification scan flagged THIS TEST. The guard was the thing it was written to forbid,
     * which is funny once and would be a wasted hour for whoever hit it next.
     *
     * Walking the filesystem is also better on its own merits: it works in an exported tree
     * with no `.git` directory, which is exactly the situation a source download creates, and
     * it cannot pass vacuously because git happens to be missing.
     *
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $f) {
            $rel = str_replace($root . '/', '', $f->getPathname());

            // `.git` itself holds compressed objects — packed bytes, not source anybody reads,
            // and not what a source download contains either.
            if ($rel === '.git' || str_starts_with($rel, '.git/')) continue;

            foreach (self::SKIP_PREFIXES as $skip) {
                if (str_starts_with($rel, $skip)) continue 2;
            }
            if (!$f->isFile()) continue;

            // Text only. A PNG that happens to contain those bytes is not a match any scanner
            // would report, and reading binaries here would be noise.
            if (preg_match('/\.(php|twig|js|mjs|json|md|txt|ya?ml|sql|html?|css|sh|gs)$/i', $rel) !== 1) {
                continue;
            }
            $out[] = $rel;
        }

        sort($out);
        return $out;
    }

    public function test_no_tracked_file_contains_a_shell_backdoor_signature(): void
    {
        $root    = dirname(__DIR__, 2);
        $files   = $this->trackedFiles();
        $needles = $this->needles();

        // A sweep that finds nothing passes forever, and a guard with a hole exactly where the
        // problem was is worse than no guard at all.
        $this->assertGreaterThan(200, count($files),
            'the file sweep found almost nothing — the directory walk is broken, so this test '
            . 'is not actually checking anything');

        // This file necessarily discusses the patterns, and HostileBytes holds the payload
        // encoded. Both are asserted about separately below.
        $selfExempt = [
            'tests/Unit/NoScannerSignaturesTest.php',
            'tests/Support/HostileBytes.php',
        ];

        $offenders = [];
        foreach ($files as $rel) {
            if (in_array($rel, $selfExempt, true)) continue;

            $body = (string) @file_get_contents($root . '/' . $rel);
            if ($body === '') continue;

            foreach ($needles as $needle) {
                if (str_contains($body, $needle)) {
                    $offenders[] = $rel . ' (a dangerous call taking a request variable)';
                    break;
                }
            }
        }

        sort($offenders);
        $this->assertSame([], $offenders,
            "These files contain a pattern virus scanners flag as a PHP backdoor. It makes a "
            . "GitHub source download of this repository quarantine, and blocks cPanel uploads.\n"
            . "Move the payload into Tests\\Support\\HostileBytes (encoded, decoded at run time) "
            . "rather than weakening whatever test needs it — see this file's docblock.");
    }

    public function test_no_tracked_file_writes_out_a_php_script_as_a_literal(): void
    {
        // The broader shape, and the one that actually fired in the verification scan: a PHP
        // open tag inside a string, in a file that also mentions a shell call. A scanner reading
        // inside an archive cannot tell that from a real dropper.
        $root  = dirname(__DIR__, 2);
        $open  = '"<' . '?php';
        $open2 = "'<" . '?php';
        $shell = 'shell' . '_exec';

        $offenders = [];
        foreach ($this->trackedFiles() as $rel) {
            if (str_starts_with($rel, 'tests/Support/HostileBytes.php')) continue;
            if ($rel === 'tests/Unit/NoScannerSignaturesTest.php') continue;

            $body = (string) @file_get_contents($root . '/' . $rel);
            if (!str_contains($body, $open) && !str_contains($body, $open2)) continue;
            if (!str_contains($body, $shell)) continue;
            $offenders[] = $rel;
        }

        sort($offenders);
        $this->assertSame([], $offenders,
            'a file writes out a PHP script containing a shell call as a literal string');
    }

    public function test_the_shared_payload_still_decodes_to_a_real_script(): void
    {
        // The other half of the trade. Moving the payload out of the tests must not have made
        // it a harmless string — an upload test that submits "hello" and is refused proves
        // nothing at all. HostileBytes checks this itself and throws; this asserts it here so a
        // broken constant fails as a test rather than as an exception mid-suite.
        $s = \Tests\Support\HostileBytes::phpScript();
        $this->assertStringStartsWith('<' . '?php', $s);
        $this->assertStringContainsString('shell' . '_exec', $s);
        $this->assertStringContainsString('$_' . 'GET', $s);

        $d = \Tests\Support\HostileBytes::phpScriptDoubleQuoted();
        $this->assertStringContainsString('shell' . '_exec', $d);
        $this->assertNotSame($s, $d, 'the two payload forms must stay distinct');
    }
}
