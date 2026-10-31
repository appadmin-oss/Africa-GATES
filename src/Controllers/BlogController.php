<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\CacheService;
use AfricaGates\Services\CommunityService;
use AfricaGates\Support\DocText;

/**
 * The blog — /blog and /blog/{slug} (Phase 9, design/BlogPage.dc.html, §8.14).
 *
 * ── WHAT THE PAGE IS GIVEN, AND WHERE EACH FACT COMES FROM ───────────────────
 *
 * Posts are `gates_posts`, published only. Everything the redesign added is DERIVED from
 * what a post already holds, never typed beside it:
 *
 *   · `read_minutes` — counted from the body's own words (210 wpm, the rate the Integrity
 *     Centre documents use), so a story's reading time cannot outlive an edit;
 *   · the topics — the distinct `tag`s the published posts carry, so a chip can never
 *     open an empty shelf;
 *   · "In this story" — the people the body itself LINKS (`/registry/{slug}`), resolved to
 *     approved profiles, so a tag cannot name somebody the story does not mention, and the
 *     sandbox cannot appear (its nominees are never approved profiles on the live chain —
 *     see people());
 *   · the cover — `cover_image` when it is a path or an https URL we can put in an `src`,
 *     otherwise the generated cover from partials/photo.twig (GAPS §8 Q8).
 *
 * Search is a GET over the published set (`?q=`, `?topic=`): shareable, back-able, and it
 * works with no script.
 */
class BlogController
{
    /** The reading rate the Integrity Centre documents use, so two pages time prose alike. */
    private const WPM = 210;

    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly ?CommunityService $community = null,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $all = $this->cache->remember('blog:index', 900, fn() =>
            array_map([self::class, 'card'], DB::table('gates_posts')->where('status', 'published')
                ->orderByDesc('published_at')->limit(60)->get()->map(fn($r) => (array) $r)->all())
        );

        $qp    = $req->getQueryParams();
        $q     = is_string($qp['q'] ?? null) ? mb_substr(trim($qp['q']), 0, 120) : '';
        $topic = is_string($qp['topic'] ?? null) ? trim($qp['topic']) : '';
        $topics = array_values(array_unique(array_filter(array_map(
            static fn (array $p): string => (string) ($p['tag'] ?? ''), $all))));
        if (!in_array($topic, $topics, true)) $topic = '';

        $needle = mb_strtolower($q);
        $posts  = array_values(array_filter($all, static function (array $p) use ($needle, $topic): bool {
            if ($topic !== '' && (string) ($p['tag'] ?? '') !== $topic) return false;
            if ($needle === '') return true;
            return str_contains(mb_strtolower($p['title'] . ' ' . ($p['excerpt'] ?? '') . ' ' . ($p['author'] ?? '')), $needle);
        }));

        // The newest story leads only on the unfiltered shelf — a featured slot over a
        // search result would promote whatever happened to match first.
        $featured = ($q === '' && $topic === '' && $posts !== []) ? array_shift($posts) : null;

        return $this->view->render($res, 'pages/blog/index.twig', [
            'page_title'       => 'Blog — Africa GATES',
            'meta_description' => 'Announcements, methodology notes and partnership stories from Africa GATES.',
            'gates_page'       => 'blog',
            'featured'         => $featured,
            'posts'            => $posts,
            'topics'           => $topics,
            'topic'            => $topic,
            'q'                => $q,
        ] + ($q !== '' ? ['meta_robots' => 'noindex, follow'] : []));
    }

    public function show(Request $req, Response $res, array $args): Response
    {
        $slug = (string)($args['slug'] ?? '');
        $post = DB::table('gates_posts')->where('slug', $slug)->where('status', 'published')->first();
        if (!$post) {
            throw new HttpNotFoundException($req);
        }
        $more = DB::table('gates_posts')->where('status', 'published')
            ->where('id', '!=', $post->id)->orderByDesc('published_at')->limit(3)
            ->get()->map(fn($r) => self::card((array) $r))->all();
        // Optional attached poll — fingerprint mirrors the community poll voter id.
        $poll = null;
        if ($this->community) {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $fp  = $uid > 0 ? 'u:' . $uid
                 : hash('sha256', ($_COOKIE['PHPSESSID'] ?? session_id() ?: 'anon') . '|' . (string)($req->getServerParams()['REMOTE_ADDR'] ?? '') . '|poll');
            $poll = $this->community->getPoll('post', (int)$post->id, $fp);
        }
        $p = self::card((array) $post);
        return $this->view->render($res, 'pages/blog/post.twig', [
            'page_title'       => $post->title . ' — Africa GATES',
            'meta_description' => (string)($post->excerpt ?? ''),
            'gates_page'       => 'blog',
            'og_type'          => 'article',
            'post'             => $p,
            'people'           => self::people((string) ($post->body ?? '')),
            'more'             => $more,
            'poll'             => $poll,
        ] + array_filter([
            'og_image'     => \AfricaGates\Support\Assets::absoluteOg($post->cover_image ?? null),
            'og_image_alt' => (string) $post->title,
        ], fn($v) => $v !== null));
    }

    /**
     * A post with what the page derives from it.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function card(array $p): array
    {
        $words = str_word_count(DocText::toText((string) ($p['body'] ?? '')));
        $cover = (string) ($p['cover_image'] ?? '');
        $p['read_minutes'] = max(1, (int) ceil($words / self::WPM));
        // Only what can safely sit in an `src`: a root-relative path or an https URL.
        $p['cover'] = (preg_match('#^/(?!/)#', $cover) || str_starts_with($cover, 'https://')) ? $cover : '';
        $p['audio'] = (string) ($p['audio_path'] ?? '') !== '' && preg_match('#^/(?!/)|^https://#', (string) $p['audio_path'])
            ? (string) $p['audio_path'] : '';
        unset($p['body_text']);
        return $p;
    }

    /**
     * "In this story": the registry profiles the body links, in the order it first links them.
     *
     * Resolved exactly as /registry/{slug} resolves one — `status = approved` and not merged
     * away (ProfileMergeService::notMerged) — so a tag can never name a profile that page
     * would 404 on. The sandbox mints no registry profiles (DemoSeeder writes nominees in an
     * inactive programme, never `gates_profiles`), so nothing from it can be linked here.
     *
     * @return list<array{slug:string,name:string,photo:string,meta:string}>
     */
    public static function people(string $body): array
    {
        if (!preg_match_all('#href=["\'](?:https?://[^/"\']+)?/registry/([a-z0-9][a-z0-9-]{0,159})(?=["\'/?\#])#i', $body, $m)) {
            return [];
        }
        $slugs = array_slice(array_values(array_unique(array_map('strtolower', $m[1]))), 0, 6);
        try {
            $rows = \AfricaGates\Services\ProfileMergeService::notMerged(
                DB::table('gates_profiles')->whereIn('slug', $slugs)->where('status', 'approved'))
                ->get(['slug', 'display_name', 'avatar_path', 'category', 'location_city'])->keyBy('slug');
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($slugs as $s) {
            if (!isset($rows[$s])) continue;
            $r = $rows[$s];
            $out[] = ['slug' => (string) $r->slug, 'name' => (string) $r->display_name,
                      'photo' => (string) ($r->avatar_path ?? ''),
                      'meta' => implode(' · ', array_filter([(string) ($r->category ?? ''), (string) ($r->location_city ?? '')]))];
        }
        return $out;
    }
}
