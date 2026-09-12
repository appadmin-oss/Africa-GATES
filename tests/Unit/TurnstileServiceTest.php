<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\TurnstileService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Tests\TestCase;

/**
 * The bot check that stopped people voting.
 *
 * Turnstile sat in front of every free vote and reported one thing — false — for
 * four unrelated situations, so the ballot said "Bot verification failed. Please
 * retry." to a voter whose retry could not possibly work. These tests pin the
 * distinctions, because the value of the change is entirely in WHICH answer
 * comes back, not in whether verification happens.
 */
final class TurnstileServiceTest extends TestCase
{
    private function svc(string $secret, string $siteKey, ?array $body = null, ?\Throwable $throw = null): TurnstileService
    {
        $http = null;
        if ($body !== null || $throw !== null) {
            $queued = $throw ?? new GuzzleResponse(200, [], (string) json_encode($body));
            $http = new Client(['handler' => HandlerStack::create(new MockHandler([$queued]))]);
        }
        return new TurnstileService($secret, null, $http, $siteKey);
    }

    /**
     * THE OUTAGE. A secret with no site key renders no widget, so no browser on earth
     * can produce a token — enforcing it 403s every vote while the log reads like the
     * protection is working. Treated as a configuration error, not as strictness.
     */
    public function test_a_secret_without_a_site_key_does_not_close_the_ballot(): void
    {
        $svc = $this->svc('sk_live_xxx', '');

        $this->assertTrue($svc->misconfigured());
        $this->assertFalse($svc->enabled(), 'half a key pair is not "enabled"');

        $r = $svc->check(null, '1.2.3.4');
        $this->assertTrue($r['ok'], 'blocking everyone protects nothing a bot was not already blocked from');
        $this->assertSame('MISCONFIGURED', $r['code']);
    }

    /** The mirror image: a widget nothing checks. Harmless, but not protection. */
    public function test_a_site_key_without_a_secret_is_decorative(): void
    {
        $svc = $this->svc('', '0x4AAA');

        $this->assertTrue($svc->decorative());
        $this->assertFalse($svc->enabled());
        $this->assertSame('DISABLED', $svc->check('anything')['code']);
    }

    public function test_both_keys_unset_is_simply_off(): void
    {
        $r = $this->svc('', '')->check(null);
        $this->assertTrue($r['ok']);
        $this->assertSame('DISABLED', $r['code']);
    }

    /**
     * An empty token is a TIMING problem, not an attack: api.js is async and the
     * challenge solves after the page is interactive, so a fast voter submits before
     * there is anything to submit. The message has to say "wait", not "you failed".
     */
    public function test_an_empty_token_says_the_check_has_not_finished(): void
    {
        $r = $this->svc('sk', '0x4AAA')->check('   ');

        $this->assertFalse($r['ok']);
        $this->assertSame('NO_TOKEN', $r['code']);
        $this->assertStringContainsString('has not finished', $r['message']);
    }

    /**
     * THE BUG USERS HIT. A token is single-use and expires after 300s, and the page
     * replayed the same one on every retry, so Cloudflare answered
     * `timeout-or-duplicate` forever. Distinguished from a real refusal because here
     * a retry genuinely does help — now that the client resets the widget.
     */
    public function test_a_spent_or_expired_token_is_reported_as_stale_not_rejected(): void
    {
        $svc = $this->svc('sk', '0x4AAA', ['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

        $r = $svc->check('used-token', '1.2.3.4');

        $this->assertFalse($r['ok']);
        $this->assertSame('STALE', $r['code']);
        $this->assertStringContainsString('expired', $r['message']);
        $this->assertStringContainsString('try again', $r['message'], 'and here that advice is actually true');
    }

    public function test_a_genuine_refusal_says_reload(): void
    {
        $svc = $this->svc('sk', '0x4AAA', ['success' => false, 'error-codes' => ['invalid-input-secret']]);

        $r = $svc->check('some-token');

        $this->assertFalse($r['ok']);
        $this->assertSame('REJECTED', $r['code']);
    }

    public function test_a_solved_challenge_passes(): void
    {
        $r = $this->svc('sk', '0x4AAA', ['success' => true])->check('good-token', '9.9.9.9');

        $this->assertTrue($r['ok']);
        $this->assertSame('OK', $r['code']);
        $this->assertSame('', $r['message']);
    }

    /**
     * Unreachable Cloudflare still fails CLOSED, and that asymmetry with the
     * misconfigured case is the point: here a challenge genuinely exists, so skipping
     * it would be a bypass an attacker could provoke. The message admits it is our end.
     */
    public function test_a_network_failure_fails_closed(): void
    {
        $svc = $this->svc('sk', '0x4AAA', null,
            new ConnectException('timeout', new GuzzleRequest('POST', 'https://challenges.cloudflare.com')));

        $r = $svc->check('good-token');

        $this->assertFalse($r['ok'], 'never skip a challenge that exists');
        $this->assertSame('UNREACHABLE', $r['code']);
        $this->assertStringNotContainsString('bot', strtolower($r['message']), 'do not blame the voter for our network');
    }

    /**
     * THERE IS ONE ENTRY POINT, AND IT RETURNS A REASON.
     *
     * This file used to end with `test_the_deprecated_boolean_wrapper_still_agrees`, over a
     * `verify(): bool` whose docblock said it was kept "for anything that has not moved to
     * check()". Nothing had not moved: the wrapper's only reference in the whole repository
     * was that test, so the deprecation was being kept alive by the test documenting it.
     *
     * Held structurally, because the cost of the wrapper coming back is not a duplicate
     * method — it is a caller with a bool in hand having to guess which of the three
     * outcomes it has, and writing "you look like a bot" over our own missing key.
     */
    public function test_the_service_offers_no_bare_boolean_check(): void
    {
        $this->assertFalse(method_exists(TurnstileService::class, 'verify'),
            'a bool cannot distinguish MISCONFIGURED (fail open, ours to fix) from a real '
            . 'failure (fail closed, do not blame the visitor) from UNREACHABLE (fail '
            . 'closed, blame us) — and the caller then invents the message');

        // And the one that remains carries the reason, which is the whole argument for
        // removing the other. Asserted on the shape rather than by enumerating this
        // class's public surface: `enabled()`, `misconfigured()` and `decorative()` are
        // legitimate questions about configuration, and a test that lists today's methods
        // fails on the next honest addition instead of on the fault.
        $r = $this->svc('sk', '0x4AAA')->check('');

        $this->assertArrayHasKey('ok', $r);
        $this->assertArrayHasKey('code', $r, 'a verdict with no code cannot be branched on');
        $this->assertArrayHasKey('message', $r,
            'without a message the caller writes one, and it is wrong three times in four');
    }
}
