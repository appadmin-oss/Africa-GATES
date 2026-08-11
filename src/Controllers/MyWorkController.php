<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\Notifier;
use AfricaGates\Services\QuestionnaireService;
use AfricaGates\Support\ClientIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * "Tell the judges about your work" — the nominee's own page.
 *
 * ── OPEN TO GUESTS, WITH A TOKEN AS THE WHOLE CREDENTIAL ─────────────────────
 *
 * The third page on this platform built that way, after the claim link and the interview
 * link, and for the same reason each time: a nominee has no account, and requiring one to
 * describe their own work would shut out exactly the population the awards exist to find.
 * The token opens one submission and does four things — read it, save a draft, attach a
 * file, send it.
 *
 * ── SAVING IS CHEAP AND SUBMITTING IS DELIBERATE ─────────────────────────────
 *
 * A draft save never validates anything: somebody filling this in on a phone between other
 * work must be able to leave half a sentence and come back tomorrow. A form that refuses to
 * save until it is perfect is a form that gets abandoned, and an abandoned questionnaire is
 * a judge reading a nominator's paragraph again.
 *
 * Submitting is the only step that checks the required answers, and it is the step that puts
 * the work in front of the panel — so it asks for a typed name first.
 *
 * ── AND FILE UPLOADS ARE THEIR OWN REQUEST ───────────────────────────────────
 *
 * Separate from the draft save, because an upload can fail on its own — too large, or a type
 * the platform will not store — and losing a page of typing to a rejected attachment is
 * exactly the kind of thing that stops somebody finishing.
 */
final class MyWorkController
{
    public function __construct(private readonly Twig $view) {}

    public function page(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $form  = QuestionnaireService::formFor($token);

        if ($form === null) {
            // Never says whether the token is unknown or spent: the difference between those
            // two answers is a way to test tokens.
            return $this->render($res->withStatus(404), null, $token, '', '');
        }

        $notice = (string) ($_SESSION['mywork_notice'] ?? '');
        $error  = (string) ($_SESSION['mywork_error'] ?? '');
        $missing = $_SESSION['mywork_missing'] ?? [];
        unset($_SESSION['mywork_notice'], $_SESSION['mywork_error'], $_SESSION['mywork_missing']);

        return $this->render($res, $form, $token, $notice, $error,
                             is_array($missing) ? $missing : []);
    }

    /** Save a draft, or send it to the panel — whichever button was pressed. */
    public function save(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $to    = '/my-work/' . $token;

        unset($_SESSION['mywork_notice'], $_SESSION['mywork_error'], $_SESSION['mywork_missing']);

        $answers = is_array($body['a'] ?? null) ? $body['a'] : [];
        $works   = is_array($body['w'] ?? null) ? array_values($body['w']) : [];

        // The draft is stored on BOTH paths. A submit that fails validation must not throw
        // away what was typed on the way to failing.
        $saved = QuestionnaireService::saveDraft($token, $answers, $works);
        if (!($saved['ok'] ?? false)) {
            $_SESSION['mywork_error'] = (string) $saved['message'];
            return $res->withHeader('Location', $to)->withStatus(302);
        }

        if ((string) ($body['action'] ?? '') !== 'submit') {
            $_SESSION['mywork_notice'] = (string) $saved['message'];
            return $res->withHeader('Location', $to)->withStatus(302);
        }

        $r = QuestionnaireService::submit(
            $token,
            (string) ($body['declared_name'] ?? ''),
            ClientIp::from($req)
        );

        if ($r['ok'] ?? false) {
            $_SESSION['mywork_notice'] = (string) $r['message'];
        } else {
            $_SESSION['mywork_error']   = (string) $r['message'];
            $_SESSION['mywork_missing'] = $r['missing'] ?? [];
        }
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    /** One file, attached to one listed work. */
    public function upload(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $file  = $req->getUploadedFiles()['file'] ?? null;

        if ($file === null) {
            $_SESSION['mywork_error'] = 'No file was chosen.';
            return $res->withHeader('Location', '/my-work/' . $token)->withStatus(302);
        }

        $r = QuestionnaireService::attachFile($token, (string) ($body['uid'] ?? ''), $file);
        $_SESSION[($r['ok'] ?? false) ? 'mywork_notice' : 'mywork_error'] = (string) $r['message'];
        return $res->withHeader('Location', '/my-work/' . $token)->withStatus(302);
    }

    /**
     * @param array<string,mixed>|null $form
     * @param list<string> $missing
     */
    private function render(Response $res, ?array $form, string $token,
                           string $notice, string $error, array $missing = []): Response
    {
        return $this->view->render($res, 'pages/my-work.twig', [
            'page_title'    => $form !== null ? 'Tell the judges about your work' : 'Your work',
            'form'          => $form,
            'token'         => $token,
            'notice'        => $notice,
            'error'         => $error,
            'missing'       => $missing,
            'support_email' => Notifier::supportEmail(),
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
