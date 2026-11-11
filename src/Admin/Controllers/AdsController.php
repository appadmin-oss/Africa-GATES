<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdMarket;
use AfricaGates\Services\Ads\AdRules;
use AfricaGates\Services\Ads\AdStaff;
use AfricaGates\Services\UploadService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * /admin/ads — every campaign running on the site, and posting one from the console
 * (owner, 4 Nov 2026: "allow admin be able to post ads"). See Services\Ads\AdStaff for the
 * two kinds a person here can post and why each is labelled as it is.
 *
 * Gated with the money (`finance`: superadmin and admin): a direct sale records income, and
 * putting something on the homepage is the platform speaking. Reviewing outsiders' ads stays
 * in Moderation, where it was.
 */
final class AdsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
        private readonly ?UploadService $uploads = null,
    ) {}

    private function adminId(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    public function index(Request $req, Response $res): Response
    {
        $old = (array) ($_SESSION['ad_staff_old'] ?? []);
        $errors = (array) ($_SESSION['ad_staff_errors'] ?? []);
        unset($_SESSION['ad_staff_old'], $_SESSION['ad_staff_errors']);
        return $this->view->render($res, 'admin/ads/index.twig', [
            'admin_page' => 'ads', 'topbar_title' => 'Ads',
            'campaigns' => AdStaff::all(),
            'placements' => AdRules::PLACEMENTS, 'ctas' => AdRules::CTAS,
            'market' => array_column(AdMarket::card(), null, 'key'),
            'ready' => AdStaff::ready(), 'old' => $old, 'errors' => $errors,
            'limits' => ['headline' => AdRules::HEADLINE_MAX, 'body' => AdRules::BODY_MAX, 'days' => AdStaff::MAX_DAYS],
        ]);
    }

    public function store(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $errors = [];
        $image = $this->upload($req, 'image', 'ad', $errors);
        $logo  = $this->upload($req, 'logo', 'ad_logo', $errors);
        $r = $errors === [] ? AdStaff::post($this->adminId(), $b, $image, $logo) : ['ok' => false, 'errors' => $errors];
        if (!$r['ok']) {
            $_SESSION['ad_staff_old'] = array_intersect_key($b, array_flip(['source', 'advertiser', 'name', 'objective', 'placements',
                'headline', 'body', 'cta', 'url', 'offer', 'offer_code', 'days', 'starts_on', 'budget', 'view_cap']));
            $_SESSION['ad_staff_errors'] = $r['errors'] ?? [];
            $_SESSION['flash_error'] = 'Not posted. ' . implode(' ', array_values($r['errors'] ?? []));
            return $res->withHeader('Location', '/admin/ads#post')->withStatus(303);
        }
        $this->audit?->record($this->adminId(), 'ads.posted', 'ad_campaign', (int) $r['id'], ['source' => (string) ($b['source'] ?? '')]);
        $_SESSION['flash_ok'] = 'Posted. It runs from its start date on the places you chose.';
        return $res->withHeader('Location', '/admin/ads')->withStatus(303);
    }

    public function status(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $to = match ((string) ($args['action'] ?? '')) {
            'pause' => AdCampaigns::ST_PAUSED, 'resume' => AdCampaigns::ST_SUBMITTED, 'end' => AdCampaigns::ST_CANCELLED, default => '',
        };
        $ok = $to !== '' && AdStaff::setStatus($id, $to);
        if ($ok) $this->audit?->record($this->adminId(), 'ads.' . $args['action'], 'ad_campaign', $id);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $ok ? 'Done.' : 'That campaign cannot be changed from here.';
        return $res->withHeader('Location', '/admin/ads')->withStatus(303);
    }

    /** @param array<string,string> $errors */
    private function upload(Request $req, string $field, string $profile, array &$errors): ?string
    {
        $file = $req->getUploadedFiles()[$field] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return null;
        try {
            $r = ($this->uploads ?? new UploadService())->uploadProcessed($file, 'ads', $profile,
                (string) (((array) $req->getParsedBody())[$field . '_crop'] ?? ''), null, 'ad', null);
            return (string) ($r['path'] ?? $r['url'] ?? '') ?: null;
        } catch (\Throwable $e) {
            $errors[$field] = sprintf('Use a JPEG, PNG or WebP image, at least %dpx on each side.',
                \AfricaGates\Support\ImageProfiles::get($profile)['min']);
            return null;
        }
    }
}
