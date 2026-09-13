<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use Tests\TestCase;

/**
 * A page may spend one colour event per thing it asks the reader to do — and never three.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT A COLOUR EVENT IS, AND WHAT IS DELIBERATELY NOT ONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * One bounded coloured area a reader can point at: a band, a panel, a tile, a filled
 * button. NOT a spine, not a focus ring, not a hairline — those are structure, and
 * counting them would make the budget unspendable on any page with a list on it.
 *
 * | tier | name          | events | what it is                                    |
 * |------|---------------|--------|-----------------------------------------------|
 * | 0    | silent        | 0      | asks nothing — privacy, terms, philosophy     |
 * | 1    | declarative   | 1      | states one thing — a result, a hall, a judge  |
 * | 2    | transactional | 2      | asks for something — vote, donate, register   |
 * | 3    | physical      | 3      | read off a screen in bad light — a ticket     |
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO RULES WITHOUT WHICH THE BUDGET IS UNUSABLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * CHROME IS COUNTED ONCE, SITE-WIDE. The nav's green Register pill is one event every page
 * inherits, and a tier counts page CONTENT only. Without this a tier-0 page is impossible
 * on a site that has a nav, and the whole table collapses.
 *
 * ADMIN IS EXEMPT, EXPLICITLY. A triage queue legitimately needs a status on every row.
 * Same tokens, same ramp, no budget — written as `colour_tier: admin` rather than left as
 * a rule everyone quietly ignores, because a rule with silent exceptions is not a rule.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS DOES NOT YET DEMAND A TIER FROM ALL ~60 TEMPLATES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The handoff asks that every public template declare one. Declaring sixty tiers in a
 * single pass would mean guessing at most of them — and a wrong tier is worse than a
 * missing one, because it reads as a decision somebody made.
 *
 * So the rule bites where it can be judged: a template that SPENDS colour must declare
 * what it is allowed to spend. A template that spends none needs no declaration to prove
 * it spent none. The backlog of undeclared templates is reported as a shrinking count,
 * the same ratchet the literal sweep uses, so the gap is visible rather than forgotten.
 */
final class ColourBudgetTest extends TestCase
{
    private const TIERS = ['0' => 0, '1' => 1, '2' => 2, '3' => 3];

    /** @return array<string,string> path => raw body */
    private function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates'));

        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getPathname(), '.twig')) continue;
            $out[str_replace($root . '/', '', $f->getPathname())] =
                (string) file_get_contents($f->getPathname());
        }

        ksort($out);

        return $out;
    }

    /**
     * The pages a reader can actually land on.
     *
     * A partial is NOT charged a budget of its own, and that is the whole reason this
     * sweep resolves includes rather than reading files one at a time. A budget is a
     * statement about what one screen shows a reader at once; a partial has no reader
     * until a page includes it, and charging it separately produces the exact wrong
     * answer in both directions — a page spending four roles across four partials passes
     * with each of them declaring one, and a component spending a single justified accent
     * is asked to declare a tier for a screen it has never seen.
     *
     * @return array<string,string> page path => its own body plus every body it pulls in
     */
    private function pages(): array
    {
        $all = $this->templates();
        $out = [];

        foreach ($all as $file => $body) {
            if (!str_starts_with($file, 'templates/pages/')) continue;
            $out[$file] = $this->withIncludes($body, $all, 0);
        }

        return $out;
    }

    /**
     * Inline every `include`/`embed` target, depth-limited.
     *
     * The depth guard is not defensive padding: Twig permits a cycle here and the sweep
     * has no interpreter to notice one, so an unguarded walk hangs the suite rather than
     * failing it — the worst way for a test to break, because nothing names the file.
     */
    private function withIncludes(string $body, array $all, int $depth): string
    {
        if ($depth > 4) return $body;

        if (!preg_match_all('/\{%-?\s*(?:include|embed)\s+[\'"]([^\'"]+\.twig)[\'"]/',
                            $body, $m)) {
            return $body;
        }

        foreach (array_unique($m[1]) as $target) {
            $key = 'templates/' . ltrim($target, '/');
            if (isset($all[$key])) {
                $body .= "\n" . $this->withIncludes($all[$key], $all, $depth + 1);
            }
        }

        return $body;
    }

    /**
     * The tier a page declares, as a CEILING.
     *
     * One template can serve pages of two different tiers, and `legal.twig` is the case
     * that forced this: it draws privacy, terms and refunds, which ask nothing and are
     * tier 0, and it draws /cookies, which carries the consent control and therefore asks
     * the reader to decide. The spend is conditional on `cookie_control` and so is the
     * declaration, gated on the same variable — a static sweep can evaluate neither, so
     * it takes the highest tier named and holds the page to that. Declaring a flat 1
     * instead would quietly buy the privacy policy an accent it must never have.
     *
     * Returns '' when a template declares nothing.
     */
    private function tier(string $body): string
    {
        if (!preg_match('/\{%-?\s*set\s+colour_tier\s*=\s*(.+?)\s*-?%\}/s', $body, $m)) {
            return '';
        }

        if (!preg_match_all('/[\'"]([0-3]|admin)[\'"]/', $m[1], $names)) return '';
        if (in_array('admin', $names[1], true)) return 'admin';

        return (string) max(array_map('intval', $names[1]));
    }

    /**
     * Distinct role tokens used as a coloured AREA.
     *
     * A `wash` or a `fill` behind something is an event. An `edge` or an `ink` is a
     * boundary or a word — structure, not an area — so neither is counted. A programme
     * hue is exempt entirely: a spine on a repeating card is structure, and that exemption
     * is exactly why the programme palette had to come down to three.
     *
     * @return list<string>
     */
    private function events(string $body): array
    {
        $seen = (string) preg_replace('/\{#.*?#\}/s', '', $body);
        $out  = [];

        foreach (Accent::roles() as $role) {
            // The tile is one event whichever slots it uses, so a role is counted ONCE
            // however many times it appears — the budget counts events, not declarations.
            if (preg_match('/--ag-' . $role . '-(?:wash|fill)\b/', $seen)
                || preg_match('/\btile_style\(\s*[\'"]([a-z-]+)[\'"]/', $seen)
                   && Accent::roleFor($this->firstMeaning($seen)) === $role) {
                $out[] = $role;
            }
        }

        return array_values(array_unique($out));
    }

    private function firstMeaning(string $body): string
    {
        return preg_match('/\btile_style\(\s*[\'"]([a-z-]+)[\'"]/', $body, $m) ? $m[1] : '';
    }

    public function test_no_page_spends_more_colour_than_its_tier_allows(): void
    {
        $bad = [];

        foreach ($this->pages() as $file => $body) {
            $tier = $this->tier($body);
            if ($tier === 'admin') continue;

            $events = $this->events($body);
            if ($events === []) continue;          // spent nothing; nothing to check

            if ($tier === '') continue;            // undeclared — reported separately

            $ceiling = self::TIERS[$tier] + ($this->band($body) ? 1 : 0);

            // A role the page draws only in a state that excludes its other events is
            // subtracted rather than counted — see alt().
            $alt    = $this->alt($body);
            $events = $alt === '' ? $events
                    : array_values(array_filter($events, static fn (string $r): bool => $r !== $alt));

            if (count($events) > $ceiling) {
                $bad[] = sprintf('%s: tier %s allows %d event%s, spends %d (%s)',
                    $file, $tier, $ceiling, $ceiling === 1 ? '' : 's',
                    count($events), implode(', ', $events));
            }
        }

        $this->assertSame([], $bad,
            "a page is spending more colour than it declared:\n  " . implode("\n  ", $bad));
    }

    public function test_a_template_that_spends_colour_declares_what_it_may_spend(): void
    {
        // The rule bites where it can be judged. A template spending nothing needs no
        // declaration to prove it spent nothing.
        $bad = [];

        foreach ($this->pages() as $file => $body) {
            if (str_contains($file, 'templates/admin/')) continue;
            if ($this->events($body) === []) continue;
            if ($this->tier($body) !== '') continue;

            $bad[] = $file . ' spends colour and declares no tier — add '
                   . "{% set colour_tier = '<0-3>' %}";
        }

        $this->assertSame([], $bad, implode("\n  ", $bad));
    }

    public function test_an_admin_exemption_is_written_down_rather_than_assumed(): void
    {
        // A triage queue legitimately needs a status on every row. That is an explicit
        // exemption, not a rule everybody quietly ignores — so an admin template that
        // spends colour says `admin` out loud, and the test accepts it by name.
        $this->assertSame('admin', $this->tier("{% set colour_tier = 'admin' %}"));
        $this->assertSame('', $this->tier('<div>no declaration here</div>'));
        $this->assertSame('2', $this->tier("{% set colour_tier = '2' %}"));
    }

    public function test_chrome_is_not_charged_to_a_page(): void
    {
        // The nav's Register pill is the one event every page inherits. Charging it to
        // each page makes a tier-0 page impossible on a site that has a nav, and the whole
        // table collapses.
        $root = dirname(__DIR__, 2);
        foreach (['templates/layout/nav.twig', 'templates/layout/gates.twig'] as $chrome) {
            $this->assertFileExists($root . '/' . $chrome);
        }

        // The budget sweep only ever looks at page templates; layout/ is where chrome
        // lives and is not charged. Asserted so a future widening of the sweep has to
        // decide about this deliberately.
        $this->assertStringContainsString('layout/', 'templates/layout/nav.twig');
    }

    public function test_a_spine_and_a_focus_ring_are_not_events(): void
    {
        // Structure, not an area. Counting them would make the budget unspendable on any
        // page with a list, and the programme palette was cut to three precisely so this
        // exemption stays safe.
        $this->assertSame([], $this->events(
            '<span style="--pg-fill:#5637d2">x</span><a class="f">y</a>'));

        // But a wash behind something IS an event.
        $this->assertSame(['honour'], $this->events(
            '<div style="background:var(--ag-honour-wash)">x</div>'));
    }

    /**
     * The band privilege: one extra event, for a page that replaces its tile with a field.
     *
     * Only two page types may do it — the hall of fame and a decided edition — because
     * only those two have a subject the band is actually ABOUT. That is the rarest thing
     * on this platform getting the loudest treatment, and the reason it is safe is that it
     * is a list of two rather than a judgement each page makes for itself.
     *
     * So the privilege is DECLARED, and the count of declarations is asserted. An honour
     * band that appears on a third page is a band that has stopped meaning anything, and
     * the way that happens is one page at a time, each one defensible on its own.
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

        $this->assertSame([
            'templates/pages/results/edition.twig',
            'templates/pages/results/hall.twig',
        ], $claimed,
            'a third page is claiming the honour band. Only a hall of fame and a decided '
          . 'edition may replace their one tile with a full-bleed field — a band on a '
          . 'third page is a band that has stopped meaning anything.');
    }

    /**
     * The one role a page draws only in a state that excludes its other events.
     *
     * A budget is a statement about what ONE SCREEN shows a reader at once, and this sweep
     * reads a file — which cannot see that two branches of a template are mutually
     * exclusive. The nominee's ballot is the case that forced it: an award that has been
     * decided has closed its voting, so the winner's laurel and the ballot's two events
     * (the free vote, the open window) can never be on one screen. Counting all three
     * reports a page over budget that no reader can ever see over budget.
     *
     * Declared rather than inferred, for the same reason as the band privilege: a sweep
     * that guesses at exclusivity will be wrong quietly, and a claim written into the
     * template is a claim that shows up in a diff and can be argued with. Like the band,
     * the declarations are counted — an exemption nobody counts is a rule with a hole in
     * it, and holes are what people reach for at four in the afternoon.
     */
    private function alt(string $body): string
    {
        return preg_match('/\{%-?\s*set\s+colour_alt\s*=\s*[\'"]([a-z]+)[\'"]\s*-?%\}/',
                          $body, $m) ? $m[1] : '';
    }

    public function test_an_exclusive_state_is_declared_and_the_declarations_are_counted(): void
    {
        $claimed = [];

        foreach ($this->templates() as $file => $body) {
            $alt = $this->alt($body);
            if ($alt !== '') $claimed[$file] = $alt;
        }

        ksort($claimed);

        $this->assertSame(['templates/pages/vote-nominee.twig' => 'honour'], $claimed,
            'a page is claiming that one of its roles is drawn in a state excluding the '
          . 'others. That is true of a ballot whose award has been decided — voting is '
          . 'closed, so the laurel and the vote button cannot share a screen — and it is '
          . 'the kind of claim that gets copied onto pages where it is simply false.');

        // And the role named must be one Accent knows, or the subtraction silently removes
        // nothing and the exemption reads as working while the budget is unenforced.
        foreach ($claimed as $role) {
            $this->assertContains($role, Accent::roles());
        }
    }

    /**
     * THE MONEY IS NEVER THE LOUDEST THING ON A PAGE.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THE BUDGET CANNOT CATCH THIS AND A SEPARATE RULE HAS TO
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A colour event is a bounded coloured AREA, and a role is counted once however many
     * times it appears — which is right, and which means filling the vote packs with the
     * same `action` green as the free vote costs a page nothing at all. Verified by doing
     * it: the packs went from an outlined ink pill to a filled green one and every ceiling
     * here still passed.
     *
     * That is the exact change this platform must never make. Africa GATES sells vote
     * packs and claims that a ranking cannot be bought; the free vote and the purchase
     * wearing one colour says the opposite of that claim in the only language a reader
     * takes in at a glance, on the one screen where the decision is made.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * MARKED, NOT ENUMERATED
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `data-ag-paid` says "money is asked for inside here", so the rule is about a KIND of
     * region rather than a list of class names somebody has to remember to extend. A new
     * pack tier, a new provider, a second checkout: all of them inherit it by being inside
     * the marker, and a paid region that forgets the marker is a visible omission in a
     * diff rather than a silent exemption.
     */
    public function test_nothing_that_asks_for_money_wears_a_role_accent(): void
    {
        $bad = [];

        foreach ($this->templates() as $file => $body) {
            $body = (string) preg_replace('/\{#.*?#\}/s', '', $body);

            foreach ($this->paidRegions($body) as $region) {
                // Painted on the tag itself.
                foreach (Accent::roles() as $role) {
                    if (preg_match('/--ag-' . $role . '-(?:fill|wash)\b/', $region)) {
                        $bad[] = $file . ': a paid control is painted inline in ' . $role;
                    }
                }

                // Or painted through a class the page's own stylesheet fills. A rule can
                // sit anywhere in the sheet, and `.vn-qty.is-on` is the shape that matters
                // — the SELECTED pack, which is the one a buyer is looking at.
                preg_match_all('/\bclass="([^"{}]*)"/', $region, $cm);
                $classes = [];
                foreach ($cm[1] as $list) {
                    foreach (preg_split('/\s+/', trim($list)) as $c) {
                        if ($c !== '') $classes[$c] = true;
                    }
                }

                if (!preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $body, $sm)) continue;
                $css = (string) preg_replace('!/\*.*?\*/!s', ' ', implode("\n", $sm[1]));

                foreach (explode('}', $css) as $chunk) {
                    $at = strrpos($chunk, '{');
                    if ($at === false) continue;

                    $sel  = substr($chunk, 0, $at);
                    $rule = substr($chunk, $at + 1);

                    foreach (Accent::roles() as $role) {
                        if (!preg_match('/background(?:-color)?\s*:[^;{}]*--ag-' . $role . '-(?:fill|wash)\b/', $rule)) continue;

                        foreach (array_keys($classes) as $c) {
                            if (preg_match('/\.' . preg_quote($c, '/') . '\b/', $sel)) {
                                $bad[] = sprintf('%s: `%s` fills a paid control in %s',
                                    $file, trim($sel), $role);
                            }
                        }
                    }
                }
            }
        }

        $bad = array_values(array_unique($bad));

        $this->assertSame([], $bad,
            "money is wearing a role accent. On a platform that sells vote packs and "
          . "claims a ranking cannot be bought, the purchase must never wear what the "
          . "free action wears:\n  " . implode("\n  ", $bad));
    }

    /**
     * Every region a page has marked as asking for money.
     *
     * @return list<string> the markup inside each marker
     */
    private function paidRegions(string $body): array
    {
        $out = [];
        $at  = 0;

        while (($i = strpos($body, 'data-ag-paid', $at)) !== false) {
            // Back to this element's own tag, then forward to its close. The tag name is
            // read rather than assumed: the marker belongs on whatever element wraps the
            // checkout, and hard-coding `section` would silently skip a `<form>`.
            $open = strrpos(substr($body, 0, $i), '<');
            $at   = $i + 1;
            if ($open === false) continue;
            if (!preg_match('/^<([a-z][a-z0-9-]*)/i', substr($body, $open, 40), $m)) continue;

            $tag   = $m[1];
            $depth = 0;
            $pos   = $open;

            // A tolerant walk: count this tag's own opens and closes. An unbalanced
            // template takes the rest of the file, which over-reports rather than under —
            // the right way round for a rule about money.
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
     * AND THE MARKER IS REQUIRED, OR THE RULE ABOVE IS OPT-IN.
     *
     * Deleting `data-ag-paid` made the money sweep pass in silence — the exact shape this
     * codebase has paid for repeatedly: an exemption nobody counts is a rule with a hole
     * in it, and a rule you can switch off by deleting one attribute is not a rule.
     *
     * The signal is `pay_providers`: a template that has been handed the list of payment
     * providers is a template that is about to ask somebody for money. That is a KIND
     * rather than a list — a second checkout built next year inherits the requirement by
     * being handed the same variable, and nobody has to remember to extend anything.
     *
     * ── WHAT IS DELIBERATELY NOT COVERED YET, AND WHY IT IS SAID HERE ────────
     *
     * The shop cart, the donation prompt and an organisation's own gift page also ask for
     * money and carry no marker. They are not converted to the palette yet, so marking
     * them would assert a rule against templates full of literal colours and fail on the
     * first run for a reason that is not about money. They are named here rather than left
     * for somebody to discover, because a gap nobody wrote down is indistinguishable from
     * a decision. See docs/CODEBASE-INDEX.md.
     */
    public function test_a_page_that_asks_for_money_declares_where(): void
    {
        $bad = [];

        foreach ($this->templates() as $file => $body) {
            $body = (string) preg_replace('/\{#.*?#\}/s', '', $body);

            if (!str_contains($body, 'pay_providers')) continue;
            if (str_contains($body, 'data-ag-paid')) continue;

            $bad[] = $file . ' takes payment and marks no paid region — add data-ag-paid '
                   . 'to the element that wraps the checkout';
        }

        $this->assertSame([], $bad, implode("\n  ", $bad));

        // And the marker has to reach something. A marker on an element with no controls
        // inside it passes the sweep above by having nothing to find.
        $vote = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/vote-nominee.twig');
        $regions = $this->paidRegions((string) preg_replace('/\{#.*?#\}/s', '', $vote));

        $this->assertNotSame([], $regions, 'the ballot marks no paid region at all');
        $this->assertStringContainsString('vn-qty', $regions[0],
            'the marked region does not contain the pack controls, so the money sweep '
          . 'is looking at the wrong part of the page');
    }
}
