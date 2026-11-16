<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\SpotlightMail;
use AfricaGates\Services\SupporterSpotlight;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * GET /spotlight/hide shows the question; POST /spotlight/hide answers it. Two ways to be
 * allowed: the signed link every Spotlight message carries (SpotlightMail::hideUrl), or
 * being signed in — a member takes THEMSELVES down, by the keys their own account votes as,
 * never anybody named in a form field.
 */
final class SpotlightController
{
    public function __construct(private readonly Twig $view) {}

    public function show(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $k = (string) ($q['k'] ?? ''); $t = (string) ($q['t'] ?? '');
        $valid = SpotlightMail::validHide($k, $t) || ($k === '' && $this->member() > 0);
        return $this->page($res, $valid, false, $valid && $k !== '' ? $k : '', $valid ? $t : '');
    }

    public function hide(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $k = (string) ($b['k'] ?? ''); $t = (string) ($b['t'] ?? '');
        if ($k !== '' && SpotlightMail::validHide($k, $t)) {
            SupporterSpotlight::hide($k);
            return $this->page($res, true, true, '', '');
        }
        if ($k === '' && ($uid = $this->member()) > 0) {
            foreach (SupporterSpotlight::keysForUser($uid) as $key) SupporterSpotlight::hide($key);
            return $this->page($res, true, true, '', '');
        }
        return $this->page($res->withStatus(403), false, false, '', '');
    }

    private function member(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    private function page(Response $res, bool $valid, bool $done, string $k, string $t): Response
    {
        return $this->view->render($res, 'pages/spotlight/hide.twig', [
            'page_title' => 'Supporter spotlight — Africa GATES',
            'gates_page' => 'awards',
            'valid' => $valid, 'done' => $done, 'k' => $k, 't' => $t,
        ]);
    }
}
