<?php
declare(strict_types=1);
use AfricaGates\Support\Env;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,ProfileService,AwardService,LegacyService,OpportunityService,OtpService,VoteService,BonusVoteService,RateLimitService,SpamService,AiService,CommunityService,GoogleSheetsService,TurnstileService,StatsService,FraudService,EventService,MilestoneService,PaymentService,GuideService,CurrencyService,UserAccountService};
use AfricaGates\Controllers\{HomeController,ApiController,RegistryController,AwardsController,LeaderboardController,LegacyController,OpportunityController,NominationController,PartnerController,VoteController,CommunityController,EventsController,BlogController,PaymentController,ShopController,ShopCheckoutController,GuideController,DonationController,PaidVoteController,PulseController,JudgesController,AccountController,GatedFormController,FormController,ActivityController,FlierController};
use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Judge\Controllers\{
    AuthController as JudgeAuthController,
    BallotController as JudgeBallotController
};
use AfricaGates\Admin\Services\{AuthService,AuditService,LogService,UploadService,SettingsService,Validator as AdminValidator};
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

return [
    Twig::class => function(ContainerInterface $c) {
        $isDev = Env::get('APP_ENV', 'production') !== 'production';
        $twig = Twig::create(__DIR__.'/../templates', [
            'cache' => Env::bool('TWIG_CACHE') ? __DIR__.'/../var/cache/twig' : false,
            'auto_reload' => true,
            'debug' => $isDev,
        ]);
        // Where the content hasher looks, and what it falls back to when a path is
        // not a file under public/. Told explicitly so the CLI, the tests and a web
        // request cannot disagree about which public/ is being hashed.
        \AfricaGates\Support\Assets::configure(
            __DIR__ . '/../public',
            (string) Env::get('ASSET_VERSION', 'v1')
        );

        // Load runtime settings if available (overrides env defaults)
        $settings = [];
        try { $settings = $c->get(SettingsService::class)->all(); } catch (\Throwable $e) {}

        $globals = [
            // Flags for embedding data in <script> blocks: hex-escape < ' " &
            // (defense-in-depth over Twig's default slash-escaping).
            'JSON_SAFE'         => JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP,
            // Demo mode: gates clearly-labeled sample/illustrative content so it
            // is never shown as real data in production (APP_ENV=demo only).
            'is_demo'           => (Env::get('APP_ENV', 'production') === 'demo'),
            'app_url'           => rtrim(Env::get('APP_URL', ''), '/'),
            // In debug/dev, bust the browser cache from the NEWEST mtime across
            // every css/js file (so editing ANY asset forces a fresh fetch); in
            // prod use the pinned ASSET_VERSION (set at deploy) for far-future caching.
            // Still here, and still used for the handful of things that are not a
            // file under public/ (and as the fallback inside asset()). Prefer
            // `{{ asset('/path') }}`, which hashes the file itself — see the
            // addFunction call below and Support\Assets.
            'asset_version'     => \AfricaGates\Support\Assets::version(
                Env::bool('APP_DEBUG'),
                Env::get('ASSET_VERSION'),
                __DIR__ . '/../public/assets'
            ),
            'csrf_token'        => $_SESSION['csrf_token'] ?? '',
            'current_section'   => 'projects',
            'has_hero'          => false,
            'announcement_text' => $settings['announce_text'] ?? Env::get('ANNOUNCE_TEXT', 'Nominations open — live in Nigeria, building toward 54'),
            // Was `/africa-gates/nominate`, which no route serves. It survives in
            // production only because public/.htaccess still carries the pre-subdomain
            // legacy rule `^africa-gates/(.+)$ → /$1`, so Apache 301s it. That makes
            // the announcement bar — above the nav on every page, and this default
            // applies until an admin sets announce_url — depend on a redirect kept for
            // old bookmarks: a wasted round trip on every click today, and a hard 404
            // the day that rule is retired or the app is served by anything but that
            // Apache config. Point it at the route directly. Found by tools/qa/links.js.
            'announcement_url'  => $settings['announce_url']  ?? '/nominate',
            'announcement_cta'  => $settings['announce_cta']  ?? 'Nominate now →',
            // Real, admin-configurable bonus-vote ratio (so methodology copy never hardcodes it).
            'donation_votes_per_1000' => (int)($settings['donation_votes_per_1000'] ?? 5),
            // Admin-configurable display constants — so copy across the site never hardcodes
            // these numbers. Settings override the defaults; templates read the globals.
            'nations_count'       => (int)($settings['nations_count'] ?? 54),
            'cpi_recompute_hours' => (int)($settings['cpi_recompute_hours'] ?? 6),
            'review_sla_hours'    => (int)($settings['review_sla_hours'] ?? 48),
            'nomination_seconds'  => (int)($settings['nomination_seconds'] ?? 90),
            'otp_expiry_minutes'  => (int)($settings['otp_expiry_minutes'] ?? 10),
            'processing_fee_pct'  => (string)($settings['processing_fee_pct'] ?? '1.5'),
            // Social card image (OG/Twitter) — admin-settable; defaults to a hosted,
            // on-brand asset (NEVER an external stock URL). Pages can override per-record.
            'og_image' => (function() use ($settings) {
                $v = trim((string)($settings['og_image'] ?? ''));
                if ($v !== '') return $v;
                return (rtrim(Env::get('APP_URL', ''), '/') ?: 'https://afg.afrovanguard.org.ng') . '/gates-logo.png';
            })(),
            // Admin-configurable social presence (footer links + rel=me). Empty = hidden.
            'social_links' => array_filter([
                'x'         => trim((string)($settings['social_x'] ?? '')),
                'facebook'  => trim((string)($settings['social_facebook'] ?? '')),
                'instagram' => trim((string)($settings['social_instagram'] ?? '')),
                'linkedin'  => trim((string)($settings['social_linkedin'] ?? '')),
                'youtube'   => trim((string)($settings['social_youtube'] ?? '')),
                'tiktok'    => trim((string)($settings['social_tiktok'] ?? '')),
            ]),
            // Ad monetization (Google AdSense). Off until a publisher client + slot are
            // configured — admin setting overrides env; empty means no ads render at all.
            'adsense_client' => trim((string)($settings['adsense_client'] ?? Env::get('ADSENSE_CLIENT', ''))),
            'adsense_slot'   => trim((string)($settings['adsense_slot']   ?? Env::get('ADSENSE_SLOT', ''))),
            'adsense_slot_2' => trim((string)($settings['adsense_slot_2'] ?? Env::get('ADSENSE_SLOT_2', ''))),
            // Canonical shop delivery regions — drives the checkout region selector.
            'shop_regions'   => \AfricaGates\Admin\Controllers\ProductsController::REGIONS,
            'gas_url'           => Env::get('GAS_URL', ''),
            // The address printed on help, partner and support pages, quoted by
            // the assistant and used to deliver tickets. A global because it was
            // previously typed out in three templates, which is how a site ends
            // up advertising a mailbox nobody reads any more.
            'support_email'     => \AfricaGates\Services\Notifier::supportEmail(),
            // Email transport health for the admin banner — config check only
            // (no network). Null-safe when the mailer can't build.
            'smtp_ok'           => (function () use ($c) {
                if (empty($_SESSION['admin_id'])) return true; // only admins see the banner
                try { return $c->get(OtpService::class)->smtpConfigured(); } catch (\Throwable) { return true; }
            })(),
            // Pending DB migrations — the #1 cause of admin "action 500s" after a
            // deploy: writes touch new columns/tables that were never applied,
            // while reads keep working. Surface it LOUDLY (admins only, one cheap
            // ledger query) so the operator sees an instruction, not a mystery 500.
            'migrations_pending' => (function () {
                if (empty($_SESSION['admin_id'])) return [];
                try { return \AfricaGates\Services\MigrationRunner::status()['pending'] ?? []; }
                catch (\Throwable) { return []; }
            })(),
            'admin_name'        => $_SESSION['admin_name']  ?? null,
            'admin_role'        => $_SESSION['admin_role']  ?? null,
            'admin_email'       => $_SESSION['admin_email'] ?? null,
            // Sections this admin's role may view — drives the sidebar (mirrors
            // the server-side SectionGuardMiddleware so UI never offers a 403).
            'admin_sections'    => isset($_SESSION['admin_role'])
                ? \AfricaGates\Admin\Support\Permissions::allowedSections((string)$_SESSION['admin_role'])
                : [],
            'admin_role_label'  => isset($_SESSION['admin_role'])
                ? \AfricaGates\Admin\Support\Permissions::label((string)$_SESSION['admin_role'])
                : null,
            'judge_id'          => $_SESSION['judge_id']    ?? null,
            'judge_name'        => $_SESSION['judge_name']  ?? null,
            'judge_email'       => $_SESSION['judge_email'] ?? null,
            // Signed-in MEMBER (public account) — session-only, no DB read.
            // Drives members-only UI (community composer, Gee suppression there).
            'is_member'         => !empty($_SESSION['user_id']),
            'member_name'       => $_SESSION['user_name'] ?? null,
            // Per-request canonical/og:url inputs (were undefined → every page
            // self-reported as the homepage). site_url tracks APP_URL so canonical
            // + Open Graph use the real deployed host.
            'request_path'      => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'site_url'          => rtrim(Env::get('APP_URL', ''), '/') ?: 'https://afg.afrovanguard.org.ng',
            'flash_ok'          => $_SESSION['flash_ok']    ?? null,
            // The per-request CSP nonce. Every inline <script> must carry it or the
            // browser refuses to run it — see AfricaGates\Support\Csp.
            'csp_nonce'         => \AfricaGates\Support\Csp::nonce(),
            'flash_error'       => $_SESSION['flash_error'] ?? null,
            'flash_notice'      => $_SESSION['flash_notice'] ?? null,
            // The built CSS bundle, or null when the layout must fall back to the
            // fifteen individual stylesheets. Null on ANY doubt — no manifest, missing
            // file, or a source edited since the build — because stale CSS is a far
            // worse failure than nine extra requests. See Support\AssetBundle.
            'css_bundle'        => \AfricaGates\Support\AssetBundle::url(),
        ];
        foreach ($globals as $k => $v) $twig->getEnvironment()->addGlobal($k, $v);
        // Allowlist-sanitise admin-authored rich text (blog/legacy bodies) at render
        // time — used instead of |raw so stored HTML can't inject script/handlers.
        $twig->getEnvironment()->addFilter(new \Twig\TwigFilter(
            'sanitize_html',
            [\AfricaGates\Support\Html::class, 'sanitize'],
            ['is_safe' => ['html']]
        ));
        // Stored image path → the URL to actually request. A filter rather than
        // forty hand-written transformation strings, so the crop rule that frames a
        // nominee's face lives in ONE place — see AfricaGates\Support\Media. Safe on a
        // local `/uploads/...` path, which it returns untouched, so templates can call
        // it unconditionally while a Cloudinary migration is only partly through.
        $twig->getEnvironment()->addFilter(new \Twig\TwigFilter(
            'media_url',
            [\AfricaGates\Support\Media::class, 'url']
        ));
        // `{{ asset('/assets/js/gee.js') }}` → the path with a CONTENT-HASH cache
        // buster. Replaces `?v={{ asset_version }}`, which in production returned
        // the pinned ASSET_VERSION — shipped as "v1", bumped by a deploy step this
        // shell-less host does not have. Every asset was therefore `?v=v1` forever,
        // so a returning visitor kept last month's JS against this month's HTML.
        // See AfricaGates\Support\Assets::url().
        $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
            'asset',
            [\AfricaGates\Support\Assets::class, 'url']
        ));
        // `{{ cron_health() }}` → is the schedule still running?
        //
        // A function rather than something each controller passes, because a stalled
        // schedule has to show on WHATEVER admin screen somebody happens to open, and
        // threading it through twenty render() calls guarantees the one that forgets
        // is the one they were on. Lazy — nothing is queried until the admin layout
        // asks, so public pages pay nothing for it.
        // See AfricaGates\Support\CronHealth: a stalled run cannot report itself.
        $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
            'cron_health',
            [\AfricaGates\Support\CronHealth::class, 'status']
        ));
        // Consume one-shot flash
        unset($_SESSION['flash_ok'], $_SESSION['flash_error'], $_SESSION['flash_notice']);
        return $twig;
    },

    // Shared application logger (Monolog → var/logs/app.log)
    \Psr\Log\LoggerInterface::class => function(){
        $logger = new \Monolog\Logger('app');
        $dir = dirname(__DIR__) . '/var/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $logger->pushHandler(new \Monolog\Handler\StreamHandler($dir . '/app.log', \Monolog\Level::Info));
        return $logger;
    },

    // Public services
    CacheService::class         => fn()=>new CacheService(),
    StatsService::class         => fn(ContainerInterface $c)=>new StatsService($c->get(CacheService::class)),
    ProfileService::class       => fn()=>new ProfileService(),
    AwardService::class         => fn(ContainerInterface $c)=>new AwardService($c->get(SpamService::class)),
    LegacyService::class        => fn()=>new LegacyService(),
    OpportunityService::class   => fn()=>new OpportunityService(),
    RateLimitService::class     => fn()=>new RateLimitService(),
    VoteService::class          => fn(ContainerInterface $c)=>new VoteService($c->get(\Psr\Log\LoggerInterface::class)),
    BonusVoteService::class     => fn(ContainerInterface $c)=>new BonusVoteService($c->get(\Psr\Log\LoggerInterface::class)),
    PaymentService::class       => fn(ContainerInterface $c)=>new PaymentService($c->get(\Psr\Log\LoggerInterface::class)),
    GuideService::class         => fn(ContainerInterface $c)=>new GuideService($c->get(\Psr\Log\LoggerInterface::class), $c->get(CacheService::class)),
    FraudService::class         => fn(ContainerInterface $c)=>new FraudService($c->get(\Psr\Log\LoggerInterface::class)),
    EventService::class         => fn()=>new EventService(),
    MilestoneService::class     => fn(ContainerInterface $c)=>new MilestoneService($c->get(OtpService::class), $c->get(EventService::class), $c->get(\Psr\Log\LoggerInterface::class)),
    // BOTH keys, not just the secret. The service has to know whether a widget can
    // even be rendered: a secret with no site key is unpassable by anyone and closes
    // the ballot, which is a different situation from "protection is on".
    TurnstileService::class     => fn(ContainerInterface $c)=>new TurnstileService(
        (string) Env::get('TURNSTILE_SECRET', ''),
        $c->get(\Psr\Log\LoggerInterface::class),
        null,
        (string) Env::get('TURNSTILE_SITE_KEY', '')
    ),
    // Pluggable AI gateway — resolves provider keys from admin settings (with
    // .env fallback); inert until a key is set, then auto-upgrades moderation
    // + powers auto-filter presets / AI integrations across the platform.
    AiService::class            => fn()=>AiService::boot(),
    // Moderation gets its OWN AiService (dedicated Groq key + best model, with
    // a free fallback to the general key) so safety decisions are isolated from
    // high-volume public AI traffic.
    SpamService::class          => fn(ContainerInterface $c)=>new SpamService(AiService::boot('moderation')),
    CommunityService::class     => fn(ContainerInterface $c)=>new CommunityService($c->get(SpamService::class)),
    JudgeService::class         => fn()=>new JudgeService(),
    GoogleSheetsService::class  => fn(ContainerInterface $c)=>new GoogleSheetsService(
        (string) Env::get('GAS_URL', ''),
        $c->has(\AfricaGates\Admin\Services\LogService::class) ? $c->get(\AfricaGates\Admin\Services\LogService::class) : null
    ),
    OtpService::class           => function(ContainerInterface $c) {
        // Sender identity (from name/address, reply-to) is admin-configurable in
        // Settings with .env as the fallback. Credentials stay env-only.
        $s = [];
        try { $s = $c->get(SettingsService::class)->all(); } catch (\Throwable $e) {}
        $pick = fn(string $key, string $env, string $dft) => trim((string)($s[$key] ?? '')) ?: (string) Env::get($env, $dft);
        $mailer = new OtpService([
            'host' => Env::get('SMTP_HOST', 'smtp-relay.brevo.com'),
            'port' => Env::int('SMTP_PORT', 587),
            'username' => Env::get('SMTP_USER', ''),
            'password' => Env::get('SMTP_PASS', ''),
            'from_address' => $pick('mail_from_address', 'MAIL_FROM_ADDRESS', 'noreply@afrovanguard.org.ng'),
            'from_name'    => $pick('mail_from_name', 'MAIL_FROM_NAME', 'Africa GATES'),
            'reply_to'     => $pick('mail_reply_to', 'MAIL_REPLY_TO', ''),
        ], $c->get(\Psr\Log\LoggerInterface::class));
        // Hand the same transport to CheckoutMailer, which sends receipts from
        // PaidVoteController and PaymentController — neither of which has a mailer to
        // inject. It can boot its own, but then it would not share this logger, so a
        // send failure on a payment path would be missing from app.log.
        \AfricaGates\Services\CheckoutMailer::using($mailer);
        return $mailer;
    },

    // Admin services
    LogService::class       => fn()=>new LogService(),
    AuditService::class     => fn()=>new AuditService(),
    SettingsService::class  => fn()=>new SettingsService(),
    UploadService::class    => fn()=>new UploadService(),
    AdminValidator::class   => fn()=>new AdminValidator(),
    AuthService::class      => fn(ContainerInterface $c)=>new AuthService($c->get(LogService::class), $c->get(AuditService::class), $c->get(RateLimitService::class)),

    // Public controllers
    HomeController::class        => fn(ContainerInterface $c)=>new HomeController($c->get(Twig::class), $c->get(CacheService::class), $c->get(ProfileService::class), $c->get(AwardService::class), $c->get(LegacyService::class), $c->get(OpportunityService::class), $c->get(StatsService::class)),
    ApiController::class         => fn(ContainerInterface $c)=>new ApiController($c->get(CacheService::class), $c->get(ProfileService::class), $c->get(AwardService::class), $c->get(VoteService::class), $c->get(OtpService::class), $c->get(RateLimitService::class), $c->get(GoogleSheetsService::class), $c->get(CommunityService::class), $c->get(TurnstileService::class), $c->get(FraudService::class), $c->get(EventService::class), $c->get(MilestoneService::class), $c->get(LegacyService::class), $c->get(OpportunityService::class)),
    RegistryController::class    => fn(ContainerInterface $c)=>new RegistryController($c->get(Twig::class), $c->get(CacheService::class), $c->get(ProfileService::class), $c->get(RateLimitService::class), $c->get(GoogleSheetsService::class), $c->get(CommunityService::class), $c->get(OtpService::class)),
    AwardsController::class      => fn(ContainerInterface $c)=>new AwardsController($c->get(Twig::class), $c->get(CacheService::class), $c->get(AwardService::class), $c->get(SettingsService::class)),
    CurrencyService::class        => fn(ContainerInterface $c)=>new CurrencyService($c->get(CacheService::class)),
    ShopController::class         => fn(ContainerInterface $c)=>new ShopController($c->get(Twig::class), $c->get(PaymentService::class), $c->get(CurrencyService::class)),
    JudgesController::class       => fn(ContainerInterface $c)=>new JudgesController($c->get(Twig::class), $c->get(JudgeService::class)),
    UserAccountService::class     => fn()=>new UserAccountService(),
    AccountController::class      => fn(ContainerInterface $c)=>new AccountController($c->get(Twig::class), $c->get(UserAccountService::class), $c->get(OtpService::class), $c->get(RateLimitService::class), $c->get(CommunityService::class)),
    LeaderboardController::class => fn(ContainerInterface $c)=>new LeaderboardController($c->get(Twig::class), $c->get(CacheService::class), $c->get(ProfileService::class)),
    LegacyController::class      => fn(ContainerInterface $c)=>new LegacyController($c->get(Twig::class), $c->get(CacheService::class), $c->get(LegacyService::class), $c->get(CommunityService::class)),
    OpportunityController::class => fn(ContainerInterface $c)=>new OpportunityController($c->get(Twig::class), $c->get(CacheService::class), $c->get(OpportunityService::class)),
    EventsController::class      => fn(ContainerInterface $c)=>new EventsController($c->get(Twig::class), $c->get(CacheService::class), $c->get(OtpService::class)),
    BlogController::class        => fn(ContainerInterface $c)=>new BlogController($c->get(Twig::class), $c->get(CacheService::class), $c->get(CommunityService::class)),
    GatedFormController::class   => fn(ContainerInterface $c)=>new GatedFormController($c->get(Twig::class)),
    FormController::class        => fn(ContainerInterface $c)=>new FormController($c->get(Twig::class), $c->get(RateLimitService::class)),
    NominationController::class  => fn(ContainerInterface $c)=>new NominationController($c->get(Twig::class), $c->get(CacheService::class), $c->get(AwardService::class), $c->get(RateLimitService::class), $c->get(GoogleSheetsService::class), $c->get(CommunityService::class), $c->get(OtpService::class)),
    PartnerController::class     => fn(ContainerInterface $c)=>new PartnerController($c->get(Twig::class), $c->get(RateLimitService::class), $c->get(GoogleSheetsService::class), $c->get(OtpService::class), $c->get(PaymentService::class), $c->get(StatsService::class)),
    PaymentController::class     => fn(ContainerInterface $c)=>new PaymentController($c->get(PaymentService::class), $c->get(Twig::class), $c->get(\Psr\Log\LoggerInterface::class), $c->get(RateLimitService::class)),
    ShopCheckoutController::class => fn(ContainerInterface $c)=>new ShopCheckoutController($c->get(PaymentService::class), $c->get(Twig::class), $c->get(OtpService::class), $c->get(\Psr\Log\LoggerInterface::class), $c->get(RateLimitService::class)),
    // Gee gets the SUPPORT agent too. Gee is on every page and the support desk is
    // on one, so the assistant a stuck person actually meets is nearly always Gee
    // — same agent, same tools, same session-scoped identity as /support. See the
    // GuideController class note for what is deliberately NOT relaxed.
    GuideController::class        => fn(ContainerInterface $c)=>new GuideController(
        $c->get(GuideService::class),
        $c->get(RateLimitService::class),
        $c->get(\Psr\Log\LoggerInterface::class),
        new \AfricaGates\Services\SupportAgentService(
            $c->get(\AfricaGates\Services\AiService::class),
            new \AfricaGates\Services\SupportTicketService($c->get(OtpService::class))
        )
    ),
    DonationController::class     => fn(ContainerInterface $c)=>new DonationController($c->get(PaymentService::class), $c->get(Twig::class), $c->get(RateLimitService::class), $c->get(OtpService::class), $c->get(\Psr\Log\LoggerInterface::class)),
    PaidVoteController::class     => fn(ContainerInterface $c)=>new PaidVoteController($c->get(PaymentService::class), $c->get(Twig::class), $c->get(RateLimitService::class), $c->get(\Psr\Log\LoggerInterface::class)),
    \AfricaGates\Admin\Controllers\AssistantController::class => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\AssistantController($c->get(Twig::class), $c->get(RateLimitService::class), $c->get(\Psr\Log\LoggerInterface::class)),
    FlierController::class        => fn(ContainerInterface $c)=>new FlierController($c->get(Twig::class), new \AfricaGates\Services\FlierService()),
    ActivityController::class     => fn(ContainerInterface $c)=>new ActivityController($c->get(Twig::class), new \AfricaGates\Services\ActivityFeedService()),
    // Support assistant. The agent gets AiService (Groq + Gemini, whichever the
    // admin configured) and the ticket service; the ticket service gets the
    // mailer so an escalation can actually reach somebody.
    \AfricaGates\Controllers\SupportController::class => fn(ContainerInterface $c)=>new \AfricaGates\Controllers\SupportController(
        $c->get(Twig::class),
        new \AfricaGates\Services\SupportAgentService(
            $c->get(\AfricaGates\Services\AiService::class),
            new \AfricaGates\Services\SupportTicketService($c->get(OtpService::class))
        ),
        new \AfricaGates\Services\SupportTicketService($c->get(OtpService::class)),
        $c->get(RateLimitService::class)
    ),
    // Refunds. Gets the mailer so the buyer is told, and the auditor because
    // money leaving needs a name against it.
    \AfricaGates\Admin\Controllers\RefundsController::class => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\RefundsController(
        $c->get(Twig::class),
        $c->get(OtpService::class),
        $c->get(AuditService::class)
    ),
    // Vote delivery. Audited, because delivering writes to a public tally and
    // "who did this and when" has to be answerable months later.
    \AfricaGates\Admin\Controllers\VoteDeliveryController::class => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\VoteDeliveryController(
        $c->get(Twig::class),
        $c->get(AuditService::class)
    ),
    // The support queue. Gets the ticket service so a staff reply travels the
    // same path as an automated one — mailed, and recorded on the member's thread.
    // Payment triage. Gets the audit service so a repair — which confirms orders and
    // moves money — is recorded against the admin who pressed the button.
    \AfricaGates\Admin\Controllers\PaymentsTriageController::class => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\PaymentsTriageController(
        $c->get(Twig::class),
        $c->get(\AfricaGates\Admin\Services\AuditService::class)
    ),
    // Judging interviews. Takes the mailer and the SMS gateway because an invitation that
    // reaches nobody is the whole failure mode of an appointment — and the audit service,
    // because publishing a nominee's recorded words to the panel is a decision with a
    // person's name on it.
    \AfricaGates\Admin\Controllers\InterviewsController::class => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\InterviewsController(
        $c->get(Twig::class),
        $c->get(\AfricaGates\Admin\Services\AuditService::class),
        $c->get(OtpService::class),
        \AfricaGates\Services\SmsService::boot()
    ),
    \AfricaGates\Controllers\InterviewController::class => fn(ContainerInterface $c)=>new \AfricaGates\Controllers\InterviewController(
        $c->get(Twig::class)
    ),
    // The nominee questionnaire. Same shape as interviews: the mailer and SMS gateway,
    // because a questionnaire nobody is told about is a table nobody fills in.
    \AfricaGates\Admin\Controllers\QuestionnairesController::class => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\QuestionnairesController(
        $c->get(Twig::class),
        $c->get(\AfricaGates\Admin\Services\AuditService::class),
        $c->get(OtpService::class),
        \AfricaGates\Services\SmsService::boot()
    ),
    \AfricaGates\Controllers\MyWorkController::class => fn(ContainerInterface $c)=>new \AfricaGates\Controllers\MyWorkController(
        $c->get(Twig::class)
    ),
    \AfricaGates\Admin\Controllers\SupportController::class => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\SupportController(
        $c->get(Twig::class),
        new \AfricaGates\Services\SupportTicketService($c->get(OtpService::class))
    ),
    // Nominee page claiming. Gets the mailer AND the SMS gateway, because §5 of
    // docs/CLAIM-FAIRNESS-AND-FRAUD.md turns on reaching a channel the claimant could
    // not control — with email alone the fan-out cannot do the one job it exists for.
    // The ticket service so a HELD claim lands in front of a person rather than
    // stopping at a message on a page, and RateLimitService so a farmer cannot walk a
    // whole category (§4, attacker 5).
    \AfricaGates\Controllers\ClaimController::class => fn(ContainerInterface $c)=>new \AfricaGates\Controllers\ClaimController(
        $c->get(Twig::class),
        new \AfricaGates\Services\NomineeClaimService(
            $c->get(OtpService::class),
            \AfricaGates\Services\SmsService::boot(),
            new \AfricaGates\Services\SupportTicketService($c->get(OtpService::class)),
            $c->get(RateLimitService::class),
        )
    ),
    // The Help Centre reads a corpus of literals in HelpCentre, so it needs
    // nothing but a renderer — no cache, no database, no gateway. That is also
    // why /help keeps working on a database that is mid-migration, which is
    // exactly when somebody is most likely to be looking for help.
    \AfricaGates\Controllers\HelpController::class => fn(ContainerInterface $c)=>new \AfricaGates\Controllers\HelpController($c->get(Twig::class)),
    PulseController::class        => fn(ContainerInterface $c)=>new PulseController($c->get(Twig::class), $c->get(CacheService::class), $c->get(ProfileService::class), $c->get(CommunityService::class), $c->get(RateLimitService::class), $c->get(OtpService::class), new \AfricaGates\Services\PulseFeedService(), new \AfricaGates\Services\PulseMediaService($c->get(UploadService::class), new \AfricaGates\Services\R2Service(null, $c->get(\Psr\Log\LoggerInterface::class)), new \AfricaGates\Services\MediaModerationService())),
    VoteController::class        => fn(ContainerInterface $c)=>new VoteController($c->get(Twig::class), $c->get(CacheService::class), $c->get(AwardService::class), $c->get(PaymentService::class)),
    CommunityController::class   => fn(ContainerInterface $c)=>new CommunityController($c->get(Twig::class), $c->get(CommunityService::class), $c->get(CacheService::class), $c->get(OtpService::class), $c->get(RateLimitService::class)),
    JudgeAuthController::class   => fn(ContainerInterface $c)=>new JudgeAuthController($c->get(Twig::class), $c->get(JudgeService::class), $c->get(OtpService::class), $c->get(RateLimitService::class)),
    JudgeBallotController::class => fn(ContainerInterface $c)=>new JudgeBallotController($c->get(Twig::class), $c->get(JudgeService::class)),

    // Admin controllers
    AdminAuthController::class         => fn(ContainerInterface $c)=>new AdminAuthController($c->get(Twig::class), $c->get(AuthService::class), $c->get(LogService::class), $c->get(OtpService::class), $c->get(RateLimitService::class)),
    // The mailer is here for ONE thing: emailing a stalled schedule. A run that has
    // stopped cannot report its own stall, so the alert has to leave from a page load.
    AdminDashboardController::class    => fn(ContainerInterface $c)=>new AdminDashboardController($c->get(Twig::class), $c->get(AuditService::class), $c->get(OtpService::class)),
    AdminProfilesController::class     => fn(ContainerInterface $c)=>new AdminProfilesController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminNominationsController::class  => fn(ContainerInterface $c)=>new AdminNominationsController($c->get(Twig::class), $c->get(AuditService::class), $c->get(OtpService::class), $c->get(AwardService::class)),
    AdminModerationController::class   => fn(ContainerInterface $c)=>new AdminModerationController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminProgrammesController::class   => fn(ContainerInterface $c)=>new AdminProgrammesController($c->get(Twig::class), $c->get(AuditService::class), $c->get(CacheService::class)),
    AdminNomineesController::class     => fn(ContainerInterface $c)=>new AdminNomineesController($c->get(Twig::class), $c->get(AuditService::class), $c->get(UploadService::class)),
    AdminLegacyController::class       => fn(ContainerInterface $c)=>new AdminLegacyController($c->get(Twig::class), $c->get(AuditService::class), $c->get(UploadService::class)),
    AdminOpportunitiesController::class=> fn(ContainerInterface $c)=>new AdminOpportunitiesController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminEventsController::class       => fn(ContainerInterface $c)=>new AdminEventsController($c->get(Twig::class), $c->get(AuditService::class), $c->get(CacheService::class)),
    AdminRegistrationsController::class => fn(ContainerInterface $c)=>new AdminRegistrationsController($c->get(Twig::class)),
    AdminDataController::class          => fn(ContainerInterface $c)=>new AdminDataController($c->get(Twig::class)),
    AdminFormsController::class         => fn(ContainerInterface $c)=>new AdminFormsController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminPostsController::class        => fn(ContainerInterface $c)=>new AdminPostsController($c->get(Twig::class), $c->get(AuditService::class), $c->get(CacheService::class), $c->get(CommunityService::class)),
    AdminPartnersController::class     => fn(ContainerInterface $c)=>new AdminPartnersController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminJudgesController::class       => fn(ContainerInterface $c)=>new AdminJudgesController($c->get(Twig::class), $c->get(AuditService::class), $c->get(UploadService::class), $c->get(OtpService::class)),
    AdminWebhooksController::class     => fn(ContainerInterface $c)=>new AdminWebhooksController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminAdminsController::class       => fn(ContainerInterface $c)=>new AdminAdminsController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminSettingsController::class     => fn(ContainerInterface $c)=>new AdminSettingsController($c->get(Twig::class), $c->get(SettingsService::class), $c->get(AuditService::class), $c->get(OtpService::class)),
    AdminAwardsPageController::class   => fn(ContainerInterface $c)=>new AdminAwardsPageController($c->get(Twig::class), $c->get(SettingsService::class), $c->get(AuditService::class)),
    AdminMediaController::class        => fn(ContainerInterface $c)=>new AdminMediaController($c->get(Twig::class), $c->get(AuditService::class)),
    \AfricaGates\Admin\Controllers\LegalController::class    => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\LegalController($c->get(Twig::class), $c->get(AuditService::class)),
    \AfricaGates\Admin\Controllers\AiAssistController::class => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\AiAssistController($c->get(RateLimitService::class)),
    AdminProductsController::class     => fn(ContainerInterface $c)=>new AdminProductsController($c->get(Twig::class), $c->get(AuditService::class), $c->get(UploadService::class)),
    \AfricaGates\Admin\Controllers\UsersController::class => fn(ContainerInterface $c)=>new \AfricaGates\Admin\Controllers\UsersController($c->get(AuditService::class)),
];
