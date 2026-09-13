<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{CommunityService, PublicResults, ResultCard, ResultThread};
use AfricaGates\Support\SiteUrl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * THE PUBLIC RECORD OF AN AWARD.
 *
 * `GET /results`                the released awards
 * `GET /results/{slug}`         one category: the full standing, and every step of the index
 * `GET /results/{slug}/card.png` the 1200×630 link preview
 *
 * Until this existed, a result's only public trace was a row in the activity feed and a
 * congratulations email pointing at `/leaderboard` — a ranking of registry profiles that
 * names neither the award nor the person who won it. The most important thing this
 * platform produces had no page, and the arithmetic behind it was visible only to the
 * administrators who published it.
 *
 * ── WHY THE WHOLE STANDING AND NOT JUST THE WINNER ──────────────────────────
 *
 * A page that prints one name is an announcement. This platform's claim is that a ranking
 * cannot be bought, and the only form that claim can take in public is the working: every
 * nominee who scored, their community half, their judge half, the denominator the
 * community half was measured against, and the reason anybody is out of the running. A
 * nominee placed fourth is entitled to see why, on the same page as the person who won.
 *
 * ── AND WHY REPLIES ARE THREAD REPLIES ──────────────────────────────────────
 *
 * {@see ResultThread} — the result posts to the Pulse, and the conversation on this page IS
 * that post's conversation. One place, one moderation queue, one rate limit. The reply form
 * posts to the community endpoint that already exists rather than to anything here.
 */
final class ResultsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CommunityService $community,
    ) {}

    /** GET /results */
    public function index(Request $req, Response $res): Response
    {
        // ── THE VIEW STATE IS IN THE URL, AND THAT IS NOT A DETAIL ──────────
        //
        // This is a public, indexable archive. Every filter and every order is a link with
        // a real address, so a reader can send somebody "the awards counting right now",
        // a search engine can index each view, and the back button does what it says. A
        // filter held in JavaScript is a filter nobody can share and a page that has one
        // URL for every state it can be in.
        //
        // Nothing is validated here: `standings()` resolves every value against a known
        // set, because a query string is untrusted input and a stale link is somebody
        // trying to read a results page rather than an error.
        $q = $req->getQueryParams();
        $r = PublicResults::standings(24, [
            'order'     => (string) ($q['order'] ?? ''),
            'programme' => (string) ($q['programme'] ?? ''),
            'q'         => (string) ($q['q'] ?? ''),
        ]);

        return $this->view->render($res, 'pages/results/index.twig', [
            'page_title'       => 'Results — Africa GATES',
            'meta_description' => 'Where every Africa GATES award stands — counting, with '
                . 'the panel, decided or withheld — and the full standing behind each '
                . 'decided one, community support and judges’ marks shown separately.',
            'gates_page'       => 'results',
            'current_section'  => 'projects',
            'has_hero'         => false,
            'editions'         => $r['editions'],
            'stats'            => $r['stats'],
            'shown'            => $r['shown'],
            'programmes'       => $r['programmes'],
            'view'             => $r['view'],
            'held'             => $r['held'],
            // ── AND THE AWARDS THAT ARE LATE ─────────────────────────────────
            //
            // A results date is a promise made in public. `PublicResults` used to serve
            // the live standing once it passed, announced or not; it publishes only an
            // announced cycle now, which would leave this page silent on the one day the
            // most people look at it. A delay is stated instead — derived, so it appears
            // when a release slips and goes when the cycle is announced.
            'delayed'          => PublicResults::delayed(),
        ]);
    }

    /**
     * GET /winners — the hall of fame.
     *
     * ── WHY THIS URL AND NOT A NEW ONE ───────────────────────────────────────
     *
     * `/winners` has existed as a redirect for as long as the near-miss table has, because
     * it is what people type. It pointed at `/results`, which was the closest thing that
     * existed and was never the thing they meant: a ledger organised by edition answers
     * "what happened in 2026", and somebody typing /winners is asking "who is in here".
     *
     * ── THE DESCRIPTION CARRIES REAL NAMES ───────────────────────────────────
     *
     * Assembled from the page's own headings is what every untended site emits. The three
     * most recent people in the hall are the sentence worth putting in a search result,
     * and they are exactly what somebody searching for one of them would recognise.
     */
    public function hall(Request $req, Response $res): Response
    {
        $hall  = \AfricaGates\Services\HallOfFame::build();
        $names = array_slice(array_map(
            static fn (array $p): string => (string) ($p['name'] ?? ''), $hall['people']), 0, 3);
        $names = array_values(array_filter($names, static fn (string $n): bool => trim($n) !== ''));

        $desc = $names === []
            ? 'Every award Africa GATES has decided and announced, and the person it was '
            . 'decided for. Nothing appears here before it has been announced.'
            : 'Everyone Africa GATES has honoured — ' . implode(', ', $names)
            . ' and ' . max(0, count($hall['people']) - count($names)) . ' others across '
            . $hall['programmes'] . ' award programme' . ($hall['programmes'] === 1 ? '' : 's')
            . '. Each with the Cultural Power Index that decided it.';

        return $this->view->render($res, 'pages/results/hall.twig', [
            'page_title'       => 'Hall of fame — Africa GATES',
            'meta_description' => mb_substr($desc, 0, 300),
            'gates_page'       => 'hall',
            'current_section'  => 'projects',
            'has_hero'         => false,
            'breadcrumbs'      => [['label' => 'Home', 'url' => '/'],
                                   ['label' => 'Results', 'url' => '/results'],
                                   ['label' => 'Hall of fame']],
            'hall'             => $hall,
        ]);
    }

    /**
     * GET /results/{edition} — one edition, drawn whole.
     *
     * The page an award programme is actually known by: "the 2026 Principal Awards", not
     * "Teachers' Choice". It is the shareable URL for an announcement, the thing press
     * link to, and the only page that answers "who won this year" without a reader
     * assembling it from a list.
     */
    public function edition(Request $req, Response $res, array $args = []): Response
    {
        $slug = (string) ($args['edition'] ?? '');
        $e    = PublicResults::edition($slug);

        // ── AN EDITION THAT IS STILL RUNNING GETS A PAGE, AND NOT ITS STANDING ──
        //
        // `edition()` answers only for an ANNOUNCED cycle, and that refusal is one of this
        // platform's load-bearing rules: serving a judged-but-unreleased standing publicly
        // IS announcing it. It is not relaxed here. {@see PublicResults::openEdition()} is
        // a different page for a different fact — that an award is running, and how far
        // along each of its categories is — built by code that never draws a nominee, so
        // there is structurally nothing on it to leak.
        //
        // Without this, every row `/results` now shows for a counting or judging edition
        // would link to a 404, which reads as the award having been taken down.
        $open = $e === null ? PublicResults::openEdition($slug) : null;
        if ($e === null && $open === null) {
            throw new \Slim\Exception\HttpNotFoundException($req);
        }

        if ($open !== null) return $this->openEdition($req, $res, $open);

        $base  = \AfricaGates\Support\SiteUrl::base($req);
        $names = array_slice(array_map(
            static fn (array $a) => (string) ($a['winner']['name'] ?? ''), $e['awards']), 0, 3);

        return $this->view->render($res, 'pages/results/edition.twig', [
            'page_title' => $e['programme'] . ' ' . ($e['edition'] ?: $e['year']) . ' — winners',
            // A REAL sentence with REAL names. A description assembled from the page's own
            // headings is what every untended site emits, and it is why they all look the
            // same in a result list; the names are the reason somebody clicks.
            'meta_description' => $e['awards'] === []
                ? 'Results for ' . $e['programme'] . ' ' . ($e['edition'] ?: $e['year']) . '.'
                : count($e['awards']) . ' award' . (count($e['awards']) === 1 ? '' : 's')
                    . ' decided at ' . $e['programme'] . ' ' . ($e['edition'] ?: $e['year'])
                    . ($names ? ', including ' . implode(', ', array_filter($names)) : '')
                    . '. Every winner, every index, and the working behind it.',
            'gates_page'      => 'results',
            'current_section' => 'projects',
            'has_hero'        => false,
            'canonical_url'   => $base . $e['url'],
            'e'               => $e,
            'breadcrumbs'     => [
                ['label' => 'Results', 'url' => '/results'],
                ['label' => $e['programme'] . ' ' . ($e['edition'] ?: $e['year'])],
            ],
        ]);
    }

    /**
     * The same URL, for an edition whose awards are not decided yet.
     *
     * Rendered from its own template rather than by threading conditionals through the
     * released one: the two pages answer different questions, and a single template
     * carrying both is a template one edit away from printing a standing that does not
     * exist. `noindex`, because the real result takes this exact URL the moment the cycle
     * is announced and a search engine holding the holding page is worse than none.
     *
     * @param array<string,mixed> $o
     */
    private function openEdition(Request $req, Response $res, array $o): Response
    {
        $name = $o['programme'] . ' ' . ($o['edition'] ?: $o['year']);

        return $this->view->render($res, 'pages/results/open.twig', [
            'page_title' => $name . ' — where it stands',
            'meta_description' => $name . ' is '
                . ($o['status']['key'] === 'counting' ? 'open for voting' : 'with the panel')
                . '. ' . count($o['categories']) . ' award'
                . (count($o['categories']) === 1 ? '' : 's') . ', and no standing is '
                . 'published until every one of them is decided.',
            'gates_page'      => 'results',
            'current_section' => 'projects',
            'has_hero'        => false,
            'robots'          => 'noindex, follow',
            'canonical_url'   => \AfricaGates\Support\SiteUrl::base($req) . $o['url'],
            'e'               => $o,
            'breadcrumbs'     => [
                ['label' => 'Results', 'url' => '/results'],
                ['label' => $name],
            ],
        ]);
    }

    /** GET /results/{slug} */
    public function show(Request $req, Response $res, array $args): Response
    {
        $categoryId = PublicResults::idFrom((string) ($args['slug'] ?? ''));
        $r = PublicResults::category($categoryId);

        if ($r === null) {
            // ── A LATE AWARD EXPLAINS ITSELF RATHER THAN 404ing ──────────────
            //
            // A result's URL is in front of people before the date: in the congratulations
            // mail, in the Pulse, in a message somebody forwarded. On the day it was
            // promised, whoever follows one is exactly the person owed an explanation —
            // and a 404 on that link, on that day, reads as the result being taken down.
            //
            // 200, not 404, and deliberately: the address is right, the award is real,
            // and there is a true answer to give about it. `noindex` because a crawler
            // must not cache a holding page as the award's content — the real result
            // takes this URL when it is announced.
            $late = PublicResults::delayForCategory($categoryId);
            if ($late !== null) {
                return $this->view->render($res, 'pages/results/late.twig', [
                    'page_title'       => ($late['award'] !== '' ? $late['award'] . ' — ' : '')
                                          . 'result not announced yet — Africa GATES',
                    'meta_description' => 'This award has not been decided yet. Results for '
                        . ($late['edition'] ?: $late['programme']) . ' were expected on '
                        . date('j F Y', strtotime($late['promised'])) . ' and are late.',
                    'gates_page'       => 'results',
                    'current_section'  => 'projects',
                    'has_hero'         => false,
                    'late'             => $late,
                ])->withHeader('X-Robots-Tag', 'noindex, follow');
            }

            throw new \Slim\Exception\HttpNotFoundException($req);
        }

        // ── THE CANONICAL URL, AND WHY THIS REDIRECTS ────────────────────────
        //
        // The id leads the segment, so `/results/12-anything` resolves. Left alone, every
        // stale share of a renamed category would be a separate URL holding a separate
        // copy of the same result — separate crawler entry, separate preview cache, and a
        // reply count split across spellings. One address per award.
        $want = (string) $r['slug'];
        if ((string) ($args['slug'] ?? '') !== $want) {
            return $res->withHeader('Location', '/results/' . $want)->withStatus(301);
        }

        $thread  = ResultThread::forCategory((int) $r['category']->id);
        $replies = $thread === null ? [] : $this->community->listComments('thread', $thread['id'], 60);

        $base = SiteUrl::base($req);

        return $this->view->render($res, 'pages/results/show.twig', [
            'page_title'       => $this->title($r),
            'meta_description' => $this->description($r),
            'gates_page'       => 'results',
            'current_section'  => 'projects',
            'has_hero'         => false,
            'r'                => $r,
            'thread'           => $thread,
            'replies'          => $replies,
            // Absolute: a relative og:image is silently ignored by every crawler and the
            // preview falls back to nothing.
            'og_image'         => $r['held'] === null ? $base . '/results/' . $want . '/card.png' : '',
            'og_image_w'       => ResultCard::W,
            'og_image_h'       => ResultCard::H,
            'canonical_url'    => $base . '/results/' . $want,
        ]);
    }

    /** GET /results/{slug}/card.png */
    public function card(Request $req, Response $res, array $args): Response
    {
        $r = PublicResults::category(PublicResults::idFrom((string) ($args['slug'] ?? '')));
        if ($r === null || $r['held'] !== null) {
            throw new \Slim\Exception\HttpNotFoundException($req);
        }

        $png = (new ResultCard())->png($r);
        // No GD, no fonts: 404 rather than an empty 200. A crawler caches a broken image
        // for days and there is no way to make it look again.
        if ($png === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $res->getBody()->write($png);

        return $res
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Content-Length', (string) strlen($png))
            ->withHeader('Cache-Control', 'public, max-age=' . ResultCard::TTL
                . ', stale-while-revalidate=' . (ResultCard::TTL * 3))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition',
                'inline; filename="result-' . $r['slug'] . '.png"');
    }

    private function title(array $r): string
    {
        $cat = (string) ($r['category']->title ?? 'Result');
        $ed  = trim((string) ($r['edition'] ?? ''));

        return $cat . ($ed !== '' ? ' ' . $ed : '') . ' — the result | Africa GATES';
    }

    /**
     * The preview line.
     *
     * Names the winner and the split where there is one. A held result says it is being
     * verified rather than naming anybody — the description is what a link preview shows
     * when the image does not load, so it has to be withheld in exactly the same cases.
     */
    private function description(array $r): string
    {
        $cat = (string) ($r['category']->title ?? 'this category');

        if ($r['held'] !== null) {
            return 'The result for ' . $cat . ' is being verified before it is published.';
        }

        $w = $r['winner'];

        return (string) $w['name'] . ' takes ' . $cat . ' with a Cultural Power Index of '
            . (int) $w['cpi'] . ' of 1000 — ' . (int) $w['community_points']
            . ' from community support and ' . (int) $w['judge_points']
            . ' from the judging panel. The full standing and every weight are on the page.';
    }
}
