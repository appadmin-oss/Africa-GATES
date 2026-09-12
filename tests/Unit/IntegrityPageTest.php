<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use AfricaGates\Services\HelpCentre;
use AfricaGates\Services\RuleEngine;

/**
 * The methodology page must describe the system that is actually running.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * /integrity is the page a disputed result gets argued against. Everything on it
 * is a PUBLISHED CLAIM: the community/judge split, the ceiling on purchased votes,
 * the number of judges needed to win, the risk bands, the community-return share.
 *
 * Every one of those is a RuleEngine setting an operator can change per programme
 * and per cycle. When they were prose, the page and the engine could diverge with
 * nothing in the system able to notice — and the failure mode is not a broken page,
 * it is a page that confidently states a rule the platform stopped following. That
 * is worse than no page, because a reader cannot tell.
 *
 * So the tests here are not about markup. They set a rule to something deliberately
 * unlike the default and assert the published claim moved with it.
 *
 * ── AND THE SAME FOR THE ARTICLES ────────────────────────────────────────────
 *
 * The page was split: it now summarises and links to Help Centre deep dives. That
 * creates a second way to be wrong — the summary tracking the engine while the
 * article it sends you to quotes a number somebody typed last year. The articles
 * resolve from the same engine, and the test below proves it by changing the rule
 * and reading the article rather than the page.
 */
final class IntegrityPageTest extends TestCase
{
    /** Render GET /integrity through the real container, router and Twig. */
    private function page(): string
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/integrity');
        return (string) $app->handle($req)->getBody();
    }

    /** @return list<string> every static /help/<slug> the template hardcodes */
    private function helpSlugsOnThePage(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/integrity.twig');
        preg_match_all("#(?:href=\"/help/|slug:')([a-z0-9-]+)#", $src, $m);
        return array_values(array_unique($m[1]));
    }

    // ── the doors have to lead somewhere ─────────────────────────────────────

    /**
     * A summary that links out is only better than a wall of text if the links
     * work. A dead /help/ link resolves at the ROUTER — `/help/{slug}` matches any
     * string — so SiteLinkIntegrityTest cannot catch this one: the 404 comes from
     * the corpus, not the route table.
     */
    public function test_every_article_the_page_links_to_actually_exists(): void
    {
        $slugs = $this->helpSlugsOnThePage();

        $this->assertGreaterThan(10, count($slugs),
            'the page is supposed to be a summary with doors — it has almost no links');

        foreach ($slugs as $slug) {
            $this->assertNotNull(HelpCentre::bySlug($slug),
                "/integrity links to /help/{$slug}, which is not an article");
        }
    }

    /** And the reverse: the deep dives are reachable, i.e. they are in a real category. */
    public function test_the_new_deep_dives_are_filed_where_a_reader_will_look(): void
    {
        $inResults = array_column(HelpCentre::inCategory('results'), 'slug');

        foreach ([
            'why-a-small-category-is-not-a-disadvantage',
            'why-the-leader-may-not-be-eligible-to-win',
            'what-the-judges-actually-score',
            'how-we-spot-a-vote-that-is-not-real',
            'what-happens-if-two-nominees-tie',
            'how-results-are-sealed',
            'votes-we-could-not-deliver',
            'the-community-return',
            'the-stages-of-an-award-cycle',
        ] as $slug) {
            $this->assertContains($slug, $inResults,
                "{$slug} is not in Results & integrity, so nobody browsing will find it");
        }
    }

    // ── the numbers must follow the engine ───────────────────────────────────

    /**
     * THE REGRESSION THIS FILE IS REALLY FOR.
     *
     * "45% public + 55% judges" was a sentence somebody typed. Set the weights to
     * 30/70 and the page must say 30/70 — and must no longer say 45.
     */
    public function test_the_published_split_is_the_split_the_scorer_uses(): void
    {
        (new RuleEngine())->set('global', null, [
            'community_weight'        => 0.30,
            'judge_weight'            => 0.70,
            'max_paid_weight_pct'     => 25,
            'min_judges_per_nominee'  => 4,
        ]);

        $html = $this->page();

        $this->assertStringContainsString('30% community', $html);
        $this->assertStringContainsString('70% judges', $html);
        // Still published from the engine, and now with the framing corrected: this
        // ceiling governs BONUS votes minted against a contribution — `BonusVoteService`
        // is the only thing that reads it — and not vote packs bought on the ballot,
        // which have none. It stopped being a ranking guarantee when the index started
        // counting every vote; a page that went on describing it as one would be quoting
        // a live number to support a claim it does not support.
        $this->assertStringContainsString('25% of the organic support', $html,
            'the contribution ceiling is published from the engine');
        $this->assertStringContainsString('have no such limit', $html,
            'the page publishes a ceiling without saying which votes it does not cover');
        $this->assertStringContainsString('4 judges to have scored them', $html,
            'the winner-eligibility quorum is published from the engine');

        $this->assertStringNotContainsString('45% community', $html,
            'the page is still quoting the default split after it was overridden');
    }

    /**
     * The risk bands are configuration too, and the bands must be contiguous.
     *
     * This used to also assert four `width:NN%` segments, because the bands were a
     * proportional bar and the template computed each segment as a subtraction —
     * `fraud_flag - fraud_monitor` and so on. Get one of those wrong and the bar
     * silently overflowed or left a gap that read as a fifth band, so the widths
     * were worth pinning.
     *
     * The bands are a table now, which removes that arithmetic entirely: each row
     * prints its own two bounds. The contiguity assertions below are therefore the
     * whole test, and they are stronger than they look — each band's upper bound is
     * the next band's lower bound, so a gap or an overlap cannot produce this
     * sequence of strings.
     */
    public function test_the_risk_bands_are_drawn_from_the_configured_thresholds(): void
    {
        (new RuleEngine())->set('global', null, [
            'fraud_monitor' => 20, 'fraud_flag' => 50, 'fraud_block' => 90,
        ]);

        $html = $this->page();

        $this->assertStringContainsString('0–20', $html);
        $this->assertStringContainsString('20–50', $html);
        $this->assertStringContainsString('50–90', $html);
        $this->assertStringContainsString('90+', $html);

        // And the defaults are genuinely gone, so this cannot pass against a
        // template that printed them.
        $this->assertStringNotContainsString('0–30', $html);
        $this->assertStringNotContainsString('80+', $html);
    }

    /**
     * The community return is the newest claim and the one with money attached.
     * Basis points must reach the reader as a percentage, without a trailing zero.
     */
    public function test_the_community_return_share_is_published_from_basis_points(): void
    {
        (new RuleEngine())->set('global', null, [
            'community_return_bps'               => 1250,
            'community_return_vote_threshold'    => 400,
            'community_return_supporter_cap_pct' => 20,
        ]);

        $html = $this->page();

        $this->assertStringContainsString('12.5%', $html);
        $this->assertStringNotContainsString('12.50%', $html, 'a rule is not a measurement');
        $this->assertStringContainsString('400 votes of qualifying support', $html);
        // 20% of 400, and the people floor DERIVED from that ceiling rather than
        // configured beside it — 100/20 = 5.
        $this->assertStringContainsString('80', $html, 'the per-supporter ceiling in votes');
        $this->assertStringContainsString('5 different verified', $html);
    }

    /** A whole-number share must not arrive as "30.0%". */
    public function test_a_whole_number_share_reads_as_a_whole_number(): void
    {
        (new RuleEngine())->set('global', null, ['community_return_bps' => 3000]);

        $html = $this->page();

        $this->assertStringContainsString('30%', $html);
        $this->assertStringNotContainsString('30.0%', $html);
    }

    // ── page and article must not drift ──────────────────────────────────────

    /**
     * THE POINT OF SPLITTING THE PAGE, PROVED.
     *
     * The summary sends a reader to an article for the detail. If the article
     * remembers its numbers instead of reading them, the deep dive contradicts the
     * summary that sent them there — and the deep dive is the one they will quote
     * back at us.
     */
    public function test_the_deep_dives_quote_the_same_engine_as_the_page(): void
    {
        (new RuleEngine())->set('global', null, [
            'community_weight'                => 0.30,
            'judge_weight'                    => 0.70,
            'max_paid_weight_pct'             => 25,
            'min_judges_per_nominee'          => 4,
            'community_return_bps'               => 1250,
            'community_return_vote_threshold'    => 400,
            'community_return_supporter_cap_pct' => 20,
        ]);

        $quorum = HelpCentre::bySlug('why-the-leader-may-not-be-eligible-to-win');
        $this->assertNotNull($quorum);
        $this->assertStringContainsString('4 judges', HelpCentre::plainText($quorum));

        $criteria = HelpCentre::bySlug('what-the-judges-actually-score');
        $this->assertNotNull($criteria);
        $this->assertStringContainsString('70%', HelpCentre::plainText($criteria));

        $paid = HelpCentre::bySlug('what-paid-votes-do');
        $this->assertNotNull($paid);
        $this->assertStringContainsString('25%', HelpCentre::plainText($paid));

        $return = HelpCentre::bySlug('the-community-return');
        $this->assertNotNull($return);
        $text = HelpCentre::plainText($return);
        $this->assertStringContainsString('12.5%', $text);
        $this->assertStringContainsString('400 votes of qualifying support', $text);
        $this->assertStringContainsString('80', $text, '20% of 400, the per-supporter ceiling');
        $this->assertStringContainsString('5 different verified people', $text);
    }

    /**
     * Prove the guard bites: with no override at all, both surfaces report the code
     * default. A test that only ever asserts the overridden value would still pass
     * against a template that hardcoded it.
     */
    public function test_with_no_override_both_surfaces_report_the_code_default(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('45% community', $html);
        $this->assertStringContainsString('55% judges', $html);

        $a = HelpCentre::bySlug('why-the-leader-may-not-be-eligible-to-win');
        $this->assertNotNull($a);
        $this->assertStringContainsString('2 judges', HelpCentre::plainText($a));
    }
}
