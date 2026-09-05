<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Views\Twig;
use AfricaGates\Services\{PaymentService, RateLimitService, OtpService, Notifier};

/**
 * Donations — free-amount philanthropic giving that funds child leadership
 * programmes. Same security model as {@see ShopCheckoutController}:
 *   1. The amount is donor-CHOSEN but server-clamped to a sane naira range and
 *      cast to an integer; an optional processing-fee cover is applied
 *      server-side (a client-sent total is never trusted).
 *   2. A PENDING gates_donations row is written before leaving for the gateway.
 *   3. Confirmation requires verify()=success AND the verified amount equalling
 *      the row's amount, via an idempotent pending→confirmed transition.
 *
 * Donations grant NO votes (bonus_votes = 0): money never touches the CPI — it
 * funds programmes. The page's figures are drawn from real confirmed rows, never
 * invented.
 */
final class DonationController
{
    private const MIN_NAIRA = 200;
    private const MAX_NAIRA = 5_000_000;

    public function __construct(
        private readonly PaymentService    $payments,
        private readonly Twig              $view,
        private readonly ?RateLimitService $rateLimit = null,
        private readonly ?OtpService       $mailer = null,
        private readonly ?LoggerInterface  $log = null,
    ) {}

    /**
     * Absolute site base. Via SiteUrl, which falls back to the REQUEST when APP_URL is
     * unset — this used to return '' and every gateway callback URL built from it was
     * relative, which a payment provider cannot redirect a browser to. See SiteUrl.
     */
    private function base(?Request $req = null): string
    {
        return \AfricaGates\Support\SiteUrl::base($req);
    }
    private function redirect(Response $res, string $url): Response { return $res->withHeader('Location', $url)->withStatus(302); }

    /**
     * Human-readable reason a donation attempt bounced back to /donate.
     *
     * The template has always had `{% if error %}<div class="dn-err" role="alert">`
     * waiting for one, and `page()` never passed anything — so all seven refusals
     * this controller emits as `?give=` landed a donor on an unchanged page with no
     * message and their amount reset. Same silent-bounce defect as the paid-vote
     * path, in the flow that funds the programmes.
     */
    private const GIVE_REASONS = [
        'rate'        => 'Checkout is busy right now — please try that again in a moment.',
        'unavailable' => 'That payment method is unavailable right now. Please try another.',
        'email'       => 'Please enter a valid email address for your receipt.',
        'low'         => 'That amount is below the minimum we can process.',
        'high'        => 'That amount is above what this form can process — contact us and we will arrange it.',
        'start'       => 'We could not start the checkout. No payment was taken — please try again.',
        'error'       => 'Something went wrong starting the checkout. No payment was taken.',
        'failed'      => 'That payment did not complete, so nothing was charged.',
        // A partner that stopped being able to receive money between the page rendering and
        // the form posting — suspended, or its settlement account detached. Said plainly,
        // because the alternative is a donor whose money silently went somewhere else.
        'closed'      => 'That appeal has closed and is no longer accepting donations. Nothing was charged.',
        // A specific appeal that closed while somebody had its page open. Distinct from
        // `closed` so the message can say the organisation is still collecting.
        'appeal_closed' => 'That appeal closed before your gift went through, so nothing was '
                         . 'charged. This organisation is still accepting donations.',
    ];

    /** GET /donate — the giving page. */
    public function page(Request $req, Response $res, array $args = []): Response
    {
        // ── GIVING TO A PARTNER, OR TO US ────────────────────────────────────
        //
        // One template, two recipients. A slug that does not resolve to a partner currently
        // able to receive money is a 404 rather than a silent fall-through to the Africa
        // GATES page: somebody following a link to a suspended charity must be told the
        // appeal is closed, not quietly redirected into giving to a different organisation.
        $slug     = trim((string) ($args['slug'] ?? ''));
        $campSlug = trim((string) ($args['campaign'] ?? ''));
        $org      = null;
        $campaign = null;

        if ($slug !== '') {
            $org = \AfricaGates\Services\PartnerOrg::bySlug($slug);

            // A campaign only exists inside an organisation, so it is resolved second and
            // only if the organisation itself is live. A closed appeal 404s for the same
            // reason a suspended partner does: quietly redirecting somebody who followed a
            // link to a specific cause into giving to a general fund is not a fallback, it
            // is spending their intention on something they did not choose.
            if ($campSlug !== '' && $org) {
                $campaign = \AfricaGates\Services\OrgCampaign::bySlug((int) $org->id, $campSlug);
                if (!\AfricaGates\Services\OrgCampaign::isOpen($campaign)) {
                    return $this->view->render($res->withStatus(404), 'pages/donate.twig', [
                        'error'      => $campaign
                            ? 'That appeal has closed. Thank you to everyone who gave.'
                            : 'That appeal could not be found.',
                        'page_title' => 'Appeal closed — Africa GATES',
                        'gates_page' => 'donate', 'has_hero' => false,
                        'providers'  => [], 'stats' => $this->stats(), 'givers' => [],
                    'org_totals' => \AfricaGates\Services\PartnerOrg::platformTotals(),
                        'org' => null, 'campaign' => null, 'org_closed' => true,
                    'fund_goal' => null, 'recurring' => false,
                        'fund_goal' => null, 'recurring' => false,
                        'min_naira' => self::MIN_NAIRA, 'max_naira' => self::MAX_NAIRA,
                        'processing_fee_pct' => $this->processingFeePct(),
                    ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
                }
            }

            if (!\AfricaGates\Services\PartnerOrg::canReceive($org)) {
                return $this->view->render($res->withStatus(404), 'pages/donate.twig', [
                    'error'      => 'That appeal is not open for gifts.',
                    'page_title' => 'Appeal closed — Africa GATES',
                    'gates_page' => 'donate', 'has_hero' => false,
                    'providers'  => [], 'stats' => $this->stats(), 'givers' => [],
                    'org_totals' => \AfricaGates\Services\PartnerOrg::platformTotals(),
                    'org' => null, 'campaign' => null, 'org_closed' => true,
                    'min_naira' => self::MIN_NAIRA, 'max_naira' => self::MAX_NAIRA,
                    'processing_fee_pct' => $this->processingFeePct(),
                ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
            }
        }

        // Once. `stats()` is a sum and a count over the same table, and the goal panel needs
        // the same figure the masthead prints — two reads could not disagree by much, but
        // they would be two reads of the number this page exists to be trusted about.
        $stats = $campaign
            ? (function () use ($campaign): array {
                $p = \AfricaGates\Services\OrgCampaign::progress((int) $campaign->id);
                return ['raised_naira' => $p['raised'], 'gifts' => $p['count']];
            })()
            : ($org ? $this->orgStats((int) $org->id) : $this->stats());

        return $this->view->render($res, 'pages/donate.twig', [
            'error'            => self::GIVE_REASONS[trim((string) ($req->getQueryParams()['give'] ?? ''))] ?? null,
            'page_title'       => $campaign
                ? ($campaign->title . ' — ' . $org->name)
                // "Donations" is the noun on this surface — it is what the navigation, the
                // footer and the page itself say, and a title that says something else is
                // the line a search result and a browser tab show.
                : ($org ? ('Donate to ' . $org->name . ' — Africa GATES') : 'Donations — Africa GATES'),
            'meta_description' => $org
                ? ('Donate to ' . $org->name . ' through Africa GATES. Your donation settles directly to the organisation.')
                : 'Fund child leadership programmes across the continent — mentorship, scholarships and grassroots education. Every donation is receipted and independently audited.',
            'gates_page'       => 'donate',
            'has_hero'         => false,
            'providers'        => $this->payments->enabledProviders(),
            'stats'            => $stats,
            'givers'           => $org ? [] : $this->recentGivers(),
            // Only for the Africa GATES fund. A partner's appeal already has a campaign
            // goal of its own, and a second target beside it would be this platform's
            // ambition printed over somebody else's ask.
            'fund_goal'        => $org ? null : $this->fundGoal($stats),
            // Whether a monthly gift can actually be honoured here. False hides the control
            // entirely — an option that quietly falls back to a single gift is worse than no
            // option, because the donor leaves believing they set something up. Never on a
            // partner appeal: that money settles into somebody else's subaccount, and a
            // standing order against another organisation's account is not this platform's
            // to create on their behalf.
            'recurring'        => !$org && \AfricaGates\Services\RecurringGiving::available($this->payments),
            'org'              => $org,
            'campaign'         => $campaign,
            // Summed from confirmed rows on every read, never cached. A fundraising figure
            // that drifts from the rows underneath it is the one number nobody forgives.
            'progress'         => $campaign
                ? \AfricaGates\Services\OrgCampaign::progress((int) $campaign->id) : null,
            'days_left'        => $campaign
                ? \AfricaGates\Services\OrgCampaign::daysLeft($campaign) : null,
            'shortfall_text'   => $campaign
                ? (\AfricaGates\Services\OrgCampaign::SHORTFALL[$campaign->shortfall_policy] ?? '') : '',
            'appeals'          => $org && !$campaign
                ? \AfricaGates\Services\OrgCampaign::openFor((int) $org->id) : [],
            // The platform's share, in the only unit a donor can act on. A percentage in a
            // footnote is not a disclosure; the template shows this in naira beside the
            // amount, because a fee discovered after payment is the fastest way to lose a
            // donor permanently.
            // ── SO A SEARCH RESULT SHOWS THE CAUSE, NOT "some website" ───────
            //
            // NGO + DonateAction on a partner's own appeal page. These are the pages whose
            // whole job is to be FOUND by somebody searching for a cause — and until the
            // sitemap work above, they were in no section at all, so nothing had ever been
            // told they exist.
            //
            // Deliberately carries the TARGET and never the amount raised: schema.org has
            // no honest slot for a running total, and a figure cached in somebody else's
            // index is a figure we cannot correct. A target is a published commitment and
            // cannot go stale the same way. See Support\Schema::appeal().
            'schema'           => $org
                ? \AfricaGates\Support\Schema::appeal(
                    $org, $campaign,
                    \AfricaGates\Support\SiteUrl::base($req),
                    \AfricaGates\Support\SiteUrl::base($req) . '/donate/' . rawurlencode((string) $org->slug)
                        . ($campaign ? '/' . rawurlencode((string) $campaign->slug) : ''),
                    '',
                    // The ORGANISATION's own description. Passing the campaign summary here
                    // would make the NGO node describe one appeal — so a charity running a
                    // borehole fund would be described to a search engine as "two boreholes
                    // for four schools" and nothing else. The campaign's own words go on the
                    // DonateAction, which is where Schema::appeal() already puts them.
                    (string) ($org->description ?? '')
                  )
                : null,
            'org_fee_bps'      => $org ? (int) ($org->platform_fee_bps ?? 0) : 0,
            'partners'         => $org ? [] : \AfricaGates\Services\PartnerOrg::listReceivable(),
            // What the platform has done FOR organisations, for the other kind of reader on
            // this page. Only meaningful on the Africa GATES page — on a partner's own appeal
            // the block that uses it is suppressed, because an advert for the platform in the
            // middle of somebody else's ask competes with the reason the visitor came.
            'org_totals'       => \AfricaGates\Services\PartnerOrg::platformTotals(),
            'min_naira'        => self::MIN_NAIRA,
            'max_naira'        => self::MAX_NAIRA,
            'processing_fee_pct' => $this->processingFeePct(),
        ]);
    }

    /** Confirmed totals for one partner. Same doctrine as stats(): never fabricated. */
    private function orgStats(int $orgId): array
    {
        $t = \AfricaGates\Services\PartnerOrg::totals($orgId);
        return ['raised_naira' => $t['gross'], 'gifts' => $t['count']];
    }

    /**
     * The processing-fee cover percentage, from admin settings.
     *
     * ── IT WAS ADMIN-CONFIGURABLE AND SERVER-IGNORED ─────────────────────────
     *
     * `processing_fee_pct` has a field on the settings screen and was read by nothing. The
     * template printed it as `{{ processing_fee_pct }}` on a page that never passed it — so
     * the checkbox read "Add % to cover processing" with the number missing — while start()
     * hard-coded 1.5% regardless of what an operator had set.
     *
     * Both halves are the same defect: a donor was shown one thing and charged another. One
     * source now feeds the sentence and the arithmetic, so they cannot disagree.
     */
    private function processingFeePct(): float
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'processing_fee_pct')->value('value');
        } catch (\Throwable) {
            $v = null;
        }
        $pct = is_numeric($v) ? (float) $v : 1.5;
        // Clamped: a mistyped 150 in an admin field must not multiply somebody's gift.
        return max(0.0, min(10.0, $pct));
    }

    /** Real, public-safe aggregates from confirmed donations (never fabricated). */
    private function stats(): array
    {
        try {
            $raised = (int) DB::table('gates_donations')->where('status', 'confirmed')->sum('amount_naira');
            $gifts  = (int) DB::table('gates_donations')->where('status', 'confirmed')->count();
        } catch (\Throwable $e) { $raised = 0; $gifts = 0; }
        return ['raised_naira' => $raised, 'gifts' => $gifts];
    }

    /**
     * The fund's own goal and how far along it is — the thing this page did not have.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A TARGET AND NOT JUST A TOTAL
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A partner's appeal has had a goal and a progress bar since it shipped; the Africa
     * GATES fund, on the same template, had a raised figure and nothing to measure it
     * against. "₦2,400,000 raised" is a fact with no shape — a stranger cannot tell whether
     * that is nearly done or barely started, and a number that cannot be read as progress
     * does not read as an ask.
     *
     * ── AND WHY IT IS NULL UNTIL SOMEBODY SETS ONE ───────────────────────────
     *
     * An invented target is worse than none. `donation_goal_naira` is unset by default and
     * this returns null, which leaves the page exactly as it was — the operator decides what
     * this fund is raising for, on the settings screen, because there is no shell on
     * production and a target that can only be edited in a template cannot be moved.
     *
     * Summed from CONFIRMED rows on every read, never cached, for the same reason the
     * campaign progress is: a fundraising figure that drifts from the rows underneath it is
     * the one number nobody forgives.
     *
     * @return array{goal:int, raised:int, pct:int, remaining:int, met:bool}|null
     */
    private function fundGoal(array $stats): ?array
    {
        try {
            $goal = (int) (DB::table('gates_settings')
                ->where('key_name', 'donation_goal_naira')->value('value') ?? 0);
        } catch (\Throwable) {
            return null;
        }

        if ($goal < 1) return null;

        $raised = max(0, (int) ($stats['raised_naira'] ?? 0));

        return [
            'goal'   => $goal,
            'raised' => $raised,
            // Capped for the BAR only. `raised` above is the true figure and the page prints
            // it — a bar that runs past its own track reads as a rendering fault rather than
            // as good news, and passing the target is good news.
            'pct'    => (int) min(100, (int) round($raised / $goal * 100)),
            'remaining' => max(0, $goal - $raised),
            'met'    => $raised >= $goal,
        ];
    }

    /** Recent confirmed gifts, lightly anonymised (first name + last initial). */
    private function recentGivers(): array
    {
        try {
            // BY TIME, then by id. It ordered by id alone, which is insertion order and
            // only coincides with chronology while nothing is ever written late — a
            // reconciled gift, a webhook that arrived after a retry, a backfill. The
            // mismatch was invisible while the ledger printed no dates; the moment it
            // started printing them, "Recent donations" was showing an April gift above a
            // two-minute-old one. `id` stays as the tiebreak so two gifts in the same
            // second still order deterministically.
            $rows = DB::table('gates_donations')->where('status', 'confirmed')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(5)->get(['donor_name', 'amount_naira', 'created_at']);
        } catch (\Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name'   => $this->anon((string)($r->donor_name ?? '')),
                'amount' => (int)$r->amount_naira,
                'when'   => (string)($r->created_at ?? ''),
                // ── THE COLUMN THAT WAS COLLECTED AND NEVER RENDERED ─────────
                //
                // `when` has been selected, carried and handed to the template since this
                // ledger shipped, and the template printed a name and an amount. So a page
                // whose whole job is to show that other people are giving could not say
                // whether the last gift arrived this morning or last year — which is the
                // difference between a live appeal and an archive.
                'ago'    => self::ago((string)($r->created_at ?? '')),
            ];
        }
        return $out;
    }

    /**
     * A timestamp as a person would say it: "just now", "3 hours ago", "4 Sep".
     *
     * Private and small on purpose. A site-wide relative-time helper with one caller is the
     * declaration-with-no-reader this codebase keeps paying for; when a second surface wants
     * this, it moves to Support and gets its own tests.
     *
     * Falls to the DATE past a day rather than counting on — "47 days ago" is arithmetic a
     * reader has to undo, and by then the useful fact is when, not how long.
     */
    private static function ago(string $stamp): string
    {
        $stamp = trim($stamp);
        if ($stamp === '') return '';

        try {
            $then = new \DateTimeImmutable($stamp, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return '';
        }

        $secs = time() - $then->getTimestamp();
        // A clock ahead of the row is a deployment detail, not a gift from the future.
        if ($secs < 60) return 'just now';

        if ($secs < 3600) {
            $m = (int) floor($secs / 60);
            return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($secs < 86400) {
            $h = (int) floor($secs / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }

        // In the platform's own display zone, like every other date a visitor reads here.
        return \AfricaGates\Support\DisplayTime::show($stamp, 'j M');
    }

    /** "Amara Okonkwo" → "Amara O."; blank/default → "A supporter". */
    private function anon(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, 'Supporter') === 0) return 'A supporter';
        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = $parts[0];
        $last  = count($parts) > 1 ? mb_substr((string)end($parts), 0, 1) . '.' : '';
        return trim($first . ' ' . $last);
    }

    /** POST /donate — first-party, CSRF-protected. Starts hosted checkout. */
    public function start(Request $req, Response $res): Response
    {
        $b        = (array)$req->getParsedBody();
        $provider = strtolower(trim((string)($b['provider'] ?? '')));
        $email    = strtolower(trim((string)($b['email'] ?? '')));
        // Title-cased on the way in — see PaidVoteController. A donor's name is
        // published on the supporters wall and printed on their receipt.
        $name     = \AfricaGates\Support\Name::title((string)($b['name'] ?? ''));
        $baseAmt  = (int) preg_replace('/[^0-9]/', '', (string)($b['amount'] ?? '0'));
        $cover    = !empty($b['cover_fees']);
        $orgSlug  = trim((string)($b['org'] ?? ''));

        // ── WHO IS BEING GIVEN TO ────────────────────────────────────────────
        //
        // Resolved SERVER-SIDE from the slug and re-checked against canReceive(), never
        // trusted from the form. The posted slug is a request, not an instruction: a
        // suspended partner whose page somebody still has open in a tab must not be able to
        // take another naira, and the page is a cache of that decision while this is the
        // decision.
        $org      = null;
        $campaign = null;
        if ($orgSlug !== '') {
            $org = \AfricaGates\Services\PartnerOrg::bySlug($orgSlug);
            if (!\AfricaGates\Services\PartnerOrg::canReceive($org)) {
                return $this->redirect($res, $this->base($req) . '/donate?give=closed');
            }

            // The appeal is re-resolved and re-checked HERE, not trusted from the form. A
            // campaign that closed between the page rendering and the form posting must not
            // take another naira — and a campaign slug belonging to a DIFFERENT organisation
            // must not attach, which is why it is looked up by (org, slug) together rather
            // than by slug alone.
            $campSlug = trim((string) ($b['campaign'] ?? ''));
            if ($campSlug !== '') {
                $campaign = \AfricaGates\Services\OrgCampaign::bySlug((int) $org->id, $campSlug);
                if (!\AfricaGates\Services\OrgCampaign::isOpen($campaign)) {
                    return $this->redirect($res, $this->base($req) . '/donate/'
                        . rawurlencode($orgSlug) . '?give=appeal_closed');
                }
            }
        }

        $back = $org
            ? ('/donate/' . rawurlencode($orgSlug) . ($campaign ? '/' . rawurlencode((string) $campaign->slug) : ''))
            : '/donate';
        $bail = fn(string $why) => $this->redirect($res, $this->base($req) . $back . '?give=' . urlencode($why));

        if (!$this->payments->isEnabled($provider))                          return $bail('unavailable');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))     return $bail('email');
        if ($baseAmt < self::MIN_NAIRA)                                      return $bail('low');
        if ($baseAmt > self::MAX_NAIRA)                                      return $bail('high');

        // Throttled here, not first: the same defect as the paid-vote path. Ten per
        // hour keyed on REMOTE_ADDR is one bucket for the whole internet behind
        // Cloudflare, and it was charged for rejected attempts too — so a donor who
        // mistyped an amount spent quota that a real donor then found exhausted.
        // See CheckoutThrottle.
        if (!(new \AfricaGates\Services\CheckoutThrottle($this->rateLimit))->allow($req, 'donate')['ok']) {
            return $bail('rate');
        }

        // Optional processing-fee cover, computed SERVER-SIDE (never trust a client total).
        $amount = $cover ? (int) ceil($baseAmt * (1 + $this->processingFeePct() / 100)) : $baseAmt;
        $amount = min(self::MAX_NAIRA, max(self::MIN_NAIRA, $amount));

        $reference = 'AFG-GIVE-' . bin2hex(random_bytes(6));

        $row = [
            'donor_name'     => $name !== '' ? mb_substr($name, 0, 120) : 'Supporter',
            'donor_email'    => $email,
            'donor_phone'    => null,
            'donor_location' => null,
            'amount_naira'   => $amount,
            'tier'           => 'donation',
            'bonus_votes'    => 0,
            'votes_used'     => 0,
            'payment_ref'    => $reference,
            'status'         => 'pending',
            'created_at'     => Carbon::now()->toDateTimeString(),
        ];

        // The recipient is written onto the row BEFORE the gateway is called, because that
        // row is what PaymentDestination reads back from the reference to decide which
        // subaccount this settles into. Writing it afterwards would route the money to the
        // platform and only then record that it belonged to somebody else.
        //
        // The platform's own share is stored in naira at the moment of the gift rather than
        // recomputed later from a rate: a fee percentage that changes next quarter must not
        // retroactively restate what a partner earned last quarter. Same doctrine as money
        // columns being written once.
        if ($org) {
            $feeBps = (int) ($org->platform_fee_bps ?? 0);
            $row['recipient_org_id']   = (int) $org->id;
            $row['platform_fee_naira'] = (int) floor($amount * $feeBps / 10000);
            // NULL means the organisation's general fund. A campaign id means the donor gave
            // for the thing that appeal describes, and the money is restricted to it.
            $row['campaign_id']        = $campaign ? (int) $campaign->id : null;
        }

        try {
            DB::table('gates_donations')->insert($row);
        } catch (\Throwable $e) {
            $this->log?->error('[donate] could not persist pending donation', ['err' => $e->getMessage()]);
            return $bail('error');
        }

        // ── A MONTHLY GIFT IS A PLAN AT THE GATEWAY ──────────────────────────
        //
        // Asked for on the form, honoured only where it can actually be honoured. The plan
        // is resolved (created once per amount, then remembered) BEFORE the checkout starts,
        // so a gateway that refuses to make one leaves the donor giving once rather than
        // believing they have set up something that does not exist.
        //
        // Not offered on a partner appeal. Those settle into somebody else's subaccount and
        // a standing order against another organisation's account is an arrangement this
        // platform has no business creating on their behalf without them asking for it.
        $monthly = !$org
                   && in_array(strtolower(trim((string) ($b['monthly'] ?? ''))), ['1', 'on', 'true', 'yes'], true)
                   && \AfricaGates\Services\RecurringGiving::available($this->payments);

        $planCode = '';
        if ($monthly) {
            $plan = \AfricaGates\Services\RecurringGiving::planFor($amount, $this->payments);
            if ($plan['ok']) {
                $planCode = $plan['code'];
                // Recorded BEFORE the donor leaves, for the same reason the donation row is:
                // somebody who pays inside a wallet app and never comes back must not leave a
                // subscription that exists at Paystack and nowhere here.
                \AfricaGates\Services\RecurringGiving::start(
                    $email, (string) ($row['donor_name'] ?? ''), $amount, $planCode, $reference);
            } else {
                // Falls back to a one-off rather than refusing the gift. The donor came to
                // give; losing the payment because a plan could not be created would be the
                // more expensive answer, and the receipt tells them what they actually did.
                $this->log?->error('[donate] could not create a recurring plan — giving once instead', [
                    'ref' => $reference, 'amount' => $amount, 'reason' => (string) ($plan['message'] ?? ''),
                ]);
                $monthly = false;
            }
        }

        $callbackUrl = $this->base($req) . '/donate/callback?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);
        $init = $this->payments->initialize($provider, $amount, $email, $reference, $callbackUrl, [
            'reference' => $reference, 'purpose' => 'donation',
            // Onto the gateway's own record, so `subscription.create` — which does not carry
            // our reference anywhere else — can be tied back to the intention we wrote above.
            'recurring' => $monthly ? 'monthly' : 'once',
        ], $planCode);
        if (!$init['ok'] || empty($init['checkout_url'])) {
            // The provider's OWN message. It was discarded, leaving an operator with a
            // generic chip and no way to know the gateway said "Invalid key".
            $this->log?->error('[donate] gateway would not start a transaction', [
                'ref' => $reference, 'provider' => $provider, 'reason' => (string) ($init['message'] ?? ''),
            ]);
            DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'pending')->update(['status' => 'failed']);
            return $bail('start');
        }
        // NOT a 302 straight to the gateway: that redirect is part of a form
        // submission, so `form-action` governs it and a policy without the gateway
        // hosts blocks the POST in the browser before any PHP runs. See GatewayHandoff.
        return $this->redirect($res, \AfricaGates\Services\GatewayHandoff::remember(
            $reference, (string) $init['checkout_url'], $this->base($req) . '/donate/redirect', $provider
        ));
    }

    /** GET /donate/callback — browser return; re-verified server-to-server. */
    /**
     * GET /donate/giving/{token} — one standing gift, and the button that stops it.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A LINK IN THE RECEIPT AND NOT A LOGIN
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Most donors here have no account and never will. Putting a cancellation behind a sign
     * up means the only way to stop giving is to first join something — which is the pattern
     * people take to their bank instead, and a chargeback costs the platform more than the
     * gift was worth. A donor who cannot easily stop is not a supporter, they are a dispute
     * waiting for a quiet month.
     *
     * The link is per GIFT, not per donor: a receipt is for one arrangement, and a link that
     * also listed somebody's other gifts would turn a forwarded email into a disclosure.
     */
    public function giving(Request $req, Response $res, array $args = []): Response
    {
        $sub = \AfricaGates\Services\RecurringGiving::byToken((string) ($args['token'] ?? ''));

        $flash = $_SESSION['flash_ok'] ?? null;
        $err   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

        return $this->view->render($res->withStatus($sub ? 200 : 404), 'pages/donate-giving.twig', [
            'page_title' => $sub ? 'Your monthly gift — Africa GATES' : 'Link not valid — Africa GATES',
            'gates_page' => 'donate', 'has_hero' => false,
            'sub'        => $sub,
            'token'      => (string) ($args['token'] ?? ''),
            'flash_ok'   => $flash,
            'flash_err'  => $err,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * POST /donate/giving/{token}/stop — stop it at the gateway.
     *
     * Our row moves to `cancelling`, never straight to `cancelled`. A subscription this
     * platform believes is stopped while Paystack is still billing reaches a person as "you
     * charged me after I cancelled" — so the webhook is what makes it `cancelled`, which is
     * the gateway's word rather than our hope.
     */
    public function givingStop(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $back  = '/donate/giving/' . rawurlencode($token);
        $sub   = \AfricaGates\Services\RecurringGiving::byToken($token);

        if (!$sub) return $this->redirect($res, '/donate');

        if (in_array((string) $sub['status'], [\AfricaGates\Services\RecurringGiving::ST_CANCELLED,
                                               \AfricaGates\Services\RecurringGiving::ST_CANCELLING], true)) {
            // Already done. Saying so is the answer; asking the gateway again would risk an
            // error message on a page whose whole job is to reassure.
            $_SESSION['flash_ok'] = 'This gift is already stopped. You will not be charged again.';
            return $this->redirect($res, $back);
        }

        $r = $this->payments->cancelSubscription(
            (string) ($sub['subscription_code'] ?? ''), (string) ($sub['email_token'] ?? ''));

        if ($r['ok']) {
            \AfricaGates\Services\RecurringGiving::markCancelling((int) $sub['id']);
            $_SESSION['flash_ok'] = 'Your monthly gift has been stopped. You will not be charged again — '
                                  . 'everything you have already given stays with the programmes.';
        } else {
            // The gateway's OWN words. "Something went wrong" on a page about money leaving
            // somebody's account is not an answer, and the next thing they do is call a bank.
            $_SESSION['flash_error'] = trim((string) $r['message']) !== ''
                ? ('We could not stop it: ' . $r['message'] . ' Email us and we will do it by hand.')
                : 'We could not stop it just now. Email us and we will do it by hand.';
        }

        return $this->redirect($res, $back);
    }

    public function callback(Request $req, Response $res): Response
    {
        $q         = $req->getQueryParams();
        $reference = trim((string)($q['ref'] ?? $q['reference'] ?? $q['tx_ref'] ?? ''));
        $provider  = strtolower(trim((string)($q['provider'] ?? '')));
        if ($reference === '' || !$this->payments->isKnownProvider($provider)) {
            return $this->redirect($res, $this->base($req) . '/donate?give=error');
        }
        $don = DB::table('gates_donations')->where('payment_ref', $reference)->first();
        if (!$don) return $this->redirect($res, $this->base($req) . '/donate?give=error');

        $result = $this->confirm($provider, $reference, $don);
        if ($result === 'confirmed' || $result === 'already') {
            if ($result === 'confirmed') $this->receipt($don);
            return $this->redirect($res, $this->base($req) . '/donate/success?ref=' . urlencode($reference));
        }
        return $this->redirect($res, $this->base($req) . '/donate?give=failed');
    }

    /** GET /donate/success — read-only confirmation. */
    public function success(Request $req, Response $res): Response
    {
        $reference = trim((string)($req->getQueryParams()['ref'] ?? ''));
        $don = $reference !== ''
            ? DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'confirmed')->first()
            : null;
        return $this->view->render($res, 'pages/donate-success.twig', [
            'page_title'       => 'Thank you — Africa GATES',
            'meta_description' => 'Thank you for funding child leadership programmes across the continent.',
            'gates_page'       => 'donate',
            'has_hero'         => false,
            'confirmed'        => $don !== null,
            'amount_naira'     => $don ? (int)$don->amount_naira : 0,
            'reference'        => $reference,
        ]);
    }

    /** Idempotent confirm — mirrors PaymentController/ShopCheckoutController. */
    private function confirm(string $provider, string $reference, object $don): string
    {
        if (($don->status ?? '') === 'confirmed') return 'already';
        $v = $this->payments->verify($provider, $reference);
        if (!$v['ok'] || ($v['status'] ?? '') !== 'success') {
            if (($v['status'] ?? '') === 'failed') {
                DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'pending')->update(['status' => 'failed']);
            }
            return 'failed';
        }
        if ((int)$v['amount'] !== (int)$don->amount_naira) {
            $this->log?->warning('[donate] amount mismatch — refusing to confirm', ['ref' => $reference]);
            return 'failed';
        }
        $changed = DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'pending')->update(['status' => 'confirmed']);
        return $changed > 0 ? 'confirmed' : 'already';
    }

    /** One-time receipt + admin alert on a freshly-confirmed gift. */
    private function receipt(object $don): void
    {
        $total = '₦' . number_format((int)$don->amount_naira);
        if ($this->mailer) {
            try {
                $this->mailer->sendBranded(
                    (string)$don->donor_email,
                    'Thank you for your gift to Africa GATES',
                    '<p>Thank you, ' . htmlspecialchars((string)$don->donor_name) . ' — your gift of <strong>' . $total . '</strong> is confirmed.</p>'
                    . '<p style="font-family:monospace">Receipt ' . htmlspecialchars((string)$don->payment_ref) . '</p>'
                    . '<p>Your gift funds child leadership programmes across the continent — mentorship, scholarships and grassroots education. With gratitude.</p>',
                    'Donations'
                );
            } catch (\Throwable $e) { /* a receipt failure must never break confirmation */ }
        }
        Notifier::adminAlert($this->mailer, 'New donation (confirmed)',
            'Donor:  ' . (string)$don->donor_name . ' <' . (string)$don->donor_email . ">\nAmount: " . $total . "\nRef:    " . (string)$don->payment_ref);
    }

    /**
     * GET /donate/redirect — the same-origin hop to the gateway.
     *
     * See {@see \AfricaGates\Services\GatewayHandoff}: a 302 from a form POST straight to
     * a gateway host is governed by `form-action`, and a policy without the gateways blocks
     * the submission in the browser before any PHP runs.
     */
    public function handoff(Request $req, Response $res): Response
    {
        $reference = \AfricaGates\Services\GatewayHandoff::reference($req);
        $url = \AfricaGates\Services\GatewayHandoff::take($reference);
        if ($url === null) {
            return $this->redirect($res, $this->base($req) . '/donate?give=start');
        }
        return \AfricaGates\Services\GatewayHandoff::page(
            $res, $url, \AfricaGates\Services\GatewayHandoff::providerLabel(), $reference
        );
    }
}
