<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Nothing here starts a second `php` process. A bare `php` is the server's DEFAULT binary,
 * which on cPanel is not the MultiPHP version the site runs on: cron/recalculate-cpi.php
 * shelled out to `php bin/console`, got PHP 7.4, and every run died at Composer's platform
 * check while the cron itself ran on 8.4. Run the work in process instead.
 */
final class NoBarePhpShellTest extends TestCase
{
    public function test_no_code_shells_out_to_php(): void
    {
        $root = dirname(__DIR__, 2);
        $bad = [];
        foreach (['src', 'cron', 'bin', 'public'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || !in_array($f->getExtension(), ['php', ''], true) || str_contains($f->getPathname(), '/vendor/')) continue;
                if (self::offends((string) file_get_contents($f->getPathname()))) $bad[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        $this->assertSame([], $bad, 'starts a php process with whatever `php` the server defaults to');
        $this->assertTrue(self::offends("@exec('php ' . \$bin);"), 'the sweep names the break');
        $this->assertTrue(self::offends('shell_exec("php artisan");'));
        $this->assertFalse(self::offends("// It used to exec('php …')"), 'a comment is not a call');
    }

    private static function offends(string $code): bool
    {
        $code = preg_replace('~^\s*(//|\*|#).*$~m', '', $code) ?? $code;
        return (bool) preg_match('~\b(exec|shell_exec|system|passthru|popen|proc_open)\s*\(\s*[\'"]php\b~', $code);
    }
}
