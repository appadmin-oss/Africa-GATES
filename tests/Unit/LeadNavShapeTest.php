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

    /**
     * An "add" form is folded on a page that already lists things, open on an empty one —
     * one rule in the page builder, not a flag in fifteen builders. A form that says how it
     * folds (the report's own, kept open) is left as it said.
     */
    public function test_adding_forms_fold_only_where_the_page_already_has_content(): void
    {
        $fold = new \ReflectionMethod(\AfricaGates\Services\EventPlan\EventPages::class, 'foldAdders');
        $form = ['k' => 'form', 'h' => 'Add work', 'do' => 'work_add', 'groups' => []];
        $empty = $fold->invoke(null, [['k' => 'note', 'text' => 'Nothing yet'], $form]);
        $this->assertTrue($empty[1]['fold']);
        $this->assertTrue($empty[1]['open'], 'on an empty page adding the first is the point');
        $listed = $fold->invoke(null, [['k' => 'rows', 'items' => []], $form]);
        $this->assertFalse($listed[1]['open'], 'the form sat above the list it adds to');
        $kept = $fold->invoke(null, [['k' => 'rows', 'items' => []], ['fold' => false] + $form]);
        $this->assertFalse($kept[1]['fold']);
        $other = $fold->invoke(null, [['k' => 'rows', 'items' => []], ['k' => 'form', 'h' => 'Event settings', 'groups' => []]]);
        $this->assertArrayNotHasKey('fold', $other[1]);
    }
}
