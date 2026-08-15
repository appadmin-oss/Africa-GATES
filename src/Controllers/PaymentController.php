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
use AfricaGates\Services\{PaymentService, PaymentDestination, RateLimitService};
// Aliased because `webhook()` and `handleWebhook()` mention it on almost every branch, and
// the fully-qualified name at each one buried the routing decision it sits beside.
use AfricaGates\Services\GatewayEventLog as Log;

/**
 * Checkout orchestration for the partner page (vote packs, event tickets, child
 * donations). Three endpoints:
 *
 *   POST /pay/init     first-party, CSRF-protected. Resolves a SERVER-SIDE price,
 *                      writes a PENDING gates_donations row, asks the gateway for
 *                      a hosted-checkout URL and redirects (or returns JSON).
 *   GET  /pay/callback browser return from the gateway. Verifies server-to-server
 *                      and confirms the donation iff status + amount match.
 *   POST /pay/webhook  server-to-server gateway notification, signature-verified,
 *                      CSRF-exempt. Confirms idempotently.
 *
 * SECURITY MODEL (every point is load-bearing):
 *   1. Amount + bonus votes come ONLY from {@see self::PRICES}. The client picks a
 *      purpose+tier; it can never send an amount. A tampered request that names an
 *      unknown tier is rejected outright.
 *   2. Confirmation requires {@see PaymentService::verify} returning 'success' AND
 *      the verified naira amount EQUALLING the pending row's amount_naira. A forged
 *      callback (right reference, wrong/absent payment) cannot confirm.
 *   3. Confirmation is IDEMPOTENT: the pending→confirmed transition is a single
 *      conditional UPDATE guarded by status='pending'. Whichever of {callback,
 *      webhook, a double-click} wins flips the row once; the rest see 0 rows
 *      affected and credit nothing. One reference confirms exactly once.
 *   4. Webhooks are authenticated by provider signature (Paystack HMAC-SHA512 over
 *      the raw body; Flutterwave shared verif-hash) BEFORE any DB work.
 *   5. Secret keys never reach the browser (they live in PaymentService/$_ENV).
 */
final class PaymentController
{
    /**
     * THE price book. Keyed by purpose → tier → [amount in naira, bonus votes].
     * This is the single source of truth for what a purchase costs and grants;
     * it mirrors templates/pages/partner.twig. `votes` is the bonus_votes credited
     * to the donation (redeemable later via BonusVoteService). Tickets carry their
     * bundled voting credits; sponsorship/donation tiers grant 0 (recognition, not
     * votes) unless a bundle is defined.
     *
     * @var array<string,array<string,array{amount:int,votes:int,label:string}>>
     */
    private const PRICES = [
        // Category A — online voting packs (₦ → bonus votes), per partner.twig.
        'vote' => [
            'supporter'  => ['amount' => 1000,  'votes' => 5,   'label' => 'Supporter — 5 votes'],
            'advocate'   => ['amount' => 2000,  'votes' => 12,  'label' => 'Advocate — 12 votes'],
            'champion'   => ['amount' => 5000,  'votes' => 35,  'label' => 'Champion — 35 votes'],
            'ambassador' => ['amount' => 10000, 'votes' => 80,  'label' => 'Ambassador — 80 votes'],
            'guardian'   => ['amount' => 20000, 'votes' => 180, 'label' => 'Guardian — 180 votes'],
            'vanguard'   => ['amount' => 50000, 'votes' => 500, 'label' => 'Vanguard — 500 votes'],
        ],
        // Category B — event tickets (admission + bundled voting credits).
        'ticket' => [
            'regular'   => ['amount' => 3000,  'votes' => 10,  'label' => 'Regular ticket — 10 votes'],
            'supporter' => ['amount' => 5000,  'votes' => 25,  'label' => 'Supporter ticket — 25 votes'],
            'vip'       => ['amount' => 10000, 'votes' => 50,  'label' => 'VIP ticket — 50 votes'],
            'gold'      => ['amount' => 20000, 'votes' => 120, 'label' => 'Gold VIP ticket — 120 votes'],
            'patron'    => ['amount' => 50000, 'votes' => 350, 'label' => 'Patron Pass — 350 votes'],
        ],
        // Category C — child-champion donations (recognition; no votes).
        'child' => [
            'champion' => ['amount' => 25000,   'votes' => 0, 'label' => 'Child Champion'],
            'bronze'   => ['amount' => 50000,   'votes' => 0, 'label' => 'Bronze Sponsor'],
            'silver'   => ['amount' => 100000,  'votes' => 0, 'label' => 'Silver Sponsor'],
            'gold'     => ['amount' => 250000,  'votes' => 0, 'label' => 'Gold Sponsor'],
            'platinum' => ['amount' => 500000,  'votes' => 0, 'label' => 'Platinum Sponsor'],
            'builder'  => ['amount' => 1000000, 'votes' => 0, 'label' => 'Future Builder'],
        ],
    ];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly Twig $view,
        private readonly ?LoggerInterface $log = null,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    /** Where to send the buyer when checkout can't start. Keeps the enquiry fallback alive. */
    private function fallbackUrl(?Request $req = null): string
    {
        return $this->base($req) . '/partner#enquiry';
    }

    /**
     * Absolute site base. Via SiteUrl, which falls back to the REQUEST when APP_URL is
     * unset — this used to return '' and every gateway callback URL built from it was
     * relative, which a payment provider cannot redirect a browser to. See SiteUrl.
     */
    private function base(?Request $req = null): string
    {
        return \AfricaGates\Support\SiteUrl::base($req);
    }

    private function isAjax(Request $req): bool
    {
        return strtolower($req->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
            || str_contains(strtolower($req->getHeaderLine('Accept')), 'application/json');
    }

    private function json(Response $res, array $data, int $status = 200): Response
    {
        $res->getBody()->write((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function redirect(Response $res, string $url, int $status = 302): Response
    {
        return $res->withHeader('Location', $url)->withStatus($status);
    }

    // ─────────────────────────────── /pay/init ──────────────────────────────

    /**
     * Begin a purchase. First-party form post (carries CSRF token via the global
     * CsrfMiddleware). Resolves price server-side, persists a pending donation,
     * and hands off to the gateway's hosted checkout.
     */
    public function init(Request $req, Response $res): Response
    {
        $b        = (array) $req->getParsedBody();
        $provider = strtolower(trim((string)($b['provider'] ?? '')));
        $purpose  = strtolower(trim((string)($b['purpose']  ?? '')));
        $tier     = strtolower(trim((string)($b['tier']     ?? '')));
        $email    = strtolower(trim((string)($b['email']    ?? '')));
        $name     = trim((string)($b['name']  ?? ''));
        $phone    = trim((string)($b['phone'] ?? ''));

        $bail = function (string $msg) use ($req, $res): Response {
            $this->log?->info('[payment] init rejected', ['reason' => $msg]);
            if ($this->isAjax($req)) {
                return $this->json($res, ['ok' => false, 'message' => $msg, 'redirect' => $this->fallbackUrl($req)], 422);
            }
            return $this->redirect($res, $this->fallbackUrl($req));
        };

        // 1. Provider must be one we can actually transact with right now.
        if (!$this->payments->isEnabled($provider)) {
            return $bail('Selected payment method is not available.');
        }
        // 2. Purpose + tier must exist in the server price book.
        $price = self::PRICES[$purpose][$tier] ?? null;
        if ($price === null) {
            return $bail('Unknown purchase option.');
        }
        // 3. Valid email (receipt + vote crediting are keyed to it).
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $bail('A valid email address is required.');
        }

        // 4. Abuse control: cap pending-row + gateway churn. Financial abuse is
        // already impossible (amounts are server-authoritative); this throttles the
        // noise/table-growth a scripted caller could otherwise generate — which is
        // why it comes AFTER validation and why the cap is generous. Twelve per hour
        // keyed on REMOTE_ADDR was, behind Cloudflare, twelve purchases per hour for
        // every buyer on the platform combined. See CheckoutThrottle.
        $gate = (new \AfricaGates\Services\CheckoutThrottle($this->rateLimit))->allow($req, 'pay_init');
        if (!$gate['ok']) {
            return $bail('Checkout is busy right now — please try again '
                . \AfricaGates\Services\CheckoutThrottle::retryPhrase((int) $gate['retry_after']) . '.');
        }

        $amount = (int) $price['amount'];
        $votes  = (int) $price['votes'];

        // Unique, unguessable reference. Used as gates_donations.payment_ref and as
        // the gateway's reference / tx_ref, so the callback maps back 1:1.
        $reference = 'AFG-' . bin2hex(random_bytes(8));

        // 4. Persist a PENDING row BEFORE leaving for the gateway, so the callback
        //    has something to reconcile against and nothing is credited until
        //    server-side verification flips it to 'confirmed'.
        try {
            // `provider` is dropped on an unmigrated database rather than throwing,
            // for the same reason `show_name` is on the paid-vote path: losing which
            // gateway took the money costs a lookup later, losing the INSERT costs
            // the buyer the ability to pay at all. Everything downstream still
            // falls back to asking every gateway when the column is absent.
            DB::table('gates_donations')->insert(\AfricaGates\Support\OptionalColumn::filter('gates_donations', [
                'donor_name'     => $name !== '' ? $name : 'Supporter',
                'donor_email'    => $email,
                'donor_phone'    => $phone !== '' ? $phone : null,
                'donor_location' => null,
                'amount_naira'   => $amount,
                'tier'           => substr($purpose . ':' . $tier, 0, 50),
                'bonus_votes'    => $votes,
                'votes_used'     => 0,
                'payment_ref'    => $reference,
                'status'         => 'pending',
                'provider'       => $provider,
                'created_at'     => Carbon::now()->toDateTimeString(),
            ], ['provider']));
        } catch (\Throwable $e) {
            $this->log?->error('[payment] could not persist pending donation', ['err' => $e->getMessage()]);
            return $bail('Could not start checkout. Please try the enquiry form.');
        }

        // 5. Ask the gateway for a hosted-checkout URL. callback carries the ref so
        //    /pay/callback knows which provider+reference to verify.
        $callbackUrl = $this->base($req) . '/pay/callback?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);
        $meta = [
            'reference' => $reference,
            'purpose'   => $purpose,
            'tier'      => $tier,
            'votes'     => $votes,
        ];

        $init = $this->payments->initialize($provider, $amount, $email, $reference, $callbackUrl, $meta);

        if (!$init['ok'] || empty($init['checkout_url'])) {
            // Mark the orphaned pending row failed so it can't linger as confirmable.
            DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'pending')
                ->update(['status' => 'failed']);
            return $bail($init['message'] ?: 'Could not start checkout.');
        }

        $this->log?->info('[payment] checkout started', ['provider' => $provider, 'ref' => $reference, 'amount' => $amount]);

        // The AJAX branch is exempt on purpose: a JavaScript-initiated navigation is not a
        // form submission, so `form-action` never governed it. Only the non-AJAX branch —
        // an ordinary form POST — needs the same-origin hop.
        if ($this->isAjax($req)) {
            return $this->json($res, ['ok' => true, 'checkout_url' => $init['checkout_url'], 'reference' => $reference]);
        }
        // NOT a 302 straight to the gateway: `form-action` governs the redirect chain of a
        // form submission, and a policy without the gateway hosts blocks the POST in the
        // browser before any PHP runs. See GatewayHandoff.
        return $this->redirect($res, \AfricaGates\Services\GatewayHandoff::remember(
            $reference, (string) $init['checkout_url'], $this->base($req) . '/pay/redirect', $provider
        ));
    }

    /**
     * GET /pay/redirect — the same-origin hop to the gateway.
     *
     * See {@see \AfricaGates\Services\GatewayHandoff}.
     */
    public function handoff(Request $req, Response $res): Response
    {
        $reference = \AfricaGates\Services\GatewayHandoff::reference($req);
        $url = \AfricaGates\Services\GatewayHandoff::take($reference);
        if ($url === null) {
            return $this->redirect($res, $this->fallbackUrl($req));
        }
        return \AfricaGates\Services\GatewayHandoff::page(
            $res, $url, \AfricaGates\Services\GatewayHandoff::providerLabel(), $reference
        );
    }

    // ───────────────────────────── /pay/callback ────────────────────────────

    /**
     * Browser return from the gateway. We DO NOT trust any status in the query —
     * we re-verify server-to-server, then confirm only on a status+amount match.
     */
    public function callback(Request $req, Response $res): Response
    {
        $q         = $req->getQueryParams();
        $reference = trim((string)($q['ref'] ?? $q['reference'] ?? $q['tx_ref'] ?? ''));
        $provider  = strtolower(trim((string)($q['provider'] ?? '')));

        if ($reference === '') {
            return $this->redirect($res, $this->base($req) . '/partner?pay=error');
        }

        // The provider comes from the callback URL we generated at /pay/init. We
        // need it to know which gateway to re-verify against; without a known one
        // we can't confirm anything, so treat it as an error.
        if (!$this->payments->isKnownProvider($provider)) {
            return $this->redirect($res, $this->base($req) . '/partner?pay=error');
        }

        $donation = DB::table('gates_donations')->where('payment_ref', $reference)->first();
        if (!$donation) {
            return $this->redirect($res, $this->base($req) . '/partner?pay=error');
        }

        $result = $this->confirmByReference($provider, $reference, $donation, 'callback');

        if ($result === 'confirmed' || $result === 'already') {
            return $this->redirect($res, $this->base($req) . '/pay/success?ref=' . urlencode($reference));
        }
        return $this->redirect($res, $this->base($req) . '/partner?pay=failed');
    }

    /**
     * Renders the success page. Read-only; the actual crediting happened in
     * callback/webhook. Shown only for a confirmed reference.
     */
    public function success(Request $req, Response $res): Response
    {
        $reference = trim((string)($req->getQueryParams()['ref'] ?? ''));
        $donation  = $reference !== ''
            ? DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'confirmed')->first()
            : null;

        return $this->view->render($res, 'pages/pay-success.twig', [
            'page_title'       => 'Payment Confirmed — Africa GATES',
            'meta_description' => 'Thank you — your Africa GATES contribution is confirmed.',
            'gates_page'       => 'partner',
            'has_hero'         => false,
            'confirmed'        => $donation !== null,
            'amount_naira'     => $donation ? (int) $donation->amount_naira : 0,
            'bonus_votes'      => $donation ? (int) $donation->bonus_votes : 0,
            'reference'        => $reference,
        ]);
    }

    // ───────────────────────────── /pay/webhook ─────────────────────────────

    /**
     * Server-to-server gateway notification. CSRF-exempt (no browser session) and
     * authenticated by provider signature over the RAW body. Confirms idempotently
     * and always answers 200 once the signature is valid so the gateway stops
     * retrying.
     */
    public function webhook(Request $req, Response $res): Response
    {
        // Raw body is required for HMAC. BodyParsingMiddleware already consumed the
        // PSR-7 stream into a parsed array, but the underlying stream is rewindable.
        $body = $req->getBody();
        $body->rewind();
        $raw = $body->getContents();

        $provider = $this->detectWebhookProvider($req);
        if ($provider === null || !$this->payments->isEnabled($provider)) {
            $this->log?->warning('[payment] webhook from unknown/disabled provider');
            Log::record((string) $provider, '', '', '', 'rejected', 'unknown or disabled provider');
            return $res->withStatus(404);
        }

        // ── OPTIONAL SECOND LINE OF DEFENCE ──────────────────────────────────
        //
        // Paystack sends webhooks from three fixed addresses, and its docs offer IP
        // allowlisting alongside signature verification. OFF unless switched on, and
        // deliberately so: the signature is the real control, and an allowlist is the kind of
        // hardening that silently drops every payment notification the day a provider adds a
        // fourth address or a reverse proxy stops forwarding the client IP.
        //
        // So it is opt-in per deployment, and a rejection is recorded rather than merely
        // dropped — a security control that fails closed and invisibly is how the "no webhooks
        // have arrived in a week" incident happens a second time.
        if (!$this->sourceAllowed($provider, $req)) {
            $this->log?->warning('[payment] webhook from a non-allowlisted address', ['provider' => $provider]);
            Log::record($provider, '', '', '', 'rejected', 'source address not in PAYSTACK_WEBHOOK_IPS');
            return $res->withStatus(403);
        }

        if (!$this->verifyWebhookSignature($provider, $req, $raw)) {
            $this->log?->warning('[payment] webhook signature rejected', ['provider' => $provider]);
            Log::record($provider, '', '', '', 'rejected', 'signature did not verify');
            return $res->withStatus(401);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Log::record($provider, '', '', '', 'rejected', 'body was not JSON');
            return $res->withStatus(400);
        }

        $event  = strtolower(trim((string) ($payload['event'] ?? ($payload['type'] ?? ''))));
        // Paystack allows ONE webhook URL per account and test and live share it, so this is
        // the only thing in the request that says which world it came from. Recorded rather
        // than acted on: the signing secret already decides which mode can reach us at all,
        // and refusing on `domain` would break the moment somebody rehearses against staging.
        $domain = strtolower(trim((string) ($payload['data']['domain'] ?? ($payload['domain'] ?? ''))));

        try {
            return $this->handleWebhook($res, $provider, $event, $domain, $payload);
        } catch (\Throwable $e) {
            // ── AND IT STILL ANSWERS 200 ─────────────────────────────────────
            //
            // A 500 here is a delivery Paystack will retry every three minutes and then
            // hourly for 72 hours, against a handler that has just proved it throws. The
            // failure is recorded where somebody can see it, and the sweep will pick the
            // payment up regardless — that is what the sweep is for.
            $this->log?->error('[payment] webhook handler threw', ['err' => $e->getMessage()]);
            Log::record($provider, $event, '', '', 'error', $e->getMessage(), $domain);
            return $res->withStatus(200);
        }
    }

    /**
     * Decide what one signed delivery means, and route it.
     *
     * ── WHY THE ROUTING IS BY REFERENCE AND NOT BY TABLE ─────────────────────
     *
     * This method used to be four lines that looked the reference up in `gates_donations` and
     * did nothing if it was not there. Paystack permits exactly ONE webhook URL per account,
     * so every payment on the platform — vote packs, donations, shop orders, event tickets —
     * arrives here. Three of those four live in `gates_donations`. The other two do not.
     *
     * So a shop order and an event ticket were signed, matched nothing, and were acknowledged
     * with a 200 — which the gateway reads as success, and which therefore ended the only
     * notification either of them was ever going to get. The shop survived on the cron sweep.
     * Event tickets had no sweep, no admin repair and no support lookup, so a buyer who paid
     * inside a wallet app and never came back lost both the money and the seat.
     *
     * The stream comes from the reference prefix via {@see PaymentDestination}, which is
     * already the authority for which subaccount the money settled into — so the confirmation
     * and the settlement can never disagree about what kind of payment this is.
     */
    private function handleWebhook(Response $res, string $provider, string $event,
                                   string $domain, array $payload): Response
    {
        $ack = static fn (): Response => $res->withStatus(200);

        // ── money going BACK ─────────────────────────────────────────────────
        $reversal = $this->webhookReversalKind($payload);
        if ($reversal !== null) {
            $ref    = $this->webhookReversalReference($provider, $payload);
            $stream = PaymentDestination::streamForReference($ref);
            $amount = null;
            $did    = 'unmatched';

            if ($ref !== '') {
                // Each stream owns what a reversal means for it. Votes lose the display votes
                // the donation bought; a shop order goes back to stock and gives up its
                // points; a ticket has its code cleared so it stops opening a door.
                if ($stream === 'shop') {
                    $o = DB::table('gates_orders')->where('reference', $ref)->first();
                    $amount = isset($o->subtotal_naira) ? (int) $o->subtotal_naira : null;
                    $did = \AfricaGates\Services\ShopOrderService::reverse($ref, $reversal, $this->log)
                        ? 'reversed' : ($o ? 'ignored' : 'unmatched');
                } elseif ($stream === 'events') {
                    $r = \AfricaGates\Services\EventTicketService::byReference($ref);
                    $amount = isset($r->amount_naira) ? (int) $r->amount_naira : null;
                    $did = \AfricaGates\Services\EventTicketService::reverse($ref, $reversal)
                        ? 'reversed' : ($r ? 'ignored' : 'unmatched');
                } else {
                    $d = DB::table('gates_donations')->where('payment_ref', $ref)->first();
                    $amount = isset($d->amount_naira) ? (int) $d->amount_naira : null;
                    if ($d) {
                        $r = \AfricaGates\Services\BonusVoteService::clawbackDonation(
                            (int) $d->id, null, 'webhook:' . $provider . ':' . $reversal);
                        $this->log?->info('[payment] reversal clawback', [
                            'ref' => $ref, 'provider' => $provider, 'event' => $reversal,
                            'cleared' => $r['cleared'] ?? 0,
                        ]);
                        $did = 'reversed';
                    }
                }
            }

            // ── A CHARGEBACK STARTS A 16-HOUR CLOCK ─────────────────────────
            //
            // Paystack gives a merchant 16 hours to respond to a dispute; if the window
            // closes it accepts the dispute on your behalf and refunds the customer out
            // of your balance. The four-hourly `dispute.remind` events are deliberately
            // ignored as step events, so there is no second chance either.
            //
            // OUTSIDE the reference guard on purpose: a dispute we cannot tie to an order
            // is MORE alarming than one we can, not less, and it is the case most in need
            // of a human. Queued, not sent — see DisputeAlert.
            if (str_contains($reversal, 'dispute') || str_contains($reversal, 'chargeback')) {
                \AfricaGates\Services\DisputeAlert::queue($ref, $reversal, $provider, $amount);
                if ($did === 'unmatched') $did = 'alerted';
            }

            Log::record($provider, $event, $ref, $stream, $did, $reversal, $domain);
            return $ack();     // ack either way so the gateway stops retrying
        }

        // ── money coming IN ──────────────────────────────────────────────────
        $reference = $this->webhookReference($provider, $payload);
        if ($reference === '') {
            // Signed, but carrying no reference we mint — a subscription notice, an expiring
            // -cards batch, a customer-identification result. Recorded so an operator can see
            // what a gateway is actually sending before deciding whether to act on it.
            Log::record($provider, $event, '', '', 'ignored', 'no reference in payload', $domain);
            return $ack();
        }

        $stream = PaymentDestination::streamForReference($reference);

        if ($stream === 'shop') {
            $r = \AfricaGates\Services\ShopOrderService::confirm(
                $reference, $provider, $this->payments, $this->log);
            Log::record($provider, $event, $reference, $stream,
                        (string) $r['state'], (string) $r['message'], $domain);
            return $ack();
        }

        if ($stream === 'events') {
            $reg = \AfricaGates\Services\EventTicketService::byReference($reference);
            if (!$reg) {
                Log::record($provider, $event, $reference, $stream, 'unmatched', '', $domain);
                return $ack();
            }
            $r = \AfricaGates\Services\EventTicketService::confirm($reference, $this->payments);
            $did = ($r['ok'] ?? false)
                ? (($r['already'] ?? false) ? 'already' : 'confirmed')
                : 'mismatch';
            // The buyer's ticket email. Only on the transition — `already` means some other
            // path has been here — and queued, because SMTP is up to twelve seconds of a
            // roughly thirty-second budget for the whole delivery.
            if ($did === 'confirmed') {
                \AfricaGates\Services\EventTicketMailer::queue((int) $reg->id);
            }
            Log::record($provider, $event, $reference, $stream, $did,
                        (string) ($r['message'] ?? ''), $domain);
            return $ack();
        }

        $donation = DB::table('gates_donations')->where('payment_ref', $reference)->first();
        if (!$donation) {
            Log::record($provider, $event, $reference, $stream, 'unmatched', '', $domain);
            return $ack();
        }
        // Re-verify server-to-server rather than trusting the webhook body's
        // amount/status — defence in depth even past the signature.
        $state = $this->confirmByReference($provider, $reference, $donation, 'webhook');
        Log::record($provider, $event, $reference, $stream, $state, '', $domain);

        return $ack();
    }

    // ──────────────────────────── confirmation core ─────────────────────────

    /**
     * The single confirmation path shared by callback + webhook.
     *
     * Verifies with the gateway, checks amount parity, then performs an IDEMPOTENT
     * pending→confirmed transition. Returns one of:
     *   'confirmed' — we flipped it this call
     *   'already'   — it was already confirmed (no re-credit)
     *   'failed'    — verification says failed / amount mismatch / not yet paid
     *
     * @param object $donation row from gates_donations (has id, amount_naira, status)
     */
    private function confirmByReference(string $provider, string $reference, object $donation, string $source): string
    {
        if (($donation->status ?? '') === 'confirmed') {
            // Idempotent fast-path: the money is settled and is never re-verified
            // or re-credited. But it is NOT nothing-to-do. The commonest way to
            // arrive here is the webhook reading a row the browser callback
            // confirmed a second earlier — and if that callback's mint was refused
            // or its process died, this is the only other chance anybody gets. Both
            // calls claim before they act, so a completed order is untouched.
            $this->deliver($reference);
            return 'already';
        }

        $v = $this->payments->verify($provider, $reference);

        if (!$v['ok'] || $v['status'] !== 'success') {
            // Only demote to 'failed' on an explicit failure; a 'pending' gateway
            // state is left pending so a later webhook/callback can still confirm.
            if (($v['status'] ?? '') === 'failed') {
                DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'pending')
                    ->update(['status' => 'failed']);
            }
            $this->log?->info('[payment] not confirmed', ['src' => $source, 'ref' => $reference, 'status' => $v['status'] ?? '?']);
            return 'failed';
        }

        // CRITICAL: the buyer must have paid at least what we asked for, in naira.
        //
        // ── WHY THIS IS `<` AND NOT `!==` ────────────────────────────────────
        //
        // Strict equality refused an OVERpayment as hard as an underpayment, and
        // the two are nothing alike. Short of the price is a partial payment or a
        // tampered reference and must never confirm. Over the price is somebody who
        // paid MORE than we asked and got nothing: the row stayed `pending`, so the
        // refund sweep — which only ever looks at CONFIRMED orders — never saw it
        // either. They were owed money by rules that could not reach them.
        //
        // It is not hypothetical. Turning on "customer bears the transaction fee" in
        // a gateway dashboard adds the fee to the charged amount, and every single
        // payment on the platform then arrives a few hundred naira over and is
        // refused. One dashboard toggle, total outage, no code change.
        //
        // The surplus is logged rather than silently absorbed, because money we did
        // not ask for is a conversation somebody has to have.
        if ((int) $v['amount'] < (int) $donation->amount_naira || !$this->currencyAgrees($v)) {
            $this->log?->warning('[payment] amount/currency mismatch — refusing to confirm', [
                'ref' => $reference, 'expected' => (int) $donation->amount_naira,
                'verified' => (int) $v['amount'], 'currency' => (string) ($v['currency'] ?? ''),
            ]);
            return 'failed';
        }
        if ((int) $v['amount'] > (int) $donation->amount_naira) {
            $this->log?->warning('[payment] OVERPAID — confirming anyway', [
                'ref' => $reference, 'expected' => (int) $donation->amount_naira,
                'paid' => (int) $v['amount'], 'surplus' => (int) $v['amount'] - (int) $donation->amount_naira,
            ]);
        }

        // IDEMPOTENT transition: the WHERE status='pending' clause means only the
        // first writer flips the row. A concurrent callback+webhook race resolves to
        // exactly one UPDATE affecting 1 row; the loser updates 0 rows.
        $changed = DB::table('gates_donations')
            ->where('payment_ref', $reference)
            ->where('status', 'pending')
            ->update(\AfricaGates\Support\OptionalColumn::filter('gates_donations', [
                'status' => 'confirmed',
                // WHEN the money arrived. The platform recorded only when checkout
                // STARTED, so every question about confirmation — including the
                // refund grace window, which documents itself as measuring this —
                // was answered with the wrong timestamp. Dropped on an unmigrated
                // database rather than throwing: losing the moment costs accuracy,
                // losing this UPDATE costs the buyer their votes.
                'confirmed_at' => Carbon::now()->toDateTimeString(),
            ], ['confirmed_at']));

        // The gateway's own transaction id and reference — the numbers on the buyer's
        // receipt, and the only moment we hold them. Every lookup surface on this
        // platform used to match only the AFG- reference WE minted, so "the number on my
        // Paystack receipt" was the one thing guaranteed to find nothing. Outside the
        // `$changed` branch on purpose: a second confirmation attempt that loses the race
        // still learned the ids, and writing them is not a state transition.
        // See PaymentLookup. Never throws.
        \AfricaGates\Services\PaymentLookup::remember('gates_donations', (int) $donation->id, $v);

        if ($changed > 0) {
            $this->log?->info('[payment] confirmed', ['src' => $source, 'ref' => $reference, 'amount' => $v['amount']]);
            // Post-commit, best-effort — additive to the audited payment path.
            // Reference + amount only; no donor PII.
            //
            // QUEUED, not sent here. This runs inside the Paystack webhook, which has
            // about 30 seconds for the whole handler before the delivery is counted as
            // failed and enters a 72-hour retry schedule — and dispatch() sends one
            // HTTP request per active integration, 8 seconds each worst case, on top of
            // the 15 seconds this method may already have spent verifying. The number
            // of integrations is set by an admin who has no reason to connect the
            // console to payment reliability. See WebhookService::dispatchLater().
            \AfricaGates\Services\WebhookService::dispatchLater('donation.confirmed', [
                'reference' => $reference,
                'amount'    => $v['amount'] ?? null,
                'source'    => $source,
            ]);
            $this->deliver($reference);
            return 'confirmed';
        }

        // 0 rows: someone else confirmed it between our read and write.
        //
        // DELIVER ANYWAY. This used to return here, which quietly assumed the writer
        // that won the race also finished the job. It need not have: mint() can be
        // refused, the process can die between the flip and the mint, a receipt can
        // throw. The loser of the race then walked away from a confirmed order with
        // no votes on it — and the WEBHOOK is very often that loser, because a buyer
        // paying inside a wallet app comes back late or not at all. Both calls are
        // idempotent, so the second one either finishes the work or does nothing.
        $this->deliver($reference);
        return 'already';
    }

    /**
     * Everything a confirmed order still owes its buyer. Idempotent, and it never
     * throws: a receipt failure must not undo a confirmation.
     */
    private function deliver(string $reference): void
    {
        try {
            $row = DB::table('gates_donations')->where('payment_ref', $reference)->first(['id', 'tier', 'intent_nominee_id']);
            if ($row && ($row->tier ?? '') === 'paid-vote' && !empty($row->intent_nominee_id)) {
                // MINTING STAYS INLINE, deliberately. It is a handful of indexed
                // writes, and it is the thing the supporter is actually watching — a
                // tally that updates on the next cron tick instead of now is the
                // complaint this platform gets most. The slow work below is what moves.
                //
                // Guarded by the votes_used claim, so a second call credits nothing.
                \AfricaGates\Services\PaidVoteService::mint((int) $row->id);
                // The buyer's receipt — and the one path that NEEDS it, because a
                // webhook confirm means the browser never came back, so the
                // confirmation page was never seen. QUEUED: SMTP is up to 12 seconds
                // and this method runs inside a gateway webhook with a ~30-second
                // budget. Claimed once per order, so whichever of {webhook, callback}
                // lands second sends nothing, and a job that runs twice sends one
                // email. See CheckoutMailer::queueReceipt().
                \AfricaGates\Services\CheckoutMailer::queueReceipt((int) $row->id);
            }
        } catch (\Throwable $e) {
            $this->log?->error('[payment] paid-vote delivery failed', ['ref' => $reference, 'err' => $e->getMessage()]);
        }
    }

    /**
     * Everything is priced and charged in naira. A gateway reporting success in
     * another currency has not paid THIS order, whatever the number says — ₦5,000
     * and $5,000 are the same integer and three orders of magnitude apart, and the
     * amount check above compares integers.
     */
    private function currencyAgrees(array $v): bool
    {
        $c = strtoupper(trim((string) ($v['currency'] ?? '')));
        return $c === '' || $c === 'NGN';   // '' = a gateway that does not report one
    }

    // ──────────────────────────── webhook helpers ───────────────────────────

    /**
     * Is this delivery coming from an address we accept?
     *
     * ── WHY THE DEFAULT IS "YES" ─────────────────────────────────────────────
     *
     * Unset means unrestricted, which is the behaviour this platform has always had and which
     * the HMAC signature already makes safe. Paystack's own three addresses are documented
     * below so an operator switching this on does not have to go and find them — but they are
     * NOT baked in as a default, because Paystack adding a fourth would then silently stop
     * every payment notification on every deployment of this code at once.
     *
     *     PAYSTACK_WEBHOOK_IPS=52.31.139.75,52.49.173.169,52.214.14.220
     *
     * Only Paystack is checked. Flutterwave publishes no equivalent list, and inventing one
     * would be a control that looks like protection and is a coin toss.
     */
    private function sourceAllowed(string $provider, Request $req): bool
    {
        if ($provider !== 'paystack') return true;

        $configured = trim((string) Env::get('PAYSTACK_WEBHOOK_IPS', ''));
        if ($configured === '') return true;                 // unset = unrestricted

        $allowed = array_values(array_filter(array_map('trim', explode(',', $configured))));
        if ($allowed === []) return true;

        // REMOTE_ADDR, not a forwarded header. Behind Cloudflare the true client address
        // arrives in a header — but a header is attacker-controlled unless the proxy in front
        // is known to overwrite it, and an allowlist that trusts a spoofable value is worse
        // than no allowlist, because it reads as a control while checking nothing. A
        // deployment behind a proxy should leave this unset and rely on the signature.
        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');

        return $ip !== '' && in_array($ip, $allowed, true);
    }

    /** Identify the calling gateway from its signature header. */
    private function detectWebhookProvider(Request $req): ?string
    {
        if ($req->getHeaderLine('x-paystack-signature') !== '') return 'paystack';
        if ($req->getHeaderLine('verif-hash') !== '')           return 'flutterwave';
        return null;
    }

    /**
     * Verify the webhook is genuinely from the gateway.
     *  - Paystack: x-paystack-signature = HMAC-SHA512(rawBody, SECRET_KEY).
     *  - Flutterwave: verif-hash header === FLUTTERWAVE_WEBHOOK_HASH (a shared
     *    secret you set in the FW dashboard).
     */
    private function verifyWebhookSignature(string $provider, Request $req, string $raw): bool
    {
        if ($provider === 'paystack') {
            $secret = trim((string) Env::get('PAYSTACK_SECRET_KEY', ''));
            if ($secret === '') return false;
            $expected = hash_hmac('sha512', $raw, $secret);
            $got      = $req->getHeaderLine('x-paystack-signature');
            return $got !== '' && hash_equals($expected, $got);
        }

        if ($provider === 'flutterwave') {
            $configured = trim((string) Env::get('FLUTTERWAVE_WEBHOOK_HASH', ''));
            if ($configured === '') return false; // no shared secret set → reject
            $got = $req->getHeaderLine('verif-hash');
            return $got !== '' && hash_equals($configured, $got);
        }

        return false;
    }

    /** Pull our reference out of a (signature-verified) webhook payload. */
    private function webhookReference(string $provider, array $payload): string
    {
        if ($provider === 'paystack') {
            // { event: 'charge.success', data: { reference: 'AFG-…', … } }
            return trim((string)($payload['data']['reference'] ?? ''));
        }
        if ($provider === 'flutterwave') {
            // { event: 'charge.completed', data: { tx_ref: 'AFG-…', … } }
            return trim((string)($payload['data']['tx_ref'] ?? ($payload['data']['txRef'] ?? '')));
        }
        return '';
    }

    /**
     * Did money actually go BACK?
     *
     * ── WHY THIS IS NOT A SUBSTRING SEARCH ───────────────────────────────────
     *
     * It used to be: any event whose name contained "refund" or "dispute" claimed
     * back the votes that donation had bought. Both gateways emit an event per STEP
     * of a refund, not just per outcome, so that matched:
     *
     *   refund.failed        the refund did NOT happen. The buyer still has no
     *                        money back — and now no votes either, permanently,
     *                        because the clawback stamps `refunded_at` and that
     *                        blocks both re-minting and redeeming. The single worst
     *                        outcome available, produced by the gateway telling us
     *                        nothing had happened.
     *   refund.pending       queued, hours from settling, might still fail.
     *   charge.dispute.remind  a reminder to respond to a dispute we already
     *                        handled at .create.
     *
     * Removing somebody's votes cannot be undone from here, so the rule is now the
     * other way round: act ONLY on events that mean the money is gone, name them,
     * and treat everything else as information. An unrecognised money-movement
     * event is LOGGED rather than acted on — a person reading a warning is a much
     * better failure than a supporter silently losing what they paid for.
     */
    private function webhookReversalKind(array $payload): ?string
    {
        $event = strtolower(trim((string)($payload['event'] ?? ($payload['event.type'] ?? ($payload['type'] ?? '')))));
        if ($event === '') return null;

        // Steps along the way, not outcomes. Explicit, and checked FIRST so no
        // later pattern can reach them.
        foreach (['refund.failed', 'refund.pending', 'refund.processing',
                  'dispute.remind', 'dispute.resolve'] as $step) {
            if (str_contains($event, $step)) return null;
        }

        // The money is gone. A dispute at CREATE is included on purpose: the bank
        // has already pulled the funds, whatever the eventual resolution.
        foreach (['refund.processed', 'refund.completed', 'refund.success', 'charge.refunded',
                  'chargeback', 'charge.back', 'dispute.create', 'reversed', 'reversal'] as $done) {
            if (str_contains($event, $done)) return $event;
        }

        // Names a refund or a dispute and matches nothing above — a gateway has
        // added an event, or renamed one. Say so; do not guess with someone's votes.
        foreach (['refund', 'dispute', 'chargeback'] as $hint) {
            if (str_contains($event, $hint)) {
                $this->log?->warning('[payment] unrecognised reversal-shaped webhook — no clawback', ['event' => $event]);
                return null;
            }
        }
        return null;
    }

    /**
     * The original charge reference on a reversal event — tolerant across
     * providers, since refund payloads carry it under different keys than a
     * successful charge (e.g. Paystack's transaction_reference).
     */
    private function webhookReversalReference(string $provider, array $payload): string
    {
        $d = (array)($payload['data'] ?? []);
        foreach (['reference', 'transaction_reference', 'tx_ref', 'txRef', 'transaction', 'flw_ref', 'flwRef'] as $k) {
            $v = $d[$k] ?? null;
            if (is_array($v)) $v = $v['reference'] ?? ($v['tx_ref'] ?? null);   // nested { transaction: { reference } }
            if (is_string($v) && trim($v) !== '') return trim($v);
        }
        // Last resort: the normal per-provider extractor.
        return $this->webhookReference($provider, $payload);
    }
}
