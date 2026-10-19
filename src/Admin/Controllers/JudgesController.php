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

            // One welcome: the single-use profile form, the way into the panel, and the dates.
            // It used to be two emails, the first carrying a typed year and typed weights.
            $this->sendJudgeForm($id, strtolower(trim((string)($b['email'] ?? ''))), trim((string)($b['name'] ?? '')), $progIds);
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
        $this->sendJudgeForm($id, (string)$j->email, (string)$j->name,
            array_values(array_filter(array_map('intval', (array) (json_decode((string) ($j->programme_ids ?? '[]'), true) ?: [])))));
        $this->audit->record((int)$_SESSION['admin_id'], 'judge.regenerate_form', 'judge', $id);
        $_SESSION['flash_ok'] = 'A fresh single-use judge form link was emailed (any previous link is now void).';
        return $res->withHeader('Location', '/admin/judges')->withStatus(302);
    }

    /** The judge's form link lives this many days (GatedFormService::issue). */
    private const FORM_DAYS = 30;

    /**
     * Issue a single-use judge form token and send the judge welcome (emails/judge.html.twig).
     *
     * The dates are the programme's own — scoring opens when its voting closes and closes on
     * its results date (CyclePolicy::phaseFor) — and are printed only for a judge on ONE
     * programme: on several, any one cycle's dates would be wrong for the rest.
     *
     * @param list<int> $programmeIds
     */
    private function sendJudgeForm(int $judgeId, string $email, string $name, array $programmeIds = []): void
    {
        $email = strtolower(trim($email));
        if (!$this->mailer || $judgeId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        $raw  = GatedFormService::issue('judge', $judgeId, $email, self::FORM_DAYS);
        $base = rtrim(\AfricaGates\Support\SiteUrl::base(), '/');

        $programme = '';
        $opens = $closes = '';
        if ($programmeIds) {
            $programme = DB::table('gates_award_programmes')->whereIn('id', $programmeIds)->pluck('title')->implode(' · ');
            if (count($programmeIds) === 1) {
                $cy = DB::table('gates_award_cycles')->where('programme_id', $programmeIds[0])->orderByDesc('year')->first();
                if ($cy) {
                    $opens  = !empty($cy->voting_close) ? \AfricaGates\Support\DisplayTime::showZoned((string) $cy->voting_close, 'j F Y, H:i') : '';
                    $closes = !empty($cy->results_date) ? \AfricaGates\Support\DisplayTime::showZoned((string) $cy->results_date, 'j F Y, H:i') : '';
                }
            }
        }
        try {
            \AfricaGates\Services\Mail\Emails::send($this->mailer, $email, 'judge', [
                'first_name'     => \AfricaGates\Services\DoorWelcome::firstName($name) ?: 'there',
                'programme'      => $programme,
                'form_url'       => GatedFormService::link($raw),
                'login_url'      => $base . '/judge/login',
                'scoring_opens'  => $opens,
                'scoring_closes' => $closes,
                'form_days'      => self::FORM_DAYS,
                'help_url'       => $base . '/support',
            ]);
        } catch (\Throwable) { /* the judge is saved; the form can be re-sent from the list */ }
    }

    /**
     * POST /admin/judges/{id}/delete — take a judge off the panel.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * A JUDGE WHO HAS MARKED ANYTHING IS RETIRED, NEVER DELETED
     * ══════════════════════════════════════════════════════════════════════════
     *
     * This was a hard DELETE under a confirm reading "scores they have already submitted
     * stay on the nominees". On production that sentence was false in the worst direction:
     * `gates_judge_criteria_scores` and `gates_judge_notes` carry `ON DELETE CASCADE` to
     * this table, so one click silently destroyed every mark the judge had ever given —
     * across every cycle, released ones included — and with it the quorum of every nominee
     * they had sat on. On SQLite (dev, the suite) the foreign keys are not enforced, so the
     * marks SURVIVED and kept COUNTING, because the scorer bars only `is_active = 0`. The
     * same button did opposite things on the two databases, and neither was "removed".
     *
     * So removal is what `is_active = 0` already means everywhere else: the sign-in, the
     * live session ({@see \AfricaGates\Judge\Middleware\JudgeAuthMiddleware}) and the
     * scorer ({@see \AfricaGates\Services\NomineeScoringService::disqualifiedJudges()},
     * which keeps the marks visible on the scorecard as "off the panel") all honour it. A
     * true delete is offered only to a judge with no record at all — the mistyped invite —
     * which is the rule {@see \AfricaGates\Services\JudgeRubric::retire()} applies to a
     * criterion, for the same reason: a row other records point at is history.
     */
    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $j = DB::table('gates_judges')->where('id', $id)->first();
        if (!$j) throw new \Slim\Exception\HttpNotFoundException($req);

        $record = self::recordCount($id);
        if ($record > 0) {
            DB::table('gates_judges')->where('id', $id)->update(['is_active' => 0]);
            $this->audit->record((int)($_SESSION['admin_id'] ?? 0), 'judge.retire', 'judge', $id,
                ['records' => $record]);
            $_SESSION['flash_ok'] = 'Judge taken off the panel. They have ' . number_format($record)
                . ' judging record' . ($record === 1 ? '' : 's') . ', so they are kept as inactive rather '
                . 'than deleted: they can no longer sign in, and their marks no longer count towards '
                . 'any result. Reactivate them from Edit if this was a mistake.';
        } else {
            DB::table('gates_judges')->where('id', $id)->delete();
            $this->audit->record((int)($_SESSION['admin_id'] ?? 0), 'judge.delete', 'judge', $id);
            $_SESSION['flash_ok'] = 'Judge removed. They had not judged anything, so nothing else changed.';
        }
        return $res->withHeader('Location', '/admin/judges')->withStatus(302);
    }

    /**
     * How many rows elsewhere point at this judge. Any at all makes the judge history.
     *
     * Every table is asked through SchemaHas, because the score log, the map flags and the
     * conflict table each arrived in a migration and an unmigrated deployment must still be
     * able to remove a judge — counting a table that does not exist is zero, not a 500.
     */
    public static function recordCount(int $judgeId): int
    {
        $n = 0;
        foreach (['gates_judge_criteria_scores', 'gates_judge_notes', 'gates_judge_score_log',
                  'gates_judge_coi', 'gates_judge_map_flags'] as $table) {
            if (!\AfricaGates\Support\SchemaHas::table($table)) continue;
            try {
                $n += (int) DB::table($table)->where('judge_id', $judgeId)->count();
            } catch (\Throwable $e) {
                // Unknown is not none: refuse the delete rather than guess.
                error_log('[judges] record count on ' . $table . ': ' . $e->getMessage());
                $n += 1;
            }
        }
        return $n;
    }
}
