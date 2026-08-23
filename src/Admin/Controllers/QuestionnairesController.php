<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\QuestionnaireInvites;
use AfricaGates\Services\QuestionnairePolicy;
use AfricaGates\Services\QuestionnaireService;
use AfricaGates\Services\QuestionnaireStyle;
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
            // ── THE INTERVIEW, WHEN THAT IS WHAT THIS WAS ────────────────────
            //
            // An operator looking at a submission needs to be able to answer two questions a
            // list of answers cannot: where did this sentence come from, and what did the
            // machine add? So the transcript and the ledger are both here, side by side, with
            // every machine-derived value labelled and carrying the turn it was taken from.
            'style'      => \AfricaGates\Services\QuestionnaireInterview::styleOf($s),
            'iv'         => \AfricaGates\Services\QuestionnaireInterview::state((string) $s->invite_token),
            'tokens'     => \AfricaGates\Services\QuestionnaireInterview::tokensUsed($s),
            // Reinstating is narrower than reading: taking a nomination back is not a
            // moderation act, and the route guard says the same thing.
            'may_reinstate' => in_array((string) ($_SESSION['admin_role'] ?? ''), ['superadmin', 'admin'], true)
                               && (string) ($s->status ?? '') === 'disqualified',
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

    /**
     * A questionnaire to rehearse on.
     *
     * The whole reason it exists: before it, seeing what a nominee sees meant opening one
     * against a real person — a live token, a row in the counts, and on submit a set of
     * evidence rows in that person's judging dossier. Nobody rehearses under those terms, so
     * the first person to meet a confusing question was always a nominee.
     */
    public function openTest(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $body    = (array) $req->getParsedBody();
        $adminId = (int) ($_SESSION['admin_id'] ?? 0) ?: null;

        $r = QuestionnaireService::openTest(
            (int) ($body['programme_id'] ?? 0) ?: null,
            $adminId,
            (string) ($body['label'] ?? '')
        );

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok'] ?? false) {
            $this->audit?->record($adminId, 'questionnaire.open_test', 'submission',
                (int) ($r['id'] ?? 0), ['programme' => (int) ($body['programme_id'] ?? 0)]);
            return $this->back($res, '/admin/questionnaires/' . (int) ($r['id'] ?? 0));
        }
        return $this->back($res, '/admin/questionnaires');
    }

    /**
     * Delete a test, rows and all.
     *
     * Deletable in a way a real submission is not, and the asymmetry is deliberate: a real
     * submission is somebody's account of their own work and gets re-opened rather than
     * destroyed. The service refuses anything that is not flagged as a test, so a mistyped id
     * cannot take a nominee's answers with it.
     */
    public function deleteTest(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = QuestionnaireService::deleteTest($id);
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.delete_test',
            'submission', $id, ['ok' => (bool) ($r['ok'] ?? false)]);
        return $this->back($res, ($r['ok'] ?? false) ? '/admin/questionnaires'
                                                    : '/admin/questionnaires/' . $id);
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

    // ══ invitations: the deadline, the audience, and the send ════════════════

    /**
     * One screen for everything about ASKING: when it is due, what a missed deadline costs,
     * who is going to be written to, and how many.
     *
     * Split from the queue because they answer different questions. The queue is "what is
     * the state of this nominee"; this is "who have we not reached". Putting a bulk send
     * behind one button on a list of three hundred rows is how somebody presses it by
     * accident.
     */
    public function invitations(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res, false)) return $b;

        $qs      = $req->getQueryParams();
        $cycles  = $this->cycles();
        $cycleId = (int) ($qs['cycle'] ?? 0);
        if ($cycleId === 0 || !isset($cycles[$cycleId])) $cycleId = (int) (array_key_first($cycles) ?? 0);

        // The selection round-trips through the URL rather than living in the session, so
        // the count an operator is looking at is always the count for the selection in
        // front of them — and the page is linkable and refreshable without re-choosing.
        $picked   = array_values(array_filter(array_map('intval', (array) ($qs['p'] ?? []))));
        $audience = (string) ($qs['audience'] ?? 'not_submitted');
        $again    = (string) ($qs['again'] ?? '') === '1';

        $plan = $cycleId > 0
            ? QuestionnaireInvites::plan($cycleId, $picked, $audience, $again)
            : ['rows' => [], 'counts' => [], 'skipped' => [], 'audience' => $audience,
               'cycle_id' => 0, 'batch' => QuestionnaireInvites::BATCH];

        return $this->view->render($res, 'admin/questionnaires/invitations.twig', [
            'page_title'  => 'Questionnaire invitations',
            'admin_page'  => 'questionnaires',
            'cycles'      => $cycles,
            'cycle_id'    => $cycleId,
            'cycle'       => $cycles[$cycleId] ?? null,
            'programmes'  => $cycleId > 0 ? $this->cycleProgrammes($cycleId) : [],
            'picked'      => $picked,
            'audiences'   => QuestionnaireInvites::AUDIENCES,
            'audience'    => $plan['audience'],
            'again'       => $again,
            'plan'        => $plan,
            'history'     => $cycleId > 0 ? QuestionnaireInvites::history($cycleId) : null,
            'policy'      => QuestionnairePolicy::forCycle($cycleId),
            'policy_text' => QuestionnairePolicy::describe(QuestionnairePolicy::forCycle($cycleId)),
            'enforce'     => $cycleId > 0 ? QuestionnairePolicy::enforce($cycleId, true) : null,
            'may_write'   => in_array((string) ($_SESSION['admin_role'] ?? ''),
                                      ['superadmin', 'admin', 'moderator'], true),
        ]);
    }

    /** Save the deadline and whether missing it disqualifies. */
    public function savePolicy(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $body    = (array) $req->getParsedBody();
        $cycleId = (int) ($body['cycle_id'] ?? 0);
        $r       = QuestionnairePolicy::save($cycleId, $body, (int) ($_SESSION['admin_id'] ?? 0));

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.policy', 'cycle', $cycleId);

        return $this->back($res, '/admin/questionnaires/invitations?cycle=' . $cycleId);
    }

    /**
     * Send one batch to the selected programmes.
     *
     * A batch and not "all", because this runs in the request that asked for it — there is
     * no worker process on this host — and PHP's time limit is the real ceiling. The
     * response says what is left, which is the part the old inviteAll() never did: it
     * capped at 500 silently, so a cycle with 600 nominees invited 500 and said so nowhere.
     */
    public function send(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $body    = (array) $req->getParsedBody();
        $cycleId = (int) ($body['cycle_id'] ?? 0);
        $picked  = array_values(array_filter(array_map('intval', (array) ($body['p'] ?? []))));
        $audience = (string) ($body['audience'] ?? 'not_submitted');
        $again    = (string) ($body['again'] ?? '') === '1';

        $qs = '/admin/questionnaires/invitations?cycle=' . $cycleId
            . '&audience=' . urlencode($audience) . ($again ? '&again=1' : '')
            . implode('', array_map(fn ($i) => '&p[]=' . $i, $picked));

        if ($this->mailer === null) {
            $_SESSION['flash_error'] = 'Email is not configured on this deployment, so nothing was sent.';
            return $this->back($res, $qs);
        }
        if ($cycleId <= 0) {
            $_SESSION['flash_error'] = 'Pick a cycle first.';
            return $this->back($res, $qs);
        }

        $r = QuestionnaireInvites::sendBatch($cycleId, $picked, $audience, $again, $this->mailer);

        $_SESSION[$r['sent'] > 0 ? 'flash' : 'flash_error'] = $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.invite_batch',
            'cycle', $cycleId, ['sent' => $r['sent'], 'failed' => $r['failed'],
                                'remaining' => $r['remaining'], 'programmes' => $picked]);

        return $this->back($res, $qs);
    }

    /** Run the disqualification rule now, rather than waiting for the cron. */
    public function disqualify(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $cycleId = (int) (((array) $req->getParsedBody())['cycle_id'] ?? 0);
        $r = QuestionnairePolicy::enforce($cycleId, false, (int) ($_SESSION['admin_id'] ?? 0));

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.disqualify',
            'cycle', $cycleId, ['count' => $r['done'] ?? 0]);

        return $this->back($res, '/admin/questionnaires/invitations?cycle=' . $cycleId);
    }

    /** Undo one. */
    public function reinstate(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $id = (int) ($args['id'] ?? 0);
        $r  = QuestionnairePolicy::reinstate($id, (int) ($_SESSION['admin_id'] ?? 0));

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.reinstate', 'submission', $id);

        return $this->back($res, '/admin/questionnaires/' . $id);
    }

    /** @return array<int,object> cycles, newest first, keyed by id */
    private function cycles(): array
    {
        try {
            return DB::table('gates_award_cycles AS cy')
                ->leftJoin('gates_award_programmes AS p', 'p.id', '=', 'cy.programme_id')
                ->orderByDesc('cy.year')->orderByDesc('cy.id')
                ->get(['cy.id', 'cy.year', 'cy.edition_label', 'cy.status', 'p.title AS programme'])
                ->keyBy('id')->all();
        } catch (\Throwable) { return []; }
    }

    /**
     * The programmes that actually have a questionnaire in this cycle, with their counts.
     *
     * Listing every programme on the platform would offer an organiser twenty checkboxes,
     * eighteen of which select nobody. The count beside each is what makes the choice
     * answerable rather than a guess.
     *
     * @return list<array<string,mixed>>
     */
    private function cycleProgrammes(int $cycleId): array
    {
        try {
            $rows = DB::table('gates_nominee_submissions AS s')
                ->leftJoin('gates_award_programmes AS p', 'p.id', '=', 's.programme_id')
                ->where('s.cycle_id', $cycleId)
                ->where(fn ($w) => $w->whereNull('s.is_test')->orWhere('s.is_test', 0))
                ->groupBy('s.programme_id', 'p.title')
                ->get([DB::raw('s.programme_id AS id'), DB::raw('p.title AS title'),
                       DB::raw('COUNT(*) AS n'),
                       DB::raw("SUM(CASE WHEN s.status = 'submitted' THEN 1 ELSE 0 END) AS done")]);

            return $rows->map(fn ($r) => [
                'id'    => (int) $r->id,
                'title' => (string) ($r->title ?? 'Unassigned'),
                'n'     => (int) $r->n,
                'done'  => (int) $r->done,
                'open'  => (int) $r->n - (int) $r->done,
            ])->all();
        } catch (\Throwable) { return []; }
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

        QuestionnaireStyle::forget();
        $cfg = QuestionnaireStyle::config($programmeId);

        return $this->view->render($res, 'admin/questionnaires/questions.twig', [
            'page_title' => 'Questionnaire — ' . $p->title,
            'admin_page' => 'questionnaires',
            'programme'  => (array) $p,
            'questions'  => QuestionnaireService::questions($programmeId),
            'has_own'    => $own > 0,
            'criteria'   => $this->criteria($programmeId),
            // ── the interview half ───────────────────────────────────────────
            'cfg'        => $cfg,
            'knowledge'  => QuestionnaireStyle::knowledge($programmeId),
            'outcomes'   => QuestionnaireStyle::outcomes($programmeId),
            'rules'      => QuestionnaireStyle::rules($programmeId),
            'own_outcomes' => DB::table('gates_questionnaire_outcomes')
                                ->where('programme_id', $programmeId)->count(),
            // Whether a nominee opening this page right now would actually get a
            // conversation. A builder that let somebody configure an interview without
            // saying "this will not run — there is no OpenAI key" is a builder that
            // wastes an afternoon.
            'live'       => QuestionnaireStyle::interviewPossible($programmeId),
            'route_default' => QuestionnaireStyle::DEFAULT_ROUTE,
            'branch_conditions' => [
                'answered'   => 'they answered it at all',
                'blank'      => 'they left it blank',
                'yes'        => 'their answer was not a clear no',
                'no'         => 'their answer was a clear no',
                'has_number' => 'their answer contained a figure',
                'no_number'  => 'their answer contained no figure',
            ],
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

        // gates_programme_questions has an index on slug but NOT a unique one, so two
        // questions can be stored with the same short name — and `questions()` dedupes by slug
        // when it reads them, so one of the two simply never reaches a nominee. Nothing errors
        // and nothing is logged: the operator saves, sees "12 questions saved", and the form
        // asks eleven things.
        $seen = 0;
        $slugs = [];
        $refused = [];

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

            $slug = mb_substr((string) preg_replace('/[^a-z0-9_]/', '',
                        strtolower((string) ($r['slug'] ?? ''))) ?: 'q' . ($seen + 1), 0, 60);
            if (isset($slugs[$slug])) {
                $refused[] = '“' . mb_substr($label, 0, 60) . '” uses the short name ' . $slug
                           . ', which “' . $slugs[$slug] . '” already has.';
                continue;
            }
            $slugs[$slug] = mb_substr($label, 0, 60);

            $data = [
                'programme_id'  => $programmeId,
                'slug'          => $slug,
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
                'placeholder'   => mb_substr(trim((string) ($r['placeholder'] ?? '')), 0, 300) ?: null,
                // ── BRANCHING, WHICH WAS READABLE AND NOT WRITABLE ───────────
                //
                // These four columns have existed since the adaptive migration and
                // QuestionnaireRules has always read them, but this editor never wrote them.
                // The consequence was invisible and bad: the shipped defaults branch, and the
                // moment an operator pressed "copy the defaults in" so they could change one
                // word, every branch was silently dropped. A nominee whose project had closed
                // was then asked, in the present tense, how it is funded — the exact failure
                // the adaptive work was done to remove.
                'show_if_slug'  => mb_substr(preg_replace('/[^a-z0-9_]/', '',
                                    strtolower((string) ($r['show_if_slug'] ?? ''))) ?: '', 0, 60) ?: null,
                'show_if'       => self::branchCondition((string) ($r['show_if'] ?? '')),
                'min_words'     => ($r['min_words'] ?? '') === '' ? null
                                    : max(0, min(200, (int) $r['min_words'])),
                'wants_number'  => !empty($r['wants_number']) ? 1 : 0,
                // Only meaningful for select/checkbox, and stored as the JSON the reader
                // already decodes. One option per line is what an operator can actually type.
                'options_json'  => self::optionsJson((string) ($r['options'] ?? '')),
            ];

            if ($id > 0) {
                DB::table('gates_programme_questions')->where('id', $id)
                    ->where('programme_id', $programmeId)->update($data);
            } else {
                DB::table('gates_programme_questions')->insert($data + ['created_at' => $now]);
            }
        }

        $_SESSION['flash'] = $seen . ' question(s) saved for this programme.';
        if ($refused !== []) {
            // Named rather than renamed. Auto-suffixing a slug behind somebody's back changes
            // where an answer is filed, which is the one thing on this screen that must never
            // happen quietly.
            $_SESSION['flash_error'] = count($refused) . ' were not saved — two questions cannot '
                . 'share a short name. ' . implode(' ', array_slice($refused, 0, 4));
        }
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.questions_saved',
            'programme', $programmeId, ['count' => $seen]);
        return $this->back($res, '/admin/questionnaires/programme/' . $programmeId . '#questions');
    }

    /**
     * A branch condition, or null.
     *
     * Anything unrecognised becomes NULL rather than being stored as typed. `applies()` fails
     * open on an unknown condition — which is the right behaviour at read time and the wrong
     * thing to rely on at write time, because it would let a typo sit in the database looking
     * like a rule that works.
     */
    private static function branchCondition(string $raw): ?string
    {
        $c = strtolower(trim($raw));
        if ($c === '') return null;
        // `is:VALUE` compares against an exact answer — kept, with the value trimmed.
        if (str_starts_with($c, 'is:')) {
            $v = trim(substr($c, 3));
            return $v === '' ? null : mb_substr('is:' . $v, 0, 40);
        }
        return in_array($c, ['answered', 'blank', 'yes', 'no', 'has_number', 'no_number'], true)
            ? $c : null;
    }

    /** One option per line, in and out. Nobody hand-writes a JSON array into a textarea. */
    private static function optionsJson(string $raw): ?string
    {
        $lines = array_values(array_filter(array_map(
            static fn(string $l): string => trim($l),
            preg_split('/\r\n|\r|\n/', $raw) ?: []
        ), static fn(string $l): bool => $l !== ''));
        return $lines === [] ? null : (string) json_encode(array_slice($lines, 0, 40));
    }

    // ══ the interview: style, brief, knowledge, outcomes ═════════════════════

    /**
     * The style toggle and the brief.
     *
     * One endpoint for both because they are one decision: choosing the conversation without
     * writing what it is for produces an interview that asks generic questions, and writing a
     * brief for a programme still set to 'form' produces nothing at all. Saving them together
     * means the screen can say what will happen.
     */
    public function saveStyle(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);
        $body = (array) $req->getParsedBody();

        $ok = QuestionnaireStyle::saveConfig($programmeId, [
            'style'           => (string) ($body['style'] ?? QuestionnaireStyle::FORM),
            'brief'           => (string) ($body['brief'] ?? ''),
            'greeting'        => (string) ($body['greeting'] ?? ''),
            'persona'         => (string) ($body['persona'] ?? ''),
            'closing'         => (string) ($body['closing'] ?? ''),
            'route'           => (string) ($body['route'] ?? ''),
            'max_turns'       => (int) ($body['max_turns'] ?? 40),
            'token_ceiling'   => (int) ($body['token_ceiling'] ?? 120000),
            'kb_token_budget' => (int) ($body['kb_token_budget'] ?? 3000),
        ]);

        $_SESSION[$ok ? 'flash' : 'flash_error'] = $ok
            ? 'Saved. Nominees who have already opened their link keep the style they started on.'
            : 'Nothing was saved.';
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.style_saved',
            'programme', $programmeId, ['style' => (string) ($body['style'] ?? '')]);
        return $this->back($res, '/admin/questionnaires/programme/' . $programmeId . '#interview');
    }

    /** The knowledge base, saved as a set so a reorder is one submission. */
    public function saveKnowledge(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);
        $body = (array) $req->getParsedBody();
        $rows = is_array($body['k'] ?? null) ? $body['k'] : [];

        $n = 0;
        foreach (array_values($rows) as $i => $r) {
            if (!is_array($r)) continue;
            $id    = (int) ($r['id'] ?? 0) ?: null;
            $title = trim((string) ($r['title'] ?? ''));
            // An emptied title retires the entry, the same convention the questions use, so
            // an operator learns one gesture rather than two.
            if ($title === '') {
                if ($id !== null) QuestionnaireStyle::retire('gates_questionnaire_knowledge', $id);
                continue;
            }
            if (QuestionnaireStyle::saveKnowledge($programmeId, $id, $title,
                    (string) ($r['body'] ?? ''), $i + 1) > 0) $n++;
        }

        $_SESSION['flash'] = $n . ' knowledge entr' . ($n === 1 ? 'y' : 'ies') . ' saved.';
        return $this->back($res, '/admin/questionnaires/programme/' . $programmeId . '#knowledge');
    }

    /** The outcomes — the vocabulary the conversation is allowed to use. */
    public function saveOutcomes(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);
        $body = (array) $req->getParsedBody();
        $rows = is_array($body['o'] ?? null) ? $body['o'] : [];

        // ── A REFUSED ROW MUST BE NAMED ──────────────────────────────────────
        //
        // The database has a UNIQUE on (programme_id, slug) and a slug is folded to letters
        // and underscores, so two rows sharing a short name — or one whose short name folds to
        // nothing at all — cannot both be written. That failure was caught, logged, and
        // reported as success: the screen said "5 outcomes saved" and the sixth was simply
        // gone, with the operator's paragraph in it.
        //
        // Checked HERE rather than in the writer because this is the only layer holding the
        // whole submitted set, which is what makes a duplicate visible at all. The constraint
        // stays as the backstop.
        $n = 0;
        $refused = [];
        $seen = [];

        foreach (array_values($rows) as $i => $r) {
            if (!is_array($r)) continue;
            $id    = (int) ($r['id'] ?? 0) ?: null;
            $label = trim((string) ($r['label'] ?? ''));
            if ($label === '') {
                if ($id !== null) QuestionnaireStyle::retire('gates_questionnaire_outcomes', $id);
                continue;
            }

            $slug = QuestionnaireStyle::slug((string) ($r['slug'] ?? ''));
            $name = mb_substr($label, 0, 60);
            if ($slug === '') {
                $refused[] = '“' . $name . '” needs a short name — letters and underscores only.';
                continue;
            }
            if (isset($seen[$slug])) {
                $refused[] = '“' . $name . '” uses the short name ' . $slug
                           . ', which “' . $seen[$slug] . '” already has.';
                continue;
            }
            $seen[$slug] = $name;

            if (QuestionnaireStyle::saveOutcome($programmeId, $id, [
                'slug' => $slug, 'label' => $label,
                'description' => (string) ($r['description'] ?? ''),
                'criterion_id' => (int) ($r['criterion_id'] ?? 0) ?: null,
                'evidence_kind' => (string) ($r['evidence_kind'] ?? 'note'),
                'required' => !empty($r['required']),
                'sort_order' => $i + 1,
            ]) > 0) {
                $n++;
            } else {
                $refused[] = '“' . $name . '” could not be saved.';
            }
        }

        $_SESSION['flash'] = $n . ' outcome(s) saved.';
        if ($refused !== []) {
            $_SESSION['flash_error'] = count($refused) . ' were not saved. '
                . implode(' ', array_slice($refused, 0, 4));
        }
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'questionnaire.outcomes_saved',
            'programme', $programmeId, ['count' => $n]);
        return $this->back($res, '/admin/questionnaires/programme/' . $programmeId . '#outcomes');
    }

    /**
     * Copy the derived outcomes into rows so they can be edited.
     *
     * The same gesture as seeding the questions, and for the same reason: a programme should
     * be able to run an interview on its first day without designing one, and an operator who
     * then wants to change a word should be refining something that already works rather than
     * facing an empty screen.
     */
    public function seedOutcomes(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);
        $n = QuestionnaireStyle::seedOutcomes($programmeId);
        $_SESSION[$n > 0 ? 'flash' : 'flash_error'] = $n > 0
            ? $n . ' outcomes copied in from the questions. Edit them freely.'
            : 'This programme already has its own outcomes.';
        return $this->back($res, '/admin/questionnaires/programme/' . $programmeId . '#outcomes');
    }

    // ══ rehearsal ════════════════════════════════════════════════════════════

    /**
     * The rehearse pane.
     *
     * It drives a real test submission through the real endpoints, so what an administrator
     * meets here is exactly what a nominee will. A preview mode would be a second
     * implementation of the one thing this feature cannot afford to have two of.
     */
    public function rehearse(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);

        $p = DB::table('gates_award_programmes')->where('id', $programmeId)->first();
        if (!$p) {
            $_SESSION['flash_error'] = 'That programme could not be found.';
            return $this->back($res, '/admin/questionnaires');
        }

        QuestionnaireStyle::forget();
        $r = \AfricaGates\Services\QuestionnaireRehearsal::open(
            $programmeId, (int) ($_SESSION['admin_id'] ?? 0) ?: null);

        if (!($r['ok'] ?? false)) {
            $_SESSION['flash_error'] = (string) $r['message'];
            return $this->back($res, '/admin/questionnaires/programme/' . $programmeId);
        }

        $token = (string) $r['token'];
        return $this->view->render($res, 'admin/questionnaires/rehearse.twig', [
            'page_title' => 'Rehearse — ' . $p->title,
            'admin_page' => 'questionnaires',
            'programme'  => (array) $p,
            'token'      => $token,
            'iv'         => \AfricaGates\Services\QuestionnaireInterview::state($token),
            'cfg'        => QuestionnaireStyle::config($programmeId),
            'rules'      => QuestionnaireStyle::rules($programmeId),
            'cases'      => \AfricaGates\Services\QuestionnaireRehearsal::cases($programmeId),
            'personas'   => \AfricaGates\Services\QuestionnaireRehearsal::personas(),
            'live'       => QuestionnaireStyle::interviewPossible($programmeId),
        ]);
    }

    /** Every rehearsal action, so one screen posts to one place. */
    public function rehearseAct(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);
        $body  = (array) $req->getParsedBody();
        $admin = (int) ($_SESSION['admin_id'] ?? 0) ?: null;
        $to    = '/admin/questionnaires/programme/' . $programmeId . '/rehearse';

        switch ((string) ($body['do'] ?? '')) {
            case 'rule':
                // A correction becomes a RULE, with the turn that provoked it kept beside it.
                // A note in a document changes nothing; this changes the next conversation.
                $n = QuestionnaireStyle::addRule($programmeId, (string) ($body['body'] ?? ''),
                        'rehearsal', (string) ($body['note'] ?? ''), $admin);
                $_SESSION[$n > 0 ? 'flash' : 'flash_error'] = $n > 0
                    ? 'Rule added. It is part of the brief from the next message onward.'
                    : 'Nothing was written.';
                break;

            case 'case':
                $r = \AfricaGates\Services\QuestionnaireRehearsal::saveCase(
                        $programmeId, (string) ($body['token'] ?? ''),
                        (string) ($body['title'] ?? ''), (string) ($body['persona'] ?? ''), $admin);
                $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
                break;

            case 'run':
                $r = \AfricaGates\Services\QuestionnaireRehearsal::runCase(
                        (int) ($body['case'] ?? 0), $admin);
                $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
                break;

            case 'drop':
                \AfricaGates\Services\QuestionnaireRehearsal::dropCase((int) ($body['case'] ?? 0));
                $_SESSION['flash'] = 'Case removed.';
                break;

            case 'reset':
                \AfricaGates\Services\QuestionnaireRehearsal::reset($programmeId, $admin);
                $_SESSION['flash'] = 'Started again.';
                break;
        }

        return $this->back($res, $to);
    }

    /** Switch one knowledge entry, outcome or rule back on after it was retired. */
    public function restoreRow(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $programmeId = (int) ($args['id'] ?? 0);
        $body = (array) $req->getParsedBody();
        QuestionnaireStyle::restore('gates_questionnaire_' . (string) ($body['what'] ?? ''),
                                    (int) ($body['row'] ?? 0));
        return $this->back($res, '/admin/questionnaires/programme/' . $programmeId);
    }
}
