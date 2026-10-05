<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{AwardAlert, AwardsFront, CacheService, OtpService, RateLimitService};
use AfricaGates\Support\{ClientIp, SiteUrl, Translator};
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `/awards` and `/awards/{slug}` — Phase 5, AwardsPage.dc.html (index · detail · soon).
 *
 * Destroyed and written again on 5 Oct 2026 with the pages it renders (inventories
 * `pages--awards--index.md`, `pages--awards--programme.md`). Every fact comes from
 * `AwardsFront`; this file reads the URL and nothing else.
 *
 * ── THE VIEW IS THE URL ─────────────────────────────────────────────────────
 *
 * The DC swaps index → detail → soon and the three tabs with state. Here each is a URL:
 * `?ph=`/`?q=` on the index (a GET form, so it works with no script and survives Back),
 * `?tab=details|terms` and `?edition=YYYY` on an award. A view a person can only reach by
 * clicking is one nobody can link to. An unknown tab falls back to the overview, and the
 * Terms tab is offered only where terms are published — a Terms view over nothing is a
 * promise the award has not made (the destroyed page's rule, re-held by AwardsPageTest).
 *
 * The coming-soon view is not a tab: an award whose current edition is `upcoming` IS the
 * coming-soon page, at the same URL it will keep when it opens.
 */
final class AwardsController
{
    private const TABS = ['overview' => 'Overview', 'details' => 'Award details', 'terms' => 'Terms'];

    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly ?OtpService $mailer = null,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    /** GET /awards */
    public function index(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $front = AwardsFront::index(['ph' => (string) ($q['ph'] ?? 'all'), 'q' => (string) ($q['q'] ?? '')]);

        return $this->view->render($res, 'pages/awards/index.twig', [
            'page_title'       => Translator::t('Awards') . ' — Africa GATES',
            'meta_description' => Translator::t('Every award on Africa GATES runs in editions, with public nominations, community voting and an independent jury.'),
            'gates_page'       => 'awards',
            'front'            => $front,
            'phases'           => AwardsFront::PHASES,
        ]);
    }

    /** GET /awards/{p} */
    public function programme(Request $req, Response $res, array $args): Response
    {
        $q    = $req->getQueryParams();
        $year = isset($q['edition']) && ctype_digit((string) $q['edition']) ? (int) $q['edition'] : null;
        $a    = AwardsFront::detail((string) ($args['p'] ?? ''), $year);
        if ($a === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $tabs = self::TABS;
        if ($a['terms'] === null) unset($tabs['terms']);
        $tab = (string) ($q['tab'] ?? 'overview');
        if (!isset($tabs[$tab])) $tab = 'overview';

        $blurb = trim(strip_tags($a['subtitle'] ?: $a['description']));
        $said  = $_SESSION['aw_alert_said'] ?? null;
        unset($_SESSION['aw_alert_said']);

        return $this->view->render($res, 'pages/awards/programme.twig', [
            'page_title'       => $a['title'] . ' — Africa GATES',
            'meta_description' => $blurb !== '' ? mb_strimwidth($blurb, 0, 160, '…')
                                  : Translator::t('An award on Africa GATES, decided by community votes and an independent jury.'),
            'gates_page'       => 'awards',
            'a'                => $a,
            'tab'              => $tab,
            'tabs'             => $tabs,
            'alert_said'       => is_string($said) ? $said : null,
        ]);
    }

    /**
     * POST /awards/{p}/notify — "Notify me", double opt-in (AwardAlert).
     *
     * A plain form that posts, throttled per connection, answering the same sentence
     * whatever happened, then post/redirect/get so a refresh does not resubmit and the
     * address never lands in a URL.
     */
    public function notify(Request $req, Response $res, array $args): Response
    {
        $slug = (string) ($args['p'] ?? '');
        $a    = AwardsFront::detail($slug);
        if ($a === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $b  = (array) $req->getParsedBody();
        $ip = hash('sha256', ClientIp::from($req));
        if ($this->rateLimit && !$this->rateLimit->check($ip, 'award_alert', 10, 3600)) {
            $_SESSION['aw_alert_said'] = Translator::t('Too many requests from this connection in the last hour. Try again later.');
        } else {
            $r = AwardAlert::want((int) $a['id'], (string) ($b['email'] ?? ''), $ip, rtrim(SiteUrl::base($req), '/'),
                $this->mailer ? \AfricaGates\Services\Newsletter\NewsletterAudience::transport($this->mailer) : null);
            $_SESSION['aw_alert_said'] = Translator::t($r['message']);
        }
        return $res->withHeader('Location', '/awards/' . rawurlencode($slug) . '#aw-notify')->withStatus(303);
    }

    /** GET shows, POST acts — mail scanners fetch every link (the newsletter's rule). */
    public function alertPage(Request $req, Response $res, array $args): Response
    {
        $token  = (string) ($args['token'] ?? '');
        $action = (string) ($args['action'] ?? '');
        $done   = false;
        $row    = AwardAlert::find($token);
        if ($row && $req->getMethod() === 'POST') {
            $row  = $action === 'stop' ? AwardAlert::stop($token) : AwardAlert::confirm($token);
            $done = true;
        }
        $prog = $row ? \Illuminate\Database\Capsule\Manager::table('gates_award_programmes')
            ->where('id', (int) $row->programme_id)->first(['slug', 'title']) : null;

        return $this->view->render($res->withStatus($row ? 200 : 404), 'pages/awards/alert.twig', [
            'page_title'  => Translator::t('Award alert') . ' — Africa GATES',
            'gates_page'  => 'awards',
            'meta_robots' => 'noindex, nofollow',
            'alert'       => $row ? ['token' => (string) $row->token, 'action' => $action,
                                     'confirmed' => $row->confirmed_at !== null,
                                     'stopped' => $row->cancelled_at !== null] : null,
            'done'        => $done,
            'award'       => $prog ? ['title' => (string) $prog->title,
                                      'url' => '/awards/' . rawurlencode((string) $prog->slug)] : null,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
