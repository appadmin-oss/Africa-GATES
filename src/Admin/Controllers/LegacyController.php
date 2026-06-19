<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\{AuditService, UploadService};

class LegacyController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        private readonly UploadService $uploads,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_legacy_events')->orderByDesc('event_date')->get()->map(fn($r)=>(array)$r)->all();
        return $this->view->render($res, 'admin/legacy/index.twig', [
            'page_title' => 'Legacy Events — Admin',
            'admin_page' => 'legacy',
            'rows'       => $rows,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_legacy_events')->where('id', $id)->first() : [];
        if ($row && !empty($row['gallery_paths']) && is_string($row['gallery_paths'])) {
            $row['gallery_paths'] = json_decode($row['gallery_paths'], true) ?: [];
        }
        return $this->view->render($res, 'admin/legacy/form.twig', [
            'page_title' => $id ? 'Edit Event — Admin' : 'New Event — Admin',
            'admin_page' => 'legacy',
            'row'        => $row,
            'is_new'     => !$id,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $files = $req->getUploadedFiles();
        $adminId = (int)$_SESSION['admin_id'];

        $row = $id ? (array)DB::table('gates_legacy_events')->where('id', $id)->first() : [];
        $existingGallery = (!empty($row['gallery_paths']) && is_string($row['gallery_paths']))
            ? (json_decode($row['gallery_paths'], true) ?: [])
            : [];

        // Cover image
        $coverPath = $row['cover_path'] ?? null;
        if (isset($files['cover']) && $files['cover']->getError() === UPLOAD_ERR_OK) {
            $u = $this->uploads->uploadImage($files['cover'], 'legacy', 1800, 82, $adminId, 'legacy_event', $id);
            $coverPath = $u['url'];
        }

        // Append gallery images (max 12)
        $gallery = $existingGallery;
        if (isset($files['gallery']) && is_array($files['gallery'])) {
            foreach ($files['gallery'] as $g) {
                if ($g->getError() === UPLOAD_ERR_OK && count($gallery) < 12) {
                    $u = $this->uploads->uploadImage($g, 'legacy', 1600, 82, $adminId, 'legacy_event', $id);
                    $gallery[] = $u['url'];
                }
            }
        }
        // Also accept URLs typed into a textarea (one per line)
        if (!empty($b['gallery_urls'])) {
            foreach (preg_split('/\R/', (string)$b['gallery_urls']) as $u) {
                $u = trim($u);
                if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL) && count($gallery) < 12) {
                    $gallery[] = $u;
                }
            }
        }

        $data = [
            'slug'           => preg_replace('/[^a-z0-9-]+/i','-', strtolower((string)($b['slug'] ?? ''))),
            'title'          => trim((string)($b['title'] ?? '')),
            'tagline'        => trim((string)($b['tagline'] ?? '')),
            'event_date'     => trim((string)($b['event_date'] ?? date('Y-m-d'))),
            'location'       => trim((string)($b['location'] ?? '')),
            'cover_path'     => $coverPath,
            'gallery_paths'  => json_encode($gallery),
            'excerpt'        => trim((string)($b['excerpt'] ?? '')),
            'full_content'   => (string)($b['full_content'] ?? ''),
            'attendee_count' => (int)($b['attendee_count'] ?? 0),
            'award_count'    => (int)($b['award_count'] ?? 0),
            'icon'           => (string)($b['icon'] ?? '🏆'),
            'is_published'   => isset($b['is_published']) ? 1 : 0,
        ];
        if ($id) {
            DB::table('gates_legacy_events')->where('id', $id)->update($data);
            $this->audit->record($adminId, 'legacy.update', 'legacy_event', $id);
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int)DB::table('gates_legacy_events')->insertGetId($data);
            $this->audit->record($adminId, 'legacy.create', 'legacy_event', $id);
        }
        $_SESSION['flash_ok'] = 'Legacy event saved.';
        return $res->withHeader('Location', '/admin/legacy/' . $id)->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        DB::table('gates_legacy_events')->where('id', $id)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'legacy.delete', 'legacy_event', $id);
        $_SESSION['flash_ok'] = 'Legacy event deleted.';
        return $res->withHeader('Location', '/admin/legacy')->withStatus(302);
    }
}
