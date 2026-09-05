<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\FraudService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The fraud queue somebody can actually work.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * DETECTION WITHOUT A SURFACE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every vote on this platform has been scored and stamped since fraud detection shipped.
 * {@see FraudService}'s own docblock says a 60–79 score is "recorded + surfaced in the
 * admin review queue". There was no queue: `summary()` — the method written to fill one —
 * was called from nowhere in the codebase, and the only place any of it reached a person
 * was a raw table dump in the data registry.
 *
 * The integrity page gathers the signals that decide whether a result can be trusted, and
 * its own docblock lists vote fraud FIRST of the four. It carried three of them.
 *
 * Two things were broken inside the unused method, which is what usually happens to code
 * nothing renders — both pinned below.
 */
final class FraudReviewQueueTest extends TestCase
{
    private FraudService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new FraudService();

        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p', 'title' => 'P']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c', 'title' => 'C', 'sort_order' => 1]);
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
        DB::table('gates_votes')->insert([
            'id' => 1, 'nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => 'h1',
            'vote_type' => 'standard', 'weight' => 1, 'voted_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function score(string $decision, int $risk, ?int $voteId, string $ip = 'ip1'): int
    {
        return (int) DB::table('gates_fraud_scores')->insertGetId([
            'vote_id'    => $voteId,
            'email_hash' => 'h-' . $decision . $risk . $ip,
            'ip_hash'    => $ip,
            'risk_score' => $risk,
            'decision'   => $decision,
            'reviewed'   => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════

    /**
     * A BLOCKED attempt appears in the list.
     *
     * This is the bug the missing panel was hiding. A block is rejected BEFORE the vote is
     * cast, so it has no vote row and `vote_id` is NULL — and the query joined
     * `gates_votes` inner, which dropped every block. The panel would have reported
     * "blocked today: 3" over a list containing none of them: we stopped three things and
     * cannot tell you what.
     */
    public function test_blocked_attempts_are_in_the_recent_list(): void
    {
        $this->score('flag', 65, 1);
        $this->score('block', 90, null);

        $recent = $this->svc->summary()['recent_flags'];

        $this->assertCount(2, $recent, 'a blocked attempt was dropped for having no vote row');
        $this->assertSame(['block', 'flag'], array_column($recent, 'decision'));
        $this->assertNull($recent[0]->nominee_name, 'a block never became a vote, so it names no nominee');
    }

    /** …and a flagged one still names the nominee it was cast for. */
    public function test_a_flagged_vote_still_names_its_nominee(): void
    {
        $this->score('flag', 65, 1);

        $this->assertSame('Ada Obi', $this->svc->summary()['recent_flags'][0]->nominee_name);
    }

    /**
     * The busiest network is not the same thing as a suspicious one.
     *
     * Without a decision filter this listed the five addresses that voted most in a day —
     * a university, an office, a phone carrier's NAT — under a fraud heading, which is how
     * a room full of legitimate voters becomes a finding.
     */
    public function test_top_addresses_are_scored_ones_not_busy_ones(): void
    {
        for ($i = 0; $i < 6; $i++) $this->score('allow', 5, null, 'school');
        $this->score('flag', 70, null, 'ring');

        $top = $this->svc->summary()['top_ip_hashes'];

        $this->assertCount(1, $top);
        $this->assertSame('ring', $top[0]->ip_hash);
    }

    // ── the queue ───────────────────────────────────────────────────────────

    /**
     * Marking works, and the counter goes down.
     *
     * `reviewed` was read by the summary and written by nothing anywhere in the codebase,
     * so "12 unreviewed" could only ever become 13. A queue nobody can clear is a queue
     * nobody works.
     */
    public function test_the_unreviewed_count_can_come_down(): void
    {
        $a = $this->score('flag', 65, 1);
        $b = $this->score('block', 88, null);

        $this->assertSame(2, $this->svc->summary()['unreviewed']);

        $this->assertSame(2, $this->svc->markReviewed([$a, $b], 7));
        $this->assertSame(0, $this->svc->summary()['unreviewed']);
    }

    /**
     * A second pass over the same rows reports nothing, rather than reporting them again.
     *
     * Two people clearing the same queue should not both be told they reviewed twelve
     * things — the number returned is what THIS call changed.
     */
    public function test_marking_the_same_row_twice_changes_nothing(): void
    {
        $a = $this->score('flag', 65, 1);

        $this->assertSame(1, $this->svc->markReviewed([$a]));
        $this->assertSame(0, $this->svc->markReviewed([$a]));
    }

    /** Nothing selected, nothing changed — and no query that means "every row". */
    public function test_an_empty_selection_marks_nothing(): void
    {
        $this->score('flag', 65, 1);

        $this->assertSame(0, $this->svc->markReviewed([]));
        $this->assertSame(0, $this->svc->markReviewed([0, -1]));
        $this->assertSame(1, $this->svc->summary()['unreviewed'], 'an empty selection cleared the queue');
    }

    /**
     * Marking is not a verdict on the vote.
     *
     * The flag stays, the risk score is unchanged, and the vote is still on the tally.
     * Reviewing records that a person looked; withdrawing a vote is a different act with
     * its own trail, and conflating them would let a queue-clearing click quietly alter a
     * result.
     */
    public function test_reviewing_does_not_touch_the_vote(): void
    {
        DB::table('gates_votes')->where('id', 1)->update(['fraud_flag' => 1, 'risk_score' => 65]);
        $a = $this->score('flag', 65, 1);

        $this->svc->markReviewed([$a]);

        $v = DB::table('gates_votes')->where('id', 1)->first();
        $this->assertSame(1, (int) $v->fraud_flag);
        $this->assertSame(65, (int) $v->risk_score);
        $this->assertSame(65, (int) DB::table('gates_fraud_scores')->where('id', $a)->value('risk_score'));
    }

    /** The panel exists on the page, with the way to clear the queue on it. */
    public function test_the_integrity_page_carries_the_panel(): void
    {
        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/integrity.twig');

        $this->assertStringContainsString('id="fraud"', $t);
        $this->assertStringContainsString('/admin/integrity/fraud-reviewed', $t);
        $this->assertStringContainsString('name="ids[]"', $t);
        // The admin CSP has no 'unsafe-inline', so the action is a plain POST form and the
        // token has to travel with it.
        $this->assertStringContainsString('name="_token"', $t);
        $this->assertStringNotContainsString('onclick=', $t);
    }
}
