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

    private function base(Request $req): string
    {
        $env = rtrim((string) \AfricaGates\Support\Env::get('APP_URL', ''), '/');
        if ($env !== '') return $env;
        $u = $req->getUri();
        return $u->getScheme() . '://' . $u->getHost() . ($u->getPort() ? ':' . $u->getPort() : '');
    }
}
