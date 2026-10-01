<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\Mail\MailConfig;
use AfricaGates\Services\Mail\MailFailure;
use AfricaGates\Services\Mail\MailHealth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * /admin/settings/mail — is email working, and if not, exactly why.
 *
 * The page every mail alert links to. It shows the open incident (if any), the last
 * automatic diagnosis step by step, the last hour of the log grouped by CAUSE rather
 * than as raw rows, and the incident history — so "were we told, and when did it start"
 * has an answer on a screen.
 *
 * Linked from Settings → Email & sender and from the console banner, never added to
 * the rail: a sub-page belongs under the page it is about (CLAUDE.md, admin nav).
 */
final class MailHealthController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $w = MailHealth::window();
        $causes = [];
        foreach ($w['causes'] as $cause => $n) {
            $causes[] = ['cause' => $cause, 'n' => $n,
                         'title' => MailFailure::title($cause), 'fix' => MailFailure::fix($cause)];
        }

        $open = MailHealth::open();

        return $this->view->render($res, 'admin/mail-health.twig', [
            'page_title'   => 'Email health',
            'topbar_title' => 'Email health',
            'admin_page'   => 'settings',
            'mail_page'    => true,
            'open'         => $open,
            'open_report'  => $open ? (json_decode((string) $open->report_json, true) ?: null) : null,
            'report'       => MailHealth::lastReport(),
            'window'       => $w,
            'causes'       => $causes,
            'config'       => MailConfig::load()->describe(),
            // The stored cause is a key; the screen says what it means.
            'history'      => array_map(static fn (object $i): object
                => (object) ((array) $i + ['cause_title' => MailFailure::title((string) $i->cause)]),
                MailHealth::history()),
            'rules'        => [
                'window' => MailHealth::WINDOW_MIN, 'fails' => MailHealth::TRIP_FAILS,
                'rate'   => (int) round(MailHealth::TRIP_RATE * 100),
                'realert'=> MailHealth::REALERT_HOURS, 'probe' => MailHealth::PROBE_MIN,
            ],
        ]);
    }

    /**
     * POST — run the diagnosis now. Nothing is sent to anybody; see MailDiagnosis.
     *
     * Then a check, so a fix the operator just made can close the incident on the same
     * press rather than at the next tick — and so a fault found now opens one, with its
     * alerts, instead of waiting for the schedule to notice what the operator is looking at.
     */
    public function diagnose(Request $req, Response $res): Response
    {
        $health = new MailHealth();
        $r = $health->diagnoseNow();
        try { $health->check(); } catch (\Throwable) {}

        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'mail.diagnose', null, null,
            ['ok' => $r['ok'], 'cause' => $r['cause']]);

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? 'Every step passed — the server accepted our login and our From address. No message was sent.'
            : $r['title'] . '. ' . $r['fix'];

        return $res->withHeader('Location', '/admin/settings/mail')->withStatus(302);
    }
}
