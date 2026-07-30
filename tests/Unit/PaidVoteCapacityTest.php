<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaidVoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "Can the site take an order of more than 1,000 votes?"
 *
 * The answer was no, and the reasons were in three different places, none of them the
 * business rule anyone thought they were configuring:
 *
 *   1. `MAX_QTY = 1000`, a bare constant, CLAMPED in two places — so a supporter who
 *      asked for 5,000 was charged for 1,000 and told nothing.
 *   2. `gates_votes.weight` was `SMALLINT UNSIGNED`. A paid order mints ONE row with
 *      `weight = quantity`, so 65,535 was the real ceiling. Under strict mode the mint
 *      aborts (paid, no votes); on a host that relaxes sql_mode MySQL CLAMPS and reports
 *      success — 65,535 votes credited for an order of 100,000, silently.
 *   3. `amount_naira` is `INT UNSIGNED`. Quantity × an admin-set rate can exceed it long
 *      before the quantity cap binds, so two ceilings that each look sufficient alone
 *      multiply into one that is not.
 *
 * These tests pin all three, and pin that a refused order is REFUSED rather than quietly
 * shrunk — because the failure that costs a client money is the silent one.
 */
class PaidVoteCapacityTest extends TestCase
{
    private function setting(string $key, string $value): void
    {
        DB::table('gates_settings')->insert(['key_name' => $key, 'value' => $value]);
    }

    // ── The quantity cap is a setting, not a constant ────────────────────────

    public function test_the_default_cap_is_unchanged_for_an_unconfigured_site(): void
    {
        // No regression for anyone who has not asked for more.
        $this->assertSame(1000, PaidVoteService::maxQty());
        $this->assertSame(PaidVoteService::DEFAULT_MAX_QTY, PaidVoteService::maxQty());
    }

    public function test_an_admin_can_raise_the_cap_past_a_thousand(): void
    {
        $this->setting('vote_max_qty', '25000');
        $this->assertSame(25000, PaidVoteService::maxQty());
        $this->assertSame(25000, PaidVoteService::maxQtyForOrder(),
            'at the default 100 naira a vote, 25,000 votes is 2.5m naira — well inside the cash ceiling');
    }

    public function test_a_blank_or_zero_setting_falls_back_rather_than_capping_at_nothing(): void
    {
        // Clearing the field must not cap every order at zero votes, which would read as
        // paid voting being broken rather than as a setting being empty.
        $this->setting('vote_max_qty', '0');
        $this->assertSame(PaidVoteService::DEFAULT_MAX_QTY, PaidVoteService::maxQty());
    }

    public function test_an_absurd_setting_is_clamped_to_the_hard_ceiling(): void
    {
        // A settings row is not trusted just because an admin typed it: this number lands
        // in a public vote tally, and a stray zero is one keystroke away.
        $this->setting('vote_max_qty', '999999999999');
        $this->assertSame(PaidVoteService::HARD_MAX_QTY, PaidVoteService::maxQty());
    }

    // ── The two ceilings, and the fact that they multiply ────────────────────

    /**
     * The hard ceiling must fit the COLUMN. This is the assertion that would have caught
     * the SMALLINT problem before a client ever hit it.
     */
    public function test_the_hard_ceiling_fits_the_widened_weight_column(): void
    {
        // gates_votes.weight is INT UNSIGNED after 2026_07_30_vote_weight_widen.php.
        $this->assertLessThanOrEqual(4294967295, PaidVoteService::HARD_MAX_QTY);
        // And it is deliberately far BELOW what the column allows — this is a blast
        // radius, not a storage limit: it is what one mistyped quantity can add to a
        // public tally in a single transaction.
        $this->assertLessThan(4294967295, PaidVoteService::HARD_MAX_QTY);
    }

    /**
     * The cash ceiling binds before the quantity one at a high per-vote rate — the
     * interaction that would have overflowed amount_naira.
     */
    public function test_the_cash_ceiling_reduces_the_quantity_at_a_high_rate(): void
    {
        $this->setting('vote_max_qty', (string) PaidVoteService::HARD_MAX_QTY);
        $this->setting('vote_price_naira', '1000');
        // 1000 naira a vote, so a 10,000,000-vote order would be 10 BILLION naira — over
        // twice what amount_naira (INT UNSIGNED) can hold.
        $max = PaidVoteService::maxQtyForOrder();

        $this->assertLessThan(PaidVoteService::HARD_MAX_QTY, $max,
            'the cash ceiling must bite before the quantity ceiling at this rate');
        $this->assertLessThanOrEqual(PaidVoteService::MAX_ORDER_NAIRA, PaidVoteService::price($max),
            'the priced maximum order must fit the naira ceiling');
        $this->assertLessThan(4294967295, PaidVoteService::price($max),
            'and therefore fit amount_naira, which is what the old code could overflow');
    }

    public function test_the_priced_maximum_always_fits_the_amount_column(): void
    {
        // Swept across plausible admin configurations rather than asserted at one point,
        // because the defect was an INTERACTION between two settings.
        foreach ([10, 100, 200, 1000, 5000] as $rate) {
            foreach ([1, 5, 10, 50] as $per1000) {
                DB::table('gates_settings')->truncate();
                $this->setting('vote_max_qty', (string) PaidVoteService::HARD_MAX_QTY);
                $this->setting('vote_price_naira', (string) $rate);
                $this->setting('vote_votes_per_1000', (string) $per1000);

                $max   = PaidVoteService::maxQtyForOrder();
                $price = PaidVoteService::price($max);

                $this->assertGreaterThan(0, $max, "rate {$rate}/per1000 {$per1000}: max must be positive");
                $this->assertLessThanOrEqual(PaidVoteService::MAX_ORDER_NAIRA, $price,
                    "rate {$rate}/per1000 {$per1000}: priced max is {$price} naira");
            }
        }
    }

    // ── Bulk pricing stays correct at scale ─────────────────────────────────

    public function test_a_large_order_gets_the_bundle_rate_not_the_per_vote_rate(): void
    {
        $this->setting('vote_max_qty', '100000');
        $this->setting('vote_price_naira', '200');
        $this->setting('vote_votes_per_1000', '6');   // the live config from the ballot

        // 60,000 votes = 10,000 full bundles = 10,000,000 naira, which is far cheaper than
        // 60,000 x 200. The buyer must get the cheaper of the two rules at every scale.
        $this->assertSame(10_000_000, PaidVoteService::price(60000));
        $this->assertLessThan(60000 * 200, PaidVoteService::price(60000));
    }

    public function test_price_is_monotonic_so_more_votes_never_cost_less(): void
    {
        $this->setting('vote_max_qty', '100000');
        $this->setting('vote_price_naira', '200');
        $this->setting('vote_votes_per_1000', '6');

        $prev = 0;
        foreach ([1, 5, 6, 7, 12, 100, 999, 1000, 5000, 60000] as $qty) {
            $p = PaidVoteService::price($qty);
            $this->assertGreaterThanOrEqual($prev, $p, "price fell going up to {$qty} votes");
            $prev = $p;
        }
    }

    // ── The database can actually hold it ───────────────────────────────────

    /**
     * A single weighted row of 100,000 stores and reads back intact.
     *
     * The point of the widening. SQLite has always stored a 64-bit INTEGER so this passes
     * either way here — the MySQL parity run (TEST_DB_DRIVER=mysql) is where it has teeth,
     * because that is the schema that had SMALLINT.
     */
    public function test_a_six_figure_weight_round_trips(): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id'       => 9001,
            'category_id'      => 9002,
            'voter_email_hash' => 'paidvote:capacity-test',
            'voter_name'       => 'Bulk Buyer',
            'vote_type'        => 'paid',
            'weight'           => 100000,
            'voted_at'         => '2026-07-30 10:00:00',
        ]);

        $this->assertSame(100000, (int) DB::table('gates_votes')
            ->where('voter_email_hash', 'paidvote:capacity-test')->value('weight'),
            'a 100,000-weight row must not be truncated — SMALLINT UNSIGNED would have clamped it to 65,535');
    }

    public function test_the_canonical_schema_declares_weight_as_int(): void
    {
        // The SQLite harness cannot catch a SMALLINT in the MySQL schema, so the file is
        // asserted directly. This is the column the whole question turned on.
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');

        $this->assertMatchesRegularExpression('~weight INT UNSIGNED NOT NULL DEFAULT 1~', $schema);
        $this->assertDoesNotMatchRegularExpression('~weight SMALLINT~', $schema,
            'SMALLINT caps a single paid order at 65,535 votes');
    }

    // ── An over-large order is refused, not silently shrunk ─────────────────

    /**
     * The behaviour change a bulk client would otherwise discover from their receipt.
     *
     * `min(MAX_QTY, $qty)` in the controller meant an order for 5,000 became an order for
     * 1,000 with no message anywhere. The controller now compares and bails with the real
     * maximum instead of clamping.
     */
    public function test_the_checkout_refuses_rather_than_clamps(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/PaidVoteController.php');
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $src);

        $this->assertDoesNotMatchRegularExpression('~min\(\s*PaidVoteService::MAX_QTY~', $code,
            'clamping the quantity down takes the money and delivers fewer votes, silently');
        $this->assertMatchesRegularExpression('~\$qty > \$maxQty~', $code, 'it must compare and refuse');
        $this->assertStringContainsString("'toomany'", $code, 'and bail with a reason the page renders');
    }

    public function test_the_refusal_reason_is_rendered_to_the_buyer(): void
    {
        // Eight paid-vote reasons were once emitted and none rendered. A new one must not
        // reintroduce that.
        $vote = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/VoteController.php');
        $this->assertStringContainsString("'toomany'", $vote, 'PAID_REASONS must carry the new reason');
    }

    /**
     * `mint()` refuses a quantity the column cannot hold, rather than letting the INSERT
     * decide. Reachable from the gateway webhook long after checkout, by which time the
     * admin cap may have changed — so the storage guard cannot live only in the controller.
     */
    public function test_mint_refuses_a_quantity_beyond_the_hard_ceiling(): void
    {
        $id = DB::table('gates_donations')->insertGetId([
            'donor_name'        => 'Bulk Buyer',
            'donor_email'       => 'bulk@example.test',
            'amount_naira'      => 1000,
            'tier'              => 'paid-vote',
            'bonus_votes'       => PaidVoteService::HARD_MAX_QTY + 1,
            'votes_used'        => 0,
            'intent_nominee_id' => 9100,
            'payment_ref'       => 'AFG-PVOTE-capacity',
            'status'            => 'confirmed',
            'created_at'        => '2026-07-30 10:00:00',
        ]);
        DB::table('gates_nominees')->insert([
            'id' => 9100, 'category_id' => 9101, 'name' => 'Nominee', 'status' => 'approved',
        ]);

        $r = PaidVoteService::mint((int) $id);

        $this->assertFalse($r['ok']);
        $this->assertSame('QTY_TOO_LARGE', $r['code'] ?? '');
        // votes_used stays 0 — the existing, queryable "paid but never minted, refund
        // owed" signal, so ops sees this the same way it sees a closed-cycle refusal.
        $this->assertSame(0, (int) DB::table('gates_donations')->where('id', $id)->value('votes_used'));
        $this->assertSame(0, DB::table('gates_votes')->where('donation_id', $id)->count());
    }

    // ── One order is one row, whatever its size ─────────────────────────────

    /**
     * The answer to "can the site handle it?", and it is about cost, not just limits.
     *
     * A paid order of any size mints exactly ONE `gates_votes` row with `weight = qty` and
     * does ONE `increment` on the nominee. So a 50,000-vote order is the same amount of
     * database work as a 1-vote order — which is why the honest constraint on bulk buying
     * is the payment gateway's per-transaction limit, not this platform's throughput.
     */
    public function test_an_order_of_any_size_is_a_single_vote_row(): void
    {
        $this->setting('vote_max_qty', '100000');

        DB::table('gates_award_programmes')->insert(['id' => 9200, 'slug' => 'p', 'title' => 'P', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert([
            'id' => 9201, 'programme_id' => 9200, 'year' => 2026, 'status' => 'voting',
            'voting_open' => '2026-07-01 00:00:00', 'voting_close' => '2026-12-31 00:00:00',
        ]);
        DB::table('gates_award_categories')->insert(['id' => 9202, 'cycle_id' => 9201, 'slug' => 'c', 'title' => 'C']);
        DB::table('gates_nominees')->insert([
            'id' => 9203, 'category_id' => 9202, 'name' => 'Bulk Target', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);

        $id = DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Bulk Buyer', 'donor_email' => 'bulk@example.test',
            'amount_naira' => 10_000_000, 'tier' => 'paid-vote', 'bonus_votes' => 50000,
            'votes_used' => 0, 'intent_nominee_id' => 9203,
            'payment_ref' => 'AFG-PVOTE-bulk', 'status' => 'confirmed',
            'created_at' => '2026-07-30 10:00:00',
        ]);

        $r = PaidVoteService::mint((int) $id);
        $this->assertTrue($r['ok'] ?? false, $r['message'] ?? 'mint failed');
        $this->assertSame(50000, $r['minted'] ?? 0);

        $this->assertSame(1, DB::table('gates_votes')->where('donation_id', $id)->count(),
            'one order is one row — 50,000 individual rows would be the scaling problem this design avoids');
        $this->assertSame(50000, (int) DB::table('gates_nominees')->where('id', 9203)->value('vote_count'));
        // The integrity model is untouched at scale: money moves the public tally only.
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', 9203)->value('organic_vote_count'),
            '50,000 paid votes must not move the CPI community signal by one');
    }
}
