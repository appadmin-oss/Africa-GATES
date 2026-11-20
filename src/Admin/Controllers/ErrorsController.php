<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Support\PublicFault;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * GET /admin/errors — what the site failed visitors with, read from the error log
 * (PublicFault::recent(), the one parser).
 *
 * The log has carried a reference per 500 since Phase 9, and the page asks each visitor to
 * quote it. Its only reader was `/__setup/errors?token=…`, which needs the setup token from a
 * file on a host with no shell — so in practice nobody running the site could read what it
 * was telling visitors to report. This is that reader, inside the console, where the
 * `faults` alert points.
 *
 * UNMAPPED in Permissions, so the guard holds it to superadmin: an entry carries file paths
 * and stack frames, and who else should read those is the owner's decision, not this page's.
 */
final class ErrorsController
{
    /** The two windows offered. A week is the longest a reference somebody quotes is fresh. */
    private const WINDOWS = [24 => 'Last 24 hours', 168 => 'Last 7 days'];

    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $qp    = $req->getQueryParams();
        $hours = (int) ($qp['hours'] ?? 24);
        if (!isset(self::WINDOWS[$hours])) $hours = 24;

        // A reference as people type it off a screen: lower case, a space, no dash.
        $asked = strtoupper(trim((string) ($qp['ref'] ?? '')));
        $asked = (string) preg_replace('/^([A-Z0-9]{4})[\s-]?([A-Z0-9]{4})$/', '$1-$2', $asked);
        $entry = $asked !== '' ? PublicFault::entry($asked) : null;

        return $this->view->render($res, 'admin/errors.twig', [
            'page_title' => 'Errors visitors saw',
            'admin_page' => 'errors',
            'recent'     => PublicFault::recent($hours, 200),
            'hours'      => $hours,
            'windows'    => self::WINDOWS,
            'asked'      => $asked,
            'entry'      => $entry,
            'others'     => $entry !== null ? PublicFault::others($asked, $entry['where']) : 0,
        ]);
    }
}
