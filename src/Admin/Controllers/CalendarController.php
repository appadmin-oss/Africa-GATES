<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\ConsoleCalendar;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The console's calendar (AdminConsole v2, README §10b): every deadline, event day and
 * draw across programmes, date-ordered, from {@see ConsoleCalendar} — the same list Home's
 * "This week" is cut from, so the two can never disagree.
 *
 * Three windows, as the design's tabs: the next fourteen days, this calendar month, and
 * later (up to six months out). Each tab carries its count, computed from the same list.
 */
final class CalendarController
{
    /** How far "Later" looks ahead. */
    private const LATER_DAYS = 180;

    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $role  = (string) ($_SESSION['admin_role'] ?? '');
        $today = (int) strtotime('today');
        $all   = ConsoleCalendar::between($today, $today + self::LATER_DAYS * 86400 - 1, $role);

        $monthStart = (int) strtotime('first day of this month 00:00');
        $monthEnd   = (int) strtotime('first day of next month 00:00') - 1;
        // This month reaches back to the 1st, so it needs its own read for the days gone.
        $month = ConsoleCalendar::between($monthStart, $monthEnd, $role);

        $edge = date('Y-m-d H:i:s', $today + 14 * 86400);
        $tabs = [
            'next'  => ['Next 14 days', array_values(array_filter($all, static fn (array $e): bool => $e['at'] < $edge))],
            'month' => ['This month', $month],
            'later' => ['Later', array_values(array_filter($all, static fn (array $e): bool => $e['at'] >= $edge))],
        ];
        $tab = isset($tabs[$req->getQueryParams()['tab'] ?? '']) ? (string) $req->getQueryParams()['tab'] : 'next';

        $rows = array_map(static function (array $e) use ($today): array {
            $t = (int) strtotime($e['at']);
            $days = (int) floor(($t - $today) / 86400);
            $e['days'] = $days;
            $e['status'] = match (true) {
                $t < time() && $days < 0 => ['Passed', 'grey'],
                $days === 0              => ['Today', 'red'],
                $days === 1              => ['Tomorrow', 'gold'],
                $days <= 3               => ['In ' . $days . ' days', 'gold'],
                default                  => ['In ' . $days . ' days', 'grey'],
            };
            return $e;
        }, $tabs[$tab][1]);

        return $this->view->render($res, 'admin/calendar.twig', [
            'page_title' => 'Calendar — Africa GATES Admin',
            'admin_page' => 'calendar',
            'tab'        => $tab,
            'tabs'       => array_map(static fn (array $t): array => ['label' => $t[0], 'n' => count($t[1])], $tabs),
            'rows'       => $rows,
            'areas'      => ConsoleCalendar::AREAS,
        ]);
    }
}
