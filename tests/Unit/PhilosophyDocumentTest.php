<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use AfricaGates\Services\CommunityVotingPhilosophy as Doc;
use AfricaGates\Services\RuleEngine;

/**
 * The philosophy document is a PUBLISHED POSITION, and it is downloadable.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO WAYS THIS CAN GO WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. **The prose remembers a number the engine has changed.** The document states
 *    what share of the outcome the public vote carries, and what share of a
 *    contribution a nominee keeps. Both are RuleEngine settings an operator can
 *    change per programme and per cycle. A typed "45%" would be a claim with
 *    nothing keeping it true — and this is the document a disputed result gets
 *    argued against, so a stale figure is not a cosmetic bug.
 *
 *    `test_no_section_hardcodes_a_figure` is the structural guard: it reads the
 *    UNRESOLVED prose and fails if a percentage was typed instead of tokenised.
 *    The behavioural guards override the engine and read the output.
 *
 * 2. **The download disagrees with the page it came from.** Copy, Download (.md),
 *    Download (.txt) and the article are four renderings of one document. If they
 *    are four copies of the prose, three of them are always out of date. They are
 *    not: all four render from {@see Doc::sections()}. The tests below prove that
 *    by changing a rule and reading all of them.
 */
final class PhilosophyDocumentTest extends TestCase
{
    private const URL = 'https://africagates.test/integrity';

    /** Figures the way the route builds them, from whatever the engine currently says. */
    private function figures(): array
    {
        $rules = new RuleEngine();
        $w     = $rules->weights();
        $eff   = $rules->effective();
        $ret   = \AfricaGates\Services\CommunityReturnService::displayRules($eff);

        return [
            'community_pct'    => (int) round($w['community'] * 100),
            'judge_pct'        => (int) round($w['judge'] * 100),
            'paid_cap_pct'     => (int) ($eff['max_paid_weight_pct'] ?? 50),
            'min_judges'       => (int) ($eff['min_judges_per_nominee'] ?? 2),
            'return_pct'       => $ret['pct'],
            'return_threshold' => $ret['threshold'],
            'return_cap_pct'   => $ret['cap_pct'],
            'return_people'    => $ret['min_supporters'],
        ];
    }

    /** @return array{0:int,1:string,2:\Psr\Http\Message\ResponseInterface} */
    private function get(string $path): array
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $res = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        return [$res->getStatusCode(), (string) $res->getBody(), $res];
    }

    // ── 1 · the structural guard ─────────────────────────────────────────────

    /**
     * THE REGRESSION THIS FILE IS REALLY FOR.
     *
     * Read the prose with NO figures supplied. Every place a number belongs must
     * still be a `{token}`. If somebody edits the copy and types "45%" over the
     * placeholder, this fails — before the page can start lying.
     *
     * Bare "100%" is allowed: "public votes do not constitute 100% of the decision"
     * is a fixed rhetorical whole, not a setting, and tokenising it would invite an
     * operator to change a sentence that is not about configuration.
     */
    public function test_no_section_hardcodes_a_figure(): void
    {
        $offenders = [];

        foreach (Doc::sections() as $sec) {
            $blob = $sec['title'] . ' ' . json_encode($sec['blocks']);
            // Any percentage that is not 100%, and not part of a {token}.
            preg_match_all('/(?<![\d{])(\d{1,3}(?:\.\d+)?)\s?%/', $blob, $m);
            foreach ($m[1] as $found) {
                if ($found !== '100') $offenders[] = $sec['id'] . ' → ' . $found . '%';
            }
        }

        $this->assertSame([], $offenders,
            "the philosophy prose has a typed percentage where it needs a {token}: "
            . implode(', ', $offenders));
    }

    /** And the tokens that must be there really are, so the guard above has teeth. */
    public function test_the_prose_carries_the_tokens_it_needs(): void
    {
        $blob = json_encode(Doc::sections());

        foreach (['{community_pct}', '{judge_pct}', '{return_pct}', '{return_threshold}'] as $token) {
            $this->assertStringContainsString($token, (string) $blob,
                "{$token} is never used — a figure it should drive is probably typed in");
        }
    }

    /** An unknown token is left visible rather than blanked. A gap reads as finished copy. */
    public function test_an_unresolved_token_stays_visible(): void
    {
        $blob = json_encode(Doc::sections(['community_pct' => 45]));

        $this->assertStringNotContainsString('{community_pct}', (string) $blob, 'this one resolves');
        $this->assertStringContainsString('{judge_pct}', (string) $blob,
            'an unsupplied token must survive to the page, not vanish into a blank');
    }

    // ── 2 · the figures follow the engine, in every rendering ────────────────

    public function test_the_article_publishes_the_engines_split_not_the_default(): void
    {
        (new RuleEngine())->set('global', null, [
            'community_weight' => 0.30, 'judge_weight' => 0.70,
        ]);

        [$status, $html] = $this->get('/integrity');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('limited to 30%', $html,
            'the standfirst states the live public-vote share');
        $this->assertStringContainsString('Public voting accounts for only 30%', $html);
        $this->assertStringContainsString('represents <strong>30% of the', $html);
        $this->assertStringNotContainsString('only 45%', $html,
            'the philosophy is still quoting the default after it was overridden');
    }

    public function test_the_plain_text_edition_tracks_the_engine_too(): void
    {
        (new RuleEngine())->set('global', null, [
            'community_weight' => 0.30, 'judge_weight' => 0.70,
        ]);

        [$status, $body] = $this->get('/integrity.txt');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('30% of the overall assessment', $body);
        $this->assertStringNotContainsString('45% of the overall assessment', $body);
    }

    /**
     * THE ANTI-DRIFT PROMISE, STATED AS A TEST.
     *
     * Whatever the community share is, the article and both downloads must all
     * quote it. Three renderings, one number, checked together — because the
     * failure this guards against is not "the number is wrong" but "the number is
     * different depending on which one you read".
     */
    public function test_article_markdown_and_text_agree_on_the_share(): void
    {
        (new RuleEngine())->set('global', null, [
            'community_weight' => 0.40, 'judge_weight' => 0.60,
            'community_return_bps' => 1250,
        ]);

        [, $html] = $this->get('/integrity');
        [, $md]   = $this->get('/integrity.md');
        [, $txt]  = $this->get('/integrity.txt');

        foreach (['article' => $html, 'markdown' => $md, 'text' => $txt] as $what => $body) {
            $this->assertStringContainsString('40%', $body, "the {$what} edition lost the community share");
            $this->assertStringContainsString('60%', $body, "the {$what} edition lost the judge share");
            $this->assertStringContainsString('12.5%', $body, "the {$what} edition lost the return share");
            $this->assertStringNotContainsString('45%', $body, "the {$what} edition still shows the default");
        }
    }

    // ── 3 · the downloads are downloads ─────────────────────────────────────

    public function test_markdown_download_is_served_as_an_attachment(): void
    {
        [$status, $body, $res] = $this->get('/integrity.md');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('text/markdown', $res->getHeaderLine('Content-Type'));
        // Without this the browser renders the Markdown inline and the Download
        // button downloads nothing.
        $this->assertStringContainsString('attachment', $res->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString(Doc::fileStem() . '.md', $res->getHeaderLine('Content-Disposition'));

        $this->assertStringStartsWith('# ' . Doc::TITLE, $body);
        $this->assertStringContainsString('## 1. ', $body, 'sections must be numbered headings');
        $this->assertStringContainsString('```bibtex', $body, 'the BibTeX entry needs a fenced block');
    }

    public function test_plain_text_download_is_served_as_an_attachment(): void
    {
        [$status, $body, $res] = $this->get('/integrity.txt');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('text/plain', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment', $res->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString(Doc::fileStem() . '.txt', $res->getHeaderLine('Content-Disposition'));

        $this->assertStringContainsString(strtoupper(Doc::TITLE), $body);
        $this->assertStringContainsString('HOW TO CITE', $body);
    }

    /**
     * The prose is authored for HTML, with entities. A clipboard or a .txt holding a
     * literal `&rsquo;` is a broken paste, so the text renderings must decode before
     * they strip.
     */
    public function test_no_rendering_leaks_html_or_entities(): void
    {
        $figs = $this->figures();

        foreach (['text' => Doc::plainText($figs, self::URL), 'markdown' => Doc::markdown($figs, self::URL)] as $what => $body) {
            $this->assertStringNotContainsString('&rsquo;', $body, "{$what} leaked an entity");
            $this->assertStringNotContainsString('&mdash;', $body, "{$what} leaked an entity");
            $this->assertStringNotContainsString('&ldquo;', $body, "{$what} leaked an entity");
            $this->assertStringNotContainsString('<strong>', $body, "{$what} leaked a tag");
            $this->assertStringNotContainsString('<em>', $body, "{$what} leaked a tag");
            // The curly forms the entities decode to, on the other hand, should be there.
            $this->assertStringContainsString('’', $body, "{$what} lost its apostrophes entirely");
        }
    }

    /** A .txt nobody can read in a terminal is not a plain-text edition. */
    public function test_the_text_edition_is_wrapped(): void
    {
        $body = Doc::plainText($this->figures(), self::URL);

        foreach (explode("\n", $body) as $i => $line) {
            $this->assertLessThanOrEqual(80, mb_strlen($line),
                'line ' . ($i + 1) . ' of the .txt edition is unwrapped: ' . $line);
        }
    }

    // ── 4 · citation ────────────────────────────────────────────────────────

    public function test_every_citation_carries_the_version_and_the_access_date(): void
    {
        $cites = Doc::citations(self::URL, '2026-08-08');

        $this->assertCount(4, $cites, 'APA, MLA, Chicago and BibTeX');

        foreach ($cites as $c) {
            $this->assertNotSame('', $c['label']);
            $this->assertStringContainsString(self::URL, $c['text'], "{$c['id']} omits the URL");
            $this->assertStringContainsString(Doc::VERSION, $c['text'], "{$c['id']} omits the version");
        }

        $byId = array_column($cites, 'text', 'id');
        $this->assertStringContainsString('8 August 2026', $byId['apa'], 'APA wants a retrieval date');
        $this->assertStringContainsString('Accessed 8 Aug. 2026', $byId['mla']);
        $this->assertStringContainsString('@misc{africagates2026philosophy', $byId['bibtex']);
        $this->assertStringContainsString('urldate      = {2026-08-08}', $byId['bibtex']);
    }

    /**
     * The access date is a parameter, not a `date()` call inside the formatter.
     * Three surfaces render citations for one request; if each asked the clock
     * separately they could straddle midnight and disagree.
     */
    public function test_the_access_date_is_injectable(): void
    {
        $cites = array_column(Doc::citations(self::URL, '1999-12-31'), 'text', 'id');

        $this->assertStringContainsString('31 December 1999', $cites['apa']);
        $this->assertStringContainsString('urldate      = {1999-12-31}', $cites['bibtex']);
    }

    public function test_the_article_offers_all_four_citation_formats_and_the_metadata(): void
    {
        [, $html] = $this->get('/integrity');

        foreach (['APA (7th edition)', 'MLA (9th edition)', 'Chicago (17th edition, note)', 'BibTeX'] as $label) {
            $this->assertStringContainsString($label, $html, "the cite panel is missing {$label}");
        }

        // What Zotero and Google Scholar actually read.
        $this->assertStringContainsString('name="citation_title"', $html);
        $this->assertStringContainsString('name="citation_author"', $html);
        $this->assertStringContainsString('name="citation_publication_date"', $html);
        $this->assertStringContainsString('name="DC.identifier"', $html);
        $this->assertStringContainsString('"@type":"Article"', $html);
        $this->assertStringContainsString('"version":"' . Doc::VERSION . '"', $html);
    }

    // ── 5 · the article is whole ─────────────────────────────────────────────

    /** Every section must be reachable from the contents, or the document has a dead limb. */
    public function test_every_section_is_rendered_and_linked_from_the_contents(): void
    {
        [, $html] = $this->get('/integrity');

        foreach (Doc::sections($this->figures()) as $sec) {
            $this->assertStringContainsString('id="' . $sec['id'] . '"', $html,
                "section {$sec['id']} is not on the page");
            $this->assertStringContainsString('href="#' . $sec['id'] . '"', $html,
                "section {$sec['id']} is not in the contents");
        }
    }

    /**
     * The disclaimer is the one part of this document with legal weight, and the one
     * a reader is most likely to have been sent here specifically to find.
     */
    public function test_the_anti_pyramid_disclaimer_survives_every_rendering(): void
    {
        $figs = $this->figures();
        [, $html] = $this->get('/integrity');

        foreach ([
            'article'  => $html,
            'text'     => Doc::plainText($figs, self::URL),
            'markdown' => Doc::markdown($figs, self::URL),
        ] as $what => $body) {
            $this->assertStringContainsString('Ponzi scheme', $body, "{$what} lost the disclaimer");
            $this->assertStringContainsString('pyramid scheme', $body, "{$what} lost the disclaimer");
            $this->assertStringContainsString('multi-level marketing', $body, "{$what} lost the disclaimer");
            $this->assertStringContainsString(
                'not promised financial returns for recruiting', $body,
                "{$what} lost the recruitment clause"
            );
        }
    }

    /** The header states a reading time; it has to come from the words, not a guess. */
    public function test_reading_time_is_counted_from_the_document(): void
    {
        $mins = Doc::readMinutes($this->figures());

        $this->assertGreaterThanOrEqual(5, $mins, 'a ~2,000 word document is not a 4-minute read');
        $this->assertLessThan(30, $mins, 'that is not plausible for this document either');
    }

    // ── 6 · the article is navigable without a mouse ────────────────────────

    /**
     * The citation tabs use a roving tabindex — selected 0, the rest -1 — which is
     * correct for a tablist and means Tab enters the group once rather than stopping
     * four times. It also means the ARROW KEYS are the only way to reach the other
     * three formats. Ship the roving tabindex without the arrow handlers and a
     * keyboard user can see four citation formats and reach exactly one.
     */
    public function test_the_citation_tabs_are_reachable_by_keyboard(): void
    {
        [, $html] = $this->get('/integrity');

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('@keydown.arrow-right.prevent="moveTab(\'next\')"', $html,
            'the tablist has a roving tabindex but no way to move within it');
        $this->assertStringContainsString('@keydown.arrow-left.prevent="moveTab(\'prev\')"', $html);
        $this->assertStringContainsString(':tabindex="tab === ', $html, 'the roving tabindex is gone');

        // Each tab must own a panel, and each panel must name its tab.
        foreach (array_column($this->citeIds(), 0) as $id) {
            $this->assertStringContainsString('id="ar-tab-' . $id . '"', $html);
            $this->assertStringContainsString('aria-controls="ar-panel-' . $id . '"', $html);
            $this->assertStringContainsString('aria-labelledby="ar-tab-' . $id . '"', $html);
        }
    }

    /** @return list<array{0:string}> */
    private function citeIds(): array
    {
        return array_map(static fn (array $c): array => [$c['id']], Doc::citations(self::URL));
    }

    /**
     * The data that used to be bar charts is tabular now, which is only an
     * improvement if it is marked up as a table: a caption to say what it is, and a
     * scope on every header so a screen reader can announce the row it is reading.
     */
    public function test_the_data_tables_are_marked_up_for_a_screen_reader(): void
    {
        [, $html] = $this->get('/integrity');

        $tables = substr_count($html, '<table class="ar-table">');
        $this->assertGreaterThanOrEqual(4, $tables, 'the method part should present its data as tables');
        $this->assertSame($tables, substr_count($html, '<caption>'),
            'every data table needs a caption saying what it is');

        // No unscoped header cells anywhere in the document. The `(?=[\s>])` is what
        // stops this matching `<thead>`, which has no scope and needs none.
        preg_match_all('/<th(?=[\s>])(?![^>]*\bscope=)[^>]*>/', $html, $m);
        $this->assertSame([], $m[0], 'a <th> without scope leaves a screen reader guessing');
    }

    /** Version and dates are what make it citable. An empty one breaks every format. */
    public function test_the_document_is_versioned_and_dated(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', Doc::VERSION);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', Doc::PUBLISHED);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', Doc::UPDATED);
        $this->assertStringContainsString(Doc::VERSION, Doc::fileStem());
        $this->assertGreaterThanOrEqual(Doc::PUBLISHED, Doc::UPDATED, 'updated cannot precede published');
    }
}
