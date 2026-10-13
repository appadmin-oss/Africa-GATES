<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\TestCase;

/**
 * THE ADMIN CONSOLE'S PALETTE: THE HANDOFF'S, EXACTLY, IN THE ONE COLOUR FILE.
 *
 * The owner chose the admin handoff's monochrome set (README §10, option (A), 4 Oct
 * 2026 — GAPS §8d) and asked for it to live in `Support\Accent` as a second set rather
 * than in a stylesheet, so there is still one file a colour may be typed in. This holds
 * the same three things `AccentTest` and `SlotFloorTest` hold for the public palette:
 *
 *   · IDENTITY — the values are pinned a second time here, because here the second copy
 *     is the contract. "Improving" a grey fails by name and has to be argued with the
 *     owner.
 *   · SEPARATION — `css('console')` emits `--cn-*` and nothing of the public palette,
 *     and the admin layout is the only layout that asks for it.
 *   · WORDS — every word the console draws, on every ground the HTML draws it on, at
 *     4.5:1. The handoff has four that fail; they are REPORTED in
 *     `Accent::consoleReported()` and this test holds that list EXACTLY, both ways, so a
 *     new failure cannot hide and a fixed one cannot linger.
 */
final class ConsolePaletteTest extends TestCase
{
    /** README §10 + the shell's own colours from `Admin Console v9.dc.html`, verbatim. */
    private const HANDOFF = [
        'ink' => '#0a0a0a', 'grey-800' => '#262626', 'grey-700' => '#404040', 'grey-600' => '#525252',
        'grey-500' => '#737373', 'grey-450' => '#8f8f8f', 'grey-400' => '#a3a3a3', 'grey-350' => '#bdbdbd',
        'grey-300' => '#d4d4d4', 'line-control' => '#e5e5e5', 'line-card' => '#ebebeb',
        'line-row' => '#f2f2f2', 'line-bar' => '#f0f0f0', 'link-line' => '#c4c4c4',
        'fill-current' => '#ededed', 'fill-hover' => '#efefef', 'fill-subtle' => '#f2f2f2',
        'fill-selected' => '#f5f5f5', 'fill-panel' => '#fafafa', 'surface' => '#ffffff',
        'green' => '#086411', 'success' => '#1a6118', 'success-dot' => '#2f8f2c', 'success-tint' => '#eef6ee',
        'warning' => '#8a5a00', 'warning-dot' => '#c98a00', 'warning-tint' => '#fdf0d2',
        'warning-tint-2' => '#fdf6e3', 'warning-tint-3' => '#fdf9ee',
        'danger' => '#b3261e', 'danger-pill' => '#8a2020', 'danger-dot' => '#d0362c',
        'danger-tint' => '#fdf0ef', 'danger-edge' => '#f0c9c5',
        'info' => '#2a5f9e', 'info-dot' => '#2a78d6', 'proposed-ink' => '#5c4400',
        'scrim' => 'rgba(10,10,10,.32)', 'scrim-palette' => 'rgba(10,10,10,.28)',
        'scrim-drawer' => 'rgba(10,10,10,.2)', 'on-ink-fill' => 'rgba(255,255,255,.14)',
    ];

    private const HANDOFF_SHADOWS = [
        'sh-menu'    => '0 12px 32px rgba(10,10,10,.12)',
        'sh-modal'   => '0 20px 48px rgba(10,10,10,.2)',
        'sh-palette' => '0 20px 48px rgba(10,10,10,.22)',
        'sh-drawer'  => '-12px 0 32px rgba(10,10,10,.12)',
        'sh-toast'   => '0 10px 30px rgba(10,10,10,.25)',
        'sh-hero'    => '0 1px 2px rgba(10,10,10,.04), 0 10px 30px rgba(10,10,10,.05)',
        'sh-tile'    => '0 1px 2px rgba(10,10,10,.04)',
    ];

    public function test_the_console_palette_is_the_handoffs_value_for_value(): void
    {
        $got = array_map(static fn (array $v): string => $v['value'], Accent::console());
        $this->assertSame(self::HANDOFF, $got,
            'the console palette drifted from the admin handoff. ±0 on colour — a value that '
          . 'fails a floor is REPORTED in Accent::consoleReported(), never adjusted.');
        $this->assertSame(self::HANDOFF_SHADOWS, Accent::consoleShadows());

        foreach (Accent::console() as $name => $v) {
            $this->assertNotSame('', trim($v['use']), "$name states no use");
        }
    }

    public function test_the_console_receives_its_own_set_and_none_of_the_public_one(): void
    {
        $css = Accent::css('console');

        $this->assertMatchesRegularExpression('/^:root\{(--cn-[a-z0-9-]+:[#a-z0-9 ,.()-]+;)+\}$/', $css);
        $this->assertStringNotContainsString('--ag-', $css, 'a public token leaked into the console');
        $this->assertSame(count(self::HANDOFF) + count(self::HANDOFF_SHADOWS), substr_count($css, '--cn-'));

        // And the public set is untouched by the second surface.
        $this->assertStringNotContainsString('--cn-', Accent::css());
        $this->assertSame(Accent::css(), Accent::css('site'));

        $this->expectException(\InvalidArgumentException::class);
        Accent::css('admin');
    }

    public function test_a_console_colour_asked_for_by_a_wrong_name_throws(): void
    {
        $this->assertSame('#737373', Accent::consoleHex('grey-500'));
        foreach (['ad-text-mute', 'grey', 'soft', ''] as $bad) {
            try {
                Accent::consoleHex($bad);
                $this->fail("'$bad' resolved to a console colour");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Accent::consoleHex('scrim');
    }

    /** @return list<string> "word@ground" for every drawn pair under 4.5:1 */
    private static function failingWords(): array
    {
        $out = [];
        foreach (Accent::consoleWords() as $word => $grounds) {
            foreach ($grounds as $g) {
                $r = Contrast::ratio(Accent::consoleHex($word), Accent::consoleHex($g));
                if (round($r, 2) < Contrast::TEXT) $out[] = "$word@$g";
            }
        }
        sort($out);
        return $out;
    }

    public function test_every_console_word_clears_4_5_or_is_reported_to_the_owner(): void
    {
        $reported = array_keys(Accent::consoleReported());
        sort($reported);

        $this->assertSame($reported, self::failingWords(),
            "the console's failing words and the REPORTED list disagree. A new failure is "
          . "added to Accent::CONSOLE_REPORTED with where it is drawn (that is the report to "
          . "the owner); a pair that now passes leaves the list. Never edit the hex.");

        foreach (Accent::consoleReported() as $key => $r) {
            [$word, $ground] = explode('@', $key);
            $this->assertSame($ground, $r['on'], $key);
            $this->assertContains($ground, Accent::consoleWords()[$word], "$key is reported but not measured");
            $this->assertNotSame('', trim($r['where']), "$key does not say where it is drawn");
        }
    }

    public function test_the_words_measured_cover_what_the_shell_draws(): void
    {
        // By name, so a word cannot drop out of the table and stop being measured.
        foreach (['ink', 'grey-800', 'grey-700', 'grey-600', 'grey-500', 'grey-450', 'danger',
                  'warning', 'success', 'info', 'surface'] as $w) {
            $this->assertArrayHasKey($w, Accent::consoleWords(), "$w is no longer measured");
        }
        $this->assertContains('ink', Accent::consoleWords()['surface'], 'white on a black button or the toast');
        $this->assertContains('fill-panel', Accent::consoleWords()['grey-450'], 'the sidebar group labels');
    }

    public function test_what_was_accepted_below_a_floor_is_not_a_word_and_is_below_it(): void
    {
        foreach (Accent::consoleAccepted() as $token => $a) {
            $r = Contrast::ratio(Accent::consoleHex($token), Accent::consoleHex($a['on']));
            $this->assertLessThan(Contrast::UI, $r, sprintf('%s is %.2f:1 — no longer below the floor it was accepted under', $token, $r));
            $this->assertArrayNotHasKey($token, Accent::consoleWords(), "$token was accepted as a non-word");
            $this->assertNotSame('', trim($a['why']), $token);
        }
    }

    public function test_the_measurement_can_fail(): void
    {
        // Seen failing before trusted passing: the group label grey on the sidebar.
        $this->assertLessThan(Contrast::TEXT,
            round(Contrast::ratio(Accent::consoleHex('grey-450'), Accent::consoleHex('fill-panel')), 2));
        $this->assertNotSame([], self::failingWords());
    }

    public function test_the_admin_layout_emits_the_console_set_before_its_stylesheets(): void
    {
        $body = (string) preg_replace('/\{#.*?#\}/s', '',
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/layout.twig'));

        $this->assertMatchesRegularExpression(
            '/<style nonce="\{\{ csp_nonce \}\}">\{\{ ag_accents\(\'console\'\)\|raw \}\}<\/style>/', $body);
        $at    = strpos($body, "ag_accents('console')");
        $sheet = strpos($body, '/assets/css/console/');
        $this->assertNotFalse($sheet, 'the admin layout links no console stylesheet');
        $this->assertLessThan($sheet, $at, 'the palette is emitted after the stylesheets');
        $this->assertStringNotContainsString('ag_accents()', $body, 'the public palette in the console');
    }

    public function test_the_rebuilt_console_types_no_colour(): void
    {
        foreach (\Tests\Support\ConsoleSurface::files() as $rel => $abs) {
            $this->assertSame(0, ColourLiteralTest::literals((string) file_get_contents($abs)),
                "$rel types a colour. The console's colours are Accent's console set — var(--cn-*), "
              . 'or color-mix() of one.');
        }
    }
}
