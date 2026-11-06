<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventPlan\LeadPages;
use PHPUnit\Framework\TestCase;

/**
 * A lead with one event was shown nine top-level items, four of them ("Deadlines, Risks,
 * Approvals, Analytics across your events") that event's own pages under a second name. With
 * one event they are left out — and never while the lead stands on one, so a page is never
 * missing from the menu that brought them to it.
 */
final class LeadNavShapeTest extends TestCase
{
    /** @return list<string> */
    private static function keys(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) foreach ($g['items'] as $i) $out[] = $i['key'];
        return $out;
    }

    public function test_one_event_hides_the_cross_event_group_but_never_the_current_page(): void
    {
        $one = self::keys(LeadPages::rootNav('', [], 1));
        $this->assertNotContains('risks', $one);
        $this->assertContains('overview', $one);
        $this->assertContains('people', $one);

        $this->assertContains('risks', self::keys(LeadPages::rootNav('risks', [], 1)), 'the page you are on left the menu');
        $this->assertContains('deadlines', self::keys(LeadPages::rootNav('', [], 2)), 'two events need the cross-event pages');
    }
}
