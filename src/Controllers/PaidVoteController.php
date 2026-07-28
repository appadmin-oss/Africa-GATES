<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

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

    private function base(): string { return rtrim((string)($_ENV['APP_URL'] ?? ''), '/'); }
    private function redirect(Response $res, string $url): Response { return $res->withHeader('Location', $url)->withStatus(302); }

    /** POST /vote/paid/start */
    public function start(Request $req, Response $res): Response
    {
        $b         = (array)$req->getParsedBody();
        $provider  = strtolower(trim((string)($b['provider'] ?? '')));
        $email     = strtolower(trim((string)($b['email'] ?? '')));
        $name      = trim((string)($b['name'] ?? ''));
        $nomineeId = (int)($b['nominee_id'] ?? 0);
        $qty       = max(1, min(PaidVoteService::MAX_QTY, (int)($b['qty'] ?? 1)));
        $ip        = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');

        // Back to the nominee's ballot with a reason chip on any failure.
        $nominee = $nomineeId > 0 ? DB::table('gates_nominees')->where('id', $nomineeId)->first() : null;
        $backUrl = $this->ballotUrl($nominee);
        $bail    = fn(string $why) => $this->redirect($res, $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'paid=' . urlencode($why));

        if (!PaidVoteService::enabled())                                     return $bail('off');
        if ($this->rateLimit && !$this->rateLimit->check(hash('sha256', $ip . '|paidvote'), 'paid_vote', 10, 3600)) {
            return $bail('rate');
        }
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
                'created_at'        => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            $this->log?->error('[paid-vote] could not persist pending order', ['err' => $e->getMessage()]);
            return $bail('error');
        }

        $callbackUrl = $this->base() . '/vote/paid/callback?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);
        $init = $this->payments->initialize($provider, $amount, $email, $reference, $callbackUrl, [
            'reference' => $reference, 'purpose' => 'paid-vote',
        ]);
        if (!$init['ok'] || empty($init['checkout_url'])) {
            DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'pending')->update(['status' => 'failed']);
            return $bail('start');
        }
        return $this->redirect($res, $init['checkout_url']);
    }

    /** GET /vote/paid/callback — browser return; re-verified server-to-server. */
    public function callback(Request $req, Response $res): Response
    {
        $q         = $req->getQueryParams();
        $reference = trim((string)($q['ref'] ?? $q['reference'] ?? $q['tx_ref'] ?? ''));
        $provider  = strtolower(trim((string)($q['provider'] ?? '')));
        if ($reference === '' || !$this->payments->isKnownProvider($provider)) {
            return $this->redirect($res, $this->base() . '/vote?paid=error');
        }
        $don = DB::table('gates_donations')->where('payment_ref', $reference)->where('tier', 'paid-vote')->first();
        if (!$don) return $this->redirect($res, $this->base() . '/vote?paid=error');

        $result = $this->confirm($provider, $reference, $don);
        if ($result === 'confirmed' || $result === 'already') {
            try { PaidVoteService::mint((int)$don->id); }
            catch (\Throwable $e) { $this->log?->error('[paid-vote] mint on callback failed', ['ref' => $reference, 'err' => $e->getMessage()]); }
            return $this->redirect($res, $this->base() . '/vote/paid/success?ref=' . urlencode($reference));
        }
        return $this->redirect($res, $this->base() . '/vote?paid=failed');
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
            'ballot_url'       => $this->ballotUrl($nominee),
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

    /** The nominee's ballot URL (or the vote index as a safe fallback). */
    private function ballotUrl(?object $nominee): string
    {
        if (!$nominee) return $this->base() . '/vote';
        try {
            $slug = DB::table('gates_award_categories as cat')
                ->join('gates_award_cycles as c', 'c.id', '=', 'cat.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('cat.id', (int)$nominee->category_id)
                ->value('p.slug');
            if ($slug) {
                $nameSlug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', (string)$nominee->name), '-'));
                return $this->base() . '/vote/' . $slug . '/' . (int)$nominee->id . '-' . $nameSlug;
            }
        } catch (\Throwable) {}
        return $this->base() . '/vote';
    }
}
