<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Psr\Log\LoggerInterface;

/**
 * Multi-gateway payment service for Africa GATES (vote packs, tickets, child
 * donations, sponsorships).
 *
 * One thin provider abstraction sits behind two concrete REST integrations —
 * Paystack and Flutterwave — both reached over raw cURL so the app takes on NO
 * new Composer dependency. Adding a third provider (e.g. GTPay/Interswitch) is a
 * matter of adding a key pair to {@see self::PROVIDERS}, an `initialize*()` and a
 * `verify*()` method, and a webhook-signature branch in the controller — the
 * public surface ({@see initialize}, {@see verify}, {@see enabledProviders})
 * does not change.
 *
 * Design rules that matter for security (enforced here + in PaymentController):
 *   - Secret keys live ONLY in $_ENV and never leave the server. Only the
 *     gateway's hosted checkout URL is ever handed to the browser.
 *   - A provider is "enabled" iff its SECRET key is configured, so the UI offers
 *     only gateways that can actually transact.
 *   - {@see verify} is the single source of truth at confirmation time: the
 *     controller re-checks status AND amount server-to-server before crediting,
 *     so a tampered callback can never confirm a payment.
 *
 * Amounts here are always in WHOLE NAIRA at the boundary. Paystack is fed kobo
 * (naira * 100) on the wire and its verified amount is divided back to naira;
 * Flutterwave transacts in naira directly. Callers never deal in kobo.
 *
 * Not `final` so the test harness can subclass it to stub the network boundary
 * ({@see initialize}/{@see verify}/{@see isEnabled}) without hitting live gateways.
 */
class PaymentService
{
    /** Provider id => [secret env key, public env key]. The keymap is the registry. */
    private const PROVIDERS = [
        'paystack'     => ['secret' => 'PAYSTACK_SECRET_KEY',     'public' => 'PAYSTACK_PUBLIC_KEY'],
        'flutterwave'  => ['secret' => 'FLUTTERWAVE_SECRET_KEY',  'public' => 'FLUTTERWAVE_PUBLIC_KEY'],
    ];

    /** Human labels for the checkout UI. */
    private const LABELS = [
        'paystack'    => 'Paystack',
        'flutterwave' => 'Flutterwave',
    ];

    private const TIMEOUT = 15;

    public function __construct(private readonly ?LoggerInterface $log = null) {}

    /** Whether $provider is a provider we know how to talk to. */
    public function isKnownProvider(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    /** A provider is usable only when its SECRET key is present in the environment. */
    public function isEnabled(string $provider): bool
    {
        $keys = self::PROVIDERS[$provider] ?? null;
        if ($keys === null) return false;
        return Env::has($keys['secret']);
    }

    /**
     * Providers that can actually transact right now (secret key configured).
     *
     * @return list<array{id:string,label:string,public_key:string}>
     */
    public function enabledProviders(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $id => $keys) {
            if (!$this->isEnabled($id)) continue;
            $out[] = [
                'id'         => $id,
                'label'      => self::LABELS[$id] ?? ucfirst($id),
                // Public (publishable) key is safe to expose; included for clients
                // that later want inline/popup checkout. Not required for redirect.
                'public_key' => (string) Env::get($keys['public'], ''),
            ];
        }
        return $out;
    }

    /** Just the enabled provider ids (e.g. ['paystack','flutterwave']). */
    public function enabledProviderIds(): array
    {
        return array_map(static fn(array $p) => $p['id'], $this->enabledProviders());
    }

    private function secret(string $provider): string
    {
        $keys = self::PROVIDERS[$provider] ?? null;
        return $keys ? (string) Env::get($keys['secret'], '') : '';
    }

    /**
     * Start a hosted-checkout transaction.
     *
     * @return array{ok:bool,checkout_url:?string,message:string}
     */
    public function initialize(
        string $provider,
        int $amountNaira,
        string $email,
        string $reference,
        string $callbackUrl,
        array $meta = []
    ): array {
        if (!$this->isEnabled($provider)) {
            return ['ok' => false, 'checkout_url' => null, 'message' => 'Payment provider is not available.'];
        }
        if ($amountNaira < 1) {
            return ['ok' => false, 'checkout_url' => null, 'message' => 'Invalid amount.'];
        }

        try {
            return match ($provider) {
                'paystack'    => $this->initializePaystack($amountNaira, $email, $reference, $callbackUrl, $meta),
                'flutterwave' => $this->initializeFlutterwave($amountNaira, $email, $reference, $callbackUrl, $meta),
                default       => ['ok' => false, 'checkout_url' => null, 'message' => 'Unknown provider.'],
            };
        } catch (\Throwable $e) {
            $this->log?->error('[payment] initialize error', ['provider' => $provider, 'ref' => $reference, 'err' => $e->getMessage()]);
            return ['ok' => false, 'checkout_url' => null, 'message' => 'Could not reach the payment provider.'];
        }
    }

    /**
     * Verify a transaction server-to-server. The ONLY trustworthy view of whether
     * money moved — callers must check both `status==='success'` AND that `amount`
     * matches the expected naira figure before confirming anything.
     *
     * @return array{ok:bool,status:string,amount:int,currency:string,meta:array,message?:string}
     */
    public function verify(string $provider, string $reference): array
    {
        $fail = static fn(string $m): array => [
            'ok' => false, 'status' => 'pending', 'amount' => 0, 'currency' => '', 'meta' => [], 'message' => $m,
        ];

        if (!$this->isEnabled($provider)) return $fail('Payment provider is not available.');
        if ($reference === '')           return $fail('Missing payment reference.');

        try {
            return match ($provider) {
                'paystack'    => $this->verifyPaystack($reference),
                'flutterwave' => $this->verifyFlutterwave($reference),
                default       => $fail('Unknown provider.'),
            };
        } catch (\Throwable $e) {
            $this->log?->error('[payment] verify error', ['provider' => $provider, 'ref' => $reference, 'err' => $e->getMessage()]);
            return $fail('Could not verify the payment.');
        }
    }

    /**
     * Give the money back.
     *
     * ── THE ONLY OUTBOUND MONEY MOVEMENT IN THIS CODEBASE ────────────────────
     *
     * Everything else here takes payment or reads its status. This hands cash
     * back, so it is deliberately dumb: it does what it is told, to one
     * reference, and it decides nothing. Whether a refund is OWED is a question
     * about votes, cycles and ceilings, and it is answered in
     * {@see RefundService} where it can be read and tested on its own.
     *
     * `pending` is a real and common outcome, not a failure. Both gateways queue
     * a refund and settle it hours later, so a caller that treats anything but
     * `refunded` as an error will retry a refund that is already on its way —
     * which is how somebody gets paid back twice.
     *
     * @return array{ok:bool, status:'refunded'|'pending'|'failed', message:string, provider_ref:?string}
     */
    public function refund(string $provider, string $reference, ?int $amountNaira = null): array
    {
        $fail = static fn(string $m): array =>
            ['ok' => false, 'status' => 'failed', 'message' => $m, 'provider_ref' => null];

        if (!$this->isEnabled($provider)) return $fail('Payment provider is not available.');
        if (trim($reference) === '')      return $fail('Missing payment reference.');
        if ($amountNaira !== null && $amountNaira < 1) return $fail('Refund amount must be positive.');

        try {
            return match ($provider) {
                'paystack'    => $this->refundPaystack($reference, $amountNaira),
                'flutterwave' => $this->refundFlutterwave($reference, $amountNaira),
                default       => $fail('Unknown provider.'),
            };
        } catch (\Throwable $e) {
            $this->log?->error('[payment] refund error', ['provider' => $provider, 'ref' => $reference, 'err' => $e->getMessage()]);
            // NOT 'failed'. A thrown exception means we do not know whether the
            // gateway accepted it, and reporting a definite failure for an unknown
            // outcome is what makes a caller retry into a double refund.
            return ['ok' => false, 'status' => 'pending', 'provider_ref' => null,
                    'message' => 'Could not reach the gateway. The refund may or may not have been accepted — check before retrying.'];
        }
    }

    // ─────────────────────────────── Paystack ───────────────────────────────

    private function initializePaystack(int $amountNaira, string $email, string $reference, string $callbackUrl, array $meta): array
    {
        // Paystack transacts in KOBO.
        $payload = [
            'amount'       => $amountNaira * 100,
            'email'        => $email,
            'reference'    => $reference,
            'callback_url' => $callbackUrl,
            'currency'     => 'NGN',
            'metadata'     => $meta,
        ];
        $res = $this->request(
            'POST',
            'https://api.paystack.co/transaction/initialize',
            $payload,
            ['Authorization: Bearer ' . $this->secret('paystack')]
        );
        $body = $res['json'];
        $url  = $body['data']['authorization_url'] ?? null;

        if ($res['ok'] && ($body['status'] ?? false) === true && is_string($url) && $url !== '') {
            return ['ok' => true, 'checkout_url' => $url, 'message' => 'ok'];
        }
        $msg = (string)($body['message'] ?? 'Paystack initialization failed.');
        $this->log?->warning('[payment] paystack init failed', ['ref' => $reference, 'http' => $res['code'], 'msg' => $msg]);
        return ['ok' => false, 'checkout_url' => null, 'message' => $msg];
    }

    private function verifyPaystack(string $reference): array
    {
        $res  = $this->request(
            'GET',
            'https://api.paystack.co/transaction/verify/' . rawurlencode($reference),
            null,
            ['Authorization: Bearer ' . $this->secret('paystack')]
        );
        $body = $res['json'];
        $data = $body['data'] ?? [];

        if (!$res['ok'] || ($body['status'] ?? false) !== true || !is_array($data) || $data === []) {
            return ['ok' => false, 'status' => 'pending', 'amount' => 0, 'currency' => '', 'meta' => [],
                    'message' => (string)($body['message'] ?? 'Verification failed.')];
        }

        // Paystack status: 'success' | 'failed' | 'abandoned' | 'reversed' | …
        $raw    = strtolower((string)($data['status'] ?? ''));
        $status = $raw === 'success' ? 'success' : ($raw === 'failed' ? 'failed' : 'pending');
        // amount comes back in KOBO → whole naira.
        $amount = (int) round(((int)($data['amount'] ?? 0)) / 100);

        return [
            'ok'       => true,
            'status'   => $status,
            'amount'   => $amount,
            'currency' => (string)($data['currency'] ?? 'NGN'),
            'meta'     => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        ];
    }

    /**
     * Paystack takes the transaction REFERENCE directly, and amounts in kobo.
     * Omitting the amount refunds the full charge, which is what we want for an
     * order that delivered nothing.
     */
    private function refundPaystack(string $reference, ?int $amountNaira): array
    {
        $payload = ['transaction' => $reference];
        if ($amountNaira !== null) $payload['amount'] = $amountNaira * 100;

        $res  = $this->request('POST', 'https://api.paystack.co/refund', $payload,
            ['Authorization: Bearer ' . $this->secret('paystack')]);
        $body = $res['json'];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if (!$res['ok'] || ($body['status'] ?? false) !== true) {
            $msg = (string) ($body['message'] ?? 'Refund was refused.');
            // Already refunded is a SUCCESS from our side: the money is back with
            // the buyer, which is the outcome the caller wanted. Treating it as a
            // failure leaves the row unmarked and invites another attempt.
            if (stripos($msg, 'already') !== false && stripos($msg, 'refund') !== false) {
                return ['ok' => true, 'status' => 'refunded', 'message' => 'Already refunded at the gateway.',
                        'provider_ref' => null];
            }
            $this->log?->warning('[payment] paystack refund refused', ['ref' => $reference, 'http' => $res['code'], 'msg' => $msg]);
            return ['ok' => false, 'status' => 'failed', 'message' => $msg, 'provider_ref' => null];
        }

        // Paystack refund status: 'pending' | 'processing' | 'processed' | 'failed'
        $raw = strtolower((string) ($data['status'] ?? 'pending'));
        return [
            'ok'           => $raw !== 'failed',
            'status'       => $raw === 'processed' ? 'refunded' : ($raw === 'failed' ? 'failed' : 'pending'),
            'message'      => (string) ($body['message'] ?? 'Refund submitted.'),
            'provider_ref' => isset($data['id']) ? (string) $data['id'] : null,
        ];
    }

    // ───────────────────────────── Flutterwave ──────────────────────────────

    private function initializeFlutterwave(int $amountNaira, string $email, string $reference, string $callbackUrl, array $meta): array
    {
        $payload = [
            'tx_ref'       => $reference,
            'amount'       => $amountNaira,
            'currency'     => 'NGN',
            'redirect_url' => $callbackUrl,
            'customer'     => ['email' => $email],
            'meta'         => $meta,
        ];
        $res  = $this->request(
            'POST',
            'https://api.flutterwave.com/v3/payments',
            $payload,
            ['Authorization: Bearer ' . $this->secret('flutterwave')]
        );
        $body = $res['json'];
        $url  = $body['data']['link'] ?? null;

        if ($res['ok'] && ($body['status'] ?? '') === 'success' && is_string($url) && $url !== '') {
            return ['ok' => true, 'checkout_url' => $url, 'message' => 'ok'];
        }
        $msg = (string)($body['message'] ?? 'Flutterwave initialization failed.');
        $this->log?->warning('[payment] flutterwave init failed', ['ref' => $reference, 'http' => $res['code'], 'msg' => $msg]);
        return ['ok' => false, 'checkout_url' => null, 'message' => $msg];
    }

    private function verifyFlutterwave(string $reference): array
    {
        $res  = $this->request(
            'GET',
            'https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference),
            null,
            ['Authorization: Bearer ' . $this->secret('flutterwave')]
        );
        $body = $res['json'];
        $data = $body['data'] ?? [];

        if (!$res['ok'] || ($body['status'] ?? '') !== 'success' || !is_array($data) || $data === []) {
            return ['ok' => false, 'status' => 'pending', 'amount' => 0, 'currency' => '', 'meta' => [],
                    'message' => (string)($body['message'] ?? 'Verification failed.')];
        }

        // Flutterwave charge status: 'successful' | 'failed' | 'pending'
        $raw    = strtolower((string)($data['status'] ?? ''));
        $status = $raw === 'successful' ? 'success' : ($raw === 'failed' ? 'failed' : 'pending');
        // 'amount_settled' may be net of fees; the gross 'amount' is what the buyer
        // was charged and is what we reconcile against the price table.
        $amount = (int) round((float)($data['amount'] ?? 0));

        return [
            'ok'       => true,
            'status'   => $status,
            'amount'   => $amount,
            'currency' => (string)($data['currency'] ?? 'NGN'),
            'meta'     => is_array($data['meta'] ?? null) ? $data['meta'] : [],
        ];
    }

    /**
     * Flutterwave refunds by NUMERIC TRANSACTION ID, not by our tx_ref — so this
     * costs two calls: resolve the id, then refund it. Worth naming, because a
     * refund that silently no-ops on a 404 is indistinguishable from one that
     * worked until somebody complains a week later.
     */
    private function refundFlutterwave(string $reference, ?int $amountNaira): array
    {
        $look = $this->request('GET',
            'https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference),
            null, ['Authorization: Bearer ' . $this->secret('flutterwave')]);
        $txId = $look['json']['data']['id'] ?? null;

        if (!$look['ok'] || !$txId) {
            return ['ok' => false, 'status' => 'failed', 'provider_ref' => null,
                    'message' => 'Flutterwave does not recognise that reference, so nothing was refunded.'];
        }

        $res  = $this->request('POST',
            'https://api.flutterwave.com/v3/transactions/' . rawurlencode((string) $txId) . '/refund',
            $amountNaira !== null ? ['amount' => $amountNaira] : [],
            ['Authorization: Bearer ' . $this->secret('flutterwave')]);
        $body = $res['json'];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if (!$res['ok'] || ($body['status'] ?? '') !== 'success') {
            $msg = (string) ($body['message'] ?? 'Refund was refused.');
            $this->log?->warning('[payment] flutterwave refund refused', ['ref' => $reference, 'http' => $res['code'], 'msg' => $msg]);
            return ['ok' => false, 'status' => 'failed', 'message' => $msg, 'provider_ref' => null];
        }

        // Flutterwave refund status: 'completed' | 'pending' | 'failed'
        $raw = strtolower((string) ($data['status'] ?? 'pending'));
        return [
            'ok'           => $raw !== 'failed',
            'status'       => $raw === 'completed' ? 'refunded' : ($raw === 'failed' ? 'failed' : 'pending'),
            'message'      => (string) ($body['message'] ?? 'Refund submitted.'),
            'provider_ref' => isset($data['id']) ? (string) $data['id'] : null,
        ];
    }

    // ─────────────────────────────── transport ──────────────────────────────

    /**
     * Single cURL chokepoint. JSON in, JSON out, 15s timeout, TLS verified.
     *
     * @return array{ok:bool,code:int,json:array,raw:string}
     */
    private function request(string $method, string $url, ?array $jsonBody, array $headers): array
    {
        $ch = curl_init();
        $headers = array_merge($headers, ['Accept: application/json']);

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR    => false, // we read 4xx bodies for error messages
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody ?? [], JSON_UNESCAPED_SLASHES);
            $headers[]                = 'Content-Type: application/json';
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            // Transport failure (DNS, TLS, timeout) — surface as a thrown error so
            // initialize()/verify() log it and return a safe failure to the caller.
            throw new \RuntimeException('cURL transport error: ' . $err);
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) $json = [];

        // 2xx is "ok" at the transport layer; provider-level success is judged by
        // each method against the decoded body.
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'json' => $json, 'raw' => (string)$raw];
    }
}
