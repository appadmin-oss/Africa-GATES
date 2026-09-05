<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\GatedFormService;

/**
 * Public gated single-use form (nominee acceptance / judge onboarding). The URL
 * token is the capability; the form can be submitted exactly once.
 */
class GatedFormController
{
    public function __construct(private readonly Twig $view) {}

    public function show(Request $req, Response $res, array $args): Response
    {
        $raw    = (string) ($args['token'] ?? '');
        $row    = GatedFormService::resolve($raw);
        $status = GatedFormService::status($row);
        $name   = $row ? GatedFormService::subjectName((string) $row->purpose, (int) $row->subject_id) : '';

        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        return $this->view->render($res->withStatus($status === 'ok' ? 200 : 410), 'pages/gated-form.twig', [
            'page_title'   => 'Your form — Africa GATES',
            'gates_page'   => 'form',
            'has_hero'     => false,
            'token'        => $raw,
            'status'       => $status,
            'purpose'      => $row ? (string) $row->purpose : '',
            'subject_name' => $name,
            'terms_url'    => $row ? GatedFormService::subjectTermsUrl((string) $row->purpose, (int) $row->subject_id) : '/terms',
            'error'        => $error,
        ]);
    }

    public function submit(Request $req, Response $res, array $args): Response
    {
        unset($_SESSION['flash_error']);
        $raw = (string) ($args['token'] ?? '');
        $b   = (array) $req->getParsedBody();
        $data = [
            'bio'          => trim((string) ($b['bio'] ?? '')),
            'expertise'    => trim((string) ($b['expertise'] ?? '')),
            'phone'        => trim((string) ($b['phone'] ?? '')),
            'links'        => trim((string) ($b['links'] ?? '')),
            'accept_terms' => !empty($b['accept_terms']) ? 1 : 0,
        ];
        if ($data['bio'] === '' || !$data['accept_terms']) {
            $_SESSION['flash_error'] = 'Please add a short bio and accept the terms to continue.';
            return $res->withHeader('Location', '/form/' . rawurlencode($raw))->withStatus(302);
        }

        $r = GatedFormService::submit($raw, $data);
        if (!$r['ok']) {
            $_SESSION['flash_error'] = 'This link is no longer valid (' . $r['status'] . '). Ask an admin to regenerate it.';
            return $res->withHeader('Location', '/form/' . rawurlencode($raw))->withStatus(302);
        }

        return $this->view->render($res, 'pages/gated-form.twig', [
            'page_title'   => 'Thank you — Africa GATES',
            'gates_page'   => 'form',
            'has_hero'     => false,
            'status'       => 'done',
            'purpose'      => (string) $r['purpose'],
            'subject_name' => '',
            'token'        => '',
        ]);
    }
}
