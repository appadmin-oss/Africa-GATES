<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AnalyticsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * One page of rates, ratios and funnels — everything the dashboard could not say.
 *
 * ── WHY IT IS SEPARATE FROM THE DASHBOARD ────────────────────────────────────
 *
 * The dashboard is a glance: eleven counts and a queue, for somebody who has two
 * minutes between other things. This is a sitting-down page. Merging them would
 * either bury the queue under charts or reduce the charts to decoration, and the
 * two are read at genuinely different moments.
 *
 * ── AND WHY IT IS `data`, NOT `finance` ──────────────────────────────────────
 *
 * There is not one naira on this page. It counts votes, accounts, nominations,
 * feed posts and tickets — the operational picture, which an editor planning
 * coverage and a moderator sizing a queue both have a legitimate need for.
 * Gating it behind finance would hide the growth of the platform from the people
 * running it in order to protect revenue figures it does not contain.
 *
 * The money view is next door at /admin/finance and stays superadmin+admin.
 */
final class AnalyticsController
{
    /** Window presets, in days. No "all time": a rate over all time is not a rate. */
    private const RANGES = [
        '7'   => 'Last 7 days',
        '30'  => 'Last 30 days',
        '90'  => 'Last 90 days',
        '365' => 'Last 12 months',
    ];

    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $qp    = (array) $req->getQueryParams();
        $range = (string) ($qp['range'] ?? '30');
        if (!isset(self::RANGES[$range])) $range = '30';
        $days = (int) $range;

        return $this->view->render($res, 'admin/analytics.twig', [
            'page_title' => 'Analytics — Admin',
            'admin_page' => 'analytics',
            'ranges'     => self::RANGES,
            'range'      => $range,
            'days'       => $days,

            'audience'   => AnalyticsService::audience($days),
            'voting'     => AnalyticsService::voting($days),
            'retention'  => AnalyticsService::retention(8),
            'noms'       => AnalyticsService::nominationFunnel(),
            'ballot'     => AnalyticsService::ballotFunnel($days),
            'geo'        => AnalyticsService::geography(12),
            'community'  => AnalyticsService::community($days),
            'support'    => AnalyticsService::support($days),
            'mail'       => AnalyticsService::deliverability($days),
            // gates_events: written on four paths since the day it was added, read by
            // nothing until this line. See AnalyticsService::platformEvents().
            'events'     => AnalyticsService::platformEvents($days),
        ]);
    }
}
