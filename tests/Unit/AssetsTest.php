<?php
declare(strict_types=1);
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Support\Assets;

/**
 * The dev cache-buster must track EVERY css/js file, not one sentinel.
 * These tests build a throwaway asset tree in the system temp dir, stamp
 * known mtimes onto the files, and assert the version reacts to edits.
 */
class AssetsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/afg_assets_' . uniqid('', true);
        mkdir($this->dir . '/css/components', 0777, true);
        mkdir($this->dir . '/js', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        parent::tearDown();
    }

    /** Create a file at $rel under the temp tree and stamp it with $mtime. */
    private function write(string $rel, int $mtime): string
    {
        $path = $this->dir . '/' . $rel;
        file_put_contents($path, "/* x */");
        touch($path, $mtime);
        return $path;
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }

    // --- latestMtime(): the core MAX aggregation -------------------------

    public function test_latest_mtime_returns_the_newest_across_files(): void
    {
        $a = $this->write('css/main.css', 1700000100);
        $b = $this->write('css/components/gee.css', 1700000300);
        $c = $this->write('js/main.js', 1700000200);
        $this->assertSame(1700000300, Assets::latestMtime([$a, $b, $c]));
    }

    /** The whole point of the fix: editing ANY tracked file moves the value. */
    public function test_latest_mtime_changes_when_any_file_is_touched(): void
    {
        $a = $this->write('css/main.css', 1700000100);
        $b = $this->write('css/components/gee.css', 1700000150);
        $before = Assets::latestMtime([$a, $b]);
        touch($a, 1700000900); // edit main.css
        $after = Assets::latestMtime([$a, $b]);

        $this->assertSame(1700000150, $before);
        $this->assertSame(1700000900, $after);
    }

    /** Missing paths are skipped — and must not emit a PHP warning. */
    public function test_latest_mtime_ignores_missing_files(): void
    {
        $a = $this->write('css/main.css', 1700000100);
        $missing = $this->dir . '/css/does-not-exist.css';
        $this->assertSame(1700000100, Assets::latestMtime([$a, $missing]));
    }

    public function test_latest_mtime_of_empty_list_is_zero(): void
    {
        $this->assertSame(0, Assets::latestMtime([]));
    }

    // --- collect(): recursive css/js discovery ---------------------------

    public function test_collect_finds_css_and_js_recursively_ignoring_other_types(): void
    {
        $this->write('css/main.css', 1700000100);
        $this->write('css/components/gee.css', 1700000100);
        $this->write('js/main.js', 1700000100);
        file_put_contents($this->dir . '/africa-mark.svg', 'x'); // not a cache-busted type

        $names = array_map('basename', Assets::collect($this->dir));
        sort($names);
        $this->assertSame(['gee.css', 'main.css', 'main.js'], $names);
    }

    public function test_collect_of_missing_dir_is_empty(): void
    {
        $this->assertSame([], Assets::collect($this->dir . '/nope'));
    }

    // --- version(): prod-vs-dev policy -----------------------------------

    public function test_version_in_production_uses_the_pinned_value(): void
    {
        $this->assertSame('v7', Assets::version(false, 'v7', $this->dir));
    }

    public function test_version_in_production_falls_back_to_v1_when_unset(): void
    {
        $this->assertSame('v1', Assets::version(false, null, $this->dir));
        $this->assertSame('v1', Assets::version(false, '', $this->dir));
    }

    public function test_version_in_debug_is_the_newest_asset_mtime(): void
    {
        $this->write('css/main.css', 1700000100);
        $this->write('css/components/gee.css', 1700000300);
        $this->write('js/main.js', 1700000200);
        // Even though a prod version is pinned, debug wins and reflects the files.
        $this->assertSame('1700000300', Assets::version(true, 'v7', $this->dir));
    }

    public function test_version_in_debug_falls_back_to_dev_when_no_assets_exist(): void
    {
        $this->assertSame('dev', Assets::version(true, 'v7', $this->dir));
    }
}
