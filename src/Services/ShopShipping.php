<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What delivery costs, per region — and the threshold above which it stops costing.
 *
 * ── WHY THE SHOP NEEDED THIS AT ALL ──────────────────────────────────────────
 *
 * Every order charged exactly the goods total. Delivery across Nigeria is not free and never
 * was, so one of two things was happening on every order: the seller was absorbing a courier
 * bill nobody had budgeted for, or the price had delivery quietly baked in — which means
 * somebody collecting in person in Lagos paid to ship to Maiduguri.
 *
 * ── AND WHY IT IS A SETTING RATHER THAN A TABLE ───────────────────────────────
 *
 * A flat rate per region is six numbers. {@see ShopPricing} already stores its six regional
 * multipliers as a JSON map in `gates_settings`, and this deliberately mirrors it — same
 * shape, same validation, same "unset behaves exactly as before" property. A table would be
 * six rows, a migration, an admin screen and a join, to hold six numbers that change twice a
 * year.
 *
 * ── FREE OVER A THRESHOLD, BECAUSE THAT IS HOW IT IS SOLD ────────────────────
 *
 * "Free delivery over ₦50,000" is the most effective thing a small shop can say, and it is
 * also honest: at that basket size the courier is a small fraction. Unset means no threshold,
 * not a threshold of zero — otherwise switching the feature on would make everything free.
 */
final class ShopShipping
{
    public const RATES_KEY     = 'shop_shipping';
    public const THRESHOLD_KEY = 'shop_ship_free_over';

    /** region => naira, whitelisted against the canonical regions and clamped. */
    public static function rates(): array
    {
        try {
            $raw = DB::table('gates_settings')->where('key_name', self::RATES_KEY)->value('value');
        } catch (\Throwable) {
            return [];
        }
        $map = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $out = [];
        foreach (is_array($map) ? $map : [] as $region => $naira) {
            if (!in_array($region, ShopPricing::regions(), true)) continue;
            $n = (int) $naira;
            // Zero is meaningful — 'we deliver free in the South West' — so it is kept rather
            // than treated as unset. The ceiling is a typo guard: a courier fee is not a
            // million naira, and a stray zero would be charged to somebody.
            if ($n >= 0) $out[$region] = min(1_000_000, $n);
        }
        return $out;
    }

    /** The basket size above which delivery is free, or null when there is no threshold. */
    public static function freeOver(): ?int
    {
        try {
            $raw = DB::table('gates_settings')->where('key_name', self::THRESHOLD_KEY)->value('value');
        } catch (\Throwable) {
            return null;
        }
        $n = (int) $raw;
        return $n > 0 ? $n : null;
    }

    /** True when an admin has configured any delivery charge at all. */
    public static function isActive(?array $rates = null): bool
    {
        $rates ??= self::rates();
        foreach ($rates as $n) { if ((int) $n > 0) return true; }
        return false;
    }

    /**
     * What this basket costs to deliver, and why.
     *
     * ── WHY THE REASON IS RETURNED, NOT JUST THE NUMBER ──────────────────────
     *
     * A delivery line that reads "₦0" makes a buyer wonder whether it will be added later.
     * "Free — every item in this order includes delivery" and "Free — orders over ₦50,000
     * ship free" are different promises, and the second one is worth telling somebody who is
     * ₦4,000 short of it.
     *
     * @param list<array<string,mixed>> $lines priced cart lines, each with ships_free set
     * @return array{naira:int, why:string, free_over:?int, short_by:int}
     */
    public static function quote(array $lines, string $region, int $goodsTotal): array
    {
        $rates = self::rates();
        $rate  = (int) ($rates[$region] ?? 0);
        $over  = self::freeOver();

        if ($lines === []) {
            return ['naira' => 0, 'why' => '', 'free_over' => $over, 'short_by' => 0];
        }

        if ($rate === 0) {
            return ['naira' => 0, 'why' => self::isActive($rates)
                ? 'Free delivery to ' . $region . '.'
                : '', 'free_over' => $over, 'short_by' => 0];
        }

        // Every item posts free. Charging a delivery fee on a basket of envelope-sized
        // keepsakes is how a ₦4,000 order gets abandoned at the last screen.
        $allFree = true;
        foreach ($lines as $l) {
            if (empty($l['ships_free'])) { $allFree = false; break; }
        }
        if ($allFree) {
            return ['naira' => 0, 'why' => 'Delivery is included in these prices.',
                    'free_over' => $over, 'short_by' => 0];
        }

        if ($over !== null && $goodsTotal >= $over) {
            return ['naira' => 0, 'why' => 'Free — orders over ₦' . number_format($over) . ' ship free.',
                    'free_over' => $over, 'short_by' => 0];
        }

        return ['naira' => $rate,
                'why' => 'Delivery to ' . $region . '.',
                'free_over' => $over,
                // How much more they would need to spend. The number that turns a delivery
                // charge into a reason to add one more thing.
                'short_by' => $over !== null ? max(0, $over - $goodsTotal) : 0];
    }
}
