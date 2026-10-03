<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\PublicSurface;

/**
 * MONOSPACE IS FOR REFERENCES AND CODES, AND NOTHING ON THE PUBLIC SITE SHOUTS.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULE, AND WHY IT NEEDED AN OWNER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REFERENCE §6.2 and §18.3–4: `JetBrains Mono` only for references and codes — order
 * refs, ticket codes, receipt codes, times inside ticket stubs — and **never** for
 * labels, stats or kickers; no uppercase labels except the ticket stub's CONFIRMED
 * stamp, which is a graphic; headings in sentence case.
 *
 * The handoff contradicted itself on exactly this (GAPS C6): HANDOFF §2 "Mono … numbers,
 * codes, times", §5e "a mono count", SKILL §24.3 "mono 17px value" against §24.7 "no
 * monospace" — and the DC files themselves set `text-transform:uppercase` in fifteen
 * places. The README's §0 puts §18 above all of them, and the owner confirmed it on
 * 3 Oct 2026 (GAPS §8 Q5): the README wins. This file is that answer made executable,
 * because the house style it replaced said the opposite in as many words — "hairline
 * rules, mono micro-labels" — and 214 declarations had been written to it. A
 * convention that reverses a documented one is the one most likely to be "corrected"
 * back by the next person reading the old prose.
 *
 * ── WHAT MONO COSTS WHEN IT IS A LABEL ───────────────────────────────────────
 *
 * Monospace says "copy me exactly". On a reference that is the truth — somebody reads a
 * ticket code to a steward or types an order ref into a support form, and fixed-width
 * glyphs are what tell `0` from `O` and keep a column of times aligned in a stub. On a
 * kicker or a stat it is a costume: it makes "Votes this week" look like machine output,
 * spends the one typographic signal the page has for "this is a code" on decoration,
 * and at micro sizes mono is the least legible face on the page. All-caps compounds it —
 * word shapes vanish, so the label is read letter by letter, and a screen reader may
 * spell it out.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE CONVENTION THAT DECIDES "IS THIS A REFERENCE?" — DERIVED FROM §18.3
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A rule may set a monospace face only when its selector NAMES what it is setting, by a
 * class segment (split on `-` and `_`) drawn from §18.3's own list:
 *
 *   `ref` / `refs`      — order refs
 *   `code` / `codes`    — ticket codes, receipt codes
 *   `stub`              — times inside ticket stubs (an ancestor `…stub` is enough:
 *                         `.tk-stub time` is a time inside a stub)
 *
 * or by the HTML elements whose meaning is already "code": `code`, `kbd`, `samp`, `pre`.
 * The name is the claim, and the claim is reviewable: a class called `.order-ref`
 * wearing mono is obviously right, and `.stat-ref` is a lie somebody has to type.
 *
 * "Monospace" is recognised by VALUE, not by one token's name: `--ag-font-mono`, the
 * family `JetBrains Mono`, the generic `monospace`, and — resolved to a fixpoint — any
 * custom property whose value is one of those. The door defines its own `--dr-mono`;
 * a sweep knowing only `--ag-font-mono` would pass every mono kicker on that page.
 *
 * Uppercase is `text-transform:uppercase`, `text-transform:capitalize` (Title Case,
 * which §6.2's sentence-case headings forbid) and every caps `font-variant`/
 * `font-variant-caps` value. The one exception is a selector with a `stamp` segment.
 * Literal all-caps text in a template — two or more words, every letter a capital, the
 * shape of a typed micro-label like "FINAL HOURS" — is refused too, because removing
 * `text-transform` and typing the capitals in is the same label with the guard stepped
 * round. A single all-caps word is not judged: it is usually an acronym.
 *
 * ── SCOPE ────────────────────────────────────────────────────────────────────
 *
 * The public surface (`Tests\Support\PublicSurface`) WITHOUT `templates/emails/`: these
 * are the handoff's rules for screens, and mail clients are a different medium the
 * handoff does not draw. The admin and judge consoles are held by the owner and are out,
 * explicitly. Not read: JavaScript, so a script that sets `style.textTransform` or
 * writes capitals into the DOM is invisible here — the celebration engine's CONFIRMED
 * stamp is exactly such a script, and is the exception anyway.
 */
final class MonoAndCaseTest extends TestCase
{
    /** §18.3's list, as class-name segments. */
    public const MONO_SEGMENTS = ['ref', 'refs', 'code', 'codes', 'stub'];

    /** Elements whose meaning is already "this is code". */
    public const MONO_ELEMENTS = ['code', 'kbd', 'samp', 'pre'];

    /** §6.2: "No uppercase labels, except the ticket-stub CONFIRMED stamp". */
    public const CAPS_SEGMENT = 'stamp';

    public function test_monospace_is_only_for_references_and_codes(): void
    {
        $bad = self::findings(self::publicSources())['mono'];

        $this->assertSame([], $bad, sprintf(
            "%d public rule(s) set a monospace face on something that is not a reference or a code "
            . "(§6.2, §18.3; the selector must name `%s` or be %s):\n%s",
            count($bad), implode('`/`', self::MONO_SEGMENTS), implode('/', self::MONO_ELEMENTS),
            implode("\n", array_slice($bad, 0, 60))
        ));
    }

    public function test_nothing_public_is_set_in_capitals_but_the_stamp(): void
    {
        $bad = self::findings(self::publicSources())['caps'];

        $this->assertSame([], $bad, sprintf(
            "%d public uppercase/small-caps/title-case label(s) (§6.2, §18.4 — only a `.…stamp`):\n%s",
            count($bad), implode("\n", array_slice($bad, 0, 60))
        ));
    }

    /**
     * Both detectors fire on planted violations, and clear what the convention allows.
     *
     * Run on every pass, not once: the alias chain, the shorthand, the media query and
     * the inline attribute are each a place an earlier sweep here went blind.
     */
    public function test_the_detectors_fail_on_what_they_exist_to_stop(): void
    {
        $f = self::findings([
            'a.css' => ":root{ --x-mono:var(--ag-font-mono); --y-mono:var(--x-mono) }\n"
                     . ".hero__kicker{ font:600 12px/1 var(--y-mono) }\n"
                     . "@media (min-width:900px){ .stat__n{ font-family:'JetBrains Mono',monospace } }\n"
                     . ".order-ref{ font-family:var(--ag-font-mono) } .tk-stub time{ font:500 12px/1 var(--x-mono) }\n"
                     . "pre, code{ font-family:monospace } .ticket__code{ font-family:var(--ag-font-mono) }\n"
                     . ".tag{ text-transform:uppercase }\n.h2{ text-transform: capitalize }\n"
                     . ".sc{ font-variant-caps:all-small-caps }\n.tk-stamp{ text-transform:uppercase }\n"
                     . "/* .gone{ text-transform:uppercase } */ .prefs{ font-family:var(--ag-font-ui) }",
            'b.twig' => "<span class=\"pill\" style=\"text-transform:uppercase\">x</span>\n"
                      . "<b class=\"kpi\" style=\"font-family:var(--ag-font-mono)\">9</b>\n"
                      . "<p class=\"eyebrow\">FINAL HOURS</p><abbr>FAQ</abbr>\n"
                      . "<span class=\"tk-stamp\">CONFIRMED STAMP</span>\n"
                      . "<style>.lbl{ font:700 11.5px/1 var(--ag-font-mono) }</style>",
        ]);

        $mono = implode("\n", $f['mono']);
        $this->assertStringContainsString('a.css:2  .hero__kicker', $mono, 'mono through a two-step alias, in the shorthand');
        $this->assertStringContainsString('a.css:3  .stat__n', $mono, 'mono inside a media query, by family name');
        $this->assertStringContainsString('b.twig:2  b.kpi', $mono, 'mono in an inline style');
        $this->assertStringContainsString('b.twig:5  .lbl', $mono, 'mono in a <style> element');
        $this->assertStringNotContainsString('order-ref', $mono);
        $this->assertStringNotContainsString('tk-stub', $mono);
        $this->assertStringNotContainsString('pre, code', $mono);
        $this->assertStringNotContainsString('ticket__code', $mono);
        $this->assertStringNotContainsString('a.css:1', $mono, 'declaring an alias is not using it');
        $this->assertStringNotContainsString('prefs', $mono, '`prefs` is not the segment `ref`');

        $caps = implode("\n", $f['caps']);
        $this->assertStringContainsString('a.css:6  .tag', $caps);
        $this->assertStringContainsString('a.css:7  .h2', $caps, 'capitalize is Title Case');
        $this->assertStringContainsString('a.css:8  .sc', $caps);
        $this->assertStringContainsString('b.twig:1  span.pill', $caps);
        $this->assertStringContainsString('b.twig:3  FINAL HOURS', $caps, 'typed capitals are the same label');
        $this->assertStringNotContainsString('FAQ', $caps, 'one all-caps word is usually an acronym');
        $this->assertStringNotContainsString('tk-stamp', $caps, 'the stamp is the exception');
        $this->assertStringNotContainsString('CONFIRMED', $caps, 'the stamp is the exception');
        $this->assertStringNotContainsString('gone', $caps, 'a comment reaches nobody');
    }

    /** @return array<string,string> */
    private static function publicSources(): array
    {
        $out = [];
        foreach (PublicSurface::files(false) as $rel => $abs) {
            $out[$rel] = (string) file_get_contents($abs);
        }
        return $out;
    }

    /**
     * @param array<string,string> $sources rel => source
     * @return array{mono:list<string>,caps:list<string>}
     */
    public static function findings(array $sources): array
    {
        $parsed = [];
        $vars   = [];
        foreach ($sources as $rel => $src) {
            $twig = str_ends_with($rel, '.twig');
            $text = PublicSurface::strip($src, $twig);
            $rules = PublicSurface::rules($text, $twig);
            foreach ($rules as [, $body]) {
                foreach (PublicSurface::declarations($body) as [$p, $v]) {
                    if (str_starts_with($p, '--')) $vars[$p][] = $v;
                }
            }
            $parsed[$rel] = [$text, $twig, $rules];
        }

        // Every custom property that IS a monospace face, to a fixpoint — so an alias of
        // an alias of `--ag-font-mono` is still mono.
        $monoVars = ['--ag-font-mono' => true];
        do {
            $grew = false;
            foreach ($vars as $name => $values) {
                if (isset($monoVars[$name])) continue;
                foreach ($values as $v) {
                    if (self::isMono($v, $monoVars)) { $monoVars[$name] = true; $grew = true; break; }
                }
            }
        } while ($grew);

        $mono = $caps = [];
        foreach ($parsed as $rel => [$text, $twig, $rules]) {
            foreach ($rules as [$sel, $body, $at]) {
                foreach (PublicSurface::declarations($body) as [$p, $v, $dAt]) {
                    $line = PublicSurface::lineAt($text, $at + $dAt);
                    if (($p === 'font-family' || $p === 'font') && self::isMono($v, $monoVars)
                        && !self::namesAReference($sel)) {
                        $mono[] = sprintf('%s:%d  %s { %s: %s }', $rel, $line, $sel, $p, $v);
                    }
                    $isCaps = ($p === 'text-transform' && preg_match('~\b(uppercase|capitalize)\b~i', $v))
                        || (($p === 'font-variant' || $p === 'font-variant-caps')
                            && preg_match('~\b(all-)?(small|petite)-caps\b|\bunicase\b|\btitling-caps\b~i', $v));
                    if ($isCaps && !self::hasSegment($sel, [self::CAPS_SEGMENT])) {
                        $caps[] = sprintf('%s:%d  %s { %s: %s }', $rel, $line, $sel, $p, $v);
                    }
                }
            }
            if ($twig) {
                foreach (self::typedCapitals($text) as [$line, $words]) {
                    $caps[] = sprintf('%s:%d  %s (typed capitals)', $rel, $line, $words);
                }
            }
        }
        return ['mono' => $mono, 'caps' => $caps];
    }

    /** @param array<string,bool> $monoVars */
    private static function isMono(string $value, array $monoVars): bool
    {
        if (preg_match('~jetbrains\s*mono|(?<![\w-])(ui-)?monospace\b~i', $value)) return true;
        preg_match_all('~var\(\s*(--[\w-]+)~', $value, $m);
        foreach ($m[1] as $name) if (isset($monoVars[$name])) return true;
        return false;
    }

    private static function namesAReference(string $selector): bool
    {
        foreach (explode(',', $selector) as $complex) {
            $ok = self::hasSegment($complex, self::MONO_SEGMENTS)
                || preg_match('~(?<![\w.#-])(' . implode('|', self::MONO_ELEMENTS) . ')(?![\w-])~i', $complex);
            if (!$ok) return false;
        }
        return true;
    }

    /** @param list<string> $segments */
    private static function hasSegment(string $selector, array $segments): bool
    {
        preg_match_all('~[.#]([\w-]+)~', $selector, $m);
        foreach ($m[1] as $class) {
            foreach (preg_split('~[-_]+~', strtolower($class)) ?: [] as $seg) {
                if (in_array($seg, $segments, true)) return true;
            }
        }
        return false;
    }

    /**
     * Text nodes of two or more words, every letter a capital. Script and style content
     * is not text; an element carrying a `stamp` class is the exception.
     *
     * @return list<array{0:int,1:string}>
     */
    private static function typedCapitals(string $text): array
    {
        $blank = static fn(array $m): string => preg_replace('~[^\n]~', ' ', $m[0]);
        $text = (string) preg_replace_callback('~<(script|style)\b.*?</\1>~is', $blank, $text);
        $out = [];
        preg_match_all('~<([a-zA-Z][\w-]*)([^>]*)>([^<]+)~', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $node) {
            $words = trim($node[3][0]);
            if (!preg_match('~^[A-Z][A-Z\'’&.]*(?:[\s\-·]+[A-Z][A-Z\'’&.]*)+$~u', $words)) continue;
            if (preg_match_all('~[A-Z]{2,}~', $words) < 2) continue;
            if (self::hasSegment(self::classes($node[2][0]), [self::CAPS_SEGMENT])) continue;
            $out[] = [PublicSurface::lineAt($text, $node[3][1]), $words];
        }
        return $out;
    }

    private static function classes(string $attrs): string
    {
        if (!preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~s', $attrs, $c)) return '';
        $sel = '';
        foreach (preg_split('~\s+~', trim($c[2])) ?: [] as $cls) {
            if ($cls !== '') $sel .= '.' . $cls;
        }
        return $sel;
    }
}
