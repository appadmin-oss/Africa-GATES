<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Admin\Services\LogService;

/**
 * Pushes events to a Google Apps Script web app endpoint that writes them
 * into a private Google Sheet. The Apps Script source lives at
 * config/AfricaGATES_AppScript.gs — deploy it as a "Web app" (execute as you,
 * access: anyone) and put the /exec URL into .env  → GAS_URL.
 *
 * All sends are best-effort + non-blocking (3s timeout, swallow errors).
 * If GAS_URL is empty or send fails, the calling controller still succeeds.
 */
class GoogleSheetsService
{
    public function __construct(
        private readonly string $url = '',
        private readonly ?LogService $log = null
    ) {}

    /**
     * The same door the calendar uses, resolved the same way.
     *
     * Sheets and Calendar are two actions on ONE Apps Script deployment, so they share the
     * /exec URL. It resolves from `gates_settings` first and `.env` second — see
     * {@see GoogleMeetService::gasUrl()} for why `.env` alone was not enough on a host with
     * no shell. Two resolvers for one value is how the two halves of this integration would
     * come to disagree about whether it is configured.
     */
    public static function boot(?LogService $log = null): self
    {
        return new self(GoogleMeetService::gasUrl(), $log);
    }

    public function isConfigured(): bool { return $this->url !== '' && filter_var($this->url, FILTER_VALIDATE_URL); }

    public function push(string $sheet, array $row): bool
    {
        if (!$this->isConfigured()) return false;
        // Matches the existing AfricaGATES_AppScript.gs payload contract:
        // { sheet, data, source }
        $payload = ['sheet' => $sheet, 'data' => $row, 'source' => 'web'];
        try {
            $ch = curl_init($this->url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 400) return true;
            $this->log?->warn('gsheets.push.fail', ['sheet' => $sheet, 'code' => $code, 'resp' => substr((string)$resp, 0, 200)]);
            return false;
        } catch (\Throwable $e) {
            $this->log?->warn('gsheets.push.exception', ['sheet' => $sheet, 'err' => $e->getMessage()]);
            return false;
        }
    }

    // ─── Convenience helpers (sheet tab names match the existing GAS) ─
    public function pushNomination(array $data): void   { $this->push('nominations',   $data); }
    public function pushRegistration(array $data): void { $this->push('registrations', $data); }
    public function pushPartnerEnquiry(array $data): void { $this->push('partners',     $data); }
    public function pushVote(array $data): void         { $this->push('votes',         $data); }
}
