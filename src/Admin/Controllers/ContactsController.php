<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\Contacts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Contacts (AdminConsole v2, README §10b): everybody who has voted, given, nominated or
 * bought, one row per person, with the newsletter's own answer to "may we email them".
 * The rules about who is and is not on it live in {@see Contacts}.
 *
 * Gated `finance` (superadmin and admin), not `data`: it totals each person's giving and
 * puts their address beside it, which no `data` page does in one place. Widening it is
 * the owner's decision, not a side effect of adding the page.
 *
 * Tabs, search and paging are query parameters — the list can be tens of thousands of
 * people, so a search that filters the drawn rows in place would only search one page.
 */
final class ContactsController
{
    private const PER_PAGE = 50;

    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $view = Contacts::page(
            Contacts::build(),
            (string) ($q['tab'] ?? 'all'),
            mb_substr((string) ($q['q'] ?? ''), 0, 120),
            max(1, (int) ($q['page'] ?? 1)),
            self::PER_PAGE,
        );

        return $this->view->render($res, 'admin/contacts.twig', $view + [
            'page_title' => 'Contacts — Africa GATES Admin',
            'admin_page' => 'contacts',
            'consent'    => Contacts::CONSENT,
        ]);
    }
}
