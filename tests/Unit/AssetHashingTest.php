<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Assets;
use Tests\TestCase;

/**
 * Cache busting that cannot go stale.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS BECAUSE OF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every stylesheet and script was linked as `?v={{ asset_version }}`, and in
 * production that token is the PINNED `ASSET_VERSION` from `.env`. It shipped as
 * `v1`. It is documented as "bumped at deploy". Nothing on this deployment bumps
 * it, because there is no shell and no deploy script.
 *
 * So every asset was `?v=v1` forever. With far-future cache headers that means a
 * returning visitor keeps running last month's JavaScript against this month's
 * HTML — indefinitely, and no amount of deploying changes it. Every fix silently
 * fails to reach the people who already hold the old copy, which is the regulars,
 * while looking perfectly correct to anybody testing in a fresh browser.
 *
 * ── THE FOUR PROPERTIES ──────────────────────────────────────────────────────
 *
 * A content hash is only an improvement if all four hold, and each is tested
 * below against real files on disk rather than a mock:
 *
 *   1. CHANGED CONTENT → NEW URL. Otherwise the fix never ships.
 *   2. UNCHANGED CONTENT → SAME URL. Otherwise every deploy throws away every
 *      cached asset on every device, which is what a deploy-wide token does.
 *   3. A TOUCH IS NOT A CHANGE. `touch` during an upload is routine; an mtime
 *      token treats it as a new file and re-downloads bytes nobody edited.
 *   4. IT NEVER THROWS. A template calls this for every asset on the page, so an
 *      exception here is a blank site. Unhashable input degrades to the old token.
 */
final class AssetHashingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // A real public/ tree, because the thing under test is filesystem
        // behaviour — realpath containment, hash_file, missing files.
        $this->dir = sys_get_temp_dir() . '/ag-assets-' . bin2hex(random_bytes(6));
        @mkdir($this->dir . '/assets/js', 0775, true);
        file_put_contents($this->dir . '/assets/js/app.js', "console.log('one');\n");
        file_put_contents($this->dir . '/assets/js/other.js', "console.log('other');\n");
        Assets::configure($this->dir, 'v1');
    }

    protected function tearDown(): void
    {
        foreach (['/assets/js/app.js', '/assets/js/other.js'] as $f) @unlink($this->dir . $f);
        @rmdir($this->dir . '/assets/js');
        @rmdir($this->dir . '/assets');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** The token for one path, or '' if the URL carries none. */
    private function token(string $path): string
    {
        $url = Assets::url($path);
        return preg_match('/[?&]v=([^&]*)$/', $url, $m) === 1 ? $m[1] : '';
    }

    // ── 1 + 2: the two directions of correctness ─────────────────────────────

    public function test_changed_content_gets_a_new_url(): void
    {
        $before = $this->token('/assets/js/app.js');
        $this->assertNotSame('', $before);
        $this->assertNotSame('v1', $before, 'A real file must be hashed, not fall back.');

        file_put_contents($this->dir . '/assets/js/app.js', "console.log('two');\n");
        Assets::configure($this->dir, 'v1');   // clears the per-request memo

        $this->assertNotSame($before, $this->token('/assets/js/app.js'),
            'A changed file must get a new URL or the fix never reaches anybody.');
    }

    public function test_unchanged_content_keeps_its_url(): void
    {
        $mine = $this->token('/assets/js/other.js');

        // A different file changes. With a deploy-wide token this would churn
        // every asset on every device; with a content hash it must not.
        file_put_contents($this->dir . '/assets/js/app.js', "console.log('changed');\n");
        Assets::configure($this->dir, 'v1');

        $this->assertSame($mine, $this->token('/assets/js/other.js'),
            "One file changing must not invalidate its neighbours' caches.");
    }

    /**
     * @depends test_unchanged_content_keeps_its_url
     */
    public function test_a_touch_is_not_a_change(): void
    {
        $before = $this->token('/assets/js/app.js');

        touch($this->dir . '/assets/js/app.js', time() + 120);
        clearstatcache(true, $this->dir . '/assets/js/app.js');
        Assets::configure($this->dir, 'v1');

        $this->assertSame($before, $this->token('/assets/js/app.js'),
            'An mtime moved by an upload is not an edit — re-downloading here is pure waste.');
    }

    public function test_the_same_file_hashes_identically_every_time(): void
    {
        $a = Assets::url('/assets/js/app.js');
        Assets::configure($this->dir, 'v1');
        $b = Assets::url('/assets/js/app.js');

        $this->assertSame($a, $b, 'The token must be a function of the bytes and nothing else.');
    }

    // ── 4: it never throws, whatever it is handed ────────────────────────────

    public function test_a_missing_file_falls_back_instead_of_failing(): void
    {
        $this->assertSame('/assets/js/nope.js?v=v1', Assets::url('/assets/js/nope.js'),
            'A template calls this for every asset; a throw here is a blank page.');
    }

    public function test_an_absolute_url_is_left_completely_alone(): void
    {
        foreach (['https://cdn.example.com/x.js', 'http://cdn.example.com/x.js',
                  '//cdn.example.com/x.js'] as $remote) {
            $this->assertSame($remote, Assets::url($remote),
                'Somebody else\'s cache is not ours to bust.');
        }
    }

    public function test_an_existing_query_string_survives(): void
    {
        $url = Assets::url('/assets/js/app.js?module=1');

        $this->assertStringStartsWith('/assets/js/app.js?module=1&v=', $url);
        $this->assertSame(1, preg_match_all('/\?/', $url),
            'Two question marks would break the query string.');
    }

    public function test_an_empty_path_stays_empty(): void
    {
        $this->assertSame('', Assets::url(''));
        $this->assertSame('', Assets::url('   '));
    }

    /**
     * A path cannot climb out of public/.
     *
     * Not paranoia about templates — `asset()` is also reachable with a path
     * assembled from stored data (an uploaded file, a logo path), and a traversal
     * there would turn a cache-buster into a filesystem probe: the token differs
     * for a file that exists and equals the fallback for one that does not.
     */
    public function test_a_traversal_cannot_reach_outside_the_public_root(): void
    {
        $outside = dirname($this->dir) . '/ag-outside-' . bin2hex(random_bytes(4)) . '.js';
        file_put_contents($outside, "secret\n");

        try {
            foreach (['/../' . basename($outside),
                      '/assets/js/../../' . basename($outside),
                      '/assets/../../etc/passwd'] as $climb) {
                $this->assertSame('v1', $this->token($climb),
                    "\"{$climb}\" must not be hashed — it is outside public/.");
            }
        } finally {
            @unlink($outside);
        }
    }

    // ── the sweep: no template may go back to the broken scheme ──────────────

    /**
     * Every local asset link in every template must use `asset()`.
     *
     * The regression is a one-character edit away — copy an existing `<link>`,
     * paste it, change the filename, leave the `?v={{ asset_version }}`. It will
     * work perfectly in dev, where the token is an mtime, and freeze on the
     * production host, where it is `v1` for ever. Nothing about that is visible
     * to whoever made the change, so it has to fail here.
     */
    public function test_no_template_still_pins_a_local_asset_to_the_shared_token(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 2) . '/templates';

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
            \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            $body = (string) file_get_contents($file->getPathname());

            if (preg_match_all('~(?:href|src)="(/assets/[^"]*\?v=\{\{\s*asset_version\s*\}\})"~',
                    $body, $m) === 0) {
                continue;
            }
            foreach ($m[1] as $hit) {
                $offenders[] = str_replace($root . '/', '', $file->getPathname()) . ' → ' . $hit;
            }
        }

        $this->assertSame([], $offenders,
            "Use {{ asset('/path') }} instead — it hashes the file, so the URL changes "
            . "when the bytes do. `?v={{ asset_version }}` is the pinned ASSET_VERSION in "
            . "production, which nothing bumps on this host:\n  " . implode("\n  ", $offenders));
    }

    /** Every path passed to asset() in a template must actually exist. */
    public function test_every_asset_a_template_links_is_on_disk(): void
    {
        $public  = dirname(__DIR__, 2) . '/public';
        $root    = dirname(__DIR__, 2) . '/templates';
        $missing = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
            \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            $body = (string) file_get_contents($file->getPathname());

            // Only literal single-quoted paths; the one interpolated path
            // (`'/assets/img/illustrations/' ~ _illo ~ '.jpg'`) cannot be checked
            // statically and is deliberately skipped rather than guessed at.
            preg_match_all("~asset\('(/assets/[^']+)'\)~", $body, $m);
            foreach ($m[1] as $path) {
                if (!is_file($public . $path)) {
                    $missing[] = str_replace($root . '/', '', $file->getPathname()) . ' → ' . $path;
                }
            }
        }

        $this->assertSame([], $missing,
            "These would silently fall back to the pinned token and 404:\n  "
            . implode("\n  ", $missing));
    }
}
