<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaidVoteService;
use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PAID VOTING MUST WORK ON A DATABASE NOBODY HAS MIGRATED YET.
 *
 * ── The outage this file exists for ──────────────────────────────────────────
 *
 * `show_name` was added to the paid-vote flow by a migration, and then written
 * unconditionally in two places. On any deployment whose migrations had not been
 * applied — which on shared cPanel hosting is the normal state between a `git pull`
 * and an operator remembering to run `db:migrate`, and the admin banner has counted
 * those pending steps in the dozens — both writes threw:
 *
 *   1. the checkout INSERT into `gates_donations`, inside a try that converted the
 *      throw into a generic error chip. Nobody could start a payment, and nothing on
 *      the page or in the UI said why.
 *   2. `mint()`'s INSERT into `gates_votes`, inside the claim TRANSACTION — after
 *      `votes_used` had been set. Money taken, order marked minted, no vote created.
 *
 * The read side had been written defensively (`$don->show_name ?? 0`, null → private).
 * The write side had not. That asymmetry is the whole bug: a nullable READ degrades on
 * its own, a WRITE never does.
 *
 * ── Why the rest of the suite missed it ──────────────────────────────────────
 *
 * Every other test runs against `sqlite-schema.sql`, which the same commit updated to
 * include the column — so the tests always saw a fully-migrated database and the 181
 * payment tests passed while production could not take a naira. So these tests DROP
 * the column first. That is the only configuration that reproduces the failure, and
 * without it this file would be decorative.
 */
final class PaidVoteWithoutMigrationTest extends TestCase
{
    private int $nomineeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        OptionalColumn::forget();

        DB::table('gates_votes')->delete();
        DB::table('gates_donations')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 910, 'title' => 'P', 'slug' => 'p-910']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 910, 'programme_id' => 910, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 910, 'cycle_id' => 910, 'title' => 'Cat', 'slug' => 'cat-910']);
        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 910, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        OptionalColumn::forget();
        parent::tearDown();
    }

    /**
     * Reproduce the unmigrated deployment: drop `show_name` from a table.
     *
     * SQLite has supported ALTER TABLE … DROP COLUMN since 3.35. If this build cannot
     * do it the test SKIPS rather than silently passing against the migrated schema —
     * a green tick that proved nothing is exactly how this bug shipped.
     */
    private function dropShowName(string $table): void
    {
        try {
            DB::connection()->getPdo()->exec("ALTER TABLE {$table} DROP COLUMN show_name");
        } catch (\Throwable $e) {
            $this->markTestSkipped("cannot drop a column on this driver: {$e->getMessage()}");
        }
        OptionalColumn::forget();
        $this->assertFalse(DB::schema()->hasColumn($table, 'show_name'),
            'the fixture must actually remove the column, or this test proves nothing');
    }

    // ── 1. Taking the money ──────────────────────────────────────────────────

    /**
     * The checkout INSERT is the exact statement PaidVoteController runs. Driving the
     * controller would mean standing up Turnstile, a rate limiter and a payment
     * gateway; the row is what broke, so the row is what is asserted.
     */
    public function test_a_pending_order_can_still_be_created_without_the_column(): void
    {
        $this->dropShowName('gates_donations');

        $row = OptionalColumn::filter('gates_donations', [
            'donor_name'        => 'Ada Obi',
            'donor_email'       => 'ada@example.test',
            'amount_naira'      => 2500,
            'tier'              => 'paid-vote',
            'bonus_votes'       => 5,
            'votes_used'        => 0,
            'intent_nominee_id' => $this->nomineeId,
            'payment_ref'       => 'AFG-NOMIG-1',
            'status'            => 'pending',
            'show_name'         => 1,
            'created_at'        => Carbon::now()->toDateTimeString(),
        ], ['show_name']);

        $this->assertArrayNotHasKey('show_name', $row, 'the absent column must be filtered out');

        DB::table('gates_donations')->insert($row);   // threw before the fix

        $saved = DB::table('gates_donations')->where('payment_ref', 'AFG-NOMIG-1')->first();
        $this->assertNotNull($saved, 'the buyer must reach the gateway');
        $this->assertSame(2500, (int) $saved->amount_naira);
        $this->assertSame('pending', (string) $saved->status);
    }

    /** With the column present the preference is still recorded — the fix is not a removal. */
    public function test_the_preference_is_still_stored_on_a_migrated_database(): void
    {
        $row = OptionalColumn::filter('gates_donations', [
            'donor_name' => 'Ada Obi', 'donor_email' => 'ada@example.test',
            'amount_naira' => 2500, 'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => 'AFG-MIG-1',
            'status' => 'pending', 'show_name' => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], ['show_name']);

        $this->assertArrayHasKey('show_name', $row);
        DB::table('gates_donations')->insert($row);

        $this->assertSame(1, (int) DB::table('gates_donations')->where('payment_ref', 'AFG-MIG-1')->value('show_name'));
    }

    // ── 2. Delivering the votes ──────────────────────────────────────────────

    /**
     * THE EXPENSIVE ONE. mint() runs after the gateway has confirmed, so a throw here
     * means the supporter has already been charged.
     */
    public function test_a_paid_order_still_mints_its_votes_without_the_column(): void
    {
        $this->dropShowName('gates_votes');

        $ref = 'AFG-NOMIG-2';
        $id = (int) DB::table('gates_donations')->insertGetId(OptionalColumn::filter('gates_donations', [
            'donor_name' => 'Kwame Mensah', 'donor_email' => 'kwame@example.test',
            'amount_naira' => 5000, 'tier' => 'paid-vote', 'bonus_votes' => 10, 'votes_used' => 0,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref, 'status' => 'confirmed',
            'show_name' => 1, 'created_at' => Carbon::now()->toDateTimeString(),
        ], ['show_name']));

        $out = (new PaidVoteService())->mint($id);

        $this->assertTrue($out['ok'], 'a confirmed payment must mint: ' . ($out['message'] ?? ''));
        $this->assertSame(10, (int) $out['minted']);
        $this->assertSame(10, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
        $this->assertSame(10, (int) DB::table('gates_donations')->where('payment_ref', $ref)->value('votes_used'));
        $this->assertSame(1, DB::table('gates_votes')->where('donation_id', '>', 0)->count());
    }

    /**
     * The failure mode was WORSE than a throw: `votes_used` is set first, so a mint
     * that dies afterwards leaves an order that looks delivered and is not. Asserting
     * the two move together is what pins the invariant rather than the symptom.
     */
    public function test_votes_used_and_the_vote_row_never_disagree(): void
    {
        $this->dropShowName('gates_votes');

        $ref = 'AFG-NOMIG-3';
        $id = (int) DB::table('gates_donations')->insertGetId(OptionalColumn::filter('gates_donations', [
            'donor_name' => 'Zainab Bello', 'donor_email' => 'z@example.test',
            'amount_naira' => 2500, 'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref, 'status' => 'confirmed',
            'show_name' => 0, 'created_at' => Carbon::now()->toDateTimeString(),
        ], ['show_name']));

        (new PaidVoteService())->mint($id);

        $used  = (int) DB::table('gates_donations')->where('payment_ref', $ref)->value('votes_used');
        $votes = (int) DB::table('gates_votes')->count();

        $this->assertSame(5, $used);
        $this->assertSame(1, $votes, 'votes_used was claimed, so a vote row must exist');
    }

    /** Minting stays idempotent — the filter must not disturb the claim gate. */
    public function test_minting_twice_still_credits_once(): void
    {
        $this->dropShowName('gates_votes');

        $ref = 'AFG-NOMIG-4';
        $id = (int) DB::table('gates_donations')->insertGetId(OptionalColumn::filter('gates_donations', [
            'donor_name' => 'Thabo Dlamini', 'donor_email' => 't@example.test',
            'amount_naira' => 2500, 'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref, 'status' => 'confirmed',
            'show_name' => 0, 'created_at' => Carbon::now()->toDateTimeString(),
        ], ['show_name']));

        $svc = new PaidVoteService();
        $svc->mint($id);
        $second = $svc->mint($id);

        $this->assertSame(0, (int) $second['minted']);
        $this->assertSame(5, (int) DB::table('gates_nominees')->where('id', $this->nomineeId)->value('vote_count'));
        $this->assertSame(1, DB::table('gates_votes')->count());
    }

    // ── The helper itself ────────────────────────────────────────────────────

    public function test_the_filter_only_touches_columns_it_was_told_are_optional(): void
    {
        $this->dropShowName('gates_donations');

        $row = OptionalColumn::filter('gates_donations',
            ['donor_email' => 'a@b.test', 'show_name' => 1, 'nonexistent_column' => 'x'],
            ['show_name']);

        $this->assertArrayNotHasKey('show_name', $row);
        // A column that was NOT declared optional stays, so a typo in a required field
        // still fails loudly instead of being silently swallowed.
        $this->assertArrayHasKey('nonexistent_column', $row);
        $this->assertArrayHasKey('donor_email', $row);
    }

    public function test_an_unknown_table_reports_no_columns_rather_than_throwing(): void
    {
        $this->assertFalse(OptionalColumn::on('gates_not_a_real_table', 'anything'));
    }
}
