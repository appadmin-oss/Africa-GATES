<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The accessibility panel's "reduce motion" sets html.ag-rm (a11y.js, applied in the head)
 * for a reader whose device does not offer the setting or who wants it on this site only.
 * Six scripts asked the system media query and nothing else, so the homepage hero rotated
 * and its figures counted up for exactly the people who had asked this site not to.
 *
 * The rule, not the list: an authored script that consults prefers-reduced-motion must
 * also consult the site's own switch.
 */
final class ReduceMotionSwitchTest extends TestCase
{
    public function test_a_script_that_honours_the_system_setting_honours_the_sites_too(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/public/assets/js/*.js') ?: [];
        $asked = 0;
        $bad = [];
        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            if (!str_contains($src, 'prefers-reduced-motion')) continue;
            $asked++;
            if (!str_contains($src, 'ag-rm')) $bad[] = basename($f);
        }
        $this->assertGreaterThan(3, $asked, 'the sweep is not reading the scripts');
        $this->assertSame([], $bad, 'these scripts ignore the site\'s own reduce-motion switch');
    }
}
