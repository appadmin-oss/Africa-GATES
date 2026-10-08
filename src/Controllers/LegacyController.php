<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use AfricaGates\Services\LegacyVault;
use AfricaGates\Support\Assets;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `/legacy` and `/legacy/{slug}` — the Legacy Vault (Phase 6, LegacyVault.dc.html, §8.18).
 * Every fact from {@see LegacyVault}; filters live in the URL (`?year=&region[]=&q=`).
 *
 * NO COMMENTS OR CHEERS are fetched for an edition: the page renders neither (TemplateContextTest
 * found the old controller querying both, uncached, on every view).
 */
class LegacyController
{
    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $v = LegacyVault::index([
            'q'      => (string) ($p['q'] ?? ''),
            'year'   => (string) ($p['year'] ?? ''),
            'region' => $p['region'] ?? [],
            'sort'   => (string) ($p['sort'] ?? ''),
        ]);
        return $this->view->render($res, 'pages/legacy/index.twig', [
            'page_title'       => 'Legacy Vault — Africa GATES',
            'meta_description' => 'The Africa GATES Legacy Vault: every edition archived — its winners, the night and the sealed record, searchable and forever credited.',
            'gates_page'       => 'legacy',
            'vault'            => $v,
        ]);
    }

    public function event(Request $req, Response $res, array $args): Response
    {
        $ed = LegacyVault::edition((string) ($args['slug'] ?? ''));
        if ($ed === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $e    = $ed['e'];
        $name = $e['award'] . ($e['edition'] !== '' ? ' · ' . $e['edition'] : '');
        $blurb = trim(strip_tags($ed['tagline']));
        $meta = $blurb !== '' ? $blurb : $name . ' — an edition preserved in the Africa GATES Legacy Vault, with its winners and its sealed record.';
        if (mb_strlen($meta) > 160) $meta = rtrim(mb_substr($meta, 0, 157)) . '…';

        return $this->view->render($res, 'pages/legacy/event.twig', [
            'page_title'       => $name . ' — Legacy Vault | Africa GATES',
            'meta_description' => $meta,
            'og_title'         => $name . ' — Africa GATES Legacy Vault',
            'gates_page'       => 'legacy',
            'ed'               => $ed,
        ] + array_filter([
            'og_image'     => Assets::absoluteOg($e['cover'] ?: null),
            'og_image_alt' => $name,
        ], static fn ($v) => $v !== null));
    }
}
