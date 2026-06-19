<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\CacheService;

class EventsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        private readonly CacheService $cache,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_site_events')->orderByDesc('event_date')->get()->map(fn($r)=>(array)$r)->all();
        return $this->view->render($res, 'admin/events/index.twig', [
            'page_title' => 'Events — Admin',
            'admin_page' => 'events',
            'rows'       => $rows,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_site_events')->where('id', $id)->first() : [];
        return $this->view->render($res, 'admin/events/form.twig', [
            'page_title' => $id ? 'Edit Event — Admin' : 'New Event — Admin',
            'admin_page' => 'events',
            'row'        => $row,
            'is_new'     => !$id,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $slug = preg_replace('/[^a-z0-9-]+/i', '-', strtolower(trim((string)($b['slug'] ?: $b['title'] ?? ''))));
        $data = [
            'slug'        => trim($slug, '-'),
            'title'       => trim((string)($b['title'] ?? '')),
            'tagline'     => trim((string)($b['tagline'] ?? '')),
            'description' => trim((string)($b['description'] ?? '')),
            'location'    => trim((string)($b['location'] ?? '')),
            'venue'       => trim((string)($b['venue'] ?? '')),
            'event_date'  => (string)($b['event_date'] ?: Carbon::now()->toDateTimeString()),
            'end_date'    => $b['end_date'] ?: null,
            'cover_image' => trim((string)($b['cover_image'] ?? '')),
            'rsvp_url'    => trim((string)($b['rsvp_url'] ?? '')),
            'status'      => in_array($b['status'] ?? '', ['published','draft'], true) ? $b['status'] : 'draft',
        ];
        if ($data['title'] === '' || $data['slug'] === '') {
            $_SESSION['flash_error'] = 'Title and slug are required.';
            return $res->withHeader('Location', $id ? "/admin/events/{$id}" : '/admin/events/new')->withStatus(302);
        }
        if ($id) {
            DB::table('gates_site_events')->where('id', $id)->update($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'event.update', 'site_event', $id);
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int)DB::table('gates_site_events')->insertGetId($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'event.create', 'site_event', $id);
        }
        $this->cache->forget('events:upcoming');
        $this->cache->forget('events:past');
        $this->cache->forget('home:site_events');
        $_SESSION['flash_ok'] = 'Event saved.';
        return $res->withHeader('Location', '/admin/events')->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        DB::table('gates_site_events')->where('id', $id)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'event.delete', 'site_event', $id);
        $this->cache->forget('events:upcoming');
        $this->cache->forget('events:past');
        $this->cache->forget('home:site_events');
        $_SESSION['flash_ok'] = 'Event deleted.';
        return $res->withHeader('Location', '/admin/events')->withStatus(302);
    }
}
