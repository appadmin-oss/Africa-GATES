<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

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

    private function base(): string { return rtrim((string)($_ENV['APP_URL'] ?? ''), '/'); }
    private function redirect(Response $res, string $url): Response { return $res->withHeader('Location', $url)->withStatus(302); }

    /** GET /donate — the giving page. */
    public function page(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'pages/donate.twig', [
            'page_title'       => 'Donate — Africa GATES',
            'meta_description' => 'Fund child leadership programmes across the continent — mentorship, scholarships and grassroots education. Every gift is receipted and independently audited.',
            'gates_page'       => 'donate',
            'has_hero'         => false,
            'providers'        => $this->payments->enabledProviders(),
            'stats'            => $this->stats(),
            'givers'           => $this->recentGivers(),
            'min_naira'        => self::MIN_NAIRA,
            'max_naira'        => self::MAX_NAIRA,
        ]);
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
        $name     = trim((string)($b['name'] ?? ''));
        $baseAmt  = (int) preg_replace('/[^0-9]/', '', (string)($b['amount'] ?? '0'));
        $cover    = !empty($b['cover_fees']);
        $ip       = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');

        $bail = fn(string $why) => $this->redirect($res, $this->base() . '/donate?give=' . urlencode($why));

        if ($this->rateLimit && !$this->rateLimit->check(hash('sha256', $ip . '|donate'), 'donate', 10, 3600)) {
            return $bail('rate');
        }
        if (!$this->payments->isEnabled($provider))                          return $bail('unavailable');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))     return $bail('email');
        if ($baseAmt < self::MIN_NAIRA)                                      return $bail('low');
        if ($baseAmt > self::MAX_NAIRA)                                      return $bail('high');

        // Optional processing-fee cover, computed SERVER-SIDE (never trust a client total).
        $amount = $cover ? (int) ceil($baseAmt * 1.015) : $baseAmt;
        $amount = min(self::MAX_NAIRA, max(self::MIN_NAIRA, $amount));

        $reference = 'AFG-GIVE-' . bin2hex(random_bytes(6));
        try {
            DB::table('gates_donations')->insert([
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
            ]);
        } catch (\Throwable $e) {
            $this->log?->error('[donate] could not persist pending donation', ['err' => $e->getMessage()]);
            return $bail('error');
        }

        $callbackUrl = $this->base() . '/donate/callback?provider=' . urlencode($provider) . '&ref=' . urlencode($reference);
        $init = $this->payments->initialize($provider, $amount, $email, $reference, $callbackUrl, [
            'reference' => $reference, 'purpose' => 'donation',
        ]);
        if (!$init['ok'] || empty($init['checkout_url'])) {
            DB::table('gates_donations')->where('payment_ref', $reference)->where('status', 'pending')->update(['status' => 'failed']);
            return $bail('start');
        }
        return $this->redirect($res, $init['checkout_url']);
    }

    /** GET /donate/callback — browser return; re-verified server-to-server. */
    public function callback(Request $req, Response $res): Response
    {
        $q         = $req->getQueryParams();
        $reference = trim((string)($q['ref'] ?? $q['reference'] ?? $q['tx_ref'] ?? ''));
        $provider  = strtolower(trim((string)($q['provider'] ?? '')));
        if ($reference === '' || !$this->payments->isKnownProvider($provider)) {
            return $this->redirect($res, $this->base() . '/donate?give=error');
        }
        $don = DB::table('gates_donations')->where('payment_ref', $reference)->first();
        if (!$don) return $this->redirect($res, $this->base() . '/donate?give=error');

        $result = $this->confirm($provider, $reference, $don);
        if ($result === 'confirmed' || $result === 'already') {
            if ($result === 'confirmed') $this->receipt($don);
            return $this->redirect($res, $this->base() . '/donate/success?ref=' . urlencode($reference));
        }
        return $this->redirect($res, $this->base() . '/donate?give=failed');
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
}
