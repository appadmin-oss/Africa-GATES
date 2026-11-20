<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use AfricaGates\Services\PublicResults;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * NOTHING UNANNOUNCED IS SEARCHABLE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT THIS WAS WRITTEN FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see PublicResults} opens by stating the rule in as many words — "a judged-but-
 * unreleased category is a decided award nobody has announced, and serving it publicly
 * is announcing it" — and enforces it on every one of its own queries.
 *
 * The site search did not. {@see ActivityFeedService::results()} selected nominees on
 * `status IN ('winner','runner_up')` alone, with no gate on the cycle at all, and the
 * status column is written by a plain admin action:
 * `POST /admin/nominees/{id}/winner` updates the row from any screen, at any phase.
 * Crowning the winners of a category you are still judging is the ordinary way to use
 * that screen.
 *
 * So the search returned the person's NAME, the word "Winner", and the CATEGORY —
 * which is the announcement, made by a search box, for an award nobody had announced.
 *
 * ── AND THE DESTINATION BEING SAFE IS WHAT MADE IT INVISIBLE ────────────────
 *
 * The item linked to `/results/{id}`, and that page IS gated: it answers with nothing
 * for an unreleased cycle. So clicking through looked correct, and only the search
 * listing itself carried the leak. Nobody checking "is the results page gated?" would
 * ever have found this, because the results page was never the thing that was wrong.
 *
 * ── WHY A SEPARATE FILE ─────────────────────────────────────────────────────
 *
 * The promise is printed on the search band in those words. A promise a page makes to
 * the public is worth a test with the promise's own name on it, rather than one more
 * case inside a suite about feed ordering.
 */
final class UnannouncedResultTest extends TestCase
{
    private ActivityFeedService $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new ActivityFeedService();
    }

    /**
     * A programme, a cycle at `$status`, one category, and one crowned nominee.
     *
     * @return string the nominee's name, which is what the search is asked for
     */
    private function crowned(string $status, string $name): string
    {
        $pid = (int) (DB::table('gates_award_programmes')->max('id') ?? 0) + 1;
        DB::table('gates_award_programmes')->insert([
            'id' => $pid, 'slug' => 'prog-' . $pid, 'title' => 'Principal Awards ' . $pid,
            'is_active' => 1, 'sort_order' => $pid,
        ]);

        $cid = (int) (DB::table('gates_award_cycles')->max('id') ?? 0) + 1;
        DB::table('gates_award_cycles')->insert([
            'id' => $cid, 'programme_id' => $pid, 'year' => 2026, 'status' => $status,
        ]);

        $catId = (int) (DB::table('gates_award_categories')->max('id') ?? 0) + 1;
        DB::table('gates_award_categories')->insert([
            'id' => $catId, 'cycle_id' => $cid, 'slug' => 'cat-' . $catId,
            'title' => 'Academic Excellence ' . $catId, 'sort_order' => 0,
        ]);

        $nid = (int) (DB::table('gates_nominees')->max('id') ?? 0) + 1;
        DB::table('gates_nominees')->insert([
            'id' => $nid, 'category_id' => $catId, 'name' => $name, 'status' => 'winner',
            'vote_count' => 0, 'nominated_at' => Carbon::now()->subDay()->toDateTimeString(),
        ]);

        return $name;
    }

    /** Every item the search returns for `$q`, as "Label · Title". */
    private function found(string $q): array
    {
        return array_map(
            static fn (array $i): string => $i['label'] . ' · ' . $i['title'],
            $this->feed->search($q, 40)['items'],
        );
    }

    public function test_a_winner_crowned_before_the_announcement_is_not_searchable(): void
    {
        // `judging` is the phase an operator is IN when they press the winner button.
        $name = $this->crowned('judging', 'Adaeze Okonkwo');

        // The OUTCOME label, not the row. Their nominee row is public and should stay
        // that way — see the third test. What may not be published is the verdict.
        $this->assertNotContains('Winner · ' . $name, $this->found($name),
            'the site search announced an award that had not been announced: a nominee '
          . 'crowned while the cycle was still being judged came back labelled as a '
          . 'winner, with their category, to anyone who typed their name.');
    }

    public function test_the_same_winner_is_searchable_once_the_cycle_is_announced(): void
    {
        // The other half, and the one that stops the fix being "return nothing". Every
        // status in PublicResults::RELEASED must publish, or the search would go dark on
        // the results people actually come back for.
        foreach (PublicResults::RELEASED as $i => $status) {
            $name = $this->crowned($status, 'Chidinma Release ' . $i);

            $this->assertContains('Winner · ' . $name, $this->found($name),
                "a cycle in '{$status}' has been announced, so its winner must be findable");
        }
    }

    public function test_an_unannounced_nominee_is_still_findable_as_a_nominee(): void
    {
        // The gate is on the OUTCOME, never on the person. A nominee standing in an open
        // ballot is public — that is what a ballot is — and dropping them from search
        // would break the one lookup a supporter actually performs. What may not be
        // published is the word "Winner" beside their name.
        $name = $this->crowned('judging', 'Emeka Standing');
        $rows = $this->found($name);

        $this->assertContains('Nominee · ' . $name, $rows,
            'a nominee in an unannounced cycle stopped being findable at all — the gate '
          . 'belongs on the outcome, not on the person');
        $this->assertNotContains('Winner · ' . $name, $rows);
    }
}
