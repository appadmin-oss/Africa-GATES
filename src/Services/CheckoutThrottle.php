<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\ClientIp;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The abuse control on the three checkout paths that take money — paid votes,
 * donations, and shop/payment init.
 *
 * ── WHAT IT IS ACTUALLY DEFENDING ────────────────────────────────────────────
 *
 * Not fraud. Every amount on those paths is priced server-side and re-verified
 * server-to-server before anything is credited, so a caller cannot make money appear
 * by hammering `start`. What they CAN generate is churn: a pending `gates_donations`
 * row and a gateway session per request. So the thing being limited is table growth
 * and gateway noise — a nuisance, not a loss.
 *
 * That matters because it fixes the ceiling. The old policy was TEN checkout starts
 * per hour per `REMOTE_ADDR`, which is a number you would choose if you believed each
 * attempt cost you something. Behind Cloudflare, `REMOTE_ADDR` is one edge address
 * for the entire internet, so the platform's paid-voting business had a hard ceiling
 * of ten checkouts an hour in total, and supporter number eleven was shown "That is a
 * lot of vote purchases from this network — please try again shortly." on their first
 * ever click. A revenue page that refuses payment is the most expensive possible
 * failure mode, and it was silent: the refusal looks like caution, not like a bug.
 *
 * ── THE POLICY ───────────────────────────────────────────────────────────────
 *
 * Two buckets, for the reasons set out on {@see ClientIp}:
 *
 *   • PER BROWSER — 30/hour. Tight, fair, and immune to carrier NAT. Thirty separate
 *     card checkouts in one hour from one browser is already far past any real
 *     supporter; a nominee's own campaign buying repeatedly stays well inside it.
 *   • PER NETWORK — 600/hour. A backstop on scripted volume only. Deliberately far
 *     above any honest shared-IP peak (a university, an office, an MTN CGNAT pool, a
 *     watch party rallying for one nominee) so it cannot be the thing that stops a
 *     real supporter. If it ever trips on real traffic, that is a capacity signal
 *     worth having, and the buyer is told exactly when to retry rather than left to
 *     guess.
 *
 * ── AND IT IS CHECKED LAST ───────────────────────────────────────────────────
 *
 * Callers must consult this AFTER validating the order, never before. The old order
 * charged a slot for every rejected attempt, so a supporter who mistyped their email
 * spent quota on a request that never reached a gateway — three typos and a fat
 * thumb, and their real payment is the one that gets refused. Only a request that is
 * about to create a pending row and a gateway session costs anything, so only that
 * request is counted.
 *
 * A refusal here is never a dead end: {@see allow()} returns the seconds remaining so
 * the page can say when, and the caller re-renders the form with the buyer's values
 * intact.
 */
final class CheckoutThrottle
{
    public const PER_BROWSER        = 30;
    public const BROWSER_WINDOW     = 3600;
    public const PER_NETWORK        = 600;
    public const NETWORK_WINDOW     = 3600;

    public function __construct(private readonly ?RateLimitService $limits = null) {}

    /**
     * May this request start a checkout?
     *
     * Consumes one slot from each bucket it evaluates, so call it exactly once per
     * attempt and only when the attempt is otherwise valid. The browser bucket is
     * evaluated first: when it is the one that trips, the network bucket is left
     * untouched, so one busy browser cannot erode the headroom everyone else on its
     * carrier is sharing.
     *
     * With no RateLimitService wired (unit tests, CLI) it allows — the limiter is an
     * abuse control, not a correctness gate, and failing closed here would mean a
     * misconfiguration silently stops all payments.
     *
     * @param string $action Short scope name, e.g. 'paid_vote' | 'donate' | 'pay_init'.
     * @return array{ok:bool, retry_after:int, scope:string}
     */
    public function allow(Request $req, string $action): array
    {
        if ($this->limits === null) {
            return ['ok' => true, 'retry_after' => 0, 'scope' => ''];
        }

        $browserFp = ClientIp::fingerprint(ClientIp::browser($req), $action . ':browser');
        if (!$this->limits->check($browserFp, $action . '_browser', self::PER_BROWSER, self::BROWSER_WINDOW)) {
            return [
                'ok'          => false,
                'retry_after' => $this->limits->retryAfter($browserFp, $action . '_browser', self::BROWSER_WINDOW),
                'scope'       => 'browser',
            ];
        }

        $ip = ClientIp::from($req);
        if ($ip === '') {
            // No resolvable address (a malformed REMOTE_ADDR, or a SAPI that omits
            // it). Hashing '' would put every such request in one bucket, which is
            // the very collision this class exists to remove, so the network backstop
            // is skipped and the browser bucket stands alone.
            return ['ok' => true, 'retry_after' => 0, 'scope' => ''];
        }

        $netFp = ClientIp::fingerprint($ip, $action . ':net');
        if (!$this->limits->check($netFp, $action . '_net', self::PER_NETWORK, self::NETWORK_WINDOW)) {
            return [
                'ok'          => false,
                'retry_after' => $this->limits->retryAfter($netFp, $action . '_net', self::NETWORK_WINDOW),
                'scope'       => 'network',
            ];
        }

        return ['ok' => true, 'retry_after' => 0, 'scope' => ''];
    }

    /**
     * "in about 4 minutes" / "shortly" — the actionable half of a refusal.
     *
     * Rounded up to whole minutes because a countdown in seconds invites the buyer to
     * sit on the page retrying, and the window is fixed rather than sliding, so a
     * minute's imprecision costs them nothing.
     */
    public static function retryPhrase(int $seconds): string
    {
        if ($seconds <= 30)   return 'in a moment';
        if ($seconds < 120)   return 'in about a minute';
        if ($seconds < 3600)  return 'in about ' . (int) ceil($seconds / 60) . ' minutes';
        return 'in about an hour';
    }
}
