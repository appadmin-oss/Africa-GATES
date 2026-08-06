<?php
declare(strict_types=1);
use AfricaGates\Support\Env;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use AfricaGates\Controllers\{HomeController,ApiController,RegistryController,AwardsController,LeaderboardController,LegacyController,OpportunityController,NominationController,PartnerController,VoteController,CommunityController,EventsController,BlogController,PaymentController,ShopController,ShopCheckoutController,GuideController,DonationController,PaidVoteController,PulseController,JudgesController,AccountController,GatedFormController,FormController,ActivityController,FlierController,SupportController,HelpController,ClaimController};
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
        $g->get('/events/{slug}',  EventsController::class.':show');
        $g->post('/events/{slug}/register', EventsController::class.':register');
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
        $g->get('/vote/{program}/{slug}', VoteController::class.':nominee');
        $g->get('/partner',       PartnerController::class.':form');
        $g->post('/partner',      PartnerController::class.':submit');
        $g->get('/partner/success',fn($req,$res)=>$tv($req)->render($res,'pages/partner-success.twig',['page_title'=>'Thank You — Africa GATES','meta_description'=>'Thank you for your interest in partnering with Africa GATES. Our team will be in touch to explore how we can champion African excellence together.','gates_page'=>'partner','has_hero'=>false]));

        // ── Shop (storefront + gateway checkout; static routes before {slug}) ──
        $g->get('/shop',           ShopController::class.':index');
        $g->post('/shop/checkout', ShopCheckoutController::class.':checkout');
        $g->get('/shop/redirect',  ShopCheckoutController::class.':handoff');  // see GatewayHandoff
        $g->get('/shop/callback',  ShopCheckoutController::class.':callback');
        $g->get('/shop/success',   ShopCheckoutController::class.':success');
        $g->get('/shop/{slug}',    ShopController::class.':item');

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
            return $tv($req)->render($res,'pages/legal.twig',[
                'page_title'=>$doc['title'].' — Africa GATES',
                'meta_description'=>'The '.$doc['title'].' for Africa GATES — the continental Cultural Power Index recognising African excellence.',
                'gates_page'=>'legal','has_hero'=>false,
                'legal_doc'=>$doc,'legal_tabs'=>\AfricaGates\Services\LegalService::published(),
                // Automated-processing disclosure, DERIVED from the capability
                // registry rather than written into the admin-editable body — so
                // adding an AI feature updates the published notice by itself and
                // the page cannot fall out of step with what the code sends.
                // Privacy doc only; the other legal docs are unrelated.
                'ai_disclosure'=>$slug==='privacy' ? \AfricaGates\Services\AiPrivacy::disclosure() : [],
                'ai_disclosure_active'=>$slug==='privacy' && \AfricaGates\Services\AiPrivacy::currentlyActive(),
            ]);
        };
        $g->get('/privacy', fn($req,$res)=>$legalRender($req,$res,'privacy'));
        $g->get('/terms',   fn($req,$res)=>$legalRender($req,$res,'terms'));
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
        $g->get('/integrity', function ($req, $res) use ($tv) {
            // ── THE PAGE HAS TO READ THE ENGINE, NOT REMEMBER IT ─────────────
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
            $rules   = new \AfricaGates\Services\RuleEngine();
            $w       = $rules->weights();
            $eff     = $rules->effective();
            return $tv($req)->render($res, 'pages/integrity.twig', [
                'page_title'       => 'Awards Integrity & Methodology — Africa GATES',
                'meta_description' => 'How Africa GATES safeguards fair results — the methodology behind community votes, expert judging and the Cultural Power Index that ranks African excellence.',
                'gates_page'       => 'integrity',
                'has_hero'         => false,
                'current_section'  => 'projects',
                'community_pct'    => (int) round($w['community'] * 100),
                'judge_pct'        => (int) round($w['judge'] * 100),
                'paid_cap_pct'     => (int) ($eff['max_paid_weight_pct'] ?? 50),
                'min_judges'       => (int) ($eff['min_judges_per_nominee'] ?? 2),
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

        // Nominee page claiming. GUESTS, deliberately: the population is nominees who
        // have never had an account here, so a login wall would gate a page on having
        // the very thing the page exists to give you. Neither POST accepts a
        // destination — only an opaque channel key that must resolve to a contact
        // already on an approved nomination. See ClaimController and
        // docs/CLAIM-FAIRNESS-AND-FRAUD.md §2.
        $g->get('/claim/{id:[0-9]+}',          ClaimController::class.':page');
        $g->post('/claim/{id:[0-9]+}/code',    ClaimController::class.':send');
        $g->post('/claim/{id:[0-9]+}/confirm', ClaimController::class.':confirm');

        // The Help Centre. One URL per answer, so support can paste one into a
        // reply, the assistant can cite one, a receipt email can point at the
        // exact paragraph and a search engine can index it — none of which a
        // single page of accordions could do. See HelpController.
        $g->get('/help', HelpController::class . ':index');
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


        // Member account actions (manual points adjustment — audited, admin+ gated in-controller).
        $a->post('/users/{id:[0-9]+}/points',    \AfricaGates\Admin\Controllers\UsersController::class.':adjustPoints');

        // Generic data explorer — every collected dataset (paginated + detail pages + CSV).
        $a->get('/data',                         AdminDataController::class.':index');
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
