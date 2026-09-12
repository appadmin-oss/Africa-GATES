<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\SettingsController;
use AfricaGates\Services\PaidVoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The admin's tier editor → the JSON the ballot prices from.
 *
 * `tiersJson()` is the ONLY writer of `vote_tiers`, and what it writes is read on
 * every public ballot render. So the cases that matter here are the ones where the
 * form does not say what it looks like it says: a row the admin cleared, two rows
 * for the same quantity, a 100% discount, and — the dangerous one — a post that
 * contains no tier fields at all.
 *
 * Exercised through reflection because the method is private and should stay that
 * way; the alternative is booting a Twig + SettingsService + AuditService triple to
 * reach one pure function.
 */
final class VoteTierEditorTest extends TestCase
{
    /** @param array<string,mixed> $body */
    private function normalise(array $body): ?string
    {
        $c = (new \ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(SettingsController::class, 'tiersJson');
        $m->setAccessible(true);
        /** @var string|null $out */
        $out = $m->invoke($c, $body);
        return $out;
    }

    /** @return list<array{qty:int,off:int}> */
    private function decode(?string $json): array
    {
        return $json === null || $json === '' ? [] : (array) json_decode($json, true);
    }

    /**
     * THE ONE THAT PROTECTS LIVE PRICING. The tier inputs are rendered by Alpine's
     * x-for, so a browser where that script did not run posts no `vote_tier_qty[]` at
     * all. Reading that as "the admin emptied the ladder" would reprice the site to
     * defaults for someone who came here to edit the announcement banner.
     */
    public function test_a_post_with_no_tier_fields_leaves_the_ladder_alone(): void
    {
        $this->assertNull($this->normalise(['paid_vote_settings' => '1', 'vote_price_naira' => '150']));
    }

    public function test_rows_become_json_sorted_by_quantity(): void
    {
        $json = $this->normalise([
            'vote_tier_qty' => ['30', '1', '10'],
            'vote_tier_off' => ['20', '0', '5'],
        ]);

        $this->assertSame(
            [['qty' => 1, 'off' => 0], ['qty' => 10, 'off' => 5], ['qty' => 30, 'off' => 20]],
            $this->decode($json),
            'whatever order the admin typed, the ladder is ascending'
        );
    }

    /** Clearing a quantity is how you delete a tier — it cannot be an error. */
    public function test_a_cleared_row_is_a_deleted_tier(): void
    {
        $json = $this->normalise([
            'vote_tier_qty' => ['1', '', '20'],
            'vote_tier_off' => ['0', '15', '25'],
        ]);

        $this->assertSame([['qty' => 1, 'off' => 0], ['qty' => 20, 'off' => 25]], $this->decode($json));
    }

    /**
     * Clearing EVERY row saves blank, and blank is what the reader answers with the
     * defaults. That equivalence is the reset button.
     */
    public function test_clearing_every_row_resets_to_the_defaults(): void
    {
        $json = $this->normalise(['vote_tier_qty' => ['', '0'], 'vote_tier_off' => ['5', '9']]);

        $this->assertSame('', $json, 'posted but empty is not the same as never posted');
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'vote_tiers'], ['value' => $json]);
        $this->assertSame(PaidVoteService::DEFAULT_TIERS, PaidVoteService::tiers());
    }

    /** 100% off is a ₦0 order no gateway will take, so it is clamped, not accepted. */
    public function test_the_discount_is_clamped_to_the_zero_to_ninety_band(): void
    {
        $json = $this->normalise([
            'vote_tier_qty' => ['5', '10'],
            'vote_tier_off' => ['100', '-40'],
        ]);

        $this->assertSame([['qty' => 5, 'off' => 90], ['qty' => 10, 'off' => 0]], $this->decode($json));
    }

    /** Two rows for one quantity would be a rung nothing can ever reach. */
    public function test_a_duplicated_quantity_collapses_to_the_last_one_typed(): void
    {
        $json = $this->normalise([
            'vote_tier_qty' => ['10', '10'],
            'vote_tier_off' => ['5', '25'],
        ]);

        $this->assertSame([['qty' => 10, 'off' => 25]], $this->decode($json));
    }

    /** A quantity is a quantity wherever it is typed — same ceiling as everywhere else. */
    public function test_the_quantity_is_capped_at_the_hard_maximum(): void
    {
        $json = $this->normalise([
            'vote_tier_qty' => [(string) (PaidVoteService::HARD_MAX_QTY * 10)],
            'vote_tier_off' => ['10'],
        ]);

        $this->assertSame([['qty' => PaidVoteService::HARD_MAX_QTY, 'off' => 10]], $this->decode($json));
    }

    /**
     * End to end: what the editor writes is what the ballot charges. The chips and the
     * prices come out of this same string, which is the whole point of storing one list.
     */
    public function test_what_the_editor_saves_is_what_the_ballot_charges(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'vote_price_naira'], ['value' => '200']);
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'vote_tiers'],
            ['value' => (string) $this->normalise([
                'vote_tier_qty' => ['1', '6', '25'],
                'vote_tier_off' => ['0', '10', '30'],
            ])]
        );

        $this->assertSame([1, 6, 25], PaidVoteService::chips());
        $this->assertSame(200,  PaidVoteService::price(1));
        $this->assertSame(1080, PaidVoteService::price(6),  '1200 less 10%');
        $this->assertSame(3500, PaidVoteService::price(25), '5000 less 30%');
        $this->assertSame(1500, PaidVoteService::savingFor(25));
    }
}
