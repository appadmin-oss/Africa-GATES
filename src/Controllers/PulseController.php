<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{CacheService, ProfileService};

/**
 * Pulse — the living feed. The design is an Instagram-style hub; this build keeps
 * that look but is driven entirely by REAL data (published posts, upcoming events,
 * top profiles, community threads) — no fabricated likes, views or member counts.
 * Every query is guarded so a missing table never 500s the page.
 */
final class PulseController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly ProfileService $profiles,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $data = $this->cache->remember('pulse:home', 600, function () {
            return [
                'posts'   => $this->safe(fn() => DB::table('gates_posts')->where('status', 'published')
                    ->orderByDesc('published_at')->limit(8)->get()->map(fn($r) => (array)$r)->all()),
                'events'  => $this->safe(fn() => DB::table('gates_site_events')->where('status', 'published')
                    ->where('event_date', '>=', date('Y-m-d H:i:s'))->orderBy('event_date')->limit(6)
                    ->get()->map(fn($r) => (array)$r)->all()),
                'threads' => $this->safe(fn() => DB::table('gates_threads')
                    ->where('status', 'approved')
                    ->orderByDesc('id')->limit(5)->get()->map(fn($r) => (array)$r)->all()),
            ];
        }, ['registry']);

        $leaders = $this->cache->remember('pulse:leaders', 600, fn() => $this->profiles->getLeaderboard(8), ['leaderboard']);

        return $this->view->render($res, 'pages/pulse.twig', [
            'page_title'       => 'Pulse — Africa GATES',
            'meta_description' => 'Pulse — the living feed of Africa GATES: the latest posts, events, and the community shaping the continental Cultural Power Index.',
            'gates_page'       => 'pulse',
            'has_hero'         => false,
            'posts'            => $data['posts'],
            'events'           => $data['events'],
            'threads'          => $data['threads'],
            'leaders'          => $leaders,
        ]);
    }

    /**
     * Run a feed query, and never let one broken section take the whole page down.
     *
     * ── BUT NOT SILENTLY ─────────────────────────────────────────────────────
     *
     * This swallowed everything and returned `[]`, which is indistinguishable from
     * "there is nothing to show". So a missing table or column — the normal state of a
     * database between a deploy and someone running `db:migrate` — rendered Pulse as a
     * page of empty sections with no error, nothing in the log and nothing to search
     * for. "Pulse is not operational" and "nobody has posted yet" looked identical.
     *
     * The degradation is still correct: one failing query must not 500 the feed. What
     * was wrong was doing it in silence, so now the reason is written down and
     * `var/logs` can answer the question instead of a guess having to.
     */
    private function safe(callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('[pulse] feed section failed, rendering it empty: ' . $e->getMessage());
            return [];
        }
    }
}
