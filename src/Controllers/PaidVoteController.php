<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Support\Env;
use AfricaGates\Services\CheckoutThrottle;
use AfricaGates\Services\PaidVoteService;
use AfricaGates\Services\PaymentService;
use AfricaGates\Services\RateLimitService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Paid voting checkout — mirrors DonationController's audited shape exactly:
 *
 *   1. POST /vote/paid/start — validate, price SERVER-SIDE, write a PENDING
 *      gates_donations row (tier 'paid-vote', bonus_votes = qty,
 *      intent_nominee_id = target), leave for the gateway.
 *   2. Gateway → GET /vote/paid/callback — server-to-server re-verify, the
 *      idempotent status flip, then PaidVoteService::mint() (also idempotent;
 *      the /pay/webhook path mints too, whichever lands first wins).
 *   3. GET /vote/paid/success — read-only confirmation.
 *
 * Only live when the superadmin enables paid voting in Settings.
 */
final class PaidVoteController
{
    public function __construct(
        private readonly PaymentService    $payments,
        private readonly Twig              $view,
        private readonly ?RateLimitService $rateLimit = null,
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
     * POST /vote/paid/start
     *
     * ── ORDER OF CHECKS IS PART OF THE BEHAVIOUR ─────────────────────────────
     *
     * The throttle is consulted LAST, immediately before the pending row is written.
     * It used to run second, before the nominee, the phase, the provider or the email
     * had been validated, so every rejected attempt spent a slot: a supporter who
     * mistyped their address three times had burned three of ten hourly attempts on
     * requests that never reached a gateway. Only a request that is about to create a
     * pending order and a gateway session costs anything, so only that request is
     * counted. See {@see CheckoutThrottle} for why the caps themselves changed.
     *
     * And a refusal is never a dead end: the buyer's quantity, email and name are
     * flashed back so the re-rendered ballot arrives filled in, with the reason and —
     * when it is a throttle — when to try again.
     */
    public function start(Request $req, Response $res): Response
    {
        $b         = (array)$req->getParsedBody();
        $provider  = strtolower(trim((string)($b['provider'] ?? '')));
        $email     = strtolower(trim((string)($b['email'] ?? '')));
        $name      = trim((string)($b['name'] ?? ''));
        $nomineeId = (int)($b['nominee_id'] ?? 0);
        // CONSENT TO BE NAMED PUBLICLY = the buyer typed a name.
        //
        // The field is optional and the ballot says plainly what filling it in does
        // ("Shown on … public supporters list … leave it blank to give anonymously"),
        // so a second tickbox would be the same question asked twice.
        //
        // This still cannot be inferred at READ time from `donor_name` being non-empty,
        // which is why the flag is stored. Orders taken before the ballot said any of
        // this have names typed under a label that named no audience; reading consent
        // off those rows would publish every one of them retroactively. The column
        // defaults to 0, so only orders placed from here on can carry a yes.
        $showName  = $name !== '';
        // NOT clamped. `min(MAX_QTY, …)` silently reduced an over-large request, so a
        // supporter who asked for 5,000 votes was charged for 1,000 and told nothing —
        // they found out by looking at the tally. An over-large order is now refused with
        // the actual maximum, which is a conversation the buyer can act on.
        $qty       = max(1, (int)($b['qty'] ?? 1));
        $maxQty    = PaidVoteService::maxQtyForOrder();

        // Back to the nominee's ballot with a reason chip on any failure.
        $nominee = $nomineeId > 0 ? DB::table('gates_nominees')->where('id', $nomineeId)->first() : null;
        $backUrl = $this->ballotUrl($nominee, $req);
        $bail    = function (string $why, string $detail = '') use ($res, $backUrl, $qty, $email, $name): Response {
            $this->rememberOrder($qty, $email, $name, $detail);
            return $this->redirect($res, $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'paid=' . urlencode($why));
        };

        if (!PaidVoteService::enabled())                                     return $bail('off');
        // Allowlist + merge check, matching every other vote path. The old
        // denylist-of-one accepted rejected/withdrawn nominees and merged-away
        // duplicates, i.e. took money for votes on a nominee the public pages
        // no longer show and whose tally is no longer read.
        if (!$nominee
            || !in_array((string)$nominee->status, ['approved', 'winner', 'runner_up'], true)
            || !empty($nominee->merged_into ?? null))                         return $bail('nominee');
        if (!$this->votingOpenFor($nominee))                                 return $bail('closed');
        if (!$this->payments->isEnabled($provider))                          return $bail('unavailable');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))     return $bail('email');
        if ($qty > $maxQty) {
            return $bail('toomany', number_format($maxQty) . ' votes');
        }

        $gate = (new CheckoutThrottle($this->rateLimit))->allow($req, 'paid_vote');
        if (!$gate['ok']) {
            return $bail('rate', CheckoutThrottle::retryPhrase((int) $gate['retry_after']));
        }

        // Price is ALWAYS computed server-side from the admin's settings.
        $amount    = PaidVoteService::price($qty);
        $reference = 'AFG-PVOTE-' . bin2hex(random_bytes(6));
        try {
            DB::table('gates_donations')->insert([
                'donor_name'        => $name !== '' ? mb_substr($name, 0, 120) : 'Supporter',
                'donor_email'       => $email,
                'donor_phone'       => null,
                'donor_location'    => null,
                'amount_naira'      => $amount,
                'tier'              => 'paid-vote',
                'bonus_votes'       => $qty,
                'votes_used'        => 0,
                'intent_nominee_id' => $nomineeId,
                'payment_ref'       => $reference,
                'status'            => 'pending',
                // Stored on the ORDER, not applied yet: the vote does not exist until
                // the gateway confirms, and PaidVoteService::mint() copies this onto it.
                'show_name'         => $showName ? 1 : 0,
                'created_at'        => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            $this->log?->error('[paid-vote] could not persist pending order', ['err' => $e->getMessage()]);
            return $bail('error');
        }

        $callbackUrl = $this->base($req) . '/vote/paid/callback?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);
        $init = $this->payments->initialize($provider, $amount, $email, $reference, $callbackUrl, [
            'reference' => $reference, 'purpose' => 'paid-vote',
        ]);
        if (!$init['ok'] || empty($init['checkout_url'])) {
            DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'pending')->update(['status' => 'failed']);
            // The provider's OWN message, at error level. It was discarded, so an
            // operator debugging "checkout does not start" had the reason
            // ("Invalid key", "Currency not supported", a TLS failure) thrown away and
            // only a generic chip on the page to work from.
            $this->log?->error('[paid-vote] gateway would not start a transaction', [
                'ref' => $reference, 'provider' => $provider, 'reason' => (string) ($init['message'] ?? ''),
            ]);
            return $bail('start');
        }

        // NOT a 302 straight to the gateway. That redirect is part of a form submission,
        // so `form-action` governs it — and a policy without the gateway hosts blocks the
        // POST in the browser before any PHP runs at all. See GatewayHandoff.
        return $this->redirect($res, \AfricaGates\Services\GatewayHandoff::remember(
            $reference, (string) $init['checkout_url'], $this->base($req) . '/vote/paid/redirect', $provider
        ));
    }

    /**
     * Flash the buyer's order back so the re-rendered ballot arrives filled in.
     *
     * In the SESSION, not the query string. The obvious implementation appends
     * `&email=…&name=…` to the redirect, which writes the supporter's address and
     * their real name into the server access log, into any proxy log in front of it,
     * and into the `Referer` of every asset the ballot then loads — including the
     * third-party ad and font hosts the page embeds. A retry convenience is not worth
     * leaking PII to a CDN.
     *
     * Read once by {@see \AfricaGates\Controllers\VoteController::nomineeBallot()},
     * which clears it, so a later visit to the same ballot is not pre-filled from a
     * checkout the supporter has since abandoned.
     */
    private function rememberOrder(int $qty, string $email, string $name, string $detail): void
    {
        // `$_SESSION` as an array, not session_status() — the convention everywhere
        // else in this codebase, and the only version that is testable.
        if (!isset($_SESSION) || !is_array($_SESSION)) return;
        $_SESSION['paid_vote_retry'] = [
            'qty'    => $qty,
            'email'  => $email,
            'name'   => $name,
            'detail' => $detail,
            // `name` carries the consent with it: the field IS the choice, so repopulating
            // the field repopulates the decision. Nothing extra to flash.
        ];
    }

    /**
     * GET /vote/paid/redirect — the same-origin hop to the gateway.
     *
     * Exists so the browser never sees a form submission that ends on a third-party host.
     * See {@see \AfricaGates\Services\GatewayHandoff} for the console message that made
     * this necessary and why `form-action 'self'` blocked every paid vote.
     *
     * A missing or expired handoff is not an error state worth a stack trace: the buyer is
     * simply returned to the ballot with a reason, because the most likely cause is a
     * back-button or a bookmarked handoff URL.
     */
    public function handoff(Request $req, Response $res): Response
    {
        $reference = \AfricaGates\Services\GatewayHandoff::reference($req);
        $url = \AfricaGates\Services\GatewayHandoff::take($reference);
        if ($url === null) {
            return $this->redirect($res, $this->base($req) . '/vote?paid=start');
        }

        return \AfricaGates\Services\GatewayHandoff::page(
            $res, $url, \AfricaGates\Services\GatewayHandoff::providerLabel(), $reference
        );
    }

    /** GET /vote/paid/callback — browser return; re-verified server-to-server. */
    public function callback(Request $req, Response $res): Response
    {
        $q         = $req->getQueryParams();
        $reference = trim((string)($q['ref'] ?? $q['reference'] ?? $q['tx_ref'] ?? ''));
        $provider  = strtolower(trim((string)($q['provider'] ?? '')));
        if ($reference === '' || !$this->payments->isKnownProvider($provider)) {
            return $this->redirect($res, $this->base($req) . '/vote?paid=error');
        }
        $don = DB::table('gates_donations')->where('payment_ref', $reference)->where('tier', 'paid-vote')->first();
        if (!$don) return $this->redirect($res, $this->base($req) . '/vote?paid=error');

        $result = $this->confirm($provider, $reference, $don);
        if ($result === 'confirmed' || $result === 'already') {
            try { PaidVoteService::mint((int)$don->id); }
            catch (\Throwable $e) { $this->log?->error('[paid-vote] mint on callback failed', ['ref' => $reference, 'err' => $e->getMessage()]); }
            // The buyer's receipt. AFTER mint, because which of the two receipts they
            // get is decided by whether the votes actually landed — and this path used
            // to send nothing at all, so someone who paid for votes had no record that
            // the purchase existed. Claimed once per order, so the gateway webhook
            // arriving a second later does not send a duplicate. Never throws.
            \AfricaGates\Services\CheckoutMailer::receipt((int) $don->id);
            return $this->redirect($res, $this->base($req) . '/vote/paid/success?ref=' . urlencode($reference));
        }
        return $this->redirect($res, $this->base($req) . '/vote?paid=failed');
    }

    /**
     * GET /vote/paid/success — read-only confirmation.
     *
     * THREE states, not two. `confirmed` used to mean only "the donation row is
     * confirmed", and the page then told the buyer their votes were "already in
     * the public tally" and a receipt was on its way. Since mint() gained its
     * phase gate that can be false: a payment initiated while voting was open but
     * CONFIRMED after it closed is deliberately refused, leaving votes_used = 0.
     * The old copy would have thanked that buyer for votes that do not exist.
     *
     * So the truthful question is whether the votes MINTED, not whether the money
     * arrived — and the "paid but not minted" case gets its own honest state
     * telling the buyer they are owed a refund, with the reference they need to
     * claim it. `cycles:audit` reports the same population to the operator, so
     * both sides of that conversation see the same fact.
     */
    public function success(Request $req, Response $res): Response
    {
        $reference = trim((string)($req->getQueryParams()['ref'] ?? ''));
        $don = $reference !== ''
            ? DB::table('gates_donations')->where('payment_ref', $reference)->where('tier', 'paid-vote')->where('status', 'confirmed')->first()
            : null;
        $nominee = ($don && !empty($don->intent_nominee_id))
            ? DB::table('gates_nominees')->where('id', (int)$don->intent_nominee_id)->first()
            : null;

        // votes_used is the mint claim flag: 0 on a confirmed order means the
        // votes were never added. Same signal ops queries for a refund.
        $minted = $don !== null && (int)$don->votes_used > 0;

        return $this->view->render($res, 'pages/vote-paid-success.twig', [
            'page_title'       => $minted ? 'Votes confirmed — Africa GATES' : 'Payment received — Africa GATES',
            'meta_description' => 'Your paid votes have been recorded — thank you for backing African excellence.',
            'gates_page'       => 'awards',
            'has_hero'         => false,
            'confirmed'        => $don !== null,
            'minted'           => $minted,
            'votes'            => $don ? (int)$don->bonus_votes : 0,
            'amount_naira'     => $don ? (int)$don->amount_naira : 0,
            'nominee_name'     => $nominee ? (string)$nominee->name : '',
            'ballot_url'       => $this->ballotUrl($nominee, $req),
            'reference'        => $reference,
            // For the /vote hub's per-device ballot tracker. Only meaningful when
            // votes actually landed, so the template gates the write on `minted`.
            'programme_id'     => $this->programmeIdFor($nominee),
        ]);
    }

    /** The programme a nominee's category belongs to, or 0 when unresolvable. */
    private function programmeIdFor(?object $nominee): int
    {
        if (!$nominee) return 0;
        try {
            return (int) DB::table('gates_award_categories as cat')
                ->join('gates_award_cycles as c', 'c.id', '=', 'cat.cycle_id')
                ->where('cat.id', (int)$nominee->category_id)
                ->value('c.programme_id');
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Idempotent confirm — mirrors DonationController/PaymentController. */
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
            $this->log?->warning('[paid-vote] amount mismatch — refusing to confirm', ['ref' => $reference]);
            return 'failed';
        }
        $changed = DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'pending')->update(['status' => 'confirmed']);
        return $changed > 0 ? 'confirmed' : 'already';
    }

    /**
     * True when the nominee's cycle is in its voting window, decided by the
     * shared COMPUTED-phase guard rather than this controller's own reading of
     * the stored status column.
     */
    private function votingOpenFor(object $nominee): bool
    {
        return PaidVoteService::votingOpenFor((int) $nominee->category_id);
    }

    /**
     * The nominee's ballot URL (or the vote index as a safe fallback).
     *
     * Delegates to the shared {@see \AfricaGates\Support\NomineeUrl}. This method built
     * the URL itself, VoteController built it a second way, and transactional email now
     * needs it a third time — three copies of one URL shape is how the link in a
     * buyer's receipt ends up differing from the link on the page they came from.
     */
    private function ballotUrl(?object $nominee, ?Request $req = null): string
    {
        if (!$nominee) return $this->base($req) . '/vote';
        return \AfricaGates\Support\NomineeUrl::ballot((int) $nominee->id, $this->base($req));
    }
}
