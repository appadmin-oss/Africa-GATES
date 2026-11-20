<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\VoteProof;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Is there any proof to show them?"
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS DEFEND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * One property, and everything else is detail: **this must be able to say no.**
 *
 * A verification tool that can only confirm is not a verification tool, it is a
 * reassurance generator — and this one was built because supporters stopped
 * believing our reassurances. So the tests below spend most of their effort on the
 * failing paths: an order that claims to have minted with no vote rows behind it,
 * an order paid with nothing delivered, and the case where our own counter
 * disagrees with the tally.
 *
 * The second property: it counts vote ROWS, not `votes_used`. Reading the counter
 * asks the system whether it thinks it did the work; reading the rows asks whether
 * the work is there. `test_a_claim_with_no_votes_behind_it_is_caught` is the one
 * that matters most, because that state is invisible to every other report on the
 * platform.
 */
final class VoteProofTest extends TestCase
{
    private int $nomineeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_votes')->delete();
        DB::table('gates_donations')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 95, 'title' => 'P', 'slug' => 'p-950']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 950, 'programme_id' => 95, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 950, 'cycle_id' => 950, 'title' => 'Cat', 'slug' => 'cat-950']);
        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 950, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0,
        ]);
    }

    private function order(string $ref, array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId($over + [
            'donor_name' => 'Kwame Mensah', 'donor_email' => 'k@example.test',
            'amount_naira' => 5000, 'tier' => 'paid-vote', 'bonus_votes' => 20, 'votes_used' => 0,
            'intent_nominee_id' => $this->nomineeId, 'payment_ref' => $ref, 'status' => 'confirmed',
            'confirmed_at' => Carbon::now()->subHours(3)->toDateTimeString(),
            'created_at'   => Carbon::now()->subHours(4)->toDateTimeString(),
        ]);
    }

    private function voteRow(int $donationId, int $weight): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $this->nomineeId, 'category_id' => 950,
            'voter_email_hash' => 'paid:' . $donationId . ':' . bin2hex(random_bytes(4)),
            'vote_type' => 'paid', 'weight' => $weight, 'donation_id' => $donationId,
            'voted_at' => Carbon::now()->subHours(2)->toDateTimeString(),
        ]);
    }

    // ── the good case, with the receipt a supporter can show ─────────────────

    public function test_a_delivered_order_shows_the_actual_vote_records(): void
    {
        $id = $this->order('AFG-PVOTE-OK', ['votes_used' => 20]);
        $this->voteRow($id, 20);

        $p = VoteProof::forReference('AFG-PVOTE-OK');

        $this->assertTrue($p['found']);
        $this->assertSame('delivered', $p['state']);
        $this->assertSame(20, $p['delivered']);
        $this->assertFalse($p['mismatch']);
        // The ENTRIES, not just a total. A number we print is a claim; the rows
        // behind it are something the reader can weigh.
        $this->assertCount(1, $p['votes']);
        $this->assertSame(20, $p['votes'][0]['weight']);
        $this->assertSame('Ada Obi', $p['votes'][0]['nominee']);
        $this->assertNotNull($p['votes'][0]['at']);
    }

    // ── the cases it must be willing to report ───────────────────────────────

    /** The incident itself: money taken, nothing on the tally. */
    public function test_a_paid_order_with_no_votes_is_reported_as_such(): void
    {
        $this->order('AFG-PVOTE-OWED');

        $p = VoteProof::forReference('AFG-PVOTE-OWED');

        $this->assertSame('awaiting_delivery', $p['state']);
        $this->assertSame(0, $p['delivered']);
        $this->assertSame(20, $p['ordered']);
    }

    /**
     * THE STATE NOTHING ELSE DETECTS.
     *
     * `votes_used` says the mint happened and there are no vote rows behind it —
     * a mint that died between stamping the claim and writing the rows. Every
     * other report on the platform reads the counter, so every other report calls
     * this order delivered. Counting rows is the whole reason this file exists.
     */
    public function test_a_claim_with_no_votes_behind_it_is_caught(): void
    {
        $this->order('AFG-PVOTE-GHOST', ['votes_used' => 20]);

        $p = VoteProof::forReference('AFG-PVOTE-GHOST');

        $this->assertSame('claimed_but_missing', $p['state']);
        $this->assertSame(0, $p['delivered']);
        $this->assertSame(20, $p['claimed']);
        $this->assertTrue($p['mismatch'], 'the disagreement must be surfaced, not smoothed away');
    }

    /** Partial delivery is its own state, not rounded to either end. */
    public function test_a_partial_delivery_is_not_reported_as_delivered(): void
    {
        $id = $this->order('AFG-PVOTE-PART', ['votes_used' => 20]);
        $this->voteRow($id, 8);

        $p = VoteProof::forReference('AFG-PVOTE-PART');

        $this->assertSame('partial', $p['state']);
        $this->assertSame(8, $p['delivered']);
        $this->assertTrue($p['mismatch']);
    }

    public function test_a_refunded_order_reads_as_refunded_not_as_missing(): void
    {
        $this->order('AFG-PVOTE-BACK', ['refunded_at' => Carbon::now()->toDateTimeString()]);

        $this->assertSame('refunded', VoteProof::forReference('AFG-PVOTE-BACK')['state']);
    }

    // ── privacy ──────────────────────────────────────────────────────────────

    /**
     * A reference is unguessable but it is still a BEARER token — anyone holding
     * it opens the page. So the receipt holds what the holder already knows and
     * nothing about the payer.
     */
    public function test_the_receipt_contains_nothing_about_the_payer(): void
    {
        $id = $this->order('AFG-PVOTE-PII', ['votes_used' => 20]);
        $this->voteRow($id, 20);

        $blob = json_encode(VoteProof::forReference('AFG-PVOTE-PII')) ?: '';

        $this->assertStringNotContainsString('Kwame', $blob, 'no payer name');
        $this->assertStringNotContainsString('k@example.test', $blob, 'no payer email');
    }

    /** An unknown reference is not an oracle for whether one exists. */
    public function test_an_unknown_reference_gives_nothing_away(): void
    {
        $p = VoteProof::forReference('AFG-PVOTE-NOPE');
        $this->assertFalse($p['found']);
        $this->assertStringContainsString('AFG-', $p['say'], 'it explains OUR format rather than confirming a miss');
    }

    // ── the operator's number ────────────────────────────────────────────────

    /**
     * `clean` is a claim about the FAILURE buckets, not a percentage.
     *
     * 99.8% delivered is not "sorted" to the 0.2%, and they are the ones who will
     * be writing to you. One outstanding order makes the whole report dirty.
     */
    public function test_the_tally_is_only_clean_when_nothing_is_outstanding(): void
    {
        $ok = $this->order('AFG-PVOTE-T1', ['votes_used' => 20]);
        $this->voteRow($ok, 20);

        $this->assertTrue(VoteProof::tally()['clean']);

        // One order paid with nothing delivered.
        $this->order('AFG-PVOTE-T2');

        $t = VoteProof::tally();
        $this->assertFalse($t['clean'], 'a single outstanding order must dirty the report');
        $this->assertSame(1, $t['broken']);
        $this->assertSame(1, $t['orders']['awaiting_delivery']);
        $this->assertSame(1, $t['orders']['delivered']);
        $this->assertSame(5000, $t['naira_owed']);
        $this->assertStringContainsString('NOT resolved', $t['say']);
    }

    /** And when it is clean, it says so in words that can be published. */
    public function test_a_clean_tally_says_something_publishable(): void
    {
        $id = $this->order('AFG-PVOTE-T3', ['votes_used' => 20]);
        $this->voteRow($id, 20);

        $t = VoteProof::tally();
        $this->assertTrue($t['clean']);
        $this->assertStringContainsString('nothing outstanding', $t['say']);
        $this->assertSame(20, $t['votes']['delivered']);
    }

    /** The report hands back real references so it can be spot-checked. */
    public function test_broken_orders_come_with_references_to_check(): void
    {
        $this->order('AFG-PVOTE-T4');

        $t = VoteProof::tally();
        $this->assertNotEmpty($t['examples']);
        $this->assertSame('AFG-PVOTE-T4', $t['examples'][0]['ref']);
        $this->assertSame('awaiting_delivery', $t['examples'][0]['state']);
    }

    /** A pending or failed checkout is not a fault and must not be counted as one. */
    public function test_an_unconfirmed_order_is_not_counted_as_broken(): void
    {
        $this->order('AFG-PVOTE-T5', ['status' => 'pending', 'confirmed_at' => null]);
        $this->order('AFG-PVOTE-T6', ['status' => 'failed', 'confirmed_at' => null]);

        $t = VoteProof::tally();
        $this->assertSame(0, $t['broken']);
        $this->assertTrue($t['clean']);
        $this->assertSame(1, $t['orders']['pending']);
        $this->assertSame(1, $t['orders']['not_paid']);
    }
}
