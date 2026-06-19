<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService, AwardService};

class VoteController {
    public function __construct(
        private readonly Twig         $view,
        private readonly CacheService $cache,
        private readonly AwardService $awards
    ) {}

    public function index(Request $req, Response $res): Response {
        $awardsData = $this->cache->remember('awards:active', 1800,
            fn() => $this->awards->getActiveProgrammesWithStatus()
        );
        return $this->view->render($res, 'pages/vote.twig', [
            'page_title'  => 'Cast Your Vote — Africa GATES | Afrovanguard',
            'meta_description' => 'Cast your vote in the Africa GATES awards. Back the African creatives, businesses and organisations you believe deserve continental cultural recognition.',
            'gates_page'  => 'awards',
            'has_hero'    => false,
            'current_section' => 'projects',
            'awards_data' => $awardsData,
            'turnstile_site_key' => $_ENV['TURNSTILE_SITE_KEY'] ?? '',
            'breadcrumbs' => [
                ['label' => 'Afrovanguard', 'url' => '/'],
                ['label' => 'Africa GATES', 'url' => '/'],
                ['label' => 'Awards',       'url' => '/awards'],
                ['label' => 'Vote',         'url' => '/vote'],
            ],
        ]);
    }
}
