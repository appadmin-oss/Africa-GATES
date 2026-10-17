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
        private readonly ?LogService $log = null,
        private readonly string $secret = '',
    ) {}

    /** For tests: fn(string $url, string $body): array{code:int, body:string}. */
    public static $transport = null;

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
        return new self(GoogleMeetService::gasUrl(), $log, GoogleMeetService::gasSecret());
    }

    public function isConfigured(): bool { return $this->url !== '' && filter_var($this->url, FILTER_VALIDATE_URL); }

    public function push(string $sheet, array $row): bool
    {
        if (!$this->isConfigured()) return false;
        // The AfricaGATES_AppScript.gs contract: { sheet, data, source, token }. The token is the
        // same GAS_SECRET the calendar actions send; the script refuses a sheet write without
        // it once its SECRET is set, so nobody holding the /exec URL can write rows.
        $payload = ['sheet' => $sheet, 'data' => $row, 'source' => 'web', 'token' => $this->secret];
        try {
            $body = (string) json_encode($payload);
            if (is_callable(self::$transport)) {
                $r = (self::$transport)($this->url, $body);
                $code = (int) ($r['code'] ?? 0); $resp = (string) ($r['body'] ?? '');
            } else {
                $ch = curl_init($this->url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    // Apps Script answers a POST with a 302 to the script's output; following it is
                    // how the reply is read at all.
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => $body,
                ]);
                $resp = (string) curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
            // The script answers 200 whether or not it wrote anything — a bad token, an unknown
            // tab and a quota error are all `{"success":false}` under a 200 (respond() in the
            // .gs). So the body decides; a
            // status alone reported every refusal as a row written.
            $j = json_decode($resp, true);
            // Either spelling, as GoogleMeetService::call() reads it: {success} from sheet writes,
            // {ok} from a newer script.
            if ($code >= 200 && $code < 300 && is_array($j) && (bool) ($j['ok'] ?? $j['success'] ?? false)) return true;
            $this->log?->warn('gsheets.push.fail', ['sheet' => $sheet, 'code' => $code,
                'why' => is_array($j) ? (string) ($j['message'] ?? $j['error'] ?? '') : substr($resp, 0, 200)]);
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
