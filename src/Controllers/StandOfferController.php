<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{GatewayHandoff, PartnerOrg, PaymentService, RateLimitService,
                          StandApplication, StandFee, StandType};
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The vendor's own page for one offer: what it is, accepting it, and paying for it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A TOKEN LINK AND NOT A DASHBOARD SECTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It is BOTH — the dashboard still holds every application — but the offer needs a route
 * with no sign-in in front of it, and the reason is the clock. An offer expires in
 * {@see StandApplication::OFFER_HOURS} hours. The person receiving it is a market trader
 * who applied from a phone six weeks ago and does not remember the password they set at the
 * time, and a password-reset round trip inside a two-day window is how a pitch gets released
 * to a waiting list because somebody could not get in.
 *
 * Fourth surface on this platform to run on a token alone, after the questionnaire, the
 * interview and the claim link, and for the same reason each time: the population it serves
 * has no account and requiring one would exclude exactly the people it exists for.
 *
 * ── THE TOKEN IS THE CREDENTIAL, SO IT BEHAVES LIKE ONE ─────────────────────
 *
 * 48 hex characters from `random_bytes`, UNIQUE in the table, and it addresses exactly one
 * application. Nothing on this page reveals anything about any other applicant — not the
 * queue position, not who else applied, not how many places are left in a way that could be
 * differenced against another load.
 *
 * ── AND ACCEPTING IS NEVER A GET ────────────────────────────────────────────
 *
 * The link opens a page that SHOWS the offer. Accepting and paying are posts from it. A
 * one-click accept URL in an email would be a state change from a GET, and a corporate mail
 * filter prefetching links — which they do, all day, on every message — would accept a
 * pitch on somebody's behalf.
 */
final class StandOfferController
{
    public function __construct(
        private readonly Twig              $view,
        private readonly PaymentService    $payments,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    private function base(Request $req): string
    {
        $u = $req->getUri();
        return $u->getScheme() . '://' . $u->getAuthority();
    }

    private function redirect(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE PAGE
    // ═══════════════════════════════════════════════════════════════════════

    public function page(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $app   = StandFee::byToken($token);

        if (!$app) {
            // A dead link is a real state and gets a page, not a 404 with no explanation:
            // the commonest cause is an offer that expired and was released, and somebody
            // holding a link to it is owed that sentence rather than a blank wall.
            return $this->view->render($res->withStatus(404), 'pages/stands/offer.twig', [
                'page_title' => 'That link is not working — Africa GATES',
                'gates_page' => 'stands', 'has_hero' => false, 'lite_page' => true,
                'app' => null, 'dead' => true,
            ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        return $this->render($res, $app);
    }

    private function render(Response $res, object $app, string $error = ''): Response
    {
        $event = DB::table('gates_site_events')->where('id', $app->event_id)->first();
        $org   = PartnerOrg::find((int) $app->org_id);
        $type  = StandType::find((int) $app->stand_type_id);

        $expires  = trim((string) ($app->offer_expires_at ?? ''));
        $expired  = $expires !== '' && $expires < date('Y-m-d H:i:s');
        $decision = (string) $app->decision;

        return $this->view->render($res, 'pages/stands/offer.twig', [
            'page_title'  => 'Your stand at ' . (string) ($event->title ?? 'the event'),
            'gates_page'  => 'stands',
            'has_hero'    => false,
            'lite_page'   => true,
            'dead'        => false,
            'app'         => $app,
            'event'       => $event,
            'org'         => $org,
            'type'        => $type,
            'owing'       => StandFee::owing($app),
            'terms_slug'  => StandFee::TERMS_SLUG,
            'agreed_at'   => trim((string) ($app->terms_agreed_at ?? '')),
            'decision'    => $decision,
            'is_offer'    => $decision === StandApplication::DECISION_OFFERED,
            'is_accepted' => $decision === StandApplication::DECISION_ACCEPTED,
            'expired'     => $expired,
            'expires_at'  => $expires,
            // Whether a Pay button can do anything at all. A button that opens a checkout
            // which cannot start is worse than no button — the vendor concludes their card
            // was refused.
            'can_pay'     => $this->payments->enabledProviderIds() !== [],
            'error'       => $error,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ACCEPTING
    // ═══════════════════════════════════════════════════════════════════════

    public function accept(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $app   = StandFee::byToken($token);
        if (!$app) return $this->redirect($res, '/');

        // ── THE TERMS ARE AGREED TO HERE, NOT ASSUMED ───────────────────────
        //
        // A trader is about to be charged for a pitch, and the questions that come up at a
        // market — who insures the stall, what happens if the event is cancelled, what the
        // pitch may be used for, when the fee is refundable — were answered nowhere before
        // this. An organiser enforcing a rule the trader never saw is the same failure as a
        // rejection with no reason.
        //
        // A REAL CHECKBOX, not a "by continuing you agree" line: the second is a sentence
        // people scroll past, and this one has money and a cancellation policy behind it.
        $body = (array) $req->getParsedBody();
        if (empty($body['agree_terms'])) {
            $_SESSION['flash_error'] = 'Tick the box to say you have read the trading terms. '
                                     . 'They cover insurance, what happens if the event is '
                                     . 'cancelled, and when the fee can be refunded — so it is '
                                     . 'worth the two minutes.';
            return $this->redirect($res, '/stand/' . $token . '#terms');
        }

        $r = StandApplication::accept((int) $app->id, (int) $app->org_id);

        // Recorded only on a successful acceptance. Stamping agreement on a refused accept
        // would put a signature against a pitch nobody holds.
        if ($r['ok']) StandFee::agreeToTerms((int) $app->id);

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = (string) $r['message'];
        return $this->redirect($res, '/stand/' . $token);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PAYING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Start a checkout for whatever is owed right now.
     *
     * The amount is computed HERE from the stamped fee and what has already been credited —
     * never taken from the form. A posted amount would let anybody pay ₦1 for a ₦35,000
     * pitch and have the row say paid.
     */
    public function pay(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $app   = StandFee::byToken($token);
        if (!$app) return $this->redirect($res, '/');

        $back = '/stand/' . $token;

        if ((string) $app->decision !== StandApplication::DECISION_ACCEPTED) {
            $_SESSION['flash_error'] = 'Accept the offer first — then you can pay for it.';
            return $this->redirect($res, $back);
        }

        $owing = StandFee::owing($app);
        if ($owing['settled'] || $owing['due'] < 1) {
            $_SESSION['flash_ok'] = 'There is nothing left to pay on this stand.';
            return $this->redirect($res, $back);
        }

        // Throttled per application rather than per address: starting a checkout costs a
        // gateway call, and the only party with a reason to hammer this one is whoever
        // holds the link.
        if ($this->rateLimit
            && !$this->rateLimit->check('stand-pay-' . (int) $app->id, 'stand_pay', 12, 3600)) {
            $_SESSION['flash_error'] = 'That has been tried several times in the last hour. '
                                     . 'Wait a few minutes, or write to us and we will take '
                                     . 'the payment another way.';
            return $this->redirect($res, $back);
        }

        $providers = $this->payments->enabledProviderIds();
        if ($providers === []) {
            $_SESSION['flash_error'] = 'Card payment is not available on this site just now. '
                                     . 'Your place is still held — write to us and we will '
                                     . 'arrange the fee.';
            return $this->redirect($res, $back);
        }
        $provider = in_array('paystack', $providers, true) ? 'paystack' : $providers[0];

        $org   = PartnerOrg::find((int) $app->org_id);
        $email = trim((string) ($org->contact_email ?? ''));
        if ($email === '') {
            $_SESSION['flash_error'] = 'We do not have an email address on this account, and '
                                     . 'the gateway needs one to send the receipt to. Add one '
                                     . 'on your dashboard first.';
            return $this->redirect($res, $back);
        }

        $reference = StandFee::reference((int) $app->id);
        // Written BEFORE the hand-off, so a callback for a reference we never issued is one
        // we can refuse rather than guess at.
        StandFee::beginPayment((int) $app->id, $reference, $provider);

        $callback = $this->base($req) . '/stand/' . $token . '/callback'
                  . '?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);

        $init = $this->payments->initialize(
            $provider, (int) $owing['due'], $email, $reference, $callback,
            ['reference' => $reference, 'purpose' => 'stand',
             'application' => (int) $app->id, 'event' => (int) $app->event_id]
        );

        if (!($init['ok'] ?? false) || empty($init['checkout_url'])) {
            $_SESSION['flash_error'] = 'We could not start the payment. Nothing has been '
                                     . 'charged, and your place is still held — try again in '
                                     . 'a moment.';
            return $this->redirect($res, $back);
        }

        // Through GatewayHandoff rather than a bare redirect, for the reason the shop and
        // the events path both do it: the CSP `form-action` governs the POST to the gateway,
        // and a direct cross-origin hand-off is blocked in the browser before it happens.
        return $this->redirect($res, GatewayHandoff::remember(
            $reference, (string) $init['checkout_url'],
            $this->base($req) . '/stand/' . $token . '/redirect', $provider
        ));
    }

    /** The same-origin hop to the gateway. Mirrors /shop/redirect. */
    public function handoff(Request $req, Response $res, array $args = []): Response
    {
        $token     = (string) ($args['token'] ?? '');
        $reference = GatewayHandoff::reference($req);
        $url       = GatewayHandoff::take($reference);

        if ($url === null) {
            $_SESSION['flash_error'] = 'That payment link had expired. Nothing was charged — '
                                     . 'press Pay again.';
            return $this->redirect($res, '/stand/' . $token);
        }

        return GatewayHandoff::page($res, $url, GatewayHandoff::providerLabel(), $reference);
    }

    /**
     * The browser coming back from the gateway.
     *
     * ── WHAT IS TRUSTED HERE, AND WHAT IS NOT ───────────────────────────────
     *
     * The reference, and nothing else. The amount is read from the gateway's own
     * verification, never from the query string: a browser returning from a checkout is a
     * party to the transaction, not a witness to it, and a callback that credited whatever
     * `?amount=` said would mark a ₦35,000 pitch paid for whatever somebody typed.
     */
    public function callback(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $q     = $req->getQueryParams();
        $ref   = trim((string) ($q['ref'] ?? $q['reference'] ?? $q['trxref'] ?? ''));
        $prov  = trim((string) ($q['provider'] ?? ''));
        $back  = '/stand/' . $token;

        if ($ref === '') {
            $_SESSION['flash_error'] = 'We did not get a payment reference back. If you were '
                                     . 'charged, nothing is lost — write to us with the '
                                     . 'reference on your bank alert and we will apply it.';
            return $this->redirect($res, $back);
        }

        $v = $this->payments->verify($prov !== '' ? $prov : 'paystack', $ref);

        if (!($v['ok'] ?? false) || (string) ($v['status'] ?? '') !== 'success') {
            $_SESSION['flash_error'] = 'That payment did not go through, so nothing has been '
                                     . 'charged. Your place is still held — you can try again.';
            return $this->redirect($res, $back);
        }

        // `amount`, in whole naira — the key PaymentService::verify() actually returns.
        // It converts from the gateway's kobo itself.
        $paid = (int) ($v['amount'] ?? 0);
        $r    = StandFee::confirm($ref, $paid, $prov);

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? 'Payment received — ₦' . number_format($paid) . '. Your stand is confirmed.'
            : (string) $r['message'];

        return $this->redirect($res, $back);
    }
}
