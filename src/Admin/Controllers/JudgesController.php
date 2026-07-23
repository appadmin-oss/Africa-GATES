<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\{AuditService, UploadService};
use AfricaGates\Services\{OtpService, GatedFormService};

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

        // Advisory judge-score anomaly scan (never changes a score/result) — a
        // judge whose scorecards are statistical outliers vs. the panel.
        $anomalies = ['flags' => [], 'judges' => [], 'nominees_scanned' => 0];
        try { $anomalies = (new \AfricaGates\Services\JudgeAnomalyService())->scanActive(); } catch (\Throwable) {}

        return $this->view->render($res, 'admin/judges/index.twig', [
            'page_title' => 'Judges — Admin',
            'admin_page' => 'judges',
            'rows'       => $rows,
            'programmes' => $programmes,
            'anomalies'  => $anomalies,
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

        $backTo = '/admin/judges/' . ($id ? $id : 'new');

        // Server-side validation — the browser's `required` is advisory only.
        $name  = trim((string)($b['name'] ?? ''));
        $email = strtolower(trim((string)($b['email'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'A judge needs a name and a valid email address.';
            return $res->withHeader('Location', $backTo)->withStatus(302);
        }
        // Duplicate email → a friendly message, not a raw UNIQUE-key 500 (this
        // was the "cannot add a second judge" crash: re-submitting an address
        // already on the panel hit the DB constraint unhandled).
        $dupe = DB::table('gates_judges')->whereRaw('LOWER(email) = ?', [$email])
            ->when($id, fn($q) => $q->where('id', '!=', $id))->exists();
        if ($dupe) {
            $_SESSION['flash_error'] = 'A judge with that email is already on the panel — edit them instead, or use a different address.';
            return $res->withHeader('Location', $backTo)->withStatus(302);
        }

        $existing = $id ? (array)DB::table('gates_judges')->where('id', $id)->first() : [];
        $avatar = $existing['avatar_path'] ?? null;
        if (isset($files['avatar']) && $files['avatar']->getError() === UPLOAD_ERR_OK) {
            // A rejected image (wrong type / too large / corrupt) must not 500
            // the whole save — explain it and let the operator retry.
            try {
                $u = $this->uploads->uploadImage($files['avatar'], 'judges', 600, 85, $adminId, 'judge', $id);
                $avatar = $u['url'];
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'The photo was rejected (' . $e->getMessage() . ') — use a PNG/JPG/WebP under the size limit.';
                return $res->withHeader('Location', $backTo)->withStatus(302);
            }
        }

        $progIds = array_values(array_filter(array_map('intval', (array)($b['programme_ids'] ?? []))));

        $data = [
            'name'         => $name,
            'email'        => $email,
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
            try {
                $id = (int)DB::table('gates_judges')->insertGetId($data);
            } catch (\Illuminate\Database\QueryException $e) {
                // Race-window duplicate (pre-check passed, constraint fired).
                $_SESSION['flash_error'] = 'A judge with that email is already on the panel — edit them instead, or use a different address.';
                return $res->withHeader('Location', $backTo)->withStatus(302);
            }
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
            // Single-use gated onboarding form link (separate from the login invite).
            $this->sendJudgeForm($id, $judgeEmail, trim((string)($b['name'] ?? '')));
        }
        $_SESSION['flash_ok'] = 'Judge saved.';
        return $res->withHeader('Location', '/admin/judges')->withStatus(302);
    }

    /** POST /admin/judges/{id}/regenerate-form — re-issue + resend the judge's single-use onboarding form link. */
    public function regenerateForm(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $j = DB::table('gates_judges')->where('id', $id)->first();
        if (!$j) throw new \Slim\Exception\HttpNotFoundException($req);
        $this->sendJudgeForm($id, (string)$j->email, (string)$j->name);
        $this->audit->record((int)$_SESSION['admin_id'], 'judge.regenerate_form', 'judge', $id);
        $_SESSION['flash_ok'] = 'A fresh single-use judge form link was emailed (any previous link is now void).';
        return $res->withHeader('Location', '/admin/judges')->withStatus(302);
    }

    /** Issue a single-use judge form token + email the judge a link to complete their profile. */
    private function sendJudgeForm(int $judgeId, string $email, string $name): void
    {
        $email = strtolower(trim($email));
        if (!$this->mailer || $judgeId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        $raw  = GatedFormService::issue('judge', $judgeId, $email);
        $link = GatedFormService::link($raw);
        $nn   = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');
        $html = "<p>Hi <strong>{$nn}</strong>,</p>"
            . "<p>Welcome to the Africa GATES judging panel. Please complete your judge profile using your private form below.</p>"
            . "<p style=\"text-align:center;margin:24px 0\"><a href=\"{$link}\" style=\"display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">Complete your judge profile →</a></p>"
            . "<p style=\"font-size:12.5px;color:#6b7674\">This is a private, single-use link just for you — it works once.</p>";
        $plain = "Hi {$name},\n\nWelcome to the Africa GATES judging panel. Complete your judge profile using your private, single-use form:\n{$link}\n\n— Africa GATES";
        try { $this->mailer->sendBranded($email, 'Complete your Africa GATES judge profile', $html, $plain, 'Judges'); } catch (\Throwable $e) {}
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
