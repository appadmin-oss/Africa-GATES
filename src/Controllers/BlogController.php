<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\CacheService;

class BlogController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
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
        return $this->view->render($res, 'pages/blog/post.twig', [
            'page_title'       => $post->title . ' — Africa GATES',
            'meta_description' => (string)($post->excerpt ?? ''),
            'gates_page'       => 'blog',
            'has_hero'         => false,
            'post'             => (array)$post,
            'more'             => $more,
        ]);
    }
}
