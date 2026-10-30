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
}
