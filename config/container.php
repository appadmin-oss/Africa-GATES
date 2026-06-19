<?php
declare(strict_types=1);
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,ProfileService,AwardService,LegacyService,OpportunityService,OtpService,VoteService,BonusVoteService,RateLimitService,SpamService,CommunityService,GoogleSheetsService,TurnstileService,StatsService,FraudService,EventService,MilestoneService,PaymentService};
use AfricaGates\Controllers\{HomeController,ApiController,RegistryController,AwardsController,LeaderboardController,LegacyController,OpportunityController,NominationController,PartnerController,VoteController,CommunityController,EventsController,BlogController,PaymentController};
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

return [
    Twig::class => function(ContainerInterface $c) {
        $isDev = ($_ENV['APP_ENV'] ?? 'production') !== 'production';
        $twig = Twig::create(__DIR__.'/../templates', [
            'cache' => ($_ENV['TWIG_CACHE'] ?? 'false') === 'true' ? __DIR__.'/../var/cache/twig' : false,
            'auto_reload' => true,
            'debug' => $isDev,
        ]);
        // Load runtime settings if available (overrides env defaults)
        $settings = [];
        try { $settings = $c->get(SettingsService::class)->all(); } catch (\Throwable $e) {}

        $globals = [
            // Flags for embedding data in <script> blocks: hex-escape < ' " &
            // (defense-in-depth over Twig's default slash-escaping).
            'JSON_SAFE'         => JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP,
            // Demo mode: gates clearly-labeled sample/illustrative content so it
            // is never shown as real data in production (APP_ENV=demo only).
            'is_demo'           => (($_ENV['APP_ENV'] ?? 'production') === 'demo'),
            'app_url'           => rtrim($_ENV['APP_URL'] ?? '', '/'),
            // In debug/dev, derive from the redesign stylesheet's mtime so every
            // CSS edit busts the browser cache automatically; in prod use the
            // pinned ASSET_VERSION (set at deploy) for stable far-future caching.
            'asset_version'     => (($_ENV['APP_DEBUG'] ?? 'false') === 'true')
                ? (string) (@filemtime(__DIR__ . '/../public/assets/css/redesign-2026.css') ?: 'dev')
                : ($_ENV['ASSET_VERSION'] ?? 'v1'),
            'csrf_token'        => $_SESSION['csrf_token'] ?? '',
            'current_section'   => 'projects',
            'has_hero'          => false,
            'announcement_text' => $settings['announce_text'] ?? ($_ENV['ANNOUNCE_TEXT'] ?? 'Nominations open — live in Nigeria, building toward 54'),
            'announcement_url'  => $settings['announce_url']  ?? '/africa-gates/nominate',
            'announcement_cta'  => $settings['announce_cta']  ?? 'Nominate now →',
            'gas_url'           => $_ENV['GAS_URL'] ?? '',
            'admin_name'        => $_SESSION['admin_name']  ?? null,
            'admin_role'        => $_SESSION['admin_role']  ?? null,
            'admin_email'       => $_SESSION['admin_email'] ?? null,
            'judge_id'          => $_SESSION['judge_id']    ?? null,
            'judge_name'        => $_SESSION['judge_name']  ?? null,
            'judge_email'       => $_SESSION['judge_email'] ?? null,
            // Per-request canonical/og:url inputs (were undefined → every page
            // self-reported as the homepage). site_url tracks APP_URL so canonical
            // + Open Graph use the real deployed host.
            'request_path'      => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'site_url'          => rtrim($_ENV['APP_URL'] ?? '', '/') ?: 'https://afg.afrovanguard.org.ng',
            'flash_ok'          => $_SESSION['flash_ok']    ?? null,
            'flash_error'       => $_SESSION['flash_error'] ?? null,
            'flash_notice'      => $_SESSION['flash_notice'] ?? null,
        ];
        foreach ($globals as $k => $v) $twig->getEnvironment()->addGlobal($k, $v);
        // Allowlist-sanitise admin-authored rich text (blog/legacy bodies) at render
        // time — used instead of |raw so stored HTML can't inject script/handlers.
        $twig->getEnvironment()->addFilter(new \Twig\TwigFilter(
            'sanitize_html',
            [\AfricaGates\Support\Html::class, 'sanitize'],
            ['is_safe' => ['html']]
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
    FraudService::class         => fn(ContainerInterface $c)=>new FraudService($c->get(\Psr\Log\LoggerInterface::class)),
    EventService::class         => fn()=>new EventService(),
    MilestoneService::class     => fn(ContainerInterface $c)=>new MilestoneService($c->get(OtpService::class), $c->get(EventService::class), $c->get(\Psr\Log\LoggerInterface::class)),
    TurnstileService::class     => fn(ContainerInterface $c)=>new TurnstileService(
        (string)($_ENV['TURNSTILE_SECRET'] ?? ''),
        $c->get(\Psr\Log\LoggerInterface::class)
    ),
    SpamService::class          => fn()=>new SpamService(
        $_ENV['GROQ_API_KEY']      ?? null,
        $_ENV['GEMINI_API_KEY']    ?? null,
        $_ENV['ANTHROPIC_API_KEY'] ?? null,
        $_ENV['OPENAI_API_KEY']    ?? null
    ),
    CommunityService::class     => fn(ContainerInterface $c)=>new CommunityService($c->get(SpamService::class)),
    JudgeService::class         => fn()=>new JudgeService(),
    GoogleSheetsService::class  => fn(ContainerInterface $c)=>new GoogleSheetsService(
        (string)($_ENV['GAS_URL'] ?? ''),
        $c->has(\AfricaGates\Admin\Services\LogService::class) ? $c->get(\AfricaGates\Admin\Services\LogService::class) : null
    ),
    OtpService::class           => fn(ContainerInterface $c)=>new OtpService([
        'host' => $_ENV['SMTP_HOST'] ?? 'smtp-relay.brevo.com',
        'port' => (int)($_ENV['SMTP_PORT'] ?? 587),
        'username' => $_ENV['SMTP_USER'] ?? '',
        'password' => $_ENV['SMTP_PASS'] ?? '',
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@afrovanguard.org.ng',
        'from_name'    => $_ENV['MAIL_FROM_NAME'] ?? 'Africa GATES',
    ], $c->get(\Psr\Log\LoggerInterface::class)),

    // Admin services
    LogService::class       => fn()=>new LogService(),
    AuditService::class     => fn()=>new AuditService(),
    SettingsService::class  => fn()=>new SettingsService(),
    UploadService::class    => fn()=>new UploadService(),
    AdminValidator::class   => fn()=>new AdminValidator(),
    AuthService::class      => fn(ContainerInterface $c)=>new AuthService($c->get(LogService::class), $c->get(AuditService::class), $c->get(RateLimitService::class)),

    // Public controllers
    HomeController::class        => fn(ContainerInterface $c)=>new HomeController($c->get(Twig::class), $c->get(CacheService::class), $c->get(ProfileService::class), $c->get(AwardService::class), $c->get(LegacyService::class), $c->get(OpportunityService::class), $c->get(StatsService::class)),
    ApiController::class         => fn(ContainerInterface $c)=>new ApiController($c->get(CacheService::class), $c->get(ProfileService::class), $c->get(AwardService::class), $c->get(VoteService::class), $c->get(OtpService::class), $c->get(RateLimitService::class), $c->get(GoogleSheetsService::class), $c->get(CommunityService::class), $c->get(TurnstileService::class), $c->get(FraudService::class), $c->get(EventService::class), $c->get(MilestoneService::class)),
    RegistryController::class    => fn(ContainerInterface $c)=>new RegistryController($c->get(Twig::class), $c->get(CacheService::class), $c->get(ProfileService::class), $c->get(RateLimitService::class), $c->get(GoogleSheetsService::class), $c->get(CommunityService::class), $c->get(OtpService::class)),
    AwardsController::class      => fn(ContainerInterface $c)=>new AwardsController($c->get(Twig::class), $c->get(CacheService::class), $c->get(AwardService::class)),
    LeaderboardController::class => fn(ContainerInterface $c)=>new LeaderboardController($c->get(Twig::class), $c->get(CacheService::class), $c->get(ProfileService::class)),
    LegacyController::class      => fn(ContainerInterface $c)=>new LegacyController($c->get(Twig::class), $c->get(CacheService::class), $c->get(LegacyService::class), $c->get(CommunityService::class)),
    OpportunityController::class => fn(ContainerInterface $c)=>new OpportunityController($c->get(Twig::class), $c->get(CacheService::class), $c->get(OpportunityService::class)),
    EventsController::class      => fn(ContainerInterface $c)=>new EventsController($c->get(Twig::class), $c->get(CacheService::class)),
    BlogController::class        => fn(ContainerInterface $c)=>new BlogController($c->get(Twig::class), $c->get(CacheService::class)),
    NominationController::class  => fn(ContainerInterface $c)=>new NominationController($c->get(Twig::class), $c->get(CacheService::class), $c->get(AwardService::class), $c->get(RateLimitService::class), $c->get(GoogleSheetsService::class), $c->get(CommunityService::class), $c->get(OtpService::class)),
    PartnerController::class     => fn(ContainerInterface $c)=>new PartnerController($c->get(Twig::class), $c->get(RateLimitService::class), $c->get(GoogleSheetsService::class), $c->get(OtpService::class), $c->get(PaymentService::class)),
    PaymentController::class     => fn(ContainerInterface $c)=>new PaymentController($c->get(PaymentService::class), $c->get(Twig::class), $c->get(\Psr\Log\LoggerInterface::class)),
    VoteController::class        => fn(ContainerInterface $c)=>new VoteController($c->get(Twig::class), $c->get(CacheService::class), $c->get(AwardService::class)),
    CommunityController::class   => fn(ContainerInterface $c)=>new CommunityController($c->get(Twig::class), $c->get(CommunityService::class), $c->get(CacheService::class), $c->get(OtpService::class), $c->get(RateLimitService::class)),
    JudgeAuthController::class   => fn(ContainerInterface $c)=>new JudgeAuthController($c->get(Twig::class), $c->get(JudgeService::class), $c->get(OtpService::class), $c->get(RateLimitService::class)),
    JudgeBallotController::class => fn(ContainerInterface $c)=>new JudgeBallotController($c->get(Twig::class), $c->get(JudgeService::class)),

    // Admin controllers
    AdminAuthController::class         => fn(ContainerInterface $c)=>new AdminAuthController($c->get(Twig::class), $c->get(AuthService::class), $c->get(LogService::class), $c->get(OtpService::class), $c->get(RateLimitService::class)),
    AdminDashboardController::class    => fn(ContainerInterface $c)=>new AdminDashboardController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminProfilesController::class     => fn(ContainerInterface $c)=>new AdminProfilesController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminNominationsController::class  => fn(ContainerInterface $c)=>new AdminNominationsController($c->get(Twig::class), $c->get(AuditService::class), $c->get(OtpService::class)),
    AdminProgrammesController::class   => fn(ContainerInterface $c)=>new AdminProgrammesController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminNomineesController::class     => fn(ContainerInterface $c)=>new AdminNomineesController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminLegacyController::class       => fn(ContainerInterface $c)=>new AdminLegacyController($c->get(Twig::class), $c->get(AuditService::class), $c->get(UploadService::class)),
    AdminOpportunitiesController::class=> fn(ContainerInterface $c)=>new AdminOpportunitiesController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminEventsController::class       => fn(ContainerInterface $c)=>new AdminEventsController($c->get(Twig::class), $c->get(AuditService::class), $c->get(CacheService::class)),
    AdminPostsController::class        => fn(ContainerInterface $c)=>new AdminPostsController($c->get(Twig::class), $c->get(AuditService::class), $c->get(CacheService::class)),
    AdminPartnersController::class     => fn(ContainerInterface $c)=>new AdminPartnersController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminJudgesController::class       => fn(ContainerInterface $c)=>new AdminJudgesController($c->get(Twig::class), $c->get(AuditService::class), $c->get(UploadService::class), $c->get(OtpService::class)),
    AdminAdminsController::class       => fn(ContainerInterface $c)=>new AdminAdminsController($c->get(Twig::class), $c->get(AuditService::class)),
    AdminSettingsController::class     => fn(ContainerInterface $c)=>new AdminSettingsController($c->get(Twig::class), $c->get(SettingsService::class), $c->get(AuditService::class), $c->get(OtpService::class)),
];
