<?php
declare(strict_types=1);
use AfricaGates\Support\Env;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use AfricaGates\Controllers\{HomeController,ApiController,RegistryController,AwardsController,LeaderboardController,LegacyController,OpportunityController,NominationController,PartnerController,VoteController,CommunityController,EventsController,BlogController,PaymentController,ShopController,ShopCheckoutController,GuideController,DonationController,PaidVoteController,PulseController,JudgesController,AccountController,GatedFormController,FormController,ActivityController,FlierController,SupportController,HelpController,ClaimController,VoteMessageController};
use AfricaGates\Judge\Controllers\{
    AuthController as JudgeAuthController,
    BallotController as JudgeBallotController
};
use AfricaGates\Judge\Middleware\JudgeAuthMiddleware;
use AfricaGates\Middleware\ApiVersionMiddleware;
use AfricaGates\Admin\Controllers\{
    AuthController as AdminAuthController,
    DashboardController as AdminDashboardController,
    ProfilesController as AdminProfilesController,
    NominationsController as AdminNominationsController,
    ModerationController as AdminModerationController,
    ProgrammesController as AdminProgrammesController,
    NomineesController as AdminNomineesController,
    LegacyController as AdminLegacyController,
    OpportunitiesController as AdminOpportunitiesController,
    EventsController as AdminEventsController,
    RegistrationsController as AdminRegistrationsController,
    DataController as AdminDataController,
    FinanceController as AdminFinanceController,
    FormsController as AdminFormsController,
    PostsController as AdminPostsController,
    PartnersController as AdminPartnersController,
    JudgesController as AdminJudgesController,
    AdminsController as AdminAdminsController,
    SettingsController as AdminSettingsController,
    AwardsPageController as AdminAwardsPageController,
    MediaController as AdminMediaController,
    ProductsController as AdminProductsController,
    ShopController as AdminShopController,
    WebhooksController as AdminWebhooksController
};
use AfricaGates\Admin\Middleware\AdminAuthMiddleware;
use AfricaGates\Admin\Middleware\SectionGuardMiddleware;
use AfricaGates\Admin\Middleware\RoleMiddleware;
use AfricaGates\Middleware\UserAuthMiddleware;

return function(App $app) {
    $app->options('/{routes:.+}', fn($req,$res)=>$res);

    /**
     * Health-check — routing works without a DB. Also: IS THE DEPLOY LIVE?
     *
     * The second question is why the extra fields are here. Production has twice been
     * running code that predates this repository — proven by planting a syntax error in
     * `Csp::policy()` on the server and still getting HTTP 200 with the old header — and
     * every CSP refusal reported since (blocked CDN scripts and stylesheets, every paid
     * vote refused by `form-action 'self'`) is that one deployment problem, not an
     * application bug. All of it is fixed in this tree; none of it was running.
     *
     * That was unanswerable from a browser, which is what made it expensive. Now:
     *
     *     curl -s https://afg.afrovanguard.org.ng/ping
     *
     * `"csp_nonce": false` — or the `rev`/`csp` fields missing entirely — means the
     * server is not loading this code, and no amount of editing it will change the
     * headers. See Support\Build. `app:doctor` does the same comparison with the actual
     * live header when you have a shell.
     *
     * `no-store`, because a cached health check answers the previous deployment's
     * question — which is the exact failure mode this endpoint exists to detect.
     */
    $app->get('/ping', function ($req, $res) {
        $res->getBody()->write((string) json_encode([
            'status' => 'ok',
            'app'    => 'Africa GATES',
            'ts'     => date('c'),
        ] + \AfricaGates\Support\Build::fingerprint()));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store');
    });

    // ── No-SSH database migration trigger (token-gated) ──────────────────────
    // For hosts without shell access (shared cPanel etc.): set a strong
    // SETUP_TOKEN in .env, visit /__setup/migrate?token=THAT once to apply all
    // schema files + pending migrations, then DELETE the token. Idempotent and
    // safe to re-run. Returns 404 when the token is unset or wrong, so the
    // endpoint is invisible to anyone without the secret. /__setup/status shows
    // which migrations are still pending (read-only).
    $setupGuard = static function ($req): bool {
        $token = trim((string) Env::get('SETUP_TOKEN', ''));
        $given = (string)($req->getQueryParams()['token'] ?? '');
        return $token !== '' && strlen($given) >= 12 && hash_equals($token, $given);
    };
    $app->get('/__setup/migrate', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);
        $tok = (string) ($req->getQueryParams()['token'] ?? '');
        $r   = \AfricaGates\Services\MigrationRunner::run();
        $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $pending = (int) ($r['pending'] ?? 0);
        $auto    = ($r['ok'] && $pending > 0);
        // Auto-advance: while steps remain, reload to apply the next small batch.
        $refresh = $auto ? '<meta http-equiv="refresh" content="1;url=/__setup/migrate?token=' . $e(rawurlencode($tok)) . '">' : '';
        [$cls, $msg] = !$r['ok'] ? ['err', 'FAILED: ' . $r['error']]
            : ($pending > 0 ? ['run', "Working… {$pending} step(s) remaining — this page is auto-continuing."]
                            : ['ok', 'DONE — setup complete. Now open /__setup/admin to set your password, then DELETE SETUP_TOKEN from your .env.']);
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . $refresh . '<title>Africa GATES — database setup</title>'
            . '<style>body{font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#10292C;color:#bcd;margin:0;padding:30px 16px}'
            . '.box{max-width:780px;margin:0 auto}h1{color:#fff;font-size:18px;margin:0 0 6px}'
            . 'pre{background:#06181a;color:#9fe6a0;padding:16px;border-radius:10px;overflow:auto;font-size:12.5px;line-height:1.5;max-height:55vh}'
            . '.ok{color:#7FC87C;font-weight:600}.err{color:#ff9a9a;font-weight:600}.run{color:#ffd479;font-weight:600}a{color:#7FC87C}</style></head><body><div class="box">'
            . '<h1>Africa GATES — database setup</h1>'
            . '<p class="' . $cls . '">' . $e($msg) . '</p>'
            . '<pre>' . $e(implode("\n", $r['lines'])) . '</pre>'
            . ($auto ? '<p>If it stops advancing on its own, just reload this page — it is safe to repeat.</p>' : '')
            . '</div></body></html>';
        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                   ->withStatus($r['ok'] ? 200 : 500);
    });
    /**
     * GET /__setup/deployed?token=… — "is the thing I just uploaded actually live?"
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     *
     * This platform is deployed by uploading a zip through cPanel File Manager and
     * pressing Extract. There is no shell, no build step and no deploy log, so when a
     * new feature does not appear there is no way to tell WHICH of these it is:
     *
     *   · the files never landed (extracted to the wrong directory, or Extract
     *     skipped existing files instead of overwriting them)
     *   · the files landed but the database migration has not been run
     *   · everything landed and the feature is switched off by a setting
     *   · everything landed and works, and the operator is looking in the wrong place
     *
     * Those four have completely different fixes and identical symptoms. Guessing
     * between them over a chat window costs more than the endpoint does.
     *
     * So this reads the actual disk and the actual database and reports facts. It never
     * writes anything. It is invisible (404) without the setup token, like every other
     * /__setup route — because a public inventory of which files are on the server is a
     * reconnaissance gift.
     *
     * ── AND IT ANSWERS "WHERE DO I LOOK" ─────────────────────────────────────
     *
     * The last section resolves the live settings into a sentence naming the page and
     * the position the message box is in right now. Its own configuration decides that
     * — free voting off moves it — which is exactly the thing an operator cannot work
     * out from the outside.
     */
    $app->get('/__setup/deployed', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);
        $root = dirname(__DIR__);
        $e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        /** A file must exist AND contain a marker, so a stale copy fails too. */
        $file = static function (string $rel, string $marker = '') use ($root): array {
            $p = $root . '/' . $rel;
            if (!is_file($p)) return [false, 'missing'];
            if ($marker === '') return [true, 'present'];
            $has = str_contains((string) file_get_contents($p), $marker);
            return [$has, $has ? 'present + current' : 'PRESENT BUT OLD (marker absent)'];
        };

        // ── GROUPED BY FEATURE, AND WHY ─────────────────────────────────────
        //
        // This page was written for one release — the vote-message and share work —
        // and then four more shipped behind it. It went on reporting "Everything is
        // in place" while checking nothing about any of them, which is the worst
        // possible answer for a page whose entire job is to be believed: the
        // operator opens the URL the upload notes point at, sees green, and still
        // has no idea whether the thing they just uploaded is live.
        //
        // Grouping by feature is what stops that happening again. Adding a release
        // now means adding a heading, and a heading with nothing under it is
        // obvious in a way that a missing row in a flat list of twelve is not.
        $groups = [
            'Comments on a vote, and sharing' => [
                ['The message box on the paid form', 'templates/pages/vote-nominee.twig', 'id="pvMsg"'],
                ['The message box on the free form', 'templates/pages/vote-nominee.twig', 'id="vnMsg"'],
                ['One message, rendered',            'templates/partials/vote-message.twig', 'vmi__act'],
                ['Cheer / Report behaviour',         'templates/partials/vote-message-assets.twig', 'vmItem'],
                ['A nominee\'s full wall',           'templates/pages/vote-messages.twig', 'vmi-list'],
                ['A message\'s own page',            'templates/pages/vote-message.twig', 'vm__quote'],
                ['The share row',                    'templates/partials/share.twig', 'ag-share--grid'],
                ['Message routes + card',            'src/Controllers/VoteMessageController.php', 'messageCard'],
                ['The message card renderer',        'src/Services/FlierService.php', 'messageCard'],
                ['Moderation queue rows',            'templates/admin/moderation/index.twig', 'vote_message'],
                ['Quantity chips (deformity fix)',   'templates/pages/vote-nominee.twig', 'vn-qty'],
                ['Poll options (deformity fix)',     'templates/partials/poll.twig', 'ag-poll__opt'],
            ],
            'The full nomination story, and every supporter' => [
                ['The story and its “read the full nomination”', 'templates/pages/vote-nominee.twig', 'vn-story'],
                ['The all-supporters page',                      'templates/pages/vote-supporters.twig', 'vsu__grid'],
                ['Sentence-aware short lines',                   'src/Support/Text.php', 'firstSentence'],
            ],
            'Profile claiming: the cooling-off period and the freeze link' => [
                ['The cooling-off rule, with code behind it', 'src/Services/ClaimGuard.php', 'payoutState'],
                ['The one-tap freeze',                       'src/Services/ClaimDispute.php', 'function freeze'],
                ['Risk signals that hold rather than refuse', 'src/Services/ClaimRisk.php', 'SHARED_CONTACT_NOMINEES'],
                ['The confirm-then-freeze page',             'templates/pages/claim-dispute.twig', 'class="cdp"'],
            ],
            'Finding a payment by the number the supporter has' => [
                ['Reference resolution',            'src/Services/PaymentLookup.php', 'function canonical'],
                ['Gateway ids captured at confirm', 'src/Services/PaymentService.php', 'gateway_ref'],
            ],
            'The support assistant, with or without an AI key' => [
                ['Model-free tool routing', 'src/Services/SupportPlan.php', 'function steps'],
                ['The queue works with no model', 'src/Services/SupportAutoResolver.php', 'SupportPlan::canAct'],
                ['The schedule takes itself over', 'src/Support/Maintenance.php', 'shouldAdopt'],
            ],
            'The Help Centre, and one door for nominations' => [
                ['A page per topic',            'templates/pages/help-category.twig', 'hcc-list'],
                ['Packed, capped topic cards',  'templates/pages/help.twig', 'hc-cats'],
                ['Both nomination doors agree', 'src/Services/NominationAftercare.php', 'function run'],
            ],
            'A chargeback can be answered before the 16 hours run out' => [
                ['The evidence flow, end to end', 'src/Services/DisputeService.php', 'function contest'],
                ['The receipt it uploads',        'src/Services/DisputeEvidence.php', 'function jpeg'],
                ['The screen with the clock',     'templates/admin/payments-disputes.twig', 'dp-clock'],
                ['Somebody is told, twice',       'src/Services/DisputeAlert.php', 'RESPOND_WITHIN_HOURS'],
            ],
            'Judging interviews on Google Meet' => [
                ['The sitting, from invite to transcript', 'src/Services/InterviewService.php', 'function publish'],
                ['Questions built from the dossier',       'src/Services/InterviewBrief.php', 'function fromRules'],
                ['The transcript read by criterion',       'src/Services/InterviewReview.php', 'function figureCheck'],
                ['The nominee\'s own consent page',        'templates/pages/interview.twig', 'ivp__consent'],
                ['The live panel console',                 'templates/admin/interviews/run.twig', 'rn-cov'],
                ['The Meet + transcript door',             'src/Services/GoogleMeetService.php', 'function createSpace'],
                ['Apps Script: calendar + transcript',     'config/AfricaGATES_AppScript.gs', 'function meetCreate'],
            ],
            'The nominee\'s own case, in their own words' => [
                ['The questionnaire, per programme', 'src/Services/QuestionnaireService.php', 'function publishEvidence'],
                ['The nominee\'s page',              'templates/pages/my-work.twig', 'mw__work'],
                ['Sending it out',                   'src/Admin/Controllers/QuestionnairesController.php', 'function inviteAll'],
                ['Answering it as a conversation',    'src/Services/QuestionnaireChat.php', 'function probeFor'],
                ['Chat, form and live progress',     'templates/pages/my-work.twig', 'mw__prog'],
                ['Questions read aloud (ElevenLabs)', 'src/Services/VoiceService.php', 'function speak'],
                ['Answering by talking',              'src/Services/QuestionnaireVoice.php', 'function hear'],
                ['What the voice notice says',        'src/Services/LegalDocument.php', 'function voiceHtml'],
            ],
            'Capturing the call live (the browser extension)' => [
                ['The token-gated live API',   'src/Services/InterviewLive.php', 'function append'],
                ['Its three endpoints',        'src/Controllers/InterviewLiveController.php', 'function say'],
                ['The panel inside the call',  'extension/content.js', 'agx__qt'],
                ['Its network worker',         'extension/worker.js', 'interview/live/'],
                ['The extension manifest',     'extension/manifest.json', 'meet.google.com'],
            ],
            'A dashboard that opens on the work, not on the totals' => [
                ['What needs a person today',   'src/Admin/Services/AttentionBoard.php', 'function probes'],
                ['A zero is never a card',      'src/Admin/Services/AttentionBoard.php', 'function items'],
                ['No door a role cannot open',  'src/Admin/Services/AttentionBoard.php', 'function forRole'],
                ['The board on the page',       'templates/admin/dashboard.twig', 'db-board'],
                ['A questionnaire to rehearse', 'src/Services/QuestionnaireService.php', 'function openTest'],
            ],
        ];

        $checks = [];
        foreach ($groups as $heading => $rows) {
            foreach ($rows as [$label, $rel, $marker]) {
                [$ok, $note] = $file($rel, $marker);
                $checks[$heading][] = [$ok, $label, $rel . ' — ' . $note];
            }
        }

        // ── the database ────────────────────────────────────────────────────
        $dbRows = [];
        try {
            // Fully qualified: routes.php has no `use` for the capsule and the rest of
            // the file spells it out too.
            $cap      = \Illuminate\Database\Capsule\Manager::class;
            $hasTable = $cap::schema()->hasTable('gates_vote_messages');
            $dbRows[] = [$hasTable, 'Table gates_vote_messages', $hasTable ? 'present' : 'MISSING — run /__setup/migrate'];
            if ($hasTable) {
                foreach (['reports', 'reported_at', 'share_token', 'cheers'] as $col) {
                    $has = $cap::schema()->hasColumn('gates_vote_messages', $col);
                    $dbRows[] = [$has, 'Column ' . $col, $has ? 'present' : 'MISSING — run /__setup/migrate'];
                }
                $dbRows[] = [true, 'Messages stored so far',
                    (string) $cap::table('gates_vote_messages')->count() . ' total, '
                    . (string) $cap::table('gates_vote_messages')->where('status', 'approved')->count() . ' approved and visible, '
                    . (string) $cap::table('gates_vote_messages')->whereIn('status', ['pending', 'quarantined'])->count() . ' waiting in /admin/moderation'];
            }

            // ── the migrations that shipped after this page was written ──────
            //
            // Each is a column a live feature reads. Missing means the files
            // landed and /__setup/migrate was skipped, which is the single most
            // common half-done upload and produces a feature that looks broken
            // rather than absent.
            foreach ([
                ['gates_nominees',   'story',             'the full “why X is nominated” text'],
                ['gates_donations',  'gateway_txn_id',    'finding a payment by its Paystack number'],
                ['gates_donations',  'gateway_ref',       'finding a payment by the gateway\'s reference'],
                ['gates_orders',     'gateway_txn_id',    'the same, for shop orders'],
                ['gates_nominee_claims', 'dispute_token',  'the “stop this claim” link'],
                ['gates_nominee_claims', 'cooling_off_until', 'the 7-day cooling-off period'],
                ['gates_interviews',     'invite_token',      'the nominee\'s own interview page'],
                ['gates_interviews',     'consent_at',        'permission to show the panel a transcript'],
                ['gates_interviews',     'live_token',        'the browser extension that captures the call'],
                ['gates_interviews',     'live_json',         'the caption buffer it writes to'],
                ['gates_nominee_submissions', 'invite_token',  'the nominee questionnaire link'],
                ['gates_programme_questions', 'criterion_id',  'filing an answer against a scoring criterion'],
                ['gates_nominee_submissions', 'chat_json',     'answering the questionnaire as a conversation'],
            ] as [$table, $col, $what]) {
                try {
                    if (!$cap::schema()->hasTable($table)) {
                        $dbRows[] = [false, $table . '.' . $col,
                            'table ' . $table . ' is MISSING — run /__setup/migrate'];
                        continue;
                    }
                    $has = $cap::schema()->hasColumn($table, $col);
                } catch (\Throwable) { $has = false; }
                $dbRows[] = [$has, $table . '.' . $col,
                    $has ? 'present — powers ' . $what
                         : 'MISSING — run /__setup/migrate, or ' . $what . ' will not work'];
            }

            // Restored stories, which is the one migration that repairs existing
            // data rather than only adding a column. Worth a number: it is the
            // difference between "the column exists" and "your nominees got their
            // text back".
            try {
                if ($cap::schema()->hasColumn('gates_nominees', 'story')) {
                    $filled = (int) $cap::table('gates_nominees')->whereNotNull('story')->where('story', '<>', '')->count();
                    $total  = (int) $cap::table('gates_nominees')->count();
                    $dbRows[] = [true, 'Nominees with their full story',
                        $filled . ' of ' . $total . ($filled === 0 && $total > 0
                            ? ' — none yet. The migration only restores a story where one nomination clearly matches the nominee; new nominations fill it from now on.'
                            : '')];
                }
            } catch (\Throwable) {}
        } catch (\Throwable $ex) {
            $dbRows[] = [false, 'Database', 'could not be read: ' . $ex->getMessage()];
        }

        // ── where the box is, given THIS site's settings ────────────────────
        $paid = \AfricaGates\Services\PaidVoteService::enabled();
        $free = !\AfricaGates\Services\PaidVoteService::freeVotingDisabled();
        $where = [];
        if ($paid) {
            $where[] = 'On the <strong>contribution panel</strong>, directly under “Your name (optional)” '
                     . 'and above the pay button: “<strong>Say something about …</strong>”. '
                     . 'It is a plain textarea — always visible, nothing to expand.';
        }
        if ($free) {
            $where[] = 'On the <strong>free vote form</strong>, under the email field: a green line reading '
                     . '“<strong>Say something about …</strong>” which opens the box when tapped.';
        }
        if (!$paid && !$free) {
            $where[] = '<strong>Nowhere — voting is closed or unconfigured on this ballot</strong>, so neither '
                     . 'form renders. The box appears with the form it belongs to.';
        }
        $where[] = 'A message only appears on the ballot <em>after</em> it is approved, so a nominee with no '
                 . 'messages yet shows no “What voters are saying” panel at all — that is deliberate, not a fault.';

        $row = static function (array $r) use ($e): string {
            [$ok, $label, $note] = $r;
            return '<tr><td>' . ($ok ? '<span class="ok">✓</span>' : '<span class="err">✗</span>')
                 . '</td><td>' . $e($label) . '</td><td class="n">' . $e($note) . '</td></tr>';
        };

        // $checks is now grouped by feature, so flatten for the verdict.
        $flatChecks = [];
        foreach ($checks as $rows) foreach ($rows as $r) $flatChecks[] = $r;
        $allOk = !in_array(false, array_column(array_merge($flatChecks, $dbRows), 0), true);

        // One table per feature, each headed by what it is FOR rather than by a
        // release number an operator has no way to recognise.
        $groupsHtml = '';
        foreach ($checks as $heading => $rows) {
            $bad = in_array(false, array_column($rows, 0), true);
            $groupsHtml .= '<h2>' . $e($heading)
                . ($bad ? ' <span class="err">— something is missing</span>' : '')
                . '</h2><table>' . implode('', array_map($row, $rows)) . '</table>';
        }

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Africa GATES — what is deployed</title>'
            . '<style>body{font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#10292C;color:#bcd;margin:0;padding:30px 16px;line-height:1.6}'
            . '.box{max-width:860px;margin:0 auto}h1{color:#fff;font-size:19px;margin:0 0 4px}h2{color:#fff;font-size:15px;margin:26px 0 8px}'
            . 'table{width:100%;border-collapse:collapse;font-size:13.5px}td{padding:6px 8px;border-top:1px solid rgba(255,255,255,.08);vertical-align:top}'
            . 'td:first-child{width:22px}.n{color:#8fa8a4;font-size:12.5px}'
            . '.ok{color:#7FC87C;font-weight:700}.err{color:#ff9a9a;font-weight:700}'
            . '.verdict{margin:14px 0 0;padding:12px 14px;border-radius:10px;font-weight:600}'
            . '.good{background:rgba(127,200,124,.14);color:#9ee89b}.bad{background:rgba(255,154,154,.14);color:#ffbcbc}'
            . 'ul{padding-left:20px}li{margin:6px 0}a{color:#7FC87C}code{background:#06181a;padding:2px 5px;border-radius:4px;font-size:12.5px}</style>'
            . '</head><body><div class="box"><h1>What is deployed</h1>'
            . '<p class="n">Read-only. Nothing on this page changes anything.</p>'
            . '<div class="verdict ' . ($allOk ? 'good' : 'bad') . '">'
            . ($allOk
                ? 'Everything is in place. If you still cannot see the box, read “Where to look” below — '
                  . 'and if your host runs a page cache (LiteSpeed Cache on cPanel does), purge it.'
                : 'Something is missing — every row marked ✗ below tells you which fix applies.')
            . '</div>'
            . $groupsHtml
            . '<h2>Database</h2><table>' . implode('', array_map($row, $dbRows)) . '</table>'
            . '<h2>Where to look, on THIS site\'s current settings</h2>'
            . '<p class="n">Paid voting: <strong>' . ($paid ? 'on' : 'off') . '</strong> · '
            . 'Free voting: <strong>' . ($free ? 'on' : 'off') . '</strong></p>'
            . '<ul><li>' . implode('</li><li>', $where) . '</li></ul>'
            . '<h2>If a row above says PRESENT BUT OLD</h2>'
            . '<p>The file is there but it is the previous version — the upload did not overwrite it. '
            . 'Re-extract with overwrite enabled, or delete that file first and upload it again.</p>'
            . '</div></body></html>';

        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    /**
     * GET /__setup/payments?token=… — "why is a transaction's status wrong, or missing?"
     *
     * ── THE REPORT THIS ANSWERS ──────────────────────────────────────────────
     *
     * "The status of the transactions on our site is either incorrect or the
     * transaction does not exist at all." Those are two different faults with two
     * different fixes, and from the outside they look identical:
     *
     *   STATUS WRONG   we have the row, and it says pending (or failed) for a payment
     *                  that actually succeeded. Cause: nothing ever re-checked it. The
     *                  browser callback is lost whenever somebody pays inside a wallet
     *                  app and never returns, the webhook can be unconfigured or have
     *                  been failing, and the sweep that exists to catch both only runs
     *                  from the maintenance schedule. If that schedule is not running,
     *                  every dropped payment stays wrong for ever.
     *
     *   NO ROW AT ALL  money at Paystack that this platform never recorded. Nothing
     *                  that starts from our own tables can find it — there is nothing
     *                  to iterate. It happens when a payment is taken outside the
     *                  checkout this code owns: a Paystack Payment Page or payment
     *                  link, a dedicated virtual account, a POS terminal, or an insert
     *                  that failed after the charge.
     *
     * {@see \AfricaGates\Services\GatewayLedger} already answers the second one by
     * walking the GATEWAY's list and asking our database about each transaction — the
     * only direction of the comparison that can find a stranger. It has been reachable
     * at /admin/payments/ledger. This page adds the local half (which decides whether
     * the answer is "stale" or "missing" before any network call), and puts both behind
     * the setup token so the question can be answered on a site where nobody can get
     * into the admin console.
     *
     * READ-ONLY, including the pull. Confirming an order, minting votes or refunding a
     * stranger's charge are decisions with money attached and they keep their own
     * guarded paths. A page that reconciled and repaired in one motion would make "let
     * me just look" a money-moving action.
     */
    $app->get('/__setup/payments', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);
        $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $q   = $req->getQueryParams();
        $cap = \Illuminate\Database\Capsule\Manager::class;
        $ngn = static fn($n) => '₦' . number_format((float) $n);

        // ── our own books ───────────────────────────────────────────────────
        $local = [];
        try {
            $byStatus = $cap::table('gates_donations')
                ->select('status', $cap::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status')->all();
            $local[] = [true, 'Payments on record',
                implode(' · ', array_map(
                    static fn($k, $v) => $v . ' ' . $k,
                    array_keys($byStatus), array_values($byStatus)
                )) ?: 'none at all'];

            // The stale bucket, and its AGE. A pending row minutes old is a checkout in
            // progress; one from last week is a payment nobody ever re-checked.
            $pending = (int) ($byStatus['pending'] ?? 0);
            $oldest  = $cap::table('gates_donations')->where('status', 'pending')->min('created_at');
            $hours   = null;
            if (is_string($oldest) && $oldest !== '') {
                // (int), because Carbon 3's diffInHours returns a float and "144.00020017861h
                // old" reads like a bug in the page rather than a fact about a payment.
                try { $hours = (int) \Illuminate\Support\Carbon::parse($oldest)->diffInHours(\Illuminate\Support\Carbon::now()); }
                catch (\Throwable) {}
            }
            $local[] = [$pending === 0 || ($hours !== null && $hours < 24), 'Stuck as pending',
                $pending === 0 ? 'none'
                    : $pending . ' pending, oldest ' . ($hours === null ? 'unknown age' : $hours . 'h old')
                      . ($hours !== null && $hours >= 24
                          ? ' — anything older than a day was never re-checked. That is the "status is wrong" fault.'
                          : ' — normal; a fresh pending row is a checkout in progress.')];

            // The worst bucket on the platform: money settled, votes never credited.
            $owed = (int) $cap::table('gates_donations')
                ->where('status', 'confirmed')->where('tier', 'paid-vote')
                ->whereNull('refunded_at')->where('votes_used', 0)
                ->whereNotNull('intent_nominee_id')->count();
            $local[] = [$owed === 0, 'Paid but no votes credited',
                $owed === 0 ? 'none' : $owed . ' order(s) — confirmed, not minted. Run the sweep below.'];
        } catch (\Throwable $ex) {
            $local[] = [false, 'Our books', 'could not be read: ' . $ex->getMessage()];
        }

        // ── the thing that fixes a wrong status, and whether it runs ────────
        $last  = \AfricaGates\Support\CronHealth::lastRunAt();
        $stale = \AfricaGates\Support\CronHealth::isStale();
        $local[] = [$last !== null && !$stale, 'The re-check sweep',
            $last === null
                ? 'HAS NEVER RUN. Nothing has ever re-checked a dropped payment, which is the '
                . 'single most likely reason a status is wrong. See /__setup/assistant.'
                : ('last ran ' . $last->diffForHumans() . ($stale ? ' — OVERDUE' : ' — healthy'))];

        $paystackOn = false;
        try { $paystackOn = (new \AfricaGates\Services\PaymentService())->isEnabled('paystack'); } catch (\Throwable) {}
        $local[] = [$paystackOn, 'Paystack configured here',
            $paystackOn ? 'yes' : 'NO — without a secret key nothing can be compared against the gateway'];

        // ── the gateway's books, on request ─────────────────────────────────
        $days = max(1, min(\AfricaGates\Services\GatewayLedger::MAX_DAYS, (int) ($q['days'] ?? 14)));
        $pull = null;
        if (!empty($q['pull']) && $paystackOn) {
            try { $pull = (new \AfricaGates\Services\GatewayLedger())->pull($days); }
            catch (\Throwable $ex) { $pull = ['ok' => false, 'message' => $ex->getMessage()]; }
        }

        $row = static function (array $r) use ($e): string {
            [$ok, $label, $note] = $r;
            return '<tr><td>' . ($ok ? '<span class="ok">✓</span>' : '<span class="err">✗</span>')
                 . '</td><td>' . $e($label) . '</td><td class="n">' . $e($note) . '</td></tr>';
        };

        $pullHtml = '';
        if ($pull === null) {
            $pullHtml = '<p class="n">Add <code>&amp;pull=1</code> (optionally <code>&amp;days=30</code>) to compare '
                      . 'against Paystack\'s own list of successful transactions. That is the only check that can '
                      . 'find money taken with no record here. It reads both sides and writes nothing.</p>';
        } elseif (empty($pull['ok'])) {
            $pullHtml = '<p class="verdict bad">Paystack would not answer: ' . $e($pull['message'] ?? 'unknown error') . '</p>';
        } else {
            $c = $pull['counts']; $n = $pull['naira'];
            $buckets = [
                ['agreed',   'Both sides agree',                 'nothing to do'],
                ['mismatch', 'WE HAVE IT, AND WE DISAGREE',      'this is the "status is wrong" bucket'],
                ['theirs',   'AT PAYSTACK, NO RECORD HERE',      'this is the "transaction does not exist" bucket'],
                ['ours',     'Confirmed here, not in their list','confirmed by hand, or outside this window'],
            ];
            $rows = '';
            foreach ($buckets as [$k, $label, $why]) {
                $bad = ($k === 'mismatch' || $k === 'theirs') && (int) ($c[$k] ?? 0) > 0;
                $rows .= $row([!$bad, $label,
                    (int) ($c[$k] ?? 0) . ' transaction(s), ' . $ngn($n[$k] ?? 0) . ' — ' . $why]);
            }
            $pullHtml = '<p class="n">Paystack reported <strong>' . (int) ($c['gateway'] ?? 0)
                . '</strong> successful transactions worth ' . $e($ngn($n['gateway'] ?? 0))
                . ' between ' . $e($pull['from']) . ' and ' . $e($pull['to'])
                . (!empty($pull['truncated']) ? ' <strong>(TRUNCATED — narrow the window with &amp;days=)</strong>' : '')
                . '</p><table>' . $rows . '</table>';

            // Name the actual references. A count tells somebody there is a problem; a
            // reference is the thing they can take to the Paystack dashboard.
            foreach (['theirs' => 'Money at Paystack with no record here',
                      'mismatch' => 'On record here, but we disagree with Paystack'] as $k => $heading) {
                $items = array_slice($pull['groups'][$k] ?? [], 0, 40);
                if ($items === []) continue;
                $li = '';
                foreach ($items as $it) {
                    $g = $it['gateway'] ?? [];
                    $why = isset($it['why']) ? ' — ' . implode('; ', (array) $it['why']) : '';
                    // The PAYER and the CHANNEL, not just the amount. Whoever reads this
                    // can only do two things about an orphan — contact the payer or refund
                    // them — and both need the address. The channel is the diagnosis:
                    // `dedicated_nuban` is a virtual-account transfer, `card` from a
                    // payment page, and either way it names the door the money came
                    // through, which is the thing to close.
                    $who = trim((string) ($g['name'] ?? '')) ;
                    $addr = trim((string) ($g['email'] ?? ($it['local']['email'] ?? '')));
                    $chan = trim((string) ($g['channel'] ?? ''));
                    $li .= '<li><code>' . $e($g['reference'] ?? ($it['local']['reference'] ?? '?')) . '</code> · '
                         . $e($ngn($g['amount'] ?? ($it['local']['amount'] ?? 0))) . ' · '
                         . $e($g['paid_at'] ?? ($it['local']['created_at'] ?? ''))
                         . ($addr !== '' ? ' · ' . $e($addr) : '')
                         . ($who !== '' ? ' (' . $e($who) . ')' : '')
                         . ($chan !== '' ? ' · via <code>' . $e($chan) . '</code>' : '')
                         . $e($why) . '</li>';
                }
                $pullHtml .= '<h2>' . $e($heading) . '</h2><ul>' . $li . '</ul>';
            }
        }

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Africa GATES — payments</title>'
            . '<style>body{font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#10292C;color:#bcd;margin:0;padding:30px 16px;line-height:1.6}'
            . '.box{max-width:900px;margin:0 auto}h1{color:#fff;font-size:19px;margin:0 0 4px}h2{color:#fff;font-size:15px;margin:26px 0 8px}'
            . 'table{width:100%;border-collapse:collapse;font-size:13.5px}td{padding:6px 8px;border-top:1px solid rgba(255,255,255,.08);vertical-align:top}'
            . 'td:first-child{width:22px}.n{color:#8fa8a4;font-size:12.5px}'
            . '.ok{color:#7FC87C;font-weight:700}.err{color:#ff9a9a;font-weight:700}'
            . '.verdict{margin:14px 0 0;padding:12px 14px;border-radius:10px;font-weight:600}'
            . '.bad{background:rgba(255,154,154,.14);color:#ffbcbc}'
            . 'ul{padding-left:20px}li{margin:5px 0;font-size:13px}a{color:#7FC87C}'
            . 'code{background:#06181a;padding:2px 5px;border-radius:4px;font-size:12.5px}</style>'
            . '</head><body><div class="box"><h1>Payments — ours against Paystack\'s</h1>'
            . '<p class="n">Read-only. Nothing on this page changes anything, including the comparison.</p>'
            . '<h2>Our own books</h2><table>' . implode('', array_map($row, $local)) . '</table>'
            . '<h2>Paystack\'s books</h2>' . $pullHtml
            . '<h2>What to do with each answer</h2><ul>'
            . '<li><strong>Stuck pending, older than a day</strong> — the re-check sweep is not running. '
            . 'Open <code>/__setup/assistant</code>; it says whether scheduled work happens at all. Once it '
            . 'runs, it works through the backlog by itself.</li>'
            . '<li><strong>Paid but no votes credited</strong> — the same sweep credits these. '
            . '<code>/admin/payments</code> can also repair one order at a time.</li>'
            . '<li><strong>At Paystack, no record here</strong> — the payment was taken outside this site\'s '
            . 'checkout. The <code>via</code> label on each row says which door: <code>dedicated_nuban</code> '
            . 'is a transfer to a virtual account, <code>card</code> is usually a Paystack Payment Page or '
            . 'payment link. <strong>Nothing in this platform can adopt one of these.</strong> A paid vote '
            . 'needs to know WHICH NOMINEE it is for, and a charge taken outside the ballot never carried '
            . 'that — so there is no correct order to create, only a guess. Your two real options are to '
            . 'refund it in the Paystack dashboard, or to email the payer (address shown above), ask who they '
            . 'meant to support, and have them vote through the ballot. Then close the door: stop sharing that '
            . 'payment link, because every charge through it arrives here as one of these.</li>'
            . '<li><strong>We disagree with Paystack</strong> — the amount or status differs. Never repair these '
            . 'in bulk; each one is a person and a number.</li>'
            . '</ul>'
            . '</div></body></html>';

        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    /**
     * GET /__setup/assistant?token=… — "is the support assistant actually working?"
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     *
     * "The assistant is not working" has at least six causes with one symptom, and
     * from the outside they are indistinguishable:
     *
     *   · no AI provider key is configured anywhere
     *   · a key is configured but expired, or its free quota is spent
     *   · a key works but the circuit breaker is open after repeated failures
     *   · everything works and the widget is being looked for on the wrong page
     *   · the tools work and the model does not, so answers arrive but read flatly
     *   · the unattended ticket queue is not being swept because cron is not running
     *
     * There is no shell on this host, so none of that can be checked from a log.
     * This reads the live configuration and reports facts.
     *
     * ── AND IT DEMONSTRATES THE ROUTING, RATHER THAN DESCRIBING IT ────────────
     *
     * The last section runs {@see \AfricaGates\Services\SupportPlan} against real
     * example questions and prints which tools each one would call. That is the
     * part an operator cannot work out from the outside, and it is also the fastest
     * way to see that the assistant does useful work with no AI key at all: the
     * repair tools are deterministic code and the plan names them either way.
     *
     * Read-only by default. `&ping=1` makes ONE live model call, because "is the
     * key still valid" cannot be answered without trying it — and it is opt-in
     * because a link can be prefetched, retried and bookmarked, and each of those
     * would otherwise spend quota.
     */
    $app->get('/__setup/assistant', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $q = $req->getQueryParams();

        $ai     = \AfricaGates\Services\AiService::boot();
        $status = $ai->status();
        $agent  = new \AfricaGates\Services\SupportAgentService($ai);
        $hasAi  = $agent->available();

        // ── the model half ──────────────────────────────────────────────────
        $model = [];
        foreach (['groq', 'gemini', 'anthropic', 'openai'] as $p) {
            $on = (bool) ($status[$p] ?? false);
            // Never the key itself, not even partially — this page is reachable by
            // anybody holding the setup token, and a key is a bearer credential.
            $model[] = [$on, ucfirst($p) . ' key', $on ? 'configured' : 'not configured'];
        }
        $model[] = [$hasAi, 'A model can plan and phrase',
            $hasAi ? 'yes — ' . ($status['active'] ?? '?') . ', model ' . ($status['model'] ?? '?')
                   : 'NO — answers will be composed from the tools\' own words instead (see below)'];

        foreach (['groq', 'gemini', 'anthropic', 'openai'] as $p) {
            if (!($status[$p] ?? false)) continue;
            $open = \AfricaGates\Support\ProviderBreaker::isOpen($p);
            $model[] = [!$open, 'Circuit breaker: ' . $p,
                $open ? 'OPEN — recent calls failed, so this provider is being skipped. It closes by '
                      . 'itself; a permanently open one means the key is wrong or the quota is spent.'
                      : 'closed (healthy)'];
        }

        if (!empty($q['ping']) && $hasAi) {
            $t0 = microtime(true);
            $out = $ai->complete('Reply with the single word OK.', 'ping', 8);
            $ms  = (int) round((microtime(true) - $t0) * 1000);
            $model[] = [$out !== null, 'Live call to the provider',
                $out !== null ? 'answered in ' . $ms . 'ms — the key is valid'
                              : 'NO ANSWER after ' . $ms . 'ms — the key is rejected, out of quota, or blocked '
                              . 'outbound by the host firewall'];
        } elseif (!empty($q['ping'])) {
            $model[] = [false, 'Live call to the provider', 'skipped — no key to test'];
        }

        // ── the half that needs no model ────────────────────────────────────
        $ctx  = new \AfricaGates\Services\SupportContext(null, null);
        $work = [];
        try {
            $tools  = $ctx->tools();
            $work[] = [$tools !== [], 'Tools a visitor can reach', (string) count($tools) . ' available'];
        } catch (\Throwable $ex) {
            $tools  = [];
            $work[] = [false, 'Tools', 'could not be listed: ' . $ex->getMessage()];
        }

        $cap = \Illuminate\Database\Capsule\Manager::class;
        foreach ([
            ['gates_support_tickets', 'Tickets table'],
            ['gates_support_messages', 'Ticket replies table'],
        ] as [$table, $label]) {
            try { $has = $cap::schema()->hasTable($table); } catch (\Throwable) { $has = false; }
            $work[] = [$has, $label, $has ? 'present' : 'MISSING — run /__setup/migrate'];
        }

        // The reference columns the assistant now looks payments up by.
        foreach (['gateway_txn_id', 'gateway_ref'] as $col) {
            try { $has = $cap::schema()->hasColumn('gates_donations', $col); } catch (\Throwable) { $has = false; }
            $work[] = [$has, 'Column gates_donations.' . $col,
                $has ? 'present — Paystack receipt numbers are searchable'
                     : 'MISSING — run /__setup/migrate, or supporters quoting a Paystack number cannot be found'];
        }

        $resolver = new \AfricaGates\Services\SupportAutoResolver(
            $agent, new \AfricaGates\Services\SupportTicketService()
        );
        $work[] = [$resolver->available(), 'Unattended ticket queue',
            $resolver->available()
                ? 'enabled — it repairs payment tickets by itself, with or without a model'
                : 'unavailable'];

        try {
            $open = (int) $cap::table('gates_support_tickets')->where('status', 'open')->count();
            $answered = (int) $cap::table('gates_support_messages')
                ->where('author_type', 'agent')->count();
            $work[] = [true, 'Open tickets right now', (string) $open
                . ' open, ' . $answered . ' replies posted by the assistant so far'];
        } catch (\Throwable) {}

        // ── IS ANY OF IT ACTUALLY RUNNING? ──────────────────────────────────
        //
        // The single most consequential question on this page, and it used to be
        // answered with an instruction ("confirm the cron job is running") rather
        // than a fact — while CronHealth, which exists precisely because a stalled
        // schedule has no symptom, could read the answer in one indexed row.
        //
        // Everything automatic lives in the maintenance run: reconciliation
        // confirming payments whose callback was dropped, the refund sweep, and the
        // assistant working the ticket queue. If it stopped, nothing about the site
        // looks wrong — supporters owed money are simply not paid.
        //
        // Kept in its OWN list rather than appended to $work, because the two answer
        // different questions and the verdict is computed from $work. Folding a
        // missing cron job in there made the headline read "Something the assistant
        // needs is missing", which sends an operator to check database columns when
        // the columns are fine and the scheduler is the thing to fix.
        $sched = [];
        $last  = \AfricaGates\Support\CronHealth::lastRunAt();
        $hours = \AfricaGates\Support\CronHealth::hoursSinceLastRun();
        $stale = \AfricaGates\Support\CronHealth::isStale();
        $auto  = \AfricaGates\Support\Maintenance::autoEnabled();

        if ($last === null) {
            $sched[] = [false, 'Scheduled work',
                'HAS NEVER RUN. Nothing automatic is happening: payments whose callback was '
                . 'dropped are not being confirmed, refunds are not being sent, and tickets are '
                . 'not being worked. Add a cPanel cron job for `php bin/console maintenance:run`, '
                . 'or leave it — the site will start running it from ordinary web traffic by '
                . 'itself once it is sure nothing else is.'];
        } else {
            $ago = $hours === null ? '?'
                 : ($hours < 1 ? (string) (int) round($hours * 60) . ' minutes ago'
                              : number_format($hours, 1) . ' hours ago');
            $sched[] = [!$stale, 'Scheduled work',
                'last completed ' . $ago . ' (' . $last->toDateTimeString() . ')'
                . ($stale
                    ? ' — OVERDUE. More than ' . \AfricaGates\Support\CronHealth::STALE_HOURS
                      . ' hours means work has provably been missed, not merely delayed.'
                    : ' — healthy.')];
        }
        $adopting = \AfricaGates\Support\Maintenance::shouldAdopt();
        $sched[] = [true, 'Runs from web traffic',
            $auto      ? 'on — maintenance runs after a page response is flushed, so no visitor '
                       . 'waits for it. Set webcron_auto=off in Admin → Settings to stop it.'
          : ($adopting ? 'about to switch itself on — nothing else is running the schedule, so the '
                       . 'next page view on this site will take it over. Nothing to do.'
                       : 'off — a real cron job is expected to do this. It switches itself on if '
                       . 'the schedule is ever found to have stopped.')];

        // ── show the routing, do not describe it ────────────────────────────
        $demo = [];
        foreach ([
            'I paid but my votes have not appeared, ref AFG-PVOTE-957ef35ed73d',
            'my debit alert says 4738291042 and nothing came',
            'I voted for my sister and it is not showing',
            'my payment failed and the card was declined',
            'my verification code never arrived at ada@gmial.com',
            'why can I not pay when voting is still open?',
            'how much is one vote?',
        ] as $sample) {
            try {
                $names = array_column(\AfricaGates\Services\SupportPlan::steps($sample, $ctx), 'tool');
            } catch (\Throwable $ex) {
                $names = ['(failed: ' . $ex->getMessage() . ')'];
            }
            $demo[] = [$names !== [], $sample, $names ? implode(' → ', $names) : 'nothing — would answer from the Help Centre'];
        }

        $row = static function (array $r) use ($e): string {
            [$ok, $label, $note] = $r;
            return '<tr><td>' . ($ok ? '<span class="ok">✓</span>' : '<span class="err">✗</span>')
                 . '</td><td>' . $e($label) . '</td><td class="n">' . $e($note) . '</td></tr>';
        };

        $blocked   = in_array(false, array_column($work, 0), true);
        $schedBad  = in_array(false, array_column($sched, 0), true);
        // Said as its own sentence rather than mixed into the assistant's verdict:
        // an unrun scheduler does not stop the assistant answering, it stops the
        // platform paying people, and the two need different reactions.
        $schedNote = $schedBad
            ? ' <br><br>Separately, and more urgently: the scheduled work below is not running, '
            . 'which is what confirms dropped payments and sends refunds.'
            : '';

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Africa GATES — support assistant</title>'
            . '<style>body{font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#10292C;color:#bcd;margin:0;padding:30px 16px;line-height:1.6}'
            . '.box{max-width:900px;margin:0 auto}h1{color:#fff;font-size:19px;margin:0 0 4px}h2{color:#fff;font-size:15px;margin:26px 0 8px}'
            . 'table{width:100%;border-collapse:collapse;font-size:13.5px}td{padding:6px 8px;border-top:1px solid rgba(255,255,255,.08);vertical-align:top}'
            . 'td:first-child{width:22px}.n{color:#8fa8a4;font-size:12.5px}'
            . '.ok{color:#7FC87C;font-weight:700}.err{color:#ff9a9a;font-weight:700}'
            . '.verdict{margin:14px 0 0;padding:12px 14px;border-radius:10px;font-weight:600}'
            . '.good{background:rgba(127,200,124,.14);color:#9ee89b}.warn{background:rgba(255,206,120,.14);color:#ffdda1}'
            . '.bad{background:rgba(255,154,154,.14);color:#ffbcbc}'
            . 'a{color:#7FC87C}code{background:#06181a;padding:2px 5px;border-radius:4px;font-size:12.5px}</style>'
            . '</head><body><div class="box"><h1>Support assistant</h1>'
            . '<p class="n">Read-only. Add <code>&amp;ping=1</code> to make one live call to the AI provider.</p>'
            . '<div class="verdict ' . ($blocked ? 'bad' : ($hasAi ? 'good' : 'warn')) . '">'
            . ($blocked
                ? 'Something the assistant needs is missing — every row marked ✗ under “The work” says which fix applies.'
                : ($hasAi
                    ? 'Fully operational: a model plans and phrases, and the tools do the work.'
                    : 'Operational without an AI key. It reads payments, repairs them, resends receipts and opens '
                    . 'tickets — all of that is ordinary code. What is missing is only the conversational phrasing, '
                    . 'so answers are assembled from the tools\' own sentences. Paste a Groq or Gemini key in '
                    . 'Admin → Settings → AI to add it; both have a free tier.'))
            . $schedNote
            . '</div>'
            . '<h2>The model (planning and phrasing)</h2><table>' . implode('', array_map($row, $model)) . '</table>'
            . '<h2>The work (looking things up and fixing them)</h2><table>' . implode('', array_map($row, $work)) . '</table>'
            . '<h2>Is any of it actually running?</h2>'
            . '<p class="n">Everything automatic — confirming payments whose gateway callback was '
            . 'dropped, sending refunds, working the ticket queue — happens in the maintenance run, '
            . 'never on a page view. If it stops, nothing about the site looks wrong.</p>'
            . '<table>' . implode('', array_map($row, $sched)) . '</table>'
            . '<h2>What it would do with real questions, right now</h2>'
            . '<p class="n">Chosen by rules, with no model involved — so this is exactly what happens when the '
            . 'provider is down as well.</p><table>' . implode('', array_map($row, $demo)) . '</table>'
            . '</div></body></html>';

        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    /**
     * GET /__setup/assets — build the CSS bundle without a shell.
     *
     * Same reason /__setup/migrate exists: this deploys to shared cPanel where there is
     * often no SSH, and a build step that cannot be run on the host will not be run. The
     * cost of skipping it is fifteen render-blocking stylesheets instead of one (~2.4s of
     * blocking requests on a mid-range Android), so it needs to be reachable from a
     * browser. Token-gated and 404 without the token, exactly like the migrate route.
     *
     * Writes only into public/assets/dist/. Safe to re-run: the bundle is content-hashed,
     * so an unchanged rebuild produces the same filename and every cached copy stays
     * valid.
     */
    /**
     * GET /__setup/legal — install the terms and privacy policy without a shell.
     *
     * Same reason /__setup/migrate and /__setup/assets exist: this deploys to shared
     * cPanel where there is often no SSH, and `php bin/console legal:seed` is not a
     * thing an operator can run there. A step that cannot be run on the host will not
     * be run, so it has to be reachable from a browser.
     *
     * ── WHY IT SKIPS BY DEFAULT, AND WHY THAT MATTERS MORE HERE ─────────────
     *
     * A document that already exists is kept unless `&force=1`. On the CLI that is
     * good manners; on a URL it is a safety requirement — a link can be opened by
     * accident, bookmarked, retried on a flaky connection, or prefetched by a
     * browser, and any of those silently reverting a policy an administrator had
     * revised would undo legal review with nothing to show what happened.
     *
     * Token-gated and 404 without the token, exactly like the other setup routes.
     */
    $app->get('/__setup/legal', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);

        $q     = $req->getQueryParams();
        $force = ($q['force'] ?? '') === '1';
        $only  = strtolower(trim((string) ($q['only'] ?? ''))) ?: null;

        $r  = \AfricaGates\Services\LegalSeeder::install($force, $only);
        $e  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $ok = $r['failed'] === [];

        $lines = [];
        foreach ($r['written'] as $slug) {
            $body = \AfricaGates\Services\LegalSeeder::documents()[$slug]['body'];
            $lines[] = '+ ' . $slug . ' installed — ' . number_format(strlen($body))
                     . ' bytes, ' . substr_count($body, '<h2') . ' sections';
        }
        foreach ($r['kept'] as $slug) {
            $lines[] = '= ' . $slug . ' already exists — KEPT (add &force=1 to replace it)';
        }
        foreach ($r['failed'] as $slug => $msg) {
            $lines[] = '! ' . $slug . ' FAILED — ' . $msg;
        }
        if ($lines === []) $lines[] = 'Nothing matched — check the &only= value.';

        $tok = (string) ($q['token'] ?? '');
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Africa GATES — legal documents</title>'
            . '<style>body{font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#10292C;color:#bcd;margin:0;padding:30px 16px}'
            . '.box{max-width:780px;margin:0 auto}h1{color:#fff;font-size:18px;margin:0 0 6px}'
            . 'pre{background:#06181a;color:#9fe6a0;padding:16px;border-radius:10px;overflow:auto;font-size:12.5px;line-height:1.6}'
            . 'a{color:#7FC87C}.ok{color:#7FC87C;font-weight:600}.err{color:#ff9a9a;font-weight:600}'
            . '.warn{background:#3a2f12;color:#f0d9a0;padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.6}'
            . '</style></head><body><div class="box">'
            . '<h1>Africa GATES — legal documents</h1>'
            . '<p class="' . ($ok ? 'ok' : 'err') . '">' . ($ok ? 'DONE' : 'FAILED') . '</p>'
            . '<pre>' . $e(implode("\n", $lines)) . '</pre>';

        if ($r['kept'] !== [] && !$force) {
            $html .= '<p>Those documents were left alone because something is already there. '
                  . 'To replace them: <a href="/__setup/legal?token=' . $e(rawurlencode($tok))
                  . '&amp;force=1">run again with force</a>.</p>';
        }

        $html .= '<p>Now edit them at <a href="/admin/legal">/admin/legal</a>, and read them at '
              . '<a href="/terms">/terms</a> and <a href="/privacy">/privacy</a>. This endpoint will '
              . 'not touch them again unless you pass <code>force=1</code>.</p>'
              . '<div class="warn"><strong>Not legal advice.</strong> This wording is an accurate, '
              . 'plain-language description of what the platform does, written from the code. It has '
              . 'not been reviewed by a lawyer — the data-protection specifics, consumer-protection '
              . 'wording and liability clause in particular want counsel before you rely on them.</div>'
              . '<p style="color:#8aa;font-size:12.5px">Delete SETUP_TOKEN from .env when you have '
              . 'finished with the setup endpoints.</p>'
              . '</div></body></html>';

        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    $app->get('/__setup/assets', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);
        $r = \AfricaGates\Support\AssetBundle::build();
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $lines = $r['ok']
            ? array_merge([
                'Bundle: ' . $r['file'],
                'Sources: ' . $r['sources'],
                'Before:  ' . number_format($r['raw'] / 1024, 1) . ' KiB across ' . $r['sources'] . ' requests',
                'After:   ' . number_format($r['min'] / 1024, 1) . ' KiB in 1 request (' . $r['saved_pct'] . '% smaller)',
              ], array_map(fn($m) => 'WARN missing source: ' . $m, $r['missing']))
            : ['FAILED: ' . $r['error']];
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Africa GATES — asset build</title>'
            . '<style>body{font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#10292C;color:#bcd;margin:0;padding:30px 16px}'
            . '.box{max-width:780px;margin:0 auto}h1{color:#fff;font-size:18px;margin:0 0 6px}'
            . 'pre{background:#06181a;color:#9fe6a0;padding:16px;border-radius:10px;overflow:auto;font-size:12.5px;line-height:1.6}'
            . '.ok{color:#7FC87C;font-weight:600}.err{color:#ff9a9a;font-weight:600}</style></head><body><div class="box">'
            . '<h1>Africa GATES — asset build</h1>'
            . '<p class="' . ($r['ok'] ? 'ok' : 'err') . '">' . ($r['ok'] ? 'DONE — the site now serves one bundled stylesheet.' : 'FAILED') . '</p>'
            . '<pre>' . $e(implode("\n", $lines)) . '</pre>'
            . '<p>Re-run this after any CSS change. Until you do, the site serves the individual '
            . 'stylesheets — correct, just slower — so forgetting it can never break styling.</p>'
            . '</div></body></html>';
        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                   ->withStatus($r['ok'] ? 200 : 500);
    });
    /**
     * GET /__setup/checkout?token=… — why a paid vote is failing, from a browser.
     *
     * WHAT THIS REPLACES. Diagnosing a failed checkout has needed a shell: `app:doctor`
     * for the live-vs-code CSP comparison, and `tail var/logs/app.log` for the gateway's
     * own refusal message. This account has no SSH, so neither was ever available, and
     * the only signal was a supporter saying "it does not work" — which is how a broken
     * checkout survived several fixes.
     *
     * It reports the four things that decide whether a payment starts, in the order they
     * fail:
     *
     *   1. THE CSP THIS RESPONSE CARRIES, compared to Csp::policy() and
     *      Csp::staticPolicy(). A third value means something downstream is replacing it
     *      — on this account, the host's account-wide injected policy — and that single
     *      fact explains blocked CDN scripts and refused paid votes together.
     *   2. THE BUILD, so "is the server even running this code" is answered on the same
     *      page rather than inferred.
     *   3. THE GATEWAY: keys present, and behind &ping=1 one real transaction/initialize
     *      reporting the provider's OWN message. A bad key or unsupported currency says
     *      so here instead of becoming a generic chip on the ballot.
     *   4. THE LOG: whether var/logs is writable (an unwritable log dir turned this very
     *      checkout into a 500) and the recent payment lines.
     *
     * SAFETY. Same SETUP_TOKEN guard as the rest of this namespace, 404 without it,
     * no-store, noindex. No secret is ever echoed — keys are reported only as
     * present/absent. The gateway ping is opt-in because it creates a real (unpaid,
     * unused) transaction reference at the provider.
     */
    $app->get('/__setup/checkout', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $q = $req->getQueryParams();

        // ── 1. The policy a BROWSER receives ─────────────────────────────────
        // Not headers_list(): the middleware sets the CSP after this route returns, and
        // Apache's `Header always set` is applied later still, so PHP's own view of its
        // headers is both empty here and unable to see what replaced them. The only
        // honest answer comes from fetching a real response over HTTP, which is what
        // app:doctor does — reused here because the shell is unavailable on this host.
        $expected = \AfricaGates\Support\Csp::policy();
        $fallback = \AfricaGates\Support\Csp::staticPolicy();
        $norm     = static fn (string $p): string => (string) preg_replace("~'nonce-[^']*'~", "'nonce-X'", $p);

        $self = rtrim((string) $req->getUri()->withPath('')->withQuery('')->withFragment(''), '/');
        $ctx  = stream_context_create(['http' => [
            'method' => 'HEAD', 'timeout' => 8, 'ignore_errors' => true,
            'follow_location' => 0, 'header' => "User-Agent: africa-gates-preflight\r\n",
        ]]);
        $hdrs  = @get_headers($self . '/ping', true, $ctx);
        $sent  = '';
        $count = 0;
        if (is_array($hdrs)) {
            foreach ($hdrs as $name => $value) {
                if (strcasecmp((string) $name, 'Content-Security-Policy') !== 0) continue;
                // An ARRAY means TWO CSP headers. Browsers enforce them as the
                // INTERSECTION, so each can look fine alone while the pair blocks
                // everything the narrower one omits — the injected-policy signature.
                $count = is_array($value) ? count($value) : 1;
                $sent  = is_array($value) ? implode("\n\n——— and a SECOND policy ———\n\n", $value) : (string) $value;
            }
        }

        if (!is_array($hdrs)) {
            $verdict = ['unknown', 'Could not fetch ' . $self . '/ping from the server itself — some hosts block loopback HTTP. Read the Content-Security-Policy from your browser\'s Network tab instead and compare it to the value below.'];
            $sent = '(unreachable) — for comparison, PHP intends to send:' . "\n\n" . $expected;
        } elseif ($count > 1) {
            $verdict = ['bad', 'TWO Content-Security-Policy headers are on the response. Browsers enforce them as the INTERSECTION, so the narrower one wins every directive — this is the host-injected policy sitting alongside ours. The `Header always unset` line in public/.htaccess is not taking effect.'];
        } elseif ($sent === '') {
            $verdict = ['bad', 'The live response carries NO Content-Security-Policy at all.'];
        } elseif ($norm($sent) === $norm($expected)) {
            $verdict = ['ok', 'The nonce policy from Csp::policy(). Correct — and it means the host is NOT injecting, so the two Header lines in public/.htaccess can be removed.'];
        } elseif ($sent === $fallback) {
            $verdict = ['ok', 'Csp::staticPolicy() from public/.htaccess — the injected policy has been displaced. Correct. Paid votes and CDN scripts are permitted.'];
        } else {
            $verdict = ['bad', 'This matches NEITHER Csp::policy() NOR Csp::staticPolicy(), so something downstream is replacing it — on this account that is the host-injected policy. Confirm the two Header lines in public/.htaccess are live; if they are, the host is overriding .htaccess and only they can turn it off.'];
        }

        // ── 3. The gateways ──────────────────────────────────────────────────
        $svc  = new \AfricaGates\Services\PaymentService();
        $rows = [];
        foreach (['paystack', 'flutterwave'] as $p) {
            $rows[$p] = ['enabled' => $svc->isEnabled($p), 'ping' => null];
        }
        if (!empty($q['ping'])) {
            $base = (string) $req->getUri()->withPath('')->withQuery('')->withFragment('');
            foreach ($rows as $p => $d) {
                if (!$d['enabled']) continue;
                try {
                    $r = $svc->initialize($p, 100, 'preflight@africagates.invalid',
                        'AFG-PREFLIGHT-' . bin2hex(random_bytes(4)),
                        rtrim($base, '/') . '/vote/paid/callback', ['purpose' => 'preflight']);
                    $rows[$p]['ping'] = !empty($r['ok'])
                        ? 'OK — the gateway returned a checkout URL.'
                        : 'REFUSED — ' . ((string) ($r['message'] ?? 'no message returned'));
                } catch (\Throwable $ex) {
                    $rows[$p]['ping'] = 'ERROR — ' . $ex->getMessage();
                }
            }
        }

        // ── 4. The log ───────────────────────────────────────────────────────
        $logDir   = dirname(__DIR__) . '/var/logs';
        $logFile  = $logDir . '/app.log';
        $writable = is_dir($logDir) && is_writable($logDir);
        $tail     = [];
        if (is_readable($logFile)) {
            $all = (array) @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (array_slice($all, -400) as $line) {
                foreach (['paid-vote', 'payment', 'gateway', 'donation'] as $needle) {
                    if (stripos((string) $line, $needle) !== false) { $tail[] = (string) $line; break; }
                }
            }
            $tail = array_slice($tail, -25);
        }

        $colour = static fn (string $k): string => ['ok' => '#7FC87C', 'bad' => '#E5736B', 'unknown' => '#D8B45C'][$k] ?? '#999999';
        $pre    = 'white-space:pre-wrap;word-break:break-all;background:#152420;padding:.75rem;border-radius:.4rem;font-size:.78rem';

        $h = '<!doctype html><meta charset="utf-8"><title>Checkout preflight</title>'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<meta name="robots" content="noindex,nofollow">'
           . '<body style="margin:0;background:#0F1A17;color:#E8F2EC;font:15px/1.6 system-ui,-apple-system,sans-serif">'
           . '<div style="max-width:56rem;margin:0 auto;padding:2rem 1.25rem">'
           . '<h1 style="font-size:1.4rem;margin:0 0 1.5rem">Checkout preflight</h1>'
           . '<h2 style="font-size:1rem;margin:1.5rem 0 .5rem">1 &middot; Content-Security-Policy</h2>'
           . '<p style="margin:.2rem 0;font-weight:600;color:' . $colour($verdict[0]) . '">'
           . $e(strtoupper($verdict[0])) . ' &mdash; ' . $e($verdict[1]) . '</p>'
           . '<pre style="' . $pre . '">' . $e($sent !== '' ? $sent : '(none set by PHP at this point)') . '</pre>'
           . '<h2 style="font-size:1rem;margin:1.5rem 0 .5rem">2 &middot; Deployed build</h2>'
           . '<pre style="' . $pre . '">' . $e((string) json_encode(\AfricaGates\Support\Build::fingerprint(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>'
           . '<h2 style="font-size:1rem;margin:1.5rem 0 .5rem">3 &middot; Payment gateways</h2><ul style="margin:.3rem 0;padding-left:1.1rem">';
        foreach ($rows as $p => $d) {
            $h .= '<li><b>' . $e($p) . '</b>: keys '
               . ($d['enabled']
                    ? '<span style="color:#7FC87C">configured</span>'
                    : '<span style="color:#E5736B">MISSING &mdash; this provider cannot start a checkout</span>')
               . ($d['ping'] !== null ? ' &middot; live: ' . $e($d['ping']) : '') . '</li>';
        }
        $h .= '</ul><p style="font-size:.85rem;color:#A9C7BD">Add <code>&amp;ping=1</code> to run one real '
           . '<code>transaction/initialize</code> and see the gateway&rsquo;s own message.</p>'
           . '<h2 style="font-size:1rem;margin:1.5rem 0 .5rem">4 &middot; Log</h2><p style="margin:.2rem 0">var/logs writable: '
           . ($writable ? '<span style="color:#7FC87C">yes</span>'
                        : '<span style="color:#E5736B">NO &mdash; an unwritable log directory turns this checkout into a 500</span>')
           . '</p><pre style="' . $pre . '">'
           . $e($tail ? implode("\n", $tail) : '(no payment lines in the last 400 log lines)')
           . '</pre>';

        // ── 5. Can the response be detached before maintenance runs? ─────────
        // The cause of ERR_HTTP2_PROTOCOL_ERROR on this host. The web-cron shutdown
        // handler only detaches if this SAPI provides one of these functions; without
        // one it used to run the full maintenance pass — including a gateway verify per
        // stale pending order, at 15 seconds each — on the visitor's own connection,
        // until the server killed the worker mid-response.
        $ls = function_exists('litespeed_finish_request');
        $fp = function_exists('fastcgi_finish_request');
        $h .= '<h2 style="font-size:1rem;margin:1.5rem 0 .5rem">5 &middot; Background work</h2>'
           . '<p style="margin:.2rem 0">SAPI <code>' . $e(PHP_SAPI) . '</code> &middot; '
           . 'litespeed_finish_request: ' . ($ls ? '<span style="color:#7FC87C">available</span>' : 'absent')
           . ' &middot; fastcgi_finish_request: ' . ($fp ? '<span style="color:#7FC87C">available</span>' : 'absent') . '</p>'
           . '<p style="margin:.2rem 0;font-weight:600;color:' . $colour($ls || $fp ? 'ok' : 'unknown') . '">'
           . ($ls || $fp
                ? 'OK &mdash; maintenance is detached from the response, so it can never hold a visitor\'s connection open.'
                : 'NOTE &mdash; this SAPI cannot detach, so the opportunistic maintenance tick is skipped entirely (by design). Schedule real cron or the token-gated /__cron/run so maintenance still happens.')
           . '</p>';

        // ── 6. Turnstile ─────────────────────────────────────────────────────
        // "The OTP does not work" is unfalsifiable from outside: a rejected challenge
        // and a missing key produce the same 403, and the half-configured pair (secret
        // set, site key blank) makes every vote on the site fail while looking, in the
        // log, exactly like the protection doing its job. Reported as PRESENCE only —
        // neither key is ever echoed.
        $tsSite   = trim((string) \AfricaGates\Support\Env::get('TURNSTILE_SITE_KEY', ''));
        $tsSecret = trim((string) \AfricaGates\Support\Env::get('TURNSTILE_SECRET', ''));
        if ($tsSite !== '' && $tsSecret !== '') {
            $tsVerdict = ['ok', 'Both keys set — the widget renders and the server verifies it.'];
        } elseif ($tsSite === '' && $tsSecret === '') {
            $tsVerdict = ['ok', 'Neither key set — bot checks are off, and the OTP path is unaffected by them.'];
        } elseif ($tsSecret !== '') {
            $tsVerdict = ['bad', 'TURNSTILE_SECRET is set but TURNSTILE_SITE_KEY is EMPTY. No widget can render, so '
                . 'no browser can produce a token and every OTP request would 403. Enforcement is being SKIPPED so '
                . 'voting still works, and logged as an error on each request — but set both keys, or clear both.'];
        } else {
            $tsVerdict = ['unknown', 'TURNSTILE_SITE_KEY is set but TURNSTILE_SECRET is EMPTY. The widget is shown '
                . 'and nothing checks it — decorative, not protection.'];
        }
        $tsLog = [];
        if (is_readable($logFile)) {
            $all = (array) @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (array_slice($all, -400) as $line) {
                if (stripos((string) $line, 'turnstile') !== false) $tsLog[] = (string) $line;
            }
            $tsLog = array_slice($tsLog, -15);
        }
        $h .= '<h2 style="font-size:1rem;margin:1.5rem 0 .5rem">6 &middot; Turnstile (OTP bot check)</h2>'
           . '<p style="margin:.2rem 0">TURNSTILE_SITE_KEY: '
           . ($tsSite !== '' ? '<span style="color:#7FC87C">set</span>' : '<span style="color:#E5736B">empty</span>')
           . ' &middot; TURNSTILE_SECRET: '
           . ($tsSecret !== '' ? '<span style="color:#7FC87C">set</span>' : '<span style="color:#E5736B">empty</span>')
           . '</p><p style="margin:.2rem 0;font-weight:600;color:' . $colour($tsVerdict[0]) . '">'
           . $e(strtoupper($tsVerdict[0])) . ' &mdash; ' . $e($tsVerdict[1]) . '</p>'
           . '<pre style="' . $pre . '">'
           . $e($tsLog ? implode("\n", $tsLog) : '(no turnstile lines in the last 400 log lines)')
           . '</pre>';

        $h .= '</div></body>';

        $res->getBody()->write($h);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    });
    $app->get('/__setup/status', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);
        $res->getBody()->write(json_encode(\AfricaGates\Services\MigrationRunner::status(), JSON_PRETTY_PRINT));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    /**
     * GET /__setup/errors?token=… — "what actually went wrong?"
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS ENDPOINT HAD TO EXIST
     * ══════════════════════════════════════════════════════════════════════════
     *
     * {@see \AfricaGates\Handlers\ErrorHandler} has always written every 5xx in full —
     * class, message, file, line, stack — to `var/logs/error-detail.log`. It is written
     * precisely because detail DISPLAY is hardened off on public hosts, so the page a
     * visitor sees says "an internal error occurred" and nothing else.
     *
     * And then nothing could read it. `var/` is outside the document root by design, this
     * platform deploys to shared cPanel hosting where a shell is often nobody, and so the
     * one file written specifically to make a production crash diagnosable was reachable
     * only by whoever could open a file manager and knew it was there.
     *
     * The cost of that gap is measured in the report that produced this endpoint: a total
     * checkout outage where the only way to learn the cause was for somebody to go and find
     * a log file by hand. Every other `/__setup/*` page exists for exactly this reason —
     * this is the one that was missing.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHAT IT SHOWS, AND WHAT IT REFUSES TO
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The last N entries, NEWEST FIRST — the opposite of `tail`, because the question is
     * always "what just happened" and an operator should not have to scroll to the bottom of
     * a growing file to answer it.
     *
     * Token-gated behind the same SETUP_TOKEN as its siblings, 404 without it so the
     * endpoint is invisible, `no-store` and `noindex`. Stack traces name internal paths and
     * are not for the public — but they are exactly what a person fixing this needs, so they
     * are shown in full to whoever holds the token rather than trimmed into uselessness.
     */
    $app->get('/__setup/errors', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);

        $e    = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $file = dirname(__DIR__) . '/var/logs/error-detail.log';
        $want = max(1, min(50, (int) ($req->getQueryParams()['n'] ?? 10)));

        $h = '<!doctype html><meta charset="utf-8"><title>Recent errors — Africa GATES</title>'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<style>body{font:14px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;margin:0;'
           . 'padding:28px 20px;background:#0f1a1c;color:#dfe9e7}h1{font-size:19px;margin:0 0 4px}'
           . '.sub{color:#8fa5a6;font-size:13px;margin:0 0 20px}'
           . 'pre{background:#132327;border:1px solid #24393c;border-left:3px solid #b4553f;'
           . 'border-radius:5px;padding:12px 14px;overflow-x:auto;font-size:12px;line-height:1.55;'
           . 'white-space:pre-wrap;word-break:break-word;margin:0 0 12px}'
           . '.ok{border-left-color:#3f7f46;color:#a8ccae}.n{color:#8fa5a6;font-size:12.5px}'
           . 'code{background:#132327;padding:1px 5px;border-radius:3px}</style>'
           . '<h1>Recent errors</h1>';

        if (!is_file($file)) {
            $h .= '<pre class="ok">Nothing here — <code>var/logs/error-detail.log</code> does not exist yet.'
                . "\n\n" . 'That means no 500 has been recorded SINCE THIS CODE WAS DEPLOYED. If you are '
                . 'seeing a 500 right now, the likeliest explanations are that the server is not running '
                . 'this deployment at all (check <code>/ping</code> — the `rev` field), or that the error '
                . 'happens before PHP reaches the error handler, in which case it will be in your host\'s '
                . 'own PHP error log rather than here.</pre>';
        } else {
            $raw = (string) @file_get_contents($file);
            // Entries are separated by a blank line and each begins with an ISO timestamp in
            // brackets — the format ErrorHandler writes. Split on that rather than on blank
            // lines alone, because a stack trace contains blank lines of its own.
            $parts = preg_split('/\n(?=\[\d{4}-\d{2}-\d{2}T)/', trim($raw)) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts)));
            $total = count($parts);
            $show  = array_slice(array_reverse($parts), 0, $want);

            $h .= '<p class="sub">' . $total . ' recorded · showing the '
                . count($show) . ' most recent, newest first · '
                . $e(date('c', (int) @filemtime($file))) . ' last written</p>';
            foreach ($show as $entry) {
                $h .= '<pre>' . $e($entry) . '</pre>';
            }
            if ($total === 0) {
                $h .= '<pre class="ok">The file exists but is empty — no 500 has been recorded.</pre>';
            }
        }

        $h .= '<p class="n">Add <code>&amp;n=30</code> for more. This page is token-gated and '
            . 'noindex. Delete <code>var/logs/error-detail.log</code> to clear it.</p>';

        $res->getBody()->write($h);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    // ── Webcron: drive the maintenance hub over HTTP (token-gated) ───────────
    // For hosts without reliable shell cron: set CRON_TOKEN in .env, then point a
    // webcron service (cron-job.org, EasyCron, or a cPanel "curl a URL" job) at
    //   https://your-site/__cron/run?token=THAT          (every ~15 min)
    // It runs the SAME single-source orchestration as cron/maintenance.php
    // (AfricaGates\Support\Maintenance), selecting work by the clock. Optional
    // ?task=cpi|queue|cycles|… runs one job. A single-instance lock means an
    // overlapping hit exits cleanly (200, skipped) rather than double-running.
    // Invisible (404) without the correct token; the token also accepted via the
    // X-Cron-Token header so it needn't sit in access logs.
    $cronGuard = static function ($req): bool {
        // Token from .env (SSH hosts) OR admin Settings (no-SSH hosts set it in
        // the browser). Either matching a >=12-char given token unlocks the run.
        $token = trim((string) Env::get('CRON_TOKEN', ''));
        if ($token === '') {
            try { $token = trim((string)(\Illuminate\Database\Capsule\Manager::table('gates_settings')->where('key_name', 'cron_token')->value('value') ?? '')); }
            catch (\Throwable) { $token = ''; }
        }
        if ($token === '') return false;
        $given = (string)($req->getQueryParams()['token'] ?? ($req->getHeaderLine('X-Cron-Token') ?: ''));
        return strlen($given) >= 12 && hash_equals($token, $given);
    };
    // ── NOT `static`. THAT ONE WORD IS WHY THIS ENDPOINT ALWAYS 500'd ───────────
    //
    // Reported from production: "the cron page 500s even at the time the whole site
    // was 200." It did, on every request, and nothing about the token, the database
    // or the maintenance work had anything to do with it.
    //
    // Slim binds a route callable to the container before invoking it:
    //
    //     Slim\CallableResolver::bindToContainer(): $callable->bindTo($this->container)
    //
    // `Closure::bindTo()` returns NULL for a STATIC closure — a static closure has no
    // `$this` to rebind — and Slim declares that method's return type as `callable`.
    // So a static route handler is a guaranteed
    //
    //     TypeError: bindToContainer(): Return value must be of type callable, null returned
    //
    // before one line of the handler runs. It fails identically whether the site is
    // healthy or not, which is exactly why it looked unrelated to everything else.
    //
    // `$cronGuard` and `$setupGuard` above stay static: they are plain closures this
    // file calls itself, never handed to Slim. Only a callable Slim RESOLVES may not
    // be static. tests/Unit/WebcronTest.php dispatches this route through the real
    // app so the mistake cannot come back in a form a grep would miss.
    $cronRun = function ($req, $res) use ($cronGuard, $app) {
        if (!$cronGuard($req)) return $res->withStatus(404);
        $root = dirname(__DIR__);
        // Single-instance: don't overlap the CLI cron or another webcron hit.
        if (!\AfricaGates\Support\CronGuard::acquire('maintenance', $root . '/var/data')) {
            $res->getBody()->write(json_encode(['ok' => true, 'skipped' => 'another run in progress']));
            return $res->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')->withStatus(200);
        }
        $task = (string)($req->getQueryParams()['task'] ?? 'auto');
        try {
            $container = $app->getContainer();   // full services; Maintenance degrades gracefully if null
            $result = (new \AfricaGates\Support\Maintenance($container, false))->run($task);

            // ── WHY A PARTIAL RUN IS STILL A 200 ─────────────────────────────────
            //
            // Reported from production: "the cron page 500s even at the time the whole
            // site was 200." One unguarded task threw, `run()` aborted, and this handler
            // answered 500 with the word "failed" and nothing else. Two consequences,
            // both bad: an operator with no SSH had no way to learn the reason, and a
            // webcron service seeing a persistent 500 backs off or disables the job —
            // so the tasks that WERE working stopped running too.
            //
            // Tasks are now isolated (see Maintenance::task()), so the run always
            // completes and the status describes what happened rather than whether
            // anything went wrong: 200 with `ok:false` and a per-task `failures` map.
            // The scheduler keeps firing, the healthy work keeps happening, and opening
            // the URL in a browser says exactly which task is broken and why.
            $ok = ($result['failures'] ?? []) === [];
            $res->getBody()->write(json_encode(['ok' => $ok] + $result, JSON_UNESCAPED_SLASHES));
            $code = 200;
        } catch (\Throwable $e) {
            // Only reached when the ORCHESTRATOR itself could not run — no database, no
            // container. The message is returned because this endpoint is gated by a
            // >=12-character shared secret compared with hash_equals: whoever is reading
            // this response is the operator, and withholding it from them bought nothing
            // and cost days.
            error_log('[webcron] ' . $e->getMessage());
            $res->getBody()->write(json_encode([
                'ok'    => false,
                'error' => 'maintenance could not start',
                'why'   => $e->getMessage(),
                'at'    => basename($e->getFile()) . ':' . $e->getLine(),
            ], JSON_UNESCAPED_SLASHES));
            $code = 500;
        }
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                   ->withStatus($code);
    };
    $app->get('/__cron/run',  $cronRun);
    $app->post('/__cron/run', $cronRun);
    // No-SSH admin bootstrap: create the FIRST superadmin, or reset the password +
    // clear the lockout for an existing admin. Token-gated (same SETUP_TOKEN). The
    // password is sent by POST (never in the URL). Returns 404 without the token.
    $app->map(['GET', 'POST'], '/__setup/admin', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);
        $tok = (string) ($req->getQueryParams()['token'] ?? '');
        $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $msg = ''; $ok = false;
        if ($req->getMethod() === 'POST') {
            $b     = (array) $req->getParsedBody();
            $email = strtolower(trim((string) ($b['email'] ?? '')));
            $name  = trim((string) ($b['name'] ?? ''));
            $pass  = (string) ($b['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))      $msg = 'Enter a valid email address.';
            elseif ($name === '')                                $msg = 'Enter a display name.';
            elseif (strlen($pass) < 10)                          $msg = 'Password must be at least 10 characters.';
            else {
                try {
                    $now = date('Y-m-d H:i:s');
                    $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                    $tbl = \Illuminate\Database\Capsule\Manager::table('gates_admins');
                    $existing = (clone $tbl)->where('email', $email)->first();
                    if ($existing) {
                        // RESET is limited to genuine recovery — a locked-out or
                        // disabled account. An active, unlocked admin must rotate
                        // from the console (or via the magic-link) so that a leaked
                        // SETUP_TOKEN can't silently seize a live superadmin.
                        $locked   = $existing->locked_until !== null && strtotime((string) $existing->locked_until) > time();
                        $disabled = (int) ($existing->is_active ?? 1) === 0;
                        if (!$locked && !$disabled) {
                            error_log("[setup] REFUSED password reset for active account {$email} from {$ip}");
                            $msg = 'This account is active and not locked, so in-place reset here is disabled. '
                                 . 'Use the admin magic-link at /admin/magic, or delete SETUP_TOKEN and run `php bin/console admin:create`.';
                        } else {
                            (clone $tbl)->where('id', $existing->id)->update([
                                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
                                'is_active' => 1, 'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => $now,
                            ]);
                            error_log("[setup] password reset + unlock for {$email} from {$ip}");
                            $ok = true; $msg = "Password reset and account unlocked for {$email} (role unchanged).";
                        }
                    } else {
                        // CREATE is limited to first-run: only when NO admin exists
                        // yet. Once provisioned, add further admins from inside the
                        // console (Admins, superadmin-only) — not via the token.
                        $adminCount = (int) (clone $tbl)->count();
                        if ($adminCount > 0) {
                            error_log("[setup] REFUSED create superadmin {$email} from {$ip} — {$adminCount} admin(s) already exist");
                            $msg = 'An admin account already exists, so first-admin creation here is disabled. '
                                 . 'Add admins from the console (Admins), recover a locked/disabled account, or use `php bin/console admin:create`.';
                        } else {
                            (clone $tbl)->insert([
                                'email' => $email, 'name' => $name, 'role' => 'superadmin',
                                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
                                'is_active' => 1, 'failed_attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
                            ]);
                            error_log("[setup] created first superadmin {$email} from {$ip}");
                            $ok = true; $msg = "Created superadmin {$email}.";
                        }
                    }
                } catch (\Throwable $ex) {
                    $msg = 'Database error: ' . $ex->getMessage() . ' — run /__setup/migrate first.';
                }
            }
        }
        $action = '/__setup/admin?token=' . rawurlencode($tok);
        $banner = $msg !== '' ? '<p class="' . ($ok ? 'ok' : 'err') . '">' . $e($msg) . '</p>' : '';
        $next   = $ok ? '<p class="ok">Now sign in at <a href="/admin/login">/admin/login</a> — then DELETE the SETUP_TOKEN line from your .env.</p>' : '';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Africa GATES — admin setup</title>'
            . '<style>body{font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#10292C;margin:0;padding:40px 16px}'
            . '.card{max-width:440px;margin:0 auto;background:#fff;border-radius:16px;padding:28px 26px;box-shadow:0 20px 50px -20px rgba(0,0,0,.55)}'
            . 'h1{font-size:20px;margin:0 0 4px;color:#0f172a}.sub{color:#64748b;font-size:13px;margin:0 0 16px}'
            . 'label{display:block;font-size:13px;font-weight:600;margin:14px 0 6px;color:#0f172a}'
            . 'input{width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid #cbd5e1;border-radius:9px;font-size:15px}'
            . 'button{width:100%;margin-top:18px;padding:12px;border:0;border-radius:999px;background:#237b22;color:#fff;font-weight:700;font-size:15px;cursor:pointer}'
            . '.ok{color:#15803d;font-size:14px;line-height:1.5}.err{color:#b91c1c;font-size:14px}a{color:#237b22}</style></head><body><div class="card">'
            . '<h1>Admin setup</h1><p class="sub">Create the first superadmin, or reset the password &amp; unlock an existing admin. Delete SETUP_TOKEN from .env when done.</p>'
            . $banner . $next
            . '<form method="post" action="' . $e($action) . '">'
            . '<label>Email</label><input type="email" name="email" required placeholder="you@afrovanguard.org.ng" autocomplete="username">'
            . '<label>Display name</label><input type="text" name="name" required placeholder="Your name">'
            . '<label>New password (min 10 characters)</label><input type="password" name="password" required minlength="10" autocomplete="new-password">'
            . '<button type="submit">Create / reset admin</button></form></div></body></html>';
        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    $container = $app->getContainer();
    $app->group('', function(RouteCollectorProxy $g) use ($container) {
        $twig = $container->get(\Slim\Views\Twig::class);
        $tv = fn($req) => $twig;
        $g->get('[/]',            HomeController::class.':index');
        $g->get('/awards',        AwardsController::class.':index');
        $g->get('/awards/{p}',    AwardsController::class.':programme');
        $g->get('/leaderboard',   LeaderboardController::class.':index');
        $g->get('/judges',        JudgesController::class.':index');
        $g->get('/judges/{slug}', JudgesController::class.':show');
        $g->get('/registry',      RegistryController::class.':index');
        $g->get('/registry/{slug}',RegistryController::class.':profile');
        // /register is retired — the member account sign-up (/account/register) is
        // the single canonical registration. Bounce old links/bookmarks there.
        $g->get('/register',         fn($req,$res)=>$res->withHeader('Location','/account/register')->withStatus(301));
        $g->post('/register',        fn($req,$res)=>$res->withHeader('Location','/account/register')->withStatus(303));
        $g->get('/register/success', fn($req,$res)=>$res->withHeader('Location','/account')->withStatus(301));
        $g->get('/legacy',        LegacyController::class.':index');
        $g->get('/legacy/{slug}', LegacyController::class.':event');
        $g->get('/opportunities',  OpportunityController::class.':index');
        $g->get('/events',         EventsController::class.':index');
        // ── PAID TICKETS ─────────────────────────────────────────────────────
        //
        // BEFORE `/events/{slug}`, and that ordering is load-bearing: Slim matches in
        // registration order, so a `{slug}` route declared first would swallow
        // `/events/redirect` and `/events/callback` and a buyer would come back from
        // Paystack to a 404 instead of a ticket.
        //
        // `register` answers with a hand-off URL when the chosen tier costs money, and
        // these are the rest of that journey. Same shape as the shop: an interstitial
        // performs the redirect (a 302 straight to a gateway from inside a form POST is
        // governed by `form-action`, and a CSP without the gateway hosts blocks it in the
        // browser before any PHP runs), the callback re-verifies server-to-server rather
        // than believing a query string, and the ticket page is reachable with the
        // reference alone — an attendee has no account, and a login has no business
        // standing between somebody and the door they are queueing at.
        $g->get('/events/redirect',        EventsController::class.':redirect');
        $g->get('/events/callback',        EventsController::class.':callback');
        // The calendar files. Registered BEFORE `/events/{slug}`, because `{slug}` would
        // otherwise swallow `calendar.ics` as a slug and answer 404 for a real event — the
        // same ordering trap the back-in-stock stop link hit in the shop.
        $g->get('/events/ticket/{ref:[A-Za-z0-9\-]{8,60}}/calendar.ics',
                EventsController::class.':ticketCalendar');
        $g->get('/events/ticket/{ref:[A-Za-z0-9\-]{8,60}}', EventsController::class.':ticket');
        $g->get('/events/{slug}/calendar.ics', EventsController::class.':calendar');
        $g->get('/events/{slug}',  EventsController::class.':show');
        $g->post('/events/{slug}/register', EventsController::class.':register');
        // A price preview for a typed discount code, and the queue for a tier that has gone.
        // Both POST: the quote takes an email address, and a waitlist join creates a row.
        $g->post('/events/{slug}/quote',    EventsController::class.':quote');
        $g->post('/events/{slug}/waitlist', EventsController::class.':waitlist');
        $g->get('/blog',           BlogController::class.':index');
        $g->get('/blog/{slug}',    BlogController::class.':show');
        $g->get('/pulse',          PulseController::class.':index');
        // Reels is the same page on its video tab. A real URL rather than only a
        // client-side tab, because the main navigation, the home page and the
        // status page all advertise Reels as a place — so it has to be linkable,
        // shareable and indexable like one. The template reads the path.
        $g->get('/pulse/reels',    PulseController::class.':index');
        // Members post to the feed. Goes through CommunityService::postThread, so it
        // inherits the spam filter, the moderation verdict and the moderation queue.
        $g->post('/pulse',         PulseController::class.':post');
        // Activity — one searchable timeline. TWO routes on purpose: /activity is a
        // real GET form rendered server-side so the search works with no JavaScript,
        // and /activity/search is the JSON the live combobox layers on top. Same
        // service behind both, so they cannot disagree about what happened.
        $g->get('/activity',       ActivityController::class.':index');
        $g->get('/activity/search',ActivityController::class.':search');
        $g->get('/nominate',      NominationController::class.':form');
        $g->post('/nominate',     NominationController::class.':submit');
        $g->get('/nominate/success',function($req,$res) use ($tv){ $d=$_SESSION['nom_done']??null; unset($_SESSION['nom_done']); return $tv($req)->render($res,'pages/nominate-success.twig',['page_title'=>'Nomination Submitted — Africa GATES','meta_description'=>'Your nomination is in. Thank you for championing African excellence — our team will review it for the Africa GATES awards cycle. Nominate someone else too.','gates_page'=>'nominate','has_hero'=>false,'ref'=>$d['ref']??'','nominee'=>$d['nominee']??'','category'=>$d['cat']??'','share_payload'=>$d['share']??null]); });
        // Paid-voting routes are STATIC and must be registered BEFORE the
        // /vote/{program}/{slug} variable route, or FastRoute treats them as
        // shadowed and aborts routing for the whole app.
        $g->post('/vote/paid/start',   PaidVoteController::class.':start');
        // The same-origin hop to the gateway. A 302 from the POST straight to Paystack is
        // part of a FORM SUBMISSION, so `form-action` governs it — and a policy without the
        // gateway hosts blocks the POST in the browser, before any PHP runs, with nothing in
        // any server log. This route is what makes paid checkout independent of the CSP.
        // See AfricaGates\Services\GatewayHandoff.
        $g->get('/vote/paid/redirect', PaidVoteController::class.':handoff');
        $g->get('/vote/paid/callback', PaidVoteController::class.':callback');
        $g->get('/vote/paid/success',  PaidVoteController::class.':success');
        // PROOF. Supporters told the unminted-vote incident was resolved asked for
        // evidence, which is the right response to being told something by a
        // platform that had just been publicly wrong. An aggregate cannot answer
        // "where are MY votes", so this answers exactly one order — reading the
        // live vote ROWS rather than the counter that claims they exist.
        //
        // No auth. The reference is a bearer token and the page deliberately holds
        // nothing about the payer, so requiring a login would only lock out the
        // majority who never had an account while protecting nothing.
        $g->get('/vote/verify', function($req, $res) use ($tv) {
            $ref = trim((string) ($req->getQueryParams()['ref'] ?? ''));
            return $tv($req)->render($res, 'pages/vote-verify.twig', [
                'page_title'       => 'Verify a payment — Africa GATES',
                'meta_description' => 'Check exactly what happened to a paid vote — what was charged, what was '
                                    . 'delivered to the tally, and when.',
                'gates_page'       => 'verify',
                'has_hero'         => false,
                'ref'              => $ref,
                'proof'            => $ref !== ''
                    ? \AfricaGates\Services\VoteProof::forReference($ref)
                    : ['found' => false],
            ]);
        });
        $g->get('/vote',                  VoteController::class.':index');
        $g->get('/vote/{program}',        VoteController::class.':program');
        // Live tallies for the race page. BEFORE /vote/{program}/{slug} — that pattern
        // would otherwise swallow "tallies" as a nominee slug. It is safe here because
        // the slug route requires a leading digit, but the ordering is what the rest of
        // this block already relies on and it should not be the one exception.
        $g->get('/vote/{program}/tallies', VoteController::class.':tallies');
        // The flier routes go BEFORE /vote/{program}/{slug}: FastRoute matches in
        // declaration order for same-length paths, and `{slug}` would otherwise
        // swallow nothing here — but `{slug}/flier` is longer, so order is not
        // strictly required. Declared first anyway, so a future change to the slug
        // pattern cannot silently capture them. `[0-9]+[^/]*` pins the canonical
        // `{id}-{name}` shape the controller casts from.
        $g->get('/vote/{program}/{slug:[0-9]+[^/]*}/flier',      FlierController::class.':page');
        $g->get('/vote/{program}/{slug:[0-9]+[^/]*}/flier.svg',  FlierController::class.':svg');
        // The raster, and the og:image target — a crawler cannot run JavaScript and no
        // major chat app renders SVG in a link preview.
        $g->get('/vote/{program}/{slug:[0-9]+[^/]*}/flier.png',  FlierController::class.':png');
        // The LINK-PREVIEW card: 1200×630, the aspect ratio Facebook and LinkedIn crop an
        // og:image to. The 4:5 flier lost its bottom third — the vote URL and the rally
        // copy — in every preview. See FlierService::ogCard().
        $g->get('/vote/{program}/{slug:[0-9]+[^/]*}/card.png',   FlierController::class.':card');
        // Every message of support for one nominee, paginated. Before /{slug} for the
        // same reason the flier routes are: FastRoute matches in declaration order.
        $g->get('/vote/{program}/{slug:[0-9]+[^/]*}/messages',   VoteMessageController::class.':forNominee');
        // Everyone who ticked the box asking to be named. The ballot shows the ten
        // biggest backers and used to end with "and 40 more" leading nowhere.
        $g->get('/vote/{program}/{slug:[0-9]+[^/]*}/supporters', VoteMessageController::class.':supporters');
        $g->get('/vote/{program}/{slug}', VoteController::class.':nominee');

        // ── A MESSAGE OF SUPPORT, AT ITS OWN URL ─────────────────────────────
        //
        // Short on purpose: this link is typed into WhatsApp, pasted into a Facebook
        // post and read aloud. `/m/{token}` survives that; `/vote/message/{token}`
        // does not. It sits OUTSIDE the /vote tree for the same reason — a message
        // outlives the ballot it came from, and the roll of honour keeps linking to
        // it long after voting closes.
        //
        // Why a token instead of an id, and why the page re-checks approval on every
        // read: see VoteMessageController.
        // The card BEFORE the page: FastRoute matches in declaration order, and
        // `/m/{token}` would otherwise swallow nothing here (the patterns differ) but
        // the ordering convention in this file is worth keeping — see the flier routes
        // above for the case where it genuinely mattered.
        $g->get('/m/{token:[A-Za-z0-9_-]{16,32}}/card.png', VoteMessageController::class.':card');
        $g->get('/m/{token:[A-Za-z0-9_-]{16,32}}', VoteMessageController::class.':permalink');
        $g->get('/partner',       PartnerController::class.':form');
        $g->post('/partner',      PartnerController::class.':submit');
        $g->get('/partner/success',fn($req,$res)=>$tv($req)->render($res,'pages/partner-success.twig',['page_title'=>'Thank You — Africa GATES','meta_description'=>'Thank you for your interest in partnering with Africa GATES. Our team will be in touch to explore how we can champion African excellence together.','gates_page'=>'partner','has_hero'=>false]));

        // ── Shop (storefront + gateway checkout; static routes before {slug}) ──
        $g->get('/shop',           ShopController::class.':index');
        $g->post('/shop/checkout', ShopCheckoutController::class.':checkout');
        // Delivery + a discount code priced before anybody commits. A preview only — the same
        // totals() the real checkout calls, so the two cannot disagree about what is owed.
        $g->post('/shop/quote',    ShopCheckoutController::class.':quote');
        $g->get('/shop/redirect',  ShopCheckoutController::class.':handoff');  // see GatewayHandoff
        $g->get('/shop/callback',  ShopCheckoutController::class.':callback');
        $g->get('/shop/success',   ShopCheckoutController::class.':success');
        // A buyer's own order, reachable with the reference alone — same doctrine as an event
        // ticket. BEFORE `/shop/{slug}`: Slim matches in registration order, so a product
        // called "order" is not what decides this.
        $g->get('/shop/order/{ref:[A-Za-z0-9\-]{8,72}}', ShopCheckoutController::class.':order');
        // One click to come off the back-in-stock list, no account — same doctrine as a ticket.
        // BEFORE `/shop/{slug}`, like the others: Slim matches in registration order.
        $g->get('/shop/back-in-stock/stop/{token:[a-f0-9]{32}}', ShopController::class.':stopAlert');
        $g->get('/shop/{slug}',    ShopController::class.':item');
        $g->post('/shop/{slug}/notify-me', ShopController::class.':notifyMe');

        // ── Payments (Paystack / Flutterwave behind PaymentService) ──────────
        //   /pay/init     first-party form post (CSRF-protected) → hosted checkout
        //   /pay/callback browser return; verified server-side before crediting
        //   /pay/success  read-only confirmation page
        //   /pay/webhook  server-to-server, signature-verified, CSRF-EXEMPT
        $g->post('/pay/init',     PaymentController::class.':init');
        $g->get('/pay/redirect',  PaymentController::class.':handoff');  // see GatewayHandoff
        $g->get('/pay/callback',  PaymentController::class.':callback');
        $g->get('/pay/success',   PaymentController::class.':success');
        $g->post('/pay/webhook',  PaymentController::class.':webhook');

        // ── Donations (free-amount giving via PaymentService) ────────────────
        //   GET  /donate           the giving page
        //   POST /donate           first-party form post (CSRF) → hosted checkout
        //   GET  /donate/callback  browser return; verified server-side
        //   GET  /donate/success   read-only thank-you
        $g->get('/donate',          DonationController::class.':page');
        $g->post('/donate',         DonationController::class.':start');
        $g->get('/donate/redirect', DonationController::class.':handoff');  // see GatewayHandoff
        $g->get('/donate/callback', DonationController::class.':callback');
        $g->get('/donate/success',  DonationController::class.':success');
        // (Paid-voting routes are registered above, before /vote/{program}.)
        // Admin-editable legal/policy docs (gates_legal_docs via LegalService).
        // Content is no longer hardcoded; a missing/unpublished doc → 404.
        $legalRender = function($req,$res,string $slug) use ($tv){
            $doc = \AfricaGates\Services\LegalService::get($slug);
            if (!$doc) return $res->withStatus(404);

            $L     = \AfricaGates\Services\LegalDocument::class;
            $url   = \AfricaGates\Support\SiteUrl::base($req) . '/' . $slug;
            $today = date('Y-m-d');
            $eff   = $L::effectiveDate($doc);

            return $tv($req)->render($res,'pages/legal.twig',[
                'page_title'=>$doc['title'].' — Africa GATES',
                'meta_description'=>'The '.$doc['title'].' for Africa GATES — the continental Cultural Power Index recognising African excellence.',
                'og_type'=>'article',
                'gates_page'=>'legal','has_hero'=>false,
                'breadcrumbs'=>[['label'=>'Home','url'=>'/'],['label'=>$doc['title']]],
                'legal_doc'=>$doc,
                'legal_tabs'=>\AfricaGates\Services\LegalService::published(),

                // ── ONE SOURCE FOR THREE RENDERINGS ──────────────────────────
                // The body the page shows is the body the .txt and .md contain —
                // including the generated AI disclosure, which used to be assembled
                // in the template and would therefore have been missing from every
                // download of the privacy policy. See LegalDocument.
                'legal_body'=>$L::bodyWithAnchors($doc),
                'doc_outline'=>$L::outline($doc),
                'doc_author'=>$L::AUTHOR,
                'doc_publisher'=>$L::PUBLISHER,
                'doc_effective'=>$eff,
                'doc_url'=>$url,
                'doc_accessed'=>$today,
                'doc_citations'=>$L::citations($doc, $url, $today),
                'doc_file_stem'=>$L::fileStem($doc),
                'doc_txt_url'=>'/'.$slug.'/download/txt',
                'doc_md_url'=>'/'.$slug.'/download/md',
                'doc_standfirst'=>($doc['updated_label'] ?? '') !== ''
                    ? 'Last updated '.$doc['updated_label'].', and effective immediately. Written to be read — if any part of it is unclear, that is a fault worth reporting.'
                    : 'Effective immediately. Written to be read — if any part of it is unclear, that is a fault worth reporting.',
            ]);
        };

        /**
         * A legal document as a file.
         *
         * Same two formats and the same `Content-Disposition: attachment` as the
         * philosophy, from the same body the page renders. A policy is the document
         * people most reasonably want to keep a copy of, and "keep a copy" has to mean
         * the copy they were actually shown.
         */
        $legalFile = function($req,$res,string $slug,string $fmt){
            $doc = \AfricaGates\Services\LegalService::get($slug);
            if (!$doc) return $res->withStatus(404);

            $L    = \AfricaGates\Services\LegalDocument::class;
            $url  = \AfricaGates\Support\SiteUrl::base($req) . '/' . $slug;
            $body = $fmt === 'md' ? $L::markdown($doc, $url) : $L::plainText($doc, $url);

            $res->getBody()->write($body);
            return $res
                ->withHeader('Content-Type', $fmt === 'md' ? 'text/markdown; charset=utf-8' : 'text/plain; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="'.$L::fileStem($doc).'.'.$fmt.'"')
                // Revised rarely, and the Copy button fetches the .txt on every click.
                ->withHeader('Cache-Control', 'public, max-age=3600');
        };

        $g->get('/privacy', fn($req,$res)=>$legalRender($req,$res,'privacy'));
        $g->get('/terms',   fn($req,$res)=>$legalRender($req,$res,'terms'));
        // Downloads. Declared BEFORE the {slug} routes below, because Slim's default
        // placeholder matches any run of non-slash characters — `/legal/{slug}` would
        // happily swallow `privacy.txt` as a slug and render the page instead of
        // serving the file.
        // Extensionless first — see the note on /philosophy/download for why an
        // extension-shaped URL is the wrong thing to depend on. Declared ahead of
        // /terms/{slug} and /legal/{slug} so "download" cannot be read as a slug.
        $g->get('/terms/download/{fmt:txt|md}',   fn($req,$res,$args)=>$legalFile($req,$res,'terms',(string)$args['fmt']));
        $g->get('/privacy/download/{fmt:txt|md}', fn($req,$res,$args)=>$legalFile($req,$res,'privacy',(string)$args['fmt']));
        $g->get('/cookies/download/{fmt:txt|md}', fn($req,$res,$args)=>$legalFile($req,$res,'cookies',(string)$args['fmt']));
        $g->get('/legal/{slug}/download/{fmt:txt|md}', fn($req,$res,$args)=>$legalFile($req,$res,strtolower((string)($args['slug']??'')),(string)$args['fmt']));
        $g->get('/privacy.txt', fn($req,$res)=>$legalFile($req,$res,'privacy','txt'));
        $g->get('/privacy.md',  fn($req,$res)=>$legalFile($req,$res,'privacy','md'));
        $g->get('/terms.txt',   fn($req,$res)=>$legalFile($req,$res,'terms','txt'));
        $g->get('/terms.md',    fn($req,$res)=>$legalFile($req,$res,'terms','md'));
        $g->get('/cookies.txt', fn($req,$res)=>$legalFile($req,$res,'cookies','txt'));
        $g->get('/cookies.md',  fn($req,$res)=>$legalFile($req,$res,'cookies','md'));
        $g->get('/legal/{slug}.txt', fn($req,$res,$args)=>$legalFile($req,$res,strtolower((string)($args['slug']??'')),'txt'));
        $g->get('/legal/{slug}.md',  fn($req,$res,$args)=>$legalFile($req,$res,strtolower((string)($args['slug']??'')),'md'));
        $g->get('/legal/{slug}', fn($req,$res,$args)=>$legalRender($req,$res,strtolower((string)($args['slug']??''))));
        // Per-programme terms (admin-editable). Unknown slug falls back to the general terms.
        $g->get('/terms/{slug}', function($req,$res,$args) use ($tv){
            $p = \Illuminate\Database\Capsule\Manager::table('gates_award_programmes')->where('slug',(string)($args['slug']??''))->where('is_active',1)->first();
            if (!$p) return $res->withHeader('Location','/terms')->withStatus(302);
            return $tv($req)->render($res,'pages/programme-terms.twig',[
                'page_title'=>$p->title.' — Terms — Africa GATES',
                'meta_description'=>'The terms for the '.$p->title.' programme on Africa GATES — eligibility, voting and nomination rules.',
                'gates_page'=>'legal','has_hero'=>false,'programme'=>(array)$p,
            ]);
        });
        $g->get('/cookies', fn($req,$res)=>$legalRender($req,$res,'cookies'));
        // ── THE PAGE HAS TO READ THE ENGINE, NOT REMEMBER IT ─────────────────
        //
        // These numbers were prose. The route passed no data at all, so
        // "45% public + 55% judges" was a sentence somebody typed, and
        // RuleEngine lets an operator change the real weights per programme
        // and per cycle. The two could drift apart with nothing to notice —
        // and of all the pages on this site, the METHODOLOGY page is the one
        // that must not describe a system the code is not running.
        //
        // Read from the same RuleEngine the scorer uses, so the published
        // claim cannot become false without the published claim changing.
        //
        // ── AND WHY IT IS A CLOSURE ─────────────────────────────────────────
        //
        // Three routes now publish this document — the article, the .txt and the
        // .md — and a download that disagrees with the page it came from is worse
        // than no download at all. Resolving the figures ONCE, here, is what makes
        // "the download is the page" true by construction rather than by care.
        $integrityFigures = static function (): array {
            $rules = new \AfricaGates\Services\RuleEngine();
            $w     = $rules->weights();
            $eff   = $rules->effective();

            // The community-return figures, shaped for publishing by the service that
            // enforces them — the same call the Help Centre articles make, so the
            // summary here and the deep dive there cannot format one setting two ways.
            $ret = \AfricaGates\Services\CommunityReturnService::displayRules($eff);

            return [
                'community_pct'    => (int) round($w['community'] * 100),
                'judge_pct'        => (int) round($w['judge'] * 100),
                'paid_cap_pct'     => (int) ($eff['max_paid_weight_pct'] ?? 50),
                'min_judges'       => (int) ($eff['min_judges_per_nominee'] ?? 2),
                'fraud_block'      => (int) ($eff['fraud_block'] ?? 80),
                'fraud_flag'       => (int) ($eff['fraud_flag'] ?? 60),
                'fraud_monitor'    => (int) ($eff['fraud_monitor'] ?? 30),
                'return_pct'       => $ret['pct'],
                'return_on'        => $ret['on'],
                'return_threshold' => $ret['threshold'],
                'return_cap_pct'   => $ret['cap_pct'],
                'return_cap_votes' => $ret['cap_votes'],
                'return_people'    => $ret['min_supporters'],
            ];
        };

        /**
         * The philosophy document as a file.
         *
         * Two formats because the two audiences want different things: `.md` keeps the
         * headings and quotes for anyone republishing or quoting it, `.txt` is what
         * pastes cleanly into an email or a court filing. Both come from the same
         * structure as the page, so there is no third copy to fall out of date.
         *
         * `Content-Disposition: attachment` is the point of the route — a browser that
         * renders Markdown inline would give the Download button nothing to download.
         */
        $integrityFile = static function ($req, $res, string $fmt) use ($integrityFigures) {
            $doc  = \AfricaGates\Services\CommunityVotingPhilosophy::class;
            $figs = $integrityFigures();
            $url  = \AfricaGates\Support\SiteUrl::base($req) . $doc::PATH;

            $body = $fmt === 'md'
                ? $doc::markdown($figs, $url)
                : $doc::plainText($figs, $url);

            $res->getBody()->write($body);
            return $res
                ->withHeader('Content-Type', $fmt === 'md'
                    ? 'text/markdown; charset=utf-8'
                    : 'text/plain; charset=utf-8')
                ->withHeader(
                    'Content-Disposition',
                    'attachment; filename="' . $doc::fileStem() . '.' . $fmt . '"'
                )
                // The document changes only when an operator changes a programme
                // setting, so an hour of caching is safe and keeps the Copy button
                // (which fetches the .txt) off the database on every click.
                ->withHeader('Cache-Control', 'public, max-age=3600');
        };
        // ── EXTENSIONLESS FIRST, DOTTED AS AN ALIAS ─────────────────────────
        //
        // `/philosophy.txt` was the only address, and it was reported as not working
        // on the live host while serving fine locally. An extension-shaped URL is at
        // the mercy of the web server before PHP ever sees it: MultiViews and
        // mod_negotiation, static-file handlers, and this project's own root
        // .htaccess — which denies whole extension classes by FilesMatch and remaps
        // everything into public/ — all get a say. None of that is visible from here,
        // and none of it can touch a path with no extension.
        //
        // So the canonical download paths have no extension. The dotted ones stay
        // registered because they have been published in the deploy notes and may
        // already be shared, and because if they DO work on a given host there is no
        // reason to break them.
        $g->get('/philosophy/download/{fmt:txt|md}', fn($req, $res, $args) => $integrityFile($req, $res, (string) $args['fmt']));
        $g->get('/philosophy.txt', fn($req, $res) => $integrityFile($req, $res, 'txt'));
        $g->get('/philosophy.md',  fn($req, $res) => $integrityFile($req, $res, 'md'));
        // The document lived at /integrity until it earned its own page. Anything
        // already published — a citation, a shared link, a saved download — must keep
        // resolving, so the old addresses redirect permanently rather than 404.
        $g->get('/integrity.txt', fn($req, $res) => $res->withHeader('Location', '/philosophy.txt')->withStatus(301));
        $g->get('/integrity.md',  fn($req, $res) => $res->withHeader('Location', '/philosophy.md')->withStatus(301));

        /**
         * The philosophy, in full.
         *
         * /integrity carries the précis and links here; this page carries the argument.
         * Splitting them is what stopped sixteen sections of Ubuntu sitting between a
         * reader arriving from a ballot and the answer to "how is a winner decided".
         */
        $g->get('/philosophy', function ($req, $res) use ($tv, $integrityFigures) {
            $doc   = \AfricaGates\Services\CommunityVotingPhilosophy::class;
            $figs  = $integrityFigures();
            $url   = \AfricaGates\Support\SiteUrl::base($req) . $doc::PATH;
            $today = date('Y-m-d');

            return $tv($req)->render($res, 'pages/philosophy.twig', $figs + [
                'page_title'       => 'The philosophy behind Africa GATES community voting',
                'meta_description' => sprintf(
                    'Why Africa GATES voting carries a token contribution, what a vote actually '
                    . 'represents, and why the public vote is deliberately limited to %d%% of the outcome.',
                    (int) $figs['community_pct']
                ),
                'og_type'          => 'article',
                'og_title'         => 'Reimagining recognition through communal spirit',
                'breadcrumbs'      => [
                    ['label' => 'Home', 'url' => '/'],
                    ['label' => 'Integrity Centre', 'url' => '/integrity'],
                    ['label' => 'Philosophy'],
                ],
                'gates_page'       => 'philosophy',
                'has_hero'         => false,
                'current_section'  => 'projects',
                'doc_sections'     => $doc::sections($figs),
                'doc_standfirst'   => $doc::standfirst($figs),
                'doc_title'        => $doc::TITLE,
                'doc_subtitle'     => $doc::SUBTITLE,
                'doc_author'       => $doc::AUTHOR,
                'doc_publisher'    => $doc::PUBLISHER,
                'doc_version'      => $doc::VERSION,
                'doc_published'    => $doc::PUBLISHED,
                'doc_updated'      => $doc::UPDATED,
                'doc_read_minutes' => $doc::readMinutes($figs),
                'doc_url'          => $url,
                'doc_accessed'     => $today,
                'doc_citations'    => $doc::citations($url, $today),
                'doc_file_stem'    => $doc::fileStem(),
            ]);
        });

        $g->get('/integrity', function ($req, $res) use ($tv, $integrityFigures) {
            $phil  = \AfricaGates\Services\CommunityVotingPhilosophy::class;
            $meth  = \AfricaGates\Services\MethodologyDocument::class;
            $figs  = $integrityFigures();
            $base  = \AfricaGates\Support\SiteUrl::base($req);
            $url   = $base . $meth::PATH;
            // One clock reading for the whole request: the visible "accessed" date and
            // the citation strings must not disagree because two callers each asked the
            // system what day it is.
            $today = date('Y-m-d');

            $cPct = (int) $figs['community_pct'];
            $jPct = (int) $figs['judge_pct'];

            return $tv($req)->render($res, 'pages/integrity.twig', $figs + [
                // ── SEO ──────────────────────────────────────────────────────
                // The title leads with the QUESTION people search, not with the
                // internal name of the page. Nobody types "awards integrity and
                // methodology"; they type "how is the winner decided".
                'page_title'       => 'How the Cultural Power Index is scored — Africa GATES',
                // Under 155 characters, and it states the two figures rather than
                // promising them — a description that answers gets the click that a
                // description that teases does not.
                'meta_description' => sprintf(
                    'How Africa GATES decides a winner: %d%% verified community votes, %d%% independent judges, '
                    . 'and why contributions move a public tally but never the score.',
                    $cPct, $jPct
                ),
                'og_type'          => 'article',
                'og_title'         => 'How a winner is decided — and what money cannot do about it',
                'breadcrumbs'      => [
                    ['label' => 'Home', 'url' => '/'],
                    ['label' => 'Integrity Centre'],
                ],
                'gates_page'       => 'integrity',
                'has_hero'         => false,
                'current_section'  => 'projects',

                // ── THE DOCUMENT ─────────────────────────────────────────────
                // Identity from MethodologyDocument: this page is its own citable
                // document, not an appendix to the philosophy, and citing it as the
                // philosophy would send a reader to the wrong title and version.
                'doc_title'        => $meth::TITLE,
                'doc_subtitle'     => $meth::SUBTITLE,
                'doc_author'       => $meth::AUTHOR,
                'doc_publisher'    => $meth::PUBLISHER,
                'doc_version'      => $meth::VERSION,
                'doc_published'    => $meth::PUBLISHED,
                'doc_updated'      => $meth::UPDATED,
                'doc_url'          => $url,
                'doc_accessed'     => $today,
                'doc_citations'    => $meth::citations($url, $today),

                // The philosophy, in précis. Same source as /philosophy, so the two
                // cannot quote different figures; see the class for why it is a
                // separate rendering rather than the first N sections.
                'doc_summary'      => $phil::summary($figs),
                // No reading time. The philosophy can count its own words because it
                // IS its data; this page's prose is in its template, so any figure
                // here would be typed, and a typed reading time is one more number
                // that silently stops being true the next time a section is added.
                // The masthead macro omits the field when it is absent.
            ]);
        });
        $g->get('/support', fn($req,$res)=>$tv($req)->render($res,'pages/support.twig',['page_title'=>'Support & Appeals — Africa GATES','meta_description'=>'Get help with Africa GATES — the CPI, voting, nominations and your profile — and appeal any moderation decision through an independent review.','gates_page'=>'support','has_hero'=>false]));
        // /signin was a non-functional mock (fake success, no auth). Retire it —
        // the real, working member sign-in is /account/login.
        $g->get('/signin',  fn($req,$res)=>$res->withHeader('Location','/account/login')->withStatus(301));
        // Status reflects REAL signals, not a hardcoded "all green": the core
        // features are operational because this DB-backed page is being served at
        // all; payments/email report their actual configuration state.
        $g->get('/status', function($req,$res) use ($tv) {
            $set  = fn(string ...$k) => (bool) array_filter($k, fn($x)=>Env::has($x));
            $pay  = $set('PAYSTACK_SECRET_KEY','FLUTTERWAVE_SECRET_KEY') ? 'Operational' : 'Degraded';
            $mail = $set('SMTP_HOST','MAIL_HOST','MAIL_MAILER','SMTP_USER') ? 'Operational' : 'Degraded';
            $components = [
                ['name'=>'Voting & ballots',      'desc'=>'Vote casting, OTP verification', 'status'=>'Operational'],
                ['name'=>'Leaderboard & CPI',     'desc'=>'Score computation, rankings',    'status'=>'Operational'],
                ['name'=>'Registry & profiles',   'desc'=>'Profile browse and search',      'status'=>'Operational'],
                ['name'=>'Pulse & media',         'desc'=>'Feed, reels, stories',           'status'=>'Operational'],
                ['name'=>'Payments & tickets',    'desc'=>'Checkout, donations, ticketing', 'status'=>$pay],
                ['name'=>'Email & notifications', 'desc'=>'Transactional delivery',         'status'=>$mail],
            ];
            $overall = ($pay==='Operational' && $mail==='Operational') ? 'operational' : 'degraded';
            return $tv($req)->render($res,'pages/status.twig',['page_title'=>'System status — Africa GATES','meta_description'=>'The operational status of Africa GATES — voting, leaderboard, registry, payments and notifications.','gates_page'=>'status','has_hero'=>false,'overall'=>$overall,'components'=>$components]);
        });
        // Support assistant. A SEPARATE path from /support, which is the appeals
        // hub above and stays exactly as it is — registering a second '/support'
        // would have been dead code, because Slim matches the first route and the
        // assistant would never have been reachable at all.
        $g->get('/support/assistant', SupportController::class.':page');
        // Ticket threads. The page redirects a guest to sign-in; the write
        // endpoints refuse one, because a ticket is a promise to reply and a
        // reply needs a verified address.
        $g->get('/support/tickets',   SupportController::class.':tickets');
        // ── ONE TICKET, NO ACCOUNT ──────────────────────────────────────────
        //
        // The rule above is right about members and wrong about everyone else. Paid
        // voting takes an email and a card and creates no account, so the whole
        // unminted-vote incident population was given the repair tools and then no
        // way to answer the reply they got; and the claim rules require a human
        // route that works WITHOUT an account, while the assisted path routes to a
        // ticket the person could not open. A thread the requester cannot reply to
        // is a monologue.
        //
        // The link IS the verified address: it was mailed to the address on the
        // ticket and dies if that address changes. Scoped to one thread, expiring,
        // and unable to list — see TicketLinkService.
        //
        // The 64-hex pattern cannot collide with /support/tickets or any other
        // /support/… path, so registration order here is not load-bearing.
        $g->get('/support/t/{token:[a-f0-9]{64}}',        SupportController::class.':linkedThread');
        $g->post('/support/t/{token:[a-f0-9]{64}}/reply', SupportController::class.':linkedReply');

        // Ticket evidence. The ONLY way these bytes leave the server — the files
        // live outside the document root, so there is no static path to guess.
        // Access is decided per request inside the controller (staff, the member
        // who owns the ticket, or a valid thread token in `?t=`), and a refusal
        // is a 404 because confirming an attachment exists is itself a disclosure.
        $g->get('/support/attachment/{id:[0-9]+}',
                \AfricaGates\Controllers\SupportAttachmentController::class.':show');

        // Nominee page claiming. GUESTS, deliberately: the population is nominees who
        // have never had an account here, so a login wall would gate a page on having
        // the very thing the page exists to give you. Neither POST accepts a
        // destination — only an opaque channel key that must resolve to a contact
        // already on an approved nomination. See ClaimController and
        // docs/CLAIM-FAIRNESS-AND-FRAUD.md §2.
        // BEFORE /claim/{id}: "dispute" is not a number, so the patterns cannot collide
        // — but the ordering convention in this file is worth keeping.
        //
        // ── "THIS WAS NOT ME", IN ONE TAP ────────────────────────────────────
        //
        // Reached from the link in every claim notification. GET renders a confirm page
        // and POST performs the freeze, and that split is not ceremony: Gmail, Outlook
        // and every link-safety scanner FETCH the URLs in a message before a human sees
        // them, so a freeze on GET would fire automatically on a large share of honest
        // claims with nothing in the log to explain it. See ClaimDispute.
        $g->get('/claim/dispute/{token:[a-f0-9]{32}}',  ClaimController::class.':disputePage');
        $g->post('/claim/dispute/{token:[a-f0-9]{32}}', ClaimController::class.':disputeFreeze');

        $g->get('/claim/{id:[0-9]+}',          ClaimController::class.':page');
        $g->post('/claim/{id:[0-9]+}/code',    ClaimController::class.':send');
        $g->post('/claim/{id:[0-9]+}/confirm', ClaimController::class.':confirm');

        // ── THE NOMINEE'S OWN QUESTIONNAIRE ──────────────────────────────────
        //
        // Third page on this platform with a token as its whole credential, after the claim
        // link and the interview link, for the same reason each time: a nominee has no
        // account, and requiring one to describe their own work would shut out exactly the
        // population the awards exist to find.
        //
        // Uploads are their own POST: a rejected file must not cost a page of typing.
        $g->get('/my-work/{token:[a-f0-9]{32}}',         \AfricaGates\Controllers\MyWorkController::class.':page');
        $g->post('/my-work/{token:[a-f0-9]{32}}',        \AfricaGates\Controllers\MyWorkController::class.':save');
        $g->post('/my-work/{token:[a-f0-9]{32}}/upload', \AfricaGates\Controllers\MyWorkController::class.':upload');
        // The conversational route. JSON, because a page reload between typing an answer and
        // being asked the next question loses the thread — and the answer is already stored
        // by the time this responds, so a dropped connection costs the reply, not the work.
        $g->post('/my-work/{token:[a-f0-9]{32}}/chat',   \AfricaGates\Controllers\MyWorkController::class.':chat');
        // Voice, both directions. `speak` returns MP3 bytes and is addressed by TURN INDEX,
        // never by text, so it cannot be used to have the platform's ElevenLabs account read
        // out a stranger's paragraph; `listen` transcribes a recording and hands the words
        // back to the PAGE for the nominee to confirm, because an answer stored against
        // somebody's name has to be one they approved.
        $g->post('/my-work/{token:[a-f0-9]{32}}/speak',  \AfricaGates\Controllers\MyWorkController::class.':speak');
        $g->post('/my-work/{token:[a-f0-9]{32}}/listen', \AfricaGates\Controllers\MyWorkController::class.':listen');
        // ── THE BRIEF, AND A MINUTE IN THEIR OWN VOICE ───────────────────────
        //
        // `ready` records that somebody read what is expected of them; the questions do not
        // start before it, and it is a POST because a mail scanner fetching every URL in a
        // message must not be able to make that record on their behalf.
        //
        // `intro` stores a spoken introduction — and unlike a spoken ANSWER, that recording is
        // KEPT: it is the artefact a judge is meant to hear rather than a way of typing. The
        // file lives outside the web root and `intro.audio` streams it, so a nominee can play
        // back their own recording without an account and nothing sits behind an unlisted URL.
        $g->post('/my-work/{token:[a-f0-9]{32}}/ready',  \AfricaGates\Controllers\MyWorkController::class.':ready');
        // Help WHILE an answer is being written rather than a verdict after it is finished.
        // Writes nothing, and resolves the question from the submission's own applicable set
        // rather than from anything the caller sends — otherwise it is a free language-model
        // endpoint on the operator's key.
        $g->post('/my-work/{token:[a-f0-9]{32}}/coach',  \AfricaGates\Controllers\MyWorkController::class.':coach');
        $g->post('/my-work/{token:[a-f0-9]{32}}/intro',  \AfricaGates\Controllers\MyWorkController::class.':intro');
        $g->get('/my-work/{token:[a-f0-9]{32}}/intro.audio', \AfricaGates\Controllers\MyWorkController::class.':introAudio');

        // ── THE NOMINEE'S OWN INTERVIEW PAGE ─────────────────────────────────
        //
        // Guest-accessible with a 32-hex token as the whole credential, because a nominee
        // has no account and demanding they make one to attend their own interview would
        // gate the appointment on the thing the appointment leads to.
        //
        // GET renders and writes nothing; confirming, consenting and declining are all
        // POST — same reasoning as the freeze link two blocks up. A mail scanner that
        // fetches every URL in a message must not be able to record a person's permission
        // to be recorded.
        $g->get('/interview/{token:[a-f0-9]{32}}',  \AfricaGates\Controllers\InterviewController::class.':page');
        $g->post('/interview/{token:[a-f0-9]{32}}', \AfricaGates\Controllers\InterviewController::class.':submit');

        // The Help Centre. One URL per answer, so support can paste one into a
        // reply, the assistant can cite one, a receipt email can point at the
        // exact paragraph and a search engine can index it — none of which a
        // single page of accordions could do. See HelpController.
        $g->get('/help', HelpController::class . ':index');
        // Every answer in one category. BEFORE the article route: both patterns
        // exclude slashes, so `c/payments` could never match {slug} anyway, but the
        // ordering is the thing that keeps that true if either pattern is ever
        // loosened. A category has its own page because without one the index had
        // to print all 33 answers inline — see HelpController::category().
        $g->get('/help/c/{cat:[a-z0-9-]+}', HelpController::class . ':category');
        // Registered after the index, and the pattern excludes slashes, so it can
        // never shadow a deeper /help/... path added later.
        $g->get('/help/{slug:[a-z0-9-]+}', HelpController::class . ':article');

        // SEO: robots.txt + sitemap.xml
        $g->get('/robots.txt', function($req, $res) {
            $scheme = $req->getUri()->getScheme();
            $host = $req->getUri()->getHost();
            $port = $req->getUri()->getPort();
            $base = $scheme . '://' . $host . ($port && !in_array($port, [80, 443], true) ? ':' . $port : '');
            $body = "User-agent: *\n"
                  . "Allow: /\n"
                  . "Disallow: /admin\n"
                  . "Disallow: /admin/\n"
                  . "Disallow: /judge\n"
                  . "Disallow: /judge/\n"
                  . "Disallow: /api/\n"
                  . "\n"
                  . "Sitemap: {$base}/sitemap.xml\n";
            $res->getBody()->write($body);
            return $res->withHeader('Content-Type', 'text/plain; charset=utf-8');
        });
        $g->get('/sitemap.xml', function($req, $res) {
            $scheme = $req->getUri()->getScheme();
            $host = $req->getUri()->getHost();
            $port = $req->getUri()->getPort();
            $base = $scheme . '://' . $host . ($port && !in_array($port, [80, 443], true) ? ':' . $port : '');
            $today = date('Y-m-d');
            $paths = [
                ['/', '1.0', 'daily'],
                ['/awards', '0.9', 'weekly'],
                ['/vote', '0.9', 'daily'],
                ['/nominate', '0.9', 'weekly'],
                ['/leaderboard', '0.9', 'daily'],
                ['/registry', '0.8', 'daily'],
                ['/legacy', '0.7', 'weekly'],
                ['/events', '0.7', 'weekly'],
                ['/blog', '0.6', 'weekly'],
                ['/opportunities', '0.7', 'weekly'],
                ['/community', '0.6', 'weekly'],
                ['/partner', '0.6', 'monthly'],
                ['/register', '0.5', 'monthly'],
                ['/privacy', '0.3', 'yearly'],
                ['/terms', '0.3', 'yearly'],
            ];
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                 . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
            foreach ($paths as [$p, $pri, $freq]) {
                $xml .= "  <url>\n"
                      . "    <loc>{$base}{$p}</loc>\n"
                      . "    <lastmod>{$today}</lastmod>\n"
                      . "    <changefreq>{$freq}</changefreq>\n"
                      . "    <priority>{$pri}</priority>\n"
                      . "  </url>\n";
            }
            $xml .= "</urlset>\n";
            $res->getBody()->write($xml);
            return $res->withHeader('Content-Type', 'application/xml; charset=utf-8');
        });
        // ── API (versioned) ──────────────────────────────────────────────────
        // The same handlers are mounted under BOTH /api/v1 (canonical) and /api
        // (legacy alias == v1) so existing first-party callers keep working while
        // integrations + AI agents can pin a version. Every response carries an
        // X-API-Version header (see ApiVersionMiddleware).
        $apiRoutes = function(RouteCollectorProxy $a) {
            $a->get('/registry',         ApiController::class.':registry');
            $a->get('/registry/{slug}',  ApiController::class.':profileBySlug');
            $a->get('/awards',           ApiController::class.':awardsIndex');
            $a->get('/nominees',         ApiController::class.':nominees');
            $a->post('/otp/request',     ApiController::class.':otpRequest');
            $a->post('/vote',            ApiController::class.':castVote');
            $a->post('/funnel',          ApiController::class.':trackFunnel');
            // Messages of support. A FREE voter's message rides along with the vote
            // itself (POST /vote), because the OTP in that same request is what proves
            // who they are. This endpoint is for the PAID path, where the checkout has
            // been to a bank and back and the payment reference is the proof. See
            // VoteMessageController::post().
            $a->post('/vote-message',        VoteMessageController::class.':post');
            $a->post('/vote-message/cheer',  VoteMessageController::class.':cheer');
            // Open to anyone, on purpose: the reader who most needs to report something
            // about a named child is a stranger who followed a WhatsApp link and has no
            // account here. Rate-limited per network per message. See the controller.
            $a->post('/vote-message/report', VoteMessageController::class.':report');
            $a->post('/nominations/draft', ApiController::class.':saveDraft');
            $a->get('/nominations/draft',  ApiController::class.':loadDraft');
            $a->post('/nominations/share-link', ApiController::class.':createShareLink');
            $a->post('/nominations/polish',     ApiController::class.':polishStory');
            $a->post('/nominations/suggest-category', ApiController::class.':suggestCategory');
            $a->get('/leaderboard',      ApiController::class.':leaderboard');
            $a->get('/dashboard',        ApiController::class.':dashboard');
            $a->get('/map-pins',         ApiController::class.':mapPins');
            $a->get('/legacy',           ApiController::class.':legacy');
            $a->get('/opportunities',    ApiController::class.':opportunities');
            $a->post('/nominations',     ApiController::class.':submitNomination');
            $a->post('/register',        ApiController::class.':register');
            $a->post('/newsletter/subscribe', ApiController::class.':newsletterSubscribe');
            // Community endpoints
            $a->post('/community/comment',  CommunityController::class.':comment');
            $a->post('/community/cheer',    CommunityController::class.':cheer');
            $a->post('/community/poll',     CommunityController::class.':pollVote');
            $a->post('/community/report',   CommunityController::class.':report');
            $a->post('/community/delete',   CommunityController::class.':deleteOwn');
            $a->post('/community/summarize',CommunityController::class.':summarize');
            $a->post('/community/assist',   CommunityController::class.':assist');
            $a->post('/community/follow',   CommunityController::class.':follow');
            $a->post('/community/bookmark', CommunityController::class.':bookmark');
            $a->post('/community/repost',   CommunityController::class.':repost');
            $a->get('/community/activity',  CommunityController::class.':activity');
            // Pulse feed: page N of the infinite scroll, and the new-posts pill count.
            // Public, because reading Pulse is public. The per-viewer fields
            // (cheered/saved/is_mine) come from the SESSION, never a parameter, so
            // one reader cannot ask the server what another reader has liked.
            $a->get('/pulse/feed',          PulseController::class.':feed');
            $a->get('/pulse/new',           PulseController::class.':feedNew');
            // Alerts. Members only, and scoped from the SESSION inside
            // AlertService — no parameter here names a member, so one reader
            // cannot ask the server what happened to another reader's posts.
            $a->get('/pulse/alerts',        PulseController::class.':alerts');
            $a->get('/pulse/alerts/count',  PulseController::class.':alertCount');
            $a->post('/pulse/alerts/read',  PulseController::class.':alertsRead');
            // Support assistant. Both are same-origin POSTs (the /api/ CSRF rule),
            // and neither accepts an identity — see SupportController.
            $a->post('/support/chat',       SupportController::class.':chat');
            $a->post('/support/escalate',   SupportController::class.':escalate');
            $a->post('/support/ticket',     SupportController::class.':ticketCreate');
            $a->post('/support/reply',      SupportController::class.':ticketReply');
            // Gee — the page-aware AI guide
            $a->post('/guide',              GuideController::class.':chat');
            // Inbound Make.com agent bridge — bearer-authenticated, 404 until configured.
            $a->post('/agent/gee',          GuideController::class.':agent');
            // ── LIVE INTERVIEW CAPTURE (the browser extension) ───────────────
            //
            // Not under /admin, because the caller is an extension service worker inside a
            // Meet tab: no admin cookie is sent (SameSite=Lax, deliberately unchanged) and
            // its Origin is chrome-extension://…, which can never be same-origin.
            //
            // Authenticated by a per-sitting live token checked in InterviewLive, and
            // CSRF-exempt for the same reason /agent/gee is. Three verbs, one sitting, and
            // no parameter that can name a different interview.
            $a->post('/interview/live/hello',  \AfricaGates\Controllers\InterviewLiveController::class.':hello');
            $a->post('/interview/live/say',    \AfricaGates\Controllers\InterviewLiveController::class.':say');
            $a->post('/interview/live/finish', \AfricaGates\Controllers\InterviewLiveController::class.':finish');
        };
        $g->group('/api/v1', $apiRoutes)->add(new ApiVersionMiddleware('1'));
        $g->group('/api',    $apiRoutes)->add(new ApiVersionMiddleware('1', true));

        // Gated single-use forms (verified nominees + invited judges)
        $g->get('/form/{token}',             GatedFormController::class.':show');
        $g->post('/form/{token}',            GatedFormController::class.':submit');

        // Admin-built public forms (form builder) — /f/{key}
        $g->get('/f/{key}',                  FormController::class.':show');
        $g->post('/f/{key}',                 FormController::class.':submit');

        // Public community surfaces (forum)
        $g->get('/community',                CommunityController::class.':threadsIndex');
        $g->get('/community/new',            CommunityController::class.':threadNew');
        $g->post('/community/new',           CommunityController::class.':threadCreate');
        $g->get('/community/{slug}',         CommunityController::class.':threadShow');
    });

    // ═══ JUDGES ═══════════════════════════════════════════════════════
    $app->group('/judge', function (RouteCollectorProxy $j) {
        $j->get('/login',              JudgeAuthController::class.':loginForm');
        $j->post('/login/request',     JudgeAuthController::class.':loginRequest');
        $j->post('/login/verify',      JudgeAuthController::class.':loginVerify');
        $j->get('/logout',             JudgeAuthController::class.':logout');
        $j->post('/logout',            JudgeAuthController::class.':logout');

        $j->get('[/]',                 JudgeBallotController::class.':dashboard');
        $j->get('/ballot',             JudgeBallotController::class.':ballot');
        $j->get('/ballot/{programmeId:[0-9]+}', JudgeBallotController::class.':ballot');
        $j->post('/score/{nomineeId:[0-9]+}',   JudgeBallotController::class.':saveScore');
        $j->post('/conflict/{programmeId:[0-9]+}', JudgeBallotController::class.':declareConflict');
        $j->post('/conflict/{programmeId:[0-9]+}/withdraw', JudgeBallotController::class.':withdrawConflict');
    })->add(new JudgeAuthMiddleware());

    // ═══ MEMBER ACCOUNTS ══════════════════════════════════════════════
    $app->group('/account', function (RouteCollectorProxy $a) {
        $a->get('/login',         AccountController::class.':loginForm');
        $a->post('/login',        AccountController::class.':loginSubmit');
        $a->post('/login/otp',    AccountController::class.':otpRequest');
        $a->post('/login/verify', AccountController::class.':otpVerify');
        $a->get('/register',      AccountController::class.':registerForm');
        $a->post('/register',     AccountController::class.':registerSubmit');
        $a->get('/verify',         AccountController::class.':verifyEmail');
        $a->post('/verify/resend', AccountController::class.':resendVerification');
        $a->get('/logout',        AccountController::class.':logout');
        $a->post('/logout',       AccountController::class.':logout');
        $a->post('/redeem',       AccountController::class.':redeem');
        $a->post('/profile',      AccountController::class.':profileUpdate');
        $a->get('[/]',            AccountController::class.':dashboard');
    })->add(new UserAuthMiddleware());

    // ═══ ADMIN ═══════════════════════════════════════════════════════
    $app->group('/admin', function (RouteCollectorProxy $a) {
        // Unauthenticated
        $a->get('/login',           AdminAuthController::class.':loginForm');
        $a->post('/login/submit',   AdminAuthController::class.':loginSubmit');
        $a->get('/magic',           AdminAuthController::class.':magicForm');
        $a->post('/magic/request',  AdminAuthController::class.':magicRequest');
        $a->get('/magic/consume',   AdminAuthController::class.':magicConsume');
        $a->get('/logout',          AdminAuthController::class.':logout');
        $a->post('/logout',         AdminAuthController::class.':logout');

        // Authenticated
        $a->get('[/]',              AdminDashboardController::class.':index');
        $a->get('/dashboard',       AdminDashboardController::class.':index');
        $a->get('/integrity-brief', AdminDashboardController::class.':integrityBrief');

        $a->get('/profiles',        AdminProfilesController::class.':index');
        $a->post('/profiles/merge',   AdminProfilesController::class.':merge');
        $a->post('/profiles/unmerge', AdminProfilesController::class.':unmerge');
        $a->get('/profiles/{id:[0-9]+}',          AdminProfilesController::class.':edit');
        $a->post('/profiles/{id:[0-9]+}',         AdminProfilesController::class.':update');
        $a->post('/profiles/{id:[0-9]+}/{action}', AdminProfilesController::class.':action');

        $a->get('/nominations',     AdminNominationsController::class.':index');
        // Review DESK — high-volume queue walker ({id} below is digit-constrained, no conflict)
        $a->post('/nominations/ai-filter', AdminNominationsController::class.':aiFilter');
        $a->get('/nominations/review', AdminNominationsController::class.':desk');
        $a->get('/nominations/review/next', AdminNominationsController::class.':deskFragment');
        $a->get('/nominations/{id:[0-9]+}',           AdminNominationsController::class.':review');
        $a->post('/nominations/{id:[0-9]+}/suggest-reason', AdminNominationsController::class.':suggestReason');
        $a->post('/nominations/{id:[0-9]+}/ai-insight', AdminNominationsController::class.':aiInsight');
        $a->post('/nominations/{id:[0-9]+}/regenerate-form', AdminNominationsController::class.':regenerateForm');
        $a->post('/nominations/{id:[0-9]+}/{action}', AdminNominationsController::class.':action');

        // Community moderation queue (release/remove auto-quarantined posts)
        $a->get('/moderation',                                       AdminModerationController::class.':index');
        $a->post('/moderation/{type}/{id:[0-9]+}/{decision}',        AdminModerationController::class.':action');
        // Thread operator controls: lock/unlock (readable, no replies) + pin/unpin
        $a->post('/moderation/thread/{id:[0-9]+}/flag/{flag}/{on:[01]}', AdminModerationController::class.':threadFlag');
        // SUPPORT QUEUE. Tickets have always been stored here and answered in
        // EMAIL — so a reply from an inbox never reached the member's own thread,
        // nothing was ever closed, and `is_internal` existed with no way to write
        // to it. This moves the workflow to where the record already is.
        // VOTE DELIVERY — the proof and the repair, in a browser. There is no SSH
        // on this deployment, so `votes:proof` and `votes:remint` were unreachable
        // by the only person who needed them. Same engine, same check-then-apply
        // shape as reconciliation.
        // REFUNDS. Verdict first, button second — the commonest request here is an
        // abandoned checkout whose bank hold looks exactly like a charge, and
        // paying that out is money leaving for nothing. See RefundDecision.
        $a->get('/refunds',        \AfricaGates\Admin\Controllers\RefundsController::class.':index');
        $a->post('/refunds/issue', \AfricaGates\Admin\Controllers\RefundsController::class.':issue');
        $a->get('/vote-delivery',          \AfricaGates\Admin\Controllers\VoteDeliveryController::class.':index');
        $a->post('/vote-delivery/deliver', \AfricaGates\Admin\Controllers\VoteDeliveryController::class.':deliver');
        $a->get('/support',                          \AfricaGates\Admin\Controllers\SupportController::class.':index');
        $a->get('/support/{ref:[A-Za-z0-9\-]+}',     \AfricaGates\Admin\Controllers\SupportController::class.':show');
        $a->post('/support/{ref:[A-Za-z0-9\-]+}/reply', \AfricaGates\Admin\Controllers\SupportController::class.':reply');
        $a->post('/support/{ref:[A-Za-z0-9\-]+}/status/{to}', \AfricaGates\Admin\Controllers\SupportController::class.':status');
        // AI assistant — console copilot (all roles; superadmin unlimited)
        $a->get('/assistant',       \AfricaGates\Admin\Controllers\AssistantController::class.':index');
        $a->post('/assistant/chat', \AfricaGates\Admin\Controllers\AssistantController::class.':chat');

        $a->get('/programmes',                       AdminProgrammesController::class.':index');
        $a->get('/programmes/new',                   AdminProgrammesController::class.':form');
        $a->post('/programmes/new',                  AdminProgrammesController::class.':save');
        $a->get('/programmes/{id:[0-9]+}',           AdminProgrammesController::class.':form');
        $a->post('/programmes/{id:[0-9]+}',          AdminProgrammesController::class.':save');
        $a->get('/programmes/{id:[0-9]+}/cycle',     AdminProgrammesController::class.':cycleEdit')->add(new RoleMiddleware('superadmin'));
        $a->post('/programmes/{id:[0-9]+}/cycle',    AdminProgrammesController::class.':cycleSave')->add(new RoleMiddleware('superadmin'));
        $a->post('/programmes/{id:[0-9]+}/categories', AdminProgrammesController::class.':categorySave');
        $a->post('/categories/{catId:[0-9]+}/delete', AdminProgrammesController::class.':categoryDelete');

        // Editorial copy for the public /awards page (programme cards stay live data).
        $a->get('/awards-page',  AdminAwardsPageController::class.':form');
        $a->post('/awards-page', AdminAwardsPageController::class.':save');

        // Media library — review & remove uploaded images/documents.
        $a->get('/media',                     AdminMediaController::class.':index');
        $a->get('/media/{id:[0-9]+}/view',    AdminMediaController::class.':view');
        $a->post('/media/{id:[0-9]+}/delete', AdminMediaController::class.':delete');
        // One batch of the local → Cloudinary sweep. POST because it writes; the page it
        // returns to continues itself while work remains. See MediaController::migrate().
        $a->post('/media/cloudinary',         AdminMediaController::class.':migrate');
        // Legal & policy documents (editable — no longer hardcoded)
        $a->get('/legal',                          \AfricaGates\Admin\Controllers\LegalController::class.':index');
        $a->get('/legal/{slug:[a-z0-9-]+}',        \AfricaGates\Admin\Controllers\LegalController::class.':edit');
        $a->post('/legal/{slug:[a-z0-9-]+}',       \AfricaGates\Admin\Controllers\LegalController::class.':save');
        $a->post('/legal/{slug:[a-z0-9-]+}/delete',\AfricaGates\Admin\Controllers\LegalController::class.':delete');
        // Shared admin AI helpers (drafting + form-schema generation)
        $a->post('/ai/assist',      \AfricaGates\Admin\Controllers\AiAssistController::class.':assist');
        $a->post('/ai/form-fields', \AfricaGates\Admin\Controllers\AiAssistController::class.':formFields');

        // Shop — product catalogue CRUD.
        $a->get('/products',                     AdminProductsController::class.':index');
        $a->get('/products/new',                 AdminProductsController::class.':form');
        $a->post('/products/new',                AdminProductsController::class.':save');
        $a->get('/products/{id:[0-9]+}',         AdminProductsController::class.':form');
        $a->post('/products/{id:[0-9]+}',        AdminProductsController::class.':save');
        $a->post('/products/{id:[0-9]+}/delete', AdminProductsController::class.':delete');
        // Running the shop, as distinct from editing the catalogue: orders that get shipped,
        // discount codes, and what delivery costs per region.
        $a->get('/shop/orders',        AdminShopController::class.':orders');
        $a->post('/shop/orders/fulfil',AdminShopController::class.':fulfil');
        $a->get('/shop/codes',         AdminShopController::class.':codes');
        $a->post('/shop/codes',        AdminShopController::class.':saveCode');
        $a->post('/shop/codes/{id:[0-9]+}/delete', AdminShopController::class.':deleteCode');
        $a->get('/shop/shipping',      AdminShopController::class.':shipping');
        $a->post('/shop/shipping',     AdminShopController::class.':saveShipping');

        $a->get('/nominees',        AdminNomineesController::class.':index');
        $a->get('/nominees/duplicate-scan', AdminNomineesController::class.':duplicateScan');
        $a->post('/nominees/merge', AdminNomineesController::class.':merge');
        $a->post('/nominees/unmerge', AdminNomineesController::class.':unmerge');
        $a->post('/nominees/{id:[0-9]+}/link',     AdminNomineesController::class.':link');
        $a->post('/nominees/{id:[0-9]+}/photo',         AdminNomineesController::class.':photo');
        $a->post('/nominees/{id:[0-9]+}/photo/primary', AdminNomineesController::class.':photoPrimary');
        $a->post('/nominees/{id:[0-9]+}/photo/delete',  AdminNomineesController::class.':photoDelete');
        $a->post('/nominees/{id:[0-9]+}/delete',   AdminNomineesController::class.':delete');
        $a->post('/nominees/{id:[0-9]+}/{action}', AdminNomineesController::class.':action');

        $a->get('/legacy',                       AdminLegacyController::class.':index');
        $a->get('/legacy/new',                   AdminLegacyController::class.':form');
        $a->post('/legacy/new',                  AdminLegacyController::class.':save');
        $a->get('/legacy/{id:[0-9]+}',           AdminLegacyController::class.':form');
        $a->post('/legacy/{id:[0-9]+}',          AdminLegacyController::class.':save');
        $a->post('/legacy/{id:[0-9]+}/delete',   AdminLegacyController::class.':delete');

        $a->get('/opportunities',                AdminOpportunitiesController::class.':index');
        $a->get('/opportunities/new',            AdminOpportunitiesController::class.':form');
        $a->post('/opportunities/new',           AdminOpportunitiesController::class.':save');
        $a->get('/opportunities/{id:[0-9]+}',    AdminOpportunitiesController::class.':form');
        $a->post('/opportunities/{id:[0-9]+}',   AdminOpportunitiesController::class.':save');
        $a->post('/opportunities/{id:[0-9]+}/delete', AdminOpportunitiesController::class.':delete');

        $a->get('/events',                       AdminEventsController::class.':index');
        $a->get('/events/new',                   AdminEventsController::class.':form');
        $a->post('/events/new',                  AdminEventsController::class.':save');
        $a->get('/events/{id:[0-9]+}',           AdminEventsController::class.':form');
        $a->post('/events/{id:[0-9]+}',          AdminEventsController::class.':save');
        $a->post('/events/{id:[0-9]+}/delete',   AdminEventsController::class.':delete');
        // Tickets, attendees and the door. Its own screen because an organiser reading a
        // door list on the morning of an event is doing a different job from one editing a
        // description, and check-in is the only thing they want on that page.
        $a->get('/events/{id:[0-9]+}/tickets',   AdminEventsController::class.':tickets');
        $a->post('/events/{id:[0-9]+}/check-in', AdminEventsController::class.':checkIn');
        $a->get('/events/{id:[0-9]+}/attendees.csv', AdminEventsController::class.':exportAttendees');
        // Discount codes get their own screen: a code is created and retired on a completely
        // different rhythm from an event's title and venue.
        $a->get('/events/{id:[0-9]+}/codes',              AdminEventsController::class.':codes');
        $a->post('/events/{id:[0-9]+}/codes',             AdminEventsController::class.':saveCode');
        $a->post('/events/{id:[0-9]+}/codes/{code:[0-9]+}/delete', AdminEventsController::class.':deleteCode');
        // Promoting off the waiting list is a button, not a cron: it emails real people about
        // a seat held for a fixed number of hours, and an organiser decides when that goes.
        $a->post('/events/{id:[0-9]+}/promote', AdminEventsController::class.':promote');
        // The other half of a waiting list: a seat only comes back if somebody can give it back.
        $a->post('/events/{id:[0-9]+}/release', AdminEventsController::class.':releaseSeat');
        // A seat or table label on one attendee — what a gala with numbered tables needs, and
        // what the ticket prints when the organiser has ticked "Seat" on the ticket design.
        $a->post('/events/{id:[0-9]+}/seat',    AdminEventsController::class.':seat');

        $a->get('/registrations',                AdminRegistrationsController::class.':index');
        $a->get('/registrations/export',         AdminRegistrationsController::class.':export');

        // Finance — every naira across donations, paid votes, shop orders and tickets.
        // Its own section in Permissions::MATRIX (superadmin + admin), deliberately
        // narrower than /admin/data; the controller re-checks rather than trusting the nav.
        $a->get('/finance',                      AdminFinanceController::class.':index');
        $a->get('/finance/export',               AdminFinanceController::class.':export');
        // Re-verify stale pending payments against the gateway. POST because it makes
        // outbound calls and, in apply mode, moves money — a GET would let a prefetch
        // or a refresh confirm payments.
        $a->post('/finance/reconcile',           AdminFinanceController::class.':reconcile');

        // Payment triage — the orders that were CHARGED and never confirmed, which
        // mint, the refund sweep and the receipt are all structurally blind to
        // because every one of them starts at status='confirmed'. Exists as a page
        // and not only as `bin/console payments:triage` because this platform's
        // operator has no shell, which made the command unrunnable by the one
        // person who needs it. GET looks; POST asks the gateway; a second POST
        // repairs, and only after the operator has seen what they are repairing.
        $a->get('/payments',                     \AfricaGates\Admin\Controllers\PaymentsTriageController::class.':index');
        $a->post('/payments/verify',             \AfricaGates\Admin\Controllers\PaymentsTriageController::class.':verify');
        $a->post('/payments/repair',             \AfricaGates\Admin\Controllers\PaymentsTriageController::class.':repair');
        $a->post('/payments/deliver',            \AfricaGates\Admin\Controllers\PaymentsTriageController::class.':deliver');
        // The same money read from the other side. Everything above starts from a
        // row of ours; this starts from Paystack's list, which is the only way a
        // charge with NO row here can ever appear on a screen.
        $a->get('/payments/ledger',              \AfricaGates\Admin\Controllers\PaymentsTriageController::class.':ledger');
        $a->post('/payments/ledger',             \AfricaGates\Admin\Controllers\PaymentsTriageController::class.':pullLedger');
        // Disputes. The GET loads the queue on view — unlike the ledger, which needs a
        // button because it makes twenty calls — because the whole failure mode of a
        // chargeback is that nobody looks until the 16 hours are gone. Resolving is a
        // POST and never a link: it is irreversible and it moves money.
        $a->get('/payments/disputes',            \AfricaGates\Admin\Controllers\PaymentsTriageController::class.':disputes');
        $a->post('/payments/disputes/resolve',   \AfricaGates\Admin\Controllers\PaymentsTriageController::class.':resolveDispute');
        $a->get('/payments/disputes/evidence',   \AfricaGates\Admin\Controllers\PaymentsTriageController::class.':disputeEvidence');


        // Member account actions (manual points adjustment — audited, admin+ gated in-controller).
        $a->post('/users/{id:[0-9]+}/points',    \AfricaGates\Admin\Controllers\UsersController::class.':adjustPoints');

        // Generic data explorer — every collected dataset (paginated + detail pages + CSV).
        $a->get('/data',                         AdminDataController::class.':index');
        // Rates, ratios and funnels. The data explorer above shows rows; this shows
        // what the rows are doing over time, which is a different question.
        $a->get('/analytics',                    \AfricaGates\Admin\Controllers\AnalyticsController::class.':index');
        $a->get('/data/{dataset}/export',        AdminDataController::class.':export');
        $a->get('/data/{dataset}/{id:[0-9]+}',   AdminDataController::class.':detail');
        $a->get('/data/{dataset}',               AdminDataController::class.':browse');

        // Form builder
        $a->get('/forms',                        AdminFormsController::class.':index');
        $a->get('/forms/new',                    AdminFormsController::class.':form');
        $a->post('/forms/new',                   AdminFormsController::class.':save');
        $a->get('/forms/{id:[0-9]+}',            AdminFormsController::class.':form');
        $a->post('/forms/{id:[0-9]+}',           AdminFormsController::class.':save');
        $a->post('/forms/{id:[0-9]+}/delete',    AdminFormsController::class.':delete');
        $a->get('/forms/{id:[0-9]+}/submissions', AdminFormsController::class.':submissions');

        $a->get('/posts',                        AdminPostsController::class.':index');
        $a->get('/posts/new',                    AdminPostsController::class.':form');
        $a->post('/posts/new',                   AdminPostsController::class.':save');
        $a->get('/posts/{id:[0-9]+}',            AdminPostsController::class.':form');
        $a->post('/posts/{id:[0-9]+}',           AdminPostsController::class.':save');
        $a->post('/posts/{id:[0-9]+}/delete',    AdminPostsController::class.':delete');

        $a->get('/partners',                     AdminPartnersController::class.':index');
        $a->post('/partners/{id:[0-9]+}/{status}', AdminPartnersController::class.':setStatus');
        $a->get('/partners/export.csv',          AdminPartnersController::class.':exportCsv');

        // ── JUDGING INTERVIEWS ────────────────────────────────────────
        //
        // Not superadmin-gated like /judges below: appointing a judge is a governance act,
        // while running an interview is programme work a moderator does. The controller
        // gates on superadmin/admin/moderator.
        //
        // `/run` is a GET that marks the sitting live — the one write behind a GET here,
        // and it is idempotent. Everything that changes a decision is a POST.
        $a->group('/interviews', function (RouteCollectorProxy $s) {
            $s->get('',                          \AfricaGates\Admin\Controllers\InterviewsController::class.':index');
            $s->post('/new',                     \AfricaGates\Admin\Controllers\InterviewsController::class.':create');
            // BEFORE /{id}: "new" is not a number so they cannot collide, but the
            // ordering convention in this file is worth keeping.
            $s->get('/{id:[0-9]+}/run',          \AfricaGates\Admin\Controllers\InterviewsController::class.':run');
            $s->get('/{id:[0-9]+}',              \AfricaGates\Admin\Controllers\InterviewsController::class.':show');
            $s->post('/{id:[0-9]+}/schedule',    \AfricaGates\Admin\Controllers\InterviewsController::class.':reschedule');
            $s->post('/{id:[0-9]+}/meet',        \AfricaGates\Admin\Controllers\InterviewsController::class.':meet');
            $s->post('/{id:[0-9]+}/invite',      \AfricaGates\Admin\Controllers\InterviewsController::class.':invite');
            $s->post('/{id:[0-9]+}/rebuild',     \AfricaGates\Admin\Controllers\InterviewsController::class.':rebuild');
            // The console's save button. Answers JSON, because it fires while a person is
            // mid-sentence and a full page reload would lose the room.
            $s->post('/{id:[0-9]+}/answer',      \AfricaGates\Admin\Controllers\InterviewsController::class.':answer');
            $s->post('/{id:[0-9]+}/close',       \AfricaGates\Admin\Controllers\InterviewsController::class.':close');
            $s->post('/{id:[0-9]+}/cancel',      \AfricaGates\Admin\Controllers\InterviewsController::class.':cancel');
            $s->post('/{id:[0-9]+}/transcript',  \AfricaGates\Admin\Controllers\InterviewsController::class.':transcript');
            $s->post('/{id:[0-9]+}/review',      \AfricaGates\Admin\Controllers\InterviewsController::class.':review');
            $s->post('/{id:[0-9]+}/withdraw',    \AfricaGates\Admin\Controllers\InterviewsController::class.':withdraw');
            // The browser extension's key, and publishing what it captured. The extension's
            // own endpoints are under /api/interview/live/… — see the note there.
            $s->post('/{id:[0-9]+}/live/rotate', \AfricaGates\Admin\Controllers\InterviewsController::class.':rotateLive');
            $s->post('/{id:[0-9]+}/live/save',   \AfricaGates\Admin\Controllers\InterviewsController::class.':saveLive');
        });

        // ── NOMINEE QUESTIONNAIRES ────────────────────────────────────
        //
        // `moderation`, like interviews: asking a nominee about their own work is programme
        // work, not governance. The controller lets a viewer read the queue and refuses them
        // every write, so the sidebar and the guard agree.
        $a->group('/questionnaires', function (RouteCollectorProxy $s) {
            $s->get('',                            \AfricaGates\Admin\Controllers\QuestionnairesController::class.':index');
            $s->post('/open',                      \AfricaGates\Admin\Controllers\QuestionnairesController::class.':open');
            // A questionnaire to rehearse on. Before this, seeing what a nominee sees meant
            // opening one against a real person — a live token, a row in the counts, and on
            // submit evidence rows in that person's dossier — so nobody rehearsed and the
            // first person to meet a confusing question was always a nominee.
            $s->post('/test',                      \AfricaGates\Admin\Controllers\QuestionnairesController::class.':openTest');
            $s->post('/invite-all',                \AfricaGates\Admin\Controllers\QuestionnairesController::class.':inviteAll');
            // BEFORE /{id}: "programme" is not a number, so they cannot collide — but the
            // ordering convention in this file is worth keeping.
            $s->get('/programme/{id:[0-9]+}',      \AfricaGates\Admin\Controllers\QuestionnairesController::class.':questions');
            $s->post('/programme/{id:[0-9]+}',     \AfricaGates\Admin\Controllers\QuestionnairesController::class.':saveQuestions');
            $s->post('/programme/{id:[0-9]+}/seed',\AfricaGates\Admin\Controllers\QuestionnairesController::class.':seed');
            $s->get('/{id:[0-9]+}',                \AfricaGates\Admin\Controllers\QuestionnairesController::class.':show');
            $s->post('/{id:[0-9]+}/invite',        \AfricaGates\Admin\Controllers\QuestionnairesController::class.':invite');
            $s->post('/{id:[0-9]+}/reopen',        \AfricaGates\Admin\Controllers\QuestionnairesController::class.':reopen');
            $s->post('/{id:[0-9]+}/republish',     \AfricaGates\Admin\Controllers\QuestionnairesController::class.':republish');
            // Only a test can be deleted; the service refuses anything else, so a mistyped id
            // cannot take a nominee's own account of their work with it.
            $s->post('/{id:[0-9]+}/delete-test',   \AfricaGates\Admin\Controllers\QuestionnairesController::class.':deleteTest');
        });

        // ── superadmin-only areas (RBAC, Task B6) ─────────────────────
        $a->group('/judges', function (RouteCollectorProxy $s) {
            $s->get('',                     AdminJudgesController::class.':index');
            $s->get('/new',                 AdminJudgesController::class.':form');
            $s->post('/new',                AdminJudgesController::class.':save');
            $s->get('/{id:[0-9]+}',         AdminJudgesController::class.':form');
            $s->post('/{id:[0-9]+}',        AdminJudgesController::class.':save');
            $s->post('/{id:[0-9]+}/delete', AdminJudgesController::class.':delete');
            $s->post('/{id:[0-9]+}/regenerate-form', AdminJudgesController::class.':regenerateForm');
        })->add(new RoleMiddleware('superadmin'));

        $a->group('/admins', function (RouteCollectorProxy $s) {
            $s->get('',                     AdminAdminsController::class.':index');
            $s->get('/new',                 AdminAdminsController::class.':form');
            $s->post('/new',                AdminAdminsController::class.':save');
            $s->get('/{id:[0-9]+}',         AdminAdminsController::class.':form');
            $s->post('/{id:[0-9]+}',        AdminAdminsController::class.':save');
            $s->post('/{id:[0-9]+}/toggle', AdminAdminsController::class.':toggle');
        })->add(new RoleMiddleware('superadmin'));

        $a->group('/settings', function (RouteCollectorProxy $s) {
            $s->get('',  AdminSettingsController::class.':form');
            $s->post('', AdminSettingsController::class.':save');
            $s->post('/smtp-test', AdminSettingsController::class.':smtpTest');
            $s->post('/test-ai',   AdminSettingsController::class.':testAi');
            $s->post('/run-cron',  AdminSettingsController::class.':runCron');
            // One task, not the whole pass — the answer to "I paid and my votes did
            // not appear" without waiting on a CPI recompute. Idempotent.
            $s->post('/reconcile-payments', AdminSettingsController::class.':reconcilePayments');
        })->add(new RoleMiddleware('superadmin'));

        // Outbound webhooks — integration endpoints for AI agents & platforms.
        $a->group('/webhooks', function (RouteCollectorProxy $s) {
            $s->get('',                     AdminWebhooksController::class.':index');
            $s->get('/new',                 AdminWebhooksController::class.':form');
            $s->post('/new',                AdminWebhooksController::class.':save');
            $s->get('/{id:[0-9]+}',         AdminWebhooksController::class.':form');
            $s->post('/{id:[0-9]+}',        AdminWebhooksController::class.':save');
            $s->post('/{id:[0-9]+}/delete', AdminWebhooksController::class.':delete');
            $s->post('/{id:[0-9]+}/test',   AdminWebhooksController::class.':test');
        })->add(new RoleMiddleware('superadmin'));
    // Auth runs first (outermost), then the per-section RBAC guard.
    })->add(new SectionGuardMiddleware())->add(new AdminAuthMiddleware());
};
