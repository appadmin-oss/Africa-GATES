<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * An Alpine `:style` binding must never share a tag with a static `style` attribute.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS ENCODES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `:style="expr"` with a STRING value sets `el.style = value`, which REPLACES the
 * element's entire style attribute. So a tag written like this:
 *
 *     <button style="border:1px solid …; border-radius:999px; padding:7px 14px; …"
 *             :style="selected ? 'background:#237b22' : ''">
 *
 * looks correct in the markup, renders correctly for the instant before Alpine boots,
 * and then loses its border, its radius, its padding and its font the moment the
 * component initialises. The unselected branch is worse than the selected one: it
 * assigns the empty string, so those elements are stripped and given nothing back.
 *
 * Two places had it. The ballot's quick-amount chips — how most buyers choose what
 * they are about to pay for — collapsed to 23px of bare text with a green blob on the
 * selected one. The community poll's options lost their border, radius, padding, full
 * width and left alignment, turning the widget into a stack of unstyled sentences.
 *
 * It is invisible to every kind of testing that does not run a browser: the HTML is
 * right, the CSS is right, and the damage is done by a framework at runtime.
 *
 * ── THE RULE, AND ITS ONE EXEMPTION ──────────────────────────────────────────
 *
 * State belongs on `:class`, which adds and removes rather than overwriting.
 * `:style` is for a live MEASUREMENT — a progress width, a computed offset — where
 * there is nothing static to destroy.
 *
 * The exemption is a binding that sets the SAME property the static attribute sets,
 * as a progressive-enhancement fallback: `style="width:40%"` server-rendered, with
 * `:style="'width:'+pct+'%'"` taking over once the component is live. Clobbering is
 * the intent there, and the property is declared on both sides so nothing else is
 * lost.
 */
final class AlpineStyleClobberTest extends TestCase
{
    /**
     * Tags that legitimately set the same property both ways.
     *
     * Keyed by template, valued by the CSS property both attributes carry. Adding an
     * entry is a claim that the static attribute sets ONLY that property — which the
     * test then verifies, so an exemption cannot quietly cover a growing style
     * attribute.
     */
    private const FALLBACK_ONLY = [
        'templates/judge/ballot.twig' => 'width',
    ];

    public function test_no_tag_carries_both_a_static_style_and_an_alpine_style_binding(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = $this->twigFiles($root . '/templates');
        $this->assertNotEmpty($files, 'no templates found — the scan is not looking anywhere');

        $offenders = [];
        foreach ($files as $file) {
            $rel = ltrim(str_replace($root, '', $file), '/');
            $src = (string) file_get_contents($file);

            // Every opening tag, across newlines: these attributes are routinely split
            // over several lines and a line-based scan misses exactly those.
            preg_match_all('/<[a-zA-Z][^>]*>/s', $src, $tags);
            foreach ($tags[0] as $tag) {
                if (!str_contains($tag, ':style=')) continue;
                // `(?<!:)` so the binding itself is not mistaken for the static attribute.
                if (!preg_match('/(?<!:)\bstyle="([^"]*)"/', $tag, $m)) continue;

                $static = trim($m[1]);
                $prop   = self::FALLBACK_ONLY[$rel] ?? null;
                if ($prop !== null && $this->onlySets($static, $prop)) continue;

                $offenders[] = $rel . ' — ' . trim(preg_replace('/\s+/', ' ', substr($tag, 0, 120)) ?? '');
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge([
            'An Alpine :style binding REPLACES the style attribute when it runs, so these',
            'elements lose every static style the moment the component initialises.',
            'Move the state onto :class and keep :style for live measurements only:',
        ], $offenders)));
    }

    /** True when a style attribute declares nothing but the named property. */
    private function onlySets(string $style, string $prop): bool
    {
        foreach (explode(';', $style) as $decl) {
            $decl = trim($decl);
            if ($decl === '') continue;
            if (!str_starts_with($decl, $prop . ':')) return false;
        }
        return true;
    }

    /** @return list<string> */
    private function twigFiles(string $dir): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.twig')) $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }
}
