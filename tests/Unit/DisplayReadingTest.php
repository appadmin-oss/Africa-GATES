<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Display & reading: three surfaces, one store, and a switch that really does something.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE THREE WAYS A SETTINGS SCREEN LIES, ALL OF THEM SILENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The full screen, the phone Quick settings sheet and the Menu's pushed sub-view are
 * three doors onto one `localStorage["ag-a11y"]`. Each door can be wrong on its own, and
 * none of the three failures throws, logs or looks wrong:
 *
 * · A SWITCH THE STORE HAS NEVER HEARD OF. `data-ag-toggle="contrast"` next to a store
 *   that calls it `hc` renders a switch that flips, remembers its own `aria-checked`
 *   until the page reloads, and changes nothing. That is §17 at the view layer: the
 *   template reads fine, the JS reads fine, and only the pair is wrong.
 *
 * · A SWITCH WITH NO STYLESHEET BEHIND IT. `AGA11y` writes `ag-easy` onto `<html>` and
 *   nothing anywhere declares it. The class is really applied, `apply()` is really
 *   idempotent, the setting really persists — and the page looks identical. This is the
 *   platform's most expensive shape, a declared field with no reader, and it costs most
 *   here because the person it fails is the person who needed the setting.
 *
 * · THE FIRST PAINT AND THE LATER APPLY DISAGREEING. The head script is a deliberate
 *   duplicate of `apply()`, kept to six lines so it can run before a stylesheet paints.
 *   Two copies of one mapping is the arrangement this codebase warns about on every
 *   page of CLAUDE.md, and it is accepted here for a reason — but only while the two
 *   agree. They are compared, key by key.
 *
 * The suite has no browser, so what is checkable is the AGREEMENT between the three
 * files. That is also where every one of these failures lives.
 */
final class DisplayReadingTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private function js(string $f): string
    {
        return (string) file_get_contents(self::ROOT . 'public/assets/js/' . $f);
    }

    /** The store's switches, read out of the shipped file rather than restated here. */
    private function switches(): array
    {
        preg_match_all(
            "/\{\s*k:\s*'([a-z]+)',\s*cls:\s*'([a-z0-9-]*)'/i",
            $this->js('a11y.js'),
            $m,
            PREG_SET_ORDER
        );

        $out = [];
        foreach ($m as $s) $out[$s[1]] = $s[2];

        return $out;
    }

    public function test_the_store_declares_the_seven_switches_the_design_fixed(): void
    {
        $this->assertSame(
            ['hc', 'easy', 'space', 'ul', 'motion', 'saver', 'listen'],
            array_keys($this->switches()),
            'REFERENCE §7.5 fixes these seven, in this order'
        );
    }

    /**
     * The switch keys a template actually offers.
     *
     * READ FROM THE LOOP'S OWN LIST, not from the attribute. Both surfaces draw their
     * rows from a Twig `for` over an inline array, so the attribute in the source reads
     * `data-ag-toggle="{{ t.k }}"` — a sweep matching the attribute text finds that
     * string on every template and either reports all of them or, after somebody
     * "fixes" it with a filter, excuses all of them. That is `SchemaIndexTest` excusing
     * three 1064s, one layer up: the right rule pinned to the wrong token.
     *
     * A literal attribute is read too, because one written by hand is exactly the case
     * where a key gets mistyped.
     *
     * @return list<string>
     */
    private function offered(string $body): array
    {
        if (!str_contains($body, 'data-ag-toggle')) return [];

        $out = [];
        preg_match_all("/\\{k:\\s*'([a-z]+)'/", $body, $loop);
        foreach ($loop[1] as $k) $out[] = $k;

        preg_match_all('/data-ag-toggle="([a-z]+)"/', $body, $lit);
        foreach ($lit[1] as $k) $out[] = $k;

        return array_values(array_unique($out));
    }

    public function test_every_switch_in_a_template_is_one_the_store_knows(): void
    {
        $known = $this->switches();
        $bad   = [];

        foreach ($this->templates() as $path => $body) {
            foreach ($this->offered($body) as $k) {
                if (!array_key_exists($k, $known)) {
                    $bad[] = "$path: data-ag-toggle=\"$k\" — the store has no such setting, so this "
                           . 'switch flips and changes nothing';
                }
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_every_switch_the_store_applies_has_a_rule_behind_it(): void
    {
        $css = '';
        foreach (glob(self::ROOT . 'public/assets/css/*.css') ?: [] as $f) {
            $css .= (string) file_get_contents($f);
        }

        $dead = [];
        foreach ($this->switches() as $k => $cls) {
            // `listen` has no class on purpose: it adds a Listen control to articles and
            // profiles rather than restyling anything, so there is nothing for a
            // stylesheet to say. Every other one is a promise the CSS has to keep.
            if ($cls === '') continue;
            if (!str_contains($css, '.' . $cls)) {
                $dead[] = "$k applies .$cls and no stylesheet declares it — the setting stores, "
                        . 'persists and does nothing';
            }
        }

        $this->assertSame([], $dead, implode("\n", $dead));
    }

    public function test_the_three_text_sizes_have_the_two_classes_the_ladder_needs(): void
    {
        $shell = (string) file_get_contents(self::ROOT . 'public/assets/css/shell.css');

        // Standard is the absence of a class, so two rules cover three steps.
        $this->assertStringContainsString('html.ag-t125', $shell);
        $this->assertStringContainsString('html.ag-t150', $shell);
    }

    public function test_the_first_paint_script_applies_exactly_what_the_store_applies(): void
    {
        $head = (string) file_get_contents(self::ROOT . 'templates/partials/a11y-head.twig');

        preg_match('/var k=\{([^}]*)\}/', $head, $m);
        $this->assertNotEmpty($m, 'the head script no longer carries a recognisable map');

        $inHead = [];
        foreach (explode(',', $m[1]) as $pair) {
            [$k, $v] = array_map(static fn ($x) => trim($x, " '"), explode(':', $pair));
            $inHead[$k] = $v;
        }

        $inStore = array_filter($this->switches(), static fn (string $c): bool => $c !== '');

        // Key by key, both directions. A switch in the store and not in the head starts
        // the page in the wrong state and corrects itself after first paint, which is the
        // flash the head script exists to prevent; one in the head and not in the store
        // is applied and never cleared.
        $this->assertSame($inStore, $inHead,
            'the head script and AGA11y.apply() disagree about which class a setting adds');
    }

    public function test_the_size_control_is_a_radiogroup_everywhere_it_appears(): void
    {
        $bad = [];
        foreach ($this->templates() as $path => $body) {
            if (!str_contains($body, 'data-ag-size=')) continue;
            if (!str_contains($body, 'role="radiogroup"')) {
                $bad[] = "$path: three size buttons with no radiogroup around them";
            }
            // "A" three times is not a choice. The accessible name carries the words.
            preg_match_all('/data-ag-size="\d"[^>]*/', $body, $m);
            foreach ($m[0] as $tag) {
                if (!str_contains($tag, 'aria-label=') && !str_contains($body, 'aria-label="Standard text"')) {
                    $bad[] = "$path: a size button whose only name is the letter it draws";
                }
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_the_settings_surfaces_share_one_markup_rather_than_three_copies(): void
    {
        // The Menu's sub-view includes the same partial the full screen uses. Two copies
        // of seven switches is how the halves of a setting come to disagree about
        // whether it is on — and the disagreement is invisible until somebody opens both.
        $menu = (string) file_get_contents(self::ROOT . 'templates/partials/menu-sheet.twig');
        $this->assertStringContainsString("include 'partials/display-reading.twig'", $menu);

        // Quick settings carries a SUBSET on purpose — two of seven, §7.2 — so it is not
        // required to include the partial; what it must not do is invent a key.
        $quick = (string) file_get_contents(self::ROOT . 'templates/partials/quick-settings.twig');
        $this->assertSame(['hc', 'motion'], $this->offered($quick),
            'REFERENCE §7.2 puts high contrast and reduce motion in the sheet, and the rest one tap away');
        $this->assertStringContainsString('data-ag-quick-all', $quick,
            'the sheet must offer a way to the other five, or it is a dead end');
    }

    public function test_the_account_keeps_exactly_the_keys_the_store_keeps(): void
    {
        // Three lists name these switches: the store (a11y.js), the account's normaliser
        // (DisplayReadingPrefs) and the templates' loops. A switch the store has and the
        // account does not is silently dropped on every save — the member sets it, sees it,
        // and finds it gone on their next device.
        $this->assertSame(
            array_keys($this->switches()),
            \AfricaGates\Services\DisplayReadingPrefs::SWITCHES,
            'a11y.js and DisplayReadingPrefs disagree about which switches exist'
        );
    }

    public function test_a_signed_in_members_settings_are_painted_before_anything_loads(): void
    {
        // §7.5 "plus the member profile when signed in" (GAPS §3.7). The head script is
        // handed the member's saved settings by the server, so a device they have never
        // used paints in them with nothing fetched — a fetch runs after the paint, which is
        // the flash the head script exists to prevent.
        $head = (string) file_get_contents(self::ROOT . 'templates/partials/a11y-head.twig');
        $this->assertStringContainsString('member_display()', $head);
        // Written back to whichever store the visitor's Preferences answer allows
        // (`data-ag-keep`, CookiePrefs): between visits with a yes, for the tab without.
        $this->assertMatchesRegularExpression("~if\(m\)\{s=m;if\(K\)localStorage\.setItem\('ag-a11y'[^;]*;else sessionStorage\.setItem\('ag-a11y'~", $head,
            'the member\'s saved settings must win over this device\'s and be written back to it');

        // And every change goes back to the account while signed in.
        $js = $this->js('a11y.js');
        $this->assertStringContainsString("fetch('/account/display'", $js);
        $this->assertStringContainsString("'X-CSRF-Token'", $js);
        $shell = (string) file_get_contents(self::ROOT . 'templates/layout/shell.twig');
        $this->assertStringContainsString('data-ag-sync=', $shell);
        $this->assertStringContainsString('name="ag-csrf"', $shell);
    }

    public function test_the_easy_read_font_is_actually_loaded_when_it_is_on(): void
    {
        // GAPS §2: `ag-easy` named Atkinson Hyperlegible and NOTHING LOADED IT, so the
        // browser fell back silently and the "easy-read font" changed the letter spacing
        // and kept the old face. It must be fetched — by the head script before first paint,
        // and by the store when the switch is turned on later — from one address, the one
        // the layout declares.
        $shell = (string) file_get_contents(self::ROOT . 'templates/layout/shell.twig');
        $this->assertMatchesRegularExpression('~data-ag-easy-font="https://fonts\.googleapis\.com/css2\?family=Atkinson\+Hyperlegible~', $shell);

        $head = (string) file_get_contents(self::ROOT . 'templates/partials/a11y-head.twig');
        $this->assertMatchesRegularExpression("~if\(s\.easy&&f\)\{var l=document\.createElement\('link'\)~", $head);
        $this->assertStringContainsString("getAttribute('data-ag-easy-font')", $head);

        $js = $this->js('a11y.js');
        $this->assertMatchesRegularExpression('~if \(s\.easy\) loadEasyFont\(\)~', $js);
        $this->assertStringContainsString("getAttribute('data-ag-easy-font')", $js);

        $css = (string) file_get_contents(self::ROOT . 'public/assets/css/shell.css');
        $this->assertStringContainsString("html.ag-easy body{ font-family:'Atkinson Hyperlegible'", $css,
            'the face the link loads is not the face the class asks for');
    }

    /** @return array<string,string> path relative to templates/ → body */
    private function templates(): array
    {
        $root = realpath(self::ROOT . 'templates');
        $out  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            $out[ltrim(str_replace($root, '', $f->getPathname()), '/')] = (string) file_get_contents($f->getPathname());
        }
        return $out;
    }
}
