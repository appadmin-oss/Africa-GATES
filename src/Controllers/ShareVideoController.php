<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{FlierService, ShareVideo};
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `…/video` beside a nominee's ballot, an appeal and an event: the page that draws the
 * 9:16 share video and lets somebody save it (Services\ShareVideo has the why).
 *
 * The award route reuses FlierService::forNominee, the same lookup the flier and the
 * share card already use, so a nominee the sandbox or a withdrawal hides from those is
 * hidden from this too. Nothing here is indexed: it is a tool for making a post, not a page.
 */
final class ShareVideoController
{
    public function __construct(private readonly Twig $view, private readonly FlierService $flier) {}

    public function nominee(Request $req, Response $res, array $args): Response
    {
        return $this->draw($req, $res, ShareVideo::forNominee($this->flier->forNominee((int) (string) ($args['slug'] ?? '0'))));
    }

    public function campaign(Request $req, Response $res, array $args): Response
    {
        return $this->draw($req, $res, ShareVideo::forCampaign((string) ($args['slug'] ?? ''), (string) ($args['campaign'] ?? ''), $this->base($req)));
    }

    public function event(Request $req, Response $res, array $args): Response
    {
        return $this->draw($req, $res, ShareVideo::forEvent((string) ($args['slug'] ?? ''), $this->base($req)));
    }

    private function draw(Request $req, Response $res, ?array $story): Response
    {
        if ($story === null) throw new \Slim\Exception\HttpNotFoundException($req);
        return $this->view->render($res, 'pages/share-video.twig', [
            'page_title' => 'Share video — ' . $story['title'] . ' — Africa GATES',
            'story'      => $story,
        ])->withHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * The one base-URL resolver. A private copy here trusted the request's Host header
     * whenever APP_URL was blank, so a forged header put somebody else's address on the
     * link a share video ends on.
     */
    private function base(Request $req): string
    {
        return \AfricaGates\Support\SiteUrl::base($req);
    }
}
