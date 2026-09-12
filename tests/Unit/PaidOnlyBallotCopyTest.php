<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ResultRelease;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * WHERE A CONTRIBUTED VOTE IS THE ONLY VOTE, IT IS NOT THE EXCEPTIONAL ONE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every surface that prints an organic count frames it as the part of a tally that was
 * NOT bought — "1,955 not organic", "0 of them organic", "the remaining 1,955 were
 * bought in a pack". That is the right disclosure on a ballot offering both kinds of
 * vote: it tells a reader what somebody's support was made of, and a nominee whose
 * tally is mostly purchased stands out from one whose tally is not.
 *
 * Where `paid_voting_disable_free` is set, nobody had a choice. `castVote` answers 403,
 * `organic_vote_count` can never be written, and the mark fires on EVERY row of EVERY
 * category — so a signal that exists to single out an unusual tally was being applied to
 * the whole field. A reader is told what "organic" means, shown a column of zeros, and
 * left to conclude that a field of nominees all chose to buy their support.
 *
 * Nobody chose anything. The disclosure that is true on such a ballot is that there was
 * no alternative, and it belongs beside the numbers rather than two clicks away on
 * /integrity.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE RESOLVER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `paid_only` is answered once, on the drawn result, because the public result page and
 * the release screen an operator signs off both read that object. Two readers of one
 * setting is how the two screens come to describe one award differently — and this one
 * decides whether a column of zeros is a fact about a ballot or an accusation about a
 * field.
 */
final class PaidOnlyBallotCopyTest extends TestCase
{
    private function settings(array $kv): void
    {
        foreach ($kv as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        }
    }

    private function seed(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'), 'status' => 'judged',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 10, 'cycle_id' => 1, 'slug' => 'cat-10', 'title' => 'Category',
        ]);
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 10, 'name' => 'A Nominee', 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => 1955, 'organic_vote_count' => 0,
        ]);
    }

    // ══ the flag ═════════════════════════════════════════════════════════════

    public function test_the_drawn_result_says_whether_a_free_vote_was_on_offer(): void
    {
        $this->seed();

        $this->settings(['paid_voting_enabled' => '1', 'paid_voting_disable_free' => '1']);
        $this->assertTrue(ResultRelease::category(10)['paid_only']);

        $this->settings(['paid_voting_disable_free' => '']);
        $this->assertFalse(ResultRelease::category(10)['paid_only']);
    }

    /**
     * INCLUDING ON A CATEGORY WITH NOTHING IN IT.
     *
     * `category()` has an early-return shape for a category that scored nobody, and a
     * template branching on a key that shape does not carry gets Twig's silent null —
     * which renders the two-kinds-of-vote explanation on a paid-only ballot. A missing
     * key is indistinguishable from `false` in Twig and that is exactly the trap.
     */
    public function test_the_flag_survives_a_category_that_scored_nobody(): void
    {
        $this->settings(['paid_voting_enabled' => '1', 'paid_voting_disable_free' => '1']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'), 'status' => 'judged',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 11, 'cycle_id' => 1, 'slug' => 'cat-11', 'title' => 'Empty',
        ]);

        $r = ResultRelease::category(11);
        $this->assertSame([], $r['rows'], 'fixture is not the empty shape');
        $this->assertArrayHasKey('paid_only', $r);
        $this->assertTrue($r['paid_only']);
    }

    // ══ every surface branches on it ═════════════════════════════════════════

    /**
     * @return array<string,string> template path => prose with Twig comments removed
     */
    private static function templates(): array
    {
        $root = dirname(__DIR__, 2) . '/';
        $out  = [];
        foreach (['templates/pages/results/show.twig',
                  'templates/admin/result-release.twig',
                  'templates/pages/vote-nominee.twig'] as $rel) {
            // Comments stripped. Each of these files documents the copy it replaced, in
            // the words it replaced — the fault reads exactly like the fix.
            $out[$rel] = (string) preg_replace('~\{#.*?#\}~s', ' ',
                (string) file_get_contents($root . $rel));
        }
        return $out;
    }

    public function test_no_surface_prints_the_organic_split_where_there_was_no_choice(): void
    {
        foreach (self::templates() as $rel => $src) {
            $flag = str_contains($rel, 'vote-nominee') ? 'paid_free_disabled' : 'paid_only';
            $this->assertStringContainsString($flag, $src,
                $rel . ' does not know whether a free vote was on offer, so it marks a '
                . 'whole field as having bought their support');
        }
    }

    /**
     * AND THE REPLACEMENT SENTENCE EXISTS.
     *
     * Branching without saying anything would delete the disclosure rather than correct
     * it — and a page that goes quiet about where a tally came from is worse than one
     * that describes it clumsily. Each surface has to state the fact positively.
     */
    public function test_each_surface_says_instead_that_there_was_no_free_vote(): void
    {
        $t = self::templates();

        $this->assertStringContainsString('Free voting was not open',
            $t['templates/pages/results/show.twig'],
            'the public result page branches on paid_only and then says nothing');
        $this->assertStringContainsString('One kind of vote',
            $t['templates/pages/results/show.twig']);

        $this->assertStringContainsString('every vote here was contributed',
            $t['templates/admin/result-release.twig'],
            'the release screen drops the per-row mark without replacing it');

        $this->assertStringContainsString('free voting is not open on',
            $t['templates/pages/vote-nominee.twig'],
            'the ballot says nothing about why every vote on it was paid for');
    }

    /**
     * THE COLUMN OF ZEROS IS A DASH.
     *
     * "0" under a heading a reader takes to mean organic support is a claim about that
     * nominee. An em dash under a heading that says the option was not offered is a fact
     * about the ballot, which is what it actually is.
     */
    public function test_the_release_screen_stops_printing_a_column_of_zeros(): void
    {
        $src = self::templates()['templates/admin/result-release.twig'];

        $this->assertStringContainsString("{% if paid_only %}&mdash;{% else %}", $src,
            'the overall table still prints 0 in a column nobody could have filled');
        $this->assertStringContainsString("{% if c.paid_only %}&mdash;{% else %}", $src,
            'the per-category table still prints 0 in a column nobody could have filled');
    }
}
