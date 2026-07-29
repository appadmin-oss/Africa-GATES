<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{CacheService, AwardService, PointsService, PaidVoteService, PaymentService};

class VoteController {
    public function __construct(
        private readonly Twig            $view,
        private readonly CacheService    $cache,
        private readonly AwardService    $awards,
        private readonly ?PaymentService $payments = null
    ) {}

    /** /vote — the HUB: browse the live programmes, then drill into one to vote. */
    public function index(Request $req, Response $res): Response {
        $hub = $this->cache->remember('vote:hub', 600, fn() => $this->awards->voteHub());

        // Hero/sidebar meta. Every figure here comes from the SAME phase
        // view-model the cards render, so the page cannot contradict itself.
        // It used to derive open/closed from the status column while deriving
        // the countdown from voting_close, so when those disagreed — the exact
        // reported failure — the hub simultaneously showed "Voting open",
        // "0 Days left" and a close date already in the past.
        $votesTotal = 0; $votingCount = 0; $year = (int) date('Y');
        $soonest = null;   // the phase view-model of the next thing to close
        foreach ($hub as $p) {
            $votesTotal += (int) ($p['total_votes'] ?? 0);
            $phase = $p['phase'] ?? null;
            if (!$phase || empty($phase['is_voting_open'])) continue;
            $votingCount++;
            $year = (int) ($p['year'] ?? $year);
            if ($phase['closes_at'] === null) continue;
            if ($soonest === null || $phase['closes_at'] < $soonest['closes_at']) {
                $soonest = $phase;
            }
        }

        return $this->view->render($res, 'pages/vote.twig', [
            'page_title'       => 'Vote — Africa GATES | Afrovanguard',
            'meta_description' => 'Cast your verified vote in the Africa GATES awards. Browse the live programmes and back the African excellence you believe deserves continental recognition.',
            'gates_page'       => 'awards',
            'has_hero'         => false,
            'current_section'  => 'projects',
            'hub'              => $hub,
            'meta'             => [
                'count'        => count($hub),
                'votes_total'  => $votesTotal,
                'voting_count' => $votingCount,
                'year'         => $year,
                // One object, or null. The template must not recompute any of it.
                'soonest'      => $soonest,
            ],
            'breadcrumbs' => [
                ['label' => 'Afrovanguard', 'url' => '/'],
                ['label' => 'Africa GATES', 'url' => '/'],
                ['label' => 'Awards',       'url' => '/awards'],
                ['label' => 'Vote',         'url' => '/vote'],
            ],
        ]);
    }

    /**
     * /vote/{program} — a PAGE FOR EACH PROGRAMME: its approved nominees grouped by
     * category, each linking to that nominee's own individual page. The {program}
     * segment is the admin-editable programme slug. A bookmarked legacy /vote/{id}
     * link (segment starts with a digit) is gracefully redirected to its canonical
     * nested URL; an unknown slug falls back to the hub — never a 404.
     */
    public function program(Request $req, Response $res, array $args): Response {
        $seg = (string) ($args['program'] ?? '');
        if ($seg !== '' && ctype_digit($seg[0])) {
            return $this->nomineeRedirect($res, (int) $seg); // legacy /vote/{id}
        }
        $p = $this->awards->getProgrammeBySlug($seg);
        if (!$p) return $res->withHeader('Location', '/vote')->withStatus(302);

        $year = (int) ($p['cycle']['year'] ?? date('Y'));
        $noms = $this->awards->getNominees((int) $p['id'], 0, $year);
        $byCat = [];
        foreach ($p['categories'] as $c) { $byCat[(int) $c['id']] = ['category' => $c, 'nominees' => []]; }
        foreach ($noms as $n) {
            $cid = (int) $n['category_id'];
            if (!isset($byCat[$cid])) $byCat[$cid] = ['category' => ['id' => $cid, 'title' => $n['category'], 'description' => null], 'nominees' => []];
            $n['url'] = $this->nomineeUrl((int) $n['id'], (string) $n['name'], (string) $p['slug']); // canonical nested link
            $byCat[$cid]['nominees'][] = $n;
        }
        $cats = array_values(array_filter($byCat, fn($c) => count($c['nominees']) > 0));

        return $this->view->render($res, 'pages/vote-program.twig', [
            'page_title'       => $p['title'] . ' — Vote — Africa GATES',
            'meta_description' => 'Vote in ' . $p['title'] . ' — browse the nominees by category and back the African excellence you believe deserves continental recognition.',
            'gates_page'       => 'awards',
            'has_hero'         => false,
            'current_section'  => 'projects',
            'programme'        => $p,
            'categories'       => $cats,
            // The computed phase, not the stored column. `voting_open` is kept
            // as a convenience boolean but is now derived from the same source.
            'phase'            => $p['phase'] ?? null,
            'voting_open'      => (bool) ($p['phase']['is_voting_open'] ?? false),
            'total_nominees'   => count($noms),
        ]);
    }

    /**
     * /vote/{program}/{slug} — ALWAYS one nominee's individual page. {slug} is
     * "{id}-{name}", resolved by the leading id; if the {program} segment doesn't
     * match the nominee's real programme, we 302 to the canonical URL.
     */
    public function nominee(Request $req, Response $res, array $args): Response {
        $slug = (string) ($args['slug'] ?? '');
        $prog = (string) ($args['program'] ?? '');
        if ($slug === '' || !ctype_digit($slug[0])) {
            return $res->withHeader('Location', '/vote/' . rawurlencode($prog))->withStatus(302);
        }
        return $this->nomineeBallot($req, $res, (int) $slug, $prog);
    }

    /** Canonical nominee URL: /vote/{programme-slug}/{id}-{name}. */
    private function nomineeUrl(int $id, string $name, string $programmeSlug): string {
        $s = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
        return '/vote/' . $programmeSlug . '/' . $id . ($s !== '' ? '-' . $s : '');
    }

    /** Bounce a legacy /vote/{id} link to its canonical nested URL. */
    private function nomineeRedirect(Response $res, int $id): Response {
        $row = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where('n.id', $id)->where('n.status', 'approved')
            ->where(fn($q) => \AfricaGates\Services\MergeService::notMerged($q, 'n.merged_into'))
            ->select(['n.id', 'n.name', 'p.slug as programme_slug'])->first();
        if (!$row) return $res->withHeader('Location', '/vote')->withStatus(302);
        return $res->withHeader('Location', $this->nomineeUrl($id, (string) $row->name, (string) $row->programme_slug))->withStatus(302);
    }

    /**
     * Human-readable reason a paid-vote checkout bounced back here.
     *
     * PaidVoteController emits eight of these as a `?paid=` query parameter and
     * NO template read any of them, so a supporter refused because voting had
     * closed — or because their email was invalid, or the provider was off — was
     * returned to an unchanged page with no message at all.
     */
    private const PAID_REASONS = [
        'off'         => 'Paid voting is not enabled right now.',
        'rate'        => 'That is a lot of vote purchases from this network — please try again shortly.',
        'nominee'     => 'That nominee is not open for votes.',
        'closed'      => 'Voting has closed for this category, so no votes could be purchased.',
        'unavailable' => 'That payment method is unavailable right now. Please try another.',
        'email'       => 'Please enter a valid email address for your receipt.',
        'start'       => 'We could not start the checkout. No payment was taken — please try again.',
        'error'       => 'Something went wrong starting the checkout. No payment was taken.',
        'failed'      => 'That payment did not complete, so no votes were added.',
    ];

    /** The individual nominee vote page (profile-style with the OTP ballot inline). */
    private function nomineeBallot(Request $req, Response $res, int $id, string $programSeg = ''): Response {
        if ($id < 1) throw new \Slim\Exception\HttpNotFoundException($req);

        $nom = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where('n.id', $id)->where('n.status', 'approved')
            ->where(fn($q) => \AfricaGates\Services\MergeService::notMerged($q, 'n.merged_into'))
            ->select([
                'n.id', 'n.name', 'n.tagline', 'n.photo_path', 'n.vote_count', 'n.country_code', 'n.profile_id',
                'c.id as category_id', 'c.title as category',
                'cy.id as cycle_id', 'cy.status as cycle_status', 'cy.year as year',
                'p.id as programme_id', 'p.title as programme_title', 'p.slug as programme_slug',
            ])->first();
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);

        // Canonical URL: the {program} segment must match the nominee's programme.
        if ($programSeg !== '' && $programSeg !== (string) $nom->programme_slug) {
            return $res->withHeader('Location', $this->nomineeUrl($id, (string) $nom->name, (string) $nom->programme_slug))->withStatus(302);
        }

        // Enrich from the linked registry profile when one exists (portrait, CPI, bio).
        $profile = null;
        if (!empty($nom->profile_id)) {
            $profile = DB::table('gates_profiles')->where('id', $nom->profile_id)->where('status', 'approved')
                ->select(['slug', 'avatar_path', 'bio', 'cpi_score', 'cpi_tier', 'region', 'view_count'])->first();
        }

        // Rank within the category (by votes) + others to discover.
        $catList = $this->awards->getNominees((int) $nom->programme_id, (int) $nom->category_id, (int) $nom->year);
        $rank = 0;
        foreach ($catList as $i => $o) { if ((int) $o['id'] === $id) { $rank = $i + 1; break; } }
        $others = array_values(array_filter($catList, fn($o) => (int) $o['id'] !== $id));

        // The one phase view-model for this ballot, from the nominee's own cycle.
        $phase = \AfricaGates\Services\BallotGuard::stateForCategory((int) $nom->category_id);

        // Member voting-points context (for the redeem-a-vote control).
        $memberId      = (int) ($_SESSION['user_id'] ?? 0);
        $pointsEnabled = PointsService::enabled();
        $memberPoints  = ($memberId > 0 && $pointsEnabled) ? PointsService::balance($memberId) : 0;

        return $this->view->render($res, 'pages/vote-nominee.twig', [
            'page_title'       => 'Vote for ' . $nom->name . ' — Africa GATES',
            'meta_description' => 'Cast your verified vote for ' . $nom->name . ' in ' . $nom->category . ' — Africa GATES, the continental Cultural Power Index.',
            'gates_page'       => 'awards',
            'has_hero'         => false,
            'nominee'          => $nom,
            'profile'          => $profile,
            'rank'             => $rank,
            'cat_count'        => count($catList),
            'others'           => array_slice($others, 0, 5),
            // COMPUTED phase, so a stale status column cannot present an open
            // ballot for a closed cycle.
            'phase'            => $phase,
            'voting_open'      => (bool) ($phase['is_voting_open'] ?? false),
            'paid_notice'      => self::PAID_REASONS[trim((string) ($req->getQueryParams()['paid'] ?? ''))] ?? null,
            'turnstile_site_key' => Env::get('TURNSTILE_SITE_KEY', ''),
            'member_points'    => $memberPoints,
            'points_per_vote'  => PointsService::pointsPerVote(),
            'can_redeem'       => $memberId > 0 && $pointsEnabled && (bool) ($phase['is_voting_open'] ?? false) && $memberPoints >= PointsService::pointsPerVote(),
            'member_logged_in' => $memberId > 0,
            'points_enabled'   => $pointsEnabled,
            'member'           => \AfricaGates\Services\UserAccountService::memberForForms(),
            // Paid voting (admin-toggleable) — the ballot leads with Buy Votes
            // when enabled; the free OTP path hides when the admin disables it.
            'paid_voting'        => PaidVoteService::enabled(),
            'paid_free_disabled' => PaidVoteService::freeVotingDisabled(),
            'vote_price'         => PaidVoteService::pricePerVote(),
            'votes_per_1000'     => PaidVoteService::votesPer1000(),
            'pay_providers'      => (PaidVoteService::enabled() && $this->payments) ? $this->payments->enabledProviders() : [],
        ] + array_filter([
            // Social card: the nominee's own photo when they have one.
            'og_image'     => \AfricaGates\Support\Assets::absoluteOg($nom->photo_path ?? null),
            'og_image_alt' => 'Vote for ' . $nom->name . ' — Africa GATES',
        ], fn($v) => $v !== null));
    }
}
