<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{AlertService, CacheService, CommunityService, Notifier, OtpService, ProfileService, PulseFeedService, PulseMediaService, RateLimitService, UserAccountService};
use AfricaGates\Support\OptionalColumn;

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
        private readonly ?PulseFeedService $feed = null,
        private readonly ?PulseMediaService $media = null,
    ) {}

    /** The signed-in member's id, or null. Feed state is per-viewer. */
    private function viewerId(): ?int
    {
        $m = UserAccountService::memberForForms();
        return $m ? (int) $m['id'] : null;
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write((string) json_encode($payload));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * A page of the feed as JSON — what infinite scroll asks for.
     *
     * Public, because reading Pulse is public. The per-viewer bits (`cheered`,
     * `saved`, `is_mine`) come from the SESSION, never from a parameter, so one
     * reader cannot ask the server what another reader has liked.
     */
    public function feed(Request $req, Response $res): Response
    {
        if ($this->feed === null) return $this->json($res, ['success' => false, 'items' => []], 503);

        $q      = $req->getQueryParams();
        $cursor = isset($q['cursor']) ? (int) $q['cursor'] : null;
        $limit  = isset($q['limit'])  ? (int) $q['limit']  : PulseFeedService::PAGE;
        // The channel chip. 0 and absent both mean "every channel", so deselecting
        // a chip can simply send nothing rather than a sentinel value.
        $chan   = isset($q['channel']) ? (int) $q['channel'] : 0;

        $page = $this->feed->page($cursor, $limit, $this->viewerId(), $chan ?: null,
            self::mediaFilter($q));
        return $this->json($res, ['success' => true] + $page);
    }

    /**
     * `?media=video` — the Reels tab. Allowlisted, never passed through.
     *
     * The value reaches a WHERE clause. The query builder parameterises it, so this
     * is not about injection; it is about not letting a stranger define what the
     * feed means. An arbitrary string would silently return an empty feed for any
     * typo and make "Reels is broken" indistinguishable from "nobody has posted a
     * video", which is the one distinction anybody debugging this needs.
     */
    private static function mediaFilter(array $q): ?string
    {
        $v = strtolower(trim((string) ($q['media'] ?? '')));
        return in_array($v, ['image', 'video'], true) ? $v : null;
    }

    /**
     * What happened to your things while you were away.
     *
     * Members only, and scoped in the SERVICE from the session id — there is no
     * parameter here that could point the query at somebody else's posts.
     */
    public function alerts(Request $req, Response $res): Response
    {
        $m = UserAccountService::memberForForms();
        if (!$m) return $this->json($res, ['success' => false, 'items' => [], 'unread' => 0], 401);

        $svc   = new AlertService();
        $items = $svc->forMember((int) $m['id'], (string) $m['email']);

        // Counted from the list that is being returned, not by a second query —
        // a badge that disagrees with the screen under it is worse than no badge.
        $unread = 0;
        foreach ($items as $a) if ($a['unread']) $unread++;

        return $this->json($res, ['success' => true, 'items' => $items, 'unread' => $unread]);
    }

    /** Just the number, for the dot on the bell. Polled, so it stays cheap. */
    public function alertCount(Request $req, Response $res): Response
    {
        $m = UserAccountService::memberForForms();
        if (!$m) return $this->json($res, ['success' => true, 'unread' => 0]);
        return $this->json($res, ['success' => true,
            'unread' => (new AlertService())->unreadFor((int) $m['id'], (string) $m['email'])]);
    }

    public function alertsRead(Request $req, Response $res): Response
    {
        $m = UserAccountService::memberForForms();
        if (!$m) return $this->json($res, ['success' => false], 401);
        return $this->json($res, ['success' => (new AlertService())->markRead((int) $m['id'])]);
    }

    /**
     * How many posts have landed since the reader loaded the page.
     *
     * Polled by the "N new posts" pill. Shared cPanel hosting gives us no
     * persistent process, so there is no websocket to push this down — the
     * honest implementation is a cheap indexed COUNT the client asks for.
     */
    public function feedNew(Request $req, Response $res): Response
    {
        if ($this->feed === null) return $this->json($res, ['success' => false, 'count' => 0], 503);

        $q     = $req->getQueryParams();
        $after = (int) ($q['after'] ?? 0);
        // The SAME filters the pill's own refetch will apply. Counting unfiltered
        // and fetching filtered is how a pill offers three new posts and then
        // delivers none.
        $chan  = isset($q['channel']) ? (int) $q['channel'] : 0;

        return $this->json($res, ['success' => true,
            'count' => $this->feed->newSince($after, $chan ?: null, self::mediaFilter($q))]);
    }

    /**
     * GET /pulse, /pulse/reels — PulsePage.dc.html.
     *
     * Tabs are addresses (`?tab=following|alerts|saved`), so Back, a bookmark and the no-script
     * page all work; "Show older posts" is `?cursor=` and, asked with `fragment=1`, answers
     * only the cards, drawn by the same partial as the first page — one renderer, so the
     * third page cannot come out subtly different from the first.
     */
    public function index(Request $req, Response $res): Response
    {
        $q      = $req->getQueryParams();
        $member = UserAccountService::memberForForms();
        $uid    = $member ? (int) $member['id'] : null;
        $tab    = in_array($q['tab'] ?? '', ['following', 'alerts', 'saved'], true) ? (string) $q['tab'] : 'foryou';
        if (!$member && $tab !== 'foryou') $tab = 'foryou';   // the member tabs have nothing to show a guest
        $media  = str_ends_with(rtrim($req->getUri()->getPath(), '/'), '/reels') ? 'video' : self::mediaFilter($q);
        $cursor = isset($q['cursor']) ? max(0, (int) $q['cursor']) : null;

        $scope = match ($tab) {
            'following' => $this->feed?->followingFor((int) $uid),
            'saved'     => ['ids' => $this->feed?->savedFor((int) $uid) ?? []],
            default     => null,
        };
        $page = $tab === 'alerts' ? ['items' => [], 'next_cursor' => null]
              : ($this->feed?->page($cursor ?: null, PulseFeedService::PAGE, $uid, null, $media, $scope)
                 ?? ['items' => [], 'next_cursor' => null]);

        // A later page, asked for by the script: only the cards.
        if (($q['fragment'] ?? '') === '1') {
            return $this->view->render($res, 'pages/pulse/_cards.twig', ['feed' => $page['items'], 'is_member' => (bool) $member]);
        }

        $alerts = $member ? (new AlertService())->forMember((int) $member['id'], (string) $member['email']) : [];
        $unread = count(array_filter($alerts, static fn ($a) => $a['unread']));

        return $this->view->render($res, 'pages/pulse.twig', [
            'page_title'       => 'Pulse — Africa GATES',
            'meta_description' => 'Pulse — what people across Africa GATES are sharing: news, thanks, results as they are announced, and the people behind them.',
            'gates_page'       => 'pulse',
            'page_id'          => 'pulse',
            'tab'              => 'pulse',
            'pulse_tab'        => $tab,
            'reels'            => $media === 'video',
            'member'           => $member,
            'feed'             => $page['items'],
            'feed_cursor'      => $page['next_cursor'],
            'alerts'           => $tab === 'alerts' ? $alerts : [],
            'unread'           => $unread,
            // The composer's limit comes from the controller, so the textarea's maxlength
            // and the server's truncation cannot disagree.
            'pulse_max'        => self::MAX_LEN,
            'media_limit'      => PulseMediaService::humanLimit(),
            'rail'             => $this->rail($uid),
        ] + ($tab !== 'foryou' || $cursor ? ['meta_robots' => 'noindex, follow'] : []));
    }

    /**
     * The desktop rail: what is being voted on now, who was recognised this week, and awards
     * worth following. Each list is the platform's own record — nothing on it is a sample.
     *
     * @return array{voting:list<array>, recognised:list<array>, follow:list<array>}
     */
    private function rail(?int $uid): array
    {
        $voting = $this->cache->remember('pulse:voting', 300, function (): array {
            $out = [];
            foreach ((new \AfricaGates\Services\AwardService())->getActiveProgrammesWithStatus() as $p) {
                if (empty($p['phase']['is_voting_open'])) continue;
                $days = null;
                if (!empty($p['voting_close'])) {
                    $days = max(0, (int) ceil((strtotime((string) $p['voting_close']) - time()) / 86400));
                }
                $out[] = ['title' => (string) $p['title'], 'slug' => (string) $p['slug'], 'days' => $days,
                          'year' => (int) ($p['year'] ?? 0)];
                if (count($out) >= 3) break;
            }
            return $out;
        }, ['registry']);

        $recognised = $this->cache->remember('pulse:recognised', 600,
            fn (): array => $this->feed?->recognisedSince(date('Y-m-d H:i:s', time() - 7 * 86400)) ?? [], ['registry']);

        // Awards to follow: live programmes this member does not follow yet. Per viewer, so
        // never cached across viewers.
        $follow = [];
        try {
            $mine = $uid ? ($this->feed?->followingFor($uid)['programmes'] ?? []) : [];
            $cols = ['id', 'title', 'slug'];
            if (\AfricaGates\Support\SchemaHas::column('gates_award_programmes', 'host_name')) $cols[] = 'host_name';
            foreach (DB::table('gates_award_programmes')->where('is_active', 1)
                     ->when($mine !== [], fn ($w) => $w->whereNotIn('id', $mine))
                     ->orderBy('sort_order')->limit(3)->get($cols) as $p) {
                $follow[] = ['id' => (int) $p->id, 'title' => (string) $p->title, 'slug' => (string) $p->slug,
                             'host' => trim((string) ($p->host_name ?? ''))];
            }
        } catch (\Throwable) {}

        return ['voting' => $voting, 'recognised' => $recognised, 'follow' => $follow];
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

        // An attachment is stored BEFORE the thread, because storing it is the step
        // that can fail for a reason the author needs to act on ("that is not a
        // video we can play"). Posting first would leave a text-only post standing
        // next to an error about the picture that was meant to be the whole point.
        $upload = $req->getUploadedFiles()['media'] ?? null;
        $media  = null;
        if ($upload instanceof \Psr\Http\Message\UploadedFileInterface
            && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
            if ($this->media === null) {
                $_SESSION['flash_error'] = 'Attachments are unavailable right now.';
                return $res->withHeader('Location', '/pulse')->withStatus(302);
            }
            $stored = $this->media->store($upload, (int) $m['id']);
            if (!$stored['ok']) {
                $_SESSION['flash_error'] = $stored['message'] ?? 'That file could not be attached.';
                return $res->withHeader('Location', '/pulse')->withStatus(302);
            }
            $media = $stored;
        }

        // A picture on its own IS a post — that is most of what a photo feed is —
        // so the text is only required when there is nothing else.
        if ($body === '' && $media === null) {
            $_SESSION['flash_error'] = 'Write something, or attach a photo or video.';
            return $res->withHeader('Location', '/pulse')->withStatus(302);
        }
        $body = mb_substr($body, 0, self::MAX_LEN);

        $r = $this->community->postThread([
            'title'        => self::titleFrom($body !== '' ? $body : ($media['type'] === 'video' ? 'A video' : 'A photo')),
            'body'         => $body,
            'author_name'  => $m['name'],
            'author_email' => $m['email'],
            'author_user_id' => $m['id'],
            // The media is the post when there is no caption — and by here the file
            // is already stored, so this cannot create an empty thread.
        ], (string) ($req->getServerParams()['REMOTE_ADDR'] ?? ''), $media !== null);

        // Attach after the insert, and only through OptionalColumn: on a database
        // where 2026_08_01_thread_media has not been applied these columns do not
        // exist, and an unguarded write would turn a working text post into a 500.
        // The post survives without its picture; that is the right way round.
        if ($r['ok'] && $media !== null && !empty($r['id'])) {
            $row = OptionalColumn::filter('gates_threads', [
                'media_path' => $media['path'],
                'media_type' => $media['type'],
                'media_w'    => $media['w'] ?: null,
                'media_h'    => $media['h'] ?: null,
            ], ['media_path', 'media_type', 'media_w', 'media_h']);

            if ($row) {
                try {
                    DB::table('gates_threads')->where('id', (int) $r['id'])->update($row);
                } catch (\Throwable $e) {
                    error_log('[pulse] media not attached to thread ' . $r['id'] . ': ' . $e->getMessage());
                }
            } else {
                error_log('[pulse] media columns absent — post stored without its attachment. Run db:migrate.');
            }

            // THE MEDIA VERDICT OVERRIDES THE TEXT VERDICT, one way only.
            //
            // The spam filter judged the caption; MediaModerationService judged
            // the picture. A clean caption on an image that could not be checked
            // must not publish the image — and video is never machine-checked at
            // all on this host, so every video post lands here. Downgrade only:
            // a media verdict can hold an approved post, never release a held one.
            if (($media['verdict'] ?? 'approved') !== 'approved' && ($r['status'] ?? '') === 'approved') {
                try {
                    DB::table('gates_threads')->where('id', (int) $r['id'])->update(['status' => 'quarantined']);
                    $r['status'] = 'quarantined';
                } catch (\Throwable $e) {
                    // Could not hold it — so remove the attachment rather than
                    // leave unreviewed media on a live post.
                    error_log('[pulse] could not quarantine thread ' . $r['id'] . ': ' . $e->getMessage());
                    try {
                        DB::table('gates_threads')->where('id', (int) $r['id'])
                          ->update(OptionalColumn::filter('gates_threads',
                                ['media_path' => null, 'media_type' => null],
                                ['media_path', 'media_type']));
                    } catch (\Throwable) {}
                }
            }
        }

        if (!$r['ok']) {
            $_SESSION['flash_error'] = $r['message'];
            return $res->withHeader('Location', '/pulse')->withStatus(302);
        }

        // The feed is cached; a post nobody can see for ten minutes reads as a post
        // that failed, and the author is the one person guaranteed to look immediately.
        try { $this->cache->forgetByTag('registry'); } catch (\Throwable) {}

        Notifier::adminAlert($this->mailer, 'New Pulse post (' . $r['status'] . ')',
            "By: {$m['name']}\nStatus: {$r['status']}\n\n" . mb_substr($body, 0, 400));

        // Say WHICH thing is being reviewed. "Held for review" on a post whose
        // caption was fine and whose video simply cannot be machine-checked reads
        // as an accusation; naming the reason makes it a process, not a verdict.
        $held = $r['status'] !== 'approved';
        $_SESSION[$held ? 'flash_error' : 'flash_notice'] = match (true) {
            !$held                       => 'Posted to the Pulse.',
            $media !== null && ($media['verdict'] ?? '') !== 'approved'
                                         => 'Posted — a moderator will check the '
                                            . ($media['type'] === 'video' ? 'video' : 'photo') . ' before it appears.',
            default                      => 'Posted — held for review by a moderator.',
        };

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
