<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{BonusVoteService, RuleEngine};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A CEILING THAT MEASURED A COLUMN NOTHING FILLS.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Bonus votes — the ones granted against a contribution, and the ones redeemed with
 * points — are bounded per nominee by `max_paid_weight_pct`, so that a tally cannot be
 * mostly granted. The ceiling was a percentage of `organic_vote_count`.
 *
 * That column can only be written by `VoteService::castVote()`, which answers 403 on
 * any deployment with `paid_voting_disable_free` set. So on such a site it is
 * permanently zero, the ceiling collapsed to its floor, and every nominee was capped at
 * TEN granted votes forever — whatever anybody contributed. The refusal read
 *
 *     "Bonus votes for this nominee are capped at 10 (50% of organic support)."
 *
 * to an operator whose site has no organic votes by design, and cannot get any.
 *
 * It was also measuring the wrong thing by then. The ceiling existed to stop granted
 * weight swamping "the community signal", and that signal was `organic_vote_count`
 * until the index started normalising over the full tally. It was guarding a quantity
 * nothing reads.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE BASE EXCLUDES THE GRANTS, WHICH IS THE PART THAT LOOKS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Bonus weight increments `vote_count`. A ceiling read straight off the tally would
 * therefore rise with every grant it permitted — 50% of a growing number converges on
 * no limit at all — so the base is the tally MINUS what has already been granted.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT IS ONE FUNCTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `PointsService` carried its own copy of the same four lines and was broken in exactly
 * the same way. Two copies of a rule is how one of them gets fixed.
 */
final class BonusVoteCapTest extends TestCase
{
    private function nominee(int $tally): int
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'), 'status' => 'voting',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 10, 'cycle_id' => 1, 'slug' => 'cat-10', 'title' => 'Category',
        ]);
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 10, 'name' => 'A Nominee', 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => $tally,
            // ZERO, deliberately, and the whole point of this file: this is what the
            // column reads on every deployment that has switched free voting off.
            'organic_vote_count' => 0,
        ]);
    }

    private function grant(int $nomineeId, int $weight): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $nomineeId, 'category_id' => 10,
            'voter_email_hash' => 'bonus:test:' . bin2hex(random_bytes(6)),
            'vote_type' => 'bonus', 'weight' => $weight,
            'voted_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ══ the ceiling actually moves ═══════════════════════════════════════════

    public function test_the_ceiling_reads_support_that_exists_rather_than_a_zero_column(): void
    {
        (new RuleEngine())->set('global', null, ['max_paid_weight_pct' => 50]);
        $id = $this->nominee(1000);

        $c = BonusVoteService::capFor($id, 1000, null, null);

        $this->assertSame(500, $c['cap'],
            'the ceiling is reading organic_vote_count again — on a paid-only site that '
            . 'is permanently zero, so every nominee is capped at ten grants forever');
        $this->assertSame(1000, $c['base']);
        $this->assertSame(0, $c['used']);
        $this->assertSame(50, $c['pct']);
    }

    /**
     * A GRANT DOES NOT RAISE ITS OWN CEILING.
     *
     * Bonus weight increments `vote_count`, so a cap taken off the raw tally would be
     * 500 here rather than 400, and would keep climbing with every grant it allowed.
     * That is not a cap, it is a ratchet — and it fails open, silently, exactly like
     * the fault this file was written for.
     */
    public function test_weight_already_granted_is_not_part_of_the_base_it_is_measured_against(): void
    {
        (new RuleEngine())->set('global', null, ['max_paid_weight_pct' => 50]);
        $id = $this->nominee(1000);
        $this->grant($id, 200);

        $c = BonusVoteService::capFor($id, 1000, null, null);

        $this->assertSame(800, $c['base'], 'the grants are counting toward their own ceiling');
        $this->assertSame(400, $c['cap']);
        $this->assertSame(200, $c['used']);
    }

    /** The floor still lets the first contributions through on an empty nominee. */
    public function test_a_nominee_with_no_support_yet_is_not_blocked_at_zero(): void
    {
        (new RuleEngine())->set('global', null, ['max_paid_weight_pct' => 50]);
        $id = $this->nominee(0);

        $this->assertSame(BonusVoteService::MIN_BONUS_CAP,
            BonusVoteService::capFor($id, 0, null, null)['cap']);
    }

    /** And the rate is the rule's, not a constant. */
    public function test_the_percentage_comes_from_the_rule_engine(): void
    {
        (new RuleEngine())->set('global', null, ['max_paid_weight_pct' => 20]);
        $id = $this->nominee(1000);

        $c = BonusVoteService::capFor($id, 1000, null, null);
        $this->assertSame(200, $c['cap']);
        $this->assertSame(20, $c['pct']);
    }

    // ══ one resolver ═════════════════════════════════════════════════════════

    /**
     * NOBODY WORKS THIS OUT A SECOND TIME.
     *
     * `PointsService` and `BonusVoteService` each had the formula, and both were wrong
     * in the same way for the same reason. A file that reads `max_paid_weight_pct`
     * directly is a file that will not be fixed with the other one.
     */
    public function test_the_cap_is_computed_in_exactly_one_place(): void
    {
        $root = dirname(__DIR__, 2) . '/src/Services/';
        foreach (['PointsService.php', 'BonusVoteService.php'] as $f) {
            $src = (string) file_get_contents($root . $f);
            // Comments stripped: both files' notes NAME the setting while explaining
            // that they no longer read it themselves. Six times in this repository a
            // scanner has reported a fix as the fault it fixed.
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ', $src);

            $reads = substr_count($code, 'max_paid_weight_pct');
            $this->assertLessThanOrEqual($f === 'BonusVoteService.php' ? 1 : 0, $reads,
                $f . ' resolves the bonus ceiling itself instead of asking capFor()');
        }
    }
}
