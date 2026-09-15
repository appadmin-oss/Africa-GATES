<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Exception\HttpNotFoundException;
use AfricaGates\Services\{FormService, RateLimitService};

/**
 * Public renderer + submission handler for admin-built forms at /f/{key}.
 * The schema is server-rendered (real inputs); Alpine layers conditional
 * show/hide; FormService runs the authoritative server-side validation.
 */
class FormController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    public function show(Request $req, Response $res, array $args): Response
    {
        $form = FormService::byKey((string) ($args['key'] ?? ''));
        if (!$form || $form['status'] !== 'published') throw new HttpNotFoundException($req);

        $doneKey = 'form_done_' . $form['form_key'];
        $done = !empty($_SESSION[$doneKey]);
        unset($_SESSION[$doneKey]);
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        return $this->render($res, $form, $error, [], $done);
    }

    public function submit(Request $req, Response $res, array $args): Response
    {
        $form = FormService::byKey((string) ($args['key'] ?? ''));
        if (!$form || $form['status'] !== 'published') throw new HttpNotFoundException($req);

        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($this->rateLimit && !$this->rateLimit->check(hash('sha256', $ip), 'form_submit', 20, 3600)) {
            return $this->render($res->withStatus(429), $form, 'You’re submitting too fast — please wait a moment.', (array) $req->getParsedBody(), false);
        }

        $b = (array) $req->getParsedBody();
        $errors = FormService::validate($form, $b);
        if ($errors) {
            return $this->render($res->withStatus(422), $form, implode(' ', array_slice($errors, 0, 4)), $b, false);
        }

        $uid = ((int) ($_SESSION['user_id'] ?? 0)) ?: null;
        FormService::storeSubmission($form, $b, $ip, $uid);
        $_SESSION['form_done_' . $form['form_key']] = true;
        return $res->withHeader('Location', '/f/' . rawurlencode($form['form_key']))->withStatus(302);
    }

    private function render(Response $res, array $form, ?string $error, array $values, bool $done): Response
    {
        return $this->view->render($res, 'pages/form.twig', [
            'page_title'       => $form['title'] . ' — Africa GATES',
            'meta_description' => $form['description'] ?: $form['title'],
            'gates_page'       => 'form',
            'has_hero'         => false,
            'form'             => $form,
            'error'            => $error,
            'values'           => $values,
            'done'             => $done,
        ]);
    }
}
