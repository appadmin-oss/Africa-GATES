<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\QuestionnaireService;
use AfricaGates\Services\SmsService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Sending the questionnaire out, and editing the questions per programme.
 *
 * ── THE THREE THINGS THIS SCREEN HAS TO MAKE OBVIOUS ─────────────────────────
 *
 * Who has not been asked. Who was asked and has not started. And who submitted, so a judge
 * can now read it. Those are the only states that lead to an action, and a list sorted by id
 * hides all three — the same reasoning as the disputes and interviews screens.
 *
 * ── AND WHAT IT DOES NOT DO ──────────────────────────────────────────────────
 *
 * Edit a nominee's answers. Staff correcting what a nominee wrote about their own work, in a
 * record labelled `nominee_supplied` and read by a panel as the nominee's own words, would
 * make the provenance a lie. If something needs changing, the submission is re-opened and the
 * nominee changes it — which is a button here, and their original link keeps working.
 */
final class QuestionnairesController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
        private readonly ?OtpService $mailer = null,
        private readonly ?SmsService $sms = null,
    ) {}

    /** Same split as interviews: a viewer may read the queue, only a moderator+ may act. */
    private function blocked(Response $res, bool $write = true): ?Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $may  = $write ? ['superadmin', 'admin', 'moderator']
                       : ['superadmin', 'admin', 'moderator', 'viewer'];
        if (in_array($role, $may, true)) return null;
        $_SESSION['flash_error'] = $write
            ? 'Your role can look at questionnaires but not change them.'
            : 'You don’t have access to questionnaires.';
        return $res->withHeader('Location', '/admin')->withStatus(302);
    }

    private function back(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    // ══ the queue ════════════════════════════════════════════════════════════

    public function index(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res, false)) return $b;

        return $this->view->render($res, 'admin/questionnaires/index.twig', [
            'page_title' => 'Nominee questionnaires',
            'admin_page' => 'questionnaires',
            'rows'       => QuestionnaireService::queue(),
            'summary'    => QuestionnaireService::summary(),
            'candidates' => QuestionnaireService::candidates(),
            'programmes' => $this->programmes(),
        ]);
    }

    private function programmes(): array
    {
        try {
            return DB::table('gates_award_programmes')->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'title'])->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }

    /** One submission, as the panel will see it. */
    public function show(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res, false)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $s  = QuestionnaireService::byId($id);
        if (!$s) {
            $_SESSION['flash_error'] = 'That questionnaire could not be found.';
            return $this->back($res, '/admin/questionnaires');
        }

        $form = QuestionnaireService::formFor((string) $s->invite_token);
        return $this->view->render($res, 'admin/questionnaires/show.twig', [
            'page_title' => 'Questionnaire #' . $id,
            'admin_page' => 'questionnaires',
            'row'        => (array) $s,
            'form'       => $form,
            'link'       => QuestionnaireService::url($id),
            'evidence'   => $this->evidenceFor((int) $s->nominee_id),
        ]);
    }

    /** What the judges can actually see for this nominee, from the nominee's own submission. */
    private function evidenceFor(int $nomineeId): array
    {
        try {
            return DB::table('gates_nominee_evidence')
                ->where('nominee_id', $nomineeId)
                ->where('provenance', 'nominee_supplied')
                ->orderBy('sort_order')
                ->get(['kind', 'title', 'source_url', 'visible_to_judges'])
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }

    // ══ actions ══════════════════════════════════════════════════════════════

    /** Open one, or one for everybody who has none. */
    public function open(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $body = (array) $req->getParsedBody();
        $adminId = (int) ($_SESSION['admin_id'] ?? 0) ?: null;

        if (!empty($body['all'])) {
            $made = 0;
            foreach (QuestionnaireService::candidates() as $c) {
                $r = QuestionnaireService::open((int) $c['id'], $adminId);
                if ($r['ok'] ?? false) $made++;
            }
            $_SESSION['flash'] = $made . ' questionnaire(s) opened. Nobody has been emailed yet — '
                               . 'review them, then invite.';
            $this->audit?->record($adminId, 'questionnaire.open_all', null, null, ['count' => $made]);
            return $this->back($res, '/admin/questionnaires');
        }

        $r = QuestionnaireService::open((int) ($body['nominee_id'] ?? 0), $adminId);
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok'] ?? false) {
            $this->audit?->record($adminId, 'questionnaire.open', 'nominee',
                (int) ($body['nominee_id'] ?? 0), ['submission' => $r['id'] ?? 0]);
            return $this->back($res, '/admin/questionnaires/' . (int) ($r['id'] ?? 0));
        }
        return $this->back($res, '/admin/questionnaires');
    }

    public function invite(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = QuestionnaireService::invite($id, $this->mailer, $this->sms);
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.invite',
            'submission', $id, ['recipients' => count($r['sent'] ?? [])]);
        return $this->back($res, '/admin/questionnaires/' . $id);
    }

    /** Invite everybody who has a questionnaire and has not been told about it. */
    public function inviteAll(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $sent = 0; $silent = 0;
        foreach (QuestionnaireService::queue(500) as $row) {
            if ($row['invited'] || $row['status'] !== 'draft') continue;
            $r = QuestionnaireService::invite((int) $row['id'], $this->mailer, $this->sms);
            if (($r['sent'] ?? []) !== []) $sent++; else $silent++;
        }
        $_SESSION['flash'] = $sent . ' invitation(s) sent.'
            . ($silent > 0 ? ' ' . $silent . ' had no reachable contact on the nomination — open '
                           . 'those and send the link yourself.' : '');
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.invite_all',
            null, null, ['sent' => $sent, 'unreachable' => $silent]);
        return $this->back($res, '/admin/questionnaires');
    }

    /** Let a nominee change what they sent. */
    public function reopen(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = QuestionnaireService::reopen($id, (string) (((array) $req->getParsedBody())['note'] ?? ''));
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.reopen', 'submission', $id);
        return $this->back($res, '/admin/questionnaires/' . $id);
    }

    /**
     * Re-publish the evidence rows from a submission.
     *
     * Needed when the rubric changed after a submission landed, or when somebody deleted a
     * row by hand. Idempotent: publishEvidence() clears its own previous rows first.
     */
    public function republish(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $n  = QuestionnaireService::publishEvidence($id);
        $_SESSION[$n > 0 ? 'flash' : 'flash_error'] = $n > 0
            ? $n . ' item(s) written into the judges\' dossier.'
            : 'Nothing was written — the questionnaire has not been submitted yet.';
        return $this->back($res, '/admin/questionnaires/' . $id);
    }

    // ══ the questions themselves ═════════════════════════════════════════════

    public function questions(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res, false)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);

        $p = DB::table('gates_award_programmes')->where('id', $programmeId)->first();
        if (!$p) {
            $_SESSION['flash_error'] = 'That programme could not be found.';
            return $this->back($res, '/admin/questionnaires');
        }

        $own = DB::table('gates_programme_questions')->where('programme_id', $programmeId)->count();

        return $this->view->render($res, 'admin/questionnaires/questions.twig', [
            'page_title' => 'Questions — ' . $p->title,
            'admin_page' => 'questionnaires',
            'programme'  => (array) $p,
            'questions'  => QuestionnaireService::questions($programmeId),
            'has_own'    => $own > 0,
            'criteria'   => $this->criteria($programmeId),
        ]);
    }

    private function criteria(int $programmeId): array
    {
        try {
            return (new \AfricaGates\Judge\Services\JudgeService())->criteria($programmeId);
        } catch (\Throwable) { return []; }
    }

    /** Copy the defaults into this programme so they can be edited. */
    public function seed(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);
        $n = QuestionnaireService::seedDefaults($programmeId);
        $_SESSION[$n > 0 ? 'flash' : 'flash_error'] = $n > 0
            ? $n . ' questions copied in. Edit them freely — nominees see this set from now on.'
            : 'This programme already has its own questions.';
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.seed',
            'programme', $programmeId, ['count' => $n]);
        return $this->back($res, '/admin/questionnaires/programme/' . $programmeId);
    }

    /** Save the edited set for one programme. */
    public function saveQuestions(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);
        $body = (array) $req->getParsedBody();
        $rows = is_array($body['q'] ?? null) ? $body['q'] : [];
        $now  = Carbon::now()->toDateTimeString();

        $seen = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $label = trim((string) ($r['label'] ?? ''));
            $id    = (int) ($r['id'] ?? 0);

            // An emptied label deletes the question. Stated on the screen, because a blank
            // field that silently does nothing is how an operator concludes the page is broken.
            if ($label === '') {
                if ($id > 0) {
                    DB::table('gates_programme_questions')->where('id', $id)
                        ->where('programme_id', $programmeId)->delete();
                }
                continue;
            }

            $data = [
                'programme_id'  => $programmeId,
                'slug'          => mb_substr(preg_replace('/[^a-z0-9_]/', '',
                                    strtolower((string) ($r['slug'] ?? ''))) ?: 'q' . ($seen + 1), 0, 60),
                'kind'          => in_array((string) ($r['kind'] ?? ''),
                                    ['text','textarea','number','url','email','date','select','checkbox'], true)
                                    ? (string) $r['kind'] : 'textarea',
                'label'         => mb_substr($label, 0, 300),
                'help'          => mb_substr(trim((string) ($r['help'] ?? '')), 0, 600) ?: null,
                'criterion_id'  => (int) ($r['criterion_id'] ?? 0) ?: null,
                'evidence_kind' => in_array((string) ($r['evidence_kind'] ?? ''),
                                    ['note','link','document','media','award','press'], true)
                                    ? (string) $r['evidence_kind'] : 'note',
                'is_required'   => !empty($r['is_required']) ? 1 : 0,
                'max_len'       => max(50, min(8000, (int) ($r['max_len'] ?? 1200))),
                'sort_order'    => ++$seen,
                'is_active'     => 1,
                'updated_at'    => $now,
            ];

            if ($id > 0) {
                DB::table('gates_programme_questions')->where('id', $id)
                    ->where('programme_id', $programmeId)->update($data);
            } else {
                DB::table('gates_programme_questions')->insert($data + ['created_at' => $now]);
            }
        }

        $_SESSION['flash'] = $seen . ' question(s) saved for this programme.';
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.questions_saved',
            'programme', $programmeId, ['count' => $seen]);
        return $this->back($res, '/admin/questionnaires/programme/' . $programmeId);
    }
}
