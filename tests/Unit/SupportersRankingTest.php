<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\SupportersService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The public supporters list.
 *
 * Two things are being pinned. First, that a PERSON is the unit: the list used
 * to be one entry per vote row, so a backer who bought votes three times filled
 * three slots and a nominee with four loyal supporters looked like it had twelve.
 * Second, that consent still gates everything — the ranking rework must not have
 * widened who appears.
 */
final class SupportersRankingTest extends TestCase
{
    private int $nominee = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_votes')->delete();
    }

    private function vote(string $name, int $weight, int $consent = 1, ?int $nominee = null): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $nominee ?? $this->nominee, 'category_id' => 1,
            'voter_name' => $name, 'show_name' => $consent, 'weight' => $weight,
            'vote_type' => 'paid', 'voter_email_hash' => hash('sha256', $name . random_int(1, 1000000)),
            'voted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function test_one_person_with_many_votes_is_one_supporter(): void
    {
        $this->vote('Ada Okonkwo', 5);
        $this->vote('Ada Okonkwo', 3);
        $this->vote('Ada Okonkwo', 2);
        $this->vote('Chidi Okeke', 1);

        $list = SupportersService::forNominee($this->nominee);
        $this->assertCount(2, $list, 'three vote rows from one person are one supporter');
        $this->assertSame('Ada Okonkwo', $list[0]['name']);
        $this->assertSame(10, $list[0]['votes'], 'their votes are summed, not listed separately');
    }

    public function test_the_list_is_ranked_by_contribution(): void
    {
        $this->vote('Small Backer', 1);
        $this->vote('Big Backer', 40);
        $this->vote('Middle Backer', 12);

        $this->assertSame(
            ['Big Backer', 'Middle Backer', 'Small Backer'],
            array_column(SupportersService::forNominee($this->nominee), 'name')
        );
    }

    /** Same person, different capitalisation and spacing — still one supporter. */
    public function test_names_are_folded_case_and_space_insensitively(): void
    {
        $this->vote('ADA  OKONKWO', 2);
        $this->vote('ada okonkwo', 9);

        $list = SupportersService::forNominee($this->nominee);
        $this->assertCount(1, $list);
        $this->assertSame(11, $list[0]['votes']);
        $this->assertSame('ada okonkwo', $list[0]['name'], 'the spelling with the most votes wins');
    }

    public function test_at_most_ten_are_returned(): void
    {
        for ($i = 0; $i < 18; $i++) $this->vote('Backer ' . $i, $i + 1);

        $list = SupportersService::forNominee($this->nominee);
        $this->assertCount(SupportersService::DEFAULT_LIMIT, $list);
        $this->assertSame(10, SupportersService::DEFAULT_LIMIT);
        $this->assertSame('Backer 17', $list[0]['name'], 'the biggest backer leads');
    }

    /** The rework must not have loosened the consent gate. */
    public function test_only_people_who_consented_appear(): void
    {
        $this->vote('Consented', 3, 1);
        $this->vote('Did Not Consent', 99, 0);
        $this->vote('Supporter', 50, 1);          // the checkout's "no name given" placeholder
        $this->vote('', 50, 1);

        $names = array_column(SupportersService::forNominee($this->nominee), 'name');
        $this->assertSame(['Consented'], $names);
        $this->assertSame(1, SupportersService::countForNominee($this->nominee));
    }

    /** The count must be over PEOPLE, or the "and N more" tail contradicts the list. */
    public function test_the_total_counts_people_not_rows(): void
    {
        foreach (range(1, 6) as $_) $this->vote('Ada Okonkwo', 1);
        $this->vote('Chidi Okeke', 1);

        $this->assertSame(2, SupportersService::countForNominee($this->nominee));
    }

    public function test_another_nominees_supporters_are_not_included(): void
    {
        $this->vote('Mine', 1);
        $this->vote('Theirs', 99, 1, 9999);

        $this->assertSame(['Mine'], array_column(SupportersService::forNominee($this->nominee), 'name'));
    }

    /**
     * The overflow label. Exact while exactness is information; rounded DOWN to a
     * round number with a "+" once it is not — never up, or the label claims more
     * supporters than exist.
     */
    public function test_the_overflow_label_rounds_down_once_the_number_is_large(): void
    {
        $this->assertSame('',                SupportersService::overflowLabel(0));
        $this->assertSame('and 1 more',      SupportersService::overflowLabel(1));
        $this->assertSame('and 24 more',     SupportersService::overflowLabel(24));
        $this->assertSame('and 25+ more',    SupportersService::overflowLabel(25));
        $this->assertSame('and 25+ more',    SupportersService::overflowLabel(49));
        $this->assertSame('and 50+ more',    SupportersService::overflowLabel(63));
        $this->assertSame('and 100+ more',   SupportersService::overflowLabel(147));
        $this->assertSame('and 500+ more',   SupportersService::overflowLabel(880));
        $this->assertSame('and 1,000+ more', SupportersService::overflowLabel(1247));
        $this->assertSame('and 4,000+ more', SupportersService::overflowLabel(4999));
    }
}
