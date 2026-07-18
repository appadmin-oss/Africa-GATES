<?php
declare(strict_types=1);
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use AfricaGates\Controllers\{HomeController,ApiController,RegistryController,AwardsController,LeaderboardController,LegacyController,OpportunityController,NominationController,PartnerController,VoteController,CommunityController,EventsController,BlogController,PaymentController,ShopController,ShopCheckoutController,GuideController,DonationController,PaidVoteController,PulseController,JudgesController,AccountController,GatedFormController,FormController};
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

    // Health-check — test routing works without DB
    $app->get('/ping', function ($req, $res) {
        $res->getBody()->write(json_encode([
            'status' => 'ok',
            'app'    => 'Africa GATES',
            'ts'     => date('c'),
        ]));
        return $res->withHeader('Content-Type', 'application/json');
    });

    // ── No-SSH database migration trigger (token-gated) ──────────────────────
    // For hosts without shell access (shared cPanel etc.): set a strong
    // SETUP_TOKEN in .env, visit /__setup/migrate?token=THAT once to apply all
    // schema files + pending migrations, then DELETE the token. Idempotent and
    // safe to re-run. Returns 404 when the token is unset or wrong, so the
    // endpoint is invisible to anyone without the secret. /__setup/status shows
    // which migrations are still pending (read-only).
    $setupGuard = static function ($req): bool {
        $token = trim((string)($_ENV['SETUP_TOKEN'] ?? ''));
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
    $app->get('/__setup/status', function ($req, $res) use ($setupGuard) {
        if (!$setupGuard($req)) return $res->withStatus(404);
        $res->getBody()->write(json_encode(\AfricaGates\Services\MigrationRunner::status(), JSON_PRETTY_PRINT));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    });
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
        $g->get('/nominate',      NominationController::class.':form');
        $g->post('/nominate',     NominationController::class.':submit');
        $g->get('/nominate/success',function($req,$res) use ($tv){ $d=$_SESSION['nom_done']??null; unset($_SESSION['nom_done']); return $tv($req)->render($res,'pages/nominate-success.twig',['page_title'=>'Nomination Submitted — Africa GATES','meta_description'=>'Your nomination is in. Thank you for championing African excellence — our team will review it for the Africa GATES awards cycle. Nominate someone else too.','gates_page'=>'nominate','has_hero'=>false,'ref'=>$d['ref']??'','nominee'=>$d['nominee']??'','category'=>$d['cat']??'','share_payload'=>$d['share']??null]); });
        // Paid-voting routes are STATIC and must be registered BEFORE the
        // /vote/{program}/{slug} variable route, or FastRoute treats them as
        // shadowed and aborts routing for the whole app.
        $g->post('/vote/paid/start',   PaidVoteController::class.':start');
        $g->get('/vote/paid/callback', PaidVoteController::class.':callback');
        $g->get('/vote/paid/success',  PaidVoteController::class.':success');
        $g->get('/vote',                  VoteController::class.':index');
        $g->get('/vote/{program}',        VoteController::class.':program');
        $g->get('/vote/{program}/{slug}', VoteController::class.':nominee');
        $g->get('/partner',       PartnerController::class.':form');
        $g->post('/partner',      PartnerController::class.':submit');
        $g->get('/partner/success',fn($req,$res)=>$tv($req)->render($res,'pages/partner-success.twig',['page_title'=>'Thank You — Africa GATES','meta_description'=>'Thank you for your interest in partnering with Africa GATES. Our team will be in touch to explore how we can champion African excellence together.','gates_page'=>'partner','has_hero'=>false]));

        // ── Shop (storefront + gateway checkout; static routes before {slug}) ──
        $g->get('/shop',           ShopController::class.':index');
        $g->post('/shop/checkout', ShopCheckoutController::class.':checkout');
        $g->get('/shop/callback',  ShopCheckoutController::class.':callback');
        $g->get('/shop/success',   ShopCheckoutController::class.':success');
        $g->get('/shop/{slug}',    ShopController::class.':item');

        // ── Payments (Paystack / Flutterwave behind PaymentService) ──────────
        //   /pay/init     first-party form post (CSRF-protected) → hosted checkout
        //   /pay/callback browser return; verified server-side before crediting
        //   /pay/success  read-only confirmation page
        //   /pay/webhook  server-to-server, signature-verified, CSRF-EXEMPT
        $g->post('/pay/init',     PaymentController::class.':init');
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
        $g->get('/integrity',fn($req,$res)=>$tv($req)->render($res,'pages/integrity.twig',['page_title'=>'Awards Integrity & Methodology — Africa GATES','meta_description'=>'How Africa GATES safeguards fair results — the methodology behind community votes, expert judging and the Cultural Power Index that ranks African excellence.','gates_page'=>'integrity','has_hero'=>false,'current_section'=>'projects']));
        $g->get('/support', fn($req,$res)=>$tv($req)->render($res,'pages/support.twig',['page_title'=>'Support & Appeals — Africa GATES','meta_description'=>'Get help with Africa GATES — the CPI, voting, nominations and your profile — and appeal any moderation decision through an independent review.','gates_page'=>'support','has_hero'=>false]));
        // /signin was a non-functional mock (fake success, no auth). Retire it —
        // the real, working member sign-in is /account/login.
        $g->get('/signin',  fn($req,$res)=>$res->withHeader('Location','/account/login')->withStatus(301));
        // Status reflects REAL signals, not a hardcoded "all green": the core
        // features are operational because this DB-backed page is being served at
        // all; payments/email report their actual configuration state.
        $g->get('/status', function($req,$res) use ($tv) {
            $set  = fn(string ...$k) => (bool) array_filter($k, fn($x)=>trim((string)($_ENV[$x] ?? ''))!=='');
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
        $g->get('/help',   fn($req,$res)=>$tv($req)->render($res,'pages/help.twig',['page_title'=>'Help Center — Africa GATES','meta_description'=>'Answers about voting, nominations, profiles, donations and privacy on Africa GATES — plus how to reach our support team.','gates_page'=>'help','has_hero'=>false]));

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

        $a->get('/profiles',        AdminProfilesController::class.':index');
        $a->get('/profiles/{id:[0-9]+}',          AdminProfilesController::class.':edit');
        $a->post('/profiles/{id:[0-9]+}',         AdminProfilesController::class.':update');
        $a->post('/profiles/{id:[0-9]+}/{action}', AdminProfilesController::class.':action');

        $a->get('/nominations',     AdminNominationsController::class.':index');
        // Review DESK — high-volume queue walker ({id} below is digit-constrained, no conflict)
        $a->get('/nominations/review', AdminNominationsController::class.':desk');
        $a->get('/nominations/{id:[0-9]+}',           AdminNominationsController::class.':review');
        $a->post('/nominations/{id:[0-9]+}/suggest-reason', AdminNominationsController::class.':suggestReason');
        $a->post('/nominations/{id:[0-9]+}/regenerate-form', AdminNominationsController::class.':regenerateForm');
        $a->post('/nominations/{id:[0-9]+}/{action}', AdminNominationsController::class.':action');

        // Community moderation queue (release/remove auto-quarantined posts)
        $a->get('/moderation',                                       AdminModerationController::class.':index');
        $a->post('/moderation/{type}/{id:[0-9]+}/{decision}',        AdminModerationController::class.':action');
        // Thread operator controls: lock/unlock (readable, no replies) + pin/unpin
        $a->post('/moderation/thread/{id:[0-9]+}/flag/{flag}/{on:[01]}', AdminModerationController::class.':threadFlag');
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
        $a->post('/nominees/{id:[0-9]+}/link',     AdminNomineesController::class.':link');
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
