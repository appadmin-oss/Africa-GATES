<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdDelivery;
use AfricaGates\Services\Ads\AdRateCard;
use AfricaGates\Services\Ads\AdRules;
use AfricaGates\Support\SiteUrl;
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

    private ?\AfricaGates\Services\PaymentService $ps = null;

    /** The gateway, made once per request and only on the pages that take money. */
    private function payments(): \AfricaGates\Services\PaymentService
    {
        return $this->ps ??= new \AfricaGates\Services\PaymentService();
    }

    /** GET /advertise — what an ad is, where it runs and what it costs, before anybody signs up. */
    public function advertise(Request $req, Response $res): Response
    {
        $host = (string) (parse_url(SiteUrl::base($req), PHP_URL_HOST) ?: 'africagates.org');
        // The planner answers a GET, so a plan has an address an advertiser can send on, and
        // it works with no script (AdPlanner).
        $q = $req->getQueryParams();
        $plan = null;
        if (isset($q['goal'], $q['n']) && (int) $q['n'] > 0) {
            $plan = \AfricaGates\Services\Ads\AdPlanner::plan((string) $q['goal'], (int) $q['n'], (int) ($q['days'] ?? 14));
        }
        return $this->view->render($res, 'pages/advertise.twig', [
            'market' => \AfricaGates\Services\Ads\AdMarket::card(), 'plan' => $plan,
            'reach' => \AfricaGates\Services\Ads\AdMarket::reach(),
            // The FAQ's figures, read from the rules that apply them, never typed.
            'facts' => ['vat_pct' => \AfricaGates\Services\Ads\AdCheckout::vatBps() / 100,
                        'min_budget' => \AfricaGates\Services\Ads\AdCheckout::MIN_BUDGET_NAIRA,
                        'max_days' => AdRules::MAX_DAYS, 'freq' => AdRules::FREQUENCY_CAP, 'wa_greeting' => AdRules::WHATSAPP_GREETING,
                        'video_max' => \AfricaGates\Services\Ads\AdVideo::MAX_SECONDS,
                        'standards' => AdRules::STANDARDS],
            'goals' => \AfricaGates\Services\Ads\AdPlanner::GOALS,
            'plan_in' => ['goal' => (string) ($q['goal'] ?? 'visits'), 'n' => (int) ($q['n'] ?? 0) ?: '', 'days' => (int) ($q['days'] ?? 14) ?: 14],
            'max_days' => AdRules::MAX_DAYS,
            'pricing' => ['dynamic' => \AfricaGates\Services\Ads\AdMarket::dynamic(), 'floor' => (int) round(100 * \AfricaGates\Services\Ads\AdMarket::FLOOR),
                          'ceil' => (int) round(100 * \AfricaGates\Services\Ads\AdMarket::CEIL), 'horizon' => \AfricaGates\Services\Ads\AdMarket::HORIZON,
                          'assumed_ctr' => round(100 * \AfricaGates\Services\Ads\AdMarket::ASSUMED_CTR, 1)],
            'app_bar' => ['variant' => 'child', 'title' => 'Advertise', 'back' => '/', 'share' => false],
            'page_title' => 'Advertise — Africa GATES',
            'meta_description' => 'Put your business, product or event in front of the people on Africa GATES.',
            'trial' => ['days' => AdRules::TRIAL_DAYS, 'views' => AdRules::TRIAL_VIEWS],
            // A drawn example, in the real card, so an advertiser sees the format before
            // signing up. Labelled an example; it links nowhere. It is OUR OWN ad, drawn from
            // our own assets (owner, 28 Oct 2026: "use Africa GATES assets for this"): an
            // invented business on the page that sells ads reads as an advertiser we have,
            // and every claim in it is a feature that exists (tickets, invites, door check-in).
            'sample' => [
                'advertiser' => 'Africa GATES Events', 'initials' => 'AG', 'logo' => '/assets/img/logo-mark.svg',
                'body' => 'Tickets, invitations and door check-in for your next award night, all in one place.',
                'image' => '/assets/img/honour/community-leader-960.webp', 'headline' => 'Host your award night',
                'domain' => $host, 'offer' => '', 'offer_code' => '', 'cta' => 'Learn more', 'url' => '#', 'external' => false,
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
        $old = (array) ($form['old'] ?? []);
        // "Promote this event" (partials/views-badge.twig): the builder opens ready for that
        // event — tickets as the goal, the event's own page as the link, its title as the
        // headline. Only a published, live event, and only into an empty form: a failed
        // submission's own values always win.
        $q = $req->getQueryParams();
        if ($old === [] && ($q['for'] ?? '') === 'event' && is_string($q['slug'] ?? null)) {
            $ev = \AfricaGates\Services\EventsFront::liveOnly(\Illuminate\Database\Capsule\Manager::table('gates_site_events as e')
                ->where('e.slug', (string) $q['slug'])->where('e.status', 'published'))->first(['e.title', 'e.slug']);
            if ($ev) {
                $old = ['objective' => 'sales', 'category' => 'events', 'cta' => 'get_tickets',
                        'url' => '/events/' . (string) $ev->slug, 'headline' => mb_substr((string) $ev->title, 0, AdRules::HEADLINE_MAX),
                        'placements' => ['events', 'event_page', 'discover']];
            }
        }

        return $this->view->render($res, 'pages/account/ads-new.twig', [
            'app_bar' => ['variant' => 'child', 'title' => 'New campaign', 'back' => '/account/ads', 'share' => false],
            'page_title' => 'New campaign — Africa GATES', 'gates_page' => 'account',
            // A flow: its own back button and its own bottom bar, no tab bar (layout/shell.twig).
            'flow_page' => true,
            'trial_left' => !AdCampaigns::trialUsed((int) $user->id),
            'nominee_open' => AdRules::inOpenAward((int) $user->id),
            'rules' => self::rules(), 'estimate' => $est,
            // Where each place sits, its tier and today's price: the builder states the same
            // market /advertise does (AdMarket::card), keyed for the checkbox loop.
            'market' => array_column(\AfricaGates\Services\Ads\AdMarket::card(), null, 'key'),
            'paid' => ['open' => \AfricaGates\Services\Ads\AdCheckout::open($this->payments()),
                       'min' => \AfricaGates\Services\Ads\AdCheckout::MIN_BUDGET_NAIRA,
                       'vat_pct' => \AfricaGates\Services\Ads\AdCheckout::vatBps() / 100],
            'errors' => $form['errors'] ?? [], 'old' => $old, 'summary' => $form['summary'] ?? '',
        ] + self::checks($old, false, false));
    }

    /**
     * POST /account/ads/check — the builder's live panels: the ad's strength as it is
     * written, and what the budget should reach (AdStrength, AdPlanner::forecast). Rendered
     * here from the same partials the page draws on load, so the script swaps in markup and
     * carries no copy of any rule. Nothing is saved; the image is not sent, only whether one
     * has been chosen.
     */
    public function check(Request $req, Response $res): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->json($res, ['ok' => false], 401);
        if ($this->rateLimit && !$this->rateLimit->check('adchk:' . (int) $user->id, 'ads_check', 600, 3600)) {
            return $this->json($res, ['ok' => false], 429);
        }
        $b = (array) $req->getParsedBody();
        $v = self::checks($b, ($b['has_image'] ?? '') === '1', ($b['has_logo'] ?? '') === '1');
        return $this->json($res, ['ok' => true,
            'strength' => $this->view->fetch('partials/ad-strength.twig', ['strength' => $v['strength']]),
            'forecast' => $this->view->fetch('partials/ad-forecast.twig', $v)]);
    }

    /**
     * The two panels' figures for what is in the form.
     *
     * @param array<string,mixed> $in
     * @return array{strength:?array, forecast:?array, fc:array<string,mixed>}
     */
    private static function checks(array $in, bool $hasImage, bool $hasLogo): array
    {
        $words = trim((string) ($in['headline'] ?? '') . (string) ($in['body'] ?? ''));
        $obj = (string) ($in['objective'] ?? 'awareness');
        $strength = $words === '' ? null : \AfricaGates\Services\Ads\AdStrength::score([
            'headline' => (string) ($in['headline'] ?? ''), 'body' => (string) ($in['body'] ?? ''),
            'offer' => (string) ($in['offer'] ?? ''), 'cta' => (string) ($in['cta'] ?? 'learn_more'),
        ], isset(AdRules::OBJECTIVES[$obj]) ? $obj : 'awareness', $hasImage, $hasLogo);

        $budget = (int) preg_replace('/[^0-9]/', '', (string) ($in['budget'] ?? ''));
        $days = max(1, min(AdRules::MAX_DAYS, (int) ($in['days'] ?? 14) ?: 14));
        $min = \AfricaGates\Services\Ads\AdCheckout::MIN_BUDGET_NAIRA;
        $places = AdRules::cleanPlacements($in['placements'] ?? []);
        $paid = (string) ($in['kind'] ?? '') === 'paid';
        $forecast = $paid && $budget >= $min && $places !== []
            ? \AfricaGates\Services\Ads\AdPlanner::forecast($budget, $days, $places, $obj) : null;
        // The part of the budget the places cannot take is worth a sentence only when it is
        // more than rounding: a twentieth of the budget, or ₦500.
        $short = $forecast !== null && $forecast['unspent_kobo'] >= max(50_000, intdiv($budget * 100, 20));
        return ['strength' => $strength, 'forecast' => $forecast,
                'fc' => ['paid' => $paid, 'budget' => $budget, 'days' => $days, 'min' => $min, 'places' => count($places),
                         'objective' => $obj, 'short' => $short,
                         // An estimate is said in whole naira: kobo would claim a precision it has not got.
                         'unspent_label' => $forecast ? AdRateCard::format($budget * 100 - self::naira($forecast['spend_kobo'])) : '',
                         'spend_label' => $forecast ? AdRateCard::format(self::naira($forecast['spend_kobo'])) : '']];
    }

    private static function naira(int $kobo): int
    {
        return intdiv($kobo + 50, 100) * 100;
    }

    /** @param array<string,mixed> $body */
    private function json(Response $res, array $body, int $status = 200): Response
    {
        $res->getBody()->write((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')->withStatus($status);
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
        $paid = (string) ($b['kind'] ?? '') === 'paid' || AdCampaigns::trialUsed((int) $user->id);
        if ($paid && !\AfricaGates\Services\Ads\AdCheckout::open($this->payments())) {
            $f->summary('Your free trial is used, and paid campaigns are not on sale right now.')->flash();
            return $this->back($res);
        }

        if (($err = \AfricaGates\Services\Ads\AdVideo::take($req->getUploadedFiles(), $b)) !== null) {
            $f->add('video', $err)->flash();
            return $this->back($res);
        }
        $image = $this->image($req, $f);
        $logo  = $this->image($req, $f, 'logo');
        if ($paid) {
            $r = \AfricaGates\Services\Ads\AdCheckout::create((int) $user->id, $b, $image, $logo);
            if (!$r['ok']) {
                foreach ($r['errors'] ?? [] as $k => $m) { $k === 'form' ? $f->summary($m) : $f->add($k, $m); }
                $f->flash();
                return $this->back($res);
            }
            return $this->toCheckout($req, $res, (int) $r['id'], $user);
        }
        $r = AdCampaigns::createTrial((int) $user->id, $b, $image, $logo);
        if (!$r['ok']) {
            foreach ($r['errors'] ?? [] as $k => $m) { $k === 'form' ? $f->summary($m) : $f->add($k, $m); }
            $f->flash();
            return $this->back($res);
        }
        $_SESSION['flash_ok'] = 'Sent for review. Your trial starts when a person approves it.';
        return $res->withHeader('Location', '/account/ads/' . (int) $r['id'])->withStatus(303);
    }

    /** Open the gateway for one unpaid campaign, or say why not and show the campaign. */
    private function toCheckout(Request $req, Response $res, int $id, object $user): Response
    {
        $cb = SiteUrl::base($req) . '/account/ads/' . $id . '/paid';
        $r = \AfricaGates\Services\Ads\AdCheckout::start($this->payments(), $id, (int) $user->id, (string) ($user->email ?? ''), $cb);
        if ($r['ok']) return $res->withHeader('Location', (string) $r['url'])->withStatus(303);
        $_SESSION['flash_error'] = $r['message'];
        return $res->withHeader('Location', '/account/ads/' . $id)->withStatus(303);
    }

    /** POST /account/ads/{id}/pay — pay for a saved campaign (a checkout left, or one that would not open). */
    public function pay(Request $req, Response $res, array $args): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->toLogin($res, '/account/ads');
        return $this->toCheckout($req, $res, (int) ($args['id'] ?? 0), $user);
    }

    /**
     * GET /account/ads/{id}/paid — back from the gateway. Asks the gateway (never the query
     * string) and says what it found; the webhook confirms the same payment if this page is
     * never reached.
     */
    public function paid(Request $req, Response $res, array $args): Response
    {
        $user = $this->accounts->current();
        $id = (int) ($args['id'] ?? 0);
        if (!$user) return $this->toLogin($res, '/account/ads/' . $id);
        $c = \Illuminate\Database\Capsule\Manager::table('gates_ad_campaigns')->where('id', $id)->where('user_id', (int) $user->id)->first();
        if ($c && $c->payment_ref) {
            $r = \AfricaGates\Services\Ads\AdCheckout::confirm((string) $c->payment_ref, $this->payments());
            if (in_array($r['state'], ['confirmed', 'already'], true)) {
                $_SESSION['flash_ok'] = 'Paid. A person checks your ad next; it runs from when it is approved. Your receipt is in your email.';
            } elseif ($r['state'] === 'pending') {
                $_SESSION['flash_ok'] = 'Your payment has not cleared yet. This page updates when it does; nothing runs before then.';
            } else {
                $_SESSION['flash_error'] = 'That payment did not match the campaign. Nothing was started; contact us with your reference.';
            }
        }
        return $res->withHeader('Location', '/account/ads/' . $id)->withStatus(303);
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
        $new = FormErrors::take('ad_new');

        return $this->view->render($res, 'pages/account/ads-campaign.twig', [
            'app_bar' => ['variant' => 'child', 'title' => $c['name'], 'back' => '/account/ads', 'share' => false],
            'page_title' => $c['name'] . ' — Ads — Africa GATES', 'gates_page' => 'account',
            'c' => $c, 'rules' => self::rules(),
            'errors' => $form['errors'] ?? [], 'old' => $form['old'] ?? [], 'fixing' => (int) ($form['old']['ad_id'] ?? 0),
            'new_errors' => $new['errors'] ?? [], 'new_old' => $new['old'] ?? [], 'new_summary' => $new['summary'] ?? '',
            // "Add a version" from the advice opens the form it points at (a <details> does
            // not open for a fragment by itself).
            'adding' => isset($req->getQueryParams()['add']),
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

    /** POST /account/ads/{id}/ads — another version of the campaign's ad, sent for review. */
    public function addVersion(Request $req, Response $res, array $args): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $this->toLogin($res, '/account/ads');
        $id = (int) ($args['id'] ?? 0);
        $b = (array) $req->getParsedBody();
        $f = FormErrors::for('ad_new')->keep($b);
        if (($err = \AfricaGates\Services\Ads\AdVideo::take($req->getUploadedFiles(), $b)) !== null) {
            $f->add('video', $err)->flash();
            return $res->withHeader('Location', '/account/ads/' . $id . '#ad-version')->withStatus(303);
        }
        $image = $this->image($req, $f);
        $r = AdCampaigns::addAd($id, (int) $user->id, $b, $image);
        if (!$r['ok']) {
            foreach ($r['errors'] ?? [] as $k => $m) { $k === 'form' ? $f->summary($m) : $f->add($k, $m); }
            $f->flash();
            return $res->withHeader('Location', '/account/ads/' . $id . '#ad-version')->withStatus(303);
        }
        $_SESSION['flash_ok'] = 'New version sent for review. It joins the rotation once a person approves it.';
        return $res->withHeader('Location', '/account/ads/' . $id . '#ad-' . (int) $r['id'])->withStatus(303);
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
        if (($err = \AfricaGates\Services\Ads\AdVideo::take($req->getUploadedFiles(), $b, true)) !== null) {
            $f->add('video', $err)->flash();
            return $res->withHeader('Location', '/account/ads/' . $campaignId . '#ad-' . $adId)->withStatus(303);
        }
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

    /** POST /ads/video — a drawn ad's video started, or was watched through (AdDelivery::watched). */
    public function video(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $token = (string) ($b['t'] ?? '');
        $counted = $token !== '' && AdDelivery::watched($token, (string) ($b['k'] ?? ''));
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
            'image' => AdRules::IMAGE_MIN, 'logo' => \AfricaGates\Support\ImageProfiles::get('ad_logo')['min'], 'days' => AdRules::TRIAL_DAYS, 'views' => AdRules::TRIAL_VIEWS,
            'max_days' => AdRules::MAX_DAYS,
            'freq' => AdRules::FREQUENCY_CAP,
            'placements' => AdRules::PLACEMENTS, 'objectives' => AdRules::OBJECTIVES, 'ctas' => AdRules::CTAS,
            'categories' => AdRules::CATEGORIES, 'approvals' => AdRules::APPROVALS, 'beta' => AdRules::inBeta(),
            'standards' => AdRules::STANDARDS, 'wa_greeting' => AdRules::WHATSAPP_GREETING,
            // Each shape with the editor's frame for it, read from its image profile.
            'shapes' => array_map(static fn ($s) => ['label' => $s[0], 'profile' => $s[1]] + \AfricaGates\Support\ImageProfiles::get($s[1]), AdRules::SHAPES),
            // Video ads (AdVideo): the limits the form states are the limits store() enforces.
            'video' => ['on' => \AfricaGates\Services\Ads\AdVideo::ready(), 'min' => \AfricaGates\Services\Ads\AdVideo::MIN_SECONDS,
                        'max' => \AfricaGates\Services\Ads\AdVideo::MAX_SECONDS, 'mb' => \AfricaGates\Services\Ads\AdVideo::limitMb()],
            'versions' => AdRules::ADS_PER_SET, 'test_views' => AdCampaigns::TEST_VIEWS, 'lead_share' => AdCampaigns::LEAD_SHARE,
        ];
    }

    /**
     * An uploaded image (`image`, or the `logo`), made into its profile's shape and stored
     * (UploadService::uploadProcessed()), or null with the reason added to the form.
     *
     * The editor (image-tool.js) sends either the chosen cut itself or the original with
     * the cut in `<field>_crop`; with no script the server cuts the centre. A cut too small
     * for the profile is refused in the editor's own words — the pixels it has and the
     * pixels it needs — rather than "use a larger image" about a photo that may be 4000px.
     */
    private function image(Request $req, FormErrors $f, string $field = 'image'): ?string
    {
        // The image is cut to the SHAPE chosen on the same form (AdRules::SHAPES).
        $profile = $field === 'logo' ? 'ad_logo' : AdRules::imageProfile(((array) $req->getParsedBody())['shape'] ?? null);
        $min = \AfricaGates\Support\ImageProfiles::get($profile)['min'];
        $file = $req->getUploadedFiles()[$field] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return null;
        $crop = (string) (((array) $req->getParsedBody())[$field . '_crop'] ?? '');
        try {
            $r = ($this->uploads ?? new UploadService())->uploadProcessed($file, 'ads', $profile, $crop, null, 'ad', null);
            return (string) ($r['path'] ?? $r['url'] ?? '') ?: null;
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            $f->add($field, str_starts_with($m, 'The ') || str_starts_with($m, 'Upload a ') || str_starts_with($m, 'Image larger')
                ? $m : sprintf('Use a JPEG, PNG or WebP photo, at least %dpx on each side.', $min));
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
