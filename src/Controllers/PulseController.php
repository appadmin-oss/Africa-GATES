<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{CacheService, CommunityService, Notifier, OtpService, ProfileService, RateLimitService, UserAccountService};

/**
 * Pulse — the living feed. The design is an Instagram-style hub; this build keeps
 * that look but is driven entirely by REAL data (published posts, upcoming events,
 * top profiles, community threads) — no fabricated likes, views or member counts.
 * Every query is guarded so a missing table never 500s the page.
 *
 * ── AND PEOPLE CAN POST TO IT ────────────────────────────────────────────────
 *
 * It used to be read-only: a beautifully arranged wall of things the platform had
 * published AT its members, with not one form on the page. Calling that "the living
 * feed" while giving nobody a way to add to it is the gap this closes.
 *
 * A Pulse post IS A COMMUNITY THREAD. That is the whole design decision, and it is
 * deliberate: {@see CommunityService::postThread()} already carries the AI spam
 * filter, the approved/quarantined moderation verdict, slug collision handling,
 * reply and cheer counters, the moderator alert and the admin moderation queue. A
 * second posting path would mean a second thing to moderate, a second thing to
 * report, and a second place for abuse to arrive unwatched — on a platform whose
 * audience includes children.
 *
 * The only difference is SHAPE. A thread is title + body; a Pulse post is one short
 * message, because nobody writes a headline for a status update. So the title is
 * DERIVED from the first sentence and the composer asks for one field. Everything
 * downstream — moderation, the thread page, comments, cheers — treats it as what it
 * is, and a Pulse post opens as a normal thread when someone clicks through.
 */
final class PulseController
{
    /** A Pulse post is short by design; this is what the textarea enforces. */
    public const MAX_LEN = 600;

    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly ProfileService $profiles,
        private readonly ?CommunityService $community = null,
        private readonly ?RateLimitService $rateLimit = null,
        private readonly ?OtpService $mailer = null,
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
            // The composer's limit comes from the controller, so the textarea's
            // maxlength and the server's truncation cannot disagree.
            'pulse_max'        => self::MAX_LEN,
        ]);
    }

    /**
     * Post to the feed.
     *
     * Members only, like the rest of the community write surface — reading Pulse is
     * public, adding to it is not. The author's identity comes from the ACCOUNT and
     * never from the form, so a post cannot be attributed to someone else.
     *
     * Throttled at 5 an hour: looser than the community's 3 (a feed invites shorter,
     * more frequent posts) and still tight enough that a compromised account cannot
     * flood the front of the site.
     */
    public function post(Request $req, Response $res): Response
    {
        unset($_SESSION['flash_error'], $_SESSION['flash_notice']);

        $m = UserAccountService::memberForForms();
        if (!$m) {
            return $res->withHeader('Location', '/account/login?next=' . rawurlencode('/pulse'))->withStatus(302);
        }
        if ($this->community === null) {
            $_SESSION['flash_error'] = 'Posting is unavailable right now.';
            return $res->withHeader('Location', '/pulse')->withStatus(302);
        }
        if ($this->rateLimit !== null) {
            $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
            if (!$this->rateLimit->check(hash('sha256', $ip), 'pulse_post', 5, 3600)) {
                $_SESSION['flash_error'] = 'You’re posting a little too quickly. Try again shortly.';
                return $res->withHeader('Location', '/pulse')->withStatus(302);
            }
        }

        // Parenthesised around the whole array access, not just the cast: `(string) $a['x'] ?? ''`
        // still warns on a missing key because ?? only silences the access it wraps. And the
        // is_string check is for `body[]`, which arrives as an array and would fatal on cast.
        $raw  = ((array) $req->getParsedBody())['body'] ?? '';
        $body = is_string($raw) ? trim($raw) : '';
        if ($body === '') {
            $_SESSION['flash_error'] = 'Write something first.';
            return $res->withHeader('Location', '/pulse')->withStatus(302);
        }
        $body = mb_substr($body, 0, self::MAX_LEN);

        $r = $this->community->postThread([
            'title'        => self::titleFrom($body),
            'body'         => $body,
            'author_name'  => $m['name'],
            'author_email' => $m['email'],
            'author_user_id' => $m['id'],
        ], (string) ($req->getServerParams()['REMOTE_ADDR'] ?? ''));

        if (!$r['ok']) {
            $_SESSION['flash_error'] = $r['message'];
            return $res->withHeader('Location', '/pulse')->withStatus(302);
        }

        // The feed is cached; a post nobody can see for ten minutes reads as a post
        // that failed, and the author is the one person guaranteed to look immediately.
        try { $this->cache->forgetByTag('registry'); } catch (\Throwable) {}

        Notifier::adminAlert($this->mailer, 'New Pulse post (' . $r['status'] . ')',
            "By: {$m['name']}\nStatus: {$r['status']}\n\n" . mb_substr($body, 0, 400));

        $_SESSION[$r['status'] === 'approved' ? 'flash_notice' : 'flash_error'] =
            $r['status'] === 'approved' ? 'Posted to the Pulse.' : 'Posted — held for review by a moderator.';

        return $res->withHeader('Location', '/pulse')->withStatus(302);
    }

    /**
     * A headline for something written without one.
     *
     * `postThread` requires a title (it is the thread's identity and its slug), but a
     * status update has none. Taking the first sentence — or the first few words when
     * there is no sentence break — gives a thread page and a URL that read like the
     * post instead of "untitled-4". Trimmed to a length a slug can carry.
     */
    public static function titleFrom(string $body): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $body));
        if ($flat === '') return 'Pulse post';

        // First sentence, if one ends early enough to be a title rather than a paragraph.
        if (preg_match('/^(.{10,90}?[.!?])\s/u', $flat, $m)) {
            return rtrim($m[1], '.!? ');
        }
        if (mb_strlen($flat) <= 90) return $flat;

        $cut = mb_substr($flat, 0, 90);
        $sp  = mb_strrpos($cut, ' ');
        return rtrim($sp !== false && $sp > 40 ? mb_substr($cut, 0, $sp) : $cut) . '…';
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
