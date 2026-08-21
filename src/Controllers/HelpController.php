<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\HelpCentre;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The Help Centre: an index, a category, and an article with its own URL.
 *
 * ── WHY ARTICLES HAVE URLs ───────────────────────────────────────────────────
 *
 * The old page was one screen of six accordions. That shape has a hard ceiling:
 * nothing on it can be linked to. Support could not paste an answer into a reply,
 * the assistant could not cite one, a receipt email could not point at the exact
 * paragraph about late payments, and Google could not index any of it — so every
 * person who searched the web for "africa gates votes not showing" found nothing
 * and opened a ticket instead.
 *
 * One URL per answer fixes all four at once, and it is the difference between a
 * help page and a help centre.
 *
 * ── SEARCH IS SERVER-SIDE ON PURPOSE ─────────────────────────────────────────
 *
 * The old search filtered the accordions with Alpine, which can only ever match
 * text that is already on the page. {@see HelpCentre::search()} matches the
 * KEYWORDS too — the words people actually type, several of which appear nowhere
 * in the article they should reach. Somebody searching "debited" has to land on
 * "I paid but my votes have not appeared", and no client-side substring filter
 * over rendered text can do that.
 */
final class HelpController
{
    public function __construct(private readonly Twig $view) {}

    /** GET /help — categories, the most-needed answers, and search results. */
    public function index(Request $req, Response $res): Response
    {
        $q = trim((string) ($req->getQueryParams()['q'] ?? ''));

        return $this->view->render($res, 'pages/help.twig', [
            'page_title'       => $q !== ''
                ? 'Search: ' . $q . ' — Help Centre — Africa GATES'
                : 'Help Centre — Africa GATES',
            'meta_description' => 'Answers about voting, payments, nominations, results and privacy on '
                                . 'Africa GATES — and how to reach a person when you need one.',
            'gates_page'       => 'help',
            'has_hero'         => false,
            'q'                => $q,
            'results'          => $q !== '' ? HelpCentre::search($q, 12) : [],
            'categories'       => HelpCentre::CATEGORIES,
            'by_category'      => $this->grouped(),
            // How many titles a category card shows before it defers to its own
            // page. The index used to print all 33 at once, which made "Results &
            // integrity" a wall of twelve links and the page 2,800px tall.
            'preview'          => self::PREVIEW,
            // The four a stuck person needs most often, promoted above the fold so
            // the commonest arrival does not have to read a taxonomy first.
            'top'              => array_values(array_filter(
                HelpCentre::all(),
                static fn(array $a) => in_array($a['slug'], [
                    'paid-but-no-votes', 'vote-not-showing', 'code-did-not-arrive', 'paid-just-before-close',
                ], true)
            )),
            'index'            => $this->searchIndex(),
            'total'            => count(HelpCentre::all()),
        ]);
    }

    /**
     * GET /help/c/{cat} — every answer in one category.
     *
     * ── WHY THIS ROUTE HAD TO EXIST ──────────────────────────────────────────
     *
     * This class's own description has always claimed "an index, a category, and an
     * article". There was no category route. The consequence was structural rather
     * than cosmetic: with nowhere for a category to lead, the index had to print
     * every one of its answers inline, so a card for a category with twelve answers
     * was twelve times the height of one with two, and a two-column grid of those
     * left roughly a third of the page as empty column.
     *
     * With somewhere to go, each card shows the first few and defers the rest — and
     * "Payments & votes you paid for" becomes a thing support can link to.
     */
    public function category(Request $req, Response $res, array $args): Response
    {
        $key = (string) ($args['cat'] ?? '');
        $cat = HelpCentre::CATEGORIES[$key] ?? null;

        if ($cat === null) {
            // Same reasoning as a stale article slug: somebody following an old
            // link is still a person with a question, not a 404.
            return $res->withHeader('Location', '/help')->withStatus(302);
        }

        $articles = HelpCentre::inCategory($key);

        return $this->view->render($res, 'pages/help-category.twig', [
            'page_title'       => $cat['title'] . ' — Help Centre — Africa GATES',
            'meta_description' => (string) ($cat['blurb'] ?? '')
                                . ' ' . count($articles) . ' answers on Africa GATES.',
            'gates_page'       => 'help',
            'has_hero'         => false,
            'category'         => $cat,
            'category_key'     => $key,
            'articles'         => $articles,
            'categories'       => HelpCentre::CATEGORIES,
            'counts'           => array_map('count', $this->grouped()),
            'breadcrumbs'      => [
                ['label' => 'Help Centre',        'url' => '/help'],
                ['label' => (string) $cat['title'], 'url' => null],
            ],
        ]);
    }

    /** Titles a category card shows before deferring to its own page. */
    private const PREVIEW = 5;

    /**
     * The corpus, flattened for instant narrowing in the browser.
     *
     * ── WHY BOTH THIS AND THE SERVER SEARCH ──────────────────────────────────
     *
     * The GET form stays exactly as it was, and it is still the thing that answers:
     * a search is a URL you can bookmark, share with support, or press back to, and
     * {@see HelpCentre::search()} scores keywords above titles above bodies, which
     * no substring filter can do.
     *
     * What this adds is the part between keystrokes. Thirty-three answers is small
     * enough to hold in a page, so typing can narrow the categories in place with
     * no round trip — and pressing Enter still performs the real, scored, shareable
     * search. The enhancement is additive: with JavaScript off, the form works and
     * nothing is missing.
     *
     * KEYWORDS are included because they are the words people actually type and
     * several appear nowhere in the article they point at — "debited" has to reach
     * "I paid but my votes have not appeared". The BODY is not: it would multiply
     * the payload for matches that are mostly noise, and the server search already
     * weights body hits last for the same reason.
     *
     * @return list<array{s:string,t:string,c:string,k:string}>
     */
    private function searchIndex(): array
    {
        $out = [];
        foreach (HelpCentre::all() as $a) {
            $out[] = [
                's' => (string) $a['slug'],
                't' => (string) $a['title'],
                'c' => (string) $a['cat'],
                // Lower-cased once here rather than on every keystroke in the browser.
                'k' => mb_strtolower(implode(' ', array_merge(
                    [(string) $a['title'], (string) ($a['summary'] ?? '')],
                    (array) ($a['keywords'] ?? [])
                ))),
            ];
        }
        return $out;
    }

    /** GET /help/{slug} — one answer, on its own page. */
    public function article(Request $req, Response $res, array $args): Response
    {
        $slug    = (string) ($args['slug'] ?? '');
        $article = HelpCentre::bySlug($slug);

        if ($article === null) {
            // Not a 404 dead end. Somebody following a stale link from an old
            // email is still a person with a question, so they land on the index
            // with their slug already in the search box.
            return $res->withHeader('Location', '/help?q=' . rawurlencode(str_replace('-', ' ', $slug)))
                       ->withStatus(302);
        }

        $cat = HelpCentre::CATEGORIES[$article['cat']] ?? null;

        // Siblings, and this article's place among them. The rail used to offer
        // two generic links ("All answers", "Contact a person"), which is a dead
        // end dressed as navigation: somebody who has just read about a missing
        // payment is very likely to want the next payment answer, and had no way
        // to reach it without going back to the index and starting again.
        $siblings = HelpCentre::inCategory((string) $article['cat']);
        $slugs    = array_column($siblings, 'slug');
        $i        = array_search($article['slug'], $slugs, true);

        return $this->view->render($res, 'pages/help-article.twig', [
            'page_title'       => $article['title'] . ' — Help Centre — Africa GATES',
            'meta_description' => (string) $article['summary'],
            'gates_page'       => 'help',
            'has_hero'         => false,
            'article'          => $article,
            'category'         => $cat,
            'category_key'     => $article['cat'],
            'related'          => array_values(array_filter(array_map(
                static fn(string $s) => HelpCentre::bySlug($s),
                (array) ($article['related'] ?? [])
            ))),
            'siblings'         => $siblings,
            'prev'             => $i !== false && $i > 0 ? $siblings[$i - 1] : null,
            'next'             => $i !== false && $i < count($siblings) - 1 ? $siblings[$i + 1] : null,
            // Rounded up, floor of one. "1 min read" on a four-paragraph answer is
            // reassurance for somebody who is about to give up and open a ticket;
            // an honest small number is worth more here than precision.
            'read_minutes'     => max(1, (int) ceil(str_word_count(HelpCentre::plainText($article)) / 200)),
            // The trail the page prints. The middle crumb points at /help/c/{cat} —
            // the visible one used to link `/help?q={category title}`, a search URL,
            // which is now `noindex` (see Support\Canonical) and was always a worse
            // destination than the category page that exists.
            'breadcrumbs'      => array_values(array_filter([
                ['label' => 'Help Centre', 'url' => '/help'],
                $cat !== null
                    ? ['label' => (string) $cat['title'],
                       'url'   => '/help/c/' . rawurlencode((string) $article['cat'])]
                    : null,
                ['label' => (string) $article['title'], 'url' => null],
            ])),
            // A help answer whose TITLE is the question and whose body is the answer
            // is a FAQPage in the literal sense — every one of these was written as
            // "I paid but my votes have not appeared", not as an essay. The answer
            // text is the article's own summary plus its plain-text body, so the
            // markup can never claim something the page does not visibly say.
            'schema'           => [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => [[
                    '@type'          => 'Question',
                    'name'           => (string) $article['title'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        // plainText() already leads with the summary — concatenating
                        // both printed the first sentence of every answer twice.
                        'text'  => mb_substr(HelpCentre::plainText($article), 0, 1200),
                    ],
                ]],
            ],
        ]);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function grouped(): array
    {
        $out = [];
        foreach (array_keys(HelpCentre::CATEGORIES) as $key) {
            $out[$key] = HelpCentre::inCategory($key);
        }
        return $out;
    }
}
