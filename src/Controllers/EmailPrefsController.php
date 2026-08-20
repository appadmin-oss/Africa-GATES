<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\EmailOptOut;
use AfricaGates\Support\SiteUrl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * /email/unsubscribe — the stop link in a bulk email's footer.
 *
 * ── GET CONFIRMS, POST ACTS ──────────────────────────────────────────────────
 * The tempting version unsubscribes on the GET, because it is one click and reads as
 * friendlier. It also hands the decision to software: Outlook's Safe Links, corporate
 * mail scanners and inbox previewers all FETCH the links in a message without anybody
 * reading it, and every one of those fetches would silently remove somebody who never
 * clicked. So GET renders a confirmation with a POST button. That is one extra click
 * for a real person and the difference between working and not for everybody whose
 * employer scans their mail.
 *
 * No CSRF token on that POST, deliberately: the token in the URL already authenticates
 * the request, the recipient arrives with no session, and the worst a forged POST can
 * achieve is unsubscribing an address the forger already had to know the HMAC for.
 */
final class EmailPrefsController
{
    public function __construct(private readonly Twig $view) {}

    public function show(Request $req, Response $res): Response
    {
        $q     = $req->getQueryParams();
        $email = EmailOptOut::verify((string) ($q['e'] ?? ''), (string) ($q['t'] ?? ''));

        return $this->page($res, $email, false, (string) ($q['e'] ?? ''), (string) ($q['t'] ?? ''));
    }

    public function stop(Request $req, Response $res): Response
    {
        $b     = (array) $req->getParsedBody();
        $email = EmailOptOut::verify((string) ($b['e'] ?? ''), (string) ($b['t'] ?? ''));

        if ($email !== null) {
            EmailOptOut::record($email, 'unsubscribe-link');
        }

        return $this->page($res, $email, true, '', '');
    }

    private function page(Response $res, ?string $email, bool $done, string $e, string $t): Response
    {
        return $this->view->render($res, 'pages/email-unsubscribe.twig', [
            'page_title'       => 'Email preferences — Africa GATES',
            'meta_description' => 'Stop receiving Africa GATES bulk email.',
            'gates_page'       => 'email',
            // A task page: no splash, no marketing chrome. Somebody here is trying to
            // finish one thing.
            'task_page'        => true,
            'has_hero'         => false,
            'valid'            => $email !== null,
            'done'             => $done,
            'email'            => $email,
            'e'                => $e,
            't'                => $t,
            'site_url'         => SiteUrl::base(),
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
