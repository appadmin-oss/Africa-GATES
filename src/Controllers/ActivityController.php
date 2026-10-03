<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\ActivityFeedService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The activity timeline's page.
 *
 * `GET /activity?q=…` renders the timeline server-side from a plain `<form method=get>`,
 * so it works with no JavaScript at all. Its page was destroyed with the old public pages
 * (docs/handoff/DESTROYED.md) and Phase 4 retires it into Discover's Live tab.
 *
 * The JSON half that used to live here, `/activity/search`, moved to `GET /search`
 * ({@see SearchController}) on 3 Oct 2026 — the address REFERENCE §7.1 names for the
 * palette's data. Same index ({@see ActivityFeedService}) behind both, so the two can never
 * disagree about what happened on the site.
 */
final class ActivityController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ActivityFeedService $feed,
    ) {}

    /** GET /activity — works with no JavaScript at all. */
    public function index(Request $req, Response $res): Response
    {
        $q = trim((string) ($req->getQueryParams()['q'] ?? ''));
        // `?literal=1` opts OUT of interpretation. Offered as a link on the page beside
        // whatever the model understood, so a wrong reading is one click from a plain
        // text search rather than something a visitor has to work around.
        $literal = ($req->getQueryParams()['literal'] ?? '') !== '';
        $result  = $this->feed->search($q, 40, interpret: !$literal);

        return $this->view->render($res, 'pages/activity.twig', [
            'page_title'       => ($q !== '' ? 'Search: ' . $q . ' — ' : '') . 'Activity — Africa GATES',
            'meta_description' => 'Everything happening across Africa GATES — nominees entered, '
                . 'results published, cycles opening and closing, stories, events and discussions. '
                . 'Searchable and live.',
            'gates_page'       => 'activity',
            'q'                => $q,
            'items'            => $result['items'],
            'live'             => $result['live'],
            'sources'          => $result['sources'],
            'understood'       => $result['understood'],
            'literal'          => $literal,
            'min_query'        => ActivityFeedService::MIN_QUERY,
        ]);
    }
}
