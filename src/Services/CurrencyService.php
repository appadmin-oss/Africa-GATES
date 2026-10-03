<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Currency conversion for display. Base prices are stored + charged in Naira (NGN);
 * this resolves them into the visitor's chosen local currency for the storefront.
 *
 * Rates come from a free, no-key API (open.er-api.com, base=NGN), cached for 12h
 * via {@see CacheService} and backed by a static fallback table, so a slow or down
 * API never breaks the shop. Conversion is DISPLAY-ONLY — the payment gateway is
 * always charged the authoritative NGN amount ({@see ShopCheckoutController::priceCart}).
 *
 * The whole feature is gated by the admin setting `currency_conversion` (off by
 * default), so prices stay in ₦ until an admin opts in.
 */
final class CurrencyService
{
    public const BASE   = 'NGN';
    public const COOKIE = 'ag_currency';

    /** Supported display currencies => symbol. */
    public const CURRENCIES = [
        'NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€',
        'GHS' => 'GH₵', 'KES' => 'KSh', 'ZAR' => 'R', 'CAD' => 'C$', 'XOF' => 'CFA',
    ];

    /** Approximate NGN→X rates used only if the live API is unreachable. */
    private const FALLBACK = [
        'USD' => 0.00065, 'GBP' => 0.00051, 'EUR' => 0.00060, 'GHS' => 0.0098,
        'KES' => 0.084, 'ZAR' => 0.0118, 'CAD' => 0.00089, 'XOF' => 0.39,
    ];

    public function __construct(private readonly CacheService $cache) {}

    /** Admin master switch — currency conversion is off until explicitly enabled. */
    public function enabled(): bool
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'currency_conversion')->value('value');
        } catch (\Throwable $e) {
            return false;
        }
        return in_array((string) $v, ['1', 'on', 'true', 'yes'], true);
    }

    /** @return array<string,string> code => symbol */
    public function currencies(): array { return self::CURRENCIES; }

    public function isValid(string $code): bool { return isset(self::CURRENCIES[$code]); }

    public function symbol(string $code): string { return self::CURRENCIES[$code] ?? $code; }

    /** The visitor's chosen currency (cookie), validated; else NGN. */
    public function current(Request $req): string
    {
        $c = (string) ($req->getCookieParams()[self::COOKIE] ?? self::BASE);
        return $this->isValid($c) ? $c : self::BASE;
    }

    /** NGN→$code rate (1.0 for NGN). Live (cached) with static fallback. */
    public function rate(string $code): float
    {
        if ($code === self::BASE) return 1.0;
        $rates = $this->rates();
        $r = $rates[$code] ?? self::FALLBACK[$code] ?? null;
        return ($r !== null && $r > 0) ? (float) $r : 1.0;
    }

    /** All NGN→X rates (cached 12h). */
    public function rates(): array
    {
        return $this->cache->remember('fx:ngn:v1', 43200, fn () => $this->fetchRates());
    }

    public function convert(int $ngn, string $code): float
    {
        return $ngn * $this->rate($code);
    }

    /** Formatted price string in the given currency (₦12,000 / $7.80 / £6.12). */
    public function format(int $ngn, string $code): string
    {
        if ($code === self::BASE || !$this->isValid($code)) {
            return '₦' . number_format($ngn);
        }
        $v = $this->convert($ngn, $code);
        return $this->symbol($code) . number_format($v, $v < 100 ? 2 : 0);
    }

    /** Fetch NGN→X from the free API; fall back to the static table on any failure. */
    private function fetchRates(): array
    {
        $out = self::FALLBACK;
        try {
            $ch = curl_init('https://open.er-api.com/v6/latest/NGN');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: AfricaGates/1'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (is_string($body) && $code === 200) {
                $j = json_decode($body, true);
                if (is_array($j) && ($j['result'] ?? '') === 'success' && !empty($j['rates'])) {
                    foreach (self::CURRENCIES as $c => $_) {
                        if ($c !== self::BASE && isset($j['rates'][$c]) && (float) $j['rates'][$c] > 0) {
                            $out[$c] = (float) $j['rates'][$c];
                        }
                    }
                }
            }
        } catch (\Throwable $e) { /* keep fallback */ }
        return $out;
    }
}
