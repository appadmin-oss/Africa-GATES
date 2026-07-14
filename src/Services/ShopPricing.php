<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Admin\Controllers\ProductsController;

/**
 * Location-based shop pricing (Netflix-style regional tiers). A per-region price
 * MULTIPLIER — admin-configured, stored as a JSON map in gates_settings
 * (key `shop_region_mult`) — scales the base naira price by the buyer's delivery
 * region. Unset / 1.0 = no change, so the shop behaves exactly as before until an
 * admin opts in. Static + DB-backed (like {@see WebhookService}) so the
 * authoritative checkout pricing ({@see ShopCheckoutController::priceCart}) can use
 * it without DI and stay unit-testable.
 */
final class ShopPricing
{
    public const DEFAULT_REGION = 'South West';
    public const COOKIE         = 'ag_region';

    /** Canonical region list — mirrors the delivery-region taxonomy (single source). */
    public static function regions(): array
    {
        return ProductsController::REGIONS;
    }

    /** region => multiplier, sanitised + clamped to 0.1..10. Unset → empty. */
    public static function multipliers(): array
    {
        try {
            $raw = DB::table('gates_settings')->where('key_name', 'shop_region_mult')->value('value');
        } catch (\Throwable $e) {
            return []; // settings table missing / DB down — fall back to base prices
        }
        $map = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $out = [];
        foreach ($map as $region => $m) {
            $m = (float) $m;
            if ($m > 0 && in_array($region, self::regions(), true)) {
                $out[$region] = max(0.1, min(10.0, $m));
            }
        }
        return $out;
    }

    /** Multiplier for one region (1.0 when unset). Pass $mults to avoid re-reading. */
    public static function multiplier(string $region, ?array $mults = null): float
    {
        $mults ??= self::multipliers();
        return $mults[$region] ?? 1.0;
    }

    /** Region-adjusted price in whole naira. */
    public static function adjust(int $baseNaira, string $region, ?array $mults = null): int
    {
        return (int) round($baseNaira * self::multiplier($region, $mults));
    }

    /** The buyer's current region from the cookie (validated), else the default. */
    public static function currentRegion(Request $req): string
    {
        $c = (string) ($req->getCookieParams()[self::COOKIE] ?? '');
        return in_array($c, self::regions(), true) ? $c : self::DEFAULT_REGION;
    }

    /** True when any region carries a non-1.0 multiplier (i.e. regional pricing is live). */
    public static function isActive(?array $mults = null): bool
    {
        $mults ??= self::multipliers();
        foreach ($mults as $m) { if (abs($m - 1.0) > 0.0001) return true; }
        return false;
    }
}
