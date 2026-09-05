<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The paid-vote receipt has to be true in three states, and it was only written
 * for two.
 *
 * `confirmed` means the money arrived. `minted` means the votes landed. Those come
 * apart the moment mint() gained its phase gate: a payment initiated inside the
 * voting window but CONFIRMED after it closed is refused on purpose rather than
 * pushing weighted votes into a closed tally, leaving votes_used = 0. The page
 * branched on `confirmed` alone, so that buyer was told their votes were "already
 * in the public tally" and a receipt was on the way.
 *
 * That is the worst kind of wrong on this platform: it takes money, adds nothing,
 * and says thank you. These tests exist so it cannot come back.
 */
class PaidVoteReceiptTest extends TestCase
{
    private function seedCycle(string $votingClose): int
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'gates', 'title' => 'GATES Awards', 'is_active' => 1, 'sort_order' => 1,
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-10 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime($votingClose)),
        ]);
        $catId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cid, 'slug' => 'music', 'title' => 'Music', 'sort_order' => 1,
        ]);
        DB::table('gates_nominees')->insert([
            'id' => 5, 'category_id' => $catId, 'name' => 'Ada Obi', 'status' => 'approved',
        ]);
        return $pid;
    }

    private function order(int $votesUsed, string $ref = 'AFG-PVOTE-abc123'): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'A Supporter', 'donor_email' => 's@x.io', 'amount_naira' => 7500,
            'tier' => 'paid-vote', 'bonus_votes' => 75, 'votes_used' => $votesUsed,
            'intent_nominee_id' => 5, 'payment_ref' => $ref, 'status' => 'confirmed',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function receipt(string $ref): string
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get(\AfricaGates\Controllers\PaidVoteController::class);

        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/vote/paid/success')
            ->withQueryParams(['ref' => $ref]);

        return (string) $ctrl->success($req, new Response())->getBody();
    }

    public function test_a_minted_order_is_celebrated(): void
    {
        $this->seedCycle('+7 days');
        $this->order(votesUsed: 75);

        $html = $this->receipt('AFG-PVOTE-abc123');

        $this->assertStringContainsString('75 votes counted', $html);
        $this->assertStringContainsString('Ada Obi', $html);
        $this->assertStringContainsString('already in the public tally', $html);
    }

    public function test_a_confirmed_but_unminted_order_is_never_reported_as_counted(): void
    {
        // Voting closed between checkout and confirmation. mint() refused, so
        // votes_used stayed 0 and nothing reached the tally.
        $this->seedCycle('-1 day');
        $this->order(votesUsed: 0);

        $html = $this->receipt('AFG-PVOTE-abc123');

        $this->assertStringNotContainsString('votes counted', $html);
        $this->assertStringNotContainsString('already in the public tally', $html);
        $this->assertStringNotContainsString('receipt is on its way', $html,
            'promising a receipt for votes that do not exist compounds the error');
    }

    public function test_the_unminted_state_says_a_refund_is_owed_and_shows_the_reference(): void
    {
        // The buyer needs two things to get their money back: to be told, and to
        // have the reference ops will ask for. `cycles:audit` reports the same
        // order to the operator, so both sides see the same fact.
        $this->seedCycle('-1 day');
        $this->order(votesUsed: 0);

        $html = $this->receipt('AFG-PVOTE-abc123');

        $this->assertStringContainsString('Voting closed before this payment confirmed', $html);
        $this->assertStringContainsString('refundable', $html);
        $this->assertStringContainsString('AFG-PVOTE-abc123', $html);
        $this->assertStringContainsString('/support', $html, 'and a route to act on it');
    }

    public function test_the_unminted_state_does_not_celebrate(): void
    {
        // Confetti over a refused order would be the same lie told visually.
        // Matched on the emitted markup, not the class name: the partial's
        // stylesheet defines .sc-flake unconditionally, so a bare substring
        // search would pass no matter what the page rendered.
        $this->seedCycle('-1 day');
        $this->order(votesUsed: 0);

        $this->assertStringNotContainsString('class="sc-flake"', $this->receipt('AFG-PVOTE-abc123'));
    }

    public function test_a_minted_order_does_celebrate(): void
    {
        // The positive control for the assertion above — without it, that test
        // would still pass if confetti stopped rendering entirely.
        $this->seedCycle('+7 days');
        $this->order(votesUsed: 75);

        $this->assertStringContainsString('class="sc-flake"', $this->receipt('AFG-PVOTE-abc123'));
    }

    public function test_an_unknown_reference_still_renders_the_pending_state(): void
    {
        $this->seedCycle('+7 days');

        $html = $this->receipt('AFG-PVOTE-nosuchref');

        $this->assertStringContainsString('could not confirm that payment yet', $html);
        $this->assertStringNotContainsString('votes counted', $html);
        $this->assertStringNotContainsString('refundable', $html,
            'an unconfirmed payment is not a refund case — nothing was charged that we know of');
    }

    public function test_a_minted_order_marks_the_per_device_ballot_tracker(): void
    {
        // The /vote hub counts "programmes voted on this device" from
        // localStorage. The OTP and points paths write it; the paid path did not,
        // so buying votes left the tracker reading zero.
        $pid = $this->seedCycle('+7 days');
        $this->order(votesUsed: 75);

        $this->assertStringContainsString("afg_voted_prog_{$pid}", $this->receipt('AFG-PVOTE-abc123'));
    }

    public function test_an_unminted_order_does_not_mark_the_tracker(): void
    {
        $pid = $this->seedCycle('-1 day');
        $this->order(votesUsed: 0);

        $this->assertStringNotContainsString("afg_voted_prog_{$pid}", $this->receipt('AFG-PVOTE-abc123'),
            'recording a vote that was refused is the same lie in a different place');
    }
}
