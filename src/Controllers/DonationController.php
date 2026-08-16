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
        $slug = trim((string) ($args['slug'] ?? ''));
        $org  = null;

        if ($slug !== '') {
            $org = \AfricaGates\Services\PartnerOrg::bySlug($slug);
            if (!\AfricaGates\Services\PartnerOrg::canReceive($org)) {
                return $this->view->render($res->withStatus(404), 'pages/donate.twig', [
                    'error'      => 'That appeal is not open for donations.',
                    'page_title' => 'Appeal closed — Africa GATES',
                    'gates_page' => 'donate', 'has_hero' => false,
                    'providers'  => [], 'stats' => $this->stats(), 'givers' => [],
                    'org' => null, 'org_closed' => true,
                    'min_naira' => self::MIN_NAIRA, 'max_naira' => self::MAX_NAIRA,
                    'processing_fee_pct' => 1.5,
                ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
            }
        }

        return $this->view->render($res, 'pages/donate.twig', [
            'error'            => self::GIVE_REASONS[trim((string) ($req->getQueryParams()['give'] ?? ''))] ?? null,
            'page_title'       => $org ? ('Donate to ' . $org->name . ' — Africa GATES') : 'Donate — Africa GATES',
            'meta_description' => $org
                ? ('Give to ' . $org->name . ' through Africa GATES. Your donation settles directly to the organisation.')
                : 'Fund child leadership programmes across the continent — mentorship, scholarships and grassroots education. Every gift is receipted and independently audited.',
            'gates_page'       => 'donate',
            'has_hero'         => false,
            'providers'        => $this->payments->enabledProviders(),
            'stats'            => $org ? $this->orgStats((int) $org->id) : $this->stats(),
            'givers'           => $org ? [] : $this->recentGivers(),
            'org'              => $org,
            // The platform's share, in the only unit a donor can act on. A percentage in a
            // footnote is not a disclosure; the template shows this in naira beside the
            // amount, because a fee discovered after payment is the fastest way to lose a
            // donor permanently.
            'org_fee_bps'      => $org ? (int) ($org->platform_fee_bps ?? 0) : 0,
            'partners'         => $org ? [] : \AfricaGates\Services\PartnerOrg::listReceivable(),
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

    /** Recent confirmed gifts, lightly anonymised (first name + last initial). */
    private function recentGivers(): array
    {
        try {
            $rows = DB::table('gates_donations')->where('status', 'confirmed')
                ->orderByDesc('id')->limit(5)->get(['donor_name', 'amount_naira', 'created_at']);
        } catch (\Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name'   => $this->anon((string)($r->donor_name ?? '')),
                'amount' => (int)$r->amount_naira,
                'when'   => (string)($r->created_at ?? ''),
            ];
        }
        return $out;
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
        $org = null;
        if ($orgSlug !== '') {
            $org = \AfricaGates\Services\PartnerOrg::bySlug($orgSlug);
            if (!\AfricaGates\Services\PartnerOrg::canReceive($org)) {
                return $this->redirect($res, $this->base($req) . '/donate?give=closed');
            }
        }

        $back = $org ? '/donate/' . rawurlencode($orgSlug) : '/donate';
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
        }

        try {
            DB::table('gates_donations')->insert($row);
        } catch (\Throwable $e) {
            $this->log?->error('[donate] could not persist pending donation', ['err' => $e->getMessage()]);
            return $bail('error');
        }

        $callbackUrl = $this->base($req) . '/donate/callback?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);
        $init = $this->payments->initialize($provider, $amount, $email, $reference, $callbackUrl, [
            'reference' => $reference, 'purpose' => 'donation',
        ]);
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
