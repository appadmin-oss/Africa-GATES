<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\Filters;

/**
 * Event registrations (gates_event_registrations) — paginated, filterable list
 * with CSV export. Read-only "data" section view (superadmin/admin/viewer).
 */
class RegistrationsController
{
    private const PER_PAGE = 50;

    public function __construct(private readonly Twig $view) {}

    /** Attendee PII → limited to the see-all roles (superadmin/admin/viewer), not editor/moderator. */
    private function blocked(Response $res): ?Response
    {
        if (in_array((string) ($_SESSION['admin_role'] ?? ''), ['superadmin', 'admin', 'viewer'], true)) return null;
        $_SESSION['flash_error'] = 'You don’t have access to event registrations.';
        return $res->withHeader('Location', '/admin/data')->withStatus(302);
    }

    /** @return \Illuminate\Database\Query\Builder filtered registrations query (joined to the event). */
    private function query(int $eventId, string $q)
    {
        $base = DB::table('gates_event_registrations as r')
            ->leftJoin('gates_site_events as e', 'e.id', '=', 'r.event_id');
        if ($eventId > 0) $base->where('r.event_id', $eventId);
        if ($q !== '') {
            $base->where(function ($w) use ($q) {
                $w->where('r.name', 'like', "%{$q}%")->orWhere('r.email', 'like', "%{$q}%")->orWhere('r.phone', 'like', "%{$q}%");
            });
        }
        return $base;
    }

    public function index(Request $req, Response $res): Response
    {
        if ($deny = $this->blocked($res)) return $deny;
        $qp      = $req->getQueryParams();
        $eventId = (int) ($qp['event'] ?? 0);
        $q       = trim((string) ($qp['q'] ?? ''));
        $page    = max(1, (int) ($qp['page'] ?? 1));

        $events = DB::table('gates_site_events')->orderByDesc('event_date')->get(['id', 'title'])->map(fn($r) => (array) $r)->all();

        $base = $this->query($eventId, $q);
        $dateMeta = Filters::applyDateRange($base, 'r.created_at', $qp);

        $total = (int) (clone $base)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = Filters::clampPage($page, $pages);

        $rows = (clone $base)
            ->orderByDesc('r.created_at')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['r.id', 'r.name', 'r.email', 'r.phone', 'r.tier', 'r.created_at', 'r.event_id', 'e.title as event_title'])
            ->map(fn($r) => (array) $r)->all();

        return $this->view->render($res, 'admin/registrations/index.twig', [
            'page_title' => 'Event Registrations — Admin',
            'admin_page' => 'registrations',
            'rows'       => $rows,
            'events'     => $events,
            'event_id'   => $eventId,
            'q'          => $q,
            'page'       => $page,
            'pages'      => $pages,
            'total'      => $total,
            'window'     => Filters::pageWindow($page, $pages),
            'range'      => $dateMeta['range'],
            'from'       => $dateMeta['from'],
            'to'         => $dateMeta['to'],
            'qs'         => Filters::qs(['event' => $eventId, 'q' => $q, 'range' => $dateMeta['range'], 'from' => $dateMeta['from'], 'to' => $dateMeta['to']]),
        ]);
    }

    /** Stream the (filtered) registrations as a CSV download. */
    public function export(Request $req, Response $res): Response
    {
        if ($deny = $this->blocked($res)) return $deny;
        $qp      = $req->getQueryParams();
        $eventId = (int) ($qp['event'] ?? 0);
        $q       = trim((string) ($qp['q'] ?? ''));

        $base = $this->query($eventId, $q);
        Filters::applyDateRange($base, 'r.created_at', $qp);
        $rows = $base->orderByDesc('r.created_at')
            ->get(['e.title as event_title', 'r.name', 'r.email', 'r.phone', 'r.tier', 'r.created_at']);

        $fh = fopen('php://temp', 'r+');
        // Pass the escape arg explicitly — PHP 8.4 deprecates relying on its default.
        fputcsv($fh, ['Event', 'Name', 'Email', 'Phone', 'Tier', 'Registered at'], ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fh, [(string) $r->event_title, (string) $r->name, (string) $r->email, (string) $r->phone, (string) $r->tier, (string) $r->created_at], ',', '"', '\\');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $res->getBody()->write($csv);
        return $res
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="event-registrations.csv"');
    }
}
