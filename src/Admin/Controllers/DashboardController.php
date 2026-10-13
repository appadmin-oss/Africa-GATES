<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Admin\Services\AuditService;

class DashboardController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        /** Only for the stalled-schedule alert; nullable so a mailerless build still renders. */
        private readonly ?\AfricaGates\Services\OtpService $mailer = null,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        // ── A STALLED SCHEDULE, EMAILED ONCE A DAY ───────────────────────────
        //
        // The layout banner covers the admin who is already looking. This covers
        // the days nobody looks, which are the days it matters — reconciliation and
        // automatic refunds live entirely inside the maintenance run, so a stall
        // means supporters who are owed money quietly stop being paid.
        //
        // Sent from a page load rather than from maintenance because a run that has
        // stopped cannot report that it has stopped. `claimAlert()` holds it to one
        // email a day; without that it would send one per click.
        if ($this->mailer !== null && \AfricaGates\Support\CronHealth::claimAlert()) {
            $h = \AfricaGates\Support\CronHealth::status();
            \AfricaGates\Services\Notifier::adminAlert(
                $this->mailer,
                'Scheduled maintenance has stopped',
                ($h['say'] ?? 'Scheduled maintenance is not running.') . "\n\n"
                . "Until it runs again: payments that the browser callback missed are NOT being "
                . "confirmed, and money owed for votes that could not be minted is NOT being "
                . "returned. Neither shows up anywhere else — the site serves normally throughout.\n\n"
                . "Last recorded run: " . ($h['last'] ?? 'never') . "\n"
                . "Fix: re-check the webcron job, or press \"Run maintenance now\" in "
                . "Settings → Automation & cron."
            );
        }

        // ── HOME, REBUILT FROM THE ADMIN HANDOFF (README §3, 4 Oct 2026) ─────
        //
        // The hero ask box, the shortcut tiles, "N things need a person" and "The quiet
        // numbers". Every figure is real and every card, tile and count is filtered by
        // what this role can open (HomeBoard → Permissions::canOpen, the guard's own
        // function). The old dashboard's region and tier distributions, fourteen-day vote
        // chart, top nominees, last twelve audit rows and integrity brief are not on the
        // handoff's Home; each is listed with its new home or as awaiting the owner in
        // docs/handoff/inventory/_admin.md. The integrity brief's JSON route stays.
        $role = (string) ($_SESSION['admin_role'] ?? '');

        return $this->view->render($res, 'admin/dashboard.twig', [
            'page_title' => 'Home — Africa GATES Admin',
            'admin_page' => 'dashboard',
            'board'      => \AfricaGates\Admin\Services\HomeBoard::board($role),
            'shortcuts'  => \AfricaGates\Admin\Services\HomeBoard::shortcuts($role),
            'quiet'      => \AfricaGates\Admin\Services\HomeBoard::quiet(),
        ]);
    }

    /** On-demand AI integrity briefing (JSON) for the dashboard button. */
    public function integrityBrief(Request $req, Response $res): Response
    {
        $r = \AfricaGates\Services\IntegrityBriefService::brief();
        $res->getBody()->write((string) json_encode([
            'ok' => true, 'text' => $r['text'], 'ai' => $r['ai'], 'total' => $r['signals']['total'] ?? 0,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
