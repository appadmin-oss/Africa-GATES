<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\PhaseAuditService;

/**
 * The audit exists to make three decisions answerable with numbers instead of
 * guesses: what is already wrong, who is owed money, and what is uncrowned.
 *
 * Its findings will be used to reverse real payments, so the properties that
 * matter most are the ones that stop it crying wolf. A false "refund owed" costs
 * real money; a false "vote cast late" would have an operator voiding legitimate
 * ballots. So these tests push hardest on the boundaries: the exact closing
 * instant, cycles with no declared deadline, and already-settled refunds.
 */
class PhaseAuditTest extends TestCase
{
    private function seedCycle(int $id, string $status, array $dates = []): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 1, 'slug' => 'p1', 'title' => 'Programme One']);
        DB::table('gates_award_cycles')->insert(array_merge(
            ['id' => $id, 'programme_id' => 1, 'year' => 2024, 'status' => $status],
            $dates
        ));
    }

    private function seedCategory(int $id, int $cycleId): void
    {
        DB::table('gates_award_categories')->insert([
            'id' => $id, 'cycle_id' => $cycleId, 'slug' => 'cat' . $id, 'title' => 'Category ' . $id,
        ]);
    }

    private function seedVote(int $categoryId, string $at, string $type = 'standard', int $weight = 1, ?int $donationId = null): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => $categoryId,
            'voter_email_hash' => 'h' . bin2hex(random_bytes(6)),
            'vote_type' => $type, 'weight' => $weight, 'donation_id' => $donationId, 'voted_at' => $at,
        ]);
    }

    // ── Windows ─────────────────────────────────────────────────────────────

    public function test_a_vote_at_the_exact_closing_instant_is_late(): void
    {
        // Half-open [open, close), matching CyclePolicy. If the audit used <=
        // here it would disagree with the guard that now refuses these writes,
        // and the two would report different populations forever.
        $this->seedCycle(1, 'results', ['voting_open' => '2024-01-01 00:00:00', 'voting_close' => '2024-02-01 00:00:00']);
        $this->seedCategory(10, 1);
        $this->seedVote(10, '2024-02-01 00:00:00');

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertCount(1, $r['votes_after_close']);
        $this->assertSame(1, $r['totals']['late_votes']);
    }

    public function test_a_vote_one_second_before_close_is_not_reported(): void
    {
        $this->seedCycle(1, 'results', ['voting_open' => '2024-01-01 00:00:00', 'voting_close' => '2024-02-01 00:00:00']);
        $this->seedCategory(10, 1);
        $this->seedVote(10, '2024-01-31 23:59:59');

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame([], $r['votes_after_close'], 'the last legitimate second must stay legitimate');
        $this->assertSame(0, $r['totals']['late_votes']);
    }

    public function test_paid_weight_is_reported_beside_the_row_count(): void
    {
        // One paid row can carry a weight of hundreds. The count says how many
        // offences; the weight says how far the standings actually moved.
        $this->seedCycle(1, 'results', ['voting_close' => '2024-02-01 00:00:00']);
        $this->seedCategory(10, 1);
        $this->seedVote(10, '2024-03-01 09:00:00', 'standard', 1);
        $this->seedVote(10, '2024-03-02 09:00:00', 'paid', 250);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $byType = [];
        foreach ($r['votes_after_close'] as $row) $byType[$row['vote_type']] = $row;
        $this->assertSame(1, $byType['standard']['weight']);
        $this->assertSame(250, $byType['paid']['weight']);
        $this->assertSame('2024-03-01 09:00:00', $byType['standard']['first_at']);
    }

    public function test_a_cycle_with_no_closing_date_is_unjudgeable_not_clean(): void
    {
        // Inventing a deadline in an audit would manufacture offences no operator
        // ever announced — so these are reported as uncheckable instead.
        $this->seedCycle(1, 'voting', ['voting_open' => '2024-01-01 00:00:00']);
        $this->seedCategory(10, 1);
        $this->seedVote(10, '2030-01-01 00:00:00');

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame([], $r['votes_after_close'], 'no declared close, so no attributable offence');
        $this->assertCount(1, $r['undated']);
        $this->assertSame(['nominations_close', 'voting_close'], $r['undated'][0]['missing']);
        $this->assertSame(1, $r['totals']['undated_cycles'], 'but it must not read as a clean audit');
    }

    public function test_votes_before_the_window_opened_are_reported_separately(): void
    {
        // A different bug from a late vote: a window edited backwards after
        // voting had already begun.
        $this->seedCycle(1, 'voting', ['voting_open' => '2024-05-01 00:00:00', 'voting_close' => '2024-09-01 00:00:00']);
        $this->seedCategory(10, 1);
        $this->seedVote(10, '2024-04-01 00:00:00');

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame([], $r['votes_after_close']);
        $this->assertCount(1, $r['votes_before_open']);
        $this->assertSame(1, $r['totals']['early_votes']);
    }

    public function test_late_nominations_are_broken_down_by_status(): void
    {
        // 40 pending rows and 40 approved finalists demand different decisions.
        $this->seedCycle(1, 'voting', ['nominations_close' => '2024-02-01 00:00:00', 'voting_close' => '2024-09-01 00:00:00']);
        foreach ([['pending', '2024-03-01 00:00:00'], ['approved', '2024-03-02 00:00:00'], ['approved', '2024-03-03 00:00:00']] as $i => [$st, $at]) {
            DB::table('gates_nominations')->insert([
                'cycle_id' => 1, 'nominee_name' => 'N' . $i, 'nominator_name' => 'X',
                'nominator_email' => 'x@example.com', 'status' => $st, 'created_at' => $at,
            ]);
        }

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $byStatus = [];
        foreach ($r['nominations_outside_window'] as $row) $byStatus[$row['status']] = $row['nominations'];
        ksort($byStatus);
        $this->assertSame(['approved' => 2, 'pending' => 1], $byStatus);
        $this->assertSame(3, $r['totals']['late_nominations']);
    }

    // ── Drift ───────────────────────────────────────────────────────────────

    public function test_drift_distinguishes_an_engine_behind_from_an_operator_ahead(): void
    {
        // Both are divergences; only one is a bug. Conflating them would have an
        // operator "fixing" their own deliberate manual advance.
        $this->seedCycle(1, 'nominations', [
            'nominations_open' => '2024-01-01 00:00:00', 'nominations_close' => '2024-02-01 00:00:00',
            'voting_open' => '2024-03-01 00:00:00', 'voting_close' => '2024-04-01 00:00:00',
        ]);
        $this->seedCycle(2, 'results', [
            'nominations_open' => '2024-01-01 00:00:00', 'nominations_close' => '2024-02-01 00:00:00',
            'voting_open' => '2024-03-01 00:00:00', 'voting_close' => '2099-01-01 00:00:00',
        ]);

        $r = PhaseAuditService::run(Carbon::parse('2024-05-01 00:00:00'));
        $dir = [];
        foreach ($r['cycles'] as $c) $dir[$c['cycle_id']] = $c['direction'];

        $this->assertSame('behind', $dir[1], 'stored still says nominations long after voting ended');
        $this->assertSame('ahead', $dir[2], 'an operator moved this one on by hand — legitimate');
        $this->assertSame(2, $r['totals']['drifted_cycles']);
        $this->assertSame('Programme One', $r['cycles'][0]['programme']);
    }

    // ── Money ───────────────────────────────────────────────────────────────

    private function seedOrder(int $id, int $nomineeId, int $votesUsed, int $naira = 5000, ?string $refunded = null): void
    {
        DB::table('gates_donations')->insert([
            'id' => $id, 'donor_name' => 'Buyer', 'donor_email' => 'b@example.com',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 50,
            'votes_used' => $votesUsed, 'intent_nominee_id' => $nomineeId,
            'payment_ref' => 'ref-' . $id, 'status' => 'confirmed',
            'refunded_at' => $refunded, 'created_at' => '2024-01-15 00:00:00',
        ]);
    }

    public function test_an_order_that_never_minted_into_a_closed_cycle_is_a_refund(): void
    {
        // mint()'s phase gate refuses rather than minting into a closed cycle and
        // leaves votes_used = 0 — this is the query that turns that signal into a
        // naira figure someone can act on.
        $this->seedCycle(1, 'results', ['voting_open' => '2024-01-01 00:00:00', 'voting_close' => '2024-02-01 00:00:00']);
        $this->seedCategory(10, 1);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada', 'status' => 'approved']);
        $this->seedOrder(500, 1, 0, 7500);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame(1, $r['paid_unminted']['orders']);
        $this->assertSame(7500, $r['paid_unminted']['naira']);
        $this->assertSame('refund', $r['paid_unminted']['rows'][0]['remedy']);
        $this->assertSame('Ada', $r['paid_unminted']['rows'][0]['nominee']);
    }

    public function test_an_order_whose_window_is_open_again_is_re_mintable_not_refundable(): void
    {
        // The remedies are not interchangeable: refunding an order that could
        // still be delivered costs the platform the sale for no reason.
        $this->seedCycle(1, 'voting', ['voting_open' => '2024-01-01 00:00:00', 'voting_close' => '2024-12-01 00:00:00']);
        $this->seedCategory(10, 1);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada', 'status' => 'approved']);
        $this->seedOrder(500, 1, 0);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame('re-mint', $r['paid_unminted']['rows'][0]['remedy']);
    }

    public function test_the_remedy_is_decided_by_the_reports_clock_not_the_wall_clock(): void
    {
        // The first version of this asked BallotGuard with no clock, so the
        // refund/re-mint verdict silently followed real time while every other
        // section followed `generated_at`. A report is not reproducible if one of
        // its sections answers a different question each time it runs — and this
        // is the section that decides whether someone is owed money.
        $this->seedCycle(1, 'voting', ['voting_open' => '2024-01-01 00:00:00', 'voting_close' => '2024-12-01 00:00:00']);
        $this->seedCategory(10, 1);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada', 'status' => 'approved']);
        $this->seedOrder(500, 1, 0);

        $inside  = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));
        $outside = PhaseAuditService::run(Carbon::parse('2025-06-01 00:00:00'));

        $this->assertSame('re-mint', $inside['paid_unminted']['rows'][0]['remedy']);
        $this->assertSame('refund', $outside['paid_unminted']['rows'][0]['remedy']);
    }

    public function test_an_order_pointing_at_no_live_nominee_needs_a_human(): void
    {
        $this->seedOrder(500, 999, 0);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame('investigate', $r['paid_unminted']['rows'][0]['remedy'],
            'a missing target must never be silently classed as refundable or deliverable');
    }

    public function test_settled_and_delivered_orders_are_not_reported_as_owing(): void
    {
        $this->seedCycle(1, 'results', ['voting_close' => '2024-02-01 00:00:00']);
        $this->seedCategory(10, 1);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada', 'status' => 'approved']);
        $this->seedOrder(500, 1, 50);                            // delivered
        $this->seedOrder(501, 1, 0, 5000, '2024-03-01 00:00:00'); // already refunded
        DB::table('gates_donations')->insert([                   // never paid
            'id' => 502, 'donor_name' => 'B', 'donor_email' => 'b@example.com', 'amount_naira' => 5000,
            'tier' => 'paid-vote', 'bonus_votes' => 50, 'votes_used' => 0, 'intent_nominee_id' => 1,
            'status' => 'pending', 'created_at' => '2024-01-15 00:00:00',
        ]);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame(0, $r['paid_unminted']['orders']);
        $this->assertSame(0, $r['paid_unminted']['naira'], 'a false refund figure costs real money');
    }

    public function test_a_paid_vote_that_minted_after_close_is_sized_in_weight_and_naira(): void
    {
        // The mirror image of the unminted case, and the worse one: money kept
        // AND a closed public tally moved.
        $this->seedCycle(1, 'results', ['voting_open' => '2024-01-01 00:00:00', 'voting_close' => '2024-02-01 00:00:00']);
        $this->seedCategory(10, 1);
        $this->seedOrder(500, 1, 50, 9000);
        $this->seedVote(10, '2024-02-11 00:00:00', 'paid', 50, 500);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame(1, $r['paid_minted_late']['orders']);
        $this->assertSame(50, $r['paid_minted_late']['weight']);
        $this->assertSame(9000, $r['paid_minted_late']['naira']);
        $this->assertSame(10, $r['paid_minted_late']['rows'][0]['days_late']);
        $this->assertFalse($r['paid_minted_late']['rows'][0]['refunded']);
    }

    // ── The uncrowned ───────────────────────────────────────────────────────

    public function test_a_finished_category_with_eligible_nominees_and_no_winner_is_a_finding(): void
    {
        $this->seedCycle(1, 'results', ['voting_close' => '2024-02-01 00:00:00', 'results_date' => '2024-02-05 00:00:00']);
        $this->seedCategory(10, 1);
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 10, 'name' => 'Ada', 'status' => 'approved'],
            ['id' => 2, 'category_id' => 10, 'name' => 'Bola', 'status' => 'approved'],
        ]);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertCount(1, $r['results_backlog']);
        $this->assertSame(2, $r['results_backlog'][0]['eligible']);
        $this->assertSame('Category 10', $r['results_backlog'][0]['category']);
        $this->assertSame(117, $r['results_backlog'][0]['days_late']);
    }

    public function test_an_empty_category_is_not_a_backlog_and_neither_is_a_crowned_one(): void
    {
        // Nobody was ever going to win an empty category, and a crowned one is
        // already done. Reporting either would bury the real gaps in noise.
        $this->seedCycle(1, 'results', ['voting_close' => '2024-02-01 00:00:00']);
        $this->seedCategory(10, 1);   // empty
        $this->seedCategory(11, 1);   // crowned
        $this->seedCategory(12, 1);   // pending only — nothing approvable yet
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 11, 'name' => 'Ada', 'status' => 'winner'],
            ['id' => 2, 'category_id' => 11, 'name' => 'Bola', 'status' => 'runner_up'],
            ['id' => 3, 'category_id' => 12, 'name' => 'Chidi', 'status' => 'pending'],
        ]);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame([], $r['results_backlog']);
        $this->assertSame(0, $r['totals']['uncrowned']);
    }

    public function test_a_merge_tombstone_does_not_count_as_an_eligible_nominee(): void
    {
        // A merged-away duplicate is not a candidate. Counting it would report a
        // category as awaiting a winner when its only entrant no longer exists.
        $this->seedCycle(1, 'results', ['voting_close' => '2024-02-01 00:00:00']);
        $this->seedCategory(10, 1);
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 10, 'name' => 'Dup', 'status' => 'approved',
            'merged_into' => 99, 'merged_at' => '2024-01-20 00:00:00',
        ]);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame([], $r['results_backlog']);
    }

    public function test_an_unfinished_cycle_is_never_in_the_backlog(): void
    {
        $this->seedCycle(1, 'voting', ['voting_open' => '2024-01-01 00:00:00', 'voting_close' => '2099-01-01 00:00:00']);
        $this->seedCategory(10, 1);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada', 'status' => 'approved']);

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame([], $r['results_backlog'], 'voting is still open — there is nothing to crown yet');
    }

    // ── Trust ───────────────────────────────────────────────────────────────

    public function test_the_clock_section_reports_the_driver_and_a_comparable_now(): void
    {
        // Every finding is a timestamp comparison, so if this section cannot be
        // produced the rest of the report is not interpretable.
        $r = PhaseAuditService::run();

        $this->assertSame('sqlite', $r['clock']['driver']);
        $this->assertNotNull($r['clock']['db_now']);
        $this->assertIsInt($r['clock']['skew_seconds']);
        $this->assertFalse($r['clock']['suspicious'], 'SQLite CURRENT_TIMESTAMP is UTC and the test clock is UTC');
    }

    public function test_an_empty_platform_reports_clean(): void
    {
        $r = PhaseAuditService::run();

        $this->assertTrue(PhaseAuditService::isClean($r));
        $this->assertSame(0, array_sum($r['totals']));
    }

    public function test_one_finding_anywhere_is_enough_to_stop_reading_clean(): void
    {
        // --strict is meant to be usable as a deploy gate, so isClean() must be
        // driven by the totals rather than by any one section a caller remembers
        // to check.
        $this->seedCycle(1, 'results', ['voting_close' => '2024-02-01 00:00:00']);
        $this->seedCategory(10, 1);
        $this->seedVote(10, '2024-03-01 00:00:00');

        $this->assertFalse(PhaseAuditService::isClean(PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'))));
    }

    public function test_the_audit_writes_nothing(): void
    {
        // It will be run against production by an operator sizing a refund bill.
        // A report that mutates the thing it reports on is not a report.
        $this->seedCycle(1, 'nominations', [
            'nominations_close' => '2024-02-01 00:00:00', 'voting_close' => '2024-04-01 00:00:00',
        ]);
        $this->seedCategory(10, 1);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada', 'status' => 'approved']);
        $this->seedOrder(500, 1, 0);
        $this->seedVote(10, '2024-05-01 00:00:00', 'paid', 50, 500);

        $before = [];
        foreach (['gates_award_cycles', 'gates_votes', 'gates_donations', 'gates_nominees',
                  'gates_cycle_transitions', 'gates_phase_drift'] as $t) {
            $before[$t] = DB::table($t)->count();
        }
        $status = (string) DB::table('gates_award_cycles')->where('id', 1)->value('status');

        PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        foreach ($before as $t => $n) {
            $this->assertSame($n, DB::table($t)->count(), "{$t} row count changed — the audit wrote something");
        }
        $this->assertSame($status, (string) DB::table('gates_award_cycles')->where('id', 1)->value('status'),
            'not even the materialised status may be repaired as a side effect');
    }

    public function test_missing_tables_degrade_to_empty_sections_rather_than_crashing(): void
    {
        // An audit that dies on a partially-migrated database tells the operator
        // nothing at exactly the moment they most need a partial answer.
        DB::statement('DROP TABLE IF EXISTS gates_votes');
        DB::statement('DROP TABLE IF EXISTS gates_donations');

        $r = PhaseAuditService::run(Carbon::parse('2024-06-01 00:00:00'));

        $this->assertSame([], $r['votes_after_close']);
        $this->assertSame(0, $r['paid_unminted']['orders']);
        $this->assertSame(0, $r['paid_minted_late']['orders']);
        $this->assertArrayHasKey('driver', $r['clock']);
    }
}
