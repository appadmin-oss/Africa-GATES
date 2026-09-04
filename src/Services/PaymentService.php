<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
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

    /**
     * How long a payment may still legitimately be MOVING.
     *
     * ── ONE FACT, BECAUSE SEVERAL PLACES WERE GUESSING IT SEPARATELY ─────────
     *
     * Not every payment here is a card. A bank transfer settles when the bank
     * feels like it, USSD involves a person walking through a menu on a feature
     * phone, and a wallet app switch can strand a buyer on a network that drops.
     * Thirty to ninety minutes is ordinary, not pathological.
     *
     * Anything that declares a pending checkout DEAD — an abandoned-cart nudge, a
     * reconciliation tombstone — must sit outside this window, or it is telling
     * somebody they did not pay while they are in the middle of paying. Each of
     * those places used to pick its own number; this is the number.
     *
     * DELIBERATELY NOT the same constant as {@see GatewayHandoff}'s TTL, which
     * governs a stored checkout URL. That URL is a bearer capability for a live
     * payment session, and "how patient we are with a slow bank" and "how long a
     * capability may sit in a session" are different questions that must be
     * allowed to have different answers. Tolerance growing must never drag a
     * credential's lifetime along with it.
     */
    public const IN_FLIGHT_MINUTES = 120;

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
        array $meta = [],
        // ── A RECURRING PLAN, OR '' FOR A ONE-OFF ────────────────────────────
        //
        // Last and optional so every existing call site is untouched — this is the payment
        // path for tickets, the shop and vote packs as well as donations, and a required
        // parameter here would have been a change to all of them for a feature none of them
        // has. Unknown to Flutterwave, which has payment plans of its own shape; a monthly
        // gift is only offered where it can actually be honoured (see RecurringGiving).
        string $planCode = ''
    ): array {
        if (!$this->isEnabled($provider)) {
            return ['ok' => false, 'checkout_url' => null, 'message' => 'Payment provider is not available.'];
        }
        if ($amountNaira < 1) {
            return ['ok' => false, 'checkout_url' => null, 'message' => 'Invalid amount.'];
        }

        try {
            $r = match ($provider) {
                'paystack'    => $this->initializePaystack($amountNaira, $email, $reference, $callbackUrl, $meta, $planCode),
                'flutterwave' => $this->initializeFlutterwave($amountNaira, $email, $reference, $callbackUrl, $meta),
                default       => ['ok' => false, 'checkout_url' => null, 'message' => 'Unknown provider.'],
            };
            if (!($r['ok'] ?? false)) {
                self::recordStartFailure($provider, $reference, (string) ($r['message'] ?? ''), null);
            }
            return $r;
        } catch (\Throwable $e) {
            $this->log?->error('[payment] initialize error', ['provider' => $provider, 'ref' => $reference, 'err' => $e->getMessage()]);
            self::recordStartFailure($provider, $reference, $e->getMessage(), $e);
            return ['ok' => false, 'checkout_url' => null, 'message' => 'Could not reach the payment provider.'];
        }
    }

    /**
     * Write down WHY a checkout could not start, somewhere an operator can read.
     *
     * ══════════════════════════════════════════════════════════════════════════════
     * THE BLIND SPOT THIS CLOSES
     * ══════════════════════════════════════════════════════════════════════════════
     *
     * "The payments are failing to start" is the hardest fault on this platform to diagnose,
     * and the reason is structural rather than accidental: a checkout that cannot start is
     * NOT an exception. {@see initialize()} catches everything and returns `ok => false`,
     * which is correct — a buyer must get a sentence, not a stack trace — and it means the
     * cause never reaches {@see \AfricaGates\Handlers\ErrorHandler}, which is the only thing
     * that writes anywhere an operator can reach without a shell.
     *
     * So the symptom was visible to everyone and the cause was visible to nobody. The gateway
     * had usually said something perfectly clear — "Invalid key", "Subaccount not found" —
     * and it went into a Monolog channel on a host where nobody can open one.
     *
     * This appends to the SAME file the error handler uses, in the same format, so
     * `/__setup/errors` lists these alongside real crashes with no extra plumbing and no
     * migration. It is a diagnostic of last resort and behaves like one: no database, no
     * mailer, no exceptions of its own.
     *
     * ── AND IT DOES NOT WRITE THE BUYER'S DETAILS ────────────────────────────
     *
     * The reference and the gateway's own words, nothing else. An email address in a log file
     * that a token unlocks is a disclosure risk that buys nothing: the reference identifies
     * the order, and the order has the email.
     */
    private static function recordStartFailure(string $provider, string $reference,
                                               string $why, ?\Throwable $e): void
    {
        try {
            $dir = dirname(__DIR__, 2) . '/var/logs';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $entry = '[' . date('c') . '] CheckoutCouldNotStart: ' . $provider
                   . ' refused ' . $reference . ' — ' . mb_substr(trim($why), 0, 400);
            if ($e !== null) {
                $entry .= ' in ' . $e->getFile() . ':' . $e->getLine()
                        . "\n" . get_class($e) . "\n" . $e->getTraceAsString();
            } else {
                // No exception means the GATEWAY said no rather than the code falling over,
                // and that distinction is the first thing a person needs.
                $entry .= "\n(the gateway answered and refused — this is its own message, "
                        . 'not a crash on our side)';
            }
            @file_put_contents($dir . '/error-detail.log', $entry . "\n\n", FILE_APPEND);
        } catch (\Throwable) { /* a diagnostic must never be the thing that breaks */ }
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
     * Every transaction the GATEWAY has, for a window — not every one we have.
     *
     * ── WHY THIS EXISTS: EVERY OTHER CALL STARTS FROM A ROW OF OURS ──────────
     *
     * `initialize()`, `verify()` and `refund()` all take a reference we minted,
     * which means the entire money picture in this codebase is drawn from
     * `gates_donations`. That is fine for a payment we started and mis-handled —
     * {@see PaymentTriage} finds those. It is structurally blind to the worse
     * case: a charge that exists at Paystack with NO row here at all. A person
     * paid, our insert failed or never ran, and there is nothing on this side to
     * start a query from. Nobody is looking for them because nothing knows they
     * exist.
     *
     * This is the only call that starts on the other side, so it is the only way
     * that bucket can ever be seen. {@see GatewayLedger} does the comparison.
     *
     * ── PAGINATION, AND WHY IT IS NOT CURSOR-BASED HERE ──────────────────────
     *
     * `GET /transaction` supports two pagers. Passing `use_cursor=true` returns a
     * `meta.next` cursor; without it you get classic offset paging with
     * `meta.pageCount`. We use the classic one deliberately: a cursor is only
     * worth its complexity when the set is being written to while you page it,
     * and this walks a CLOSED window (`from`/`to` are both in the past), so the
     * page numbering cannot shift under us. `meta.pageCount` is honoured when the
     * account returns it, and a short page ends the walk when it does not — both
     * shapes are in the wild.
     *
     * ── IT REFUSES TO WALK FOREVER, AND SAYS SO ──────────────────────────────
     *
     * `truncated` is returned rather than logged, because a reconciliation screen
     * that quietly stopped at page 20 would render "nothing unmatched" over a
     * window it never finished reading. A partial answer must know it is partial.
     *
     * Paystack only. Flutterwave's equivalent is a different endpoint with a
     * different envelope, and guessing it from the shape of this one would produce
     * a page that silently reconciles against nothing.
     *
     * @param array{status?:string,from?:string,to?:string,perPage?:int,maxPages?:int} $filter
     * @return array{ok:bool,message:string,transactions:list<array>,truncated:bool,pages:int}
     */
    public function listTransactions(string $provider, array $filter = []): array
    {
        $fail = static fn (string $m): array =>
            ['ok' => false, 'message' => $m, 'transactions' => [], 'truncated' => false, 'pages' => 0];

        if ($provider !== 'paystack') {
            return $fail('Listing transactions is only implemented for Paystack. '
                       . 'Flutterwave exposes a different endpoint and is not read here.');
        }
        if (!$this->isEnabled($provider)) return $fail('Paystack is not configured in this environment.');

        $perPage  = max(1, min(100, (int) ($filter['perPage'] ?? 100)));
        $maxPages = max(1, min(200, (int) ($filter['maxPages'] ?? 20)));

        $query = ['perPage' => $perPage];
        foreach (['status', 'from', 'to'] as $k) {
            if (isset($filter[$k]) && (string) $filter[$k] !== '') $query[$k] = (string) $filter[$k];
        }

        $out   = [];
        $page  = 1;
        $seen  = [];

        try {
            while ($page <= $maxPages) {
                $res = $this->request(
                    'GET',
                    'https://api.paystack.co/transaction?' . http_build_query($query + ['page' => $page]),
                    null,
                    ['Authorization: Bearer ' . $this->secret('paystack')]
                );
                $body = $res['json'];

                if (!$res['ok'] || ($body['status'] ?? false) !== true || !is_array($body['data'] ?? null)) {
                    // A failure MID-WALK still returns what we already read, flagged
                    // truncated. Throwing away four good pages because the fifth
                    // timed out would hide four pages of real charges.
                    $msg = (string) ($body['message'] ?? 'Paystack would not list transactions.');
                    if ($out === []) return $fail($msg);
                    return ['ok' => true, 'message' => 'Stopped early: ' . $msg,
                            'transactions' => $out, 'truncated' => true, 'pages' => $page - 1];
                }

                $rows = $body['data'];
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    $t = self::normalisePaystackTransaction($r);
                    // Paging a window can repeat a row at a boundary; a duplicate
                    // charge in a reconciliation total is a fictional charge.
                    $key = $t['reference'] !== '' ? 'r:' . $t['reference'] : 'i:' . $t['id'];
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $out[] = $t;
                }

                $meta      = is_array($body['meta'] ?? null) ? $body['meta'] : [];
                $pageCount = isset($meta['pageCount']) ? (int) $meta['pageCount'] : null;

                $more = $pageCount !== null ? ($page < $pageCount) : (count($rows) >= $perPage);
                if (!$more) {
                    return ['ok' => true, 'message' => 'ok', 'transactions' => $out,
                            'truncated' => false, 'pages' => $page];
                }
                $page++;
            }
        } catch (\Throwable $e) {
            $this->log?->error('[payment] paystack list error', ['err' => $e->getMessage()]);
            if ($out === []) return $fail('Could not reach Paystack to list transactions.');
            return ['ok' => true, 'message' => 'Stopped early: could not reach Paystack.',
                    'transactions' => $out, 'truncated' => true, 'pages' => $page - 1];
        }

        return ['ok' => true, 'message' => 'ok', 'transactions' => $out,
                'truncated' => true, 'pages' => $maxPages];
    }

    /**
     * One Paystack transaction object → the shape the rest of this codebase uses.
     *
     * Amounts land in WHOLE NAIRA here, exactly as {@see verifyPaystack} does it,
     * so that a figure from the list and a figure from a verify can be compared
     * without either caller remembering which one is in kobo.
     */
    private static function normalisePaystackTransaction(array $r): array
    {
        $raw = strtolower((string) ($r['status'] ?? ''));

        return [
            'provider'   => 'paystack',
            'id'         => (int) ($r['id'] ?? 0),
            'reference'  => (string) ($r['reference'] ?? ''),
            // 'success' | 'failed' | 'abandoned' | 'reversed' | 'ongoing' | 'pending'
            'status'     => $raw !== '' ? $raw : 'unknown',
            'paid'       => $raw === 'success',
            'amount'     => (int) round(((int) ($r['amount'] ?? 0)) / 100),
            'fees'       => (int) round(((int) ($r['fees'] ?? 0)) / 100),
            'currency'   => (string) ($r['currency'] ?? 'NGN'),
            'channel'    => (string) ($r['channel'] ?? ''),
            'email'      => (string) ($r['customer']['email'] ?? ''),
            'name'       => trim((string) ($r['customer']['first_name'] ?? '') . ' '
                               . (string) ($r['customer']['last_name'] ?? '')),
            'paid_at'    => (string) ($r['paid_at'] ?? $r['paidAt'] ?? ''),
            'created_at' => (string) ($r['created_at'] ?? $r['createdAt'] ?? ''),
            'gateway_response' => (string) ($r['gateway_response'] ?? ''),
        ];
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
     * ── AND IT SAYS WHETHER "NO" IS WORTH ASKING AGAIN ───────────────────────
     *
     * `retryable` classifies the refusal, because "the gateway said no" is two
     * completely different events wearing the same word:
     *
     *   true   The gateway could not do it JUST NOW. An insufficient settlement
     *          balance is the common one here and it is a clock problem, not a
     *          decision: Nigerian card settlement is T+1, so the refund refused at
     *          09:00 very often succeeds by itself that evening. A person told
     *          about it has nothing to do but wait.
     *   false  The gateway will never do it. An unknown reference, a transaction
     *          past the refundable age, a revoked key, a permission that is not
     *          granted. Waiting cannot help, so every retry is noise and every
     *          hour of retrying is an hour a person has not been told.
     *   null   Unrecognised. Not assumed either way.
     *
     * Classified HERE and not in the caller, because this is the layer that has
     * the gateway's own words and its HTTP status. {@see RefundService} then makes
     * a policy decision from a typed signal instead of grepping prose, and adding
     * a provider does not mean teaching a second class to read its error strings.
     *
     * ── $amountNaira: LEAVE IT NULL UNLESS YOU MEAN A PARTIAL REFUND ─────────
     *
     * Both gateways treat a supplied amount as "refund exactly this much". Passing a
     * figure of our own therefore asks the gateway to trust our arithmetic over its
     * own record of what it collected, and it fails asymmetrically: too high is
     * refused outright ("exceeds"), while too LOW succeeds quietly and leaves the
     * buyer short with every column reading `refunded`. Null means "return the whole
     * transaction", which is the only correct instruction when nothing was delivered.
     *
     * `amount_naira` in the reply is what the gateway says went back — null when it
     * did not say, so a caller can distinguish an unknown from a figure we invented.
     *
     * @return array{ok:bool, status:'refunded'|'pending'|'failed', message:string,
     *               provider_ref:?string, retryable?:?bool, amount_naira?:?int}
     */
    public function refund(string $provider, string $reference, ?int $amountNaira = null): array
    {
        // A refusal WE generate before any network call is permanent by
        // construction: no gateway has been asked, and nothing about waiting
        // changes an unconfigured provider or a missing reference.
        $fail = static fn(string $m): array =>
            ['ok' => false, 'status' => 'failed', 'message' => $m, 'provider_ref' => null,
             'retryable' => false, 'amount_naira' => null];

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
            return ['ok' => false, 'status' => 'pending', 'provider_ref' => null, 'retryable' => null,
                    'amount_naira' => null,
                    'message' => 'Could not reach the gateway. The refund may or may not have been accepted — check before retrying.'];
        }
    }

    /**
     * Will waiting help?
     *
     * ── WHY PHRASE MATCHING, AND WHY THAT IS ACCEPTABLE HERE ─────────────────
     *
     * Neither gateway returns a machine-readable refusal code on the refund
     * endpoint — Paystack and Flutterwave both answer with `message` written for a
     * human. So the choice is between reading those words and treating every "no"
     * identically, and treating them identically is what produced a day of pointless
     * retries on a revoked API key.
     *
     * The classification is deliberately CONSERVATIVE in one direction: an
     * unrecognised message returns null, not a guess. Null gets a short bounded
     * schedule rather than either extreme, so a gateway that rewords an error never
     * causes an infinite loop and never silently abandons a refund that is owed.
     *
     * HTTP status is consulted first because it is the one structured signal both
     * gateways do give: 5xx and 429 are the gateway's own admission that this was
     * about capacity rather than about the request.
     */
    private static function classifyRefusal(int $httpCode, string $message): ?bool
    {
        if ($httpCode >= 500 || $httpCode === 429 || $httpCode === 408) return true;

        $m = mb_strtolower($message);

        // Permanent FIRST. "insufficient permission" contains "insufficient", and
        // reading it as an insufficient balance would retry a revoked key for a day.
        foreach ([
            'not found', 'does not exist', 'unknown transaction', 'invalid transaction',
            'invalid key', 'invalid secret', 'unauthor', 'forbidden', 'permission', 'not allowed',
            'not permitted', 'disabled', 'too old', 'no longer', 'cannot be refunded',
            'not refundable', 'exceeds', 'greater than', 'more than the', 'currency',
        ] as $needle) {
            if (str_contains($m, $needle)) return false;
        }

        // Transient: the gateway is telling us to come back.
        foreach ([
            'insufficient', 'balance', 'try again', 'temporar', 'timeout', 'timed out',
            'unavailable', 'rate limit', 'too many', 'busy', 'settlement', 'processing',
            'in progress', 'later',
        ] as $needle) {
            if (str_contains($m, $needle)) return true;
        }

        return null;   // unrecognised — say so rather than guess
    }

    // ─────────────────────────────── Paystack ───────────────────────────────

    private function initializePaystack(int $amountNaira, string $email, string $reference, string $callbackUrl, array $meta, string $planCode = ''): array
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

        // ── WITH A PLAN, THIS CHECKOUT BECOMES A STANDING ARRANGEMENT ────────
        //
        // Paystack charges the first instalment now and creates the subscription itself, so
        // there is no second call and no window in which the donor has paid and the
        // arrangement does not exist. `amount` stays on the payload deliberately: Paystack
        // takes the PLAN's amount when both are present, and sending them apart would let a
        // future edit change what the donor was shown without changing what they are billed.
        if ($planCode !== '') $payload['plan'] = $planCode;

        // ── WHICH SUBACCOUNT THIS MONEY SETTLES INTO ─────────────────────────
        //
        // Ticket money, shop money and vote money are three different kinds of money to whoever
        // has to account for them, and settling all three into one account means "how much of
        // this is ticket income" can only be answered from our own records and hoped to agree
        // with the bank. See PaymentDestination.
        //
        // The stream is read from the REFERENCE rather than passed down through five call sites,
        // so the attribution cannot disagree with the reference the gateway knows the payment by.
        // Unconfigured returns an EMPTY ARRAY, so merging it changes nothing about the request
        // that goes out — an operator who never opens that screen sees no change at all.
        //
        // ── AND THE WHOLE BLOCK IS BEST-EFFORT ───────────────────────────────
        //
        // Reading a setting, validating a code and writing an attribution row are three
        // database touches standing between a buyer and a checkout URL. Each one is guarded
        // internally, but the guarantee that matters is the one made here: if ANY of it
        // throws, routing is abandoned and the payment goes out unrouted. Money settling to
        // the main account is a bookkeeping problem somebody can fix next week. A buyer who
        // cannot pay is a lost sale today, and this feature is not worth a single one of them.
        $stream = '';
        $route  = [];
        try {
            // A donation to a PARTNER ORGANISATION settles into that organisation's own
            // subaccount rather than into one of the three platform streams. Checked first,
            // and recorded under its own stream name so a partner's money is never counted
            // as platform donation income on the finance screen.
            $orgId = PaymentDestination::partnerOrgIdForReference($reference);
            if ($orgId > 0) {
                $route = PaymentDestination::initFieldsForPartner($orgId);
                // Only claim the partner stream if the routing actually resolved. A suspended
                // partner falls through to an empty route and the payment settles to the main
                // account, where it is visible and refundable.
                $stream = $route !== [] ? 'partner:' . $orgId : '';
            } else {
                $stream = PaymentDestination::streamForReference($reference);
                $route  = $stream !== '' ? PaymentDestination::initFields($stream) : [];
            }

            if ($route !== []) {
                // Recorded on the row, not derived later from the settings — settings change,
                // and an order that silently re-attributed itself the moment somebody edited
                // the field would stop matching the bank. Same doctrine as money columns
                // being written once.
                $this->rememberDestination($reference, $stream, (string) $route['subaccount'],
                                           (string) ($route['bearer'] ?? 'account'), $amountNaira);
            }
        } catch (\Throwable $e) {
            $this->log?->error('[payment] subaccount routing failed — sending unrouted', [
                'ref' => $reference, 'err' => $e->getMessage(),
            ]);
            $route = [];
        }
        $payload += $route;

        $r = $this->postInitialize($payload, $reference);
        if ($r['ok']) {
            return $r;
        }

        // ══ REFUSING TO ROUTE IS RECOVERABLE. REFUSING TO SELL IS NOT. ══════════
        //
        // {@see PaymentDestination} states that rule and did not implement it. It validates
        // the SHAPE of a subaccount code, which catches a pasted bank account number and
        // catches nothing else — and a shape-valid code that belongs to another Paystack
        // account, or has been deleted, or was never activated, is rejected by Paystack at
        // initialise. Every payment on that stream then dies here.
        //
        // The failure is invisible in exactly the wrong way. The buyer sees "we could not
        // start the payment"; the operator sees nothing at all, because the only trace was a
        // log line on a host with no shell. And it is per-stream, so the shop and the events
        // page can both be dead while votes — the stream somebody is most likely to test —
        // works perfectly. That is the shape of the reported outage.
        //
        // So: one retry, WITHOUT the routing. The money lands in the main account, which is
        // where it landed before anybody configured a subaccount and is a bookkeeping problem
        // rather than a lost sale. The attribution row is corrected to say so, and somebody is
        // told, loudly, with Paystack's own words in the message.
        if ($route !== []) {
            $this->log?->error('[payment] paystack refused the subaccount — retrying unrouted', [
                'ref' => $reference, 'stream' => $stream,
                'subaccount' => (string) $route['subaccount'],
                'msg' => (string) $r['message'],
            ]);

            unset($payload['subaccount'], $payload['bearer']);
            $retry = $this->postInitialize($payload, $reference);

            if ($retry['ok']) {
                // ── BOOKKEEPING MUST NOT BE ABLE TO UNDO A SALE ──────────────
                //
                // The retry has succeeded: there is a live checkout URL in hand and a buyer
                // waiting for it. Everything after this point is record-keeping — clearing an
                // attribution row, storing a refusal, sending an alert — and every one of
                // those touches the database or the mailer.
                //
                // Without this guard a throw in any of them would escape into
                // {@see initialize()}'s catch, which turns the whole call into "could not
                // reach the payment provider" and discards the URL. That would take a
                // RECOVERED failure and make it a hard one — precisely inverting the point of
                // the fallback, and doing it only on sites that have subaccounts configured,
                // which is the hardest possible place to notice.
                try {
                    $this->forgetDestination($reference);
                    PaymentDestination::reportRefusal($stream, (string) $route['subaccount'],
                                                      (string) $r['message']);
                } catch (\Throwable $e) {
                    $this->log?->error('[payment] could not record the subaccount refusal',
                                       ['ref' => $reference, 'err' => $e->getMessage()]);
                }
                return $retry;
            }
            // Both attempts failed, so the subaccount was not the problem. Report the FIRST
            // message: it is the one that describes the request we actually meant to make.
        }

        return $r;
    }

    /**
     * One initialise attempt. Split out so the subaccount fallback above can make two without
     * duplicating the response reading — which is where the kobo lives.
     *
     * @return array{ok:bool,checkout_url:?string,message:string}
     */
    private function postInitialize(array $payload, string $reference): array
    {
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
        $msg = (string) ($body['message'] ?? 'Paystack initialization failed.');
        $this->log?->warning('[payment] paystack init failed',
                             ['ref' => $reference, 'http' => $res['code'], 'msg' => $msg]);
        return ['ok' => false, 'checkout_url' => null, 'message' => $msg];
    }

    /**
     * Does Paystack agree this subaccount exists, on THIS account, right now?
     *
     * ── THE CHECK THAT SHOULD HAVE EXISTED BEFORE THE FEATURE SHIPPED ────────
     *
     * A subaccount code is validated locally for shape and nothing else, so the settings
     * screen accepted a code from a different Paystack integration, a deleted one, or one
     * typed with a transposed character — and the first anybody learned of it was a stream
     * that had silently stopped selling.
     *
     * Asking Paystack costs one call at the moment somebody presses Save, which is exactly
     * when a person is present to read the answer. Its own words are returned, because
     * "Subaccount not found" tells an operator what to do and "invalid" does not.
     *
     * @return array{ok:bool, message:string, name:string, bank:string}
     */
    public function subaccount(string $code): array
    {
        $code = trim($code);
        if ($code === '')                  return ['ok' => false, 'message' => 'No subaccount code given.', 'name' => '', 'bank' => ''];
        if (!$this->isEnabled('paystack')) return ['ok' => false, 'message' => 'Paystack is not configured in this environment.', 'name' => '', 'bank' => ''];

        try {
            $res = $this->request('GET', 'https://api.paystack.co/subaccount/' . rawurlencode($code),
                                  null, $this->paystackAuth());
        } catch (\Throwable $e) {
            // NOT a refusal. We could not ask, which is a different answer from "no" — and
            // treating an outage as a bad code would refuse a save that was perfectly correct.
            return ['ok' => false, 'message' => 'Paystack could not be reached to check that code: '
                                              . $e->getMessage(), 'name' => '', 'bank' => ''];
        }

        $body = $res['json'];
        $d    = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (!$res['ok'] || ($body['status'] ?? false) !== true || $d === []) {
            return ['ok' => false, 'name' => '', 'bank' => '',
                    'message' => (string) ($body['message'] ?? 'Paystack does not recognise that subaccount.')];
        }

        // An inactive subaccount resolves but cannot receive a split, so it fails at
        // initialise exactly like an unknown one. Caught here, where it is still a sentence
        // on a form rather than a stream that has stopped selling.
        if (array_key_exists('active', $d) && !$d['active']) {
            return ['ok' => false, 'name' => (string) ($d['business_name'] ?? ''), 'bank' => '',
                    'message' => 'That subaccount exists but is not active at Paystack, so payments '
                               . 'routed to it would be refused.'];
        }

        return ['ok' => true, 'message' => '',
                'name' => (string) ($d['business_name'] ?? ($d['account_name'] ?? '')),
                'bank' => (string) ($d['settlement_bank'] ?? '')];
    }

    // ───────────────────── partner organisations: onboarding ────────────────
    //
    // These four calls exist so a partner organisation can be onboarded WITHOUT anybody at
    // Africa GATES handling their bank details by hand, and without this platform ever
    // holding their money. The subaccount belongs to them; we only ever learn its code.

    /**
     * Ask the gateway who owns an account number, BEFORE creating anything.
     *
     * ── THIS IS A FRAUD CONTROL, NOT A CONVENIENCE ───────────────────────────
     *
     * `/bank/resolve` returns the account NAME the bank holds for a number. Comparing it to
     * the organisation's registered name is the cheapest anti-impersonation check available:
     * somebody registering "Bright Futures Initiative" whose settlement account resolves to a
     * personal name has either made a mistake worth catching or is about to collect strangers'
     * donations into their own pocket. Either way a human should look before money can move.
     *
     * A transport failure is NOT a mismatch. "We could not ask" and "the answer was no" are
     * different, and conflating them rejects correct applications during an outage.
     *
     * @return array{ok:bool,name:string,message:string,reachable:bool}
     */
    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        $accountNumber = preg_replace('/\D+/', '', $accountNumber) ?? '';
        $bankCode      = trim($bankCode);

        if ($accountNumber === '' || $bankCode === '') {
            return ['ok' => false, 'name' => '', 'reachable' => true,
                    'message' => 'An account number and a bank are both needed.'];
        }
        if (!$this->isEnabled('paystack')) {
            return ['ok' => false, 'name' => '', 'reachable' => false,
                    'message' => 'Paystack is not configured in this environment.'];
        }

        try {
            $res = $this->request('GET', 'https://api.paystack.co/bank/resolve?' . http_build_query([
                'account_number' => $accountNumber,
                'bank_code'      => $bankCode,
            ]), null, $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'name' => '', 'reachable' => false,
                    'message' => 'Could not reach Paystack to check that account: ' . $e->getMessage()];
        }

        $body = $res['json'];
        $name = trim((string) ($body['data']['account_name'] ?? ''));

        if (!$res['ok'] || ($body['status'] ?? false) !== true || $name === '') {
            return ['ok' => false, 'name' => '', 'reachable' => true,
                    'message' => (string) ($body['message'] ?? 'That account number could not be resolved.')];
        }
        return ['ok' => true, 'name' => $name, 'reachable' => true, 'message' => ''];
    }

    /**
     * Create a subaccount the organisation owns.
     *
     * `percentage_charge` is the share PAYSTACK keeps for the MAIN account — i.e. the platform
     * fee, expressed as a percentage of the transaction. Zero is legitimate and is what "100%
     * of your gift reaches the cause" requires, so it is not defaulted to something else.
     *
     * `settlement_schedule` is the whole reason an organisation can have a withdraw button
     * without this platform holding their money: `auto` settles T+1 to their own bank with no
     * involvement from us, `manual` holds it at the gateway until it is asked for.
     *
     * @return array{ok:bool,code:string,message:string}
     */
    public function createSubaccount(
        string $businessName,
        string $bankCode,
        string $accountNumber,
        float  $percentageCharge = 0.0,
        string $settlementSchedule = 'auto',
        array  $contact = []
    ): array {
        if (!$this->isEnabled('paystack')) {
            return ['ok' => false, 'code' => '', 'message' => 'Paystack is not configured in this environment.'];
        }
        if (!in_array($settlementSchedule, ['auto', 'weekly', 'monthly', 'manual'], true)) {
            $settlementSchedule = 'auto';
        }
        // Paystack rejects a negative or >100 share, and a wrong one here silently changes what
        // every future donation to this organisation is worth to them.
        if ($percentageCharge < 0 || $percentageCharge > 100) {
            return ['ok' => false, 'code' => '', 'message' => 'The platform share must be between 0 and 100 percent.'];
        }

        $payload = [
            'business_name'       => $businessName,
            'settlement_bank'     => $bankCode,
            'account_number'      => preg_replace('/\D+/', '', $accountNumber) ?? '',
            'percentage_charge'   => $percentageCharge,
            'settlement_schedule' => $settlementSchedule,
        ];
        foreach (['primary_contact_email' => 'email', 'primary_contact_name' => 'name',
                  'primary_contact_phone' => 'phone'] as $field => $key) {
            if (trim((string) ($contact[$key] ?? '')) !== '') $payload[$field] = trim((string) $contact[$key]);
        }

        try {
            $res = $this->request('POST', 'https://api.paystack.co/subaccount', $payload, $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => '', 'message' => 'Could not reach Paystack: ' . $e->getMessage()];
        }

        $body = $res['json'];
        $code = trim((string) ($body['data']['subaccount_code'] ?? ''));
        if (!$res['ok'] || ($body['status'] ?? false) !== true || $code === '') {
            return ['ok' => false, 'code' => '',
                    'message' => (string) ($body['message'] ?? 'Paystack refused to create the subaccount.')];
        }
        return ['ok' => true, 'code' => $code, 'message' => ''];
    }

    /**
     * The bank list, for the account picker. Cached because it barely changes and the
     * Resolve Account rate limit is the one worth protecting.
     *
     * @return array<int,array{code:string,name:string}>
     */
    public function banks(string $country = 'nigeria'): array
    {
        if (!$this->isEnabled('paystack')) return [];
        try {
            $res = $this->request('GET', 'https://api.paystack.co/bank?' . http_build_query([
                'country' => $country, 'perPage' => 100,
            ]), null, $this->paystackAuth());
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ((array) ($res['json']['data'] ?? []) as $b) {
            $code = trim((string) ($b['code'] ?? ''));
            $name = trim((string) ($b['name'] ?? ''));
            if ($code !== '' && $name !== '') $out[] = ['code' => $code, 'name' => $name];
        }
        usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    // ────────────────────── partner organisations: payouts ──────────────────

    /**
     * A transfer recipient for an organisation's settlement account.
     *
     * Created once and the code stored, because creating a second recipient for the same
     * account is how you end up with two live handles to one bank account and no way to tell
     * which a given transfer used.
     *
     * @return array{ok:bool,code:string,message:string}
     */
    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode): array
    {
        if (!$this->isEnabled('paystack')) {
            return ['ok' => false, 'code' => '', 'message' => 'Paystack is not configured in this environment.'];
        }
        try {
            $res = $this->request('POST', 'https://api.paystack.co/transferrecipient', [
                'type'           => 'nuban',
                'name'           => $name,
                'account_number' => preg_replace('/\D+/', '', $accountNumber) ?? '',
                'bank_code'      => $bankCode,
                'currency'       => 'NGN',
            ], $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => '', 'message' => 'Could not reach Paystack: ' . $e->getMessage()];
        }
        $body = $res['json'];
        $code = trim((string) ($body['data']['recipient_code'] ?? ''));
        if (!$res['ok'] || ($body['status'] ?? false) !== true || $code === '') {
            return ['ok' => false, 'code' => '',
                    'message' => (string) ($body['message'] ?? 'Paystack refused to create the recipient.')];
        }
        return ['ok' => true, 'code' => $code, 'message' => ''];
    }

    /**
     * Send money out.
     *
     * ── THE REFERENCE IS THE IDEMPOTENCY KEY ─────────────────────────────────
     *
     * Paystack documents no client-supplied idempotency header, so OUR reference is the only
     * thing standing between a retry and paying somebody twice. The caller generates and
     * STORES it before this is reached, and a retry passes the same one back. Paystack has
     * also announced it will begin ENFORCING `reference` as required on Initiate Transfer,
     * so supplying our own is both the safe and the future-proof choice.
     *
     * Transfer references are stricter than transaction references: lowercase alphanumerics,
     * hyphen and underscore only. The caller is responsible for that shape; this asserts it
     * rather than silently mangling a reference that is already written to a row.
     *
     * ── AND `ok` DOES NOT MEAN "PAID" ────────────────────────────────────────
     *
     * A transfer can come back `otp`, `pending` or `received` — none of which are conclusive.
     * The status is returned verbatim for the caller to store; only a webhook or a later
     * verify settles it. Do not call Verify Transfer immediately: before the transfer exists
     * at Paystack it returns an error, which reads exactly like a failure.
     *
     * @return array{ok:bool,status:string,transfer_code:string,transfer_id:?string,message:string}
     */
    public function initiateTransfer(int $amountNaira, string $recipientCode, string $reference, string $reason = ''): array
    {
        $blank = ['ok' => false, 'status' => '', 'transfer_code' => '', 'transfer_id' => null];

        if (!$this->isEnabled('paystack')) {
            return $blank + ['message' => 'Paystack is not configured in this environment.'];
        }
        if ($amountNaira < 1) {
            return $blank + ['message' => 'Invalid amount.'];
        }
        if (!preg_match('/^[a-z0-9._-]+$/', $reference)) {
            return $blank + ['message' => 'Transfer references may only contain lowercase letters, digits, hyphen and underscore.'];
        }

        try {
            $res = $this->request('POST', 'https://api.paystack.co/transfer', [
                'source'    => 'balance',
                'amount'    => $amountNaira * 100,      // kobo
                'recipient' => $recipientCode,
                'reference' => $reference,
                'reason'    => $reason !== '' ? $reason : 'Partner payout',
            ], $this->paystackAuth());
        } catch (\Throwable $e) {
            // Transport failure. The transfer MAY still have been created — Paystack could
            // have processed it and lost the response — so this is explicitly not a
            // "did not happen". The caller must leave the row open and let the sweep resolve
            // it, never retry with a fresh reference.
            return $blank + ['message' => 'Could not reach Paystack: ' . $e->getMessage()];
        }

        $body = $res['json'];
        $d    = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (!$res['ok'] || ($body['status'] ?? false) !== true) {
            return $blank + ['message' => (string) ($body['message'] ?? 'Paystack refused the transfer.')];
        }

        return [
            'ok'            => true,
            'status'        => strtolower(trim((string) ($d['status'] ?? 'pending'))),
            'transfer_code' => (string) ($d['transfer_code'] ?? ''),
            // Unsigned 64-bit since June 2022 — kept as a string so no PHP int boundary or
            // narrow column can truncate the one value that matches Paystack's own record.
            'transfer_id'   => isset($d['id']) ? (string) $d['id'] : null,
            'message'       => (string) ($body['message'] ?? ''),
        ];
    }

    /**
     * What happened to a transfer, by our reference. For the reconciliation sweep.
     *
     * @return array{ok:bool,status:string,message:string,reachable:bool}
     */
    public function verifyTransfer(string $reference): array
    {
        if (!$this->isEnabled('paystack')) {
            return ['ok' => false, 'status' => '', 'reachable' => false,
                    'message' => 'Paystack is not configured in this environment.'];
        }
        try {
            $res = $this->request('GET', 'https://api.paystack.co/transfer/verify/' . rawurlencode($reference),
                                  null, $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => '', 'reachable' => false, 'message' => $e->getMessage()];
        }
        $body = $res['json'];
        $st   = strtolower(trim((string) ($body['data']['status'] ?? '')));
        if (!$res['ok'] || ($body['status'] ?? false) !== true || $st === '') {
            return ['ok' => false, 'status' => '', 'reachable' => true,
                    'message' => (string) ($body['message'] ?? 'Paystack did not recognise that transfer.')];
        }
        return ['ok' => true, 'status' => $st, 'reachable' => true, 'message' => ''];
    }

    /**
     * Note where a payment was routed, in `gates_payment_routes`.
     *
     * ── ITS OWN TABLE, KEYED BY REFERENCE ────────────────────────────────────
     *
     * References live in three different tables with three different shapes — `gates_orders`,
     * `gates_event_registrations` and `gates_votes` — so there is no single row to write a column
     * onto. The reference is the one identifier all three share and the one the gateway knows the
     * payment by, so it is the key. (The first attempt wrote to a `gates_payments` table that
     * does not exist; running the migration said so.)
     *
     * ── BEST EFFORT, DELIBERATELY ────────────────────────────────────────────
     *
     * A failure to record the attribution must never stop the payment. The money moving is what
     * matters, and a missing route row still has a defined meaning — settled to the main account
     * — whereas a buyer who cannot pay because a bookkeeping insert failed is a far worse outcome
     * than a bookkeeping row that is absent.
     *
     * Upserted rather than inserted: a buyer who abandons checkout and starts again re-initialises
     * the same reference, and two rows for one payment would make a per-stream total double.
     */
    private function rememberDestination(string $reference, string $stream, string $subaccount,
                                        string $bearer, int $amountNaira): void
    {
        try {
            if (!DB::schema()->hasTable('gates_payment_routes')) {
                return;                     // not migrated yet — see the migration's note
            }
            DB::table('gates_payment_routes')->updateOrInsert(
                ['reference' => $reference],
                ['revenue_stream' => $stream, 'subaccount' => $subaccount,
                 'fee_bearer' => $bearer, 'amount_naira' => $amountNaira,
                 'created_at' => \Illuminate\Support\Carbon::now()->toDateTimeString()]
            );
        } catch (\Throwable $e) {
            $this->log?->warning('[payment] could not record destination',
                                 ['ref' => $reference, 'err' => $e->getMessage()]);
        }
    }

    /**
     * Un-record a routing that Paystack refused.
     *
     * A MISSING row means "settled to the main account", which is exactly what happens when
     * the retry above goes out without the subaccount. Leaving the row would make the
     * platform's own records claim a settlement that the bank will never show — the precise
     * drift the attribution table was created to prevent.
     */
    private function forgetDestination(string $reference): void
    {
        try {
            if (!DB::schema()->hasTable('gates_payment_routes')) return;
            DB::table('gates_payment_routes')->where('reference', $reference)->delete();
        } catch (\Throwable $e) {
            $this->log?->warning('[payment] could not clear destination',
                                 ['ref' => $reference, 'err' => $e->getMessage()]);
        }
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

        // Paystack status: 'success' | 'failed' | 'abandoned' | 'reversed' | 'ongoing' | …
        //
        // ── 'reversed' IS CONCLUSIVE, AND USED TO BE READ AS 'pending' ───────
        //
        // Everything that was not `success` or `failed` collapsed to `pending`, which is right
        // for `abandoned` and `ongoing` — those may still complete — and wrong for `reversed`,
        // which means the money has already gone back. A reversed transaction was therefore
        // re-verified on every sweep, forever, until the three-day ceiling wrote it off as an
        // abandoned checkout. It is not abandoned; it is finished, and it finished badly.
        $raw = strtolower((string) ($data['status'] ?? ''));
        $status = match (true) {
            $raw === 'success'  => 'success',
            $raw === 'failed'   => 'failed',
            $raw === 'reversed' => 'failed',
            default             => 'pending',
        };
        // amount comes back in KOBO → whole naira.
        $amount = (int) round(((int)($data['amount'] ?? 0)) / 100);

        return [
            'ok'       => true,
            'status'   => $status,
            'amount'   => $amount,
            'currency' => (string)($data['currency'] ?? 'NGN'),
            'meta'     => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            // ── THE GATEWAY'S OWN IDENTIFIERS ────────────────────────────────
            //
            // Dropped on the floor until now, and they are the numbers a supporter
            // actually has. Paystack's receipt and dashboard show its transaction id and
            // its own reference; ours (AFG-PVOTE-…) is the one WE minted. So somebody
            // pasting "the reference from my receipt" matched nothing anywhere, and
            // VoteProof's own error message had to apologise for it: "if you paid inside
            // a bank or wallet app, that app shows its own different number".
            //
            // Stored on the order at confirm time so every later lookup is a local
            // indexed hit rather than a live call. See PaymentLookup.
            'gateway_id'  => (string) ($data['id'] ?? ''),
            'gateway_ref' => (string) ($data['reference'] ?? ''),
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
                        'provider_ref' => null, 'retryable' => false,
                        // Refunded on an earlier attempt, so this reply carries no
                        // figure. Null rather than our row's number: we genuinely do
                        // not know what that earlier refund sent.
                        'amount_naira' => null];
            }
            $retryable = self::classifyRefusal((int) $res['code'], $msg);
            $this->log?->warning('[payment] paystack refund refused', [
                'ref' => $reference, 'http' => $res['code'], 'msg' => $msg,
                'retryable' => $retryable === null ? 'unknown' : ($retryable ? 'yes' : 'no'),
            ]);
            return ['ok' => false, 'status' => 'failed', 'message' => $msg,
                    'provider_ref' => null, 'retryable' => $retryable];
        }

        // Paystack refund status: 'pending' | 'processing' | 'processed' | 'failed'
        $raw = strtolower((string) ($data['status'] ?? 'pending'));
        return [
            'ok'           => $raw !== 'failed',
            'status'       => $raw === 'processed' ? 'refunded' : ($raw === 'failed' ? 'failed' : 'pending'),
            'message'      => (string) ($body['message'] ?? 'Refund submitted.'),
            'provider_ref' => isset($data['id']) ? (string) $data['id'] : null,
            // WHAT WAS ACTUALLY SENT BACK, in the gateway's own words. Paystack
            // reports refunds in kobo. Null when it did not say, so a caller can
            // tell "we do not know" apart from a figure we made up.
            'amount_naira' => isset($data['amount']) && is_numeric($data['amount'])
                ? (int) round(((float) $data['amount']) / 100)
                : null,
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
            // Same reason as Paystack's: these are the numbers on the supporter's own
            // receipt. Flutterwave calls them `id` and `flw_ref`.
            'gateway_id'  => (string) ($data['id'] ?? ''),
            'gateway_ref' => (string) ($data['flw_ref'] ?? $data['tx_ref'] ?? ''),
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
            // A 5xx on the lookup is the gateway being unwell, not the reference
            // being wrong — and telling those apart is the difference between
            // waiting an hour and telling a person their refund needs doing by hand.
            $transportFault = (int) $look['code'] >= 500 || (int) $look['code'] === 429;
            return ['ok' => false, 'status' => 'failed', 'provider_ref' => null,
                    'retryable' => $transportFault,
                    'message' => $transportFault
                        ? 'Flutterwave could not be asked about that reference just now.'
                        : 'Flutterwave does not recognise that reference, so nothing was refunded.'];
        }

        $res  = $this->request('POST',
            'https://api.flutterwave.com/v3/transactions/' . rawurlencode((string) $txId) . '/refund',
            $amountNaira !== null ? ['amount' => $amountNaira] : [],
            ['Authorization: Bearer ' . $this->secret('flutterwave')]);
        $body = $res['json'];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if (!$res['ok'] || ($body['status'] ?? '') !== 'success') {
            $msg       = (string) ($body['message'] ?? 'Refund was refused.');
            $retryable = self::classifyRefusal((int) $res['code'], $msg);
            $this->log?->warning('[payment] flutterwave refund refused', [
                'ref' => $reference, 'http' => $res['code'], 'msg' => $msg,
                'retryable' => $retryable === null ? 'unknown' : ($retryable ? 'yes' : 'no'),
            ]);
            return ['ok' => false, 'status' => 'failed', 'message' => $msg,
                    'provider_ref' => null, 'retryable' => $retryable];
        }

        // Flutterwave refund status: 'completed' | 'pending' | 'failed'
        $raw = strtolower((string) ($data['status'] ?? 'pending'));
        // Flutterwave reports in the settlement currency (NGN here), not in kobo —
        // hence no /100. Getting this wrong in either direction would record a figure
        // a hundred times out, so the two providers are converted separately rather
        // than through one "amount" helper that has to remember which is which.
        $amount = null;
        foreach (['amount_refunded', 'amount'] as $k) {
            if (isset($data[$k]) && is_numeric($data[$k])) { $amount = (int) round((float) $data[$k]); break; }
        }
        return [
            'ok'           => $raw !== 'failed',
            'status'       => $raw === 'completed' ? 'refunded' : ($raw === 'failed' ? 'failed' : 'pending'),
            'message'      => (string) ($body['message'] ?? 'Refund submitted.'),
            'provider_ref' => isset($data['id']) ? (string) $data['id'] : null,
            'amount_naira' => $amount,
        ];
    }

    // ─────────────────────────────── transport ──────────────────────────────

    /**
     * Single cURL chokepoint. JSON in, JSON out, 15s timeout, TLS verified.
     *
     * PROTECTED, not private, so a test can intercept the one network call without
     * bypassing anything else — the same reasoning as {@see AiService::httpPost()}.
     * Overriding `refundPaystack()`/`refundFlutterwave()` instead would skip the
     * payload assembly and the response parsing, which is where the money lives:
     * Paystack transacts in KOBO and Flutterwave in naira, so a unit-conversion
     * mistake in either direction is a factor of a hundred on a figure that gets
     * quoted to a supporter. That is exactly the code a test needs to reach.
     *
     * It was private, and the consequence showed up immediately: a test double could
     * not take effect, so the "unit" test made a real HTTPS call to the live gateway
     * with whatever key the environment happened to hold.
     *
     * @return array{ok:bool,code:int,json:array,raw:string}
     */
    protected function request(string $method, string $url, ?array $jsonBody, array $headers): array
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

    // ═══════════════════════════════════════════════════════════════════════
    // DISPUTES — chargebacks and fraud claims
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Paystack gives a merchant 16 HOURS to answer a dispute. Miss it and Paystack
    // accepts on your behalf and refunds the customer out of your balance, so this
    // is the one part of the API where being slow costs money by default.
    //
    // Paystack only. Flutterwave's dispute handling is a dashboard-and-email process
    // with no equivalent endpoints, and pretending otherwise would produce a screen
    // that silently defends nothing.

    /**
     * Disputes needing an answer from us.
     *
     * `awaiting-merchant-feedback` is the only status with a clock on it. The others
     * (resolved, archived, pending pre-arbitration) are history, and mixing them in
     * would bury the two rows that have hours left.
     *
     * @param array{status?:string, from?:string, to?:string, perPage?:int, page?:int} $filter
     * @return array{ok:bool, message:string, disputes:list<array>}
     */
    public function disputes(array $filter = []): array
    {
        if (!$this->isEnabled('paystack')) {
            return ['ok' => false, 'message' => 'Paystack is not configured in this environment.', 'disputes' => []];
        }
        $query = ['perPage' => max(1, min(100, (int) ($filter['perPage'] ?? 50)))];
        foreach (['status', 'from', 'to', 'page', 'transaction'] as $k) {
            if (isset($filter[$k]) && (string) $filter[$k] !== '') $query[$k] = (string) $filter[$k];
        }

        try {
            $res = $this->request('GET', 'https://api.paystack.co/dispute?' . http_build_query($query),
                                  null, $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Paystack could not be reached: ' . $e->getMessage(), 'disputes' => []];
        }
        $body = $res['json'];
        if (!$res['ok'] || ($body['status'] ?? false) !== true || !is_array($body['data'] ?? null)) {
            return ['ok' => false, 'message' => (string) ($body['message'] ?? 'Paystack would not list disputes.'),
                    'disputes' => []];
        }
        return ['ok' => true, 'message' => '', 'disputes' => array_values(array_map(
            [self::class, 'normaliseDispute'], array_filter($body['data'], 'is_array')
        ))];
    }

    /** One dispute, normalised the same way as the list. */
    public function dispute(string $id): array
    {
        if ($id === '' || !$this->isEnabled('paystack')) {
            return ['ok' => false, 'message' => 'Unknown dispute.', 'dispute' => null];
        }
        try {
            $res = $this->request('GET', 'https://api.paystack.co/dispute/' . rawurlencode($id),
                                  null, $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'dispute' => null];
        }
        $body = $res['json'];
        if (!$res['ok'] || ($body['status'] ?? false) !== true || !is_array($body['data'] ?? null)) {
            return ['ok' => false, 'message' => (string) ($body['message'] ?? 'Not found.'), 'dispute' => null];
        }
        return ['ok' => true, 'message' => '', 'dispute' => self::normaliseDispute($body['data'])];
    }

    /**
     * A one-time URL to upload one evidence file to.
     *
     * The signed URL is valid for THIRTY MINUTES, so it must be fetched at the moment
     * of upload rather than stored and reused later. `fileName` is what
     * {@see disputeResolve()} has to be given as `uploaded_filename` — the signed URL
     * itself is not the handle.
     *
     * @return array{ok:bool, message:string, url:string, filename:string}
     */
    public function disputeUploadUrl(string $id, string $filename): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'message' => $m, 'url' => '', 'filename' => ''];
        if ($id === '' || $filename === '') return $fail('A dispute id and a filename are both required.');
        if (!$this->isEnabled('paystack'))  return $fail('Paystack is not configured in this environment.');
        // Paystack accepts .jpg, .jpeg and .pdf only. Refused here rather than at the
        // upload, because a rejection there arrives as an opaque S3 error.
        if (!preg_match('/\.(jpe?g|pdf)$/i', $filename)) {
            return $fail('Evidence must be a .jpg, .jpeg or .pdf file.');
        }

        try {
            $res = $this->request('GET', 'https://api.paystack.co/dispute/' . rawurlencode($id)
                                       . '/upload_url?' . http_build_query(['upload_filename' => $filename]),
                                  null, $this->paystackAuth());
        } catch (\Throwable $e) {
            return $fail('Paystack could not be reached: ' . $e->getMessage());
        }
        $body = $res['json'];
        $d    = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (!$res['ok'] || ($body['status'] ?? false) !== true || ($d['signedUrl'] ?? '') === '') {
            return $fail((string) ($body['message'] ?? 'Paystack would not issue an upload URL.'));
        }
        return ['ok' => true, 'message' => '',
                'url' => (string) $d['signedUrl'],
                'filename' => (string) ($d['fileName'] ?? $filename)];
    }

    /**
     * PUT one evidence file to the signed URL.
     *
     * ── TWO THINGS THAT ARE EASY TO GET WRONG HERE ──────────────────────────
     *
     * 1. NO AUTHORIZATION HEADER. This URL is pre-signed and points at Paystack's
     *    storage host, not their API. Sending `Authorization: Bearer sk_live_…` to it
     *    would hand our secret key to a third party for no reason, and the signature
     *    in the URL is the whole authentication. This is why it does not go through
     *    request(), which always attaches auth and always sends JSON.
     *
     * 2. A SUCCESSFUL UPLOAD RETURNS AN EMPTY BODY. So success is judged on the HTTP
     *    STATUS CODE alone. Code that looked for a `status: true` in the response —
     *    the shape every other Paystack call returns — would treat every successful
     *    upload as a failure and every dispute would go undefended.
     *
     * @return array{ok:bool, code:int, message:string}
     */
    public function putSignedFile(string $signedUrl, string $bytes, string $contentType): array
    {
        if ($signedUrl === '' || $bytes === '') {
            return ['ok' => false, 'code' => 0, 'message' => 'Nothing to upload.'];
        }
        $ch = curl_init($signedUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,   // an image over a Nigerian uplink
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Content-Type only. No Authorization: see the note above.
            CURLOPT_HTTPHEADER     => ['Content-Type: ' . $contentType, 'Content-Length: ' . strlen($bytes)],
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false && $err !== '') {
            return ['ok' => false, 'code' => 0, 'message' => 'Upload transport error: ' . $err];
        }
        $ok = $code >= 200 && $code < 300;
        return ['ok' => $ok, 'code' => $code,
                'message' => $ok ? '' : ('The upload was refused (HTTP ' . $code . ').')];
    }

    /**
     * Extra evidence, required for FRAUD CLAIMS specifically.
     *
     * A chargeback is answered with a receipt; a fraud claim is answered with proof
     * that a real person received something, so Paystack takes structured fields for
     * it rather than a file.
     *
     * @param array{customer_email?:string, customer_name?:string, customer_phone?:string,
     *              service_details?:string, delivery_address?:string, delivery_date?:string} $fields
     * @return array{ok:bool, message:string, evidence_id:?int}
     */
    public function disputeAddEvidence(string $id, array $fields): array
    {
        if ($id === '' || !$this->isEnabled('paystack')) {
            return ['ok' => false, 'message' => 'Paystack is not configured.', 'evidence_id' => null];
        }
        $payload = [];
        foreach (['customer_email', 'customer_name', 'customer_phone', 'service_details',
                  'delivery_address', 'delivery_date'] as $k) {
            if (isset($fields[$k]) && trim((string) $fields[$k]) !== '') $payload[$k] = (string) $fields[$k];
        }
        if ($payload === []) {
            return ['ok' => false, 'message' => 'No evidence fields were given.', 'evidence_id' => null];
        }

        try {
            $res = $this->request('POST', 'https://api.paystack.co/dispute/' . rawurlencode($id) . '/evidence',
                                  $payload, $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'evidence_id' => null];
        }
        $body = $res['json'];
        if (!$res['ok'] || ($body['status'] ?? false) !== true) {
            return ['ok' => false, 'message' => (string) ($body['message'] ?? 'Evidence was refused.'),
                    'evidence_id' => null];
        }
        $d = is_array($body['data'] ?? null) ? $body['data'] : [];
        return ['ok' => true, 'message' => '',
                'evidence_id' => isset($d['id']) ? (int) $d['id'] : null];
    }

    /**
     * Answer the dispute. This is the irreversible step.
     *
     * `merchant-accepted` concedes and refunds. `declined` contests it, and Paystack
     * requires `uploaded_filename` with it — a decline with no evidence attached is
     * rejected, which is the API refusing to let you contest something with nothing.
     *
     * @param array{resolution:string, message:string, refund_amount?:int,
     *              uploaded_filename?:string, evidence?:int} $payload
     * @return array{ok:bool, message:string, status:string}
     */
    public function disputeResolve(string $id, array $payload): array
    {
        if ($id === '' || !$this->isEnabled('paystack')) {
            return ['ok' => false, 'message' => 'Paystack is not configured.', 'status' => ''];
        }
        $resolution = (string) ($payload['resolution'] ?? '');
        if (!in_array($resolution, ['merchant-accepted', 'declined'], true)) {
            return ['ok' => false, 'message' => 'A resolution must be merchant-accepted or declined.', 'status' => ''];
        }
        if ($resolution === 'declined' && trim((string) ($payload['uploaded_filename'] ?? '')) === '') {
            return ['ok' => false, 'status' => '',
                    'message' => 'Paystack will not accept a declined dispute without an uploaded evidence file.'];
        }

        $body = ['resolution' => $resolution,
                 'message'    => mb_substr(trim((string) ($payload['message'] ?? '')), 0, 1000)];
        // Required by the endpoint even when conceding in full; 0 is a legitimate value
        // for a decline and must not be dropped by an empty() check.
        $body['refund_amount']     = (int) ($payload['refund_amount'] ?? 0);
        if (($payload['uploaded_filename'] ?? '') !== '') $body['uploaded_filename'] = (string) $payload['uploaded_filename'];
        if (($payload['evidence'] ?? null) !== null)      $body['evidence'] = (int) $payload['evidence'];

        try {
            $res = $this->request('PUT', 'https://api.paystack.co/dispute/' . rawurlencode($id) . '/resolve',
                                  $body, $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'status' => ''];
        }
        $j = $res['json'];
        if (!$res['ok'] || ($j['status'] ?? false) !== true) {
            return ['ok' => false, 'message' => (string) ($j['message'] ?? 'Paystack refused the resolution.'),
                    'status' => ''];
        }
        $d = is_array($j['data'] ?? null) ? $j['data'] : [];
        return ['ok' => true, 'message' => '', 'status' => (string) ($d['status'] ?? 'resolved')];
    }

    /** @return list<string> */
    /**
     * A recurring PLAN at the gateway, for one amount and one interval.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A PLAN AND NOT A CARD WE CHARGE OURSELVES
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Paystack returns an `authorization_code` on every successful charge, and re-charging
     * it on a schedule of our own is the other way to build this. It would mean this
     * platform holding a reusable payment credential, running its own billing clock, and
     * owning every retry, every expired card and every dispute that follows a charge the
     * donor did not expect — on a host with no shell, where the schedule is a webcron that
     * can miss a day.
     *
     * A plan puts all of that at the gateway. Paystack bills, retries, emails the donor
     * before each charge and lets them stop from their own receipt. What this platform
     * keeps is the record of the arrangement, which is exactly the part it should own.
     *
     * @return array{ok:bool, code:string, message:string}
     */
    public function createPlan(int $amountNaira, string $interval = 'monthly', string $name = ''): array
    {
        if (!$this->isEnabled('paystack')) {
            return ['ok' => false, 'code' => '', 'message' => 'Paystack is not configured in this environment.'];
        }
        if ($amountNaira < 1) {
            return ['ok' => false, 'code' => '', 'message' => 'Invalid amount.'];
        }
        // Paystack's own vocabulary. Anything else is rejected by the API with a message no
        // donor should ever see, so it is refused here where it can be reported as ours.
        if (!in_array($interval, ['daily', 'weekly', 'monthly', 'quarterly', 'biannually', 'annually'], true)) {
            return ['ok' => false, 'code' => '', 'message' => 'Unsupported billing interval.'];
        }

        try {
            $res = $this->request('POST', 'https://api.paystack.co/plan', [
                // KOBO, like every other amount that crosses this boundary.
                'amount'   => $amountNaira * 100,
                'interval' => $interval,
                'currency' => 'NGN',
                'name'     => $name !== '' ? $name : ('Africa GATES — ' . $interval . ' ₦' . number_format($amountNaira)),
            ], $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => '', 'message' => 'Could not reach Paystack: ' . $e->getMessage()];
        }

        $code = trim((string) ($res['json']['data']['plan_code'] ?? ''));
        if (!$res['ok'] || $code === '') {
            $msg = trim((string) ($res['json']['message'] ?? '')) ?: 'Paystack did not create the plan.';
            $this->log?->error('[payment] plan create failed', ['amount' => $amountNaira, 'msg' => $msg]);
            return ['ok' => false, 'code' => '', 'message' => $msg];
        }

        return ['ok' => true, 'code' => $code, 'message' => ''];
    }

    /**
     * Stop a subscription at the gateway.
     *
     * Paystack requires the subscription code AND the email token together — the token is
     * what proves the request came from somebody who holds the donor's receipt rather than
     * from anybody who has seen a subscription code. Both are stored when the subscription
     * is created, for this call and only this call.
     *
     * ── AND WHY "ALREADY DISABLED" IS SUCCESS ────────────────────────────────
     *
     * A donor who cancels twice, or cancels here after cancelling from Paystack's own email,
     * must be told it is stopped — because it is. Reporting a failure would send somebody who
     * has already succeeded to their bank instead, which is the one outcome worse than a
     * cancellation that does not work.
     *
     * @return array{ok:bool, message:string}
     */
    public function cancelSubscription(string $subscriptionCode, string $emailToken): array
    {
        if (!$this->isEnabled('paystack')) {
            return ['ok' => false, 'message' => 'Paystack is not configured in this environment.'];
        }
        if (trim($subscriptionCode) === '' || trim($emailToken) === '') {
            return ['ok' => false, 'message' => 'This subscription cannot be stopped automatically.'];
        }

        try {
            $res = $this->request('POST', 'https://api.paystack.co/subscription/disable', [
                'code'  => trim($subscriptionCode),
                'token' => trim($emailToken),
            ], $this->paystackAuth());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Paystack: ' . $e->getMessage()];
        }

        if ($res['ok']) return ['ok' => true, 'message' => ''];

        $msg = strtolower(trim((string) ($res['json']['message'] ?? '')));
        if (str_contains($msg, 'not active') || str_contains($msg, 'already') || str_contains($msg, 'disabled')) {
            return ['ok' => true, 'message' => ''];
        }

        return ['ok' => false, 'message' => trim((string) ($res['json']['message'] ?? ''))
                                            ?: 'Paystack refused the cancellation.'];
    }

    private function paystackAuth(): array
    {
        return ['Authorization: Bearer ' . $this->secret('paystack')];
    }

    /**
     * One shape for a dispute, whatever the endpoint returned.
     *
     * `amount` is converted from kobo to naira here so nothing downstream has to
     * remember which unit it is holding — the mistake that makes a ₦50,000 dispute
     * read as ₦500.
     *
     * @param array<string,mixed> $r
     */
    private static function normaliseDispute(array $r): array
    {
        $tx = is_array($r['transaction'] ?? null) ? $r['transaction'] : [];
        $cs = is_array($r['customer'] ?? null) ? $r['customer'] : [];
        $kobo = (int) ($r['refund_amount'] ?? ($tx['amount'] ?? 0));

        return [
            'id'          => (string) ($r['id'] ?? ''),
            'status'      => (string) ($r['status'] ?? ''),
            'category'    => (string) ($r['category'] ?? ''),
            // 'chargeback' | 'fraud' — decides whether structured evidence is needed
            // on top of a file. Absent on some payloads, hence the category fallback.
            'kind'        => str_contains(mb_strtolower((string) ($r['category'] ?? '')), 'fraud')
                                ? 'fraud' : 'chargeback',
            'reference'   => (string) ($tx['reference'] ?? ''),
            'amount'      => (int) round($kobo / 100),
            'currency'    => (string) ($r['currency'] ?? ($tx['currency'] ?? 'NGN')),
            'email'       => (string) ($cs['email'] ?? ''),
            'created_at'  => (string) ($r['createdAt'] ?? ($r['created_at'] ?? '')),
            'due_at'      => (string) ($r['due_at'] ?? ''),
            'resolution'  => (string) ($r['resolution'] ?? ''),
            'note'        => (string) ($r['note'] ?? ($r['message'] ?? '')),
        ];
    }
}
