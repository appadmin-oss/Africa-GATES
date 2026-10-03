<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A custom property declared and never read.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS WORTH A TEST RATHER THAN A TIDY-UP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `CLAUDE.md` §17's rule at the stylesheet layer: a declared thing with no reader.
 * Fifty-five had accumulated — seven greys of a `--kale-*` ramp, twelve `--s-*` spacing
 * steps, two shadow sets declared `none`, three border presets — all of them from
 * palettes the house style replaced. The cost is not the bytes. It is that the next
 * person reads `--shadow-lg: none` and takes it for the site's shadow convention, then
 * writes `box-shadow: var(--shadow-lg)` and gets nothing, on a token that has not been
 * part of this design system for years.
 *
 * ── THE SWEEP LIES IN THREE WAYS, AND EACH ONE REPORTED LIVE CODE DEAD ───────
 *
 * Every one of these was observed while writing this, not imagined:
 *
 *  1. **BEM's modifier separator is the custom-property sigil.** `.ag-share__btn--x:hover{`
 *     matches `--name:` exactly as a declaration does, so a text-level sweep reported
 *     thirty-odd live selectors — `--x`, `--wa`, `--fb`, `--yes`, `--no`, `--light` — as
 *     dead tokens. Depth alone does not fix it either: an at-rule opens a block, so
 *     inside `@media (…){ .hm-cal__c--mark::after{ … } }` the selector sits at depth 1
 *     and reads as a declaration again. A declaration is the FIRST thing in its
 *     `;`-separated segment of an innermost block; a selector never is.
 *
 *  2. **A reader can be a file you are not allowed to edit.** `--plyr-color-main` is
 *     Plyr's own theming API: declared in ours, consumed in `css/vendor/`. A sweep that
 *     skips vendored files to avoid editing them reports our declaration dead and takes
 *     the player's colour with it. Declarations are read from authored source only;
 *     READERS are counted everywhere, the built bundle and vendored code included.
 *
 *  3. **A script can name a property without writing `var()`.** stripe-gradient holds
 *     `['--gradient-color-1', … , '--gradient-color-4']` and reads the four off the
 *     canvas in a loop, so a sweep knowing only `var()` and `getPropertyValue('--x')`
 *     sees the first and calls the other three dead — three quarters of the homepage
 *     aurora's palette. Any quoted `--name` anywhere counts as a reader.
 */
final class DeadTokenTest extends TestCase
{
    /** Declarations are authored here; readers may live anywhere, these two included. */
    private const NOT_AUTHORED = ['vendor', 'dist'];

    /** @return array{0: array<string, array<int, string>>, 1: array<string, true>} */
    private function tokens(): array
    {
        $root  = dirname(__DIR__, 2);
        $decls = [];
        $reads = [];

        foreach (['templates', 'public/assets', 'src', 'resources'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir"));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                $path = $f->getPathname();
                if (!preg_match('~\.(css|twig|js|php|html|svg)$~', $path)) continue;
                $body = (string) file_get_contents($path);
                $rel  = ltrim(str_replace($root, '', $path), '/');

                // Readers, from every file — see lie (2) and lie (3).
                foreach (['~var\(\s*(--[A-Za-z0-9_-]+)~', '~[\'"`](--[A-Za-z0-9_-]+)[\'"`]~'] as $re) {
                    preg_match_all($re, $body, $m);
                    foreach ($m[1] as $n) $reads[$n] = true;
                }

                $parts = explode(DIRECTORY_SEPARATOR, str_replace($root, '', $path));
                if (array_intersect($parts, self::NOT_AUTHORED)) continue;
                if (!preg_match('~\.(css|twig|html)$~', $path)) continue;

                foreach ($this->declarations($body) as [$line, $name]) {
                    $decls[$name][] = "$rel:$line";
                }
            }
        }
        // ── COLOUR IS DECLARED IN PHP, AND IS STILL A DECLARATION ────────────
        //
        // Support\Accent emits the palette and the shadows into the layout's <style>;
        // no stylesheet declares them. Reading only CSS would have exempted every
        // colour token from this rule the moment colour moved there — a sweep going
        // quiet over exactly the file that holds what it was written for.
        foreach (array_merge(array_keys(\AfricaGates\Support\Accent::palette()),
                             array_keys(\AfricaGates\Support\Accent::shadows())) as $name) {
            $decls['--ag-' . $name][] = 'src/Support/Accent.php';
        }

        return [$decls, $reads];
    }

    /**
     * Declarations, from the innermost blocks only — see lie (1).
     *
     * @return list<array{0:int, 1:string}>
     */
    private function declarations(string $text): array
    {
        $out   = [];
        $stack = [];   // one frame per open block, innermost last
        $line  = 1;

        // ── A TRAILING COMMENT HID THE NEXT DECLARATION, AND THAT IS LIE (4) ──
        //
        // A declaration is recognised as the first thing in its `;`-separated segment.
        // A comment is not stripped, so `--a:red; /* why */` leaves the NEXT segment
        // beginning with `/* why */` — and `--b` after it is skipped entirely.
        //
        // In a token file where almost every line carries an explanatory comment, that
        // is most of the file: `--ag-stock-low` sat dead behind `--ag-error`'s comment
        // and this sweep reported a clean pass over it. It surfaced only because an
        // unrelated token was inserted between the two, which is the worst way for a
        // guard to be wrong — it goes quiet exactly in the files somebody documented,
        // which in this repo is all of them.
        //
        // Comments are replaced by a space rather than removed, so the line numbers the
        // findings carry stay true. `str_repeat` keeps every newline for the same reason.
        $text = (string) preg_replace_callback('~/\*.*?\*/~s', static function (array $m): string {
            return str_repeat("\n", substr_count($m[0], "\n")) . ' ';
        }, $text);

        foreach (str_split($text) as $ch) {
            if ($ch === "\n") $line++;

            if ($ch === '{') {
                // Whatever encloses this is an at-rule or a nesting parent, not a
                // declaration block — see lie (1). Tracking it per frame rather than
                // once for the file is what lets a rule inside `@media` still be read;
                // a single flag made every declaration under a media query invisible,
                // which is the quiet half of the same fault.
                if ($stack) $stack[count($stack) - 1]['nested'] = true;
                $stack[] = ['line' => $line, 'buf' => '', 'nested' => false];
                continue;
            }

            if ($ch === '}') {
                $frame = array_pop($stack) ?: null;
                if ($frame !== null && !$frame['nested']) {
                    foreach (explode(';', $frame['buf']) as $seg) {
                        if (preg_match('~^\s*(--[A-Za-z0-9_-]+)\s*:~', $seg, $m)) {
                            $out[] = [$frame['line'], $m[1]];
                        }
                    }
                }
                continue;
            }

            if ($stack) $stack[count($stack) - 1]['buf'] .= $ch;
        }

        // Inline `style="--x: …"` is a declaration too, and lives outside any block.
        if (preg_match_all('~style\s*=\s*"([^"]*)"~', $text, $m)) {
            foreach ($m[1] as $attr) {
                foreach (explode(';', $attr) as $seg) {
                    if (preg_match('~^\s*(--[A-Za-z0-9_-]+)\s*:~', $seg, $mm)) {
                        $out[] = [0, $mm[1]];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * DECLARED, UNREAD, AND NOT OURS TO DELETE — A LIST THAT ONLY SHRINKS.
     *
     * On 3 Oct 2026 the old public pages were destroyed (docs/handoff/DESTROYED.md) and
     * these lost their last reader; the orphans those pages left behind were destroyed
     * the same day and took the second half of the list with them. Every entry is the
     * handoff's palette or shadows, which the owner decided ship ±0 under the handoff's
     * names (GAPS §8 Q1) and which AccentTest pins name for name — so deleting one is an
     * owner decision, not a tidy-up. The non-palette tokens that went dead the same way
     * were simply removed: tokens.css's spacing and layer steps (restored at the
     * handoff's value by the phase that rebuilds a reader), and two legacy main.css
     * tokens whose readers were the destroyed legacy sheets.
     *
     *  - the first seven lost their readers with the pages;
     *  - `--ag-gold`, `-gold-edge`, `-gold-ink`, `-green-light`, `-green-wash` and `--ag-info`
     *    lost theirs with the orphans (`components/awards.css`, `components/promo.css`,
     *    `js/favicon.js`).
     *
     * The `--ob-accent*` three that used to close this list were written by
     * `partials/org-page.twig`; with their writer destroyed they are declared nowhere, so
     * they left it rather than being carried as a name nothing holds.
     *
     * Each entry leaves the moment a rebuilt page reads it — the test below fails on an
     * entry that has a reader, or one nothing declares any more.
     */
    // Phase 2 (the shared chrome, 3 Oct 2026) gave eight of these their reader back in
    // components/chrome.css — the mega and Menu tile tones (gold-wash-2, gold-ink,
    // green-wash, live-wash, live-ink), the status dot (gold), the flash mark (info) and
    // the mega panel's shadow (sh-mega) — and they left the list as it requires.
    private const AWAITING_REBUILD = [
        // Phase 3 (Gee) gave green-edge, info-wash and green-light their readers.
        '--ag-gold-wash', '--ag-gold-edge',
    ];

    public function test_the_awaiting_rebuild_list_only_shrinks(): void
    {
        [$decls, $reads] = $this->tokens();
        $stale = [];
        foreach (self::AWAITING_REBUILD as $name) {
            if (!isset($decls[$name])) $stale[] = "$name: no longer declared — delete its line";
            elseif (isset($reads[$name])) $stale[] = "$name: has a reader now — delete its line";
        }
        $this->assertSame([], $stale, implode("\n", $stale));
    }

    public function test_no_custom_property_is_declared_without_a_reader(): void
    {
        [$decls, $reads] = $this->tokens();

        $dead = [];
        foreach ($decls as $name => $at) {
            if (in_array($name, self::AWAITING_REBUILD, true)) continue;
            if (!isset($reads[$name])) $dead[] = $name . '  ' . $at[0];
        }
        sort($dead);

        $this->assertSame([], $dead, sprintf(
            "%d custom propert%s declared and never read:\n%s",
            count($dead), count($dead) === 1 ? 'y' : 'ies', implode("\n", $dead)
        ));
    }

    /**
     * The sweep above is only worth anything if it can still SEE a declaration, and lie
     * (1)'s fix — innermost blocks only — is the part most likely to be loosened by
     * somebody who finds it fiddly. So it is held directly.
     */
    public function test_the_parser_tells_a_declaration_from_a_bem_modifier(): void
    {
        $css = <<<'CSS'
        .btn--x:hover{ color:#111; --live-one:red; }
        @media (max-width:400px){ .cal__c--mark::after{ --live-two:blue; } }
        CSS;

        $found = array_column($this->declarations($css), 1);
        sort($found);

        $this->assertSame(['--live-one', '--live-two'], $found,
            'the parser is reading BEM modifiers as declarations, or missing declarations inside an at-rule');
    }
}
