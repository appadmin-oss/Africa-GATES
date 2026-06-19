<?php
declare(strict_types=1);
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use AfricaGates\Controllers\{HomeController,ApiController,RegistryController,AwardsController,LeaderboardController,LegacyController,OpportunityController,NominationController,PartnerController,VoteController,CommunityController,EventsController,BlogController,PaymentController};
use AfricaGates\Judge\Controllers\{
    AuthController as JudgeAuthController,
    BallotController as JudgeBallotController
};
use AfricaGates\Judge\Middleware\JudgeAuthMiddleware;
use AfricaGates\Admin\Controllers\{
    AuthController as AdminAuthController,
    DashboardController as AdminDashboardController,
    ProfilesController as AdminProfilesController,
    NominationsController as AdminNominationsController,
    ProgrammesController as AdminProgrammesController,
    NomineesController as AdminNomineesController,
    LegacyController as AdminLegacyController,
    OpportunitiesController as AdminOpportunitiesController,
    EventsController as AdminEventsController,
    PostsController as AdminPostsController,
    PartnersController as AdminPartnersController,
    JudgesController as AdminJudgesController,
    AdminsController as AdminAdminsController,
    SettingsController as AdminSettingsController
};
use AfricaGates\Admin\Middleware\AdminAuthMiddleware;
use AfricaGates\Admin\Middleware\RoleMiddleware;

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
    $container = $app->getContainer();
    $app->group('', function(RouteCollectorProxy $g) use ($container) {
        $twig = $container->get(\Slim\Views\Twig::class);
        $tv = fn($req) => $twig;
        $g->get('[/]',            HomeController::class.':index');
        $g->get('/awards',        AwardsController::class.':index');
        $g->get('/awards/{p}',    AwardsController::class.':programme');
        $g->get('/leaderboard',   LeaderboardController::class.':index');
        $g->get('/registry',      RegistryController::class.':index');
        $g->get('/registry/{slug}',RegistryController::class.':profile');
        $g->get('/register',      RegistryController::class.':registerForm');
        $g->post('/register',     RegistryController::class.':registerSubmit');
        $g->get('/register/success',fn($req,$res)=>$tv($req)->render($res,'pages/registry/register-success.twig',['page_title'=>'Registered — Africa GATES','meta_description'=>'Your Africa GATES profile is registered. Once verified, your Cultural Power Index score begins ticking and you become eligible for every awards cycle.','gates_page'=>'register','has_hero'=>false]));
        $g->get('/legacy',        LegacyController::class.':index');
        $g->get('/legacy/{slug}', LegacyController::class.':event');
        $g->get('/opportunities',  OpportunityController::class.':index');
        $g->get('/events',         EventsController::class.':index');
        $g->get('/blog',           BlogController::class.':index');
        $g->get('/blog/{slug}',    BlogController::class.':show');
        $g->get('/nominate',      NominationController::class.':form');
        $g->post('/nominate',     NominationController::class.':submit');
        $g->get('/nominate/success',fn($req,$res)=>$tv($req)->render($res,'pages/nominate-success.twig',['page_title'=>'Nomination Submitted — Africa GATES','meta_description'=>'Your nomination is in. Thank you for championing African excellence — our team will review it for the Africa GATES awards cycle. Nominate someone else too.','gates_page'=>'nominate','has_hero'=>false]));
        $g->get('/vote',          VoteController::class.':index');
        $g->get('/partner',       PartnerController::class.':form');
        $g->post('/partner',      PartnerController::class.':submit');
        $g->get('/partner/success',fn($req,$res)=>$tv($req)->render($res,'pages/partner-success.twig',['page_title'=>'Thank You — Africa GATES','meta_description'=>'Thank you for your interest in partnering with Africa GATES. Our team will be in touch to explore how we can champion African excellence together.','gates_page'=>'partner','has_hero'=>false]));

        // ── Payments (Paystack / Flutterwave behind PaymentService) ──────────
        //   /pay/init     first-party form post (CSRF-protected) → hosted checkout
        //   /pay/callback browser return; verified server-side before crediting
        //   /pay/success  read-only confirmation page
        //   /pay/webhook  server-to-server, signature-verified, CSRF-EXEMPT
        $g->post('/pay/init',     PaymentController::class.':init');
        $g->get('/pay/callback',  PaymentController::class.':callback');
        $g->get('/pay/success',   PaymentController::class.':success');
        $g->post('/pay/webhook',  PaymentController::class.':webhook');
        $g->get('/privacy', fn($req,$res)=>$tv($req)->render($res,'pages/privacy.twig',['page_title'=>'Privacy Policy — Africa GATES','meta_description'=>'How Africa GATES collects, uses and protects your data across the registry, voting and nominations. Read our privacy commitments to the community.','gates_page'=>'','has_hero'=>false]));
        $g->get('/terms',   fn($req,$res)=>$tv($req)->render($res,'pages/terms.twig',['page_title'=>'Terms of Use — Africa GATES','meta_description'=>'The terms governing your use of Africa GATES — the continental Cultural Power Index for recognising African excellence through voting and nominations.','gates_page'=>'','has_hero'=>false]));
        $g->get('/integrity',fn($req,$res)=>$tv($req)->render($res,'pages/integrity.twig',['page_title'=>'Awards Integrity & Methodology — Africa GATES','meta_description'=>'How Africa GATES safeguards fair results — the methodology behind community votes, expert judging and the Cultural Power Index that ranks African excellence.','gates_page'=>'integrity','has_hero'=>false,'current_section'=>'projects']));

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
        $g->group('/api', function(RouteCollectorProxy $a) {
            $a->get('/registry',         ApiController::class.':registry');
            $a->get('/registry/{slug}',  ApiController::class.':profileBySlug');
            $a->get('/awards',           ApiController::class.':awardsIndex');
            $a->get('/nominees',         ApiController::class.':nominees');
            $a->post('/otp/request',     ApiController::class.':otpRequest');
            $a->post('/vote',            ApiController::class.':castVote');
            $a->post('/funnel',          ApiController::class.':trackFunnel');
            $a->post('/nominations/draft', ApiController::class.':saveDraft');
            $a->get('/nominations/draft',  ApiController::class.':loadDraft');
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
            $a->get('/community/activity',  CommunityController::class.':activity');
        });

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
    })->add(new JudgeAuthMiddleware());

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
        $a->get('/nominations/{id:[0-9]+}',           AdminNominationsController::class.':review');
        $a->post('/nominations/{id:[0-9]+}/{action}', AdminNominationsController::class.':action');

        $a->get('/programmes',                       AdminProgrammesController::class.':index');
        $a->get('/programmes/new',                   AdminProgrammesController::class.':form');
        $a->post('/programmes/new',                  AdminProgrammesController::class.':save');
        $a->get('/programmes/{id:[0-9]+}',           AdminProgrammesController::class.':form');
        $a->post('/programmes/{id:[0-9]+}',          AdminProgrammesController::class.':save');
        $a->get('/programmes/{id:[0-9]+}/cycle',     AdminProgrammesController::class.':cycleEdit')->add(new RoleMiddleware('superadmin'));
        $a->post('/programmes/{id:[0-9]+}/cycle',    AdminProgrammesController::class.':cycleSave')->add(new RoleMiddleware('superadmin'));
        $a->post('/programmes/{id:[0-9]+}/categories', AdminProgrammesController::class.':categorySave');
        $a->post('/categories/{catId:[0-9]+}/delete', AdminProgrammesController::class.':categoryDelete');

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
    })->add(new AdminAuthMiddleware());
};
