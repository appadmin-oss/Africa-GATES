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

    /**
     * The list's filters, as Ads Manager's: what is running, what is waiting on a person,
     * what staff posted and what advertisers booked themselves. Counted from the same rows
     * the list draws, so a tab's number is the number of rows it shows.
     */
    public const SHOWS = [
        'all'    => 'All',
        'live'   => 'Running',
        'review' => 'Waiting',
        'staff'  => 'Posted by us',
        'self'   => 'Self-serve',
    ];

    /** @param array<string,mixed> $c */
    public static function inShow(array $c, string $show): bool
    {
        return match ($show) {
            'live'   => in_array($c['delivery'], ['active', 'scheduled', 'paused'], true),
            'review' => in_array($c['delivery'], ['in_review', 'unpaid'], true),
            'staff'  => (bool) $c['staff'],
            'self'   => !$c['staff'],
            default  => true,
        };
    }

    public function index(Request $req, Response $res): Response
    {
        $old = (array) ($_SESSION['ad_staff_old'] ?? []);
        $errors = (array) ($_SESSION['ad_staff_errors'] ?? []);
        unset($_SESSION['ad_staff_old'], $_SESSION['ad_staff_errors']);
        $show = (string) ($req->getQueryParams()['show'] ?? 'all');
        if (!isset(self::SHOWS[$show])) $show = 'all';
        $all = AdStaff::all();
        $tabs = [];
        foreach (self::SHOWS as $k => $label) {
            $tabs[] = ['key' => $k, 'label' => $label, 'on' => $k === $show, 'href' => $k === 'all' ? '/admin/ads' : '/admin/ads?show=' . $k,
                       'n' => count(array_filter($all, static fn ($c) => self::inShow($c, $k)))];
        }
        $rev = AdMarket::revenue();
        return $this->view->render($res, 'admin/ads/index.twig', [
            'admin_page' => 'ads', 'topbar_title' => 'Ads',
            'campaigns' => array_values(array_filter($all, static fn ($c) => self::inShow($c, $show))),
            'tabs' => $tabs, 'show' => $show,
            // The month's advertising money against its target (AdMarket::revenue), the
            // figure the whole page exists to move.
            'rev' => ['earned' => \AfricaGates\Services\Ads\AdRateCard::format($rev['earned_kobo']),
                      'projected' => \AfricaGates\Services\Ads\AdRateCard::format($rev['projected_kobo']),
                      'target' => $rev['target_kobo'] !== null ? \AfricaGates\Services\Ads\AdRateCard::format($rev['target_kobo']) : null,
                      'gap' => $rev['gap_kobo'] ? \AfricaGates\Services\Ads\AdRateCard::format($rev['gap_kobo']) : null,
                      'on_track' => $rev['on_track']],
            'placements' => AdRules::PLACEMENTS, 'ctas' => AdRules::CTAS, 'shapes' => AdRules::SHAPES,
            'video' => ['on' => \AfricaGates\Services\Ads\AdVideo::ready(), 'max' => \AfricaGates\Services\Ads\AdVideo::MAX_SECONDS, 'mb' => \AfricaGates\Services\Ads\AdVideo::limitMb()],
            'market' => array_column(AdMarket::card(), null, 'key'), 'window' => AdMarket::WINDOW_DAYS,
            'ready' => AdStaff::ready(), 'old' => $old, 'errors' => $errors,
            'limits' => ['headline' => AdRules::HEADLINE_MAX, 'body' => AdRules::BODY_MAX, 'days' => AdStaff::MAX_DAYS],
        ]);
    }

    /**
     * GET /admin/ads/{id} — one campaign, any kind, as its advertiser sees it plus what
     * staff need beside it: the money, who posted it, each version with its review and its
     * strength, by place and by day, pacing and the advice the advertiser is being given —
     * so the person answering "why is my ad not doing anything?" is reading the same page.
     */
    public function show(Request $req, Response $res, array $args): Response
    {
        $c = AdCampaigns::detail((int) ($args['id'] ?? 0), null);
        if ($c === null) {
            $_SESSION['flash_error'] = 'There is no such campaign.';
            return $res->withHeader('Location', '/admin/ads')->withStatus(302);
        }
        unset($c['_set']);
        $row = \Illuminate\Database\Capsule\Manager::table('gates_ad_campaigns')->where('id', $c['id'])->first();
        $by = $row && ($row->posted_by ?? null) ? \Illuminate\Database\Capsule\Manager::table('gates_admins')->where('id', (int) $row->posted_by)->value('name') : null;
        $owner = $row && (int) $row->user_id > 0 ? \Illuminate\Database\Capsule\Manager::table('gates_users')->where('id', (int) $row->user_id)->value('name') : null;
        return $this->view->render($res, 'admin/ads/show.twig', [
            'admin_page' => 'ads', 'topbar_title' => 'Ads',
            'c' => $c, 'placements' => AdRules::PLACEMENTS, 'shapes' => AdRules::SHAPES,
            'source_label' => $c['trial'] ? 'Free trial' : (AdRules::SOURCES[$c['source']] ?? $c['source']),
            'staff' => in_array($c['source'], ['direct', 'house'], true),
            'posted_by' => $by ? (string) $by : null, 'owner' => $owner ? (string) $owner : null,
        ]);
    }

    public function store(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $errors = [];
        if (($verr = \AfricaGates\Services\Ads\AdVideo::take($req->getUploadedFiles(), $b)) !== null) $errors['video'] = $verr;
        $image = $this->upload($req, 'image', AdRules::imageProfile($b['shape'] ?? null), $errors);
        $logo  = $this->upload($req, 'logo', 'ad_logo', $errors);
        $r = $errors === [] ? AdStaff::post($this->adminId(), $b, $image, $logo) : ['ok' => false, 'errors' => $errors];
        if (!$r['ok']) {
            $_SESSION['ad_staff_old'] = array_intersect_key($b, array_flip(['source', 'advertiser', 'name', 'objective', 'placements', 'shape',
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
        $back = ((array) $req->getParsedBody())['back'] ?? '';
        return $res->withHeader('Location', $back === 'show' ? '/admin/ads/' . $id : '/admin/ads')->withStatus(303);
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
