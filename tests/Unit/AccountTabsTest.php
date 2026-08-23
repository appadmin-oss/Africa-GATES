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

    /** Every tab needs the CSS rule that un-hides its section. */
    public function test_every_tab_has_a_rule_that_shows_its_section(): void
    {
        $tpl = self::tpl();

        foreach (self::tabs() as $tab) {
            $this->assertStringContainsString(
                '[data-me="' . $tab . '"]  #me-' . $tab,
                (string) preg_replace('/ +/', '  ', $tpl),
                "no CSS un-hides the '{$tab}' section, so [data-me] .me-sec keeps it display:none"
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
}
