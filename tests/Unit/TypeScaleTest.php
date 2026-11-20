<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\AssetBundle;
use PHPUnit\Framework\TestCase;
use Tests\Support\PublicSurface;

/**
 * THE PUBLIC TYPE SCALE IS THE HANDOFF'S, IT IS CLOSED, IT DOES NOT MOVE WITH THE
 * WINDOW — AND IT DOES MOVE WITH THE READER.
 *
 * Destroyed and written again on 3 Oct 2026, when the owner put type in rem.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULES, AND WHOSE THEY ARE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The owner answered GAPS §8 Q4 on 3 Oct 2026 (apply REFERENCE §6.2 as written), and
 * later that day decided that type is written in rem. Four things follow:
 *
 *  1. **Small text sits on a closed ladder** — `11.5 · 12 · 12.5 · 13 · 13.5 · 14 · 14.5
 *     · 15 · 15.5 · 16 · 16.5 · 17`. Nothing below 11.5; there is no 10 and no 11, and
 *     the `MIGRATING` allowance that once carried them stays deleted.
 *  2. **Display type is a FIXED size per breakpoint, never scaled by the viewport** —
 *     no `vw`, no container units, no `clamp()` (§6.2, §4.5). A breakpoint is a QUERY,
 *     not a unit.
 *  3. **Screen type is in rem.** The Display & reading setting (100 · 125 · 150%) sets
 *     the ROOT font size, and a px size ignores the root. That is not a theory: the
 *     first build of the chrome wrote every size in px, the setting's control worked,
 *     `ag-t150` landed on <html> — and not one letter grew
 *     (docs/handoff/PHASE-2.md §9.2). A setting that does nothing and says nothing is
 *     the fault this file now refuses, from both ends: no screen size may be px, and
 *     the text-size classes must really change the root.
 *  4. **`em` and `%` sizes are refused, except the root's own percentage.** An `em` is a
 *     multiple of whatever the parent happens to be, so the size it produces is
 *     decided by nesting rather than by the declaration — `0.9em` inside a 14.5px row
 *     is 13.05px, inside a 16px one 14.4px, and neither is on the ladder. The ladder
 *     can only be held where a declaration NAMES its size, and rem does (it is a
 *     multiple of one fixed root). The root is the single place a percentage belongs,
 *     because there it is the reader's setting: `html{font-size:100%}` and the two
 *     classes Display & reading writes, at 125% and 150% and nothing else.
 *
 * Rem is converted at the 16px root, so `.90625rem` is read as 14.5px and checked
 * against the ladder exactly as the px it replaced was. Mail is the exception to rule
 * 3 and only to rule 3: an email client has no Display & reading setting, many ignore
 * rem, and px is right there — but its sizes still sit on the ladder.
 *
 * ── TWO THINGS A SWEEP OVER THIS HAS TO KNOW (both have had a sweep here lying) ──
 *
 *  - **Most type lives in the `font:` SHORTHAND**, not in `font-size:` — 214
 *    micro-labels were once `font:600 11px/1 var(--ag-font-mono)` and a `font-size:`
 *    grep never saw one. Inside the shorthand the size is the first length — or the
 *    first `var()` that RESOLVES to one, now that sizes are tokens: a reader that knew
 *    only literals would see `font:700 var(--ag-fs-12-5)/1 …` as a rule with no size
 *    and pass every one of them.
 *  - **`font-size:1px` in an email is not type.** It is the collapse that keeps the
 *    hidden preheader from occupying a line in clients that ignore `display:none`. It
 *    is exempt by what it DOES — at most 1px in a rule that also says `mso-hide:all` —
 *    never by naming the files that do it.
 *
 * ── SCOPE ────────────────────────────────────────────────────────────────────
 *
 * The public surface, defined once in `Tests\Support\PublicSurface`: every template
 * except `admin/`, `judge/` and the held door scanner, every stylesheet except the
 * ones only those link, and `vendor/`. Email templates ARE in for the ladder. Sizes
 * are read from stylesheets, `<style>` elements and `style="…"` attributes, through
 * custom properties to a fixpoint. Not read: JavaScript.
 */
final class TypeScaleTest extends TestCase
{
    /** The closed small-text ladder — REFERENCE §6.2. */
    public const LADDER = [11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 15.5, 16, 16.5, 17];

    /** At and above this, a size is display type: fixed per breakpoint, off this ladder. */
    public const DISPLAY_FLOOR = 18;

    /** The root the rem is measured against at 100% — the browser default. */
    public const ROOT_PX = 16;

    /** Display & reading's three steps: the root percentage each class writes. */
    public const TEXT_SIZES = ['' => 100, 'ag-t125' => 125, 'ag-t150' => 150];

    /** Units that scale with the viewport or a container — a size nobody chose. */
    private const FLUID = '~(?<![\w-])[0-9.]+(?:vw|vh|vmin|vmax|vi|vb|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax)\b|\bclamp\s*\(~i';

    /** A length in a size: number and unit. */
    private const LENGTH = '~(?<![\w.-])([0-9]*\.?[0-9]+)(px|rem|em|ex|ch|%)(?![\w-])~i';

    /** CSS's relative size keywords: a size decided by the parent, like `em`. */
    private const KEYWORDS = '~^(?:smaller|larger)$~i';

    // ══ the ladder, the viewport, the unit ═══════════════════════════════════

    public function test_no_public_size_leaves_the_closed_ladder(): void
    {
        $off = self::findings(self::publicSources())['off'];

        $this->assertSame([], $off, sprintf(
            "%d public font size(s) are off the closed §6.2 ladder (%s); 10 and 11 are not rungs:\n%s",
            count($off), implode(' · ', self::LADDER), implode("\n", array_slice($off, 0, 60))
        ));
    }

    public function test_no_public_type_is_scaled_by_the_viewport(): void
    {
        $fluid = self::findings(self::publicSources())['fluid'];

        $this->assertSame([], $fluid, sprintf(
            "%d public font size(s) are fluid. §6.2/§4.5: a FIXED size per breakpoint, never vw, "
            . "container units or clamp():\n%s",
            count($fluid), implode("\n", array_slice($fluid, 0, 60))
        ));
    }

    /**
     * Screen type is rem, so the Display & reading setting reaches it.
     *
     * A px size is fixed against the reader: it ignores the root that the setting moves
     * and the browser's own default text size. Mail is exempt (no setting reaches an
     * inbox, and px is right there) and only mail.
     */
    public function test_screen_type_is_in_rem_so_the_text_size_setting_reaches_it(): void
    {
        $f = self::findings(self::publicSources());

        $this->assertSame([], $f['px'], sprintf(
            "%d public screen font size(s) are px, so Display & reading cannot move them. Use a "
            . "tokens.css step (`var(--ag-fs-14-5)`):\n%s",
            count($f['px']), implode("\n", array_slice($f['px'], 0, 60))
        ));
        // Not vacuous: the sweep resolved real sizes, and they were rem.
        $this->assertGreaterThan(80, $f['rem'], 'the sweep found almost no rem sizes — is it reading anything?');
    }

    public function test_no_size_is_relative_to_a_parent_but_the_root(): void
    {
        $rel = self::findings(self::publicSources())['relative'];

        $this->assertSame([], $rel, sprintf(
            "%d public font size(s) are em, %%, or a relative keyword. Their size is decided by nesting, "
            . "so the ladder cannot hold them; only the root's own percentage is allowed:\n%s",
            count($rel), implode("\n", array_slice($rel, 0, 60))
        ));
    }

    // ══ the tokens and the root ═══════════════════════════════════════════════

    /**
     * The conversion is written once, in tokens.css, and it is exact.
     *
     * Every `--ag-fs-*` is named by its px (a hyphen for the half point) and valued at
     * that px over the 16px root, so at 100% a page is the DC's to the pixel. Every
     * ladder rung has a step — the ladder IS the tokens below 18 — and no step below 18
     * exists that is not a rung, which is the closed ladder said a second way.
     */
    public function test_the_type_tokens_are_the_ladder_and_each_name_is_its_px(): void
    {
        $css = (string) file_get_contents(PublicSurface::root() . '/public/assets/css/tokens.css');
        preg_match_all('~(--ag-fs-([0-9]+)(?:-(5))?)\s*:\s*([^;}\s]+)~', $css, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'tokens.css declares no --ag-fs-* step');

        $small = [];
        foreach ($m as [, $name, $int, $half, $value]) {
            $px = (float) $int + ($half !== '' ? 0.5 : 0);
            $this->assertMatchesRegularExpression('~^[0-9]*\.?[0-9]+rem$~', $value, "$name is not a rem value");
            $this->assertEqualsWithDelta($px, (float) $value * self::ROOT_PX, 1e-9,
                "$name is $value, which is not {$px}px at the 16px root");
            if ($px < self::DISPLAY_FLOOR) $small[] = $px;
        }
        sort($small);
        $this->assertSame(array_map('floatval', self::LADDER), $small,
            'the --ag-fs steps under 18px must be exactly the §6.2 ladder');
    }

    /**
     * The setting moves the root, and the type follows it.
     *
     * Both halves, because each was once true without the other: the root moved and px
     * type ignored it. The classes are the ones the two writers put on <html> — the
     * first-paint script and `a11y.js` — so a renamed class fails here rather than
     * becoming a setting that styles nothing. And nothing else on the public surface
     * may set the root's size: one `:root{font-size:16px}` later in any sheet would
     * pin it and quietly switch the setting off again.
     */
    public function test_the_text_size_setting_moves_the_root_and_the_type_follows(): void
    {
        $root = PublicSurface::root();
        $roots = self::rootSizes(self::publicSources());

        $want = [];
        foreach (self::TEXT_SIZES as $class => $pct) {
            $want[$class === '' ? 'html' : "html.$class"] = $pct . '%';
        }
        $this->assertSame($want, $roots['by_selector'],
            'the root sizes are not exactly html 100% / html.ag-t125 125% / html.ag-t150 150%');
        $this->assertSame([], $roots['elsewhere'], 'something else sets the root font size');

        $head = (string) file_get_contents($root . '/templates/partials/a11y-head.twig');
        $js   = (string) file_get_contents($root . '/public/assets/js/a11y.js');
        foreach (array_filter(array_keys(self::TEXT_SIZES)) as $class) {
            $this->assertStringContainsString("'$class'", $head, "the first-paint script never writes $class");
            $this->assertStringContainsString("'$class'", $js, "a11y.js never writes $class");
        }

        // The type follows: at 150% every resolved screen size is 1.5× its 100% value.
        // That holds only for a rem size, which is the claim — computed rather than
        // inferred from the unit, so a size the sweep cannot convert is not counted as
        // following.
        $f = self::findings(self::publicSources());
        $this->assertGreaterThan(0, $f['rem']);
        foreach ($f['resolved'] as [$where, $unit, $value]) {
            $at100 = self::px($unit, $value, 100);
            $at150 = self::px($unit, $value, 150);
            if ($at100 === null || $at150 === null) continue;
            $this->assertEqualsWithDelta($at100 * 1.5, $at150, 1e-9, "$where does not grow with the text-size setting");
        }
    }

    // ══ the allowance and the held surfaces ═════════════════════════════════

    /** The allowance is deleted, and stays deleted. */
    public function test_ten_and_eleven_are_not_rungs_and_no_allowance_exists(): void
    {
        $this->assertNotContains(10, self::LADDER);
        $this->assertNotContains(11, self::LADDER);
        $this->assertSame(11.5, min(self::LADDER), 'the smallest type on the public site is 11.5px (§6.2 micro)');
        $this->assertFalse(defined(self::class . '::MIGRATING'),
            'the MIGRATING allowance was deleted by the owner (GAPS Q4, 3 Oct 2026)');
    }

    /** An exclusion is honest only while nothing public links what it excludes. */
    public function test_the_held_sheets_are_not_on_the_public_surface(): void
    {
        $root = PublicSurface::root();
        $linked = [];
        foreach (PublicSurface::HELD_CSS as $held) {
            $this->assertFileExists($root . '/' . $held, "held sheet $held is gone; remove it from PublicSurface::HELD_CSS");
            $name = substr($held, strlen('public'));
            foreach (PublicSurface::files(true) as $rel => $abs) {
                if (str_ends_with($rel, '.twig') && str_contains((string) file_get_contents($abs), $name)) {
                    $linked[] = "$rel links $name";
                }
            }
        }
        $this->assertSame([], $linked, 'a public page links a held sheet, so its type is public and unswept');
        $this->assertNotEmpty(PublicSurface::files(true), 'the public surface resolved to nothing');
    }

    /** A held template stays held only while nothing public includes it. */
    public function test_the_held_partials_are_included_only_by_held_screens(): void
    {
        $root = PublicSurface::root();
        $leaks = [];
        foreach (PublicSurface::HELD_TEMPLATES as $held) {
            $this->assertFileExists($root . '/' . $held, "held template $held is gone; remove it from PublicSurface::HELD_TEMPLATES");
            $name = substr($held, strlen('templates/'));
            foreach (PublicSurface::files(true) as $rel => $abs) {
                if (str_ends_with($rel, '.twig') && str_contains((string) file_get_contents($abs), $name)) {
                    $leaks[] = "$rel includes $name";
                }
            }
        }
        $this->assertSame([], $leaks, 'a public template includes a held template, so its type is public and unswept');
    }

    /**
     * The door's sheet is held because the door alone reads it — so that is asked.
     *
     * The owner held the door scanner on 3 Oct 2026 (a staff tool, outside the
     * redesign). Its sheet carries 10px mono capitals; held, they are the door's
     * business. Linked by ANY other template — public, admin or judge — they would be
     * somebody else's page's type that no sweep reads, so every template in the tree is
     * asked, not just the public ones. The bundle is asked too: a sheet in
     * `AssetBundle::STYLESHEETS` is on every public page without a single `<link>`.
     */
    public function test_the_held_door_sheet_is_linked_only_by_the_held_door(): void
    {
        $root = PublicSurface::root();
        $sheet = substr(PublicSurface::DOOR_CSS, strlen('public/'));        // assets/css/components/door.css
        $this->assertContains(PublicSurface::DOOR_CSS, PublicSurface::HELD_CSS);
        $this->assertContains(PublicSurface::DOOR_TEMPLATE, PublicSurface::HELD_TEMPLATES);

        $readers = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!str_ends_with($f->getFilename(), '.twig')) continue;
            if (str_contains((string) file_get_contents($f->getPathname()), $sheet)) {
                $readers[] = ltrim(str_replace($root, '', $f->getPathname()), '/');
            }
        }
        $this->assertSame([PublicSurface::DOOR_TEMPLATE], $readers,
            'the held door sheet must be linked by the door template and by nothing else');
        $this->assertNotContains($sheet, AssetBundle::STYLESHEETS, 'the door sheet is in the public bundle');
    }

    // ══ the reader, run over planted text ════════════════════════════════════

    /**
     * A sweep is evidence only once it has been seen to fail, so the extractor runs over
     * planted text on every run: each break below must be named, and each correct
     * declaration must not be.
     */
    public function test_the_reader_finds_type_where_it_hides(): void
    {
        $f = self::findings([
            'a.css'  => ".a{ font:600 11px/1 var(--ag-font-ui) }\n"                        // 1 shorthand px, off
                      . "@media (min-width:900px){ .b{ font-size:.625rem } }\n"           // 2 rem 10px, in a query
                      . ":root{ --t-h1:2.75rem; --t-fl:clamp(2rem,4vw,3rem); --t-x:.72rem }\n"
                      . ".c{ font-size:var(--t-h1) }\n.d{ font-size:.72rem }\n"           // 4 ok · 5 11.52px off
                      . ".e{ font-size:var(--t-fl) }\n.f{ font:700 2.5vw/1.1 serif }\n"   // 6 · 7 fluid
                      . "/* .g{ font-size:9px } a comment reaches nobody */\n"
                      . ".h{ font-size:.90625rem } .i{ font:600 var(--t-x)/1 x }\n"       // 9 ok · 9 token off
                      . ".j{ font-size:.9em } .k{ font-size:90% } .l{ font-size:smaller }\n" // 10 relative ×3
                      . "html{ font-size:100% } html.ag-t150{ font-size:150% } html.big{ font-size:130% }\n"
                      . ".m{ font-size:14.5px }\n",                                        // 12 px on a screen
            'b.twig' => "<p class=\"k\" style=\"font-size:.625rem\">x</p>\n"
                      . "{# <p style=\"font-size:9px\"> #}",
            'templates/emails/m.twig' => "<td style=\"font-size:14.5px\">a</td>\n"                    // px in mail: fine
                      . "<div style=\"display:none;font-size:1px;mso-hide:all\">pre</div>\n"
                      . "<div style=\"display:none;font-size:1px\">not a preheader</div>\n"
                      . "<p style=\"font-size:11px\">x</p>",
        ]);

        $off = implode("\n", $f['off']);
        $this->assertStringContainsString('a.css:1  11px', $off, 'the shorthand');
        $this->assertStringContainsString('a.css:2  10px', $off, 'a rem inside a media query, at 16px to the rem');
        $this->assertStringContainsString('a.css:5  11.52px', $off, 'an off-ladder rem: .72rem is 11.52px');
        $this->assertStringContainsString('a.css:9  11.52px', $off, 'a token reached through the shorthand');
        $this->assertStringContainsString('b.twig:1  10px', $off, 'an inline style attribute');
        $this->assertStringContainsString('m.twig:3  1px', $off, '1px without mso-hide:all is type');
        $this->assertStringContainsString('m.twig:4  11px', $off, 'mail is on the ladder too');
        $this->assertStringNotContainsString('m.twig:2', $off, 'the preheader collapse is excused');
        $this->assertStringNotContainsString('9px', $off, 'a comment is not type');
        $this->assertStringNotContainsString('a.css:4', $off, 'display sizes are off the small ladder by design');
        $this->assertStringNotContainsString('14.5', $off);

        $fluid = implode("\n", $f['fluid']);
        $this->assertStringContainsString('a.css:6', $fluid, 'a fluid size through a custom property');
        $this->assertStringContainsString('a.css:7', $fluid, 'vw in the shorthand');
        $this->assertStringNotContainsString('a.css:4', $fluid, 'a fixed size is not fluid');

        $px = implode("\n", $f['px']);
        $this->assertStringContainsString('a.css:1', $px, 'px in a stylesheet');
        $this->assertStringContainsString('a.css:12', $px, 'an on-ladder px is still px');
        $this->assertStringNotContainsString('m.twig', $px, 'px is right in mail');

        $rel = implode("\n", $f['relative']);
        foreach (['.9em', '90%', 'smaller', '130%'] as $v) {
            $this->assertStringContainsString($v, $rel, "$v is a size decided by the parent (or an unknown root step)");
        }
        $this->assertStringNotContainsString('html{', $rel);
        $this->assertStringNotContainsString(' 100%', $rel, 'the root at 100% is the reader\'s default');
        $this->assertStringNotContainsString(' 150%', $rel, 'html.ag-t150 is the setting');
    }

    // ══ the reader ══════════════════════════════════════════════════════════

    /** @return array<string,string> rel => source, for the public surface */
    private static function publicSources(): array
    {
        $out = [];
        foreach (PublicSurface::files(true) as $rel => $abs) {
            $out[$rel] = (string) file_get_contents($abs);
        }
        return $out;
    }

    /**
     * Every font size in a set of sources, sorted into what is wrong with it.
     *
     * Takes the sources rather than reading the tree so the same code runs over planted
     * text in `test_the_reader_finds_type_where_it_hides` — a self-test of a copy would
     * prove the copy.
     *
     * @param array<string,string> $sources rel => source (`.css` or `.twig`)
     * @return array{off:list<string>,fluid:list<string>,px:list<string>,relative:list<string>,rem:int,resolved:list<array{0:string,1:string,2:float}>}
     */
    public static function findings(array $sources): array
    {
        [$parsed, $vars] = self::parse($sources);

        $out = ['off' => [], 'fluid' => [], 'px' => [], 'relative' => [], 'rem' => 0, 'resolved' => []];
        foreach ($parsed as [$rel, $text, $sel, $body, $at, $decls]) {
            $mail = str_contains($rel, 'emails/');
            foreach ($decls as [$p, $v, $dAt]) {
                if ($p !== 'font-size' && $p !== 'font') continue;
                $line  = PublicSurface::lineAt($text, $at + $dAt);
                $where = sprintf('%s:%d', $rel, $line);

                foreach (self::through($v, $vars) as $t) {
                    if (preg_match(self::FLUID, $t)) { $out['fluid'][] = "$where  $p: $v"; break; }
                }

                foreach (self::sizes($p, $v, $vars) as [$num, $unit, $raw]) {
                    $unit = strtolower($unit);
                    if ($unit === 'keyword') {
                        $out['relative'][] = "$where  $sel { $p: $raw }";
                        continue;
                    }
                    if ($unit !== 'px' && $unit !== 'rem') {
                        // The root's own percentage, at one of the setting's steps, is the
                        // one relative size that belongs; everything else is nesting.
                        $ok = $unit === '%' && $p === 'font-size' && self::isRoot($sel)
                            && in_array((int) round($num), self::TEXT_SIZES, true);
                        if (!$ok) $out['relative'][] = "$where  $sel { $p: $raw }";
                        continue;
                    }
                    $px = self::px($unit, $num, 100);
                    if (!$mail) $out['resolved'][] = [$where, $unit, $num];
                    if ($unit === 'rem') $out['rem']++;
                    if ($unit === 'px' && !$mail) $out['px'][] = "$where  $sel { $p: $v }";

                    if ($px >= self::DISPLAY_FLOOR) continue;
                    // The preheader collapse: by what it does, never by file.
                    if ($px <= 1.0 && preg_match('~mso-hide\s*:\s*all~i', $body)) continue;
                    if (!in_array(round($px, 4), array_map('floatval', self::LADDER), true)) {
                        $out['off'][] = sprintf('%s  %spx', $where, rtrim(rtrim(sprintf('%.4F', $px), '0'), '.'));
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Every rule that sets the ROOT's font size, by selector.
     *
     * @param array<string,string> $sources
     * @return array{by_selector:array<string,string>,elsewhere:list<string>}
     */
    private static function rootSizes(array $sources): array
    {
        [$parsed] = self::parse($sources);
        $by = $else = [];
        foreach ($parsed as [$rel, $text, $sel, $body, $at, $decls]) {
            if (!self::isRoot($sel)) continue;
            foreach ($decls as [$p, $v, $dAt]) {
                if ($p !== 'font-size' && $p !== 'font') continue;
                if ($rel === 'public/assets/css/shell.css' && $p === 'font-size' && !isset($by[$sel])) {
                    $by[$sel] = $v;
                } else {
                    $else[] = sprintf('%s:%d  %s { %s: %s }', $rel, PublicSurface::lineAt($text, $at + $dAt), $sel, $p, $v);
                }
            }
        }
        return ['by_selector' => $by, 'elsewhere' => $else];
    }

    /**
     * @param array<string,string> $sources
     * @return array{0:list<array{0:string,1:string,2:string,3:string,4:int,5:list<array{0:string,1:string,2:int}>}>,1:array<string,list<string>>}
     */
    private static function parse(array $sources): array
    {
        $parsed = [];
        $vars   = [];
        foreach ($sources as $rel => $src) {
            $twig = str_ends_with($rel, '.twig');
            $text = PublicSurface::strip($src, $twig);
            foreach (PublicSurface::rules($text, $twig) as [$sel, $body, $at]) {
                $decls = PublicSurface::declarations($body);
                foreach ($decls as [$p, $v]) {
                    if (str_starts_with($p, '--')) $vars[$p][] = $v;
                }
                $parsed[] = [$rel, $text, $sel, $body, $at, $decls];
            }
        }
        return [$parsed, $vars];
    }

    /** `html`, `:root`, or either with a class/attribute/pseudo — every part of a list. */
    private static function isRoot(string $sel): bool
    {
        foreach (explode(',', $sel) as $one) {
            if (!preg_match('/^\s*(?:html|:root)(?:[.\[:#][^\s>+~,]*)?\s*$/i', $one)) return false;
        }
        return trim($sel) !== '';
    }

    /**
     * A value and every value its `var()`s resolve to, to a fixpoint.
     *
     * @param array<string,list<string>> $vars
     * @return list<string>
     */
    private static function through(string $v, array $vars, int $depth = 0): array
    {
        $out = [$v];
        if ($depth > 6) return $out;
        preg_match_all('~var\(\s*(--[\w-]+)~', $v, $vm);
        foreach ($vm[1] as $name) {
            foreach ($vars[$name] ?? [] as $vv) {
                foreach (self::through($vv, $vars, $depth + 1) as $t) $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * The sizes one declaration sets, as [number, unit, as-written]. In the shorthand,
     * the size is the first length or the first `var()` resolving to one — the
     * line-height after `/` and the family after it are not type.
     *
     * @param array<string,list<string>> $vars
     * @return list<array{0:float,1:string,2:string}>
     */
    private static function sizes(string $prop, string $value, array $vars): array
    {
        $v = trim($value);
        if ($prop === 'font') {
            $head = trim(explode('/', $v)[0]);
            foreach (preg_split('~\s+(?![^(]*\))~', $head) ?: [] as $tok) {
                if (preg_match(self::LENGTH, $tok, $m)) return [[(float) $m[1], $m[2], $tok]];
                if (str_starts_with($tok, 'var(')) {
                    $found = self::sizes('font-size', $tok, $vars);
                    if ($found) return $found;
                }
            }
            return [];
        }
        if (preg_match(self::KEYWORDS, $v)) return [[0.0, 'keyword', $v]];

        $out = [];
        $stripped = (string) preg_replace('~var\([^)]*\)~', ' ', $v);
        preg_match_all(self::LENGTH, $stripped, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) $out[] = [(float) $m[1], $m[2], $m[0]];
        preg_match_all('~var\(\s*(--[\w-]+)~', $v, $vm);
        foreach ($vm[1] as $name) {
            foreach ($vars[$name] ?? [] as $vv) {
                foreach (self::sizes('font-size', $vv, $vars) as $s) $out[] = $s;
            }
        }
        return $out;
    }

    /** A px or rem size at a given text-size setting; null for anything else. */
    private static function px(string $unit, float $value, int $setting): ?float
    {
        return match (strtolower($unit)) {
            'rem'   => $value * self::ROOT_PX * $setting / 100,
            'px'    => $value,
            default => null,
        };
    }
}
