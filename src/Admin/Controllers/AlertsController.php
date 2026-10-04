<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Admin\Support\Permissions;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * GET /admin/alerts — what the platform noticed on its own (README §4.3), stage 1.
 *
 * The list, its two tabs (Open · High) and each alert's detail and next step, from
 * {@see ConsoleAlerts}. The handoff's Closed tab, Assign to me, Close-with-reason and the
 * seven-day timeline need alert STATE this platform does not keep yet — what "closed"
 * means for a fact that is still true is a decision, not a column — and are the Alerts
 * screen's own build in stage 2 (docs/handoff/PHASE-ADMIN.md).
 *
 * Gated `health` (superadmin, admin, viewer) through the path map, like the top bar's
 * pill that links here. An alert's own "what to do" link is drawn only when this role can
 * open where it points: a viewer told about chargebacks is not handed a door to a money
 * screen the guard would close.
 */
final class AlertsController
{
    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $tab  = (string) ($req->getQueryParams()['tab'] ?? 'open');
        if (!in_array($tab, ['open', 'high'], true)) $tab = 'open';

        $all  = ConsoleAlerts::open();
        $high = ConsoleAlerts::high();
        $list = $tab === 'high' ? $high : $all;

        $want = (string) ($req->getQueryParams()['alert'] ?? '');
        $focus = null;
        foreach ($list as $a) if ($a['key'] === $want) $focus = $a;
        $focus ??= $list[0] ?? null;

        $canAct = static fn (?string $href): bool => $href !== null && Permissions::canOpen($role, $href);

        return $this->view->render($res, 'admin/alerts.twig', [
            'page_title' => 'Alerts',
            'admin_page' => 'alerts',
            'tab'        => $tab,
            'alerts'     => array_map(static fn (array $a): array => $a + ['can_act' => $canAct($a['href'])], $list),
            'focus'      => $focus ? $focus + ['can_act' => $canAct($focus['href'])] : null,
            'n_open'     => count($all),
            'n_high'     => count($high),
        ]);
    }
}
