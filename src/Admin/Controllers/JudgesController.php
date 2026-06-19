<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\{AuditService, UploadService};
use AfricaGates\Services\OtpService;

class JudgesController
{
    public function __construct(
        private readonly Twig          $view,
        private readonly AuditService  $audit,
        private readonly UploadService $uploads,
        private readonly ?OtpService   $mailer = null,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_judges')->orderByDesc('id')->get()->map(function ($r) {
            $arr = (array)$r;
            $arr['programme_ids'] = $r->programme_ids ? (json_decode((string)$r->programme_ids, true) ?: []) : [];
            return $arr;
        })->all();
        $programmes = DB::table('gates_award_programmes')->orderBy('sort_order')->get()->map(fn($r)=>(array)$r)->all();
        return $this->view->render($res, 'admin/judges/index.twig', [
            'page_title' => 'Judges — Admin',
            'admin_page' => 'judges',
            'rows'       => $rows,
            'programmes' => $programmes,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_judges')->where('id', $id)->first() : [];
        if ($row && !empty($row['programme_ids']) && is_string($row['programme_ids'])) {
            $row['programme_ids'] = json_decode($row['programme_ids'], true) ?: [];
        }
        $programmes = DB::table('gates_award_programmes')->orderBy('sort_order')->get()->map(fn($r)=>(array)$r)->all();
        return $this->view->render($res, 'admin/judges/form.twig', [
            'page_title' => $id ? 'Edit Judge — Admin' : 'New Judge — Admin',
            'admin_page' => 'judges',
            'row'        => $row,
            'is_new'     => !$id,
            'programmes' => $programmes,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $files = $req->getUploadedFiles();
        $adminId = (int)$_SESSION['admin_id'];

        $existing = $id ? (array)DB::table('gates_judges')->where('id', $id)->first() : [];
        $avatar = $existing['avatar_path'] ?? null;
        if (isset($files['avatar']) && $files['avatar']->getError() === UPLOAD_ERR_OK) {
            $u = $this->uploads->uploadImage($files['avatar'], 'judges', 600, 85, $adminId, 'judge', $id);
            $avatar = $u['url'];
        }

        $progIds = array_values(array_filter(array_map('intval', (array)($b['programme_ids'] ?? []))));

        $data = [
            'name'         => trim((string)($b['name'] ?? '')),
            'email'        => strtolower(trim((string)($b['email'] ?? ''))),
            'title'        => trim((string)($b['title'] ?? '')),
            'organisation' => trim((string)($b['organisation'] ?? '')),
            'bio'          => trim((string)($b['bio'] ?? '')),
            'country_code' => strtoupper(substr((string)($b['country_code'] ?? ''), 0, 2)),
            'programme_ids'=> json_encode($progIds),
            'avatar_path'  => $avatar,
            'is_active'    => isset($b['is_active']) ? 1 : 0,
        ];
        if ($id) {
            DB::table('gates_judges')->where('id', $id)->update($data);
            $this->audit->record($adminId, 'judge.update', 'judge', $id);
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int)DB::table('gates_judges')->insertGetId($data);
            $this->audit->record($adminId, 'judge.create', 'judge', $id);

            // Email the new judge their assignment details
            $judgeEmail = strtolower(trim((string)($b['email'] ?? '')));
            if ($this->mailer && filter_var($judgeEmail, FILTER_VALIDATE_EMAIL) && !empty($progIds)) {
                $progTitles = DB::table('gates_award_programmes')
                    ->whereIn('id', $progIds)->pluck('title')->implode(', ');
                $loginUrl = rtrim($_ENV['APP_URL'] ?? 'https://afg.afrovanguard.org.ng', '/') . '/judge/login';
                $this->mailer->sendJudgeAssignment(
                    $judgeEmail,
                    trim((string)($b['name'] ?? 'Judge')),
                    $progTitles ?: 'Africa GATES Awards',
                    $loginUrl
                );
            }
        }
        $_SESSION['flash_ok'] = 'Judge saved.';
        return $res->withHeader('Location', '/admin/judges')->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        DB::table('gates_judges')->where('id', $id)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'judge.delete', 'judge', $id);
        $_SESSION['flash_ok'] = 'Judge removed.';
        return $res->withHeader('Location', '/admin/judges')->withStatus(302);
    }
}
