<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * WHICH OF AFRICA'S FIVE REGIONS A COUNTRY IS IN — one table, the UN M49 geoscheme.
 *
 * The region filters the redesign draws (Leaderboard, Legacy Vault) say "West Africa",
 * "East Africa"… and the only region the platform stored was `gates_profiles.region`, an
 * ENUM a person picks. Anything else that has a country — an archived edition, a nominee —
 * needs the region DERIVED, and deriving it in each page is how two pages come to disagree
 * about where Sudan is. So it is here, keyed by ISO 3166-1 alpha-2, under the same five
 * keys the profile ENUM uses (`west`, `east`, `north`, `central`, `south`), so a filter
 * value means one thing on every page.
 *
 * M49 is used as published, including its less intuitive placements (Sudan in North Africa,
 * Zambia and Zimbabwe in East Africa) — a classification nobody here invented is one
 * nobody here has to defend.
 */
final class AfricaRegion
{
    public const LABELS = [
        'west'    => 'West Africa',
        'east'    => 'East Africa',
        'north'   => 'North Africa',
        'central' => 'Central Africa',
        'south'   => 'Southern Africa',
    ];

    public const MAP = [
        // Northern Africa
        'DZ' => 'north', 'EG' => 'north', 'LY' => 'north', 'MA' => 'north', 'SD' => 'north', 'TN' => 'north', 'EH' => 'north',
        // Western Africa
        'BJ' => 'west', 'BF' => 'west', 'CV' => 'west', 'CI' => 'west', 'GM' => 'west', 'GH' => 'west', 'GN' => 'west',
        'GW' => 'west', 'LR' => 'west', 'ML' => 'west', 'MR' => 'west', 'NE' => 'west', 'NG' => 'west', 'SN' => 'west',
        'SL' => 'west', 'TG' => 'west', 'SH' => 'west',
        // Eastern Africa
        'BI' => 'east', 'KM' => 'east', 'DJ' => 'east', 'ER' => 'east', 'ET' => 'east', 'KE' => 'east', 'MG' => 'east',
        'MW' => 'east', 'MU' => 'east', 'YT' => 'east', 'MZ' => 'east', 'RE' => 'east', 'RW' => 'east', 'SC' => 'east',
        'SO' => 'east', 'SS' => 'east', 'TZ' => 'east', 'UG' => 'east', 'ZM' => 'east', 'ZW' => 'east',
        // Middle Africa
        'AO' => 'central', 'CM' => 'central', 'CF' => 'central', 'TD' => 'central', 'CG' => 'central', 'CD' => 'central',
        'GQ' => 'central', 'GA' => 'central', 'ST' => 'central',
        // Southern Africa
        'BW' => 'south', 'SZ' => 'south', 'LS' => 'south', 'NA' => 'south', 'ZA' => 'south',
    ];

    /** The region key for a country, or null for one outside Africa or unknown. */
    public static function of(?string $countryCode): ?string
    {
        $cc = strtoupper(trim((string) $countryCode));
        return self::MAP[$cc] ?? null;
    }
}
