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
        // Seeds for the Alpine repeaters (stable ids → editable rows without the focus bug).
        $schedSeed = [];
        foreach ((json_decode((string)($row['schedule'] ?? '[]'), true) ?: []) as $i => $s) {
            $schedSeed[] = ['id' => $i + 1, 'time' => (string)($s['time'] ?? ''), 'title' => (string)($s['title'] ?? ''), 'body' => (string)($s['body'] ?? '')];
        }
        $tierSeed = [];
        foreach ((json_decode((string)($row['ticket_tiers'] ?? '[]'), true) ?: []) as $i => $t) {
            $tierSeed[] = ['id' => $i + 1, 'name' => (string)($t['name'] ?? ''), 'price' => (int)($t['price'] ?? 0), 'perk' => (string)($t['perk'] ?? ''), 'sold' => !empty($t['sold_out'])];
        }
        return $this->view->render($res, 'admin/events/form.twig', [
            'page_title' => $id ? 'Edit Event — Admin' : 'New Event — Admin',
            'admin_page' => 'events',
            'row'        => $row,
            'is_new'     => !$id,
            'sched_seed' => $schedSeed,
            'tier_seed'  => $tierSeed,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $slug = preg_replace('/[^a-z0-9-]+/i', '-', strtolower(trim((string)($b['slug'] ?: $b['title'] ?? ''))));

        // Run-of-show: parallel arrays from the repeater → JSON [{time,title,body}].
        $schedule = [];
        $sTitle = (array)($b['sched_title'] ?? []); $sTime = (array)($b['sched_time'] ?? []); $sBody = (array)($b['sched_body'] ?? []);
        foreach ($sTitle as $i => $title) {
            $title = trim((string)$title);
            if ($title === '') continue;
            $schedule[] = ['time' => mb_substr(trim((string)($sTime[$i] ?? '')), 0, 40), 'title' => mb_substr($title, 0, 160), 'body' => mb_substr(trim((string)($sBody[$i] ?? '')), 0, 300)];
        }
        // Ticket tiers: parallel arrays (sold-out via a hidden 1/0 field for index alignment) → JSON.
        $tiers = [];
        $tName = (array)($b['tier_name'] ?? []); $tPrice = (array)($b['tier_price'] ?? []); $tPerk = (array)($b['tier_perk'] ?? []); $tSold = (array)($b['tier_soldout'] ?? []);
        foreach ($tName as $i => $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $tiers[] = ['name' => mb_substr($name, 0, 80), 'price' => max(0, (int)($tPrice[$i] ?? 0)), 'perk' => mb_substr(trim((string)($tPerk[$i] ?? '')), 0, 120), 'sold_out' => (string)($tSold[$i] ?? '0') === '1'];
        }

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
            'capacity'    => trim((string)($b['capacity'] ?? '')) !== '' ? max(0, (int)$b['capacity']) : null,
            'schedule'            => $schedule ? json_encode($schedule) : null,
            'map_embed'           => trim((string)($b['map_embed'] ?? '')) ?: null,
            'ticket_tiers'        => $tiers ? json_encode($tiers) : null,
            'early_bird_text'     => trim((string)($b['early_bird_text'] ?? '')) ?: null,
            'early_bird_deadline' => trim((string)($b['early_bird_deadline'] ?? '')) ?: null,
            'early_bird_url'      => trim((string)($b['early_bird_url'] ?? '')) ?: null,
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
