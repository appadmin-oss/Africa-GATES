<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{OrgAuth, OrgCampaign, OrgPayout, PartnerOrg, PaymentService, RateLimitService};
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * What a partner organisation can see and do about its own money.
 *
 * ── EVERY READ IS SCOPED BY THE SESSION, NOT BY THE REQUEST ──────────────────
 *
 * There is no organisation id in any path or form on these screens. The organisation is
 * whichever one the signed-in user belongs to, resolved fresh from the database on every
 * request. An id in a URL is an invitation to change it, and that is the standard way a
 * multi-tenant dashboard shows one charity another charity's donors.
 *
 * ── AND IT SHOWS WHAT IS TRUE, INCLUDING WHEN THAT IS AWKWARD ────────────────
 *
 * Confirmed donations only — a dashboard that counts pending money is a dashboard that
 * causes an argument. Donor identities are shown only where the donor agreed to be named.
 * And in settlement mode the withdraw screen says plainly that the money settles to the
 * organisation's own account on the gateway's schedule, rather than implying this platform
 * is holding it and choosing when to let go.
 */
final class OrgDashboardController
{
    public function __construct(
        private readonly Twig               $view,
        private readonly PaymentService     $payments,
        private readonly ?RateLimitService  $rateLimit = null,
    ) {}

    private function redirect(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    /** Every authenticated screen starts here. Returns null when the caller must be bounced. */
    private function requireUser(): ?object
    {
        return OrgAuth::user();
    }

    // ──────────────────────────────── sign in ───────────────────────────────

    public function loginPage(Request $req, Response $res): Response
    {
        if (OrgAuth::user()) return $this->redirect($res, '/org');

        return $this->view->render($res, 'pages/org/login.twig', [
            'page_title' => 'Partner sign in — Africa GATES',
            'gates_page' => 'partner',
            'has_hero'   => false,
            'lite_page'  => true,
            'error'      => trim((string) ($req->getQueryParams()['e'] ?? '')) !== ''
                            ? 'Those details did not match. Check the address and password and try again.'
                            : null,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function login(Request $req, Response $res): Response
    {
        $b  = (array) $req->getParsedBody();
        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');

        $user = (new OrgAuth($this->rateLimit))->attempt(
            (string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''), $ip
        );

        // One message for every failure — unknown address, wrong password, locked, suspended
        // organisation. Telling them apart is an account-enumeration oracle.
        if (!$user) return $this->redirect($res, '/org/login?e=1');

        (new OrgAuth($this->rateLimit))->signIn($user);
        return $this->redirect($res, '/org');
    }

    public function logout(Request $req, Response $res): Response
    {
        (new OrgAuth())->signOut();
        return $this->redirect($res, '/org/login');
    }

    // ─────────────────────────────── dashboard ──────────────────────────────

    public function dashboard(Request $req, Response $res): Response
    {
        $user = $this->requireUser();
        if (!$user) return $this->redirect($res, '/org/login');

        $orgId = (int) $user->org_id;
        $org   = PartnerOrg::find($orgId);
        if (!$org) return $this->redirect($res, '/org/login');

        $totals = PartnerOrg::totals($orgId);

        return $this->view->render($res, 'pages/org/dashboard.twig', [
            'page_title'  => $org->name . ' — partner dashboard',
            'gates_page'  => 'partner',
            'has_hero'    => false,
            'lite_page'   => true,
            'org'         => $org,
            'me'          => $user,
            'totals'      => $totals,
            'available'   => OrgPayout::available($orgId),
            'payouts'     => OrgPayout::history($orgId, 20),
            'donations'   => $this->recentDonations($orgId),
            'payout_mode' => OrgPayout::mode(),
            'can_payout'  => OrgAuth::canRequestPayout($user),
            'min_payout'  => OrgPayout::MIN_NAIRA,
            'campaigns'   => $this->campaignRows($orgId),
            'shortfall'   => OrgCampaign::SHORTFALL,
            'flash_ok'    => $_SESSION['org_flash_ok']    ?? null,
            'flash_error' => $_SESSION['org_flash_error'] ?? null,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The organisation's appeals, each with what it has actually raised.
     *
     * @return array<int,array<string,mixed>>
     */
    private function campaignRows(int $orgId): array
    {
        $out = [];
        foreach (OrgCampaign::allFor($orgId) as $c) {
            $out[] = [
                'row'      => $c,
                'progress' => OrgCampaign::progress((int) $c->id),
                'open'     => OrgCampaign::isOpen($c),
                'days'     => OrgCampaign::daysLeft($c),
            ];
        }
        return $out;
    }

    // ─────────────────────────────── appeals ────────────────────────────────

    /**
     * Create or edit an appeal.
     *
     * An owner writes it; Africa GATES publishes it. A viewer cannot touch it — same gate
     * as payouts, because an appeal is a public claim about what the organisation will do
     * with money and that is not a read-only act.
     */
    public function saveCampaign(Request $req, Response $res, array $args = []): Response
    {
        $user = $this->requireUser();
        if (!$user) return $this->redirect($res, '/org/login');
        if (!OrgAuth::canRequestPayout($user)) {
            $_SESSION['org_flash_error'] = 'Only an account owner can create or edit an appeal.';
            return $this->redirect($res, '/org');
        }

        $r = OrgCampaign::save(
            (int) $user->org_id,
            (array) $req->getParsedBody(),
            (int) ($args['id'] ?? 0)
        );
        $_SESSION[$r['ok'] ? 'org_flash_ok' : 'org_flash_error'] = $r['message'];
        return $this->redirect($res, '/org');
    }

    /** Ask for it to be reviewed and published. */
    public function submitCampaign(Request $req, Response $res, array $args = []): Response
    {
        $user = $this->requireUser();
        if (!$user) return $this->redirect($res, '/org/login');
        if (!OrgAuth::canRequestPayout($user)) {
            $_SESSION['org_flash_error'] = 'Only an account owner can send an appeal for review.';
            return $this->redirect($res, '/org');
        }

        $r = OrgCampaign::submit((int) $user->org_id, (int) ($args['id'] ?? 0));
        $_SESSION[$r['ok'] ? 'org_flash_ok' : 'org_flash_error'] = $r['message'];
        return $this->redirect($res, '/org');
    }

    /**
     * Close an appeal early.
     *
     * An organisation may always STOP collecting for something — that needs no permission
     * from us, and a charity that has met its need and cannot switch off the button is a
     * charity taking money it did not ask for.
     */
    public function closeCampaign(Request $req, Response $res, array $args = []): Response
    {
        $user = $this->requireUser();
        if (!$user) return $this->redirect($res, '/org/login');
        if (!OrgAuth::canRequestPayout($user)) {
            $_SESSION['org_flash_error'] = 'Only an account owner can close an appeal.';
            return $this->redirect($res, '/org');
        }

        $id = (int) ($args['id'] ?? 0);
        $c  = OrgCampaign::find($id);
        // Scoped to the signed-in organisation, like everything else on these screens.
        if (!$c || (int) $c->org_id !== (int) $user->org_id) {
            $_SESSION['org_flash_error'] = 'That appeal does not belong to your organisation.';
            return $this->redirect($res, '/org');
        }

        $r = OrgCampaign::close($id);
        $_SESSION[$r['ok'] ? 'org_flash_ok' : 'org_flash_error'] = $r['message'];
        return $this->redirect($res, '/org');
    }

    /**
     * The organisation's own confirmed gifts.
     *
     * A donor's name appears only where they ticked the box that publishes it. Passing
     * donor identities to a third party is a disclosure under the Nigeria Data Protection
     * Act 2023 and needs consent that was actually given — `show_name` is the only consent
     * on this row, so it is the only thing that unlocks a name.
     *
     * Email addresses are never shown. There is no consent on file for handing a donor's
     * contact details to a partner, and a partner that wants to thank somebody can ask us.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recentDonations(int $orgId, int $limit = 25): array
    {
        try {
            $rows = DB::table('gates_donations')
                ->where('recipient_org_id', $orgId)
                ->where('status', 'confirmed')
                ->orderByDesc('id')->limit($limit)
                ->get(['donor_name', 'amount_naira', 'platform_fee_naira', 'show_name', 'created_at']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $gross = (int) ($r->amount_naira ?? 0);
            $fee   = (int) ($r->platform_fee_naira ?? 0);
            $out[] = [
                'name'   => ((int) ($r->show_name ?? 0) === 1)
                                ? (string) ($r->donor_name ?? 'A supporter')
                                : 'Anonymous',
                'gross'  => $gross,
                'fee'    => $fee,
                'net'    => max(0, $gross - $fee),
                'when'   => (string) ($r->created_at ?? ''),
            ];
        }
        return $out;
    }

    // ──────────────────────────────── payouts ───────────────────────────────

    public function requestPayout(Request $req, Response $res): Response
    {
        $user = $this->requireUser();
        if (!$user) return $this->redirect($res, '/org/login');

        // A viewer can read every figure on the dashboard and move nothing. Checked here
        // rather than only hidden in the template, because a hidden form is not a control.
        if (!OrgAuth::canRequestPayout($user)) {
            $_SESSION['org_flash_error'] = 'Only an account owner can request a payout.';
            return $this->redirect($res, '/org');
        }

        $b      = (array) $req->getParsedBody();
        $amount = (int) preg_replace('/[^0-9]/', '', (string) ($b['amount'] ?? '0'));

        $r = OrgPayout::request($this->payments, (int) $user->org_id, $amount, (int) $user->id);

        if ($r['ok']) $_SESSION['org_flash_ok']    = $r['message'];
        else          $_SESSION['org_flash_error'] = $r['message'];

        return $this->redirect($res, '/org');
    }
}
