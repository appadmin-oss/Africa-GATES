<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Judge\Services\JudgeService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The panel judges the SHORTLIST, not the whole field.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS HAPPENING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every approved nominee in a programme reached the ballot. So a panel of six opened a list
 * of two hundred, and the cut that the shortlist rules had already computed and PUBLISHED —
 * with a rule, a considered count and a frozen set of entries — counted for nothing at the
 * one screen it exists for.
 *
 * That is not only wasted effort. A judge scoring somebody who was never shortlisted
 * produces scores that the results stage has no place for, and a nominee below the cut
 * carries scores in the database that were never meant to exist.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE EMPTY CASE HAS TO BE LOUD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A ballot filtered down to nobody looks exactly like a ballot somebody has finished. The
 * judge cannot tell the difference, and the thing they would report — "there is nothing to
 * score" — is the same sentence in both cases. This file's sibling
 * ({@see JudgeCannotScoreTest}) exists because that exact failure already shipped once with
 * a missing rubric.
 */
final class JudgeShortlistOnlyTest extends TestCase
{
    private JudgeService $svc;
    private int $in = 0;
    private int $out = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new JudgeService();

        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'Educators']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);

        $this->in = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 1, 'name' => 'Above the cut', 'status' => 'approved',
            'vote_count' => 10, 'organic_vote_count' => 10,
        ]);
        $this->out = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 1, 'name' => 'Below the cut', 'status' => 'approved',
            'vote_count' => 900, 'organic_vote_count' => 900,
        ]);

        DB::table('gates_judges')->insert([
            'id' => 1, 'name' => 'J1', 'email' => 'j1@x.io', 'is_active' => 1,
            'programme_ids' => json_encode([1]),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE RULE
    // ════════════════════════════════════════════════════════════════════════

    public function test_only_shortlisted_nominees_reach_the_ballot(): void
    {
        $this->publishShortlist(1, 1, [$this->in]);

        $ids = array_column($this->svc->ballot(1, 1)['categories'][0]['nominees'], 'id');

        $this->assertSame([$this->in], array_map('intval', $ids),
            'a nominee below the cut was put in front of the panel');
    }

    /**
     * Vote count decides nothing here — the nominee LEFT OFF has 90× the votes.
     *
     * Deliberate: the shortlist is a published editorial act with its own rule, and the
     * panel judges that act. A filter that quietly re-sorted by popularity would put the
     * community signal back inside the expert one through a different door.
     */
    public function test_the_most_voted_nominee_is_excluded_when_not_shortlisted(): void
    {
        $this->publishShortlist(1, 1, [$this->in]);

        $ids = array_map('intval',
            array_column($this->svc->ballot(1, 1)['categories'][0]['nominees'], 'id'));

        $this->assertNotContains($this->out, $ids);
        $this->assertSame(1, $this->svc->ballot(1, 1)['progress']['total']);
    }

    /** A withdrawn shortlist stops counting — only `published` is a shortlist. */
    public function test_a_withdrawn_shortlist_does_not_open_the_ballot(): void
    {
        $id = $this->publishShortlist(1, 1, [$this->in]);
        DB::table('gates_shortlists')->where('id', $id)->update(['status' => 'withdrawn']);

        $b = $this->svc->ballot(1, 1);

        $this->assertFalse($b['judging_open']);
        $this->assertTrue($b['no_shortlist']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND THE LOCK
    // ════════════════════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS: no shortlist is a LOCK, not a ballot with nobody on it.
     *
     * An empty ballot is indistinguishable from a finished one, so the reason has to be on
     * the page — and aimed at the person who can act on it, which is not the judge reading
     * it.
     */
    public function test_an_unpublished_shortlist_locks_the_ballot_and_says_why(): void
    {
        $b = $this->svc->ballot(1, 1);

        $this->assertFalse($b['judging_open'], 'an unusable ballot was left open');
        $this->assertTrue($b['no_shortlist']);
        $this->assertStringContainsString('shortlist', strtolower($b['lock_reason']));
        $this->assertStringContainsString('organisers', strtolower($b['lock_reason']),
            'the judge reading this cannot publish a shortlist themselves');
    }


    // ════════════════════════════════════════════════════════════════════════
    // AND THE HALF-CONFIGURED CYCLE, WHICH THE LOCK COULD NEVER SEE
    // ════════════════════════════════════════════════════════════════════════

    /**
     * A SHORTLIST BELONGS TO A CATEGORY, AND THE CUT WAS ASKED PER CYCLE.
     *
     * `ShortlistService::shortlistedIn()` returns every shortlisted id in a CYCLE as one
     * set, and the ballot kept any nominee in it. So on a cycle where one category had
     * published and another had not, every nominee of the second was absent from the set
     * and silently dropped — while `$noShortlist` could not fire, because the FIRST
     * category still had people.
     *
     * The result is the exact failure this file exists to prevent, one level down:
     * judging reads as open, the category reads as finished, and nobody in it can be
     * scored. A judge cannot tell that from a category they have completed.
     */
    public function test_a_category_without_a_shortlist_says_so_when_another_has_one(): void
    {
        // A second category, with entries and no published shortlist of its own.
        DB::table('gates_award_categories')->insert(['id' => 2, 'cycle_id' => 1, 'slug' => 'c2', 'title' => 'C2']);
        $other = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 2, 'name' => 'In the quiet category', 'status' => 'approved',
            'vote_count' => 5, 'organic_vote_count' => 5,
        ]);

        $this->publishShortlist(1, 1, [$this->in]);

        $b  = $this->svc->ballot(1, 1);
        $by = [];
        foreach ($b['categories'] as $c) $by[$c['category']['title']] = $c;

        // The published category is unaffected.
        $this->assertSame([$this->in],
            array_map('intval', array_column($by['C1']['nominees'], 'id')));

        // The other is still empty — that is the rule — but it no longer says nothing.
        $this->assertSame([], $by['C2']['nominees']);
        $this->assertNotSame('', $by['C2']['empty_why'],
            'a category cut to nobody rendered empty with no explanation, which is '
            . 'indistinguishable from one the judge has finished');
        // The RIGHT reason, not merely a reason. Asked per cycle instead of per category,
        // C2 still comes out empty and still carries a sentence — but the wrong one, about
        // a published list naming nobody rather than a list nobody has published. That
        // sends the organiser to the wrong screen, so a test that accepts either message
        // does not hold the fix.
        $this->assertStringContainsString('not been published', strtolower($by['C2']['empty_why']),
            'the category is empty because its own shortlist is unpublished, and the '
            . 'reason given describes a different fault');
        $this->assertStringContainsString('organisers', strtolower($by['C2']['empty_why']),
            'the judge reading this cannot publish a shortlist themselves');

        // And a category that IS judgeable carries no explanation to read.
        $this->assertSame('', $by['C1']['empty_why']);

        // The programme-level lock is correctly silent: there IS something to judge.
        $this->assertTrue($b['judging_open']);
        $this->assertFalse($b['no_shortlist']);
    }

    /**
     * An empty category is not reported as a missing shortlist either.
     *
     * Same distinction the programme-level lock already draws, drawn per category:
     * different problem, different person, different next step.
     */
    public function test_a_category_with_no_entries_says_that_rather_than_shortlist(): void
    {
        DB::table('gates_award_categories')->insert(['id' => 2, 'cycle_id' => 1, 'slug' => 'c2', 'title' => 'Empty']);
        $this->publishShortlist(1, 1, [$this->in]);

        $by = [];
        foreach ($this->svc->ballot(1, 1)['categories'] as $c) $by[$c['category']['title']] = $c;

        $this->assertStringContainsString('no entries', strtolower($by['Empty']['empty_why']));
        $this->assertStringNotContainsString('shortlist', strtolower($by['Empty']['empty_why']),
            'told to publish a shortlist for a category with nothing to shortlist');
    }

    /**
     * And a published list that names nobody still approved is its own case.
     *
     * A withdrawal or a merge after the list was frozen. "Publish the shortlist" is the
     * wrong instruction — it IS published — so it gets a different sentence.
     */
    public function test_a_published_list_naming_nobody_approved_says_so(): void
    {
        $this->publishShortlist(1, 1, [$this->in]);
        // The one shortlisted entry stops being approved after the list was frozen; the
        // other approved nominee was never on it. ('rejected' is not in the status set —
        // an entry that falls out goes back to pending or is merged away.)
        DB::table('gates_nominees')->where('id', $this->in)->update(['status' => 'pending']);

        $by = [];
        foreach ($this->svc->ballot(1, 1)['categories'] as $c) $by[$c['category']['title']] = $c;

        $why = strtolower($by['C1']['empty_why']);
        $this->assertStringContainsString('names', $why);
        $this->assertStringNotContainsString('not been published', $why,
            'told to publish a shortlist that is already published');
    }

    /** And the ballot renders the reason rather than holding it in an array. */
    public function test_the_ballot_screen_shows_why_a_category_is_empty(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/judge/ballot.twig');
        // Twig comments quote the reasoning and would satisfy a naive scan alone.
        $body = (string) preg_replace('~\{#.*?#\}~s', '', $tpl);

        $this->assertStringContainsString('empty_why', $body,
            'the reason a category is empty reaches no screen');
    }

    /**
     * A programme with no entries at all is NOT reported as an unpublished shortlist.
     *
     * Different problem, different person, different next step — and "publish the
     * shortlist" is useless advice when there is nothing to shortlist.
     */
    public function test_an_empty_field_is_not_reported_as_a_missing_shortlist(): void
    {
        DB::table('gates_nominees')->whereIn('id', [$this->in, $this->out])->delete();

        $b = $this->svc->ballot(1, 1);

        $this->assertFalse($b['no_shortlist'],
            'told the organisers to publish a shortlist for a programme with no entries');
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND IT IS A RULE, NOT A SCREEN
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Enforced server-side, so a stale tab or a crafted POST cannot score past the cut.
     *
     * Scoping a screen is presentation. This is the part that holds when the screen is not
     * involved — a judge who opened the ballot before a shortlist was withdrawn still has
     * live inputs in front of them.
     */
    public function test_a_nominee_off_the_shortlist_cannot_be_scored_by_a_crafted_post(): void
    {
        $this->publishShortlist(1, 1, [$this->in]);
        $crit = (int) DB::table('gates_judge_criteria')->whereNull('programme_id')->value('id');
        $this->assertGreaterThan(0, $crit, 'the shipped rubric should be installed');

        $r = $this->svc->saveScore(1, $this->out, [$crit => 9]);

        $this->assertFalse($r['ok'], 'a nominee below the cut was scored');
        $this->assertStringContainsString('shortlist', strtolower($r['message']));
        $this->assertSame(0, DB::table('gates_judge_criteria_scores')
            ->where('nominee_id', $this->out)->count());
    }

    /**
     * And the dossier is not reachable by id either.
     *
     * `mayJudgeNominee()` gates the evidence reader and the dossier-map endpoint as well as
     * the ballot. Scoping the list while leaving the record reachable would be the same
     * restriction with one more click in front of it.
     */
    public function test_the_dossier_of_an_unshortlisted_nominee_is_not_reachable(): void
    {
        $this->publishShortlist(1, 1, [$this->in]);

        $this->assertTrue($this->svc->mayJudgeNominee(1, $this->in));
        $this->assertFalse($this->svc->mayJudgeNominee(1, $this->out));
    }
}
