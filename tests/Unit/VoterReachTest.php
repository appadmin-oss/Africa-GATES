<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CpiService, VoterReach, VoteService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * ONE PERSON IS ONE PERSON, HOWEVER MANY TIMES THEY PAID.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE IS THE LOAD-BEARING ONE FOR THE WHOLE BASIS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Seventy per cent of the community half — 315 of 450 — is "how many verified people
 * backed this nominee". Every argument for the reach basis rests on that number being a
 * count of HUMAN BEINGS, because the single claim being made is that money cannot buy it
 * in bulk.
 *
 * And the obvious implementation destroys that claim. `COUNT(DISTINCT voter_email_hash)`
 * counts rows, and three of the five services that mint a vote row write a RANDOMISED
 * synthetic hash — one per order, one per grant, one per redemption. Under a distinct
 * count, a supporter who splits ₦200,000 into a thousand ₦200 orders buys a thousand
 * units of reach, which is the exact scheme the seventy per cent exists to defeat, and
 * every screen would agree with itself while it happened.
 *
 * So these are not incidental unit tests. Each one pins a way the count could go back to
 * being a transaction count without anything else in the suite noticing.
 */
final class VoterReachTest extends TestCase
{
    private const CAT = 70;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 1, 'slug' => 'reach-cat', 'title' => 'Reach',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 700, 'category_id' => self::CAT, 'name' => 'N', 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
    }

    private function organic(string $email, int $weight = 1, int $nominee = 700): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $nominee, 'category_id' => self::CAT, 'vote_type' => 'standard',
            'weight' => $weight, 'voter_email_hash' => VoteService::voterHash($email),
        ]);
    }

    private function order(int $id, string $email, int $qty, int $nominee = 700): void
    {
        DB::table('gates_donations')->insertOrIgnore([
            'id' => $id, 'donor_name' => 'Buyer', 'donor_email' => $email,
            'amount_naira' => $qty * 200, 'status' => 'confirmed',
        ]);
        DB::table('gates_votes')->insert([
            'nominee_id' => $nominee, 'category_id' => self::CAT, 'vote_type' => 'paid',
            'weight' => $qty, 'donation_id' => $id,
            'voter_email_hash' => 'paidvote:' . $id . ':' . bin2hex(random_bytes(6)),
        ]);
    }

    // ══ the scheme this exists to defeat ═════════════════════════════════════

    /**
     * A THOUSAND SMALL ORDERS FROM ONE PERSON ARE ONE PERSON.
     *
     * The operator named this case exactly: "one person could make multiple ₦200
     * donations and artificially inflate the reach." Under a row count they buy ten units
     * of reach here. They buy one.
     */
    public function test_many_small_orders_from_one_buyer_are_one_supporter(): void
    {
        for ($i = 1; $i <= 10; $i++) $this->order(1000 + $i, 'whale@example.test', 1);

        $this->assertSame(1, VoterReach::forNominee(700),
            'ten orders from one address bought more than one unit of reach — the count '
            . 'is reading transactions again');
    }

    /**
     * AND BUYING AFTER VOTING IS STILL ONE PERSON.
     *
     * This is why the buyer's address is hashed through {@see VoteService::voterHash()}
     * with no prefix: it has to COLLIDE with their organic vote by construction. A
     * prefixed key would be correct-looking, would pass the test above, and would still
     * let one supporter count twice by voting once and then paying once.
     */
    public function test_a_supporter_who_votes_and_then_buys_counts_once(): void
    {
        $this->organic('same@example.test');
        $this->order(2001, 'same@example.test', 500);

        $this->assertSame(1, VoterReach::forNominee(700),
            'one supporter counted twice for doing both things a supporter can do');
    }

    /** Different buyers are different people, or the count measures nothing at all. */
    public function test_separate_buyers_are_separate_people(): void
    {
        $this->order(3001, 'a@example.test', 100);
        $this->order(3002, 'b@example.test', 100);
        $this->organic('c@example.test');

        $this->assertSame(3, VoterReach::forNominee(700));
    }

    // ══ what is not a person ═════════════════════════════════════════════════

    /**
     * A GRANT IS NOBODY, AND ITS VOTES STILL COUNT.
     *
     * A bonus vote is awarded by an operator. Nobody chose to cast it, so it buys no
     * reach — while its weight still lands in the thirty per cent, because it is a real
     * vote on a real tally and the receipts say so. Both halves of that are asserted:
     * dropping it from the tally too would be a different unfairness.
     */
    public function test_a_granted_bonus_vote_adds_no_reach(): void
    {
        $this->organic('real@example.test');
        DB::table('gates_votes')->insert([
            'nominee_id' => 700, 'category_id' => self::CAT, 'vote_type' => 'bonus',
            'weight' => 500, 'voter_email_hash' => 'bonus:9:' . bin2hex(random_bytes(6)),
        ]);

        $this->assertSame(1, VoterReach::forNominee(700),
            'an operator grant bought a unit of reach');

        // …and the tally it belongs to is untouched by that.
        $this->assertSame(501, (int) DB::table('gates_votes')
            ->where('nominee_id', 700)->sum('weight'),
            'the grant was dropped from the tally as well, which is not what was decided');
    }

    /** A fraud-flagged row is nobody, wherever it came from. */
    public function test_a_fraud_flagged_row_is_not_a_person(): void
    {
        $this->organic('clean@example.test');
        DB::table('gates_votes')->insert([
            'nominee_id' => 700, 'category_id' => self::CAT, 'vote_type' => 'standard',
            'weight' => 1, 'fraud_flag' => 1,
            'voter_email_hash' => VoteService::voterHash('dirty@example.test'),
        ]);

        $this->assertSame(1, VoterReach::forNominee(700));
    }

    /**
     * A points redemption is its MEMBER, not its transaction.
     *
     * `points:<userId>:<random>` — the suffix exists only to clear the one-vote-per-
     * category unique key, so redeeming twice must not read as two supporters.
     */
    public function test_a_member_redeeming_points_twice_is_one_supporter(): void
    {
        foreach ([1, 2] as $_) {
            DB::table('gates_votes')->insert([
                'nominee_id' => 700, 'category_id' => self::CAT, 'vote_type' => 'standard',
                'weight' => 1, 'voter_email_hash' => 'points:42:' . bin2hex(random_bytes(6)),
            ]);
        }

        $this->assertSame(1, VoterReach::forNominee(700));
    }

    /**
     * An order whose donation row has gone is ONE person, never one per row.
     *
     * The failure direction is the whole point: an unresolvable order must fall back to
     * something that cannot inflate reach. Two rows against one lost order are one unit.
     */
    public function test_an_unresolvable_order_cannot_inflate_reach(): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => 700, 'category_id' => self::CAT, 'vote_type' => 'paid',
            'weight' => 50, 'donation_id' => 8888,
            'voter_email_hash' => 'paidvote:8888:' . bin2hex(random_bytes(6)),
        ]);
        DB::table('gates_votes')->insert([
            'nominee_id' => 700, 'category_id' => self::CAT, 'vote_type' => 'paid',
            'weight' => 50, 'donation_id' => 8888,
            'voter_email_hash' => 'paidvote:8888:' . bin2hex(random_bytes(6)),
        ]);

        $this->assertSame(1, VoterReach::forNominee(700));
    }

    // ══ the arithmetic it feeds ══════════════════════════════════════════════

    /**
     * THE OPERATOR'S OWN WORKED EXAMPLE, TO THE DIGIT.
     *
     * A: 1,000 people, 2,000 votes.  B: 2 people, 2,000 votes.  C: 600 / 1,200.
     * If this drifts, the rule that was agreed is not the rule being applied.
     */
    public function test_the_worked_example_comes_out_as_specified(): void
    {
        $at450 = static fn (int $people, int $votes): float => round(
            CpiService::communityPart($votes, 2000, null, null,
                CpiService::BASIS_REACH, $people, 1000) * 450, 2);

        $this->assertSame(450.0,   $at450(1000, 2000), 'A is not on a full community half');
        $this->assertSame(135.63,  $at450(2,    2000), 'B bought more than the tally term');
        $this->assertSame(270.0,   $at450(600,  1200), 'C is not 189 + 81');
    }

    /** Missing nominees come back as zero rather than absent — a caller indexes by id. */
    public function test_a_nominee_with_no_votes_is_zero_and_not_missing(): void
    {
        $r = VoterReach::forNominees([700, 999]);

        $this->assertSame(0, $r[700]);
        $this->assertArrayHasKey(999, $r);
        $this->assertSame(0, $r[999]);
    }
}
