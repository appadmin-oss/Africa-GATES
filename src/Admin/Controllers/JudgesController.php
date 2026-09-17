<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Support\Env;
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

    /**
     * The round, as a schedule — who is expected where, and whether the calendar agrees.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS SCREEN DID NOT EXIST AND HAD TO
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Every screen that reads a sitting reads ONE — `/admin/interviews/{id}`. So an operator
     * running a panel of ten across forty entries had the whole round in the database and no
     * way to answer "what is Tuesday", "does Dr Achebe know about Thursday", or "did the
     * calendar actually take it". The information existed; the view did not.
     *
     * ── THE GOOGLE CHECK IS A BUTTON, NOT PART OF THE RENDER ─────────────────
     *
     * Forty sittings is forty round trips to an Apps Script deployment. Verifying on render
     * would make this the slowest screen in the console and spend the script's quota every
     * time somebody glanced at it. {@see JudgeSchedule::verify()} is per sitting, on demand,
     * and the answer is flashed rather than stored — it is a fact about right now.
     */
    public function schedule(Request $req, Response $res): Response
    {
        $q    = (array) $req->getQueryParams();
        $prog = (int) ($q['programme'] ?? 0) ?: null;

        $sittings = \AfricaGates\Services\JudgeSchedule::upcoming($prog);

        // Grouped by day here rather than in Twig: "which day is this" is a property of the
        // data and a template that computes it re-derives it per row and gets the boundary
        // wrong at midnight in the display zone.
        $byDay = [];
        foreach ($sittings as $s) {
            $byDay[(string) $s['day']][] = $s;
        }

        return $this->view->render($res, 'admin/judges/schedule.twig', [
            'page_title'  => 'Judging schedule — Admin',
            'admin_page'  => 'judges',
            'by_day'      => $byDay,
            'sittings'    => $sittings,
            'judges'      => \AfricaGates\Services\JudgeSchedule::judgesWithSittings($prog),
            'programmes'  => \AfricaGates\Services\JudgeSchedule::programmes(),
            'programme'   => $prog,
            // Whether the Google side can be asked at all. Without this the screen offers a
            // "check the calendar" button that can only ever report the same failure.
            'meet'        => \AfricaGates\Services\GoogleMeetService::boot()->canSchedule(),
            'meet_why'    => \AfricaGates\Services\GoogleMeetService::boot()->why(),
        ]);
    }

    /** Ask Google what it holds for one sitting. Flashed, never stored — see schedule(). */
    public function verify(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $r  = \AfricaGates\Services\JudgeSchedule::verify($id);

        $_SESSION[$r['state'] === \AfricaGates\Services\JudgeSchedule::SYNC_OK
            ? 'flash' : 'flash_error'] = 'Sitting #' . $id . ': ' . $r['message']
            . ($r['calendar_at'] !== '' ? ' Calendar has it at ' . $r['calendar_at'] . '.' : '');

        return $res->withHeader('Location', '/admin/judges/schedule')->withStatus(302);
    }

    /**
     * Send the reminder, by hand, to whoever was chosen.
     *
     * Three scopes, all reducing to a set of judge ids: everybody with something scheduled,
     * everybody on one programme, or one named judge. The choosing happens here and the
     * sending has one behaviour — {@see JudgeSchedule::remind()}.
     */
    public function remind(Request $req, Response $res): Response
    {
        $b     = (array) $req->getParsedBody();
        $scope = (string) ($b['scope'] ?? 'all');
        $prog  = (int) ($b['programme'] ?? 0) ?: null;

        // ── A NARROW SCOPE MUST NOT WIDEN WHEN ITS ARGUMENT IS MISSING ───────
        //
        // `judgesWithSittings(null)` means "everybody", which is the right default for the
        // "all" button and exactly the wrong one here: a `scope=programme` post that
        // arrives without a programme — a hand-edited form, a stale page, a double submit
        // after somebody cleared the filter — would silently mail the entire panel. Refused
        // rather than guessed, because the guess is unsendable-back.
        if ($scope === 'programme' && $prog === null) {
            $_SESSION['flash_error'] = 'No programme was chosen, so nothing was sent. '
                                     . 'Pick one and try again — or use “remind all” if that is what you meant.';
            return $res->withHeader('Location', '/admin/judges/schedule')->withStatus(302);
        }

        $ids = match ($scope) {
            // One judge. Cast from the form rather than trusted as a list, so a hand-edited
            // field cannot turn "remind her" into "remind everybody".
            'judge'     => array_filter([(int) ($b['judge_id'] ?? 0)]),
            // A programme's panel. `$prog` also narrows what each of them is TOLD, so a
            // judge sitting on two programmes hears about the one this send is for.
            'programme' => array_column(
                \AfricaGates\Services\JudgeSchedule::judgesWithSittings($prog), 'id'),
            default     => array_column(
                \AfricaGates\Services\JudgeSchedule::judgesWithSittings(), 'id'),
        };

        $r = \AfricaGates\Services\JudgeSchedule::remind(
            array_values($ids),
            $scope === 'programme' ? $prog : null,
            $this->mailer,
        );

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];

        try {
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'judges.reminded', null, null, [
                'scope' => $scope, 'programme' => $prog,
                'sent' => $r['sent'], 'skipped' => $r['skipped'], 'failed' => $r['failed'],
            ]);
        } catch (\Throwable) {}

        return $res->withHeader('Location',
            '/admin/judges/schedule' . ($prog ? '?programme=' . $prog : ''))->withStatus(302);
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
                $loginUrl = rtrim(Env::get('APP_URL', 'https://afg.afrovanguard.org.ng'), '/') . '/judge/login';
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
