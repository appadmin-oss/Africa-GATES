<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CheckoutThrottle;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Support\ClientIp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The throttle on the paths that take money.
 *
 * WHY THIS FILE IS MOSTLY ABOUT NOT BLOCKING. The reported failure was a supporter
 * seeing "That is a lot of vote purchases from this network — please try again shortly."
 * on their first ever attempt to buy a vote. Nothing was wrong with their request: the
 * limiter was keyed on `REMOTE_ADDR`, which behind Cloudflare is one edge address shared
 * by every visitor on the platform, and the cap was ten per hour. So the assertions
 * below are weighted toward the cases where a refusal must NOT happen —
 *
 *   • two different browsers behind ONE address (carrier NAT, an office, a watch party)
 *     must not consume each other's quota,
 *   • a rejected order (bad email, closed voting) must not cost a slot at all,
 *   • the real ceiling must be high enough that no plausible honest volume reaches it,
 *
 * — because "blocked a paying supporter" is the expensive failure here and "let a script
 * write a few hundred extra pending rows" is the cheap one.
 */
class CheckoutThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // ClientIp::browser() prefers the session token; with no active session it falls
        // back to the IP, which is what these request-level tests exercise.
        $_SESSION = [];
    }

    private function requestFrom(string $ip, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://afg.test/vote/paid/start', ['REMOTE_ADDR' => $ip]);
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        return $req;
    }

    public function test_a_first_time_buyer_is_never_refused(): void
    {
        $gate = new CheckoutThrottle(new RateLimitService());
        $this->assertTrue($gate->allow($this->requestFrom('102.89.34.7'), 'paid_vote')['ok']);
    }

    /**
     * The regression that produced the screenshot. Thirty-one starts from ONE browser
     * trips at thirty-one, not at eleven, and the ceiling is a per-browser one.
     */
    public function test_browser_ceiling_is_thirty_not_ten(): void
    {
        $gate = new CheckoutThrottle(new RateLimitService());
        $req  = $this->requestFrom('102.89.34.7');

        for ($i = 1; $i <= CheckoutThrottle::PER_BROWSER; $i++) {
            $this->assertTrue($gate->allow($req, 'paid_vote')['ok'], "checkout #{$i} should be allowed");
        }
        $blocked = $gate->allow($req, 'paid_vote');
        $this->assertFalse($blocked['ok']);
        $this->assertSame('browser', $blocked['scope']);
        $this->assertGreaterThan(0, $blocked['retry_after'], 'a refusal must say when to come back');
    }

    /**
     * The heart of the fix. With no session, the browser bucket falls back to the IP, so
     * this measures the ceiling for a whole shared address — and it must be the NETWORK
     * cap, not the per-browser one. Under the old policy the eleventh purchase from an
     * MTN CGNAT pool was refused; here 400 consecutive ones are not.
     */
    public function test_hundreds_of_purchases_from_one_shared_address_are_allowed(): void
    {
        $gate = new CheckoutThrottle(new RateLimitService());

        // Distinct browsers behind one carrier address, which is what a session token
        // models. 400 is comfortably past the old ceiling of 10 and past any real burst.
        for ($i = 0; $i < 400; $i++) {
            $_SESSION = ['afg_throttle_id' => 'browser-' . $i];
            $r = $gate->allow($this->requestFrom('105.112.7.9'), 'paid_vote');
            $this->assertTrue($r['ok'], "supporter #{$i} behind the shared address should not be refused");
        }
    }

    /** One busy browser must not erode the headroom its whole carrier is sharing. */
    public function test_a_blocked_browser_does_not_consume_network_headroom(): void
    {
        $rl   = new RateLimitService();
        $gate = new CheckoutThrottle($rl);
        $_SESSION = ['afg_throttle_id' => 'greedy'];
        $req = $this->requestFrom('41.58.2.2');

        for ($i = 0; $i < CheckoutThrottle::PER_BROWSER + 20; $i++) {
            $gate->allow($req, 'paid_vote');
        }

        // The network bucket saw only the requests that passed the browser gate.
        $netFp = ClientIp::fingerprint('41.58.2.2', 'paid_vote:net');
        $row = \Illuminate\Database\Capsule\Manager::table('gates_rate_limits')
            ->where('fingerprint', $netFp)->where('action', 'paid_vote_net')->first();
        $this->assertNotNull($row);
        $this->assertSame(CheckoutThrottle::PER_BROWSER, (int) $row->hit_count,
            'the 20 attempts refused at the browser gate must not have been charged to the network');

        // And a different supporter on the same address still gets through.
        $_SESSION = ['afg_throttle_id' => 'innocent'];
        $this->assertTrue($gate->allow($req, 'paid_vote')['ok']);
    }

    /** Separate scopes are separate buckets: buying votes must not exhaust donating. */
    public function test_actions_do_not_share_a_bucket(): void
    {
        $gate = new CheckoutThrottle(new RateLimitService());
        $_SESSION = ['afg_throttle_id' => 'one-browser'];
        $req = $this->requestFrom('102.89.34.7');

        for ($i = 0; $i < CheckoutThrottle::PER_BROWSER; $i++) {
            $gate->allow($req, 'paid_vote');
        }
        $this->assertFalse($gate->allow($req, 'paid_vote')['ok'], 'paid votes exhausted');
        $this->assertTrue($gate->allow($req, 'donate')['ok'], 'donating is a different bucket');
        $this->assertTrue($gate->allow($req, 'pay_init')['ok'], 'the shop is a different bucket');
    }

    /** With no limiter wired the gate opens: a misconfiguration must not stop payments. */
    public function test_missing_limiter_fails_open(): void
    {
        $this->assertTrue((new CheckoutThrottle(null))->allow($this->requestFrom('1.1.1.1'), 'paid_vote')['ok']);
    }

    public function test_retry_phrase_is_actionable_and_never_a_bare_number(): void
    {
        $this->assertSame('in a moment', CheckoutThrottle::retryPhrase(10));
        $this->assertSame('in about a minute', CheckoutThrottle::retryPhrase(90));
        $this->assertSame('in about 5 minutes', CheckoutThrottle::retryPhrase(241));
        $this->assertSame('in about an hour', CheckoutThrottle::retryPhrase(3600));
    }

    // ── ClientIp ─────────────────────────────────────────────────────────────

    /**
     * The bug's mechanism, pinned. `REMOTE_ADDR` behind a CDN is the edge, and the
     * forwarded header is what identifies the visitor — but only when TRUST_PROXY says a
     * proxy we control sets it, because on a directly-exposed host a client that can
     * choose its own header can mint a fresh identity per request.
     */
    public function test_forwarded_headers_are_used_only_when_the_proxy_is_trusted(): void
    {
        $req = $this->requestFrom('172.68.1.1', ['CF-Connecting-IP' => '102.89.34.7']);

        $_ENV['TRUST_PROXY'] = 'false';
        $this->assertSame('172.68.1.1', ClientIp::from($req), 'untrusted: the header must be ignored');

        $_ENV['TRUST_PROXY'] = 'true';
        $this->assertSame('102.89.34.7', ClientIp::from($req), 'trusted: the real client, not the Cloudflare edge');

        unset($_ENV['TRUST_PROXY']);
    }

    public function test_x_forwarded_for_takes_the_left_most_valid_address(): void
    {
        $_ENV['TRUST_PROXY'] = 'true';
        $req = $this->requestFrom('10.0.0.1', ['X-Forwarded-For' => 'not-an-ip, 197.210.5.5, 172.68.1.1']);
        $this->assertSame('197.210.5.5', ClientIp::from($req));
        unset($_ENV['TRUST_PROXY']);
    }

    /** A garbage address must not become a fingerprint everybody shares. */
    public function test_an_unparseable_address_is_empty_not_a_shared_bucket(): void
    {
        $this->assertSame('', ClientIp::from($this->requestFrom('unix:')));
        $this->assertSame('', ClientIp::from($this->requestFrom('')));
    }

    public function test_fingerprint_fits_the_rate_limit_column(): void
    {
        // gates_rate_limits.fingerprint is VARCHAR(64) — exactly one hex SHA-256. A
        // longer value would be silently truncated on MySQL in non-strict mode, which
        // would merge distinct identities into one bucket.
        $fp = ClientIp::fingerprint('102.89.34.7', 'paid_vote:net');
        $this->assertSame(64, strlen($fp));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fp);
    }
}
