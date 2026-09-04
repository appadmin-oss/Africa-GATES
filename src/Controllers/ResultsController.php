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
        $r = PublicResults::index();

        return $this->view->render($res, 'pages/results/index.twig', [
            'page_title'       => 'Results — Africa GATES',
            'meta_description' => 'Every award Africa GATES has decided, with the full '
                . 'standing and the arithmetic behind each Cultural Power Index — community '
                . 'support and judges’ marks, shown separately.',
            'gates_page'       => 'results',
            'current_section'  => 'projects',
            'has_hero'         => false,
            'items'            => $r['items'],
            'held'             => $r['held'],
        ]);
    }

    /** GET /results/{slug} */
    public function show(Request $req, Response $res, array $args): Response
    {
        $r = PublicResults::category(PublicResults::idFrom((string) ($args['slug'] ?? '')));
        if ($r === null) throw new \Slim\Exception\HttpNotFoundException($req);

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
