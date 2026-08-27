<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{CacheService, AwardService, PointsService, PaidVoteService, PaymentService, RuleEngine};

class VoteController {

    /**
     * Nominee states that have a public page.
     *
     * 'approved' is the state a nominee is in only while the cycle is unfinished.
     * The moment {@see \AfricaGates\Services\CycleMaterialiser} seals the standings
     * it writes 'winner' or 'runner_up' onto the row — so filtering on 'approved'
     * alone means WINNING DELETES A NOMINEE'S PAGE, and every link shared during the
     * whole campaign breaks at the exact moment it starts to mean something.
     *
     * The filter exists to hide pending, rejected and archived entries. A winner is
     * none of those.
     */
    private const PUBLIC_STATUSES = ['approved', 'winner', 'runner_up'];

    public function __construct(
        private readonly Twig            $view,
        private readonly CacheService    $cache,
        private readonly AwardService    $awards,
        private readonly ?PaymentService $payments = null
    ) {}

    /**
     * The community/judge split, as the scorer currently computes it.
     *
     * ── WHY A BALLOT NEEDS THIS ─────────────────────────────────────────────
     *
     * The vote pages told a voter "community votes are 45% of the final score" in
     * typed prose, while /integrity one click away read the figure out of
     * RuleEngine — which an operator can change per programme and per cycle. The
     * two could disagree, and the page that would have been wrong is the one a
     * person reads immediately before deciding whether to pay.
     *
     * Same engine, same numbers, everywhere the split is quoted.
     *
     * @return array{community:int, judge:int}
     */
    private function splitPct(): array {
        try {
            $w = (new RuleEngine())->weights();
            return [
                'community' => (int) round($w['community'] * 100),
                'judge'     => (int) round($w['judge'] * 100),
            ];
        } catch (\Throwable) {
            // A ballot must still render if the rules table is unreachable. The code
            // defaults are the same ones RuleEngine would have returned.
            return [
                'community' => (int) round(RuleEngine::DEFAULTS['community_weight'] * 100),
                'judge'     => (int) round(RuleEngine::DEFAULTS['judge_weight'] * 100),
            ];
        }
    }

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
            'split'            => $this->splitPct(),
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

        // Race framing. Rank, the gap to the position above and a share-of-leader bar,
        // computed once per category in memory rather than by asking StandingsService
        // per nominee — which would be one full category scan for every card on the page.
        foreach ($cats as &$c) {
            $c['nominees'] = \AfricaGates\Services\RaceService::annotate($c['nominees']);
            $c['headline'] = \AfricaGates\Services\RaceService::headline($c['nominees']);
        }
        unset($c);

        // ── THE SHORTLIST BADGE ──────────────────────────────────────────────
        //
        // ONE query for the whole page, not one per card. `shortlistedIn()` returns an
        // id-keyed set, so marking sixty cards is sixty array lookups rather than sixty
        // round trips — the same reason the race framing above is computed in memory.
        //
        // Read from the PUBLISHED snapshot and never recomputed here: a badge derived from
        // a live threshold would appear and disappear as votes landed, telling a nominee
        // they were shortlisted and then that they were not, on a page they did not reload.
        $shortlisted = \AfricaGates\Services\ShortlistService::shortlistedIn((int) ($p['cycle']['id'] ?? 0));
        if ($shortlisted !== []) {
            foreach ($cats as &$c) {
                foreach ($c['nominees'] as &$n) {
                    $n['shortlisted'] = isset($shortlisted[(int) $n['id']]);
                }
                unset($n);
            }
            unset($c);
        }

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
            // Mirrors the trail this page already SHOWS. Every one of these pages had
            // a visible breadcrumb and no BreadcrumbList, so the SERP printed a bare
            // URL where it could have printed Vote › programme — and the trail is how
            // a searcher sees that a nominee page belongs to an awards programme.
            'breadcrumbs'      => [
                ['label' => 'Vote',      'url' => '/vote'],
                ['label' => $p['title'], 'url' => null],
            ],
        ]);
    }

    /**
     * /vote/{program}/tallies — the live figures, as JSON.
     *
     * ── WHY POLLING AND NOT A SOCKET ─────────────────────────────────────────
     *
     * Shared cPanel hosting with no persistent process. A websocket or SSE stream holds
     * a PHP worker open per viewer, and this platform's audience arrives in bursts when
     * a nominee posts a flier — the exact moment holding connections open would exhaust
     * the pool and take the ballot down. A cached poll degrades instead: more viewers
     * hit the same cached payload.
     *
     * Cached for a few seconds, so a thousand people watching one category cost roughly
     * one query rather than a thousand. Votes are not a trading price; nobody is harmed
     * by seeing a tally that is five seconds old, and the alternative is a page that
     * takes the database down at precisely the moment it matters.
     *
     * Returns the SAME shape RaceService puts in the template, so the client patches
     * numbers it already knows how to render rather than re-deriving standings — a
     * second implementation of rank arithmetic in JavaScript is how the page and its
     * updates start disagreeing.
     */
    public function tallies(Request $req, Response $res, array $args): Response {
        $seg = (string) ($args['program'] ?? '');
        $p   = $this->awards->getProgrammeBySlug($seg);
        if (!$p) {
            $res->getBody()->write('{"ok":false}');
            return $res->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $key  = 'tallies:' . $seg . ':' . (int) ($p['cycle']['year'] ?? 0);
        $body = (new \AfricaGates\Services\CacheService())->remember($key, 5, function () use ($p) {
            $year  = (int) ($p['cycle']['year'] ?? date('Y'));
            $byCat = [];
            foreach ($this->awards->getNominees((int) $p['id'], 0, $year) as $n) {
                $byCat[(int) $n['category_id']][] = $n;
            }
            $out = [];
            foreach ($byCat as $cid => $list) {
                $ranked = \AfricaGates\Services\RaceService::annotate($list);
                $out[] = [
                    'category_id' => $cid,
                    'headline'    => \AfricaGates\Services\RaceService::headline($ranked),
                    'nominees'    => array_map(static fn (array $n) => [
                        'id'    => (int) $n['id'],
                        'votes' => (int) $n['vote_count'],
                        'rank'  => (int) $n['rank'],
                        'gap'   => $n['gap'],
                        'pct'   => (int) $n['pct'],
                    ], $ranked),
                ];
            }
            return ['ok' => true, 'at' => date('c'), 'categories' => $out];
        });

        // CacheService stores and returns ARRAYS (it json_decodes on read), so the
        // encoding happens here rather than inside the callback — otherwise the payload
        // is a JSON string inside a JSON string and the client parses twice.
        $res->getBody()->write((string) json_encode(is_array($body) ? $body : ['ok' => false]));
        return $res
            ->withHeader('Content-Type', 'application/json')
            // Let a CDN or the browser absorb a rally too. Short enough that the page
            // still feels live, long enough that a burst does not reach PHP at all.
            ->withHeader('Cache-Control', 'public, max-age=5');
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
        // Slug::idSegment, not a local expression. The five copies of
        // `preg_replace('/[^a-z0-9]+/i', ...)` in this codebase DELETED accented letters
        // instead of folding them, so "Ọlásùnkànmí Adébáyọ̀" became "l-s-nk-nm-ad-b-y" —
        // fourteen of twenty letters gone. It still resolved, because the id leads the
        // segment, which is exactly why it survived: the failure is a link that looks
        // like corruption everywhere a nominee shares it.
        return '/vote/' . $programmeSlug . '/' . \AfricaGates\Support\Slug::idSegment($id, $name);
    }

    /** Bounce a legacy /vote/{id} link to its canonical nested URL. */
    private function nomineeRedirect(Response $res, int $id): Response {
        $row = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            // The demo programme carries `is_active = 0`, and that one column is the whole
            // thing keeping the sandbox off the public site. Without this, /vote/{id}
            // resolved ANY id — so it handed the canonical URL of a sandbox nominee to
            // anybody who guessed a number. Full account on nomineeBallot() below.
            ->where('p.is_active', 1)
            ->where('n.id', $id)->whereIn('n.status', self::PUBLIC_STATUSES)
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
        // "from this network" is gone, and so is the policy that produced it. That
        // wording was accusing a supporter of behaviour that belonged to a Cloudflare
        // edge address shared by the whole internet — see CheckoutThrottle. What is
        // left is a queue notice, and the caller appends when to come back.
        'rate'        => 'We are processing a burst of payments right now. Your details are saved — press pay again',
        'nominee'     => 'That nominee is not open for votes.',
        // The quantity is echoed back by the caller, so a bulk buyer is told the actual
        // limit instead of having their order quietly cut down to it.
        'toomany'     => 'That is more votes than one order can carry. The maximum is',
        'closed'      => 'Voting has closed for this category, so no votes could be purchased.',
        // The last few minutes belong to the free ballot. Card payments stop a
        // little earlier because they have to travel to a bank and back, and an
        // order that cannot finish in time is one we should never have taken.
        'cutoff'      => 'Card payment for this category has closed so payments in progress can finish. '
                       . 'Free voting is still open — use the ballot above.',
        'unavailable' => 'That payment method is unavailable right now. Please try another.',
        'email'       => 'Please enter a valid email address for your receipt.',
        'start'       => 'We could not start the checkout. No payment was taken — please try again.',
        'error'       => 'Something went wrong starting the checkout. No payment was taken.',
        'failed'      => 'That payment did not complete, so no votes were added.',
    ];

    /**
     * Read-and-clear the bounced paid-vote order.
     *
     * Cleared on read so it prefills exactly the one page load it was written for. A
     * flash that lingers would re-open a supporter's email address on a ballot they
     * later return to from a shared phone, and would silently re-arm a quantity they
     * had already decided against.
     *
     * @return array{qty:int, email:string, name:string, detail:string}
     */
    private function takePaidRetry(): array
    {
        $blank = ['qty' => 0, 'email' => '', 'name' => '', 'detail' => ''];
        if (!isset($_SESSION) || !is_array($_SESSION)) return $blank;
        $r = $_SESSION['paid_vote_retry'] ?? null;
        unset($_SESSION['paid_vote_retry']);
        if (!is_array($r)) return $blank;
        return [
            'qty'    => max(0, (int) ($r['qty'] ?? 0)),
            'email'  => mb_substr(trim((string) ($r['email'] ?? '')), 0, 191),
            'name'   => mb_substr(trim((string) ($r['name'] ?? '')), 0, 120),
            'detail' => mb_substr(trim((string) ($r['detail'] ?? '')), 0, 60),
        ];
    }

    /** The individual nominee vote page (profile-style with the OTP ballot inline). */
    private function nomineeBallot(Request $req, Response $res, int $id, string $programSeg = ''): Response {
        if ($id < 1) throw new \Slim\Exception\HttpNotFoundException($req);

        $nom = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            // ── A WINNER STILL HAS A PAGE ────────────────────────────────────
            //
            // This read `status = 'approved'`, which is the state a nominee is in
            // for exactly as long as the cycle is unfinished. CycleMaterialiser
            // writes 'winner' and 'runner_up' onto the nominee row the moment the
            // standings are sealed — so the promotion that is supposed to be the
            // high point of a nominee's cycle DELETED THEIR PUBLIC PAGE.
            //
            // Every link anybody had shared for the whole campaign 404s at the
            // moment it finally means something, and the supporters being told
            // "see the roll of honour" would have arrived at a not-found. The
            // filter is there to hide pending and rejected entries; a winner is
            // neither.
            //
            // ── AND THE SANDBOX IS NOT PUBLIC ────────────────────────────────
            //
            // DemoSeeder contains the demo by construction rather than by flag: its
            // programme carries `is_active = 0`, and that single column is the whole
            // public-invisibility mechanism. Its nominees, though, are seeded
            // 'approved' inside a cycle at 'voting' — so every other filter on this
            // query waves them through, and this is THE BALLOT. A sandbox nominee had
            // a live, votable page at its direct URL: real ballots landing on a row
            // that exists to be deleted, and a real voter's OTP spent against it.
            //
            // The hub and the programme page were never affected — AwardService and
            // SitemapService both require 1 already. This reader did not, which is
            // exactly why the containment note in DemoSeeder says "both public
            // readers" and there were three.
            //
            // Note what this means beyond the sandbox, because it is a real change:
            // "Active" is an operator checkbox, so deactivating a REAL programme now
            // takes its nominee pages down too. That was already true of the hub, the
            // programme page and the sitemap — so the only state this removes is a
            // nominee page that outlives the programme it belongs to, reachable by
            // direct link and de-indexed on the same day. Filtering on the slug
            // instead would keep that state alive and hard-code the sandbox besides;
            // `is_active` is the mechanism DemoSeeder deliberately chose so that
            // nothing new has to know the demo exists.
            ->where('p.is_active', 1)
            ->where('n.id', $id)->whereIn('n.status', self::PUBLIC_STATUSES)
            ->where(fn($q) => \AfricaGates\Services\MergeService::notMerged($q, 'n.merged_into'))
            // `organisation` joins the SELECT only when the column exists.
            //
            // A bare `n.organisation` would throw on any database whose migrations have
            // not been applied — and this is THE BALLOT, so the failure would be the
            // whole voting page rather than one missing line. Exactly the outage
            // `show_name` caused on the paid path; a new column is optional until every
            // deployment actually has it.
            ->select(array_merge([
                // `status` is SELECTED, not just filtered on. The celebration block
                // keys off it, and a column that is only in the WHERE clause reads
                // back as null — so the section silently never rendered for anybody.
                'n.id', 'n.name', 'n.status', 'n.tagline', 'n.photo_path', 'n.vote_count', 'n.country_code', 'n.profile_id',
                'c.id as category_id', 'c.title as category',
                'cy.id as cycle_id', 'cy.status as cycle_status', 'cy.year as year',
                'p.id as programme_id', 'p.title as programme_title', 'p.slug as programme_slug',
            ],
                \AfricaGates\Support\OptionalColumn::on('gates_nominees', 'organisation') ? ['n.organisation'] : [],
                // `story` is the nominator's FULL case for this person. Optional in the
                // same way and for the same reason as `organisation`: a bare column name
                // would throw on any deployment whose migrations have not been applied,
                // and this is THE BALLOT — the failure would be the whole voting page
                // rather than one missing paragraph.
                \AfricaGates\Support\OptionalColumn::on('gates_nominees', 'story') ? ['n.story'] : []))
            ->first();
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

        // Standing, gaps and 24-hour momentum. Computed in one place so the ballot, the
        // flier and anything added later cannot describe the same position three
        // different ways — which is how a nominee ends up with a share card that
        // contradicts the page it came from. Note this uses COMPETITION rank (ties share
        // a position) where $rank above is a list index; the two differ on a tie, and
        // the standing is the one that is defensible if a nominee disputes it.
        $standing = (new \AfricaGates\Services\StandingsService())
            ->forNominee($id, (int) $nom->category_id);

        // Absolute, because an og:image must be — a relative path is silently ignored by
        // every crawler and the preview falls back to nothing.
        $flierBase = \AfricaGates\Support\SiteUrl::base($req);
        $nomPath   = $this->nomineeUrl((int) $nom->id, (string) $nom->name, (string) $nom->programme_slug);
        // The og:image is the CARD, not the flier. Both are server-rendered rasters; the
        // difference is the aspect ratio, and it decides whether the preview works.
        $cardPng   = $flierBase . $nomPath . '/card.png';
        $others = array_values(array_filter($catList, fn($o) => (int) $o['id'] !== $id));

        // The one phase view-model for this ballot, from the nominee's own cycle.
        $phase = \AfricaGates\Services\BallotGuard::stateForCategory((int) $nom->category_id);

        // A bounced paid-vote checkout, read-and-cleared. PaidVoteController stashes
        // the buyer's quantity/email/name here rather than in the query string (see
        // its rememberOrder()), so the form they are sent back to arrives filled in
        // instead of blank — the difference between one retry and an abandoned sale.
        $retry = $this->takePaidRetry();
        $paidNotice = self::PAID_REASONS[trim((string) ($req->getQueryParams()['paid'] ?? ''))] ?? null;
        if ($paidNotice !== null && ($retry['detail'] ?? '') !== '') {
            $paidNotice .= ' ' . $retry['detail'] . '.';
        }

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
            // The competitive layer. A vote total on its own answers no question a
            // supporter has — whether their vote would matter, how close the race is,
            // whether the last push worked — so the page had no reason to be visited
            // twice and no reason to be shared, on a platform whose whole mechanic is
            // rallying support. Every figure is computed from real rows; where one
            // cannot be (momentum on a category with no timestamped votes, a gap when
            // the nominee leads) it comes back null and the template omits the element
            // rather than printing a zero that reads as a measurement.
            'standing'         => $standing,
            'standing_headline'=> \AfricaGates\Services\StandingsService::headline($standing),
            'standing_cta'     => \AfricaGates\Services\StandingsService::callToAction($standing),
            'flier_url'        => $nomPath . '/flier',
            'others'           => array_slice($others, 0, 5),
            // Same trail the page prints above the hero. The category has no page of
            // its own, so it is a label with no `item` — a BreadcrumbList entry
            // pointing at a URL that does not exist is an invalid item, and Google
            // drops the whole trail rather than the one entry.
            'breadcrumbs'      => [
                ['label' => 'Vote', 'url' => '/vote'],
                ['label' => (string) $nom->programme_title,
                 'url'   => '/vote/' . rawurlencode((string) $nom->programme_slug)],
                ['label' => (string) $nom->category, 'url' => null],
                ['label' => (string) $nom->name,     'url' => null],
            ],
            // Person JSON-LD on the ballot. This is the page people reach by typing a
            // NOMINEE'S NAME, and it was the one content page on the site with no
            // structured data at all — the registry profile had it, the leaderboard
            // had an ItemList, and the highest-intent page of the three had nothing.
            // `category_title` is mapped from the category so Schema::person emits the
            // award line; the key names differ because that helper serves both
            // profiles and nominees.
            'schema'           => \AfricaGates\Support\Schema::person(
                [
                    'name'           => (string) $nom->name,
                    'tagline'        => (string) ($nom->tagline ?? ''),
                    'story'          => (string) ($nom->story ?? ''),
                    'organisation'   => (string) ($nom->organisation ?? ''),
                    'country_code'   => (string) ($nom->country_code ?? ''),
                    'category_title' => (string) $nom->category,
                ],
                \AfricaGates\Support\SiteUrl::base($req),
                \AfricaGates\Support\SiteUrl::base($req) . $nomPath,
                (string) ($cardPng ?? '')
            ),
            // COMPUTED phase, so a stale status column cannot present an open
            // ballot for a closed cycle.
            'phase'            => $phase,
            'voting_open'      => (bool) ($phase['is_voting_open'] ?? false),
            'paid_notice'      => $paidNotice,
            // Repopulates the buy-votes form after a bounce. Empty on a normal visit.
            'paid_retry'       => $retry,
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
            // The quantity ladder, sent WHOLE rather than as a rendered list of chips.
            // The page has to price a quantity the buyer types by hand as well as one
            // they tap, so the browser needs the same rule the server prices with — not
            // a set of pre-computed totals it can only look up. Any chip the template
            // draws comes out of this same array.
            'vote_tiers'         => PaidVoteService::tiers(),
            // The form's `max` comes from the SAME function the checkout rejects on, so
            // the page can never offer a quantity the next request refuses.
            'max_qty'            => PaidVoteService::maxQtyForOrder(),
            'pay_providers'      => (PaidVoteService::enabled() && $this->payments) ? $this->payments->enabledProviders() : [],
            // Supporters who ASKED to be named. Empty for every nominee until someone
            // ticks the box — the reader publishes consent, not names it happens to hold.
            'supporters'         => $supporters = \AfricaGates\Services\SupportersService::forNominee((int) $nom->id),
            'supporter_count'    => $supporterCount = \AfricaGates\Services\SupportersService::countForNominee((int) $nom->id),
            // The tail is phrased by the service, not the template: exact while the
            // number is small enough to be information, rounded to "100+" once it
            // is just a big number that changes on every reload.
            'supporters_more'    => \AfricaGates\Services\SupportersService::overflowLabel(
                                        max(0, $supporterCount - count($supporters))),
            // ── The celebration ──────────────────────────────────────────────
            //
            // Set only once this nominee has actually been promoted, so the whole
            // block is absent for everybody else rather than sitting there empty.
            // 'winner' and 'runner_up' are written by CycleMaterialiser at the moment
            // the standings are sealed — this READS that decision, it does not make
            // one of its own.
            'award_kind'         => in_array((string) ($nom->status ?? ''), ['winner', 'runner_up'], true)
                                        ? (string) $nom->status : '',
            // The named supporters again, but more of them. The wall further down is
            // a taste; on the day somebody wins, the roll of honour is the page.
            'roll_of_honour'     => in_array((string) ($nom->status ?? ''), ['winner', 'runner_up'], true)
                                        ? \AfricaGates\Services\SupporterHonours::rollOfHonour((int) $nom->id)
                                        : [],
            // Everybody behind them, named or not — the honest size of the crowd,
            // which the consented list always understates.
            'backer_count'       => \AfricaGates\Services\CommunityReturnService::supporterCount((int) $nom->id),
            // ── What voters SAID ─────────────────────────────────────────────
            //
            // Approved messages only, resolved against each voter's naming consent
            // inside the service — the template never sees a name it is not allowed
            // to print. The count is of approved messages too, so the "showing the N
            // most recent" line cannot imply held-back messages that a reader would
            // then wonder about.
            // Six on the ballot, not twelve. This is a VOTING page — the wall is
            // evidence that other people showed up, and past about half a dozen it
            // becomes a scroll between the reader and the ballot they came for. The
            // rest live at their own URL.
            'messages'           => \AfricaGates\Services\VoteMessageService::wall((int) $nom->id, 6),
            'message_count'      => \AfricaGates\Services\VoteMessageService::countForNominee((int) $nom->id),
            'messages_url'       => $nomPath . '/messages',
            // "and 40 more" used to lead nowhere. Every one of those people ticked a
            // box asking to be named in public.
            'supporters_url'     => $nomPath . '/supporters',
            // The ceiling comes from the service, not the textarea: the server
            // truncates at this number, so a `maxlength` that disagreed would either
            // silently cut a voter's words or promise room that does not exist.
            'msg_max'            => \AfricaGates\Services\VoteMessageService::MAX_LEN,
        ] + array_filter([
            // Social card: the nominee's own photo when they have one.
            /**
             * A PURPOSE-BUILT CARD IS THE LINK PREVIEW.
             *
             * Two steps got here. It was originally the nominee's bare photo, which
             * previews as a face with no context — no name, no category, no standing, no
             * reason to tap — on what is the single highest-intent share on the platform.
             * That was replaced by the flier, which persuades but is 4:5, and Facebook and
             * LinkedIn crop an og:image to 1.91:1 while WhatsApp crops to roughly square.
             * So the bottom third went missing in every preview, and the bottom third of
             * the flier is the vote URL, the rally copy and the jury footnote.
             *
             * {@see \AfricaGates\Services\FlierService::ogCard()} is 1200×630 — the shape
             * the platforms want — with the face in a column beside the text instead of
             * behind it. The flier is unchanged and remains what a nominee downloads.
             *
             * It has to be a RASTER at a URL: no major chat app renders SVG in a preview
             * and a crawler cannot run JavaScript.
             */
            'og_image'      => $cardPng,
            'og_image_w'    => \AfricaGates\Services\FlierService::OG_W,
            'og_image_h'    => \AfricaGates\Services\FlierService::OG_H,
            'og_image_type' => 'image/png',
            'og_image_alt'  => 'Vote for ' . $nom->name . ' in ' . $nom->category
                . ' — ' . \AfricaGates\Services\StandingsService::headline($standing),

        ], fn($v) => $v !== null));
    }
}
