<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Admin\Support\TimeRange;
use AfricaGates\Admin\Support\TimeSeries;
use AfricaGates\Services\SystemStatus;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * GET /admin/alerts — what the platform noticed on its own (README §4.3), stage 1.
 *
 * The list, filter chips (Severity, Source — owner's screenshots, 13 Oct 2026), a
 * timeline of what the status page recorded over the chosen window, and each alert's
 * detail and next step, from {@see ConsoleAlerts}. The handoff's Closed tab, Assign to me
 * and Close-with-reason need alert STATE this platform does not keep yet — what "closed"
 * means for a fact that is still true is a decision, not a column — and remain stage 2
 * (docs/handoff/PHASE-ADMIN.md).
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
        $qp   = $req->getQueryParams();

        // `?tab=high` is what the top bar's pill and every older link carry; it is the
        // Severity chip set to high, so both addresses reach one view.
        if (($qp['tab'] ?? '') === 'high' && ($qp['severity'] ?? '') === '') $qp['severity'] = ConsoleAlerts::HIGH;
        unset($qp['tab']);
        $severity = (string) ($qp['severity'] ?? '');
        $source   = (string) ($qp['source'] ?? '');

        $all  = ConsoleAlerts::open();
        $list = array_values(array_filter($all, static fn (array $a): bool =>
            ($severity === '' || $a['severity'] === $severity) && ($source === '' || $a['source'] === $source)));

        $want = (string) ($qp['alert'] ?? '');
        $focus = null;
        foreach ($list as $a) if ($a['key'] === $want) $focus = $a;
        $focus ??= $list[0] ?? null;

        $canAct = static fn (?string $href): bool => $href !== null && Permissions::canOpen($role, $href);

        // The chips' options, with counts, from the alerts that are open — never a fixed
        // list, so a chip can never offer a value that matches nothing.
        $count = static function (string $k) use ($all): array {
            $n = [];
            foreach ($all as $a) $n[$a[$k]] = ($n[$a[$k]] ?? 0) + 1;
            return $n;
        };
        $sev = [];
        foreach ([ConsoleAlerts::HIGH, ConsoleAlerts::MEDIUM, ConsoleAlerts::LOW] as $s) {
            $c = $count('severity')[$s] ?? 0;
            if ($c > 0) $sev[] = ['v' => $s, 'l' => ucfirst($s), 'n' => $c];
        }
        $src = [];
        foreach ($count('source') as $s => $c) $src[] = ['v' => $s, 'l' => $s, 'n' => $c];

        // The timeline: what the status page recorded every fifteen minutes, worst state
        // per bucket, a gap where nothing was recorded at all.
        $range = TimeRange::fromQuery($qp, '7d');
        $strip = TimeSeries::worst(
            SystemStatus::overallBetween($range->fromSql(), $range->toSql()), $range,
            static fn (string $st): ?int => SystemStatus::rank($st));

        return $this->view->render($res, 'admin/alerts.twig', [
            'page_title' => 'Alerts',
            'admin_page' => 'alerts',
            'alerts'     => array_map(static fn (array $a): array => $a + ['can_act' => $canAct($a['href'])], $list),
            'focus'      => $focus ? $focus + ['can_act' => $canAct($focus['href'])] : null,
            'n_open'     => count($all),
            'filters'    => [
                ['key' => 'severity', 'label' => 'Severity', 'value' => $severity, 'options' => $sev],
                ['key' => 'source',   'label' => 'Source',   'value' => $source,   'options' => $src],
            ],
            'params'     => array_intersect_key($qp, array_flip(['severity', 'source', 'range', 'from', 'to'])),
            'range'      => $range,
            'strip'      => $strip,
            'labels'     => SystemStatus::LABELS,
        ]);
    }
}
