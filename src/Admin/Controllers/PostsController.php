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

class PostsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        private readonly CacheService $cache,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_posts')->orderByDesc('published_at')->get()->map(fn($r)=>(array)$r)->all();
        return $this->view->render($res, 'admin/posts/index.twig', [
            'page_title' => 'Blog — Admin',
            'admin_page' => 'posts',
            'rows'       => $rows,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_posts')->where('id', $id)->first() : [];
        return $this->view->render($res, 'admin/posts/form.twig', [
            'page_title' => $id ? 'Edit Post — Admin' : 'New Post — Admin',
            'admin_page' => 'posts',
            'row'        => $row,
            'is_new'     => !$id,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $slug = preg_replace('/[^a-z0-9-]+/i', '-', strtolower(trim((string)($b['slug'] ?: $b['title'] ?? ''))));
        $data = [
            'slug'         => trim($slug, '-'),
            'title'        => trim((string)($b['title'] ?? '')),
            'excerpt'      => trim((string)($b['excerpt'] ?? '')),
            'body'         => (string)($b['body'] ?? ''),
            'cover_image'  => trim((string)($b['cover_image'] ?? '')),
            'author'       => trim((string)($b['author'] ?? 'Africa GATES Editorial')),
            'tag'          => trim((string)($b['tag'] ?? '')),
            'status'       => in_array($b['status'] ?? '', ['published','draft'], true) ? $b['status'] : 'draft',
            'published_at' => $b['published_at'] ?: Carbon::now()->toDateTimeString(),
        ];
        if ($data['title'] === '' || $data['slug'] === '') {
            $_SESSION['flash_error'] = 'Title and slug are required.';
            return $res->withHeader('Location', $id ? "/admin/posts/{$id}" : '/admin/posts/new')->withStatus(302);
        }
        if ($id) {
            DB::table('gates_posts')->where('id', $id)->update($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'post.update', 'post', $id);
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int)DB::table('gates_posts')->insertGetId($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'post.create', 'post', $id);
        }
        $this->cache->forget('blog:index');
        $this->cache->forget('home:posts');
        $_SESSION['flash_ok'] = 'Post saved.';
        return $res->withHeader('Location', '/admin/posts')->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        DB::table('gates_posts')->where('id', $id)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'post.delete', 'post', $id);
        $this->cache->forget('blog:index');
        $this->cache->forget('home:posts');
        $_SESSION['flash_ok'] = 'Post deleted.';
        return $res->withHeader('Location', '/admin/posts')->withStatus(302);
    }
}
