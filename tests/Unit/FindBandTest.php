<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\ActivityController;
use AfricaGates\Services\ActivityFeedService;
use DI\ContainerBuilder;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * "WHO ARE YOU LOOKING FOR?" — the band, and the two claims printed on it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A BAND NEEDS A TEST AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Because it makes two statements about the platform, in the reader's own language,
 * on the homepage — and this repository's most expensive documented failures are
 * exactly that: a true sentence outliving the rule it described.
 *
 * `/cookies` said in bold "We set one cookie" while three were being set and "We run
 * no analytics" while every arrival's source, campaign, device and country was being
 * recorded. Both were true the day they were typed. A search box that says what it
 * searches is the same document making the same kind of claim, and it will go stale
 * the same way — the day somebody adds a source, or removes one.
 *
 * So the coverage sentence is GENERATED from `ActivityFeedService::SOURCES` and this
 * file asserts the generation, not the wording. The test moves when the rule moves.
 *
 * ── AND ONE SENTENCE IS A GUARANTEE RATHER THAN A DESCRIPTION ───────────────
 *
 * "Nothing unannounced is searchable" is the platform's central promise, printed
 * where a stranger reads it. {@see UnannouncedResultTest} is the evidence for it;
 * this file only holds that the claim and the evidence stay on the same page as each
 * other — a promise with no test behind it should not be printed, and a gate with
 * nothing saying so is a courtesy nobody knows they have.
 */
final class FindBandTest extends TestCase
{
    /** The band as the activity surface renders it — the live, opted-in variant. */
    private function render(): string
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $c = $builder->build();

        $controller = new ActivityController($c->get(Twig::class), new ActivityFeedService());
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/activity');

        return (string) $controller->index($req, new Response())->getBody();
    }

    private function partial(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/partials/find-band.twig');
    }

    // ── What it says it covers ───────────────────────────────────────────────

    public function test_every_named_source_appears_in_the_sentence(): void
    {
        $html = $this->render();

        foreach (ActivityFeedService::nouns() as $noun) {
            $this->assertStringContainsString($noun, $html,
                "the band does not tell a reader it searches '{$noun}', and it does");
        }
    }

    public function test_the_sentence_is_not_typed_into_the_template(): void
    {
        // The whole mechanism. If the nouns were spelled in the Twig file, adding a
        // source would leave the band describing the previous platform — and nothing
        // would fail, which is how the cookie policy stayed wrong for months.
        $body = $this->partial();

        foreach (ActivityFeedService::nouns() as $noun) {
            $this->assertStringNotContainsString($noun, $body,
                "'{$noun}' is written into find-band.twig. It must come from "
              . 'ActivityFeedService::SOURCES through search_covers(), or the sentence '
              . 'stops being true the moment the source list changes.');
        }

        $this->assertStringContainsString('search_covers()', $body,
            'the band must generate its coverage sentence');
    }

    public function test_a_source_with_no_public_noun_is_still_covered_by_the_sentence(): void
    {
        // Several sources are deliberately unnamed — ten nouns is not a sentence anybody
        // reads. They are covered by a closing clause instead, and that clause is what
        // keeps the omission honest rather than a quiet under-claim.
        $unnamed = array_filter(
            ActivityFeedService::SOURCES,
            static fn (array $s): bool => ($s['noun'] ?? null) === null,
        );

        $this->assertNotSame([], $unnamed, 'this test is vacuous if every source is named');
        $this->assertStringContainsString('and everything else published here', $this->render(),
            'sources are searched that the sentence neither names nor admits to');
    }

    // ── The promise ──────────────────────────────────────────────────────────

    public function test_the_promise_is_printed(): void
    {
        $this->assertStringContainsString('Nothing unannounced is searchable', $this->render());
    }

    public function test_the_promise_has_a_test_behind_it(): void
    {
        // A claim this load-bearing may not rest on a gate somebody might refactor out.
        // Named by file rather than by re-testing the behaviour here: two tests with
        // their own idea of what "announced" means is how they come to disagree.
        $this->assertFileExists(dirname(__DIR__) . '/Unit/UnannouncedResultTest.php',
            'the band promises nothing unannounced is searchable and nothing proves it');
    }

    // ── The things that fail silently ────────────────────────────────────────

    public function test_the_live_search_hooks_survive_on_the_search_surface(): void
    {
        // The activity page's script upgrades this form into an ARIA combobox, and it
        // bails out QUIETLY when it cannot find its hooks (`if (!form || !input) return`).
        // When the band replaced that page's own field, dropping these two attributes
        // would have cost the as-you-type search with nothing failing anywhere and
        // nothing in the console — the search would simply have stopped being live.
        // ── ASSERTED ON THE ELEMENT, NOT ON THE PAGE ────────────────────────
        //
        // This test first read `assertStringContainsString('data-act-form', $html)` and
        // it was VACUOUS: the page's own inline script contains the SELECTOR STRING
        // `document.querySelector('[data-act-form]')`, so the assertion matched the code
        // that looks for the hook rather than the hook. Deleting both attributes from the
        // template left this passing — proven by doing it — which is the precise failure
        // the test was written to prevent, in the test written to prevent it.
        //
        // So: match the attribute inside a <form> tag, and the input's inside an <input>.
        $html = $this->render();

        $this->assertMatchesRegularExpression('~<form[^>]*\bdata-act-form\b[^>]*>~', $html,
            'the search form lost the hook the live-search script binds to — the script '
          . 'returns quietly when it cannot find it, so the as-you-type search would '
          . 'simply stop existing with nothing failing and nothing in the console');
        $this->assertMatchesRegularExpression('~<input[^>]*\bdata-act-input\b[^>]*>~', $html,
            'the search input lost the hook the live-search script binds to');
    }

    public function test_the_hooks_are_opt_in_so_they_do_not_appear_where_no_script_reads_them(): void
    {
        // The homepage has no such script. An attribute there would be a hook to nothing
        // — harmless today and exactly the sort of thing somebody later reads as evidence
        // that a live search exists on the homepage.
        $body = $this->partial();

        $this->assertStringContainsString("live|default(false)", $body,
            'the combobox hooks must be opt-in per include');
    }

    public function test_the_field_posts_to_the_search_that_already_exists(): void
    {
        // Not to a second endpoint. The whole reason this is a band and not a new page
        // is that `/help` and `/support` had just been merged for being two front doors
        // to one job; building `/search` with its own service would have repeated it.
        $this->assertStringContainsString('action="/activity"', $this->partial());
    }

    public function test_no_other_search_entrance_enumerates_the_sources(): void
    {
        // There are three ways into this search — the band, the header dialog, and the
        // activity page — and exactly ONE of them may claim what it covers, because only
        // one of them generates the claim.
        //
        // The header dialog's field was labelled "Search nominees, events, posts and
        // pages". Ten sources, four named, on the one string a screen reader announces as
        // that field's name — and it had already drifted past announced results,
        // categories, verified organisations, profiles, discussions and cycle phases. The
        // fix was not to add the other six. A coverage claim belongs where it is derived.
        //
        // Asserted on the NOUNS, so this fails when somebody re-enumerates with today's
        // list rather than only when they restore the old wording.
        $dialog = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/partials/site-search.twig');

        // Comments reach no reader, but they go stale the same way and this one did.
        $visible = (string) preg_replace('/\{#.*?#\}/s', '', $dialog);

        foreach (ActivityFeedService::nouns() as $noun) {
            $this->assertStringNotContainsString($noun, $visible,
                "the header search dialog names '{$noun}' as something it covers. Only "
              . 'the find band may make that claim, because only the find band generates '
              . 'it from ActivityFeedService::SOURCES.');
        }
    }

    public function test_the_field_carries_a_hidden_label(): void
    {
        // The visible label is the heading above the field (see the accessibility test
        // on the activity surface); this is the programmatic one, and both are required.
        //
        // NOT also asserting the class is spelled right. `SrOnlyClassTest` sweeps every
        // template for a screen-reader class that does not exist — including this one —
        // and a second test asking the same question here would be two tests with their
        // own idea of the answer. It would also have FAILED: the comment beside the
        // label in this band names the wrong class in order to warn about it, and a bare
        // string search cannot tell a warning from an offence. That sweep strips
        // comments; this one would not have.
        $this->assertStringContainsString('class="sr-only"', $this->partial());
        $this->assertMatchesRegularExpression('~<label[^>]+for="fbQ"~', $this->partial());
    }
}
