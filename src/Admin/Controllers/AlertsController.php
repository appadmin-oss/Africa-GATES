<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\ConsoleAlertLog;
use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Admin\Support\TimeRange;
use AfricaGates\Admin\Support\TimeSeries;
use AfricaGates\Services\SystemStatus;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The console's alerts: what the platform noticed on its own, from {@see ConsoleAlerts},
 * with the human half — first seen, resolved, owner, timeline — from {@see ConsoleAlertLog}.
 * Resolved is written when a check passes again, never by a button: an alert closed by hand
 * while its check still fails would be the console saying something broken is fine.
 *
 * Gated `health` (superadmin, admin, viewer) through the path map, like the top bar's
 * pill that links here. An alert's own "what to do" link is drawn only when this role can
 * open where it points: a viewer told about chargebacks is not handed a door to a money
 * screen the guard would close.
 */
final class AlertsController
{
    /** The service strip's window and its blocks: seven days of twelve hours. */
    private const STRIP_DAYS = 7;

    public function __construct(
        private readonly Twig $view,
        private readonly ?\AfricaGates\Admin\Services\AuditService $audit = null,
    ) {}

    /**
     * GET /admin/alerts — AdminConsole v2 (README §10b), rendered from `admin/alerts/index.twig`.
     *
     * The impact banner, the service strip, Open and Resolved tabs, severity chips with
     * counts, an "Affects production" chip, a source select, and the list beside a sticky
     * detail: summary, who it reaches, facts, what to do, copy the diagnosis, recheck,
     * owner and timeline. Opening the page syncs the alert log, so what it shows as
     * resolved is what the checks say now.
     */
    public function index(Request $req, Response $res): Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $qp   = $req->getQueryParams();

        ConsoleAlertLog::sync();

        // `?tab=high` is what older links carry; it is the High chip.
        if (($qp['tab'] ?? '') === 'high') { $qp['severity'] = ConsoleAlerts::HIGH; $qp['tab'] = 'open'; }
        $tab      = ($qp['tab'] ?? 'open') === 'resolved' ? 'resolved' : 'open';
        $severity = in_array($qp['severity'] ?? '', [ConsoleAlerts::HIGH, ConsoleAlerts::MEDIUM, ConsoleAlerts::LOW], true) ? (string) $qp['severity'] : '';
        $prodOnly = ($qp['impact'] ?? '') === 'production';
        $source   = (string) ($qp['source'] ?? '');

        $all = ConsoleAlertLog::annotate(ConsoleAlerts::open());
        $resolved = ConsoleAlertLog::resolvedSince(7);
        $base = $tab === 'open' ? $all : $resolved;
        $list = array_values(array_filter($base, static fn (array $a): bool =>
            ($severity === '' || $a['severity'] === $severity)
            && (!$prodOnly || in_array($a['impact'] ?? null, ['affects', 'may'], true))
            && ($source === '' || $a['source'] === $source)));

        $want = (string) ($qp['alert'] ?? '');
        $focus = null;
        foreach ($list as $a) if ($a['key'] === $want) $focus = $a;
        $focus ??= $list[0] ?? null;

        $canAct = static fn (?string $href): bool => $href !== null && $href !== '' && Permissions::canOpen($role, $href);
        if ($focus !== null) {
            $focus['can_act']   = $tab === 'open' && $canAct($focus['href'] ?? null);
            $focus['timeline']  = ConsoleAlertLog::timeline((string) $focus['key']);
            $focus['diagnosis'] = $tab === 'open' ? self::diagnosis($focus) : '';
        }

        // Counts for the chips, from the tab's own unfiltered list, so a value never shows 0.
        $sev = [];
        foreach ([ConsoleAlerts::HIGH, ConsoleAlerts::MEDIUM, ConsoleAlerts::LOW] as $s) {
            $n = count(array_filter($base, static fn (array $a): bool => $a['severity'] === $s));
            if ($n > 0 || $severity === $s) $sev[] = ['v' => $s, 'l' => ucfirst($s), 'n' => $n];
        }
        $sources = array_values(array_unique(array_column($base, 'source')));
        sort($sources);
        $prodN = count(array_filter($base, static fn (array $a): bool => in_array($a['impact'] ?? null, ['affects', 'may'], true)));

        $affects = array_values(array_filter($all, static fn (array $a): bool => ($a['impact'] ?? null) === 'affects'));
        $may     = array_values(array_filter($all, static fn (array $a): bool => ($a['impact'] ?? null) === 'may'));

        return $this->view->render($res, 'admin/alerts/index.twig', [
            'page_title' => 'Alerts',
            'admin_page' => 'alerts',
            'tab'        => $tab,
            'n_open'     => count($all),
            'n_resolved' => count($resolved),
            'list'       => $list,
            'focus'      => $focus,
            'severity'   => $severity,
            'prod_only'  => $prodOnly,
            'prod_n'     => $prodN,
            'source'     => $source,
            'sev_chips'  => $sev,
            'sources'    => $sources,
            'affects'    => $affects,
            'may'        => $may,
            'services'   => self::services(),
            'can_write'  => Permissions::canWrite($role),
            'admin_id'   => (int) ($_SESSION['admin_id'] ?? 0),
            'logged'     => ConsoleAlertLog::ready(),
            'params'     => array_filter(['tab' => $tab === 'resolved' ? 'resolved' : null, 'severity' => $severity ?: null,
                                          'impact' => $prodOnly ? 'production' : null, 'source' => $source ?: null]),
        ]);
    }

    /** POST /admin/alerts/{key}/assign — "Assign to me". */
    public function assign(Request $req, Response $res, array $args): Response
    {
        $key = (string) ($args['key'] ?? '');
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        if (in_array($key, ConsoleAlerts::keys(), true) && ConsoleAlertLog::assign($key, $adminId)) {
            $this->audit?->record($adminId, 'alert.assign', null, null, ['alert' => $key]);
            $_SESSION['flash_ok'] = 'You own this alert now. It is on its timeline.';
        } else {
            $_SESSION['flash_error'] = 'That alert is not open any more, so there is nothing to take.';
        }
        return $res->withHeader('Location', '/admin/alerts?alert=' . rawurlencode($key))->withStatus(303);
    }

    /**
     * Each status-page component: its last recorded state and seven days of twelve-hour
     * blocks, worst state per block, a gap where nothing was recorded.
     *
     * @return list<array{name:string, status:string, label:string, blocks:list<array<string,mixed>>}>
     */
    private static function services(): array
    {
        $range = TimeRange::between(time() - self::STRIP_DAYS * 86400, time());
        $hist = SystemStatus::componentsBetween($range->fromSql(), $range->toSql());
        $now = SystemStatus::snapshot();
        $names = $now !== null ? array_column($now['components'], 'name') : array_keys($hist);
        $out = [];
        foreach ($names as $i => $name) {
            $strip = TimeSeries::worst($hist[$name] ?? [], $range, static fn (string $st): ?int => SystemStatus::rank($st), 14);
            $cur = $now['components'][$i] ?? null;
            $out[] = [
                'name'   => (string) $name,
                'status' => (string) ($cur['status'] ?? 'unknown'),
                'label'  => (string) ($cur['label'] ?? SystemStatus::LABELS['unknown']),
                'blocks' => $strip['bars'],
            ];
        }
        return $out;
    }

    /** The alert as plain text, for pasting into a ticket or a message to a developer. */
    private static function diagnosis(array $a): string
    {
        $impact = match ($a['impact'] ?? null) { 'affects' => 'affects production', 'may' => 'may affect production', default => 'no production impact' };
        $lines = [
            'Alert: ' . $a['title'],
            'Severity: ' . ucfirst((string) $a['severity']) . ' · ' . ucfirst($impact),
            'Source: ' . $a['source'],
            'First seen: ' . ($a['first_seen'] ?? 'unknown'),
        ];
        if (($a['reopened'] ?? 0) > 0) $lines[] = 'Has come back ' . $a['reopened'] . ' time' . ($a['reopened'] === 1 ? '' : 's');
        if (($a['who'] ?? '') !== '') $lines[] = 'Who it reaches: ' . $a['who'];
        $lines[] = '';
        $lines[] = (string) $a['summary'];
        foreach ($a['facts'] ?? [] as [$k, $v]) $lines[] = $k . ': ' . $v;
        if (!empty($a['steps'])) {
            $lines[] = '';
            $lines[] = 'What to do:';
            foreach ($a['steps'] as $i => $st) $lines[] = ($i + 1) . '. ' . $st;
        }
        $lines[] = '';
        $lines[] = 'Read at ' . date('Y-m-d H:i') . ' UTC from /admin/alerts';
        return implode("\n", $lines);
    }
}
