<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every card on /admin/settings belongs to a group that has a tab.
 *
 * The cards are shown by group and the groups are a fixed list in the template, so a card
 * filed under a group with no tab renders nowhere unless somebody happens to search for
 * it. "Arrivals" — the visitor counting and its consent mode — sat under `data` for
 * exactly that reason, invisible on the screen an operator would look for it on.
 */
final class SettingsGroupsTest extends TestCase
{
    public function test_no_card_is_filed_under_a_group_with_no_tab(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');
        $this->assertSame(1, preg_match('/\{% set st_sections = \[(.*?)\] %\}/s', $tpl, $m), 'the group list moved');
        preg_match_all("/id:\s*'([a-z]+)'/", $m[1], $tabs);
        preg_match_all('/class="ad-card"[^>]*\bdata-sec="([a-z]+)"|data-sec="([a-z]+)"/', $tpl, $cards);
        $used = array_unique(array_filter(array_merge($cards[1], $cards[2])));

        $this->assertNotEmpty($tabs[1]);
        $this->assertGreaterThan(20, count($cards[0]), 'the sweep found too few cards to mean anything');
        $this->assertSame([], array_values(array_diff($used, $tabs[1])), 'a card is filed under a group with no tab');
    }
}
