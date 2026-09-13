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
}
