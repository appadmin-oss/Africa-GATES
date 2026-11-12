<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Gee on a phone is the whole screen, in portrait AND on its side, and its composer follows
 * the on-screen keyboard.
 *
 * Three things went wrong before, each silent:
 *  - a landscape phone (740×360) is wider than the 600 breakpoint, so it got the desktop
 *    card, 400×320, with about forty pixels of conversation;
 *  - iOS keeps the layout viewport full height when the keyboard opens, so a fixed sheet
 *    kept its height and the composer sat behind the keys;
 *  - the stylesheet and the script must agree on WHEN the sheet is full screen, or the script
 *    sizes a desktop panel to the keyboard (or leaves a phone sheet behind it).
 *
 * Measured in a browser while this was written (portrait, 360×640, 740×360, and a shrunken
 * visual viewport); this file holds the wiring so it cannot drift.
 */
final class GeeFullScreenTest extends TestCase
{
    private const MQ = '(max-width:599.98px), (max-height:499.98px) and (pointer:coarse)';

    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_the_stylesheet_and_the_script_use_one_query_for_full_screen(): void
    {
        $css = self::read('public/assets/css/components/gee.css');
        $js = self::read('public/assets/js/gee.js');
        $this->assertStringContainsString('@media ' . self::MQ . '{', $css, 'a phone on its side is full screen too');
        $this->assertStringContainsString("matchMedia('" . self::MQ . "')", $js, 'the script must ask the same question as the stylesheet');
        // The full-screen block must come after the 600 block, or the desktop card wins on a landscape phone.
        $this->assertGreaterThan(strpos($css, '@media (min-width:600px){'), strpos($css, '@media ' . self::MQ));
    }

    public function test_the_sheet_fills_the_screen_and_follows_the_visual_viewport(): void
    {
        $css = self::read('public/assets/css/components/gee.css');
        $block = substr($css, (int) strpos($css, '@media ' . self::MQ));
        $block = substr($block, 0, (int) strpos($block, "\n}\n"));
        $this->assertMatchesRegularExpression('/\.gee__layer\{[^}]*height:var\(--gee-vv-h, 100%\)/', $block);
        $this->assertMatchesRegularExpression('/\.gee__panel\{[^}]*border-radius:0/', $block, 'edge to edge, not a card');
        $this->assertStringContainsString('safe-area-inset-top', $block, 'the notch is kept clear');

        $js = self::read('public/assets/js/gee.js');
        $this->assertMatchesRegularExpression("/setProperty\\('--gee-vv-h',[^;]*vv\\.height/", $js, 'the height comes from the visual viewport');
        $this->assertMatchesRegularExpression("/vv\\.addEventListener\\('resize', fit\\)/", $js, 'and follows it as the keyboard opens and closes');
    }
}
