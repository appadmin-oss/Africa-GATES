<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\HelpCentre;
use AfricaGates\Services\SupportContext;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The written answers, and the two properties that make them worth having.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS ARE ACTUALLY DEFENDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A help centre fails in two ways, and neither is caught by "does the page load".
 *
 *   1. IT STOPS BEING TRUE. An article states a price, the admin changes the
 *      price, and now the platform confidently tells people the wrong number —
 *      worse than saying nothing, because the reader has no way to know. Every
 *      figure in the corpus is a placeholder resolved from live settings, and the
 *      tests below make bypassing that mechanism fail here.
 *
 *   2. NOBODY FINDS IT. The corpus can be perfect and still useless if searching
 *      the words people actually type returns nothing. Several articles are
 *      deliberately keyed on vocabulary that appears nowhere in their own prose
 *      — "debited", "OPay", "not reflecting" — and those routes are pinned one by
 *      one, because they are exactly the ones a careless edit silently removes.
 *
 * And one structural property: the assistant and the page must read the SAME
 * corpus. Two documents answering one question is how they start disagreeing.
 */
final class HelpCentreTest extends TestCase
{
    // ── it must stay true ────────────────────────────────────────────────────

    /**
     * No article may hardcode a figure a supporter could act on.
     *
     * This is the test that stops the corpus rotting. Writing "₦1,000 per vote"
     * into an article is a completely reasonable-looking edit that makes the
     * platform lie the next time somebody changes the setting.
     */
    public function test_no_article_hardcodes_a_price_or_a_deadline(): void
    {
        foreach (HelpCentre::articles() as $a) {
            $raw = json_encode($a, JSON_UNESCAPED_UNICODE) ?: '';

            $this->assertDoesNotMatchRegularExpression(
                '/₦\s?[\d,]{3,}/u', $raw,
                $a['slug'] . ' states a naira figure. Use {price} so it tracks the setting.'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\b\d{1,3}\s?minutes before\b/i', $raw,
                $a['slug'] . ' states a cutoff. Use {cutoff}.'
            );
        }
    }

    /** And the placeholders must actually resolve — an unswapped `{price}` is a bug on a page. */
    public function test_every_placeholder_is_resolved_before_it_reaches_a_reader(): void
    {
        foreach (HelpCentre::all() as $a) {
            $rendered = HelpCentre::plainText($a);
            $this->assertDoesNotMatchRegularExpression(
                '/\{[a-z_]+\}/', $rendered,
                $a['slug'] . ' still contains an unresolved placeholder'
            );
        }
    }

    /** The resolved numbers are the ones the checkout is using, not a copy. */
    public function test_the_price_in_an_article_is_the_price_the_checkout_charges(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'vote_price_naira'], ['value' => '2500']);

        $a = HelpCentre::bySlug('what-paid-votes-do');
        $this->assertNotNull($a);
        $this->assertStringContainsString('2,500', HelpCentre::plainText($a),
            'the article must quote the live setting, not a number written a year ago');
    }

    // ── people must find it ──────────────────────────────────────────────────

    /**
     * THE WORDS PEOPLE TYPE, NOT THE WORDS WE WROTE.
     *
     * Each of these is a real phrasing from the ticket queue, and several appear
     * nowhere in the body of the article they must reach. "Debited" is the clearest
     * case: the article about a missing payment never uses the word, and a
     * substring filter over rendered text — which is what the old page did — finds
     * nothing at all for it.
     */
    public function test_the_phrases_people_actually_use_reach_the_right_answer(): void
    {
        $expected = [
            'my money was debited but no votes'      => 'paid-but-no-votes',
            'votes not reflecting'                   => 'paid-but-no-votes',
            'i paid with opay'                       => 'wallet-app-reference',
            'i never got the code'                   => 'code-did-not-arrive',
            'it says i already voted'                => 'already-voted',
            'why cant i pay'                         => 'card-payment-closed-early',
            'can i get a refund'                     => 'refund-when-votes-cannot-count',
            'how is the winner decided'              => 'how-cpi-works',
            'someone is buying votes'                => 'dispute-a-result',
            'take my profile down'                   => 'remove-my-profile',
            'do you store my card details'           => 'is-my-card-safe',
            'why was my nomination rejected'         => 'nomination-rejected',
        ];

        foreach ($expected as $query => $slug) {
            $hits = HelpCentre::search($query, 3);
            $this->assertNotEmpty($hits, 'nothing at all matched: ' . $query);
            $this->assertSame($slug, $hits[0]['slug'],
                sprintf('“%s” should reach %s, reached %s', $query, $slug, $hits[0]['slug']));
        }
    }

    /** A keyword match must beat a passing mention in someone else's body text. */
    public function test_an_article_about_a_thing_outranks_one_that_merely_mentions_it(): void
    {
        $hits = HelpCentre::search('refund', 5);
        $this->assertSame('refund-when-votes-cannot-count', $hits[0]['slug']);
    }

    /** An empty or nonsense search returns nothing rather than a random article. */
    public function test_a_search_with_no_answer_says_so(): void
    {
        $this->assertSame([], HelpCentre::search(''));
        $this->assertSame([], HelpCentre::search('   '));
        $this->assertSame([], HelpCentre::search('qwertyuiop zxcvbnm'));
    }

    // ── structure ────────────────────────────────────────────────────────────

    public function test_every_article_is_well_formed_and_reachable(): void
    {
        $slugs = [];
        foreach (HelpCentre::articles() as $a) {
            $slugs[] = $a['slug'];

            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $a['slug'], 'slug must be URL-safe');
            $this->assertArrayHasKey($a['cat'], HelpCentre::CATEGORIES, $a['slug'] . ' has no real category');
            $this->assertNotEmpty($a['summary'], $a['slug'] . ' has no summary — it is the search result line');
            $this->assertNotEmpty($a['keywords'] ?? [], $a['slug'] . ' has no keywords, so nobody will find it');
            $this->assertNotEmpty($a['body'] ?? [], $a['slug'] . ' has no body');
        }

        $this->assertSame(count($slugs), count(array_unique($slugs)), 'two articles share a slug');
    }

    /** A related link pointing at nothing is a dead end inside the help centre itself. */
    public function test_no_related_link_points_at_a_missing_article(): void
    {
        $slugs = array_column(HelpCentre::articles(), 'slug');
        foreach (HelpCentre::articles() as $a) {
            foreach ((array) ($a['related'] ?? []) as $r) {
                $this->assertContains($r, $slugs, $a['slug'] . ' links to a non-existent article: ' . $r);
            }
        }
    }

    /** Every category must have something in it — an empty heading is a broken promise. */
    public function test_no_category_is_empty(): void
    {
        foreach (array_keys(HelpCentre::CATEGORIES) as $key) {
            $this->assertNotEmpty(HelpCentre::inCategory($key), 'category "' . $key . '" has no articles');
        }
    }

    // ── one corpus, two readers ──────────────────────────────────────────────

    /**
     * THE STRUCTURAL POINT OF THE WHOLE FILE.
     *
     * The page and the assistant must answer from the same document. They used to
     * answer from two — six FAQs in a Twig template and a separate set of
     * playbooks in the model briefing — and every policy change had to be
     * remembered in both places by whoever happened to make it.
     */
    public function test_the_assistant_reads_the_same_articles_as_the_page(): void
    {
        $ctx = new SupportContext(null, null, false, null);

        $this->assertContains('help_article', array_column($ctx->tools(), 'name'),
            'the tool must exist for a guest — most people who need it are not signed in');

        $r = $ctx->run('help_article', ['query' => 'my money was debited but no votes']);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['data']['found']);

        $page = HelpCentre::bySlug('paid-but-no-votes');
        $this->assertSame($page['title'], $r['data']['article']['title']);
        $this->assertSame('/help/paid-but-no-votes', $r['data']['article']['url'],
            'the model must be handed a URL the reader can actually open');
    }

    /** What the model is given is speakable prose, not markup. */
    public function test_the_assistant_is_handed_words_not_html(): void
    {
        $ctx  = new SupportContext(null, null, false, null);
        $text = $ctx->run('help_article', ['query' => 'opay reference'])['data']['article']['text'];

        $this->assertStringNotContainsString('<', $text, 'an anchor tag read aloud in a chat bubble is noise');
        $this->assertStringNotContainsString('{', $text, 'and an unresolved placeholder is worse');
    }

    /** When nothing covers the question it says so, rather than offering a near-miss. */
    public function test_the_assistant_is_told_when_there_is_no_written_answer(): void
    {
        $ctx = new SupportContext(null, null, false, null);
        $r   = $ctx->run('help_article', ['query' => 'qwertyuiop zxcvbnm'])['data'];

        $this->assertFalse($r['found']);
        $this->assertStringContainsString('do not invent', $r['say']);
    }
}
