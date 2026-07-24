<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Views\Twig;
use AfricaGates\Services\{PaymentService, RateLimitService};

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
    private function fallbackUrl(): string
    {
        return $this->base() . '/partner#enquiry';
    }

    private function base(): string
    {
        return rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
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
                return $this->json($res, ['ok' => false, 'message' => $msg, 'redirect' => $this->fallbackUrl()], 422);
            }
            return $this->redirect($res, $this->fallbackUrl());
        };

        // Abuse control: cap pending-row + gateway churn per IP. Financial abuse is
        // already impossible (amounts are server-authoritative); this throttles the
        // noise/table-growth a scripted caller could otherwise generate.
        $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($this->rateLimit && $ip !== '' && !$this->rateLimit->check(hash('sha256', $ip . '|pay-init'), 'pay_init', 12, 3600)) {
            return $bail('Too many attempts — please wait a few minutes and try again.');
        }

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

        $amount = (int) $price['amount'];
        $votes  = (int) $price['votes'];

        // Unique, unguessable reference. Used as gates_donations.payment_ref and as
        // the gateway's reference / tx_ref, so the callback maps back 1:1.
        $reference = 'AFG-' . bin2hex(random_bytes(8));

        // 4. Persist a PENDING row BEFORE leaving for the gateway, so the callback
        //    has something to reconcile against and nothing is credited until
        //    server-side verification flips it to 'confirmed'.
        try {
            DB::table('gates_donations')->insert([
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
                'created_at'     => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            $this->log?->error('[payment] could not persist pending donation', ['err' => $e->getMessage()]);
            return $bail('Could not start checkout. Please try the enquiry form.');
        }

        // 5. Ask the gateway for a hosted-checkout URL. callback carries the ref so
        //    /pay/callback knows which provider+reference to verify.
        $callbackUrl = $this->base() . '/pay/callback?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);
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

        if ($this->isAjax($req)) {
            return $this->json($res, ['ok' => true, 'checkout_url' => $init['checkout_url'], 'reference' => $reference]);
        }
        return $this->redirect($res, $init['checkout_url']);
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
            return $this->redirect($res, $this->base() . '/partner?pay=error');
        }

        // The provider comes from the callback URL we generated at /pay/init. We
        // need it to know which gateway to re-verify against; without a known one
        // we can't confirm anything, so treat it as an error.
        if (!$this->payments->isKnownProvider($provider)) {
            return $this->redirect($res, $this->base() . '/partner?pay=error');
        }

        $donation = DB::table('gates_donations')->where('payment_ref', $reference)->first();
        if (!$donation) {
            return $this->redirect($res, $this->base() . '/partner?pay=error');
        }

        $result = $this->confirmByReference($provider, $reference, $donation, 'callback');

        if ($result === 'confirmed' || $result === 'already') {
            return $this->redirect($res, $this->base() . '/pay/success?ref=' . urlencode($reference));
        }
        return $this->redirect($res, $this->base() . '/partner?pay=failed');
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
            return $res->withStatus(404);
        }

        if (!$this->verifyWebhookSignature($provider, $req, $raw)) {
            $this->log?->warning('[payment] webhook signature rejected', ['provider' => $provider]);
            return $res->withStatus(401);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $res->withStatus(400);
        }

        // Refund / chargeback / dispute → claw back the purchased votes this
        // donation bought. Purely ADDITIVE: it never runs the confirm path and
        // only removes paid display votes (organic CPI is untouched).
        if ($this->webhookIsReversal($payload)) {
            $ref = $this->webhookReversalReference($provider, $payload);
            if ($ref !== '') {
                $d = DB::table('gates_donations')->where('payment_ref', $ref)->first();
                if ($d) {
                    $r = \AfricaGates\Services\BonusVoteService::clawbackDonation((int) $d->id, null, 'webhook:' . $provider);
                    $this->log?->info('[payment] reversal clawback', ['ref' => $ref, 'provider' => $provider, 'cleared' => $r['cleared'] ?? 0]);
                }
            }
            return $res->withStatus(200);   // ack either way so the gateway stops retrying
        }

        $reference = $this->webhookReference($provider, $payload);
        if ($reference === '') {
            // Signed but no reference we recognise (e.g. a non-charge event). Ack so
            // the gateway doesn't retry; nothing to do.
            return $res->withStatus(200);
        }

        $donation = DB::table('gates_donations')->where('payment_ref', $reference)->first();
        if ($donation) {
            // Re-verify server-to-server rather than trusting the webhook body's
            // amount/status — defence in depth even past the signature.
            $this->confirmByReference($provider, $reference, $donation, 'webhook');
        }

        return $res->withStatus(200);
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
            return 'already'; // idempotent fast-path; never re-credits
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

        // CRITICAL: the verified amount must equal what we asked the buyer to pay.
        // Guards against a buyer paying a different amount or a forged callback.
        if ((int) $v['amount'] !== (int) $donation->amount_naira) {
            $this->log?->warning('[payment] amount mismatch — refusing to confirm', [
                'ref' => $reference, 'expected' => (int) $donation->amount_naira, 'verified' => (int) $v['amount'],
            ]);
            return 'failed';
        }

        // IDEMPOTENT transition: the WHERE status='pending' clause means only the
        // first writer flips the row. A concurrent callback+webhook race resolves to
        // exactly one UPDATE affecting 1 row; the loser updates 0 rows.
        $changed = DB::table('gates_donations')
            ->where('payment_ref', $reference)
            ->where('status', 'pending')
            ->update(['status' => 'confirmed']);

        if ($changed > 0) {
            $this->log?->info('[payment] confirmed', ['src' => $source, 'ref' => $reference, 'amount' => $v['amount']]);
            // Post-commit, best-effort (dispatch never throws) — additive to the
            // audited payment path. Reference + amount only; no donor PII.
            \AfricaGates\Services\WebhookService::dispatch('donation.confirmed', [
                'reference' => $reference,
                'amount'    => $v['amount'] ?? null,
                'source'    => $source,
            ]);
            // Paid-vote orders: a confirmed payment auto-mints the votes for the
            // nominee the order was created for. Idempotent (guarded votes_used
            // flip) so the browser callback minting the same order is harmless.
            try {
                $row = DB::table('gates_donations')->where('payment_ref', $reference)->first(['id', 'tier', 'intent_nominee_id']);
                if ($row && ($row->tier ?? '') === 'paid-vote' && !empty($row->intent_nominee_id)) {
                    \AfricaGates\Services\PaidVoteService::mint((int) $row->id);
                }
            } catch (\Throwable $e) {
                $this->log?->error('[payment] paid-vote mint failed', ['ref' => $reference, 'err' => $e->getMessage()]);
            }
            return 'confirmed';
        }

        // 0 rows: someone else confirmed it between our read and write.
        return 'already';
    }

    // ──────────────────────────── webhook helpers ───────────────────────────

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
            $secret = trim((string)($_ENV['PAYSTACK_SECRET_KEY'] ?? ''));
            if ($secret === '') return false;
            $expected = hash_hmac('sha512', $raw, $secret);
            $got      = $req->getHeaderLine('x-paystack-signature');
            return $got !== '' && hash_equals($expected, $got);
        }

        if ($provider === 'flutterwave') {
            $configured = trim((string)($_ENV['FLUTTERWAVE_WEBHOOK_HASH'] ?? ''));
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

    /** True when the webhook event is a refund / chargeback / dispute / reversal. */
    private function webhookIsReversal(array $payload): bool
    {
        $event = strtolower((string)($payload['event'] ?? ($payload['event.type'] ?? ($payload['type'] ?? ''))));
        if ($event === '') return false;
        foreach (['refund', 'dispute', 'chargeback', 'charge.back', 'reversed', 'reversal'] as $needle) {
            if (str_contains($event, $needle)) return true;
        }
        return false;
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
