<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use AfricaGates\Services\Leaderboard;
use AfricaGates\Support\Schema;
use AfricaGates\Support\SiteUrl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `/leaderboard` — Phase 6, Leaderboard.dc.html (§8.19). Every figure from {@see Leaderboard}.
 *
 * The filters are URL parameters (`q`, `cat`, `region`, `n`), so the board works with no
 * script, survives Back and can be sent to somebody.
 */
class LeaderboardController
{
    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $p  = $req->getQueryParams();
        $lb = Leaderboard::build([
            'q'      => (string) ($p['q'] ?? ''),
            'cat'    => (string) ($p['cat'] ?? ''),
            'region' => (string) ($p['region'] ?? ''),
            'n'      => (int) ($p['n'] ?? 0),
        ]);

        $top = array_merge($lb['podium'], $lb['filtered'] ? [] : $lb['rows']);

        return $this->view->render($res, 'pages/leaderboard.twig', [
            'page_title'       => 'Cultural Power Index leaderboard — Africa GATES',
            'meta_description' => $lb['empty']
                ? 'The Africa GATES Cultural Power Index: one transparent score for every verified profile, from community votes and an independent judge panel.'
                : $lb['total'] . ' verified profiles ranked by the Africa GATES Cultural Power Index — community votes and an independent judge panel, recomputed every ' . $lb['every'] . ' hours.',
            'gates_page'       => 'leaderboard',
            'lb'               => $lb,
            // ItemList JSON-LD of what the page actually lists, through Schema::text().
            'schema'           => Schema::itemList(
                'Africa GATES — Cultural Power Index leaderboard',
                array_map(static fn (array $r): array => ['name' => $r['name'], 'url' => $r['url']], $top),
                SiteUrl::base($req)
            ),
        ]);
    }
}
