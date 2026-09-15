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

    /**
     * Community v2 access model: READ is public, WRITE is members-only.
     * Returns the signed-in member (fresh row) or null for guests. Author
     * identity on posts always comes from the ACCOUNT, never from form input.
     */
    private function member(): ?array {
        return \AfricaGates\Services\UserAccountService::memberForForms();
    }

    /** Overwrite author fields with the member's account identity. */
    private function asMember(array $b, array $m): array {
        $b['author_name']    = $m['name'];
        $b['author_email']   = $m['email'];
        $b['author_user_id'] = $m['id'];
        return $b;
    }

    // ── Threads list (forum index) ──────────────────────────────
    public function threadsIndex(Request $req, Response $res): Response
    {
        $progId  = (int)($req->getQueryParams()['programme'] ?? 0);
        $sort    = in_array(($req->getQueryParams()['sort'] ?? ''), ['top','latest'], true) ? $req->getQueryParams()['sort'] : 'latest';
        $threads = $this->community->listThreads($progId ?: null, 40, $sort);

        // Spaces = active programmes, each with its real thread count + a stable colour.
        $palette = [['#5b3a8a','#f3eef7'],['#1a6118','#effaf0'],['#27607a','#e6f0f4'],['#a47306','#fff8df'],['#b03a5b','#fdeaf0'],['#2b373d','#e9efef']];
        $counts  = DB::table('gates_threads')->where('status','approved')->selectRaw('programme_id, COUNT(*) c')->groupBy('programme_id')->pluck('c','programme_id');
        $progRows = DB::table('gates_award_programmes')->where('is_active', 1)->orderBy('sort_order')->get();
        $spaces = [];
        foreach ($progRows as $i => $p) {
            $c = $palette[$i % count($palette)];
            $spaces[] = ['id' => (int)$p->id, 'name' => $p->title, 'count' => (int)($counts[$p->id] ?? 0), 'fg' => $c[0], 'bg' => $c[1]];
        }

        // Right-rail data — all real, all cheap.
        $stats = [
            'threads' => (int) DB::table('gates_threads')->where('status','approved')->count(),
            'replies' => (int) DB::table('gates_comments')->where('target_type','thread')->where('status','approved')->count(),
            'spaces'  => count($spaces),
        ];
        $trending = $this->community->listThreads(null, 200);
        usort($trending, fn($a,$b) => (($b['reply_count']??0)+($b['cheer_count']??0)) <=> (($a['reply_count']??0)+($a['cheer_count']??0)));
        $trending = array_slice($trending, 0, 5);
        $nextEvent = DB::table('gates_site_events')->where('status','published')
            ->where('event_date','>=', \Illuminate\Support\Carbon::now()->toDateTimeString())->orderBy('event_date')->first();

        return $this->view->render($res, 'pages/community/threads.twig', [
            'page_title' => 'The Community — Africa GATES',
            'meta_description' => 'Join the Africa GATES community. Discuss award programmes, rally support for nominees and connect with the people shaping African cultural recognition.',
            'gates_page' => 'community',
            'has_hero'   => false,
            'threads' => $threads,
            'spaces' => $spaces,
            'active_programme' => $progId,
            'active_sort' => $sort,
            'stats' => $stats,
            'trending' => $trending,
            'next_event' => $nextEvent ? (array)$nextEvent : null,
            'flash_notice' => $_SESSION['flash_notice'] ?? null,
            'member_logged_in' => !empty($_SESSION['user_id']),
            'member_name' => $_SESSION['user_name'] ?? null,
        ]);
    }

    // ── Single thread ───────────────────────────────────────────
    public function threadShow(Request $req, Response $res, array $args): Response
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $fp = $this->pollFingerprint($req, $userId);
        $data = $this->community->getThread($args['slug'] ?? '', $fp);
        if (!$data) throw new \Slim\Exception\HttpNotFoundException($req);
        $body = trim(strip_tags((string)($data['thread']['body'] ?? '')));
        $meta = $body !== ''
            ? (mb_strlen($body) > 160 ? rtrim(mb_substr($body, 0, 157)) . '…' : $body)
            : ($data['thread']['title'] . ' — join the conversation in the Africa GATES community and help shape continental cultural recognition.');
        $t = $data['thread'];
        $member = !empty($_SESSION['user_id'])
            ? ['name' => (string)($_SESSION['user_name'] ?? ''), 'email' => (string)($_SESSION['user_email'] ?? '')]
            : null;
        $related = array_values(array_filter(
            $this->community->listThreads(!empty($t['programme_id']) ? (int)$t['programme_id'] : null, 6),
            fn($x) => $x['slug'] !== $t['slug']
        ));
        $threadId = (int)$t['id'];
        $memberState = [
            'bookmarked' => $userId > 0 && $this->community->isBookmarked($userId, $threadId),
            'reposted'   => $userId > 0 && $this->community->isReposted($userId, $threadId),
            'following'  => $userId > 0 && $this->community->isFollowing($userId, 'thread', $threadId),
        ];
        return $this->view->render($res, 'pages/community/thread.twig', [
            'page_title' => $data['thread']['title'] . ' — Africa GATES',
            'meta_description' => $meta,
            'og_title' => $data['thread']['title'] . ' — Africa GATES Community',
            'gates_page' => 'community',
            'thread' => $t,
            'replies' => $data['replies'],
            'poll' => $data['poll'],
            'member' => $member,
            'member_logged_in' => $userId > 0,
            'member_id' => $userId,
            'member_state' => $memberState,
            'related' => array_slice($related, 0, 4),
        ]);
    }

    // ── New thread (form) ───────────────────────────────────────
    public function threadNew(Request $req, Response $res): Response
    {
        if (!$this->member()) {
            return $res->withHeader('Location', '/account/login?next=' . rawurlencode('/community/new'))->withStatus(302);
        }
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
        $m = $this->member();
        if (!$m) {
            return $res->withHeader('Location', '/account/login?next=' . rawurlencode('/community/new'))->withStatus(302);
        }
        // Volume control on thread creation (the AI spam filter is content-based, not
        // a rate limit) — mirrors the comment/cheer throttles already in this file.
        if ($this->tooMany($req, 'community_thread', 3, 3600)) {
            $_SESSION['flash_error'] = 'You’re creating threads too quickly. Please wait a little while and try again.';
            return $res->withHeader('Location', '/community/new')->withStatus(302);
        }
        $b = $this->asMember((array)$req->getParsedBody(), $m);
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
        \AfricaGates\Services\WebhookService::dispatch('community.thread_created', [
            'slug'   => (string) $slug,
            'title'  => trim((string)($b['title'] ?? '')),
            'status' => (string) $r['status'],
        ]);
        if ($r['status'] !== 'approved') {
            $_SESSION['flash_notice'] = 'Thread is queued for review.';
            return $res->withHeader('Location', '/community')->withStatus(302);
        }
        return $res->withHeader('Location', '/community/' . $slug)->withStatus(302);
    }

    // ── Comment API (used on profiles, legacy, threads) ─────────
    public function comment(Request $req, Response $res): Response
    {
        // Community v2: guests are view-only — posting needs a member account.
        $m = $this->member();
        if (!$m) {
            $res->getBody()->write(json_encode(['success' => false, 'code' => 'SIGN_IN', 'message' => 'Sign in to join the conversation.', 'login_url' => '/account/login']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
        if ($this->tooMany($req, 'community_comment', 8, 3600)) {
            $res->getBody()->write(json_encode(['success' => false, 'message' => 'You are posting too fast. Please wait a moment.']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(429);
        }
        $b = $this->asMember((array)$req->getParsedBody(), $m);
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
        if ($out['success']) {
            \AfricaGates\Services\WebhookService::dispatch('community.comment_posted', [
                'comment_id'  => (int) ($r['id'] ?? 0),
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'status'      => (string) ($r['status'] ?? ''),
            ]);
        }
        $res->getBody()->write(json_encode($out));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($out['success'] ? 200 : 422);
    }

    // ── Cheer toggle (community v2: members-only, account-keyed) ──
    public function cheer(Request $req, Response $res): Response
    {
        $m = $this->member();
        if (!$m) {
            $res->getBody()->write(json_encode(['success' => false, 'code' => 'SIGN_IN', 'message' => 'Sign in to react.', 'login_url' => '/account/login']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
        if ($this->tooMany($req, 'community_cheer', 40, 3600)) {
            $res->getBody()->write(json_encode(['success' => false, 'message' => 'Too many actions. Please slow down.']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(429);
        }
        $b = (array)$req->getParsedBody();
        $targetType = (string)($b['target_type'] ?? '');
        $targetId   = (int)($b['target_id'] ?? 0);
        // Which of the four. Defaults to `cheer`, so every caller that predates
        // reactions — the profile page, the nominee card, the comment row — keeps
        // working without knowing the parameter exists. An unknown kind is
        // rejected inside react(), not here.
        $kind = (string) ($b['kind'] ?? 'cheer');
        // Account-keyed fingerprint: one reaction per member per target, any device.
        $r = $this->community->react($targetType, $targetId, 'u:' . $m['id'], $kind);
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

    // ── Poll vote (community v2: members-only, account-keyed) ──
    public function pollVote(Request $req, Response $res): Response
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            $res->getBody()->write(json_encode(['success' => false, 'code' => 'SIGN_IN', 'message' => 'Sign in to vote in polls.', 'login_url' => '/account/login']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
        if ($this->tooMany($req, 'community_poll', 30, 3600)) {
            $res->getBody()->write(json_encode(['success' => false, 'message' => 'Too many actions. Please slow down.']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(429);
        }
        $b = (array)$req->getParsedBody();
        $pollId = (int)($b['poll_id'] ?? 0);
        $opt    = (int)($b['option_index'] ?? -1);
        $fp     = $this->pollFingerprint($req, $userId);
        $r = $this->community->votePoll($pollId, $opt, $fp, $userId);
        $out = array_merge(['success' => (bool)($r['ok'] ?? false)], $r);
        $res->getBody()->write(json_encode($out));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($out['success'] ? 200 : 422);
    }

    // ── Community AI (v2): thread summaries + composer assist ───
    // Members-only (the reason the Gee fab hides here for members). Both
    // degrade silently: no provider configured → {code:'AI_OFF'} and the UI
    // simply hides the affordance — never an error state.

    public function summarize(Request $req, Response $res): Response
    {
        if ($deny = $this->memberOr401($res)) return $deny;
        if ($this->tooMany($req, 'community_ai', 20, 3600)) {
            return $this->json($res, ['ok' => false, 'message' => 'Give the AI a short breather — try again in a bit.']);
        }
        $slug = trim((string)(((array)$req->getParsedBody())['slug'] ?? ''));
        $data = $slug !== '' ? $this->community->getThread($slug) : null;
        if (!$data) return $this->json($res, ['ok' => false, 'message' => 'Thread not found.']);

        $t = $data['thread'];
        // Cache keyed on reply_count — a new reply naturally invalidates.
        $key = 'ai:thread-sum:' . (int)$t['id'] . ':' . (int)($t['reply_count'] ?? 0);
        $summary = $this->cache->remember($key, 21600, function () use ($t, $data): ?string {
            $lines = ['THREAD: ' . $t['title'], 'OP (' . $t['author_name'] . '): ' . mb_substr((string)$t['body'], 0, 1200)];
            foreach (array_slice($data['replies'], 0, 40) as $r) {
                $lines[] = $r['author_name'] . ': ' . mb_substr((string)$r['body'], 0, 400);
            }
            // Member-written posts, fenced as untrusted: a thread is exactly the
            // place someone would try to write instructions to the summariser.
            $result = (new \AfricaGates\Services\AiGateway())->run('community.thread_summary', [
                'system' => 'You summarise Africa GATES community threads. Reply with a neutral, 2-4 sentence summary of the discussion so far, then — only if distinct viewpoints exist — one line starting "Perspectives:" listing them. No preamble, no markdown headers.',
                'user' => implode("\n", $lines),
                'temperature' => 0.3,
                'subject_type' => 'thread',
                'subject_id' => (int) $t['id'],
                'schema' => static function (string $raw): ?string {
                    $s = trim($raw);
                    return $s === '' ? null : mb_substr($s, 0, 1200);
                },
            ]);
            return $result->ok ? $result->value : null;
        });
        return $this->json($res, $summary === null
            ? ['ok' => false, 'code' => 'AI_OFF']
            : ['ok' => true, 'summary' => $summary]);
    }

    public function assist(Request $req, Response $res): Response
    {
        if ($deny = $this->memberOr401($res)) return $deny;
        if ($this->tooMany($req, 'community_ai', 20, 3600)) {
            return $this->json($res, ['ok' => false, 'message' => 'Give the AI a short breather — try again in a bit.']);
        }
        $draft = trim((string)(((array)$req->getParsedBody())['draft'] ?? ''));
        if ($draft === '' || mb_strlen($draft) > 3000) {
            return $this->json($res, ['ok' => false, 'message' => 'Write a draft first (up to 3000 characters).']);
        }
        // FAIL_DEGRADE, like the nomination-story polish: an optional nicety must
        // never surface an error, so every refusal collapses to the same AI_OFF.
        $r = (new \AfricaGates\Services\AiGateway())->run('community.polish', [
            'system' => "You polish community posts for Africa GATES (a continental cultural awards platform). Improve clarity, warmth and flow while KEEPING the author's voice, language and meaning. Never add facts. Reply with ONLY the improved text.",
            'user' => $draft,
            'temperature' => 0.4,
            'schema' => static function (string $raw): ?string {
                $t = trim($raw);
                return $t === '' ? null : mb_substr($t, 0, 3000);
            },
        ]);
        if (!$r->ok) return $this->json($res, ['ok' => false, 'code' => 'AI_OFF']);
        return $this->json($res, ['ok' => true, 'text' => $r->value]);
    }

    // ── Member reporting + own-post removal (community v2) ──────
    public function report(Request $req, Response $res): Response
    {
        if ($deny = $this->memberOr401($res)) return $deny;
        if ($this->tooMany($req, 'community_report', 15, 3600)) {
            $res->getBody()->write(json_encode(['success' => false, 'message' => 'Too many reports — please slow down.']));
            return $res->withHeader('Content-Type', 'application/json')->withStatus(429);
        }
        $b = (array)$req->getParsedBody();
        $r = $this->community->report((string)($b['target_type'] ?? ''), (int)($b['target_id'] ?? 0), (int)$_SESSION['user_id'], (string)($b['reason'] ?? ''));
        // Deliberately quiet: reporters never learn whether the threshold tripped.
        return $this->json($res, ['ok' => (bool)($r['ok'] ?? false), 'message' => $r['ok'] ? 'Thanks — our moderators will take a look.' : ($r['message'] ?? 'Could not report.')]);
    }

    public function deleteOwn(Request $req, Response $res): Response
    {
        if ($deny = $this->memberOr401($res)) return $deny;
        $b = (array)$req->getParsedBody();
        $r = $this->community->deleteOwn((string)($b['target_type'] ?? ''), (int)($b['target_id'] ?? 0), (int)$_SESSION['user_id']);
        return $this->json($res, $r);
    }

    // ── Member-only social actions: follow / bookmark / repost ──
    public function follow(Request $req, Response $res): Response
    {
        if ($deny = $this->memberOr401($res)) return $deny;
        $b = (array)$req->getParsedBody();
        $r = $this->community->toggleFollow((int)$_SESSION['user_id'], (string)($b['target_type'] ?? ''), (int)($b['target_id'] ?? 0));
        return $this->json($res, $r);
    }

    public function bookmark(Request $req, Response $res): Response
    {
        if ($deny = $this->memberOr401($res)) return $deny;
        $b = (array)$req->getParsedBody();
        $r = $this->community->toggleBookmark((int)$_SESSION['user_id'], (int)($b['thread_id'] ?? 0));
        return $this->json($res, $r);
    }

    public function repost(Request $req, Response $res): Response
    {
        if ($deny = $this->memberOr401($res)) return $deny;
        $b = (array)$req->getParsedBody();
        $r = $this->community->toggleRepost((int)$_SESSION['user_id'], (int)($b['thread_id'] ?? 0),
            (string)($b['comment'] ?? ''));
        return $this->json($res, $r);
    }

    /** Stable poll fingerprint: account-keyed for members, session/IP for guests. */
    private function pollFingerprint(Request $req, int $userId): string
    {
        if ($userId > 0) return 'u:' . $userId;
        $ip  = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
        $sid = $_COOKIE['PHPSESSID'] ?? session_id() ?: 'anon';
        return hash('sha256', $sid . '|' . $ip . '|poll');
    }

    /** Return a 401 JSON response when no member session, else null. */
    private function memberOr401(Response $res): ?Response
    {
        if (!empty($_SESSION['user_id'])) return null;
        $res->getBody()->write(json_encode(['success' => false, 'auth' => true, 'message' => 'Please sign in to do that.']));
        return $res->withHeader('Content-Type', 'application/json')->withStatus(401);
    }

    private function json(Response $res, array $r): Response
    {
        $ok = (bool)($r['ok'] ?? false);
        $res->getBody()->write(json_encode(array_merge(['success' => $ok], $r)));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($ok ? 200 : 422);
    }
}
