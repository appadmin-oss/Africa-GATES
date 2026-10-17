<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CommunityReturnService, HelpCentre, RuleEngine};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A PROMISE WITH NO MECHANISM BEHIND IT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see CommunityReturnService::accrue()} refuses any donation whose tier is not
 * `paid-vote`. Every kobo this ledger has ever held came from somebody buying votes on
 * a ballot — a general contribution to the programme does not accrue, and there is no
 * other producer.
 *
 * So where {@see \AfricaGates\Services\PaidVoteService::enabled()} is off, nothing can
 * raise a naira in a nominee's name and the return is inert. That toggle is OFF BY
 * DEFAULT, which makes the inert state the state a fresh deployment ships in.
 *
 * Meanwhile /integrity §06, the Help Centre article and the settings screen all
 * described the return in the present tense, gated on nothing but the rate. A nominee
 * searching "what do I earn" was answered with a mechanism their programme does not
 * run, and an operator tuning the share was tuning a slice of revenue that does not
 * exist. It is docs/CODEBASE-INDEX.md §19's shape — prose promising a behaviour — with
 * the promise written into the product rather than into a comment.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE HALF OF THE FIX THAT IS EASY TO GET WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The obvious implementation gates `accrue()` too. That would be a bug with money in
 * it: a webhook can land days after its order, and an operator switching paid voting
 * off must not retrospectively cancel a share on money already taken. The gate is on
 * the PROMISE. `test_switching_paid_voting_off_does_not_cancel_money_already_raised`
 * is the one that holds it.
 */
final class CommunityReturnActiveTest extends TestCase
{
    private function settings(array $kv): void
    {
        foreach ($kv as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        }
    }

    private function rate(int $bps): array
    {
        (new RuleEngine())->set('global', null, ['community_return_bps' => $bps]);
        return (new RuleEngine())->effective();
    }

    // ══ is the mechanism live at all? ════════════════════════════════════════

    public function test_the_return_is_dead_where_no_votes_are_sold(): void
    {
        $this->settings(['paid_voting_enabled' => '']);

        $this->assertFalse(CommunityReturnService::active());

        $r = CommunityReturnService::displayRules($this->rate(5000));
        $this->assertFalse($r['on'],
            'the return reports itself live on a site that sells no votes, so nothing '
            . 'can ever accrue and every page says it is');
        $this->assertSame('no_paid_voting', $r['off_reason']);

        // The rate itself is still reported. An operator has to be able to see what
        // they have configured; what they must not be told is that it is running.
        $this->assertSame('50', $r['pct']);
    }

    public function test_it_is_live_once_votes_are_sold(): void
    {
        $this->settings(['paid_voting_enabled' => '1']);

        $this->assertTrue(CommunityReturnService::active());

        $r = CommunityReturnService::displayRules($this->rate(3000));
        $this->assertTrue($r['on']);
        $this->assertSame('', $r['off_reason']);
        $this->assertSame('30', $r['pct']);
    }

    /**
     * THE TWO REASONS ARE NOT INTERCHANGEABLE.
     *
     * "The share is set to 0%" is a decision somebody made about a live mechanism, and
     * points at a settings field. "No votes are sold here" is the mechanism having no
     * input, and points at a different one. A page printing one sentence for both sends
     * half its readers to fix something that changes nothing.
     */
    public function test_a_zero_rate_is_reported_as_a_zero_rate_and_not_as_a_dead_mechanism(): void
    {
        $this->settings(['paid_voting_enabled' => '1']);

        $r = CommunityReturnService::displayRules($this->rate(0));
        $this->assertFalse($r['on']);
        $this->assertSame('rate_zero', $r['off_reason']);
    }

    /**
     * AND PAID VOTING WINS THE TIE.
     *
     * With no vote sales the rate is moot, so naming the rate would send an operator to
     * raise a percentage of nothing.
     */
    public function test_no_paid_voting_is_named_before_a_zero_rate(): void
    {
        $this->settings(['paid_voting_enabled' => '']);

        $this->assertSame('no_paid_voting',
            CommunityReturnService::displayRules($this->rate(0))['off_reason']);
    }

    // ══ the money is not touched ═════════════════════════════════════════════

    /**
     * SWITCHING THE PROMISE OFF DOES NOT CANCEL A SHARE ALREADY EARNED.
     *
     * This is the whole safety argument for gating the copy rather than the ledger, and
     * it is asserted rather than reasoned about: a webhook confirming an order placed
     * while the contract was live may land after the toggle is flipped, and refusing it
     * would take money from a nominee for a decision made after they raised it.
     */
    public function test_switching_paid_voting_off_does_not_cancel_money_already_raised(): void
    {
        $this->settings(['paid_voting_enabled' => '1']);
        (new RuleEngine())->set('global', null, [
            'community_return_bps' => 5000,
            'community_return_vote_threshold' => 1,
            'community_return_supporter_cap_pct' => 100,
        ]);

        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'), 'status' => 'voting',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 10, 'cycle_id' => 1, 'slug' => 'cat-10', 'title' => 'Category',
        ]);
        DB::table('gates_nominees')->insert([
            'id' => 7, 'category_id' => 10, 'name' => 'A Nominee', 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => 40, 'organic_vote_count' => 0,
        ]);

        $placed = Carbon::now()->subMinutes(10)->toDateTimeString();
        $donation = (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'A Supporter', 'donor_email' => 'buyer@example.test',
            'amount_naira' => 10000, 'tier' => 'paid-vote', 'status' => 'confirmed',
            'intent_nominee_id' => 7, 'bonus_votes' => 0, 'votes_used' => 40,
            'created_at' => $placed,
        ]);

        // The operator switches vote sales off AFTER the money arrived.
        $this->settings(['paid_voting_enabled' => '']);
        $this->assertFalse(CommunityReturnService::active(), 'fixture did not take');

        $out = CommunityReturnService::accrue($donation);

        $this->assertTrue($out['ok'],
            'a confirmed contribution stopped accruing because a toggle was flipped '
            . 'after it was paid — refused with: ' . $out['code']);
        $this->assertSame(500000, $out['kobo'], '50% of ₦10,000, in kobo');
    }

    // ══ the article says the same thing the page does ════════════════════════

    /**
     * ONE STATE, ONE NOTE.
     *
     * The article carries a `when` note for each state. They are mutually exclusive, and
     * help-article.twig renders a flat list of blocks with no notion of settings — so an
     * unfiltered body would print "this is not running on this site" directly above "it
     * is 50% today".
     */
    public function test_the_help_article_carries_only_the_note_that_applies(): void
    {
        $this->settings(['paid_voting_enabled' => '']);
        $this->rate(5000);

        $off = (string) json_encode(HelpCentre::bySlug('the-community-return')['body']);
        $this->assertStringContainsString('not running on this site', $off);
        $this->assertStringNotContainsString('currently set to <strong>0%', $off,
            'both conditional notes rendered, so the article contradicts itself');

        $this->settings(['paid_voting_enabled' => '1']);
        $on = (string) json_encode(HelpCentre::bySlug('the-community-return')['body']);
        $this->assertStringNotContainsString('not running on this site', $on,
            'the article tells a nominee they earn nothing on a site that sells votes');

        // And no `when` key survives into the body: help-article.twig would render such
        // a block as nothing at all, silently dropping prose somebody wrote.
        foreach (HelpCentre::bySlug('the-community-return')['body'] as $b) {
            $this->assertArrayNotHasKey('when', $b);
        }
    }

    /**
     * AND THE ASSISTANT READS THE SAME FILTERED PROSE.
     *
     * Most callers of {@see HelpCentre::plainText()} pass a RAW article straight out of
     * search(), which does not resolve. Without filtering there too, the support
     * assistant ingests every branch at once and can answer "the community return is not
     * running on this site" on a site where it is — the one place a wrong answer is
     * generated rather than merely displayed.
     */
    public function test_the_model_never_reads_the_branch_that_does_not_apply(): void
    {
        $this->settings(['paid_voting_enabled' => '1']);
        $this->rate(5000);

        // The RAW article, exactly as search() hands it over — unresolved, with every
        // branch still present. Reached through a public reader rather than the private
        // corpus, because that is the shape every real caller passes in.
        $raw = null;
        foreach (HelpCentre::all() as $a) {
            if ($a['slug'] === 'the-community-return') { $raw = $a; break; }
        }
        $this->assertNotNull($raw, 'the article has been renamed');
        $raw['body'][] = ['when' => 'no_paid_voting',
                          'note' => 'not running on this site (unfiltered branch)'];

        $this->assertStringNotContainsString('not running on this site',
            HelpCentre::plainText($raw),
            'the assistant is being fed a branch that is false on this deployment');
    }
}
