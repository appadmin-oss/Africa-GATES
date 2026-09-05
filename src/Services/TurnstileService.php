<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Cloudflare Turnstile verification — bot protection for the OTP request that
 * opens every free vote.
 *
 * ── WHY THIS RETURNS A REASON AND NOT A BOOLEAN ──────────────────────────────
 *
 * It used to be `verify(): bool`, and the caller turned every false into one
 * message: "Bot verification failed. Please retry." That single sentence covered
 * four unrelated situations, three of which are OUR fault and none of which the
 * voter can act on:
 *
 *   • the widget had not finished solving when they clicked (they were early);
 *   • the token was already spent or older than 300s (they retried, and the page
 *     resent the same one — see the ballot's resetTurnstile());
 *   • Cloudflare could not be reached from this host (nothing to do with them);
 *   • a real refusal.
 *
 * Telling someone to "retry" when the retry is guaranteed to fail the same way is
 * worse than saying nothing. {@see check()} returns which of these happened so the
 * caller can say something true, and so the log records the actual error codes
 * instead of the fact that something went wrong.
 *
 * ── THE HALF-CONFIGURED PAIR ─────────────────────────────────────────────────
 *
 * Turnstile needs TWO keys that live in two variables, and the failure when only
 * one is set is silent and total in one direction:
 *
 *   • SECRET set, SITE KEY blank — the ballot renders no widget, so no browser on
 *     earth can produce a token, and every OTP request 403s forever. The site
 *     stops taking votes and the logs say "bot verification failed", which reads
 *     like the protection is working.
 *   • SITE KEY set, SECRET blank — the widget renders and is decorative. Harmless,
 *     but worth a warning, because the operator believes they are protected.
 *
 * The first case is treated as MISCONFIGURED and enforcement is skipped, loudly.
 * That is a deliberate call and it is not "failing open on error": with no site
 * key there is no challenge to pass, so refusing every request blocks humans and
 * bots identically — it does not protect the endpoint, it closes the ballot. The
 * OTP path keeps its own IP and per-email rate limits either way (10/hour and
 * 3/10min), so the endpoint is never bare. A key pair with a hole in it is a
 * configuration error, and the right response to a configuration error is a loud
 * log and a working ballot, not a silent outage.
 *
 * A genuine network failure still fails CLOSED, because there the challenge does
 * exist and skipping it would be a real bypass.
 */
final class TurnstileService {
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Cloudflare's codes for "this token is spent or too old" — a retry CAN fix these. */
    private const STALE_CODES = ['timeout-or-duplicate', 'invalid-input-response'];

    public function __construct(
        private readonly string $secret = '',
        private readonly ?LoggerInterface $log = null,
        private readonly ?Client $http = null,
        private readonly string $siteKey = '',
    ) {}

    /** Both halves present — the only state in which a challenge can be issued AND checked. */
    public function enabled(): bool { return $this->secret !== '' && $this->siteKey !== ''; }

    /**
     * A secret with no site key. Enforcing this configuration cannot be passed by
     * anyone, so it is a broken deployment rather than a strict one.
     */
    public function misconfigured(): bool { return $this->secret !== '' && $this->siteKey === ''; }

    /** A site key with no secret — the widget is shown but nothing checks it. */
    public function decorative(): bool { return $this->secret === '' && $this->siteKey !== ''; }

    /**
     * Verify a client token.
     *
     * @return array{ok:bool, code:string, message:string} code is one of
     *         OK · DISABLED · MISCONFIGURED · NO_TOKEN · STALE · REJECTED · UNREACHABLE
     */
    public function check(?string $token, string $ip = ''): array
    {
        if ($this->misconfigured()) {
            // Every request, on purpose: this must be impossible to miss in a log,
            // because the alternative symptom (votes quietly not happening) is not
            // visible anywhere at all.
            $this->log?->error('[turnstile] TURNSTILE_SECRET is set but TURNSTILE_SITE_KEY is empty — no '
                . 'browser can produce a token, so bot checks are being SKIPPED rather than failing every '
                . 'vote. Set both keys or clear both.');
            return ['ok' => true, 'code' => 'MISCONFIGURED', 'message' => ''];
        }
        if (!$this->enabled()) {
            if ($this->decorative()) {
                $this->log?->warning('[turnstile] TURNSTILE_SITE_KEY is set but TURNSTILE_SECRET is empty — '
                    . 'the widget is displayed but nothing verifies it.');
            }
            return ['ok' => true, 'code' => 'DISABLED', 'message' => ''];
        }

        $token = trim((string) $token);
        if ($token === '') {
            // Almost always a timing problem, not an attack: api.js loads async and
            // the challenge solves after the page is interactive, so a fast voter can
            // submit before there is anything to submit.
            return ['ok' => false, 'code' => 'NO_TOKEN',
                    'message' => 'The browser check has not finished yet. Wait a moment and try again.'];
        }

        try {
            $http = $this->http ?? new Client(['timeout' => 5]);
            $form = ['secret' => $this->secret, 'response' => $token];
            if ($ip !== '') $form['remoteip'] = $ip;
            $res  = $http->post(self::ENDPOINT, ['form_params' => $form]);
            $data = json_decode((string) $res->getBody(), true) ?: [];

            if (!empty($data['success'])) return ['ok' => true, 'code' => 'OK', 'message' => ''];

            $codes = array_map('strval', (array) ($data['error-codes'] ?? []));
            $this->log?->warning('[turnstile] challenge rejected', ['errors' => $codes]);

            if (array_intersect($codes, self::STALE_CODES)) {
                return ['ok' => false, 'code' => 'STALE',
                        'message' => 'That browser check has expired. We have reset it — please try again.'];
            }
            return ['ok' => false, 'code' => 'REJECTED',
                    'message' => 'The browser check did not pass. Please reload the page and try again.'];
        } catch (\Throwable $e) {
            // FAIL CLOSED, unlike the misconfigured case above: here the challenge
            // genuinely exists and skipping it would be a bypass an attacker could
            // provoke. The message says whose fault it is, because it is ours.
            $this->log?->error('[turnstile] could not reach Cloudflare: ' . $e->getMessage());
            return ['ok' => false, 'code' => 'UNREACHABLE',
                    'message' => 'We could not complete the security check just now. Please try again in a moment.'];
        }
    }

    /**
     * @deprecated Use {@see check()} — a bare bool cannot say why, and the caller
     *             then has to invent a message that is wrong three times in four.
     */
    public function verify(?string $token, string $ip = ''): bool
    {
        return $this->check($token, $ip)['ok'];
    }
}
