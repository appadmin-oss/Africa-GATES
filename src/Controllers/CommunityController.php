<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CommunityService, CacheService, OtpService, Notifier, RateLimitService};
use Illuminate\Database\Capsule\Manager as DB;

class CommunityController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CommunityService $community,
        private readonly CacheService $cache,
        private readonly ?OtpService $mailer = null,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    private function tooMany(Request $req, string $action, int $max, int $win): bool {
        if (!$this->rateLimit) return false;
        $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
        return !$this->rateLimit->check(hash('sha256', $ip), $action, $max, $win);
    }

    // ── Threads list (forum index) ──────────────────────────────
    public function threadsIndex(Request $req, Response $res): Response
    {
        $progId = (int)($req->getQueryParams()['programme'] ?? 0);
        $threads = $this->community->listThreads($progId ?: null, 60);
        $progs = DB::table('gates_award_programmes')->where('is_active', 1)->orderBy('sort_order')->get()->map(fn($r) => (array)$r)->all();
        return $this->view->render($res, 'pages/community/threads.twig', [
            'page_title' => 'Programme channels — Africa GATES',
            'meta_description' => 'Join the Africa GATES community. Discuss award programmes, rally support for nominees and connect with the people shaping African cultural recognition.',
            'gates_page' => 'community',
            'threads' => $threads,
            'programmes' => $progs,
            'active_programme' => $progId,
        ]);
    }

    // ── Single thread ───────────────────────────────────────────
    public function threadShow(Request $req, Response $res, array $args): Response
    {
        $data = $this->community->getThread($args['slug'] ?? '');
        if (!$data) throw new \Slim\Exception\HttpNotFoundException($req);
        $body = trim(strip_tags((string)($data['thread']['body'] ?? '')));
        $meta = $body !== ''
            ? (mb_strlen($body) > 160 ? rtrim(mb_substr($body, 0, 157)) . '…' : $body)
            : ($data['thread']['title'] . ' — join the conversation in the Africa GATES community and help shape continental cultural recognition.');
        return $this->view->render($res, 'pages/community/thread.twig', [
            'page_title' => $data['thread']['title'] . ' — Africa GATES',
            'meta_description' => $meta,
            'og_title' => $data['thread']['title'] . ' — Africa GATES Community',
            'gates_page' => 'community',
            'thread' => $data['thread'],
            'replies' => $data['replies'],
        ]);
    }

    // ── New thread (form) ───────────────────────────────────────
    public function threadNew(Request $req, Response $res): Response
    {
        $progs = DB::table('gates_award_programmes')->where('is_active', 1)->orderBy('sort_order')->get()->map(fn($r) => (array)$r)->all();
        return $this->view->render($res, 'pages/community/new-thread.twig', [
            'page_title' => 'Start a thread — Africa GATES',
            'meta_description' => 'Start a new thread in the Africa GATES community — open a discussion on an award programme, champion a nominee or rally the continent behind African excellence.',
            'gates_page' => 'community',
            'programmes' => $progs,
            'error' => $_SESSION['flash_error'] ?? null,
        ]);
    }

    public function threadCreate(Request $req, Response $res): Response
    {
        unset($_SESSION['flash_error']);
        $b = (array)$req->getParsedBody();
        $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
        $r = $this->community->postThread($b, $ip);
        if (!$r['ok']) {
            $_SESSION['flash_error'] = $r['message'];
            return $res->withHeader('Location', '/community/new')->withStatus(302);
        }
        $slug = $r['slug'];
        // Alert moderators about the new thread (esp. ones held for review).
        Notifier::adminAlert($this->mailer, 'New community thread (' . $r['status'] . ')',
            "Title: " . trim((string)($b['title'] ?? '')) . "\nBy: " . trim((string)($b['author_name'] ?? '')) . "\nStatus: " . $r['status'] . "\nSlug: " . $slug);
        if ($r['status'] !== 'approved') {
            $_SESSION['flash_notice'] = 'Thread is queued for review.';
            return $res->withHeader('Location', '/community')->withStatus(302);
        }
        return $res->withHeader('Location', '/community/' . $slug)->withStatus(302);
    }

    // ── Comment API (used on profiles, legacy, threads) ─────────
    public function comment(Request $req, Response $res): Response
    {
        if ($this->tooMany($req, 'community_comment', 8, 3600)) {
            $res->getBody()->write(json_encode(['success' => false, 'message' => 'You are posting too fast. Please wait a moment.']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(429);
        }
        $b = (array)$req->getParsedBody();
        $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
        $targetType = (string)($b['target_type'] ?? '');
        $targetId   = (int)($b['target_id'] ?? 0);
        if ($targetType === 'thread') {
            $r = $this->community->replyToThread($targetId, $b, $ip);
        } else {
            $r = $this->community->postComment($targetType, $targetId, $b, $ip);
        }
        // Normalise: service returns 'ok', API callers expect 'success'
        $out = array_merge(['success' => (bool)($r['ok'] ?? false)], $r);
        $res->getBody()->write(json_encode($out));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($out['success'] ? 200 : 422);
    }

    // ── Cheer toggle ────────────────────────────────────────────
    public function cheer(Request $req, Response $res): Response
    {
        if ($this->tooMany($req, 'community_cheer', 40, 3600)) {
            $res->getBody()->write(json_encode(['success' => false, 'message' => 'Too many actions. Please slow down.']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(429);
        }
        $b = (array)$req->getParsedBody();
        $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
        $targetType = (string)($b['target_type'] ?? '');
        $targetId   = (int)($b['target_id'] ?? 0);
        // Fingerprint = session id + ip-hash (good enough for client-side cheer dedupe)
        $sid = $_COOKIE['PHPSESSID'] ?? session_id() ?: 'anon';
        $fp = hash('sha256', $sid . '|' . $ip . '|' . $targetType);
        $r = $this->community->toggleCheer($targetType, $targetId, $fp);
        $res->getBody()->write(json_encode(array_merge(['success' => true], $r)));
        return $res->withHeader('Content-Type', 'application/json');
    }

    // ── Activity feed JSON ──────────────────────────────────────
    public function activity(Request $req, Response $res): Response
    {
        $limit = min(60, max(5, (int)($req->getQueryParams()['limit'] ?? 30)));
        $items = $this->community->activityFeed($limit);
        $res->getBody()->write(json_encode(['success' => true, 'items' => $items]));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
