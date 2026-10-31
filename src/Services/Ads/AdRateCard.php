<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The published price of each placement, per 1,000 views, in naira.
 *
 * A rate card rather than an auction (owner, 27 Oct 2026): with few advertisers an auction
 * settles at its minimum every time, so it is a rate card that is harder to explain. Every
 * advertiser pays the same price for the same placement, the price is on /advertise before
 * anybody signs up, and an auction can be switched on later for a placement whose demand
 * outgrows its space.
 *
 * Set from the console (`ads_cpm_<placement>`), because there is no shell on production. An
 * unset or zero rate means the placement is not on sale; the trial still runs there, because
 * the trial is views and not money.
 *
 * A campaign keeps the rate it was booked at (gates_ad_sets.rates), so a change here never
 * re-prices one that is already running.
 */
final class AdRateCard
{
    public const KEY_PREFIX = 'ads_cpm_';

    /** Naira per 1,000 views, or null when the placement is not on sale. */
    public static function naira(string $placement): ?int
    {
        if (!isset(AdRules::PLACEMENTS[$placement])) return null;
        try {
            $v = DB::table('gates_settings')->where('key_name', self::KEY_PREFIX . $placement)->value('value');
        } catch (\Throwable) {
            return null;
        }
        $n = (int) trim((string) $v);
        return $n > 0 ? $n : null;
    }

    /** @return array<string,?int> every placement, naira per 1,000 views or null */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(AdRules::PLACEMENTS) as $p) $out[$p] = self::naira($p);
        return $out;
    }

    /** Is anything on sale at all? */
    public static function anyOnSale(): bool
    {
        return array_filter(self::all()) !== [];
    }

    /** The rates a new ad set is booked at, in kobo per 1,000 views. */
    public static function snapshot(array $placements): array
    {
        $out = [];
        foreach ($placements as $p) {
            $n = self::naira($p);
            if ($n !== null) $out[$p] = $n * 100;
        }
        return $out;
    }

    /** What a number of views costs at a booked rate, in kobo, rounded up to the kobo. */
    public static function cost(int $views, int $cpmKobo): int
    {
        return $views <= 0 || $cpmKobo <= 0 ? 0 : intdiv($views * $cpmKobo + 999, 1000);
    }

    /** Kobo as an advertiser reads it: ₦1,250, or ₦1,250.50 when there are kobo. */
    public static function format(int $kobo): string
    {
        return '₦' . number_format($kobo / 100, $kobo % 100 === 0 ? 0 : 2);
    }

    /** Save from the console. Anything that is not a whole positive number takes it off sale. */
    public static function save(array $in): void
    {
        foreach (array_keys(AdRules::PLACEMENTS) as $p) {
            if (!array_key_exists($p, $in)) continue;
            $n = max(0, min(1_000_000, (int) preg_replace('/[^0-9]/', '', (string) $in[$p])));
            DB::table('gates_settings')->updateOrInsert(['key_name' => self::KEY_PREFIX . $p], ['value' => $n > 0 ? (string) $n : '']);
        }
    }
}
