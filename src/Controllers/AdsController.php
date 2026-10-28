<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdDelivery;
use AfricaGates\Services\Ads\AdRateCard;
use AfricaGates\Services\Ads\AdRules;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Services\UserAccountService;
use AfricaGates\Services\VisitTracker;
use AfricaGates\Support\FormErrors;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Ads: the public rate card (/advertise), the advertiser's Ads Manager (/account/ads), and
 * the two endpoints a drawn ad reports through (/ads/seen, /ads/go/{token}).
 *
 * Everything an advertiser is told is read from AdRules and AdRateCard, never typed into a
 * template: the trial's days and views, the character limits, the prices.
 */
final class AdsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly UserAccountService $accounts,
        private readonly ?RateLimitService $rateLimit = null,
        private readonly ?UploadService $uploads = null,
    ) {}

    /** GET /advertise — what an ad is, where it runs and what it costs, before anybody signs up. */
    public function advertise(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'pages/advertise.twig', [
            'app_bar' => ['variant' => 'child', 'title' => 'Advertise', 'back' => '/', 'share' => false],
            'page_title' => 'Advertise — Africa GATES',
            'meta_description' => 'Put your business, product or event in front of the people on Africa GATES.',
            'placements' => AdRules::PLACEMENTS, 'rates' => AdRateCard::all(),
            'trial' => ['days' => AdRules::TRIAL_DAYS, 'views' => AdRules::TRIAL_VIEWS],
            // A drawn example, in the real card, so an advertiser sees the format before
            // signing up. Labelled an example; it links nowhere.
            'sample' => [
                'advertiser' => 'Mama Put Kitchen', 'initials' => 'MP', 'logo' => null,
                'body' => 'Party trays of jollof, fried rice and small chops, delivered across Lagos the same day.',
                'image' => null, 'headline' => 'Jollof by the tray', 'domain' => 'mamaput.ng',
                'offer' => '10% off this week', 'offer_code' => 'TRAY10', 'cta' => 'Shop now', 'url' => '#', 'external' => false,
            ],
        ]);
    }

    /**
     * GET /advertise/library — every ad running now, who is behind it and since when. Meta's
     * Ad Library and Google's Ads Transparency Center are the model: anybody can see what is
     * being advertised here, without signing in. `?from=<ad id>` narrows it to that ad's
     * advertiser (the card's "More ads from" link).
     */
    public function library(Request $req, Response $res): Response
    {
        $from = (int) ($req->getQueryParams()['from'] ?? 0);
        $lib = AdCampaigns::library($from > 0 ? $from : null);
        return $this->view->render($res, 'pages/advertise-library.twig', [
            'app_bar' => ['variant' => 'child', 'title' => 'Ad library', 'back' => '/advertise', 'share' => false],
            'page_title' => 'Ad library — Africa GATES',
            'meta_description' => 'Every ad running on Africa GATES, who placed it and since when.',
            'meta_robots' => 'noindex, follow',
            'lib' => $lib, 'placements' => AdRules::PLACEMENTS,
        ]);
    }

    /** GET /account/ads — the manager: every campaign, its state and its results. */
    public function index(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->toLogin($res, '/account/ads');
        $mine = AdCampaigns::mine((int) $user->id);
        foreach ($mine as &$c) unset($c['_set']);
        unset($c);

        return $this->view->render($res, 'pages/account/ads.twig', [
            'app_bar' => ['variant' => 'child', 'title' => 'Ads', 'back' => '/account', 'share' => false],
            'page_title' => 'Ads — Africa GATES', 'gates_page' => 'account',
            'campaigns' => $mine, 'trial_left' => !AdCampaigns::trialUsed((int) $user->id), 'rules' => self::rules(),
        ]);
    }

    /** GET /account/ads/new — one page in Meta's three steps: campaign, ad set, ad. */
    public function create(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->toLogin($res, '/account/ads/new');
        $form = FormErrors::take('ad');
        $est = AdDelivery::estimate(array_keys(AdRules::PLACEMENTS), AdRules::TRIAL_DAYS);

        return $this->view->render($res, 'pages/account/ads-new.twig', [
            'app_bar' => ['variant' => 'child', 'title' => 'New campaign', 'back' => '/account/ads', 'share' => false],
            'page_title' => 'New campaign — Africa GATES', 'gates_page' => 'account',
            'trial_left' => !AdCampaigns::trialUsed((int) $user->id),
            'nominee_open' => AdRules::inOpenAward((int) $user->id),
            'rules' => self::rules(), 'estimate' => $est,
            'errors' => $form['errors'] ?? [], 'old' => $form['old'] ?? [], 'summary' => $form['summary'] ?? '',
        ]);
    }

    /** POST /account/ads — create the campaign and send its ad for review. */
    public function store(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->toLogin($res, '/account/ads/new');
        $b = (array) $req->getParsedBody();
        $f = FormErrors::for('ad')->keep($b);

        if ($this->rateLimit && !$this->rateLimit->check('ads:' . (int) $user->id, 'ads_create', 10, 86400)) {
            $f->summary('That is a lot of campaigns for one day. Try again tomorrow.')->flash();
            return $this->back($res);
        }
        if (AdCampaigns::trialUsed((int) $user->id)) {
            // Paid campaigns are not open yet: the money needs its own line in finance before
            // it can be taken (see the commit that added this). Said, not hidden.
            $f->summary('Your free trial is used. Paid campaigns open soon.')->flash();
            return $this->back($res);
        }

        $image = $this->image($req, $f);
        $logo  = $this->image($req, $f, 'logo', 200);
        $r = AdCampaigns::createTrial((int) $user->id, $b, $image, $logo);
        if (!$r['ok']) {
            foreach ($r['errors'] ?? [] as $k => $m) { $k === 'form' ? $f->summary($m) : $f->add($k, $m); }
            $f->flash();
            return $this->back($res);
        }
        $_SESSION['flash_ok'] = 'Sent for review. Your trial starts when a person approves it.';
        return $res->withHeader('Location', '/account/ads/' . (int) $r['id'])->withStatus(303);
    }

    /** GET /account/ads/{id} — one campaign: its ads, its results by day, its controls. */
    public function show(Request $req, Response $res, array $args): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->toLogin($res, '/account/ads');
        $c = AdCampaigns::detail((int) ($args['id'] ?? 0), (int) $user->id);
        if ($c === null) return $res->withHeader('Location', '/account/ads')->withStatus(302);
        unset($c['_set']);
        $form = FormErrors::take('ad_fix');

        return $this->view->render($res, 'pages/account/ads-campaign.twig', [
            'app_bar' => ['variant' => 'child', 'title' => $c['name'], 'back' => '/account/ads', 'share' => false],
            'page_title' => $c['name'] . ' — Ads — Africa GATES', 'gates_page' => 'account',
            'c' => $c, 'rules' => self::rules(),
            'errors' => $form['errors'] ?? [], 'old' => $form['old'] ?? [], 'fixing' => (int) ($form['old']['ad_id'] ?? 0),
        ]);
    }

    /**
     * GET /account/ads/{id}/results.csv — every day and place the campaign was shown. Only
     * its owner's, and only totals: nothing in it says who saw an ad, because nothing does.
     */
    public function export(Request $req, Response $res, array $args): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->toLogin($res, '/account/ads');
        $id = (int) ($args['id'] ?? 0);
        $rows = AdCampaigns::exportRows($id, (int) $user->id);
        if ($rows === null) return $res->withHeader('Location', '/account/ads')->withStatus(302);
        $fh = fopen('php://temp', 'r+');
        \AfricaGates\Support\Csv::put($fh, ['day', 'place', 'views', 'clicks', 'click_rate_pct'], ',', '"', '');
        foreach ($rows as $r) {
            \AfricaGates\Support\Csv::put($fh, [$r['day'], AdRules::PLACEMENTS[$r['placement']] ?? $r['placement'], $r['views'], $r['clicks'],
                $r['views'] > 0 ? round(100 * $r['clicks'] / $r['views'], 2) : ''], ',', '"', '');
        }
        rewind($fh);
        $res->getBody()->write((string) stream_get_contents($fh));
        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="ad-campaign-' . $id . '-results.csv"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** POST /account/ads/{id}/{pause|resume|cancel} — the advertiser's own switch. */
    public function status(Request $req, Response $res, array $args): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->toLogin($res, '/account/ads');
        $id = (int) ($args['id'] ?? 0);
        $to = match ((string) ($args['action'] ?? '')) {
            'pause' => AdCampaigns::ST_PAUSED, 'resume' => AdCampaigns::ST_SUBMITTED, 'cancel' => AdCampaigns::ST_CANCELLED, default => '',
        };
        $ok = $to !== '' && AdCampaigns::setStatus($id, (int) $user->id, $to);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $ok
            ? ['paused' => 'Paused.', 'submitted' => 'Running again.', 'cancelled' => 'Cancelled.'][$to]
            : 'That did not change anything.';
        return $res->withHeader('Location', '/account/ads/' . $id)->withStatus(303);
    }

    /** POST /account/ads/ad/{id} — an ad edited (or a refused one fixed) and sent for review again. */
    public function resubmit(Request $req, Response $res, array $args): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->toLogin($res, '/account/ads');
        $adId = (int) ($args['id'] ?? 0);
        $ad = AdCampaigns::ownAd($adId, (int) $user->id);
        if ($ad === null) return $res->withHeader('Location', '/account/ads')->withStatus(302);
        $campaignId = (int) (\Illuminate\Database\Capsule\Manager::table('gates_ad_sets')->where('id', $ad->ad_set_id)->value('campaign_id') ?? 0);

        $b = (array) $req->getParsedBody();
        $f = FormErrors::for('ad_fix')->keep($b + ['ad_id' => $adId]);
        $image = $this->image($req, $f);
        $r = AdCampaigns::resubmitAd($adId, (int) $user->id, $b, $image);
        if (!$r['ok']) {
            foreach ($r['errors'] ?? [] as $k => $m) { $k === 'form' ? $f->summary($m) : $f->add($k, $m); }
            $f->flash();
            return $res->withHeader('Location', '/account/ads/' . $campaignId . '#ad-' . $adId)->withStatus(303);
        }
        $_SESSION['flash_ok'] = 'Saved and sent for review. It shows again once a person approves it.';
        return $res->withHeader('Location', '/account/ads/' . $campaignId)->withStatus(303);
    }

    /** POST /ads/seen — ads.js: half the ad was on screen for a second. */
    public function seen(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $token = (string) ($b['t'] ?? '');
        $counted = $token !== '' && AdDelivery::seen($token);
        return $res->withStatus($counted ? 204 : 202);
    }

    /** GET /ads/go/{token} — a click, counted once, then on to the advertiser. */
    public function go(Request $req, Response $res, array $args): Response
    {
        $url = AdDelivery::click((string) ($args['token'] ?? ''), VisitTracker::isReader($req));
        return $res->withHeader('Location', $url ?? '/')->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex')->withStatus(302);
    }

    /** POST /ads/hide — "Hide ad", from the card's menu. */
    public function hide(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $ok = AdDelivery::hide((string) ($b['t'] ?? ''));
        return $this->answer($req, $res, $b, $ok, 'Ad hidden. You will not see it again.');
    }

    /** POST /ads/report — "Report ad": a reason, once per network, for a person to read. */
    public function report(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $net = \AfricaGates\Support\ClientIp::fingerprint(\AfricaGates\Support\ClientIp::from($req), 'ad-report|' . date('Y-m-d'));
        if ($this->rateLimit && !$this->rateLimit->check('adrep:' . $net, 'ad_report', 20, 86400)) {
            return $this->answer($req, $res, $b, false, '');
        }
        $ok = AdDelivery::report((string) ($b['t'] ?? ''), (string) ($b['reason'] ?? ''), $net);
        return $this->answer($req, $res, $b, $ok, 'Thanks. A person will look at this ad.');
    }

    /** JSON for ads.js; with no script, back to the page the form was on, with the sentence. */
    private function answer(Request $req, Response $res, array $b, bool $ok, string $message): Response
    {
        if (str_contains($req->getHeaderLine('Accept'), 'application/json')) {
            $res->getBody()->write((string) json_encode(['ok' => $ok, 'message' => \AfricaGates\Support\Translator::t($message)]));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($ok ? 200 : 422);
        }
        $back = (string) ($b['back'] ?? '/');
        if (preg_match('{^/(?!/)[A-Za-z0-9/_.~%?=&+#-]*$}', $back) !== 1) $back = '/';
        if ($ok) $_SESSION['flash_ok'] = $message;
        return $res->withHeader('Location', $back)->withStatus(303);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> every limit the forms state, read from AdRules */
    private static function rules(): array
    {
        return [
            'headline' => AdRules::HEADLINE_MAX, 'body_min' => AdRules::BODY_MIN, 'body' => AdRules::BODY_MAX,
            'name' => AdRules::NAME_MAX, 'offer' => AdRules::OFFER_MAX, 'code' => AdRules::CODE_MAX,
            'image' => AdRules::IMAGE_MIN, 'days' => AdRules::TRIAL_DAYS, 'views' => AdRules::TRIAL_VIEWS,
            'freq' => AdRules::FREQUENCY_CAP,
            'placements' => AdRules::PLACEMENTS, 'objectives' => AdRules::OBJECTIVES, 'ctas' => AdRules::CTAS,
            'categories' => AdRules::CATEGORIES, 'approvals' => AdRules::APPROVALS, 'beta' => AdRules::inBeta(),
            'standards' => AdRules::STANDARDS,
        ];
    }

    /** An uploaded image (`image`, or the `logo`), stored, or null with the reason added to the form. */
    private function image(Request $req, FormErrors $f, string $field = 'image', int $min = AdRules::IMAGE_MIN): ?string
    {
        $file = $req->getUploadedFiles()[$field] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return null;
        try {
            $r = ($this->uploads ?? new UploadService())->uploadImage($file, 'ads', $field === 'logo' ? 400 : 1200, 82, null, 'ad', null, $min);
            return (string) ($r['path'] ?? $r['url'] ?? '') ?: null;
        } catch (\Throwable $e) {
            $f->add($field, sprintf('Use a JPEG, PNG or WebP at least %dpx on each side.', $min));
            return null;
        }
    }

    private function back(Response $res): Response
    {
        return $res->withHeader('Location', '/account/ads/new#ad-form')->withStatus(303);
    }

    private function toLogin(Response $res, string $next): Response
    {
        return $res->withHeader('Location', '/account/login?next=' . rawurlencode($next))->withStatus(302);
    }
}
