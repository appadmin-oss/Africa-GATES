<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Cloudflare Turnstile verification — privacy-friendly bot protection for the
 * voting + OTP request flow.
 *
 * Fully optional: when TURNSTILE_SECRET is unset the service is "disabled" and
 * verify() returns true, so the site works identically without a key. When a
 * secret is present, a valid client token is required.
 */
final class TurnstileService {
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly string $secret = '',
        private readonly ?LoggerInterface $log = null,
        private readonly ?Client $http = null,
    ) {}

    public function enabled(): bool { return $this->secret !== ''; }

    /** True if the challenge passes (or protection is disabled). Fail-closed when enabled. */
    public function verify(?string $token, string $ip = ''): bool {
        if (!$this->enabled()) return true;            // no key configured → skip
        $token = trim((string)$token);
        if ($token === '') return false;

        try {
            $http = $this->http ?? new Client(['timeout' => 5]);
            $form = ['secret' => $this->secret, 'response' => $token];
            if ($ip !== '') $form['remoteip'] = $ip;
            $res = $http->post(self::ENDPOINT, ['form_params' => $form]);
            $data = json_decode((string)$res->getBody(), true) ?: [];
            $ok = (bool)($data['success'] ?? false);
            if (!$ok) $this->log?->warning('[turnstile] challenge failed', ['errors' => $data['error-codes'] ?? []]);
            return $ok;
        } catch (\Throwable $e) {
            // Network/transport problem: log and fail-closed so abuse can't slip
            // through during an outage, but only when protection is enabled.
            $this->log?->error('[turnstile] verify error: ' . $e->getMessage());
            return false;
        }
    }
}
