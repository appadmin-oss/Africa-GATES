<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\EditionPrizes;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Prizes per edition, by place (owner, 31 Oct 2026). What these try to break: one edition's
 * prizes showing on another; a gap in the places; cash typed with commas refused, or words in the
 * cash box accepted; the old award-wide box surviving as a second place prizes live; and the
 * award page not saying the places are overall.
 */
final class EditionPrizesTest extends TestCase
{
    private int $y1;
    private int $y2;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'teachers', 'title' => 'Teachers', 'is_active' => 1]);
        $this->y1 = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => 1, 'year' => 2025, 'status' => 'archived']);
        $this->y2 = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => 1, 'year' => 2026, 'status' => 'voting']);
    }

    public function test_places_are_numbered_in_order_per_edition(): void
    {
        $this->assertNull(EditionPrizes::save($this->y2, [
            ['award' => 'A trophy', 'cash' => '₦1,000,000'], ['award' => '', 'cash' => ''], ['award' => '', 'cash' => '250000'],
        ]));
        $p = EditionPrizes::for($this->y2);
        $this->assertSame(['1st place', '2nd place'], array_column($p, 'label'), 'no gap where a row was blank');
        $this->assertSame('₦1,000,000 and a trophy', $p[0]['text']);
        $this->assertSame('₦250,000', $p[1]['text']);
        $this->assertSame([], EditionPrizes::for($this->y1), 'last year is not this year');

        $this->assertNotNull(EditionPrizes::save($this->y2, [['award' => 'Trophy', 'cash' => 'a lot']]), 'words in the cash box');
        $this->assertCount(2, EditionPrizes::for($this->y2), 'a refused save changes nothing');
        $this->assertNotNull(EditionPrizes::save($this->y2, array_fill(0, 11, ['award' => 'x', 'cash' => ''])));
        $this->assertSame('11th place', EditionPrizes::ordinal(11));
        $this->assertSame('22nd place', EditionPrizes::ordinal(22));
    }

    public function test_the_old_award_wide_box_moves_to_the_latest_edition_and_is_gone(): void
    {
        DB::table('gates_award_programmes')->where('id', 1)->update(['details_json' => json_encode(['prize' => 'A trophy for the winner', 'voting_note' => 'Vote'])]);
        ob_start();
        require dirname(__DIR__, 2) . '/database/migrations/2027_04_28_edition_prizes.php';
        ob_end_clean();
        $this->assertSame('A trophy for the winner', EditionPrizes::for($this->y2)[0]['award']);
        $this->assertSame(['voting_note' => 'Vote'], json_decode((string) DB::table('gates_award_programmes')->where('id', 1)->value('details_json'), true));
        $this->assertArrayNotHasKey('prize', \AfricaGates\Support\AwardDetails::FIELDS, 'one place prizes live, not two');
    }

    public function test_the_award_page_lists_the_places_as_overall(): void
    {
        EditionPrizes::save($this->y2, [['award' => 'A trophy', 'cash' => '500000']]);
        $a = \AfricaGates\Services\AwardsFront::detail('teachers', 2026);
        $this->assertSame('₦500,000 and a trophy', $a['prizes'][0]['text'] ?? null);
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/templates/pages/awards/programme.twig');
        $this->assertStringContainsString('a.prizes', $tpl);
        $this->assertStringContainsString('across every category', $tpl);
    }

    public function test_prizes_say_they_depend_on_the_milestone_until_it_is_reached(): void
    {
        $this->assertNull(EditionPrizes::condition($this->y2), 'no milestone, nothing to state');
        $this->assertTrue(\AfricaGates\Services\CommunityReturnMilestone::saveEditionRules($this->y2, ['model' => 'milestone', 'full_pct' => 50, 'locked_pct' => 10, 'milestone_naira' => 500000])['ok']);
        $c = EditionPrizes::condition($this->y2);
        $this->assertFalse($c['reached']);
        $this->assertStringContainsString('depend on the edition reaching its ₦500,000 milestone (₦0 so far)', $c['text']);
        $this->assertStringContainsString('may be reduced or not awarded', $c['letter']);
        $this->assertStringNotContainsString('so far', $c['letter'], 'a letter is read later: no figure that will have moved');

        EditionPrizes::setOnMilestone($this->y2, false);
        $this->assertNull(EditionPrizes::condition($this->y2), 'turned off for this edition');
        EditionPrizes::setOnMilestone($this->y2, true);

        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->y2, 'slug' => 'sci', 'title' => 'Science']);
        $nom = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat, 'name' => 'Ada', 'status' => 'approved']);
        DB::table('gates_donations')->insert(['donor_name' => 'C', 'donor_email' => 'c@example.com', 'amount_naira' => 500000, 'tier' => 'paid-vote',
            'bonus_votes' => 0, 'votes_used' => 1, 'intent_nominee_id' => $nom, 'payment_ref' => 'R1', 'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
        $reached = EditionPrizes::condition($this->y2);
        $this->assertTrue($reached['reached']);
        $this->assertSame('The edition has reached its ₦500,000 milestone, so these prizes will be awarded.', $reached['text']);
    }
}
