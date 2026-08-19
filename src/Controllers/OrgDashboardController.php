<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\{OrgAuth, OrgCampaign, OrgPayout, PartnerOrg, PaymentService,
                          RateLimitService, StandApplication};
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
        private readonly ?UploadService     $uploads   = null,
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

        // Named rather than inlined, because the rail counts them and the counts are read
        // in the same array literal that builds them — an inline expression cannot be.
        $required     = PartnerOrg::requiredDocuments($orgId);
        $missing      = StandApplication::missingDocuments($orgId);
        $applications = $this->applicationRows($orgId);
        $campaigns    = $this->campaignRows($orgId);
        $payouts      = OrgPayout::history($orgId, 20);

        return $this->view->render($res, 'pages/org/dashboard.twig', [
            'page_title'  => $org->name . ' — partner dashboard',
            'gates_page'  => 'partner',
            'has_hero'    => false,
            'lite_page'   => true,
            'org'         => $org,
            'me'          => $user,
            'totals'      => $totals,
            'available'   => OrgPayout::available($orgId),
            'payouts'     => $payouts,
            'donations'   => $this->recentDonations($orgId),
            'payout_mode' => OrgPayout::mode(),
            'can_payout'  => OrgAuth::canRequestPayout($user),
            'min_payout'  => OrgPayout::MIN_NAIRA,
            'campaigns'   => $campaigns,
            'shortfall'   => OrgCampaign::SHORTFALL,
            // ── THE VENDOR HALF ──────────────────────────────────────────
            //
            // Shown to everybody rather than gated on `kind`, because the arrays are empty
            // for a donation partner and an empty section renders as nothing. Branching the
            // template on kind would mean a partner who later takes a stand sees a dashboard
            // that has quietly decided what they are.
            'is_vendor'   => PartnerOrg::kindOf($org) === PartnerOrg::KIND_VENDOR,
            'individual'  => PartnerOrg::isIndividual($org),
            'applications'=> $applications,
            'documents'   => $this->documentRows($orgId),
            'required'    => $required,
            'missing'     => $missing,
            'doc_kinds'   => PartnerOrg::DOCUMENT_KINDS,
            'uploads_on'  => $this->uploads !== null,
            'decisions'   => StandApplication::DECISIONS,
            'flash_ok'    => $_SESSION['org_flash_ok']    ?? null,
            'flash_error' => $_SESSION['org_flash_error'] ?? null,

            // ── WHAT THE SECTIONED LAYOUT NEEDS ──────────────────────────────
            //
            // Counts on the rail, because a section label with no number beside it is a door
            // people open to find out whether it was worth opening. The one that needs doing
            // says so as a WORD — see the template; a bare "3" on Documents reads as three
            // filed, which is the opposite of the truth.
            'counts' => [
                'documents'    => count($required),
                'to_do'        => count($missing),
                'applications' => count($applications),
                'offers'       => count(array_filter($applications, static fn ($a) => !empty($a['live_offer']))),
                'donations'    => (int) ($totals['count'] ?? 0),
                'appeals'      => count($campaigns),
                'payouts'      => count($payouts),
            ],
            'money_chart' => \AfricaGates\Support\Viz::area(
                'viz-money', 'Received, last 90 days', $this->donationSeries($orgId),
                [
                    'unit' => '₦',
                    'sub'  => 'Your share, after the platform fee — the figure that reaches your account.',
                    'empty'=> 'The line starts with your first confirmed donation.',
                ]
            ),
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * This organisation's stand applications, each with its outcome.
     *
     * The DECISION and its REASON are shown to the applicant, always, including a rejection.
     * §5.7 of the vendor specification gives every applicant an outcome they can understand,
     * and the difference between a disappointment and a story about favouritism is whether
     * anybody ever told them which rule they fell on.
     *
     * @return array<int,array<string,mixed>>
     */
    private function applicationRows(int $orgId): array
    {
        $apps = StandApplication::forOrg($orgId);
        if ($apps === []) return [];

        try {
            $types  = DB::table('gates_stand_types')
                ->whereIn('id', array_map(static fn($a) => (int) $a->stand_type_id, $apps))
                ->get()->keyBy('id');
            $events = DB::table('gates_site_events')
                ->whereIn('id', array_map(static fn($a) => (int) $a->event_id, $apps))
                ->get()->keyBy('id');
        } catch (\Throwable) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $out = [];
        foreach ($apps as $a) {
            $expires = trim((string) ($a->offer_expires_at ?? ''));
            $out[] = [
                'app'   => $a,
                'type'  => $types[(int) $a->stand_type_id] ?? null,
                'event' => $events[(int) $a->event_id] ?? null,
                // An offer that has run out is shown as run out rather than as an accept
                // button that will be refused. The sweep may not have reached it yet.
                'live_offer' => (string) $a->decision === StandApplication::DECISION_OFFERED
                                && ($expires === '' || $expires > $now),
                'expired'    => (string) $a->decision === StandApplication::DECISION_OFFERED
                                && $expires !== '' && $expires <= $now,
            ];
        }
        return $out;
    }

    /** @return array<int,object> */
    private function documentRows(int $orgId): array
    {
        try {
            return DB::table('gates_org_documents')->where('org_id', $orgId)
                ->orderByDesc('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ─────────────────────────── stands and documents ───────────────────────

    /**
     * Accept an offered stand.
     *
     * The window, the ownership check and the expiry all live in the service, so this cannot
     * be the place any of them is forgotten.
     */
    public function acceptStand(Request $req, Response $res, array $args = []): Response
    {
        $user = $this->requireUser();
        if (!$user) return $this->redirect($res, '/org/login');
        if (!OrgAuth::canRequestPayout($user)) {
            $_SESSION['org_flash_error'] = 'Only an account owner can accept a stand — accepting '
                                         . 'commits the organisation to the stand fee.';
            return $this->redirect($res, '/org');
        }

        $r = StandApplication::accept((int) ($args['id'] ?? 0), (int) $user->org_id);
        $_SESSION[$r['ok'] ? 'org_flash_ok' : 'org_flash_error'] = $r['message'];
        return $this->redirect($res, '/org');
    }

    /**
     * Upload a certificate.
     *
     * ── WHY A VENDOR UPLOADS THEIR OWN ───────────────────────────────────────
     *
     * The alternative is that certificates arrive by email and an administrator files them,
     * which puts a person between a vendor and the requirement they are being judged against.
     * It also makes the eligibility check dishonest: an application marked incomplete may
     * simply be one whose insurance certificate is sitting unread in an inbox.
     *
     * Completeness is refreshed straight afterwards, because the document that just landed
     * may be the last one — and the tiebreak in §5.4 is the moment an application became
     * complete, so a delay here costs the vendor queue position they earned.
     */
    public function uploadDocument(Request $req, Response $res): Response
    {
        $user = $this->requireUser();
        if (!$user) return $this->redirect($res, '/org/login');
        if (!OrgAuth::canRequestPayout($user)) {
            $_SESSION['org_flash_error'] = 'Only an account owner can upload documents.';
            return $this->redirect($res, '/org');
        }
        if (!$this->uploads) {
            $_SESSION['org_flash_error'] = 'Uploads are not available on this server. '
                                         . 'Email your certificates and we will file them.';
            return $this->redirect($res, '/org');
        }

        $orgId = (int) $user->org_id;
        $b     = (array) $req->getParsedBody();
        $kind  = (string) ($b['kind'] ?? 'other');
        if (!isset(PartnerOrg::DOCUMENT_KINDS[$kind])) $kind = 'other';

        $file = $req->getUploadedFiles()['document'] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            $_SESSION['org_flash_error'] = 'Choose a file to upload.';
            return $this->redirect($res, '/org');
        }

        try {
            // 'public' as the uploader type, truthfully: this file arrived from outside the
            // administration, and the pipeline verifies the BYTES rather than the client's
            // claim about them.
            $r = $this->uploads->uploadDocument($file, 'org-docs', 15, (int) $user->id, 'public',
                                                'partner_org', $orgId);
        } catch (\Throwable $e) {
            $_SESSION['org_flash_error'] = 'Could not upload — ' . $e->getMessage();
            return $this->redirect($res, '/org');
        }

        $expires = trim((string) ($b['expires_on'] ?? ''));
        DB::table('gates_org_documents')->insert([
            'org_id'        => $orgId,
            'kind'          => $kind,
            'original_name' => mb_substr((string) $file->getClientFilename(), 0, 250),
            'stored_path'   => (string) $r['path'],
            'mime'          => (string) ($r['mime'] ?? ''),
            'size_bytes'    => (int) ($r['size'] ?? 0),
            // Nullable on purpose: a CAC certificate does not expire and an insurance policy
            // very much does. An expiry nobody gave must not read as "expired".
            'expires_on'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) ? $expires : null,
            'uploaded_by'   => (int) $user->id,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        foreach (StandApplication::forOrg($orgId) as $a) {
            StandApplication::refreshCompleteness((int) $a->id);
        }

        $missing = StandApplication::missingDocuments($orgId);
        $_SESSION['org_flash_ok'] = $missing === []
            ? 'Uploaded. Everything we ask for is now on file.'
            : 'Uploaded. Still outstanding: ' . implode(', ', $missing) . '.';
        return $this->redirect($res, '/org');
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
    /**
     * Money received, day by day, as a running total — for the chart on the dashboard.
     *
     * ── WHY CUMULATIVE AND NOT PER-DAY BARS ──────────────────────────────────
     *
     * A fundraiser's question is "how close am I", not "what happened on Tuesday". A bar per
     * day answers the second and makes the first something the reader has to do in their
     * head; a rising line answers the first and still shows the busy days as the steep bits.
     *
     * Empty days hold the previous total, for the same reason the points chart does: a total
     * is a running figure, and plotting only the days with a donation draws a steady climb
     * where there was one gift and then three weeks of nothing.
     *
     * @return list<array{date: string, balance: int, delta: int}> the shape Viz::area reads
     */
    private function donationSeries(int $orgId, int $days = 90): array
    {
        $days = max(2, min(365, $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        try {
            $rows = DB::table('gates_donations')
                ->where('recipient_org_id', $orgId)
                ->where('status', 'confirmed')
                ->where('created_at', '>=', $from . ' 00:00:00')
                ->orderBy('id')
                ->get(['amount_naira', 'platform_fee_naira', 'created_at']);
            // Everything before the window, so the line starts where the organisation
            // actually stood rather than at zero — a partner two years in is not somebody
            // who joined ninety days ago.
            $before = (int) DB::table('gates_donations')
                ->where('recipient_org_id', $orgId)
                ->where('status', 'confirmed')
                ->where('created_at', '<', $from . ' 00:00:00')
                ->sum(DB::raw('amount_naira - COALESCE(platform_fee_naira, 0)'));
        } catch (\Throwable) {
            return [];
        }

        if (count($rows) === 0 && $before === 0) return [];

        $moved = [];
        foreach ($rows as $r) {
            $net = max(0, (int) ($r->amount_naira ?? 0) - (int) ($r->platform_fee_naira ?? 0));
            $d   = substr((string) $r->created_at, 0, 10);
            $moved[$d] = ($moved[$d] ?? 0) + $net;
        }

        $out  = [];
        $held = $before;
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
            $held += (int) ($moved[$d] ?? 0);
            $out[] = ['date' => $d, 'balance' => $held, 'delta' => (int) ($moved[$d] ?? 0)];
        }
        return $out;
    }

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
