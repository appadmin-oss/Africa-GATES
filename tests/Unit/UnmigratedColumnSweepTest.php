<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\BonusVoteService;
use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The rest of the writes to migration-added columns, swept after `show_name` broke
 * paid voting.
 *
 * ── TWO DIFFERENT RIGHT ANSWERS ──────────────────────────────────────────────
 *
 * The reflex after that outage is to wrap every such write in a filter. That would be
 * wrong, and the two cases here are the reason — the test names say which is which.
 *
 *   FILTER, when the row without the column is still TRUE.
 *     A nomination's decision is its `status`. `decision_reason` is a note attached to
 *     that decision, so dropping the note still leaves an honest record of an approval
 *     — and a reviewer who cannot approve at all is a worse outcome than a missing
 *     sentence. This is the failure the admin console's own migration banner names:
 *     "approving/rejecting nominations … are failing with errors while pages still
 *     load."
 *
 *   REFUSE, when dropping the column would make the row a LIE.
 *     The clawback deletes a supporter's votes and then stamps `refunded_at`, which is
 *     what stops the order being redeemed again. Filtering that stamp would delete the
 *     votes and leave the donation reading as live and redeemable — silent corruption,
 *     strictly worse than the exception it replaced. So it is checked before the
 *     transaction opens and refused with something an operator can act on.
 *
 * The rule is not "how important is this write" — it is "would the surviving row still
 * be true".
 */
final class UnmigratedColumnSweepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OptionalColumn::forget();
        DB::table('gates_votes')->delete();
        DB::table('gates_donations')->delete();
    }

    protected function tearDown(): void
    {
        OptionalColumn::forget();
        parent::tearDown();
    }

    /** Skips rather than passing vacuously if the driver cannot drop a column. */
    private function drop(string $table, string $col): void
    {
        try {
            DB::connection()->getPdo()->exec("ALTER TABLE {$table} DROP COLUMN {$col}");
        } catch (\Throwable $e) {
            $this->markTestSkipped("cannot drop a column here: {$e->getMessage()}");
        }
        OptionalColumn::forget();
        $this->assertFalse(DB::schema()->hasColumn($table, $col));
    }

    // ── FILTER: the decision must land without its note ──────────────────────

    public function test_a_nomination_can_still_be_rejected_without_the_reason_column(): void
    {
        $id = (int) DB::table('gates_nominations')->insertGetId([
            'cycle_id' => 1, 'nominee_name' => 'Someone', 'nominator_name' => 'A Nominator', 'nominator_email' => 'n@example.test',
            'status' => 'pending', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->drop('gates_nominations', 'decision_reason');

        $update = OptionalColumn::filter('gates_nominations', [
            'status'          => 'rejected',
            'decision_reason' => 'Out of scope for this cycle.',
        ], ['decision_reason']);

        $this->assertSame(['status' => 'rejected'], $update, 'only the note is dropped');

        DB::table('gates_nominations')->where('id', $id)->update($update);   // threw before

        $this->assertSame('rejected', (string) DB::table('gates_nominations')->where('id', $id)->value('status'));
    }

    /** With the column present the reviewer's words are still saved. */
    public function test_the_reason_is_still_recorded_on_a_migrated_database(): void
    {
        $id = (int) DB::table('gates_nominations')->insertGetId([
            'cycle_id' => 1, 'nominee_name' => 'Someone', 'nominator_name' => 'A Nominator', 'nominator_email' => 'n@example.test',
            'status' => 'pending', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        DB::table('gates_nominations')->where('id', $id)->update(
            OptionalColumn::filter('gates_nominations', [
                'status' => 'approved', 'decision_reason' => 'Strong evidence.',
            ], ['decision_reason'])
        );

        $row = DB::table('gates_nominations')->where('id', $id)->first();
        $this->assertSame('approved', (string) $row->status);
        $this->assertSame('Strong evidence.', (string) $row->decision_reason);
    }

    // ── REFUSE: a refund that cannot be recorded must not happen ─────────────

    /**
     * THE ONE THAT MUST NOT BE FILTERED.
     *
     * If `refunded_at` were merely dropped, this call would delete the supporter's
     * votes and return ok — leaving a donation that still reads as confirmed and can
     * be redeemed a second time. The votes must survive alongside the refusal.
     */
    public function test_a_clawback_is_refused_rather_than_half_applied(): void
    {
        $donationId = (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Kwame Mensah', 'donor_email' => 'k@example.test',
            'amount_naira' => 5000, 'tier' => 'paid-vote', 'bonus_votes' => 10,
            'votes_used' => 10, 'status' => 'confirmed', 'payment_ref' => 'AFG-CB-1',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => 'paidvote:x',
            'vote_type' => 'paid', 'weight' => 10, 'donation_id' => $donationId,
            'voted_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->drop('gates_donations', 'refunded_at');

        $out = BonusVoteService::clawbackDonation($donationId);

        $this->assertFalse($out['ok'], 'a refund that cannot be recorded must not be applied');
        $this->assertSame(0, (int) $out['cleared']);
        // The message must tell an operator what to DO. "Clawback failed." was all they
        // got when the raw SQLSTATE was swallowed.
        $this->assertStringContainsString('migration', $out['error']);
        $this->assertStringContainsString('refunded_at', $out['error']);
        $this->assertStringContainsString('db:migrate', $out['error']);

        // And nothing was half-done: the votes are untouched.
        $this->assertSame(1, DB::table('gates_votes')->where('donation_id', $donationId)->count(),
            'the votes must survive a refused clawback');
    }

    /** On a migrated database the clawback still works exactly as before. */
    public function test_a_clawback_still_works_when_the_column_exists(): void
    {
        $donationId = (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Ada Obi', 'donor_email' => 'a@example.test',
            'amount_naira' => 5000, 'tier' => 'paid-vote', 'bonus_votes' => 10,
            'votes_used' => 10, 'status' => 'confirmed', 'payment_ref' => 'AFG-CB-2',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => 'paidvote:y',
            'vote_type' => 'paid', 'weight' => 10, 'donation_id' => $donationId,
            'voted_at' => Carbon::now()->toDateTimeString(),
        ]);

        $out = BonusVoteService::clawbackDonation($donationId);

        $this->assertTrue($out['ok'], (string) ($out['error'] ?? ''));
        $this->assertSame(1, (int) $out['cleared']);
        $this->assertSame(0, DB::table('gates_votes')->where('donation_id', $donationId)->count());
        $this->assertNotNull(DB::table('gates_donations')->where('id', $donationId)->value('refunded_at'));
    }

    // ── The primitive ────────────────────────────────────────────────────────

    public function test_missing_reports_only_the_absent_columns(): void
    {
        $this->drop('gates_donations', 'refunded_at');

        $this->assertSame(['refunded_at'],
            OptionalColumn::missing('gates_donations', ['donor_email', 'refunded_at', 'amount_naira']));
        $this->assertSame([], OptionalColumn::missing('gates_donations', ['donor_email']));
    }

    public function test_the_explanation_names_the_column_and_the_remedy(): void
    {
        $msg = OptionalColumn::explain('gates_donations', ['refunded_at']);

        $this->assertStringContainsString('gates_donations', $msg);
        $this->assertStringContainsString('refunded_at', $msg);
        $this->assertStringContainsString('db:migrate', $msg);
    }
}
