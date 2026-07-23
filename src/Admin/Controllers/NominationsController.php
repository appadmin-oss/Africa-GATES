<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

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

    public function index(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $status = (string)($p['status'] ?? 'pending');
        $q = (string)($p['q'] ?? '');
        $sort = in_array(($p['sort'] ?? ''), ['oldest', 'newest'], true) ? (string)$p['sort'] : 'newest';
        $page = max(1, (int)($p['page'] ?? 1));
        $per = 25;
        // Accountability filters: slice the queue by programme, cycle and country
        // so a reviewer can audit exactly one segment at a time.
        $programmeId = (int)($p['programme'] ?? 0);
        $cycleId     = (int)($p['cycle'] ?? 0);
        $country     = strtoupper(trim((string)($p['country'] ?? '')));

        $base = DB::table('gates_nominations as n')
            ->leftJoin('gates_award_cycles as c', 'c.id', '=', 'n.cycle_id')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
            ->select(['n.*', 'p.title as programme_title', 'c.year as cycle_year']);
        if ($status !== '' && $status !== 'all') $base->where('n.status', $status);
        if ($q !== '') $base->where(function ($x) use ($q) {
            $x->where('n.nominee_name','like',"%$q%")
              ->orWhere('n.nominator_name','like',"%$q%")
              ->orWhere('n.nominator_email','like',"%$q%");
        });
        if ($programmeId > 0) $base->where('c.programme_id', $programmeId);
        if ($cycleId > 0)     $base->where('n.cycle_id', $cycleId);
        if ($country !== '')  $base->where('n.country_code', $country);
        // Date-range filter (day/week/month presets + custom from/to) on submission date.
        $dateMeta = Filters::applyDateRange($base, 'n.created_at', $p);

        $base->orderBy('n.id', $sort === 'oldest' ? 'asc' : 'desc');
        $pg = Paginator::paginate($base, $page, $per);
        $rows = $pg['rows']; $total = $pg['total']; $pages = $pg['pages']; $page = $pg['page'];

        // Option lists for the filter dropdowns.
        $programmes = DB::table('gates_award_programmes')->orderBy('title')->get(['id', 'title'])->map(fn($r)=>(array)$r)->all();
        $cycles = DB::table('gates_award_cycles as c')->join('gates_award_programmes as p','p.id','=','c.programme_id')
            ->orderByDesc('c.year')->get(['c.id', 'c.year', 'p.title'])->map(fn($r)=>(array)$r)->all();
        $countries = DB::table('gates_nominations')->whereNotNull('country_code')->where('country_code','!=','')
            ->distinct()->orderBy('country_code')->pluck('country_code')->all();

        $filterState = ['status' => $status, 'sort' => $sort, 'q' => $q, 'programme' => $programmeId ?: '', 'cycle' => $cycleId ?: '', 'country' => $country, 'range' => $dateMeta['range'], 'from' => $dateMeta['from'], 'to' => $dateMeta['to']];
        return $this->view->render($res, 'admin/nominations/index.twig', [
            'page_title'  => 'Nominations — Admin',
            'admin_page'  => 'nominations',
            'rows'        => $rows->map(fn($r)=>(array)$r)->all(),
            'total'       => $total,
            'page'        => $page,
            'pages'       => $pages,
            'per'         => $per,
            'window'      => Filters::pageWindow($page, $pages),
            'programmes'  => $programmes,
            'cycles'      => $cycles,
            'countries'   => $countries,
            // `qs` keeps every filter (for the pager); `qs_base` drops status/sort
            // (for the status tabs, which set those themselves).
            'qs'          => Filters::qs($filterState),
            'qs_base'     => Filters::qs(['q' => $q, 'programme' => $programmeId ?: '', 'cycle' => $cycleId ?: '', 'country' => $country, 'range' => $dateMeta['range'], 'from' => $dateMeta['from'], 'to' => $dateMeta['to']]),
            'filters'     => $filterState,
            // Show the plain-English AI filter box only when a provider is set.
            'ai_enabled'  => (function () { try { return \AfricaGates\Services\AiService::boot()->configured(); } catch (\Throwable) { return false; } })(),
            'counts'      => [
                'pending'  => (int)DB::table('gates_nominations')->where('status','pending')->count(),
                'approved' => (int)DB::table('gates_nominations')->where('status','approved')->count(),
                'rejected' => (int)DB::table('gates_nominations')->where('status','rejected')->count(),
            ],
        ]);
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

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $action = $args['action'] ?? '';
        $nom = DB::table('gates_nominations')->where('id', $id)->first();
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);
        // Desk mode: after a decision, auto-advance to the next pending
        // nomination instead of bouncing back to the list. Same audited
        // process — only the redirect changes.
        $fromDesk = (((array)$req->getParsedBody())['desk'] ?? '') === '1';
        // Desk SPA posts over fetch with this header: return JSON so it can swap
        // in the next nomination instead of following a redirect.
        $wantsJson = strtolower($req->getHeaderLine('X-Requested-With')) === 'fetch';
        $deskBack  = '/admin/nominations/' . $id . ($fromDesk ? '?desk=1' : '');

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

        // Reject / pending — no category needed
        if ($action === 'reject' || $action === 'pending') {
            $status = ['reject' => 'rejected', 'pending' => 'pending'][$action];
            // Decision note to the nominator: the moderator's own words, or —
            // when they leave it blank on a rejection — an AI-drafted reason
            // (moderation AI, free backup). Collected here, shown in the email.
            $reason = mb_substr(trim((string) (((array) $req->getParsedBody())['decision_reason'] ?? '')), 0, 800);
            if ($action === 'reject' && $reason === '') {
                try { $reason = (string) (\AfricaGates\Services\NominationFeedbackService::suggestReason($nom, 'rejected') ?? ''); }
                catch (\Throwable $e) { $reason = ''; }
            }
            try {
                DB::table('gates_nominations')->where('id', $id)->update(['status' => $status, 'decision_reason' => $reason !== '' ? $reason : null]);
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

            // Email the nominator when their nomination is rejected
            if ($action === 'reject' && $this->mailer && filter_var($nom->nominator_email ?? '', FILTER_VALIDATE_EMAIL)) {
                // Escape attacker-controlled names before interpolating into HTML email.
                $nn = htmlspecialchars((string)$nom->nominee_name, ENT_QUOTES, 'UTF-8');
                $by = htmlspecialchars((string)$nom->nominator_name, ENT_QUOTES, 'UTF-8');
                $reasonBlock = $reason !== ''
                    ? '<p style="font-size:14px;line-height:1.7;color:#3a4448">' . nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')) . '</p>'
                    : '<p>This may be because the submission did not fully meet the eligibility criteria for the selected category, or because sufficient supporting detail was not provided.</p>';
                $html = <<<HTML
<p>Hi <strong>{$by}</strong>,</p>
<p>Thank you for taking the time to nominate <strong>{$nn}</strong> for the Africa GATES 2026 awards.</p>
<p>After review, our moderation team was unable to approve this nomination at this time.</p>
{$reasonBlock}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;background:#fef2f2;border-left:4px solid #fca5a5;border-radius:0 8px 8px 0;padding:12px 16px">
  <tr><td style="font-size:14px;color:#7f1d1d">
    Nominee: <strong>{$nn}</strong><br>
    Status: <strong>Not approved</strong>
  </td></tr>
</table>
<p>You are welcome to submit a revised nomination with additional supporting evidence. If you believe this decision was made in error, please reply to this email.</p>
HTML;
                $this->mailer->sendBranded(
                    $nom->nominator_email,
                    "Update on your nomination of {$nom->nominee_name}",
                    $html,
                    "Hi {$nom->nominator_name},\n\nYour nomination of {$nom->nominee_name} was not approved after review." . ($reason !== '' ? "\n\n{$reason}" : '') . "\n\nYou may resubmit with additional supporting evidence.\n\n— Africa GATES",
                    'Nominations'
                );
            }

            $msg = 'Nomination ' . $status . ($reason !== '' && $action === 'reject' ? ' — reason sent to the nominator.' : '.');
            return $ok($msg, $fromDesk ? '/admin/nominations/review?after=' . $id : '/admin/nominations?status=' . $nom->status);
        }

        // Approve flow — admin must pick a category (POST body category_id)
        if ($action !== 'approve') throw new \Slim\Exception\HttpNotFoundException($req);
        $b = (array)$req->getParsedBody();
        $catId = (int)($b['category_id'] ?? $nom->category_id ?? 0);
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
            DB::transaction(function () use ($id, $catId, $approveReason, $nom, &$nomineeId) {
                DB::table('gates_nominations')->where('id', $id)->update([
                    'status' => 'approved',
                    'category_id' => $catId,
                    'decision_reason' => $approveReason !== '' ? $approveReason : null,
                ]);

                // Normalised dedup (case-insensitive + trimmed) so "Dr. Jane Doe" and
                // "dr. jane doe " don't both become separate nominees that split votes.
                $normName = strtolower(trim((string)$nom->nominee_name));
                $nomineeId = (int)(\AfricaGates\Services\MergeService::notMerged(
                        DB::table('gates_nominees')->where('category_id', $catId)
                            ->whereRaw('LOWER(TRIM(name)) = ?', [$normName])
                    )->value('id') ?? 0);   // never re-attach to a merged-away tombstone
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
                    $nomineeId = (int)DB::table('gates_nominees')->insertGetId([
                        'category_id'  => $catId,
                        'profile_id'   => $profileId ?: null,
                        'name'         => $nom->nominee_name,
                        'tagline'      => mb_substr((string)$nom->reason, 0, 200),
                        'country_code' => $nom->country_code,
                        'status'       => 'approved',
                        'vote_count'   => 0,
                        'nominated_at' => Carbon::now()->toDateTimeString(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            return $fail($this->dbErrorFlash($e), 500);
        }

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
            $base = rtrim((string)($_ENV['APP_URL'] ?? 'https://afg.afrovanguard.org.ng'), '/');
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

        // Verified nominee → congratulatory email with a single-use gated form link.
        $this->sendNomineeForm($nom, $nomineeId);

        return $ok(
            'Nomination approved and added to "' . $cat->title . '".',
            $fromDesk ? '/admin/nominations/review?after=' . $id : '/admin/nominations?status=approved'
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
        $normName = strtolower(trim((string)$nom->nominee_name));
        $nomineeId = (int)(\AfricaGates\Services\MergeService::notMerged(
                DB::table('gates_nominees')->where('category_id', $nom->category_id)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [$normName])
            )->value('id') ?? 0);
        if ($nomineeId < 1) {
            $_SESSION['flash_error'] = 'Could not locate the created nominee record.';
            return $res->withHeader('Location', '/admin/nominations/' . $id)->withStatus(302);
        }
        if ($this->sendNomineeForm($nom, $nomineeId)) {
            $this->audit->record((int)$_SESSION['admin_id'], 'nomination.regenerate_form', 'nomination', $id);
            $_SESSION['flash_ok'] = 'A fresh single-use acceptance form link was emailed to the nominee (any previous link is now void).';
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
    private function sendNomineeForm(object $nom, int $nomineeId): bool
    {
        $email = strtolower(trim((string)($nom->nominee_email ?? '')));
        if (!$this->mailer || $nomineeId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        // Issuing the token, building the link, and sending are ALL best-effort:
        // a missing gates_form_tokens table (stale DB) or a mail failure must
        // never bubble up and 500 an approval that already committed.
        try {
            $raw  = GatedFormService::issue('nominee', $nomineeId, $email);
            $link = GatedFormService::link($raw);
            $nn   = htmlspecialchars((string)$nom->nominee_name, ENT_QUOTES, 'UTF-8');
            $html = "<p>Hi <strong>{$nn}</strong>,</p>"
                . "<p>Congratulations — your nomination has been <strong style=\"color:#15803d\">verified and approved</strong> on Africa GATES.</p>"
                . "<p>Please confirm your details using your private form below. It takes a minute and helps us present you accurately to the continent.</p>"
                . "<p style=\"text-align:center;margin:24px 0\"><a href=\"{$link}\" style=\"display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">Complete your form →</a></p>"
                . "<p style=\"font-size:12.5px;color:#6b7674\">This is a private, single-use link just for you — it works once.</p>";
            $plain = "Hi {$nom->nominee_name},\n\nCongratulations — your nomination has been verified and approved on Africa GATES.\nPlease complete your details using your private, single-use form:\n{$link}\n\n— Africa GATES";
            $this->mailer->sendBranded($email, 'Congratulations — complete your Africa GATES nominee form', $html, $plain, 'Nominations');
            return true;
        } catch (\Throwable $e) { /* token issue or mail failure — never break approval */ }
        return false;
    }

    /** Review page — preview the nomination + pick a category before approving. */
    /**
     * Review DESK — the high-volume flow: serves the oldest pending nomination
     * (or the next one after ?after=N when walking the queue) and redirects to
     * its review page in desk mode. Decisions go through the SAME audited
     * approve/reject actions — the desk changes navigation speed, never process.
     */
    public function desk(Request $req, Response $res): Response
    {
        $after = (int)($req->getQueryParams()['after'] ?? 0);
        $lite  = \AfricaGates\Services\NominationTriageService::nextPending($after ?: null);
        if (!$lite && $after > 0) {
            $_SESSION['flash_ok'] = 'You reached the end of the queue. Anything you skipped is still pending — restart the desk to pick it up.';
            return $res->withHeader('Location', '/admin/nominations?status=pending&sort=oldest')->withStatus(302);
        }
        if (!$lite) {
            $_SESSION['flash_ok'] = 'Review queue is clear — no pending nominations. 🎉';
            return $res->withHeader('Location', '/admin/nominations')->withStatus(302);
        }
        // Render the SPA desk shell with the first nomination server-side (so it
        // works with JS off too). Subsequent nominations load as fragments.
        $nom = $this->fullNomination((int)$lite->id);
        if (!$nom) {
            $_SESSION['flash_ok'] = 'Review queue is clear — no pending nominations. 🎉';
            return $res->withHeader('Location', '/admin/nominations')->withStatus(302);
        }
        return $this->view->render($res, 'admin/nominations/desk.twig', [
            'page_title' => 'Review desk — Admin',
            'admin_page' => 'nominations',
        ] + $this->reviewContext($nom));
    }

    /**
     * SPA fragment endpoint: the next pending nomination's review body as JSON
     * ({ok, done, html, id, position, total, nominee}). The desk swaps this in
     * without a full reload. Same FIFO/eligibility/duplicate logic as desk().
     */
    public function deskFragment(Request $req, Response $res): Response
    {
        $json = function (array $p, int $code = 200) use ($res): Response {
            $res->getBody()->write((string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        };
        $after = (int)($req->getQueryParams()['after'] ?? 0);
        $lite  = \AfricaGates\Services\NominationTriageService::nextPending($after ?: null);
        if (!$lite) {
            return $json([
                'ok'   => true,
                'done' => true,
                'message' => $after > 0
                    ? 'You reached the end of the queue. Anything you skipped is still pending — reopen the desk to pick it up.'
                    : 'No more pending nominations.',
            ]);
        }
        $nom = $this->fullNomination((int)$lite->id);
        if (!$nom) return $json(['ok' => true, 'done' => true, 'message' => 'No more pending nominations.']);

        $ctx  = $this->reviewContext($nom);
        $html = $this->view->fetch('admin/nominations/_review_body.twig', $ctx + ['spa' => true, 'csrf_token' => ($_SESSION['csrf_token'] ?? '')]);
        return $json([
            'ok'       => true,
            'done'     => false,
            'id'       => (int)$nom->id,
            'nominee'  => (string)$nom->nominee_name,
            'position' => $ctx['queue']['position'] ?? null,
            'total'    => $ctx['queue']['total'] ?? null,
            'html'     => $html,
        ]);
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
            'insight'     => \AfricaGates\Services\NominationTriageService::insight((int)$nom->id),
            'queue'       => ($nom->status === 'pending') ? \AfricaGates\Services\NominationTriageService::queuePosition((int)$nom->id) : null,
        ];
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
            'admin_page' => 'nominations',
            // Flash renders from the Twig globals via the layout — do not shadow it.
        ] + $this->reviewContext($nom));
    }
}
