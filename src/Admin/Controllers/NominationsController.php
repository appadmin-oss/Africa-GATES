<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Support\Env;
use AfricaGates\Support\OptionalColumn;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Support\Filters;
use AfricaGates\Support\Paginator;
use AfricaGates\Services\{OtpService, WebhookService, AwardService, GatedFormService};

class NominationsController
{
    public function __construct(
        private readonly Twig         $view,
        private readonly AuditService $audit,
        private readonly ?OtpService  $mailer = null,
        private readonly ?AwardService $awards = null,
    ) {}

    /**
     * The old "All nominations" list, retired 21 Oct 2026: it was a second page for the same
     * nominations the review list already shows by status, so the console had two
     * Nominations pages. The address forwards there, carrying what it can say in the
     * review list's own words — so the many links into it (the desk, the attention board,
     * the staff alert email, the palette) keep landing somewhere sensible.
     */
    public function index(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $tabs = \AfricaGates\Admin\Services\ReviewQueue::TABS;
        $out = [];
        $status = (string) ($p['status'] ?? '');
        if (isset($tabs[$status])) $out['tab'] = $status;
        if (($q = trim((string) ($p['q'] ?? ''))) !== '') $out['q'] = $q;
        if (($c = (int) ($p['cycle'] ?? 0)) > 0) $out['scope'] = 'c' . $c;
        elseif (($pr = (int) ($p['programme'] ?? 0)) > 0) $out['scope'] = 'p' . $pr;
        if (($cc = strtoupper(trim((string) ($p['country'] ?? '')))) !== '') $out['cc'] = [$cc];
        if (($p['sort'] ?? '') === 'oldest') { $out['sort'] = 'recv'; $out['dir'] = 'asc'; }
        $qs = http_build_query($out);
        return $res->withHeader('Location', '/admin/nominations/review' . ($qs !== '' ? '?' . $qs : ''))->withStatus(301);
    }

    /**
     * AI filter: turn a plain-English request ("pending STEM nominations from
     * Kenya this month") into the list's own whitelisted filter params and
     * redirect to the filtered view. Falls back to a plain text search on the
     * phrase when no AI provider is configured — so the box always does
     * something useful. GET stays the source of truth; this just fills it in.
     */
    public function aiFilter(Request $req, Response $res): Response
    {
        $q = trim((string) (((array) $req->getParsedBody())['ai_query'] ?? ''));
        if ($q === '') return $res->withHeader('Location', '/admin/nominations')->withStatus(302);

        $params = \AfricaGates\Services\AiFilterService::parseNominationFilter($q);
        if ($params === null || $params === []) {
            $_SESSION['flash_notice'] = 'AI filtering is unavailable — showing a text search instead.';
            $params = ['q' => mb_substr($q, 0, 80)];
        }
        $qs = http_build_query($params);
        return $res->withHeader('Location', '/admin/nominations' . ($qs !== '' ? '?' . $qs : ''))->withStatus(302);
    }

    /**
     * The AI's triage score expressed in the same vocabulary as the human's
     * verdict, so the two are comparable.
     *
     * The score is 0-100 for "how complete, specific and credible this reads".
     * Anything at or above the midpoint is read as a lean toward approval. This
     * is a coarse mapping on purpose — the point is to detect whether the score
     * tracks reviewer judgement at all, not to grade it finely.
     */
    private static function triageLean(int $nominationId): ?string
    {
        $insight = \AfricaGates\Services\NominationTriageService::insight($nominationId);
        $score   = $insight['quality_score'] ?? null;
        if ($score === null) return null;
        return (int) $score >= 50 ? 'approved' : 'rejected';
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $action = $args['action'] ?? '';
        $nom = DB::table('gates_nominations')->where('id', $id)->first();
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);
        // Captured BEFORE the decision, so the comparison is against what the
        // reviewer actually had in front of them.
        $aiLean = self::triageLean($id);
        // Desk mode: after a decision, auto-advance to the next pending
        // nomination instead of bouncing back to the list. Same audited
        // process — only the redirect changes.
        $fromDesk = (((array)$req->getParsedBody())['desk'] ?? '') === '1';
        // Desk SPA posts over fetch with this header: return JSON so it can swap
        // in the next nomination instead of following a redirect.
        $wantsJson = strtolower($req->getHeaderLine('X-Requested-With')) === 'fetch';
        $deskBack  = $fromDesk ? '/admin/nominations/desk?item=' . $id : '/admin/nominations/' . $id;
        // The review list (v2) posts its decisions with `back` = the list as it stood, so
        // the reviewer lands where they were, filters and all. Only that one path is
        // accepted: a free `back` is an open redirect wearing an admin session.
        $back = self::reviewBack((string) (((array) $req->getParsedBody())['back'] ?? ''));
        if ($back !== null) $deskBack = $back;

        // Uniform exits: JSON for the SPA, flash+redirect for the classic flow.
        $fail = function (string $msg, int $code = 422) use ($res, $wantsJson, $deskBack): Response {
            if ($wantsJson) {
                $res->getBody()->write((string) json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
            }
            $_SESSION['flash_error'] = $msg;
            return $res->withHeader('Location', $deskBack)->withStatus(302);
        };
        $ok = function (string $msg, string $redirect) use ($res, $wantsJson): Response {
            if ($wantsJson) {
                $res->getBody()->write((string) json_encode(['ok' => true, 'message' => $msg], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return $res->withHeader('Content-Type', 'application/json');
            }
            $_SESSION['flash_ok'] = $msg;
            return $res->withHeader('Location', $redirect)->withStatus(302);
        };

        // Nobody decides a nomination that carries their own inbox (ReviewConflict). Checked
        // before anything else, so bulk approve — which runs this same method — is held too.
        if (in_array($action, \AfricaGates\Admin\Services\ReviewConflict::GUARDED, true)) {
            $conflict = \AfricaGates\Admin\Services\ReviewConflict::of((int) ($_SESSION['admin_id'] ?? 0), $nom);
            if ($conflict !== null) return $fail($conflict, 403);
        }

        // The nominee's own contact, corrected by a reviewer. The address the nominator typed
        // is where the nominee's form link goes, so it may not be set to an inbox somebody who
        // nominated them reads — that is the case this edit exists to repair.
        if ($action === 'contact') {
            $b = (array) $req->getParsedBody();
            $email = trim((string) ($b['nominee_email'] ?? ''));
            $phone = trim((string) ($b['nominee_phone'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return $fail('That email address does not look right.');
            $e164 = $phone !== '' ? \AfricaGates\Support\Phone::normalize($phone, 'NG') : null;
            if ($phone !== '' && $e164 === null) return $fail('That phone number does not look right. Start with the country code if it is not a Nigerian number.');
            $probe = (object) ['nominee_email' => $email, 'cycle_id' => $nom->cycle_id, 'nominee_name' => $nom->nominee_name];
            if ($email !== '' && \AfricaGates\Services\NominationSignals::contactIsNominators($probe)) {
                return $fail('That is the address of somebody who nominated them. The nominee’s form link has to reach the nominee.');
            }
            $changes = ['nominee_email' => $email !== '' ? mb_substr($email, 0, 190) : null, 'nominee_phone' => $e164];
            $fields = array_keys(array_filter($changes, static fn ($v, $k): bool => (string) ($nom->{$k} ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH));
            if ($fields === []) return $ok('Nothing changed.', '/admin/nominations/' . $id);
            try {
                DB::table('gates_nominations')->where('id', $id)->update(OptionalColumn::filter('gates_nominations', $changes, ['nominee_phone']));
            } catch (\Throwable $e) {
                return $fail($this->dbErrorFlash($e), 500);
            }
            // Which fields moved, never their values: the audit log is read by every role.
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'nomination.contact', 'nomination', $id, ['fields' => $fields]);
            return $ok('Saved the nominee’s own contact.' . ((string) $nom->status === 'approved' ? ' Send the form link again from here.' : ''), '/admin/nominations/' . $id);
        }

        // The desk's one decision form names its choice; it runs exactly the action the
        // three separate buttons on the list run.
        if ($action === 'decide') {
            $choice = (string) (((array) $req->getParsedBody())['choice'] ?? '');
            if (!in_array($choice, ['approve', 'ask', 'reject'], true)) return $fail('Choose Approve, Ask for more or Reject.');
            $action = $choice;
        }

        // ── ONE DECISION PER NOMINATION ──────────────────────────────────────────
        //
        // The review list and the desk are built for several reviewers at once, and
        // presence only informs. Without this, two reviewers pressing Approve on the same
        // nomination both approved it — two "approved" emails to the nominator, a second
        // single-use form link to the nominee — and Reject could reject a nomination that
        // was already approved while its nominee stayed on the ballot. So a decision that
        // is already the nomination's status is refused, reject never undoes an approval
        // (that is a withdrawal, a different act), and the write below is CONDITIONAL on
        // the status the reviewer saw, so the second of two simultaneous presses changes
        // nothing and is told so. Bulk approve already refused anything not pending.
        $seen = (string) $nom->status;
        $target = ['approve' => 'approved', 'merge' => 'approved', 'reject' => 'rejected', 'pending' => 'pending'][$action] ?? null;
        if ($target !== null && $seen === $target) {
            return $fail('This nomination is already ' . $target . '. Somebody may have decided it a moment ago.', 409);
        }
        if ($action === 'reject' && $seen === 'approved') {
            return $fail('This nomination is approved and its nominee is on the ballot. Rejecting it now would not take them off; withdraw the nominee instead.', 409);
        }
        $taken = static fn (): \RuntimeException => new \RuntimeException('nomination.decided-elsewhere');

        // The desk's optional team note travels with whichever decision it was written
        // beside, and is kept only once that decision has landed.
        $teamNote = trim((string) (((array) $req->getParsedBody())['team_note'] ?? ''));
        $keepNote = function () use ($teamNote, $id): void {
            if ($teamNote !== '') \AfricaGates\Admin\Services\ReviewQueue::addNote($id, (int) ($_SESSION['admin_id'] ?? 0), $teamNote);
        };

        // Ask for more — the nominator is emailed one link to add what is missing; the
        // nomination waits in Asked until they do. NominationAsk holds the rules.
        if ($action === 'ask') {
            $r = \AfricaGates\Services\NominationAsk::ask($nom, (string) (((array) $req->getParsedBody())['asked_message'] ?? ''), $this->mailer);
            if (!$r['ok']) return $fail($r['error'] ?? 'The request could not be sent.');
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'nomination.ask', 'nomination', $id);
            $keepNote();
            return $ok('Asked the nominator for more. It is in Asked until they reply.',
                $back ?? ($fromDesk ? '/admin/nominations/desk?after=' . $id : '/admin/nominations/review?tab=needs_details'));
        }

        // Not a duplicate — a reviewer's ruling that a same-name entry is somebody else.
        // Kept on the row, so the desk and the list stop raising the same pair. It is not
        // a decision about the nomination, which stays where it was.
        if ($action === 'apart') {
            if (!\AfricaGates\Support\SchemaHas::column('gates_nominations', 'not_duplicate')) {
                return $fail('The database needs its latest update before this works.');
            }
            DB::table('gates_nominations')->where('id', $id)->update(['not_duplicate' => 1]);
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'nomination.not_duplicate', 'nomination', $id);
            return $ok('Marked as a different person.', $back ?? ($fromDesk ? '/admin/nominations/desk?item=' . $id : '/admin/nominations/' . $id));
        }

        // Reject / pending — no category needed
        if ($action === 'reject' || $action === 'pending') {
            $status = ['reject' => 'rejected', 'pending' => 'pending'][$action];
            // Decision note to the nominator: the moderator's own words, or —
            // when they leave it blank on a rejection — an AI-drafted reason
            // (moderation AI, free backup). Collected here, shown in the email.
            $reason = mb_substr(trim((string) (((array) $req->getParsedBody())['decision_reason'] ?? '')), 0, 800);
            // The desk rejects with one of four reasons, and the nominator is shown it.
            // There, no reason is a refusal rather than a drafted one: the reviewer was
            // made to pick, so an empty pick means the form was bypassed.
            if ($action === 'reject' && $reason === '' && $fromDesk) {
                $reason = \AfricaGates\Admin\Services\ReviewDesk::reasonText((string) (((array) $req->getParsedBody())['reject_reason'] ?? ''));
                if ($reason === '') return $fail('Pick the reason the nominator will see.');
            }
            if ($action === 'reject' && $reason === '') {
                try { $reason = (string) (\AfricaGates\Services\NominationFeedbackService::suggestReason($nom, 'rejected') ?? ''); }
                catch (\Throwable $e) { $reason = ''; }
            }
            try {
                // The STATUS is the decision; the reason is feedback attached to it. So
                // the reason is filtered out on a database that lacks the column rather
                // than taking the whole update down with it — a reviewer must be able to
                // approve or reject regardless. This is the failure the admin console's
                // migration banner names out loud: "approving/rejecting nominations …
                // are failing with errors while pages still load".
                $changed = DB::table('gates_nominations')->where('id', $id)->where('status', $seen)->update(
                    OptionalColumn::filter('gates_nominations', [
                        'status'          => $status,
                        'decision_reason' => $reason !== '' ? $reason : null,
                    ], ['decision_reason'])
                );
                // Zero rows: the status moved since this request read it — another
                // reviewer decided first.
                if ($changed === 0) {
                    return $fail('Somebody else decided this nomination a moment ago. Nothing was changed and nobody was emailed.', 409);
                }
                // Whether the AI's lean matched the human's call. Without this
                // there is no way to tell if the triage score is helping the
                // reviewer or merely decorating the page.
                if ($action === 'reject') {
                    \AfricaGates\Services\AiDecisionLog::record(
                        'nomination.triage', 'nomination', $id, $aiLean, 'rejected',
                        (int) ($_SESSION['admin_id'] ?? 0) ?: null
                    );
                }
            } catch (\Throwable $e) {
                return $fail($this->dbErrorFlash($e), 500);
            }
            $this->audit->record((int)$_SESSION['admin_id'], "nomination.$action", 'nomination', $id);
            if ($action === 'reject') {
                \AfricaGates\Services\WebhookService::dispatch('nomination.rejected', [
                    'nomination_id' => $id,
                    'nominee'       => (string) $nom->nominee_name,
                    'reference'     => (string) ($nom->reference ?? ''),
                ]);
            }

            // Email the nominator when their nomination is rejected — the handoff's "Not taken
            // forward". The reviewer's reason travels as written; with none, the email says
            // so rather than guessing one ("did not meet the eligibility criteria" was a
            // reason nobody had given).
            if ($action === 'reject' && $this->mailer && filter_var($nom->nominator_email ?? '', FILTER_VALIDATE_EMAIL)) {
                $base = \AfricaGates\Support\SiteUrl::base();
                $slug = '';
                try {
                    $slug = (string) (DB::table('gates_award_cycles as cy')
                        ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                        ->where('cy.id', (int) ($nom->cycle_id ?? 0))->value('p.slug') ?? '');
                } catch (\Throwable) {}
                try {
                    \AfricaGates\Services\Mail\Emails::send($this->mailer, (string) $nom->nominator_email, 'rejected', [
                        'nominee'   => (string) $nom->nominee_name,
                        'reason'    => $reason,
                        'rules_url' => $base . ($slug !== '' ? '/awards/' . rawurlencode($slug) : '/awards'),
                        'help_url'  => $base . '/support',
                        'site_url'  => $base,
                    ]);
                } catch (\Throwable) {}
            }

            $keepNote();
            $msg = 'Nomination ' . $status . ($reason !== '' && $action === 'reject' ? ' — reason sent to the nominator.' : '.');
            return $ok($msg, $back ?? ($fromDesk ? '/admin/nominations/desk?after=' . $id : '/admin/nominations?status=' . $nom->status));
        }

        // Approve flow — admin must pick a category (POST body category_id)
        if ($action !== 'approve' && $action !== 'merge') throw new \Slim\Exception\HttpNotFoundException($req);
        $b = (array)$req->getParsedBody();
        $catId = (int)($b['category_id'] ?? $nom->category_id ?? 0);

        // Merge — the desk's "Merge into earlier": this nomination is a repeat of one
        // already approved, so it is approved INTO that entry's category and resolves to
        // the same nominee (one ballot entry, both nominators credited). Never a
        // rejection, and never a second nominee: if the earlier entry's nominee cannot be
        // found, nothing is written.
        $mergeInto = null;
        if ($action === 'merge') {
            $mergeInto = DB::table('gates_nominations')->where('id', (int) ($b['merge_into'] ?? 0))->first();
            if (!$mergeInto || (int) $mergeInto->id === $id || (int) $mergeInto->cycle_id !== (int) $nom->cycle_id
                || (string) $mergeInto->status !== 'approved' || (int) ($mergeInto->category_id ?? 0) < 1
                || (\AfricaGates\Services\NominationTriageService::nameKey((string) $mergeInto->nominee_name)
                    !== \AfricaGates\Services\NominationTriageService::nameKey((string) $nom->nominee_name)
                    // A near-miss spelling (Muhammed / Mohammed) is the case the desk offers,
                    // by the same rule that flagged it — a button it drew must be one that works.
                    && !\AfricaGates\Services\NominationTriageService::similarName((string) $mergeInto->nominee_name, (string) $nom->nominee_name))) {
                return $fail('That earlier nomination cannot take a merge. Only an approved entry for the same person in this edition can.');
            }
            $catId = (int) $mergeInto->category_id;
        }
        if (!$catId) {
            return $fail('Choose a category before approving.');
        }
        $cat = DB::table('gates_award_categories')->where('id', $catId)->first();
        if (!$cat || (int)$cat->cycle_id !== (int)$nom->cycle_id) {
            return $fail('That category is not part of this nomination\'s cycle.');
        }

        // Eligibility gate — when enforced (Settings), a nominee must have nominations
        // from at least N different locations before they can be approved.
        if ($this->awards) {
            $elig = $this->awards->nomineeEligibility((string)$nom->nominee_name, (int)$nom->cycle_id);
            if ($elig['enabled'] && !$elig['eligible']) {
                return $fail(sprintf(
                    'Not yet eligible — %d of %d required locations. Adjust the threshold in Settings → Nomination eligibility.',
                    $elig['distinct_locations'], $elig['min']
                ));
            }
        }

        // Optional welcome note to the nominator, included in the approval email.
        $approveReason = mb_substr(trim((string) ($b['decision_reason'] ?? '')), 0, 800);
        // Approve + create/resolve the nominee ATOMICALLY. If any write fails —
        // most commonly a stale DB missing a column/table from an unapplied
        // migration — the transaction rolls back and the moderator gets an
        // actionable flash, never a raw 500 with a half-approved nomination.
        $nomineeId = 0;
        try {
            DB::transaction(function () use ($id, $catId, $approveReason, $nom, $mergeInto, $seen, $taken, &$nomineeId) {
                $changed = DB::table('gates_nominations')->where('id', $id)->where('status', $seen)->update(
                    // Same rule as the reject path: the approval must land even when the
                    // feedback column does not exist yet. `status` and `category_id` are
                    // the approval; `decision_reason` is a note about it.
                    OptionalColumn::filter('gates_nominations', [
                        'status'          => 'approved',
                        'category_id'     => $catId,
                        'decision_reason' => $approveReason !== '' ? $approveReason : null,
                    ], ['decision_reason'])
                );
                // The status moved since this request read it: another reviewer decided
                // first. Thrown so the whole approval (nominee row included) rolls back.
                if ($changed === 0) throw $taken();

                // Auto-attach on approval: if a nominee for this person already
                // exists in the category, link to it instead of minting a duplicate
                // that would split their votes. The resolver is honorific / case /
                // punctuation insensitive ("Dr. Jane Doe" → existing "jane doe") and
                // skips tombstones — the prevention that pairs with the merge cure.
                // Unless a reviewer ruled this a different person who shares the name
                // ("Not a duplicate" on the desk): then joining the existing entry is
                // exactly the wrong answer, and a nominee of their own is minted.
                // A merge joins the EARLIER entry's nominee, found by that entry's own name:
                // on a near-miss spelling this nomination's name matches nobody.
                $nomineeId = (int) ($nom->not_duplicate ?? 0) === 1 && $mergeInto === null ? 0
                    : \AfricaGates\Services\MergeSuggestionService::findLiveMatch($catId, (string) ($mergeInto->nominee_name ?? $nom->nominee_name));
                if ($nomineeId < 1 && $mergeInto !== null) {
                    throw new \RuntimeException('merge: the earlier entry has no live nominee to join');
                }
                if ($nomineeId < 1) {
                    // Best-effort auto-link to an existing registry profile so this
                    // nominee's votes + judge scores roll up into the CPI leaderboard.
                    // Match by nominee email (exact) first, then by display name.
                    $profileId = null;
                    // Auto-link ONLY on an unambiguous single match — if two+ approved
                    // profiles share the email/name, linking to an arbitrary one would
                    // roll this nominee's votes + judge scores into the wrong profile.
                    // Ambiguous cases stay unlinked for manual resolution.
                    $nomEmail = strtolower(trim((string)($nom->nominee_email ?? '')));
                    if ($nomEmail !== '') {
                        $m = DB::table('gates_profiles')->where('status', 'approved')
                            ->whereRaw('LOWER(email) = ?', [$nomEmail])->limit(2)->pluck('id');
                        $profileId = $m->count() === 1 ? $m->first() : null;
                    }
                    if (!$profileId) {
                        $m = DB::table('gates_profiles')->where('status', 'approved')
                            ->whereRaw('LOWER(display_name) = ?', [strtolower(trim((string)$nom->nominee_name))])
                            ->limit(2)->pluck('id');
                        $profileId = $m->count() === 1 ? $m->first() : null;
                    }
                    $nomineeId = (int)DB::table('gates_nominees')->insertGetId(
                        OptionalColumn::filter('gates_nominees', [
                            'category_id'  => $catId,
                            'profile_id'   => $profileId ?: null,
                            // Normalised again at approval, not only at submission.
                            // Rows already sitting in the queue when this shipped
                            // still carry whatever was typed, and this is the last
                            // moment before the name becomes a public ballot entry.
                            'name'         => \AfricaGates\Support\Name::title((string)$nom->nominee_name),
                            // ── THE STORY SURVIVES APPROVAL NOW ──────────────────────
                            //
                            // This was `mb_substr($nom->reason, 0, 200)` into `tagline`
                            // and nothing else — so a nominator's 3,000-character case
                            // for a person became 200 characters cut mid-word, and the
                            // full text stayed in gates_nominations.reason, which no
                            // public page joins to. Every ballot on the platform showed
                            // a fragment with no way to read on, because there was
                            // nothing more on the row to read.
                            //
                            // `story` carries the whole thing and is what the ballot
                            // prints. `tagline` stays the SHORT line that leaderboard
                            // rows, cards and fliers need — but it now ends where a
                            // sentence ends instead of at a byte count.
                            'story'        => trim((string)$nom->reason) ?: null,
                            'tagline'      => \AfricaGates\Support\Text::firstSentence((string)$nom->reason, 200),
                            'country_code' => $nom->country_code,
                            // CARRIED ACROSS, not re-asked. The nomination form already
                            // collected the school/organisation and approval used to drop
                            // it here — so every public surface downstream had nothing to
                            // show, because by then the value no longer existed on the row
                            // being rendered.
                            'organisation' => mb_substr(trim((string)($nom->nominee_org ?? '')), 0, 200) ?: null,
                            'status'       => 'approved',
                            'vote_count'   => 0,
                            'nominated_at' => Carbon::now()->toDateTimeString(),
                        ], ['organisation'])
                    );
                }
            });
        } catch (\Throwable $e) {
            if ($e->getMessage() === 'nomination.decided-elsewhere') {
                return $fail('Somebody else decided this nomination a moment ago. Nothing was changed and nobody was emailed.', 409);
            }
            if ($mergeInto !== null && str_starts_with($e->getMessage(), 'merge:')) {
                return $fail('The earlier entry is not on the ballot any more, so nothing was merged. Approve or reject this one instead.');
            }
            return $fail($this->dbErrorFlash($e), 500);
        }
        if ($mergeInto !== null) {
            try {
                if (\AfricaGates\Support\SchemaHas::column('gates_nominations', 'merged_into')) {
                    DB::table('gates_nominations')->where('id', $id)->update(['merged_into' => (int) $mergeInto->id]);
                }
            } catch (\Throwable) {}
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'nomination.merge', 'nomination', $id, ['into' => (int) $mergeInto->id]);
        }
        $keepNote();

        // The counterpart of the rejection record. Agreement is only meaningful if
        // BOTH verdicts are captured — logging only one outcome silently biases
        // the rate toward it.
        \AfricaGates\Services\AiDecisionLog::record(
            'nomination.triage', 'nomination', $id, $aiLean, 'approved',
            (int) ($_SESSION['admin_id'] ?? 0) ?: null
        );

        $this->audit->record((int)$_SESSION['admin_id'], 'nomination.approve', 'nomination', $id, ['category_id' => $catId]);

        // Notify subscribed integrations / AI agents that a nominee is now live.
        WebhookService::dispatch('nomination.approved', [
            'nomination_id' => $id,
            'nominee'       => (string) $nom->nominee_name,
            'category_id'   => $catId,
            'category'      => (string) $cat->title,
        ]);

        // Email the nominator — their nomination was approved and is now live
        if ($this->mailer && filter_var($nom->nominator_email ?? '', FILTER_VALIDATE_EMAIL)) {
            $prog  = DB::table('gates_award_programmes')
                ->join('gates_award_cycles', 'gates_award_cycles.programme_id', '=', 'gates_award_programmes.id')
                ->where('gates_award_cycles.id', $nom->cycle_id)
                ->value('gates_award_programmes.title') ?? 'Africa GATES Awards';
            $base = rtrim((string) Env::get('APP_URL', 'https://afg.afrovanguard.org.ng'), '/');
            // Escape attacker/admin-supplied fields before interpolating into HTML.
            $nn = htmlspecialchars((string)$nom->nominee_name, ENT_QUOTES, 'UTF-8');
            $by = htmlspecialchars((string)$nom->nominator_name, ENT_QUOTES, 'UTF-8');
            $pg = htmlspecialchars((string)$prog, ENT_QUOTES, 'UTF-8');
            $ct = htmlspecialchars((string)$cat->title, ENT_QUOTES, 'UTF-8');
            $html = <<<HTML
<p>Hi <strong>{$by}</strong>,</p>
<p>Great news — your nomination of <strong>{$nn}</strong> has been <span style="color:#15803d;font-weight:700">approved</span> and is now live on the Africa GATES platform.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:14px 18px">
  <tr><td style="font-size:14px;color:#166534;line-height:1.7">
    Nominee: <strong>{$nn}</strong><br>
    Programme: <strong>{$pg}</strong><br>
    Category: <strong>{$ct}</strong><br>
    Status: <strong style="color:#15803d">Live — open for community voting</strong>
  </td></tr>
</table>
<p>Share the voting page with your network to help <strong>{$nn}</strong> gather votes. Community votes account for 45% of the final score — every vote counts.</p>
<p style="text-align:center;margin:24px 0">
  <a href="{$base}/vote" style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px">
    View the voting page →
  </a>
</p>
HTML;
            $this->mailer->sendBranded(
                $nom->nominator_email,
                "Your nomination of {$nom->nominee_name} is approved and live",
                $html,
                "Hi {$nom->nominator_name},\n\nYour nomination of {$nom->nominee_name} for {$prog} — {$cat->title} has been approved and is now live.\n\nShare the vote page: {$base}/vote\n\n— Africa GATES",
                'Nominations'
            );
        }

        // Verified nominee → congratulatory email with a single-use gated form link. Not on
        // a merge: the nominee already had theirs when the earlier entry was approved.
        $form = $mergeInto === null ? $this->sendNomineeForm($nom, $nomineeId) : '';

        return $ok(
            $mergeInto !== null ? 'Merged into the earlier entry in "' . $cat->title . '". Both nominators keep credit.'
                                : 'Nomination approved and added to "' . $cat->title . '".'
                                  . ($form === 'withheld' ? ' The nominee’s form link was not sent: their email is a nominator’s own. Add the nominee’s address on the full record and send it from there.' : ''),
            $back ?? ($fromDesk ? '/admin/nominations/desk?after=' . $id : '/admin/nominations?status=approved')
        );
    }

    /** POST /admin/nominations/{id}/regenerate-form — re-issue the nominee's single-use form link + resend. */
    public function regenerateForm(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $nom = DB::table('gates_nominations')->where('id', $id)->first();
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);
        if ($nom->status !== 'approved') {
            $_SESSION['flash_error'] = 'Approve the nomination before sending an acceptance form.';
            return $res->withHeader('Location', '/admin/nominations/' . $id)->withStatus(302);
        }
        // Same resolver as approval, so we find the nominee even when it was
        // auto-attached under a slightly different spelling of the name.
        $nomineeId = \AfricaGates\Services\MergeSuggestionService::findLiveMatch((int)$nom->category_id, (string)$nom->nominee_name);
        if ($nomineeId < 1) {
            $_SESSION['flash_error'] = 'Could not locate the created nominee record.';
            return $res->withHeader('Location', '/admin/nominations/' . $id)->withStatus(302);
        }
        $sent = $this->sendNomineeForm($nom, $nomineeId);
        if ($sent === 'sent') {
            $this->audit->record((int)$_SESSION['admin_id'], 'nomination.regenerate_form', 'nomination', $id);
            $_SESSION['flash_ok'] = 'A fresh single-use acceptance form link was emailed to the nominee (any previous link is now void).';
        } elseif ($sent === 'withheld') {
            $_SESSION['flash_error'] = 'Not sent: the nominee’s email is the address of somebody who nominated them, so the link would reach the nominator. Add the nominee’s own address, then send it again.';
        } else {
            $_SESSION['flash_error'] = 'Could not issue or email the acceptance form link — check the nominee has a valid email and that mail is configured, then try again.';
        }
        return $res->withHeader('Location', '/admin/nominations/' . $id)->withStatus(302);
    }

    /**
     * Turn an unexpected DB write failure into an actionable flash instead of a
     * raw 500. The overwhelmingly common cause is an unapplied migration — a
     * write touches a column/table a stale database doesn't have yet — so name
     * that first. No decision is committed when this fires (writes are wrapped).
     */
    private function dbErrorFlash(\Throwable $e): string
    {
        return \AfricaGates\Admin\Support\ActionError::dbMessage($e);
    }

    /**
     * Issue a single-use nominee form token + email the verified nominee a
     * congrats + link. Returns true only when the email was actually sent, so
     * the explicit regenerate action can report an honest success/failure. In
     * the approve flow the return value is ignored — sending is best-effort
     * there and must never break an approval that already committed.
     */
    /**
     * Issue the nominee's single-use form link and email it.
     *
     * @return string 'sent' | 'withheld' (the address is a nominator's own — see
     *                NominationSignals::contactIsNominators) | 'failed'
     */
    private function sendNomineeForm(object $nom, int $nomineeId): string
    {
        $email = strtolower(trim((string)($nom->nominee_email ?? '')));
        if (!$this->mailer || $nomineeId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'failed';
        if (\AfricaGates\Services\NominationSignals::contactIsNominators($nom)) return 'withheld';
        // Issuing the token, building the link, and sending are ALL best-effort:
        // a missing gates_form_tokens table (stale DB) or a mail failure must
        // never bubble up and 500 an approval that already committed.
        try {
            $ttl  = 30;
            $raw  = GatedFormService::issue('nominee', $nomineeId, $email, $ttl);
            $link = GatedFormService::link($raw);
            // The handoff's "Approved". The page, the dates and the deadline are the live
            // ones: the form is due when this single-use link expires.
            $base = \AfricaGates\Support\SiteUrl::base();
            $row  = DB::table('gates_nominees as n')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('n.id', $nomineeId)
                ->first(['n.id', 'n.name', 'c.title as category', 'cy.voting_open', 'p.title as programme', 'p.slug as programme_slug']);
            $opens = '';
            if ($row && !empty($row->voting_open)) {
                try { $opens = \Illuminate\Support\Carbon::parse((string) $row->voting_open)->format('j F Y'); } catch (\Throwable) {}
            }
            $name = (string) ($row->name ?? $nom->nominee_name);
            $r = \AfricaGates\Services\Mail\Emails::send($this->mailer, $email, 'approved', [
                'programme'    => (string) ($row->programme ?? ''),
                'first_name'   => \AfricaGates\Services\DoorWelcome::firstName($name) ?: $name,
                'category'     => (string) ($row->category ?? ''),
                'due'          => \Illuminate\Support\Carbon::now()->addDays($ttl)->format('j F Y'),
                'voting_opens' => $opens,
                'form_url'     => $link,
                // The one builder, so the link in the email is the page's own link.
                'page_url'     => !empty($row->programme_slug) ? \AfricaGates\Support\NomineeUrl::ballot($nomineeId) : '',
                'site_url'     => $base,
            ]);
            return ($r['success'] ?? false) ? 'sent' : 'failed';
        } catch (\Throwable $e) { /* token issue or mail failure — never break approval */ }
        return 'failed';
    }

    /**
     * The review desk (AdminConsole v2, README §10b): the queue, one nomination, and the
     * decision beside it. `?item=N` opens one; `?after=N` opens the one after it (where a
     * decision landed); neither opens the oldest. The list's scope and quick view carry
     * over, so the desk works the same rows the list's To review tab shows.
     */
    public function desk(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'admin/nominations/desk.twig', [
            'page_title' => 'Review desk — Admin',
            'admin_page' => 'review',
        ] + $this->deskContext($req->getQueryParams()));
    }

    /**
     * The desk's two working panes for review-desk.js, as JSON
     * ({ok, done, id, nominee, position, total, html}). Same choice of nomination as
     * desk(); `done` when nothing is left.
     */
    public function deskFragment(Request $req, Response $res): Response
    {
        $json = function (array $p, int $code = 200) use ($res): Response {
            $res->getBody()->write((string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        };
        $ctx = $this->deskContext($req->getQueryParams());
        if ($ctx['n'] === null) {
            return $json(['ok' => true, 'done' => true, 'message' => 'Nothing is left in this queue.']);
        }
        $html = $this->view->fetch('admin/nominations/_desk_main.twig', $ctx + [
            'csrf_token' => ($_SESSION['csrf_token'] ?? ''),
            'shell' => ['can_write' => \AfricaGates\Admin\Support\Permissions::canWrite((string) ($_SESSION['admin_role'] ?? ''))],
        ]);
        return $json([
            'ok'       => true,
            'done'     => false,
            'id'       => (int) $ctx['n']['id'],
            'nominee'  => (string) $ctx['n']['name'],
            'position' => $ctx['pos'],
            'total'    => $ctx['total'],
            'html'     => $html,
        ]);
    }

    /** @return array<string,mixed> what both desk views draw */
    private function deskContext(array $q): array
    {
        $p = \AfricaGates\Admin\Services\ReviewQueue::params(['tab' => 'pending'] + array_intersect_key($q, ['scope' => 1, 'view' => 1]));
        $queue = \AfricaGates\Admin\Services\ReviewQueue::deskQueue($p, $this->awards);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $queue);
        $id = self::deskPick($ids, (int) ($q['item'] ?? 0), (int) ($q['after'] ?? 0));

        $keep = array_filter(['scope' => $p['scope'], 'view' => $p['view'] === 'all' ? '' : $p['view']]);
        $qbase = '/admin/nominations/desk?' . ($keep ? http_build_query($keep) . '&' : '');
        $at = array_search($id, $ids, true);
        $n = $id > 0 ? \AfricaGates\Admin\Services\ReviewDesk::view($id, $this->awards, (int) ($_SESSION['admin_id'] ?? 0)) : null;
        $count = count($ids);
        return [
            'queue'      => $queue,
            'n'          => $n,
            'pos'        => $at === false ? null : $at + 1,
            'total'      => $count,
            'qbase'      => $qbase,
            'back'       => $qbase . 'item=' . $id,
            'prev_href'  => $count ? $qbase . 'item=' . $ids[$at === false ? 0 : ($at - 1 + $count) % $count] : null,
            'next_href'  => $count ? $qbase . 'item=' . $ids[$at === false ? 0 : ($at + 1) % $count] : null,
            'list_href'  => \AfricaGates\Admin\Services\ReviewQueue::qs($p),
            'scope_line' => \AfricaGates\Admin\Services\ReviewQueue::scopeLine($p['scope']),
            'view_label' => $p['view'] === 'all' ? 'Everything waiting' : (\AfricaGates\Admin\Services\ReviewQueue::VIEWS[$p['view']] ?? ''),
        ];
    }

    /**
     * Which nomination the desk opens. An item still waiting is opened even when the
     * quick view would not list it — a link somebody followed must land on what it
     * named. An item already decided means "the one after it", which is where the
     * no-script flow comes back to after a decision. The end of the queue wraps to its
     * start, so anything skipped comes round again.
     *
     * @param list<int> $ids the queue, oldest first
     */
    private static function deskPick(array $ids, int $item, int $after): int
    {
        if ($item > 0) {
            if (in_array($item, $ids, true)) return $item;
            $st = DB::table('gates_nominations')->where('id', $item)->value('status');
            if ($st === 'pending') return $item;
            $after = $item;
        }
        if ($ids === []) return 0;
        if ($after > 0) {
            $ref = DB::table('gates_nominations')->where('id', $after)->first(['id', 'created_at']);
            if ($ref) {
                $order = DB::table('gates_nominations')->whereIn('id', $ids)->get(['id', 'created_at']);
                $by = [];
                foreach ($order as $o) $by[(int) $o->id] = (string) $o->created_at;
                foreach ($ids as $i) {
                    if ($by[$i] > (string) $ref->created_at || ($by[$i] === (string) $ref->created_at && $i > (int) $ref->id)) return $i;
                }
            }
        }
        return $ids[0];
    }

    /** Load a nomination joined to its programme/cycle (the review view's shape). */
    private function fullNomination(int $id): ?object
    {
        return DB::table('gates_nominations as n')
            ->leftJoin('gates_award_cycles as c', 'c.id', '=', 'n.cycle_id')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
            ->select(['n.*', 'p.title as programme_title', 'p.icon_emoji', 'c.year'])
            ->where('n.id', $id)->first();
    }

    /** Assemble the shared review context (categories, eligibility, duplicate hints, AI insight, queue position). */
    private function reviewContext(object $nom): array
    {
        $cats = DB::table('gates_award_categories')->where('cycle_id', $nom->cycle_id)->orderBy('sort_order')->get()->map(fn($r) => (array)$r)->all();
        return [
            'nom'         => (array)$nom,
            'categories'  => $cats,
            'eligibility' => $this->awards ? $this->awards->nomineeEligibility((string)$nom->nominee_name, (int)$nom->cycle_id) : null,
            'duplicates'  => \AfricaGates\Services\NominationTriageService::duplicatesFor($nom),
            // The desk's checks, with the rule behind each, on the full record too.
            'signals'     => \AfricaGates\Services\NominationSignals::forIds([(int) $nom->id])[(int) $nom->id] ?? [],
            'conflict'    => \AfricaGates\Admin\Services\ReviewConflict::of((int) ($_SESSION['admin_id'] ?? 0), $nom),
            'insight'     => \AfricaGates\Services\NominationTriageService::insight((int)$nom->id),
            'queue'       => ($nom->status === 'pending') ? \AfricaGates\Services\NominationTriageService::queuePosition((int)$nom->id) : null,
            // Would the call actually run? A configured() probe alone showed the
            // button even when a switch was off or the budget was spent.
            'ai_enabled'  => \AfricaGates\Services\AiGateway::available('nomination.triage'),
        ];
    }

    /**
     * Generate (or refresh) the AI triage insight for a nomination ON DEMAND —
     * quality score + summary + duplicate hints — so a reviewer isn't waiting on
     * the background cron. Advisory only; never decides. Redirects back to the
     * review screen with the fresh insight rendered.
     */
    public function aiInsight(Request $req, Response $res, array $args): Response
    {
        $back = $req->getServerParams()['HTTP_REFERER'] ?? ('/admin/nominations/' . (int)($args['id'] ?? 0));
        $id   = (int)($args['id'] ?? 0);
        if (!DB::table('gates_nominations')->where('id', $id)->exists()) {
            $_SESSION['flash_error'] = 'Nomination not found.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $insight = \AfricaGates\Services\NominationTriageService::generate($id);
        $_SESSION[$insight ? 'flash_ok' : 'flash_error'] = $insight
            ? 'AI insight generated.'
            : 'AI is not configured (add a Groq/Gemini/Anthropic/OpenAI key in Settings) — nothing generated.';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * AI-suggest a decision note (JSON) for the review screen. Moderators can
     * take it as-is, edit it, or ignore it and type their own. 503 when no AI
     * provider is configured so the UI can quietly hide the button.
     */
    public function suggestReason(Request $req, Response $res, array $args): Response
    {
        $json = function (array $p, int $code = 200) use ($res): Response {
            $res->getBody()->write((string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        };
        $id = (int)($args['id'] ?? 0);
        $decision = (((array)$req->getParsedBody())['decision'] ?? 'rejected') === 'approved' ? 'approved' : 'rejected';
        $nom = DB::table('gates_nominations')->where('id', $id)->first();
        if (!$nom) return $json(['ok' => false, 'error' => 'Nomination not found.'], 404);
        $reason = \AfricaGates\Services\NominationFeedbackService::suggestReason($nom, $decision);
        if ($reason === null) return $json(['ok' => false, 'error' => 'No AI provider is configured — type a note instead.'], 503);
        return $json(['ok' => true, 'reason' => $reason]);
    }

    public function review(Request $req, Response $res, array $args): Response
    {
        $nom = $this->fullNomination((int)$args['id']);
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);
        // The high-volume desk is now its own SPA page (/admin/nominations/review);
        // this route is the single-nomination review. Accuracy aids come from the
        // shared context: deterministic duplicate hints, optional AI insight and
        // the reviewer's position in the pending queue.
        return $this->view->render($res, 'admin/nominations/review.twig', [
            'page_title' => 'Review nomination — Admin',
            'admin_page' => 'review',
            // Flash renders from the Twig globals via the layout — do not shadow it.
        ] + $this->reviewContext($nom));
    }

    // ══ THE REVIEW LIST (AdminConsole v2, screenshot 02) ═════════════════════════════
    //
    // `/admin/nominations/review` is the rail's "Nominations": the list a reviewer works
    // from, with the checks on every row, quick views, facets, a side peek and a bulk bar.
    // The one-at-a-time desk it used to be moved to `/admin/nominations/desk`; an old desk
    // link still carrying `?after=` is sent there rather than dropped on the list.

    /** Only the review list itself is an acceptable `back`. */
    private static function reviewBack(string $back): ?string
    {
        // Anchored on the path itself, so `//host`, a scheme or another page never matches;
        // what follows the `?` is only ever this list's own query.
        return preg_match('~^/admin/nominations/(review|desk)(\?[^#\s]*)?$~', $back) ? $back : null;
    }

    public function queue(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        if (isset($q['after'])) {
            return $res->withHeader('Location', '/admin/nominations/desk?after=' . (int) $q['after'])->withStatus(302);
        }
        $p = \AfricaGates\Admin\Services\ReviewQueue::params($q);
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        \AfricaGates\Admin\Services\Presence::touch($adminId, 'review', $p['open'] ?: null);

        $peek = $p['open'] ? \AfricaGates\Admin\Services\ReviewQueue::peek($p['open'], $this->awards) : null;
        $others = \AfricaGates\Admin\Services\Presence::others($adminId, 'review');
        $viewing = [];
        if ($peek) foreach ($others as $o) if ($o['target_id'] === (int) $peek['id']) $viewing[] = $o['short'];

        // The peek alone, for the list's own script: J/K and a row click swap it in place
        // rather than reloading the whole list.
        if (($q['part'] ?? '') === 'peek') {
            if (!$peek) throw new \Slim\Exception\HttpNotFoundException($req);
            // Rendered without the layout, so the shell's `can_write` is not in scope: pass
            // the same answer it gives, or a writer's fragment would draw read-only.
            return $this->view->render($res, 'admin/nominations/_queue_peek.twig', [
                'shell' => ['can_write' => \AfricaGates\Admin\Support\Permissions::canWrite((string) ($_SESSION['admin_role'] ?? ''))],
                'peek' => $peek, 'viewing' => $viewing, 'back' => \AfricaGates\Admin\Services\ReviewQueue::qs($p),
                'close' => \AfricaGates\Admin\Services\ReviewQueue::qs($p, ['open' => 0]),
            ]);
        }

        $m = \AfricaGates\Admin\Services\ReviewQueue::build($p, $this->awards);
        $pos = '';
        if ($peek) {
            foreach ($m['rows'] as $i => $r) {
                if ((int) $r['id'] === (int) $peek['id']) { $pos = ($m['from'] + $i) . ' of ' . number_format($m['total']); break; }
            }
        }
        return $this->view->render($res, 'admin/nominations/queue.twig', $m + [
            'pos'        => $pos,
            'page_title' => 'Nomination review — Admin',
            'admin_page' => 'review',
            'sub_line'   => \AfricaGates\Admin\Services\ReviewQueue::subLine($p['scope']),
            'peek'       => $peek,
            'viewing'    => $viewing,
            'others'     => $others,
            'back'       => \AfricaGates\Admin\Services\ReviewQueue::qs($p),
            'close'      => \AfricaGates\Admin\Services\ReviewQueue::qs($p, ['open' => 0]),
            'pending'    => (int) DB::table('gates_nominations')->where('status', 'pending')->count(),
            'nominate_url' => \AfricaGates\Support\SiteUrl::base() . '/nominate',
        ]);
    }

    /** GET /admin/nominations/review/count — the Filter popover's live "Show N". */
    public function queueCount(Request $req, Response $res): Response
    {
        $m = \AfricaGates\Admin\Services\ReviewQueue::build(\AfricaGates\Admin\Services\ReviewQueue::params($req->getQueryParams()), $this->awards);
        $res->getBody()->write((string) json_encode(['ok' => true, 'n' => $m['total']]));
        return $res->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /admin/nominations/review/export.csv — the list as filtered, or the selected rows.
     *
     * Working columns only: who, what, where it stands and what the checks say. No email
     * address and no phone number — this is for working the queue, and a spreadsheet of
     * contact details is a different thing with a different lawful basis (Contacts).
     */
    public function queueExport(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $p = \AfricaGates\Admin\Services\ReviewQueue::params($q);
        $ids = array_values(array_filter(array_map('intval', (array) ($q['ids'] ?? []))));
        $rows = \AfricaGates\Admin\Services\ReviewQueue::exportRows($p, $this->awards, $ids);
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'nomination.export', 'nomination', null,
            ['tab' => $p['tab'], 'rows' => count($rows), 'selected' => count($ids)]);

        $fh = fopen('php://temp', 'r+');
        \AfricaGates\Support\Csv::put($fh, ['Reference', 'Nominee', 'Organisation', 'Country', 'Programme', 'Category',
            'Reads closer to', 'Nominated by', 'How they know them', 'Checks', 'Supporting links or files', 'Status', 'Received'], ',', '"', '\\');
        foreach ($rows as $r) {
            \AfricaGates\Support\Csv::put($fh, [
                (string) ($r['reference'] ?? ''), $r['name'], $r['org'], (string) ($r['country_code'] ?? ''), $r['edition'],
                $r['category'], $r['suggest']['title'] ?? '', $r['by'], $r['rel'],
                implode('; ', array_column($r['checks'], 't')), (string) $r['evidence'],
                \AfricaGates\Support\NominationStatus::label((string) $r['status']), (string) $r['created_at'],
            ], ',', '"', '\\');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        $res->getBody()->write($csv);
        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="nominations-' . $p['tab'] . '-' . date('Y-m-d') . '.csv"');
    }

    /**
     * POST /admin/nominations/review/bulk — Approve the selected nominations.
     *
     * Each one goes through {@see action()} exactly as a single Approve does — the same
     * category check, the same eligibility gate, the same emails and the same audit row —
     * so a bulk approval is N approvals and never a second, laxer way to approve. Whatever
     * one of them refuses is reported by name and the rest still go through.
     */
    public function bulk(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $back = self::reviewBack((string) ($b['back'] ?? '')) ?? '/admin/nominations/review';
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($b['ids'] ?? []))))), 0, 100);
        if (($b['do'] ?? '') !== 'approve' || $ids === []) {
            $_SESSION['flash_error'] = 'Choose at least one nomination to approve.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $done = 0; $refused = [];
        $factory = new \Slim\Psr7\Factory\ResponseFactory();
        foreach ($ids as $id) {
            $nom = DB::table('gates_nominations')->where('id', $id)->first(['id', 'nominee_name', 'status']);
            if (!$nom) continue;
            if ($nom->status !== 'pending') { $refused[] = $nom->nominee_name . ' (already ' . \AfricaGates\Support\NominationStatus::label((string) $nom->status) . ')'; continue; }
            $one = $req->withParsedBody(['_token' => $b['_token'] ?? ''])->withHeader('X-Requested-With', 'fetch');
            $r = $this->action($one, $factory->createResponse(), ['id' => $id, 'action' => 'approve']);
            $j = json_decode((string) $r->getBody(), true);
            if (!empty($j['ok'])) $done++; else $refused[] = $nom->nominee_name . ' — ' . (string) ($j['error'] ?? 'refused');
        }
        if ($done) $_SESSION['flash_ok'] = $done . ' nomination' . ($done === 1 ? '' : 's') . ' approved.';
        if ($refused) $_SESSION['flash_error'] = 'Not approved: ' . implode('; ', array_slice($refused, 0, 8)) . (count($refused) > 8 ? ' and ' . (count($refused) - 8) . ' more.' : '.');
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** POST /admin/nominations/{id}/note — a note for the team, never shown outside the console. */
    public function note(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $b = (array) $req->getParsedBody();
        $back = self::reviewBack((string) ($b['back'] ?? '')) ?? '/admin/nominations/' . $id;
        if (!DB::table('gates_nominations')->where('id', $id)->exists()) throw new \Slim\Exception\HttpNotFoundException($req);
        $saved = \AfricaGates\Admin\Services\ReviewQueue::addNote($id, (int) ($_SESSION['admin_id'] ?? 0), (string) ($b['body'] ?? ''));
        if ($saved) {
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'nomination.note', 'nomination', $id);
            $_SESSION['flash_ok'] = 'Note saved for the team.';
        } else {
            $_SESSION['flash_error'] = trim((string) ($b['body'] ?? '')) === '' ? 'Write the note first.' : 'The note could not be saved.';
        }
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** POST /admin/nominations/review/presence — the heartbeat; answers who else is here. */
    public function presence(Request $req, Response $res): Response
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $target = (int) (((array) $req->getParsedBody())['target'] ?? 0);
        \AfricaGates\Admin\Services\Presence::touch($adminId, 'review', $target ?: null);
        $others = \AfricaGates\Admin\Services\Presence::others($adminId, 'review');
        $res->getBody()->write((string) json_encode(['ok' => true, 'others' => array_map(
            static fn (array $o): array => ['short' => $o['short'], 'initials' => $o['initials'], 'target' => $o['target_id']], $others)]));
        return $res->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
