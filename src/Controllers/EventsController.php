<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\CacheService;

class EventsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $now = Carbon::now()->toDateTimeString();
        $upcoming = $this->cache->remember('events:upcoming', 900, fn() =>
            DB::table('gates_site_events')->where('status', 'published')
                ->where('event_date', '>=', $now)
                ->orderBy('event_date')->get()->map(fn($r) => (array)$r)->all()
        );
        $past = $this->cache->remember('events:past', 1800, fn() =>
            DB::table('gates_site_events')->where('status', 'published')
                ->where('event_date', '<', $now)
                ->orderByDesc('event_date')->limit(12)->get()->map(fn($r) => (array)$r)->all()
        );
        return $this->view->render($res, 'pages/events.twig', [
            'page_title'       => 'Events — Africa GATES',
            'meta_description' => 'Ceremonies, webinars and community sessions across the Africa GATES cycle.',
            'gates_page'       => 'events',
            'has_hero'         => true,
            'upcoming'         => $upcoming,
            'past'             => $past,
        ]);
    }
}
