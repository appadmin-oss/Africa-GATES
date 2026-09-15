<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Support\AdminNav;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\CpiService;
use AfricaGates\Services\CycleMaterialiser;
use AfricaGates\Services\RegistryCheck;
use AfricaGates\Services\RuleEngine;
use Tests\TestCase;

/**
 * THE ADMINISTRATOR'S HANDBOOK, AND THE ONE PROPERTY THAT KEEPS IT HONEST.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT A HANDBOOK RISKS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This repo has paid four times for prose outliving the rule it describes. An article
 * titled "Why a small category is not a disadvantage" survived the change that moved the
 * denominator out of the category. The release screen described "the largest ORGANIC vote
 * count IN THE CATEGORY" for a figure that was neither. `how-cpi-works` asserted "paid
 * votes are excluded entirely" while a second article in the same file said they count
 * like a free vote. And the settings screen explained the default scoring basis using the
 * arithmetic of a different one — 157 points out, on the screen where an operator picks it.
 *
 * A handbook is that same hazard with people actively told to trust it. So the design rule
 * is that every STRUCTURAL fact on the page is read from the code that implements it, and
 * only judgement is typed.
 *
 * `test_the_numbers_are_read_from_the_rules_and_not_typed_in` is the assertion that makes
 * that real rather than aspirational: it moves the scoring settings and requires the page
 * to move with them. A handbook that passes every other test here and fails that one is a
 * handbook with the numbers hard-coded, which is exactly how the four faults above
 * happened.
 */
final class HandbookTest extends TestCase
{
    /** The page, rendered the way the controller renders it. */
    private function render(?array $rules = null): string
    {
        $_SESSION['admin_role'] = 'admin';

        // The real layout pulls in globals a unit test has no business booting.
        $twig = new \Twig\Environment(
            new \Twig\Loader\ChainLoader([
                new \Twig\Loader\ArrayLoader(['admin/layout.twig' =>
                    '{% block head_styles %}{% endblock %}{% block content %}{% endblock %}']),
                new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
            ]),
            // Strict, so a key the controller stops passing fails here rather than
            // rendering as a blank sentence on a page people are told to trust.
            ['strict_variables' => true]);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addGlobal('csrf_token', 'test-csrf');
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $p): string => $p));

        $html = $twig->render('admin/handbook.twig', [
            'page_title'   => 'Handbook',
            'admin_page'   => 'handbook',
            'areas'        => AdminNav::sections(),
            'roles'        => Permissions::ROLES,
            'matrix'       => Permissions::MATRIX,
            'your_role'    => 'admin',
            'your_label'   => Permissions::label('admin'),
            'rules'        => $rules ?? (new RuleEngine())->effective(null, null),
            'basis_ideal'  => CpiService::BASIS_IDEAL,
            'grace_days'   => CycleMaterialiser::ANNOUNCE_GRACE_DAYS,
            'check_states' => RegistryCheck::STATES,
            'check_sense'  => array_map(static fn (): string => 'sense', RegistryCheck::STATES),
            'cac_search'   => RegistryCheck::CAC_SEARCH,
        ]);

        // Whitespace-normalised: the page wraps its prose, so a claim about what it SAYS
        // must not become a claim about where the template breaks a line.
        return (string) preg_replace('~\s+~', ' ', $html);
    }

    // ══ it exists, and it is reachable ═══════════════════════════════════════

    /**
     * IT IS IN THE RAIL, WHICH IS THE WHOLE POINT OF WRITING IT HERE.
     *
     * Documentation nobody can find is the fault this repo keeps paying for — the
     * provider-health page sat routed and unlinked, the donor's stop link was built by a
     * function with no caller. A handbook in `docs/` would be worse still: there is no SSH
     * on production, so an administrator cannot open one at all.
     */
    public function test_the_handbook_is_in_the_rail_for_every_role(): void
    {
        $overview = AdminNav::sections()[0];
        $this->assertNull($overview['gate'],
            'the handbook lives in the always-visible section: a role that cannot reach an '
            . 'area still needs to know it exists and why their rail is shorter');

        $pages = array_column($overview['items'], 'page');
        $this->assertContains('handbook', $pages);

        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $this->assertStringContainsString("'/handbook'", $routes);
    }

    // ══ it covers what it claims to cover ════════════════════════════════════

    /**
     * EVERY AREA IN THE RAIL IS DESCRIBED.
     *
     * Looped from `AdminNav::sections()` in the template, so an area added to the console
     * appears here with no edit. This asserts the loop is still a loop — a heading typed
     * by hand would pass on the day it was written and silently omit the next one.
     */
    public function test_every_area_of_the_console_is_described(): void
    {
        $html = $this->render();

        foreach (AdminNav::sections() as $section) {
            // `&` in a label arrives escaped, which is Twig doing its job.
            $label = str_replace('&', '&amp;', $section['label']);
            $this->assertStringContainsString($label, $html,
                "the '{$section['label']}' area is in the rail and unexplained here");

            foreach ($section['items'] as $item) {
                $this->assertStringContainsString('href="' . $item['href'] . '"', $html,
                    "the handbook lists no way to reach {$item['label']}");
            }
        }
    }

    /** And every role, with the sentence that says what it is for. */
    public function test_every_role_is_listed_with_what_it_may_reach(): void
    {
        $html = $this->render();

        foreach (Permissions::ROLES as $key => $role) {
            $this->assertStringContainsString($role['label'], $html,
                "the {$key} role is not described");
        }
        foreach (array_keys(Permissions::MATRIX) as $section) {
            $this->assertStringContainsString('<code>' . $section . '</code>', $html,
                "the '{$section}' permission is never named");
        }
    }

    /** And every verification state, in the words the screens print. */
    public function test_every_verification_state_is_explained(): void
    {
        $html = $this->render();

        foreach (RegistryCheck::STATES as $key => $label) {
            $this->assertStringContainsString('<code>' . $key . '</code>', $html);
            $this->assertStringContainsString($label, $html,
                "the handbook must use the same word the screen prints for '{$key}'");
        }
    }

    /**
     * THE THIRTEEN TOPICS THE HANDBOOK WAS ASKED FOR.
     *
     * Anchors rather than prose, because an anchor is what the contents list links to and
     * a section that loses its heading loses its entry silently.
     */
    public function test_the_handbook_covers_every_topic_it_was_asked_for(): void
    {
        $html = $this->render();

        foreach (['hb-nav' => 'navigation', 'hb-areas' => 'the areas',
                  'hb-perms' => 'permissions', 'hb-prog' => 'programmes and editions',
                  'hb-score' => 'scoring', 'hb-release' => 'releasing a result',
                  'hb-events' => 'events', 'hb-verify' => 'verification',
                  'hb-find' => 'search and filtering', 'hb-bulk' => 'bulk actions',
                  'hb-single' => 'individual actions', 'hb-trouble' => 'common issues',
                  'hb-never' => 'warnings'] as $anchor => $topic) {
            $this->assertStringContainsString('id="' . $anchor . '"', $html,
                "the handbook has no section covering {$topic}");
            $this->assertStringContainsString('href="#' . $anchor . '"', $html,
                "{$topic} is not in the contents list");
        }
    }

    // ══ and the property that keeps it true ══════════════════════════════════

    /**
     * THE NUMBERS MOVE WHEN THE RULES MOVE.
     *
     * The assertion this file exists for. The handbook quotes the community and judging
     * weights and the judging quorum, because those are the three things an administrator
     * is asked about — and a quoted number is exactly what went wrong on the settings
     * screen, where the default basis was explained with another basis's arithmetic.
     *
     * So the page is rendered twice against DIFFERENT rulesets and the figures are
     * required to differ. Typing "450 points" into the template passes every other test
     * in this file and fails this one.
     */
    public function test_the_numbers_are_read_from_the_rules_and_not_typed_in(): void
    {
        $live = (new RuleEngine())->effective(null, null);

        $normal = $this->render($live);
        $this->assertStringContainsString('450 points', $normal, 'the community half today');
        $this->assertStringContainsString('550 points', $normal, 'the judging half today');
        $this->assertStringContainsString('2 complete scorecards', $normal, 'the quorum today');

        // A programme that weights the halves differently and wants three judges.
        $moved = $this->render(array_merge($live, [
            'community_weight'       => 0.30,
            'judge_weight'           => 0.70,
            'min_judges_per_nominee' => 3,
        ]));

        $this->assertStringContainsString('300 points', $moved,
            'the handbook must read the community weight, not carry a copy of it');
        $this->assertStringContainsString('700 points', $moved,
            'the handbook must read the judging weight, not carry a copy of it');
        $this->assertStringContainsString('3 complete scorecards', $moved,
            'and the quorum, including its plural');

        $this->assertStringNotContainsString('450 points', $moved,
            'a stale figure beside a live one is worse than no figure: it is what somebody '
            . 'checks their understanding against');
    }

    /**
     * AND THE ANNOUNCEMENT GRACE WINDOW, FOR THE SAME REASON.
     *
     * The handbook explains why a late edition publishes unsealed, and names the number of
     * days. `ANNOUNCE_GRACE_DAYS` is the constant the sweep actually uses.
     */
    public function test_the_grace_window_is_read_from_the_constant(): void
    {
        $this->assertStringContainsString(
            CycleMaterialiser::ANNOUNCE_GRACE_DAYS . ' days late', $this->render());
    }

    /**
     * THE HANDBOOK LINKS ONLY TO ROUTES THAT EXIST.
     *
     * It is a page of links by construction, and a handbook that sends an administrator to
     * a 404 is worse than one that says nothing — they will conclude the feature is gone.
     * The same sweep the organisation page has, for the same reason.
     */
    public function test_the_handbook_names_no_route_that_does_not_exist(): void
    {
        $html   = $this->render();
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        preg_match_all('~href="(/admin/[a-z0-9/-]+)"~', $html, $m);
        $this->assertNotEmpty($m[1], 'a handbook with no links is not a handbook');

        // ── HOW A ROUTE IS MATCHED, AND WHY IT IS NOT A SUBSTRING ───────────
        //
        // Routes are declared relative to their group, so one path can appear three ways:
        // `/admin/legal` is `'/legal'` on the admin group, `/admin/shop/orders` is
        // `'/orders'` inside a `/shop` group. `AdminNavTest` documents this and names the
        // case that catches a naive check — `/admin/questionnaires/invitations`, a real
        // `$s->get('/invitations', …)` inside the questionnaires group. The first cut of
        // this test used a substring and reported that route and `/admin/settings/providers`
        // as missing, both of which exist and one of which I had just linked myself.
        //
        // Same three forms as AdminNavTest, deliberately: two tests disagreeing about what
        // counts as a registered route is worse than either being slightly loose.
        $missing = [];
        foreach (array_unique($m[1]) as $href) {
            $tail = substr($href, strlen('/admin'));
            $last = basename($tail);
            $alts = array_map(
                static fn (string $v): string => '[\'"]' . preg_quote($v, '~') . '[\'"]',
                [$tail, $last, '/' . $last]);

            if (!preg_match('~' . implode('|', $alts) . '~', $routes)) $missing[] = $href;
        }

        $this->assertSame([], $missing,
            "the handbook sends an administrator to routes that do not exist:\n  "
            . implode("\n  ", $missing));
    }

    /**
     * IT SAYS WHICH ACTIONS CANNOT BE UNDONE.
     *
     * The part of documentation that earns its keep. Everything else here an administrator
     * could work out by clicking; the irreversible actions are the ones where clicking to
     * find out is the mistake.
     */
    public function test_it_warns_about_the_irreversible_things(): void
    {
        $html = $this->render();

        // Release notifies winners; a phase never goes back; used records are retired
        // rather than deleted; a rejected registration is a stop.
        foreach (['tells people they won', 'only ever moves forward',
                  'retired', 'Rejected is a reason to stop'] as $warning) {
            $this->assertStringContainsString($warning, $html,
                "the handbook does not warn about: {$warning}");
        }
    }
}
