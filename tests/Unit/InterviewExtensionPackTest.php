<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\InterviewExtension;
use Tests\TestCase;

/**
 * The Chrome extension, as a thing an operator can actually obtain and install.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY "IT IS IMPOSSIBLE TO TRIGGER THE EXTENSION" WAS EXACTLY RIGHT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two independent blockers, and each one alone was enough.
 *
 * ── 1 · THE FOLDER COULD NOT BE OBTAINED ────────────────────────────────────
 *
 * The interview screen said "Load unpacked → the extension/ folder from the upload".
 * Nothing served that folder. It sits outside the web root — correctly; a browsable
 * directory of extension source under public/ is one anybody can enumerate — and this host
 * has no SSH. So the instruction named a location the operator had no route to, by URL or
 * otherwise, and there was nothing on any screen to say so.
 *
 * ── 2 · THE HOST WAS HARDCODED IN THREE FILES ───────────────────────────────
 *
 * `manifest.json`'s `host_permissions`, and `DEFAULT_BASE` in `worker.js` and `popup.js`.
 * The manifest one is the dangerous one: `host_permissions` is what makes the service
 * worker's fetch a PRIVILEGED extension request. Pointed at the wrong host, every call to
 * the platform is an ordinary cross-origin fetch, blocked with no CORS headers to satisfy
 * it — and typing the right address into the popup does not help, because the popup only
 * sets `DEFAULT_BASE`, not the permission. The panel then reports "Could not reach the
 * platform" from inside a live interview and nothing in Chrome names the manifest.
 *
 * So the host is injected at download time from the request the admin made, which is by
 * definition the deployment they are configuring.
 */
final class InterviewExtensionPackTest extends TestCase
{
    private const HOST = 'https://gates.example.org';

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('no zip extension on this runtime');
        }
    }

    /** @return array<string,string> every entry in the built zip, by name */
    private function entries(string $base = self::HOST): array
    {
        $pack = InterviewExtension::pack($base);

        // ZipArchive reads from a path, so the bytes go back to disk to be inspected. The
        // assertion is about the BYTES the browser receives, which is why the archive is
        // not read from the temp file pack() used — that file is deliberately gone.
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ag-ext-test-');
        file_put_contents($tmp, $pack['bytes']);

        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($tmp) === true, 'the download is not a readable zip');

            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $out[$name] = (string) $zip->getFromIndex($i);
            }
            $zip->close();
            return $out;
        } finally {
            @unlink($tmp);
        }
    }

    // ── the archive is loadable ──────────────────────────────────────────────

    public function test_every_file_the_extension_needs_is_in_it(): void
    {
        $e = $this->entries();

        foreach (InterviewExtension::FILES as $f) {
            $this->assertArrayHasKey($f, $e, $f . ' is missing from the download');
            $this->assertNotSame('', $e[$f], $f . ' was packed empty');
        }
    }

    public function test_the_manifest_is_at_the_root_and_not_inside_a_folder(): void
    {
        foreach (array_keys($this->entries()) as $name) {
            // Chrome's "Load unpacked" wants the directory CONTAINING manifest.json. An
            // operator who unzips a folder-inside-a-folder picks the outer one, gets
            // "Manifest file is missing or unreadable", and has no way to tell which of the
            // two identical-looking folders it meant.
            $this->assertStringNotContainsString('/', $name,
                'nothing may be nested in a directory: ' . $name);
        }
    }

    public function test_the_manifest_is_still_valid_json_after_the_substitution(): void
    {
        $m = json_decode($this->entries()['manifest.json'], true);

        $this->assertIsArray($m, 'the packed manifest does not parse — Chrome would refuse to load it');
        $this->assertSame(3, $m['manifest_version']);
        $this->assertSame(['storage'], $m['permissions'],
            'the extension asks for storage and the site, and the README promises exactly that');
    }

    // ── the host actually reaches all three places ───────────────────────────

    public function test_the_host_permission_is_this_deployment(): void
    {
        $m = json_decode($this->entries()['manifest.json'], true);

        $this->assertSame([self::HOST . '/*'], $m['host_permissions'],
            'without this host the service worker fetch is a blocked cross-origin request, '
            . 'and no setting in the popup can fix it');
    }

    public function test_both_default_base_constants_are_this_deployment(): void
    {
        $e = $this->entries();

        foreach (['worker.js', 'popup.js'] as $f) {
            $this->assertStringContainsString("'" . self::HOST . "'", $e[$f], $f);
            $this->assertStringNotContainsString(InterviewExtension::SOURCE_HOST, $e[$f],
                $f . ' still points at the host the source was written against');
        }
    }

    public function test_nothing_executable_still_carries_the_committed_host(): void
    {
        foreach ($this->entries() as $name => $body) {
            // README.md is exempt ON PURPOSE: it names the committed host while explaining
            // what happens if you load the repository folder directly, and rewriting it
            // there would turn a true warning into a false one.
            if ($name === 'README.md') continue;

            $this->assertStringNotContainsString(InterviewExtension::SOURCE_HOST, $body,
                $name . ' would send this deployment\'s interviews to another host');
        }
    }

    public function test_the_placeholder_in_the_popup_shows_the_right_site(): void
    {
        // Cosmetic and not: an operator who clears the field to retype it is shown the
        // placeholder, and a placeholder naming somebody else's deployment is what they
        // then type.
        $this->assertStringContainsString(self::HOST, $this->entries()['popup.html']);
    }

    // ── the download says which deployment it is for ─────────────────────────

    public function test_the_download_carries_its_own_install_note(): void
    {
        $e = $this->entries();

        $this->assertArrayHasKey('INSTALL.txt', $e);
        $this->assertStringContainsString(self::HOST, $e['INSTALL.txt'],
            'two deployments produce two zips that otherwise look identical');
        $this->assertStringContainsString('chrome://extensions', $e['INSTALL.txt']);
        $this->assertStringContainsString('CC', $e['INSTALL.txt'],
            'Google needs a person to turn captions on and the note has to say so');
    }

    public function test_the_filename_names_the_host(): void
    {
        $pack = InterviewExtension::pack('https://gates.example.org');

        $this->assertStringContainsString('gates.example.org', $pack['filename']);
        $this->assertStringEndsWith('.zip', $pack['filename']);
        // No path separators or quotes reach the Content-Disposition header.
        $this->assertDoesNotMatchRegularExpression('~[/\\\\"\']~', $pack['filename']);
    }

    // ── the committed source is left alone ───────────────────────────────────

    public function test_packing_does_not_rewrite_the_repository(): void
    {
        $before = (string) file_get_contents(InterviewExtension::dir() . '/manifest.json');
        InterviewExtension::pack(self::HOST);

        $this->assertSame($before, (string) file_get_contents(InterviewExtension::dir() . '/manifest.json'),
            'the source is the source; the substitution happens on the way out');
    }

    public function test_the_source_host_is_the_one_the_files_actually_contain(): void
    {
        // The substitution is anchored to a literal rather than a pattern, on purpose: a
        // regex over https://[^/]+ would rewrite any address in any file, including Google's
        // own URLs. The cost is that the anchor has to stay true, so it is asserted.
        foreach (['manifest.json', 'worker.js', 'popup.js', 'popup.html'] as $f) {
            $this->assertStringContainsString(
                InterviewExtension::SOURCE_HOST,
                (string) file_get_contents(InterviewExtension::dir() . '/' . $f),
                $f . ' no longer contains SOURCE_HOST, so packing it silently changes nothing '
                   . 'and the download would ship pointing at the wrong host'
            );
        }
    }

    // ── refusing rather than guessing ────────────────────────────────────────

    public function test_a_base_that_is_not_a_site_is_refused(): void
    {
        foreach (['', '   ', 'gates.example.org', 'javascript:alert(1)', 'https://', 'ftp://x.org'] as $bad) {
            try {
                InterviewExtension::pack($bad);
                $this->fail('packed an extension against “' . $bad . '”');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('site address', $e->getMessage());
            }
        }
    }

    public function test_a_trailing_slash_does_not_double_up_in_the_permission(): void
    {
        $m = json_decode($this->entries('https://gates.example.org/')['manifest.json'], true);

        // 'https://host//*' is not a valid match pattern, and Chrome rejects the whole
        // manifest for it rather than ignoring the entry.
        $this->assertSame(['https://gates.example.org/*'], $m['host_permissions']);
    }

    public function test_a_port_survives(): void
    {
        // Not academic: this is how anybody reviews the console on a staging box.
        $m = json_decode($this->entries('http://localhost:8080')['manifest.json'], true);

        $this->assertSame(['http://localhost:8080/*'], $m['host_permissions']);
    }

    // ── and it is reachable from the screen that needs it ────────────────────

    public function test_the_interview_screen_offers_the_download(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/interviews/show.twig');

        $this->assertStringContainsString('/admin/interviews/extension.zip', $tpl);

        // The instruction it replaced. If it comes back, the screen is telling an operator
        // to fetch a folder they cannot reach.
        //
        // Twig comments stripped first: the replacement carries a comment that QUOTES the
        // old sentence while explaining why it went, and this assertion matched that. The
        // same mistake TwigBlockScopeTest documents, made again in the test that was
        // written to prevent it.
        $body = (string) preg_replace('~\{#.*?#\}~s', '', $tpl);
        $this->assertStringNotContainsString('folder from the upload', $body);
    }

    public function test_the_route_exists(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        $this->assertStringContainsString("'/extension.zip'", $routes);
        $this->assertStringContainsString(":extension'", $routes);
    }
}
