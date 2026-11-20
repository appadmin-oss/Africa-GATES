<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\AnalyticsService;
use AfricaGates\Services\MergeService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Two things a merge and a count owe each other (26 Oct 2026).
 *
 * An imported tally — votes held in `vote_count` with no ballot rows behind them — is part
 * of an entry's total, so a merge carries it and an unmerge gives each entry its own back.
 * The merge used to rebuild the survivor from the ledger alone: 120 + 45 imported votes
 * merged to 1, and an undo left the survivor on 0, because it rebuilt from the ledger too.
 *
 * And the counts read by people agree with the rule the platform pays on: one buyer of ten
 * orders is one voter, and a merged-away duplicate is nobody's second nominee.
 */
final class MergeCountsTest extends TestCase
{
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'mc-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $c = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'voting']);
        $this->cat[] = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $c, 'slug' => 'mc' . bin2hex(random_bytes(3)), 'title' => 'Teacher of the Year', 'sort_order' => 1]);
    }

    private function nominee(string $name, int $votes = 0): int
    {
        return (int) DB::table('gates_nominees')->insertGetId(['category_id' => $this->cat[0], 'name' => $name, 'status' => 'approved',
            'vote_count' => $votes, 'organic_vote_count' => $votes, 'country_code' => 'NG']);
    }

    private function vote(int $nominee, string $hash, array $o = []): void
    {
        DB::table('gates_votes')->insert($o + ['nominee_id' => $nominee, 'category_id' => $this->cat[0], 'voter_email_hash' => $hash,
            'vote_type' => 'standard', 'weight' => 1, 'voted_at' => date('Y-m-d H:i:s')]);
    }

    public function test_an_imported_tally_is_carried_by_a_merge_and_given_back_by_an_unmerge(): void
    {
        // 120 and 45 imported, one real ballot each on top.
        $a = $this->nominee('Grace Mensah', 121);
        $b = $this->nominee('Mrs Grace Mensah', 46);
        $this->vote($a, hash('sha256', 'a@x.org'));
        $this->vote($b, hash('sha256', 'b@x.org'));
        $this->assertSame(165, MergeService::importedTally([$a, $b]));

        $preview = MergeService::preview($a, [$b]);
        $this->assertSame(167, (int) $preview['votes'], 'the ledger alone says 2');

        $r = MergeService::mergeNominees($a, [$b]);
        $this->assertTrue($r['ok']);
        $this->assertSame(167, (int) DB::table('gates_nominees')->where('id', $a)->value('vote_count'));
        $this->assertSame(167, (int) DB::table('gates_nominees')->where('id', $a)->value('organic_vote_count'));

        // A vote cast for the survivor after the merge stays with it on the undo.
        $this->vote($a, hash('sha256', 'late@x.org'));
        DB::table('gates_nominees')->where('id', $a)->increment('vote_count');
        MergeService::unmerge($b);
        $this->assertSame(122, (int) DB::table('gates_nominees')->where('id', $a)->value('vote_count'),
            'the survivor keeps its own 120 imported, its ballot and the late one');
        $this->assertSame(46, (int) DB::table('gates_nominees')->where('id', $b)->value('vote_count'));
    }

    public function test_a_ledgered_merge_is_unchanged_by_the_carry(): void
    {
        $a = $this->nominee('Tunde Bakare', 2);
        $b = $this->nominee('Tunde  Bakare', 1);
        $this->vote($a, hash('sha256', '1@x.org'));
        $this->vote($a, hash('sha256', '2@x.org'));
        $this->vote($b, hash('sha256', '3@x.org'));
        $this->assertSame(0, MergeService::importedTally([$a, $b]));
        MergeService::mergeNominees($a, [$b]);
        $this->assertSame(3, (int) DB::table('gates_nominees')->where('id', $a)->value('vote_count'));
        MergeService::unmerge($b);
        $this->assertSame([2, 1], [(int) DB::table('gates_nominees')->where('id', $a)->value('vote_count'),
                                    (int) DB::table('gates_nominees')->where('id', $b)->value('vote_count')]);
    }

    public function test_one_buyer_of_several_orders_is_one_voter_on_the_analytics_screen(): void
    {
        $n = $this->nominee('Adaeze Okafor');
        foreach ([1, 2, 3] as $_) {
            $d = (int) DB::table('gates_donations')->insertGetId(['donor_name' => 'Buyer', 'donor_email' => 'buyer@x.org',
                'amount_naira' => 200, 'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
            $this->vote($n, 'paidvote:' . $d . ':' . bin2hex(random_bytes(4)), ['vote_type' => 'paid', 'donation_id' => $d, 'weight' => 10]);
        }
        $this->vote($n, hash('sha256', 'buyer@x.org'));             // and their own free vote
        $this->vote($n, 'bonus:1:' . bin2hex(random_bytes(4)), ['vote_type' => 'bonus']);   // a grant: nobody
        $this->assertSame(1, AnalyticsService::voting(30)['voters']);
    }

    public function test_a_merged_away_duplicate_is_not_a_second_nominee_in_the_funnel(): void
    {
        $a = $this->nominee('Chidi Nwosu');
        $b = $this->nominee('Chidi  Nwosu');
        $live = static function (): int {
            foreach (AnalyticsService::nominationFunnel()['stages'] as $s) if ($s['key'] === 'nominee') return (int) $s['n'];
            return -1;
        };
        $before = $live();
        MergeService::mergeNominees($a, [$b]);
        $this->assertSame($before - 1, $live());
    }
}
