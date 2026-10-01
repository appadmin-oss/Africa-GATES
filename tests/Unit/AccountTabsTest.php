<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The account page's tabs, and the four-way agreement they depend on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The referral panel — the link, the earnings, the withdraw form — was fully built,
 * rendered into the page, and unreachable by anybody with JavaScript. Four things have to
 * line up for a section to be visible, and it satisfied one:
 *
 *   1. a `me_tabs` entry, or the script's `paint()` falls back to 'overview';
 *   2. a rail item, or there is nothing to click;
 *   3. `[data-me="<tab>"] #me-<tab>{display:block}`, because `[data-me] .me-sec` sets
 *      `display:none` on everything by default;
 *   4. a section whose id is exactly `me-<tab>`.
 *
 * Its id was `referral`, not `me-referral`, and no list mentioned it. So it was hidden on
 * every tab, and `/account#referral` — which the controller's own redirect uses after
 * minting a code — landed on Overview.
 *
 * Nothing failed. The page rendered, returned 200 and looked fine. That is why these
 * assertions are structural rather than a render check.
 */
final class AccountTabsTest extends TestCase
{
    private static function tpl(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/account/dashboard.twig'
        );
    }

    /** @return list<string> */
    private static function tabs(): array
    {
        preg_match('/\{%\s*set me_tabs\s*=\s*\[(.*?)\]\s*%\}/s', self::tpl(), $m);
        self::assertNotEmpty($m, 'the me_tabs list is gone');

        preg_match_all("/'([a-z-]+)'/", $m[1], $t);
        return $t[1];
    }

    /**
     * The list is defined ONCE and rendered into both scripts. It used to be written out
     * twice by hand, and the two drifting is precisely how the referral panel was lost.
     */
    public function test_the_tab_list_is_defined_once_and_reused(): void
    {
        $tpl = self::tpl();

        $this->assertSame(1, preg_match_all('/\{%\s*set me_tabs\s*=/', $tpl), 'more than one tab list');
        $this->assertSame(2, substr_count($tpl, 'me_tabs|json_encode|raw'),
            'both scripts should read the one list');
        // No hand-written copy left behind in either script. The `me_tabs` definition
        // itself is a literal, of course — what must not exist is a SECOND one that a
        // future edit could forget.
        foreach (['var ok  =', 'var ok =', 'var IDS  =', 'var IDS ='] as $decl) {
            $this->assertStringNotContainsString(
                $decl . " ['", $tpl,
                "a script still declares its own tab list ({$decl}) — that is the drift this fixed"
            );
        }
    }

    /**
     * The reveal rules are GENERATED from the one list.
     *
     * ── WHY THIS ASSERTION CHANGED SHAPE ────────────────────────────────────
     *
     * It used to look for a literal `[data-me="points"] #me-points` per tab, because the
     * block was seven rules typed out by hand. That made this a FOURTH copy of the list —
     * beside the two scripts and the rail — and it drifted exactly as the others had: "Your
     * stall" was added to `me_tabs`, to the markup and to the rail, and stayed invisible on
     * every tab because nothing here revealed it.
     *
     * The block is now a `{% for t in me_tabs %}` loop, so there is nothing per-tab left in
     * the template to grep for and nothing left to forget. What is checked here is that the
     * loop exists and reads the one list; that a rule actually comes out for every tab is
     * checked against the RENDERED page in AccountDashboardTest, which is the only place it
     * can honestly be checked.
     */
    public function test_the_reveal_rules_are_generated_from_the_one_list(): void
    {
        $tpl = self::tpl();

        $this->assertMatchesRegularExpression(
            '~\{%\s*for t in me_tabs\s*%\}\s*\[data-me="\{\{ t \}\}"\]\s*#me-\{\{ t \}\}~',
            $tpl,
            'the reveal rules are no longer generated from me_tabs, so they are a fourth '
            . 'hand-maintained copy of the tab list'
        );

        // And no per-tab literal has crept back in beside the loop.
        foreach (self::tabs() as $tab) {
            $this->assertStringNotContainsString(
                '[data-me="' . $tab . '"] #me-' . $tab, $tpl,
                "a hand-written rule for '{$tab}' is back — that is the drift the loop removed"
            );
        }
    }

    /** Every tab needs a section whose id matches it exactly. */
    public function test_every_tab_has_a_section_with_the_matching_id(): void
    {
        $tpl = self::tpl();

        foreach (self::tabs() as $tab) {
            $this->assertStringContainsString(
                'id="me-' . $tab . '"', $tpl,
                "the '{$tab}' tab has no section — its id must be exactly me-{$tab}"
            );
        }
    }

    /** And a rail item, or there is nothing to click. */
    public function test_every_tab_has_a_rail_item(): void
    {
        preg_match('/\{%\s*set rail\s*=\s*\[(.*?)\]\s*%\}/s', self::tpl(), $m);
        $this->assertNotEmpty($m, 'the rail list is gone');

        preg_match_all("/'id'\s*:\s*'([a-z-]+)'/", $m[1], $r);
        $railIds = $r[1];

        foreach (self::tabs() as $tab) {
            $this->assertContains($tab, $railIds, "the '{$tab}' tab has no rail item to click");
        }
    }

    /** The regression itself, named. */
    public function test_referrals_is_reachable(): void
    {
        $tpl = self::tpl();

        $this->assertContains('referral', self::tabs(), 'referrals is not a tab');
        $this->assertStringContainsString('id="me-referral"', $tpl);
        $this->assertStringContainsString("'label':'Referrals'", $tpl);
    }

    /**
     * The controller redirects to `/account#me-referral` after minting a code, after a
     * payout request and after saving bank details. That hash must name a real SECTION, or
     * all three land on Overview and the member is told nothing happened over a panel that
     * does not contain the form they just used.
     *
     * The hash is the section id (`#me-referral`) and not the bare tab name, because
     * `.me-sec:target` is what actually reveals a section — that is what makes the rail
     * work with no JavaScript, and a bare `#referral` matches no element at all.
     */
    public function test_the_controllers_redirect_hash_is_a_real_section(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(\AfricaGates\Controllers\AccountController::class))->getFileName()
        );
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/account/dashboard.twig'
        );

        preg_match_all("~'/account#([a-z-]+)'~", $src, $m);
        $this->assertNotEmpty($m[1], 'no anchored redirects found — did they change?');

        foreach (array_unique($m[1]) as $hash) {
            $this->assertStringContainsString('id="' . $hash . '"', $tpl,
                "the controller redirects to #{$hash}, which is not an element on the page — "
                . ':target matches nothing and the reader lands on Overview');
            $this->assertStringStartsWith('me-', $hash,
                'the hash must name the section, not the tab');
        }
    }

    /**
     * THE ONE THAT MATTERS. The rail must work with the script removed.
     *
     * Every tab was previously revealed by a delegated click handler setting `data-me`, so
     * anything that stopped that script — a 404 after a deploy, a CSP mismatch, an error
     * thrown earlier in the file — left every tab dead while the URL still changed. That
     * reads as "clicking the tabs does nothing" and is undiagnosable from the outside.
     */
    public function test_a_section_is_revealed_by_the_hash_alone(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/account/dashboard.twig'
        );

        $this->assertMatchesRegularExpression('~\.me-sec:target\s*\{[^}]*display\s*:\s*block~', $tpl,
            'without a :target rule the rail cannot work when the script does not run');

        // And the links have to be real anchors at those ids, or :target never fires.
        $this->assertStringContainsString('href="#me-{{ r.id }}"', $tpl,
            'the rail links must point at the section ids');

        // The handler must NOT swallow the click, or the browser never navigates the hash
        // and :target is bypassed — which is the bug this whole change removes.
        $handler = substr($tpl, strpos($tpl, "page.addEventListener('click'") ?: 0, 700);
        $this->assertStringNotContainsString('preventDefault', $handler,
            'intercepting the click puts the rail back on the script it was failing without');
    }

    /**
     * ══ THE PHONE HUB IS A FIFTH PLACE A SECTION ID IS SPELLED ═════════════════
     *
     * The docblock above counts four things that have to agree for a section to be
     * visible. §2's phone hub added two more readers of the same list — the child bar's
     * title, which is the only thing naming the screen you are pushed into, and the
     * "Your account" list, which at phone width is the ONLY way to reach a section
     * because the rail is hidden there.
     *
     * So a section added to `me_tabs` and to the rail but missing a title renders a
     * pushed view with a back button and no heading, and one missing from the list is
     * unreachable on a phone while being perfectly reachable on the desk the person
     * adding it was sitting at. Same failure as the referral panel, two surfaces later,
     * and invisible from every width a developer tests at by default.
     *
     * Proved by breaking it: dropping the `{% for r in rail %}` loop from the child bar
     * fails the first assertion, and hard-coding one id into it fails the second.
     */
    public function test_every_section_has_a_title_in_the_phone_child_bar(): void
    {
        $tpl = self::tpl();

        // The titles are GENERATED, not typed. A literal `data-me-title="points"` would
        // pass a per-id check while being the drift this whole file exists to stop.
        $this->assertMatchesRegularExpression(
            '/\{%\s*for r in rail\s*%\}.{0,400}?data-me-title="\{\{ r\.id \}\}"/s',
            $tpl,
            'the child bar titles are no longer looped from `rail` — a typed list is the drift'
        );

        // The reveal rules are generated from `me_tabs` the same way the section rules
        // above them are, so there is no per-tab literal to look for — looking for one is
        // what made the first version of this test fail on a template that was correct.
        $this->assertMatchesRegularExpression(
            '~\{%\s*for t in me_tabs\s*%\}\s*\[data-me="\{\{ t \}\}"\]\s*\.me-ph__t\[data-me-title="\{\{ t \}\}"\]~',
            $tpl,
            'the child-bar titles are no longer revealed from me_tabs, so a new section '
            . 'pushes to a bar with no heading on it'
        );

        foreach (self::tabs() as $tab) {
            $this->assertStringNotContainsString(
                '.me-ph__t[data-me-title="' . $tab . '"]', $tpl,
                "a hand-written reveal rule for '{$tab}' is back — that is the drift the loop removed"
            );
        }
    }

    /**
     * The rail is `display:none` below 600px, so this list is the whole of phone
     * navigation. It is looped from the same `rail` array for that reason.
     */
    public function test_the_phone_list_is_the_rail_and_not_a_copy_of_it(): void
    {
        $tpl = self::tpl();

        $start = strpos($tpl, 'class="me-more__l"');
        $this->assertNotFalse($start, 'the phone "Your account" list is gone');
        $block = substr($tpl, $start, 900);

        $this->assertStringContainsString('{% for r in rail %}', $block,
            'the phone list is hand-written — that is the fourth copy of the section list');
        $this->assertStringContainsString('href="#me-{{ r.id }}"', $block,
            'the rows must be real hash links, or phone navigation needs the script the rest of this page deliberately does not');
        $this->assertStringContainsString("r.id != 'overview'", $block,
            'Overview is the page this list is ON; a row pointing at it goes nowhere');

        // And the rail itself must actually be hidden there, or both are on screen and
        // the page says the same thing twice in two shapes.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width:599px\)\{.*?\.me-rail\{ display:none/s',
            $tpl,
            'the rail is still drawn at phone width beside the list that replaces it'
        );
    }

    /**
     * Hiding a balance has to happen BEFORE the first paint, which means the head
     * script and not the foot one. Applied at the foot, the number a member asked us to
     * hide is on the screen for a frame on every load — on a slow connection, a second —
     * which is the whole of what the control was for.
     */
    public function test_the_balance_answer_is_applied_before_first_paint(): void
    {
        $tpl = self::tpl();

        $head = substr($tpl, 0, strpos($tpl, '{% block content %}') ?: 0);
        $this->assertStringContainsString("localStorage.getItem('ag-hide-bal')", $head,
            'the stored answer is read after first paint, so the balance flashes on every load');
        $this->assertStringContainsString("setAttribute('data-bal', 'hidden')", $head);

        // It must be wrapped: localStorage THROWS in a private window, and an exception
        // in that script aborts the section choice above it and leaves every tab dead.
        $probe = substr($head, strpos($head, "ag-hide-bal") - 400, 600);
        $this->assertStringContainsString('try {', $probe,
            'an unguarded localStorage read here takes the whole head script down in a private window');

        // `display`, not `visibility`: a hidden-but-present balance is still read aloud.
        $this->assertMatchesRegularExpression(
            '/\[data-bal="hidden"\] \.me-cash\{ display:none/', $tpl,
            'the real figure must leave the accessibility tree, not just the screen');
    }
}
