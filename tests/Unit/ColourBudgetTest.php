<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use Tests\Support\ColourFields;
use Tests\TestCase;

/**
 * A page may spend one colour event per thing it asks the reader to do — and never three.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT A COLOUR EVENT IS, AND WHAT IS DELIBERATELY NOT ONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * One colour FAMILY painted as a bounded area a reader can point at — a band, a panel, a
 * tile, a filled button. Not a spine, not a focus ring, not a hairline, not a word: those
 * are structure, and counting them would make the budget unspendable on any page with a
 * list on it. What counts as a field is `Tests\Support\ColourFields`, shared with
 * `AccentTest`, because two sweeps disagreeing about it is worse than either being loose.
 *
 * | tier | name          | events | what it is                                    |
 * |------|---------------|--------|-----------------------------------------------|
 * | 0    | silent        | 0      | asks nothing — privacy, terms, philosophy     |
 * | 1    | declarative   | 1      | states one thing — a result, a hall, a judge  |
 * | 2    | transactional | 2      | asks for something — vote, donate, register   |
 * | 3    | physical      | 3      | read off a screen in bad light — a ticket     |
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * REBUILT FOR THE HANDOFF'S NAMES, AND WHAT THAT CLOSED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The previous version recognised a field by its role slot (`--ag-honour-wash`) and
 * charged the bare `--ag-green`/`--ag-gold` only on pages that had already declared a
 * tier. Under the handoff's palette those bare names ARE the palette, so that version
 * would have passed any page painted entirely in them (GAPS C2). Now every page is read
 * through the same definition of a field, and it reads the CSS a page links for itself
 * as well as its own `<style>` blocks (C8).
 *
 * CHROME IS COUNTED ONCE, SITE-WIDE: the layouts and the globally loaded sheets are not
 * charged to a page, or a tier-0 page would be impossible on a site with a nav. ADMIN IS
 * EXEMPT, EXPLICITLY, as `colour_tier: admin`.
 */
final class ColourBudgetTest extends TestCase
{
    private const TIERS = ['0' => 0, '1' => 1, '2' => 2, '3' => 3];

    /** @return array<string,string> path => raw body */
    private function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates')) as $f) {
            if (!$f->isFile() || !str_ends_with($f->getPathname(), '.twig')) continue;
            $out[str_replace($root . '/', '', $f->getPathname())] = (string) file_get_contents($f->getPathname());
        }
        ksort($out);

        return $out;
    }

    /**
     * The pages a reader can land on, each with every partial it pulls in and every sheet
     * it links for itself. A partial has no reader until a page includes it, so it is
     * never charged a budget of its own.
     *
     * @return array<string,string>
     */
    private function pages(): array
    {
        $all = $this->templates();
        $out = [];

        foreach ($all as $file => $body) {
            if (!str_starts_with($file, 'templates/pages/')) continue;
            $whole = $this->withIncludes($body, $all, 0);
            $out[$file] = $whole . "\n" . $this->linkedCss($whole);
        }

        return $out;
    }

    /** Inline every include/embed/import target, depth-limited — Twig permits a cycle. */
    private function withIncludes(string $body, array $all, int $depth): string
    {
        if ($depth > 4) return $body;
        if (!preg_match_all('/\{%-?\s*(?:include|embed|import|from)\s+[\'"]([^\'"]+\.twig)[\'"]/', $body, $m)) {
            return $body;
        }
        foreach (array_unique($m[1]) as $target) {
            $key = 'templates/' . ltrim($target, '/');
            if (isset($all[$key])) $body .= "\n" . $this->withIncludes($all[$key], $all, $depth + 1);
        }

        return $body;
    }

    /**
     * The tier a page declares, as a CEILING: one template can serve pages of two tiers
     * (`legal.twig` draws privacy, tier 0, and /cookies, which asks), so the highest named
     * is the one held. '' when nothing is declared.
     */
    private function tier(string $body): string
    {
        if (!preg_match('/\{%-?\s*set\s+colour_tier\s*=\s*(.+?)\s*-?%\}/s', $body, $m)) return '';
        if (!preg_match_all('/[\'"]([0-3]|admin)[\'"]/', $m[1], $names)) return '';
        if (in_array('admin', $names[1], true)) return 'admin';

        return (string) max(array_map('intval', $names[1]));
    }

    /**
     * Distinct families spent as a field. A tile is one event in its MEANING's family,
     * whichever tokens it resolves to.
     *
     * @return list<string>
     */
    private function events(string $body): array
    {
        $seen = (string) preg_replace('/\{#.*?#\}/s', '', $body);
        $seen = (string) preg_replace('!/\*.*?\*/!s', ' ', $seen);
        $seen = (string) preg_replace('/[^{}\n][^{}]*:focus[a-z-]*[^{}]*\{[^{}]*\}/i', ' ', $seen);

        $out = ColourFields::families($seen);
        if (preg_match_all('/\btile_style\(\s*[\'"]([a-z-]+)[\'"]/', $seen, $m)) {
            foreach ($m[1] as $meaning) $out[] = Accent::familyFor($meaning);
        }
        if (preg_match_all('/meaning\s*:\s*[\'"]([a-z-]+)[\'"]/', $seen, $m)) {
            foreach ($m[1] as $meaning) {
                if (isset(Accent::meanings()[$meaning])) $out[] = Accent::familyFor($meaning);
            }
        }

        return array_values(array_unique($out));
    }

    public function test_no_page_spends_more_colour_than_its_tier_allows(): void
    {
        $bad = [];

        foreach ($this->pages() as $file => $body) {
            $tier = $this->tier($body);
            if ($tier === 'admin' || $tier === '') continue;   // undeclared is reported below

            $events = $this->events($body);
            if ($events === []) continue;

            $ceiling = self::TIERS[$tier] + ($this->band($body) ? 1 : 0);
            $alt     = $this->alt($body);
            $events  = array_values(array_filter($events, static fn (string $f): bool => $f !== $alt));

            if (count($events) > $ceiling) {
                $bad[] = sprintf('%s: tier %s allows %d, spends %d (%s)',
                    $file, $tier, $ceiling, count($events), implode(', ', $events));
            }
        }

        $this->assertSame([], $bad, "a page is spending more colour than it declared:\n  " . implode("\n  ", $bad));
    }

    /**
     * THE BACKLOG THE REBUILD OPENED, AS A LIST THAT MAY ONLY SHRINK.
     *
     * Seeing the handoff's names (GAPS C2) found 32 pages that paint a family as a
     * field — almost all of them a filled `--ag-green` button — and declare no tier. The
     * previous sweep passed every one, because it charged the bare green only to pages
     * that had already opted in. Guessing 32 tiers in one pass would be guessing,
     * and a wrong tier reads as a decision somebody made; each of these pages is destroyed
     * and rebuilt in a later phase, and that phase declares its tier and deletes its line.
     *
     * @var list<string>
     */
    private const UNDECLARED = [
        // Empty since 3 Oct 2026: every page that was on it was destroyed with the old
        // pages (docs/handoff/DESTROYED.md). A rebuilt page declares its tier from its
        // first commit; nothing is ever added here again.
    ];

    public function test_a_template_that_spends_colour_declares_what_it_may_spend(): void
    {
        // The rule bites where it can be judged: a template spending nothing needs no
        // declaration to prove it.
        $bad = [];

        foreach ($this->pages() as $file => $body) {
            if ($this->events($body) === []) continue;
            if ($this->tier($body) !== '') continue;
            if (in_array($file, self::UNDECLARED, true)) continue;

            $bad[] = $file . ' spends ' . implode(', ', $this->events($body))
                   . " and declares no tier — add {% set colour_tier = '<0-3>' %}";
        }

        $this->assertSame([], $bad, implode("\n  ", $bad));
    }

    public function test_the_undeclared_backlog_only_shrinks(): void
    {
        // A page that has declared its tier, or stopped spending, leaves the list — or
        // the list goes stale and becomes a silent exemption for whatever is named on it.
        $pages = $this->pages();
        $stale = [];

        foreach (self::UNDECLARED as $file) {
            if (!isset($pages[$file])) { $stale[] = $file . ': gone — delete its line'; continue; }
            if ($this->tier($pages[$file]) !== '' || $this->events($pages[$file]) === []) {
                $stale[] = $file . ': no longer undeclared — delete its line';
            }
        }

        $this->assertSame([], $stale, implode("\n  ", $stale));
    }

    public function test_the_tier_reader_and_the_event_counter_can_fail(): void
    {
        $this->assertSame('admin', $this->tier("{% set colour_tier = 'admin' %}"));
        $this->assertSame('', $this->tier('<div>no declaration here</div>'));
        $this->assertSame('2', $this->tier("{% set colour_tier = '2' %}"));

        // A spine and a focus ring are structure; a wash and a filled button are events.
        $this->assertSame([], $this->events(
            '<span style="--pg-fill:var(--ag-info)">x</span><style>a:focus-visible{outline:3px solid var(--ag-green)}</style>'));
        $this->assertSame(['gold'], $this->events('<div style="background:var(--ag-gold-wash)">x</div>'));
        $this->assertSame(['green'], $this->events('<style>.b{background:var(--ag-green)}</style>'));
        $this->assertSame(['gold'], $this->events("{{ tile_style('overall-winner') }}"));
    }

    public function test_chrome_is_not_charged_to_a_page(): void
    {
        $root = dirname(__DIR__, 2);
        // `layout/gates.twig` left this list when it was destroyed (DESTROYED.md).
        foreach (['templates/layout/nav.twig'] as $chrome) {
            $this->assertFileExists($root . '/' . $chrome);
            $this->assertArrayNotHasKey($chrome, $this->pages());
        }
    }

    // ══ the band privilege and the exclusive state ═══════════════════════════

    /**
     * One extra event for a page that replaces its tile with a field — the hall of fame
     * and a decided edition only, because only those two have a subject the band is
     * about. Declared, and the declarations counted.
     */
    private function band(string $body): bool
    {
        return preg_match('/\{%-?\s*set\s+colour_band\s*=\s*true\s*-?%\}/', $body) === 1;
    }

    public function test_the_band_privilege_is_still_a_list_of_two(): void
    {
        $claimed = [];
        foreach ($this->templates() as $file => $body) {
            if ($this->band($body)) $claimed[] = $file;
        }
        sort($claimed);

        // The two holders — results/edition.twig and results/hall.twig — were destroyed
        // with the old pages; the privilege is recorded in their inventories
        // (docs/handoff/inventory/) and a rebuilt page that claims it adds itself here,
        // never a third.
        $this->assertSame([], $claimed,
            'a page is claiming the honour band — only the rebuilt edition and hall pages may, and only by adding themselves here.');
    }

    /**
     * The one family a page draws only in a state that excludes its other events — the
     * ballot's gold laurel, which appears only once voting has closed. Declared, counted.
     */
    private function alt(string $body): string
    {
        return preg_match('/\{%-?\s*set\s+colour_alt\s*=\s*[\'"]([a-z]+)[\'"]\s*-?%\}/', $body, $m) ? $m[1] : '';
    }

    public function test_an_exclusive_state_is_declared_and_the_declarations_are_counted(): void
    {
        $claimed = [];
        foreach ($this->templates() as $file => $body) {
            $alt = $this->alt($body);
            if ($alt !== '') $claimed[$file] = $alt;
        }
        ksort($claimed);

        // The one holder — vote-nominee.twig's gold laurel, drawn only once voting has
        // closed — was destroyed with the old pages; its rebuild adds itself back here.
        $this->assertSame([], $claimed,
            'a page is claiming one of its families is drawn in a state excluding the others');

        // The family named must exist, or the subtraction silently removes nothing.
        foreach ($claimed as $family) {
            $this->assertArrayHasKey($family, Accent::families());
        }
    }

    // ══ money ════════════════════════════════════════════════════════════════

    /**
     * THE MONEY IS NEVER THE LOUDEST THING ON A PAGE.
     *
     * A family is counted once however often it appears, so filling the vote packs with
     * the same green as the free vote costs the budget nothing — and it is the change this
     * platform must never make: it sells vote packs and claims a ranking cannot be bought,
     * and the free vote and the purchase wearing one colour says the opposite at a glance.
     * `data-ag-paid` marks the region, so the rule is about a KIND of region.
     */
    public function test_nothing_that_asks_for_money_wears_a_colour_field(): void
    {
        $bad = [];

        foreach ($this->templates() as $file => $body) {
            $body = (string) preg_replace('/\{#.*?#\}/s', '', $body);

            foreach ($this->paidRegions($body) as $region) {
                foreach (ColourFields::families($region) as $family) {
                    $bad[] = $file . ': a paid control is painted inline in ' . $family;
                }

                preg_match_all('/\bclass="([^"{}]*)"/', $region, $cm);
                $classes = [];
                foreach ($cm[1] as $list) {
                    foreach (preg_split('/\s+/', trim($list)) as $c) if ($c !== '') $classes[$c] = true;
                }

                if (!preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $body, $sm)) continue;
                $css = (string) preg_replace('!/\*.*?\*/!s', ' ', implode("\n", $sm[1]));

                foreach (explode('}', $css) as $chunk) {
                    $at = strrpos($chunk, '{');
                    if ($at === false) continue;
                    $sel  = substr($chunk, 0, $at);
                    $rule = substr($chunk, $at + 1);

                    foreach (ColourFields::families($rule) as $family) {
                        foreach (array_keys($classes) as $c) {
                            if (preg_match('/\.' . preg_quote($c, '/') . '\b/', $sel)) {
                                $bad[] = sprintf('%s: `%s` fills a paid control in %s', $file, trim($sel), $family);
                            }
                        }
                    }
                }
            }
        }

        $bad = array_values(array_unique($bad));
        $this->assertSame([], $bad,
            "money is wearing a colour field — the purchase must never wear what the free action wears:\n  "
            . implode("\n  ", $bad));
    }

    /** @return list<string> the markup inside each `data-ag-paid` element */
    private function paidRegions(string $body): array
    {
        $out = [];
        $at  = 0;

        while (($i = strpos($body, 'data-ag-paid', $at)) !== false) {
            $open = strrpos(substr($body, 0, $i), '<');
            $at   = $i + 1;
            if ($open === false) continue;
            if (!preg_match('/^<([a-z][a-z0-9-]*)/i', substr($body, $open, 40), $m)) continue;

            $tag   = $m[1];
            $depth = 0;
            $pos   = $open;
            // Unbalanced markup takes the rest of the file: over-reporting is the right way
            // round for a rule about money.
            while (preg_match('/<(\/?)' . $tag . '\b/i', $body, $t, PREG_OFFSET_CAPTURE, $pos)) {
                $pos = $t[0][1] + 1;
                $depth += $t[1][0] === '' ? 1 : -1;
                if ($depth === 0) break;
            }
            $out[] = substr($body, $open, max(0, $pos - $open));
        }

        return $out;
    }

    /**
     * And the marker is required, or the rule above is opt-in: a template handed
     * `pay_providers` is about to ask somebody for money.
     */
    public function test_a_page_that_asks_for_money_declares_where(): void
    {
        $bad = [];
        foreach ($this->templates() as $file => $body) {
            $body = (string) preg_replace('/\{#.*?#\}/s', '', $body);
            if (!str_contains($body, 'pay_providers') || str_contains($body, 'data-ag-paid')) continue;
            $bad[] = $file . ' takes payment and marks no paid region — add data-ag-paid';
        }
        $this->assertSame([], $bad, implode("\n  ", $bad));

        // The second half read the ballot (vote-nominee.twig) and required its marked
        // region to hold the pack controls (`vn-qty`). The ballot was destroyed; the rule
        // is in its inventory for the rebuild to re-assert.
    }

    public function test_the_money_rule_fails_on_a_planted_fill(): void
    {
        $page = '<section data-ag-paid><button class="pk">Pay</button></section>'
              . '<style>.pk{ background:var(--ag-green) }</style>';
        $regions = $this->paidRegions($page);
        $this->assertCount(1, $regions);
        $this->assertSame(['green'], ColourFields::families('.pk{ background:var(--ag-green) }'));
    }

    // ══ the sheets a page links for itself ═══════════════════════════════════

    /**
     * Sheets the public layout loads on every page ({@see \AfricaGates\Support\AssetBundle::STYLESHEETS})
     * are chrome, counted once. A sheet a page LINKS FOR ITSELF is that page's colour —
     * `/cookies` spent green through `components/article.css` while its template held no
     * token at all. One level of local `--x: var(--ag-y)` indirection is resolved.
     */
    private function linkedCss(string $body): string
    {
        $root = dirname(__DIR__, 2);
        if (!preg_match_all('/<link\b[^>]*\bhref\s*=\s*"[^"]*?([a-z0-9\/_.-]+\.css)[^"]*"/i', $body, $m)) return '';

        $global = array_map(static fn (string $p): string => basename($p), \AfricaGates\Support\AssetBundle::STYLESHEETS);
        $out    = '';

        foreach (array_unique($m[1]) as $href) {
            $rel = ltrim(str_replace('/assets/', 'assets/', $href), '/');
            if (in_array(basename($rel), $global, true)) continue;
            $path = $root . '/public/' . $rel;
            if (is_file($path)) $out .= "\n" . $this->resolved((string) file_get_contents($path));
        }

        return $out;
    }

    private function resolved(string $css): string
    {
        $css = (string) preg_replace('!/\*.*?\*/!s', ' ', $css);
        if (!preg_match_all('/(--[a-z0-9-]+)\s*:\s*var\(\s*(--[a-z0-9-]+)/i', $css, $m, PREG_SET_ORDER)) return $css;

        $map = [];
        foreach ($m as $d) $map[$d[1]] = $d[2];

        return (string) preg_replace_callback('/var\(\s*(--[a-z0-9-]+)/i',
            static fn (array $v): string => 'var(' . ($map[$v[1]] ?? $v[1]), $css);
    }
}
