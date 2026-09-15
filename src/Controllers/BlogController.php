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

class BlogController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly ?CommunityService $community = null,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $posts = $this->cache->remember('blog:index', 900, fn() =>
            DB::table('gates_posts')->where('status', 'published')
                ->orderByDesc('published_at')->limit(24)->get()->map(fn($r) => (array)$r)->all()
        );
        return $this->view->render($res, 'pages/blog/index.twig', [
            'page_title'       => 'Blog — Africa GATES',
            'meta_description' => 'Announcements, methodology notes and partnership stories from Africa GATES.',
            'gates_page'       => 'blog',
            'has_hero'         => true,
            'posts'            => $posts,
        ]);
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
            ->get()->map(fn($r) => (array)$r)->all();
        // Optional attached poll — fingerprint mirrors the community poll voter id.
        $poll = null;
        if ($this->community) {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $fp  = $uid > 0 ? 'u:' . $uid
                 : hash('sha256', ($_COOKIE['PHPSESSID'] ?? session_id() ?: 'anon') . '|' . (string)($req->getServerParams()['REMOTE_ADDR'] ?? '') . '|poll');
            $poll = $this->community->getPoll('post', (int)$post->id, $fp);
        }
        return $this->view->render($res, 'pages/blog/post.twig', [
            'page_title'       => $post->title . ' — Africa GATES',
            'meta_description' => (string)($post->excerpt ?? ''),
            'gates_page'       => 'blog',
            'has_hero'         => false,
            'post'             => (array)$post,
            'more'             => $more,
            'poll'             => $poll,
        ] + array_filter([
            'og_image'     => \AfricaGates\Support\Assets::absoluteOg($post->cover_image ?? null),
            'og_image_alt' => (string) $post->title,
        ], fn($v) => $v !== null));
    }
}
