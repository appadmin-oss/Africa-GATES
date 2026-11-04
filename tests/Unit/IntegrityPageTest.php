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

    // ── the page answers first, and its tables hold their shape ──────────────

    /**
     * "In short" leads, and its figures are the engine's: change the split and the quorum and
     * the summary moves with them. A summary typed as prose is the shape this page exists to
     * avoid — the first thing a reader sees disagreeing with the table they scroll to.
     */
    public function test_the_page_opens_with_a_summary_read_from_the_engine(): void
    {
        (new RuleEngine())->set('global', null, ['community_weight' => 0.30, 'judge_weight' => 0.70, 'min_judges_per_nominee' => 4]);
        $html = $this->page();

        preg_match_all('~<section class="dp-sec" id="([a-z]+)"~', $html, $m);
        $this->assertSame('short', $m[1][0] ?? null, 'the summary is the first section');
        $this->assertSame(['why', 'more'], array_slice($m[1], -2), 'the argument for the method comes after the rules, then the reading list');
        // Help articles are gathered at the end, once each, not closing every section.
        $this->assertSame(1, substr_count($html, 'class="dp-more"'));
        $this->assertStringNotContainsString('class="dp-refs"', $html, 'no per-section link list on this page');
        $this->assertSame(1, substr_count($html, '>I think a count or a ranking is wrong</a>'), 'an article two sections cite is listed once');

        $this->assertStringContainsString('30% of the score is verified community support and 70% is an independent judge panel', $html);
        $this->assertStringContainsString('complete scorecards from at least 4 judges', $html);
    }

    /**
     * Two faults in the shared document stylesheet, both on every /integrity, /philosophy and
     * policy table at desktop width: the phone rule `.dp-rich tbody th { padding:0 }` out-ranked
     * the desktop `.dp-rich th`, so every row label sat flush to the edge and above its row; and
     * `white-space:nowrap` on that label let one long name push the table wider than its column,
     * where the table's own overflow:hidden cut the other columns off mid-word.
     */
    public function test_a_document_table_pads_and_wraps_its_row_labels(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/doc.css');
        $at  = strpos($css, '@media (min-width:600px)');
        $this->assertNotFalse($at);
        $wide = substr($css, $at, (int) strpos($css, "\n}\n", $at) - $at);

        $this->assertMatchesRegularExpression('~\.dp-rich tbody th[^{]*\{[^}]*padding:[1-9][0-9]*px [1-9][0-9]*px~', $wide,
            'the row label needs its padding named at a specificity the phone rule cannot beat');
        preg_match_all('~\.dp-table tbody th\{([^}]*)\}~', $wide, $rules);
        $this->assertNotEmpty($rules[1]);
        foreach ($rules[1] as $body) {
            $this->assertStringNotContainsString('nowrap', $body, 'a long row label must wrap, not push the table off the page');
        }
    }
}
