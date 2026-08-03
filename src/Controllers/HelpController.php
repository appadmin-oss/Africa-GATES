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
            // The four a stuck person needs most often, promoted above the fold so
            // the commonest arrival does not have to read a taxonomy first.
            'top'              => array_values(array_filter(
                HelpCentre::all(),
                static fn(array $a) => in_array($a['slug'], [
                    'paid-but-no-votes', 'vote-not-showing', 'code-did-not-arrive', 'paid-just-before-close',
                ], true)
            )),
        ]);
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
