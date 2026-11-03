<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What each placement is worth, measured, and the price that follows from it.
 *
 * Owner, 3 Nov 2026: "cost should also vary for high-impact positions … the better the
 * chances of getting people, the higher the cost. Make it dynamic." The rate card was one
 * number per placement typed in the console, so a placement where people tap ads three
 * times as often as anywhere else sold for whatever somebody guessed, for ever, and a
 * placement nobody booked never got cheaper.
 *
 * ── THE PRICE ───────────────────────────────────────────────────────────────
 *
 *     price = base × quality × demand,   kept between FLOOR and CEIL of the base
 *
 *   base     the console's number for the placement — the operator's judgement of what it
 *            is worth, and still the switch that puts it on sale (blank = not on sale).
 *   quality  how often people ACT on an ad there, against the platform: its click rate,
 *            smoothed toward the platform's so a lucky week cannot double a price, raised
 *            to Q_EXP so twice the click rate is not twice the price, and clamped. A view
 *            is only billed when it was seen (AdDelivery), so where an ad is SEEN is
 *            already paid for per view; what makes one view worth more than another is
 *            the chance it reaches somebody who does something about it.
 *   demand   how much of the next HORIZON days is already booked by paid campaigns,
 *            against what the placement can deliver in that time. Under-sold space is
 *            discounted to fill it; nearly sold-out space rises, because the last
 *            thousand views of a busy week are the scarce ones.
 *
 * Neither multiplier moves until it is a MEASUREMENT: quality waits for MIN_VIEWS seen
 * views in the window, demand for INVENTORY_DAYS days of inventory. Until then the price is
 * the base, exactly — an engine that adjusts on three days of a new placement is guessing,
 * and a guess printed as a price is the thing this repo keeps paying for.
 *
 * ── ONE PRICE A DAY ─────────────────────────────────────────────────────────
 *
 * Worked out on the first read of the day and written to `gates_ad_prices`, so everybody
 * booking that day pays the same price (the rate card's promise, kept), the figure cannot
 * move between the quote and the checkout, and the history of what was charged and why can
 * be read back. A campaign keeps the rate it was booked at (gates_ad_sets.rates), so a
 * price moving never re-prices anything already running. Changing the base in the console
 * re-works the day's price at once: a stored price under an old base is not today's price.
 *
 * `ads_pricing_mode = fixed` turns all of it off and sells at the base, for an operator who
 * wants the old card back without losing their numbers.
 */
final class AdMarket
{
    public const WINDOW_DAYS    = 28;     // the measurements look back this far
    public const MIN_VIEWS      = 1000;   // seen views in the window before a click rate is trusted
    public const PRIOR_VIEWS    = 2000;   // weight of the platform's click rate in the smoothing
    public const INVENTORY_DAYS = 7;      // days of inventory history before demand counts
    public const HORIZON        = 7;      // demand is the next week's bookings against the next week's supply
    public const SHARE_MAX      = 0.5;    // one campaign never plans on more than half a placement

    public const Q_EXP = 0.7;
    public const Q_MIN = 0.6;
    public const Q_MAX = 2.0;
    public const D_MIN = 0.85;
    public const D_MAX = 1.5;
    public const FLOOR = 0.6;             // of the base
    public const CEIL  = 2.5;             // of the base

    /** Before anything is measured, the click rate a plan assumes (0.8%): stated as an assumption. */
    public const ASSUMED_CTR = 0.008;
    /** And the share of drawn slots assumed to become a seen view. */
    public const ASSUMED_VIEWABILITY = 0.5;

    public const MODE_KEY   = 'ads_pricing_mode';
    public const TARGET_KEY = 'ads_revenue_target_naira';

    /**
     * Where each placement sits and who is reading it. What the advertiser is told on
     * /advertise and in the builder; the label is AdRules::PLACEMENTS.
     */
    public const POSITIONS = [
        'event_page' => ['where' => 'On an event’s own page, under the tickets', 'who' => 'People deciding whether to go'],
        'events'     => ['where' => 'In the list of events, after what is on', 'who' => 'People looking for something to attend'],
        'discover'   => ['where' => 'Between awards and results, where people look for things', 'who' => 'People exploring the site'],
        'vote_hub'   => ['where' => 'Under the list of awards people vote in', 'who' => 'Voters choosing an award'],
    ];

    /** @var array<string,array<string,mixed>> */
    private static array $memo = [];

    public static function forget(): void
    {
        self::$memo = [];
    }

    public static function dynamic(): bool
    {
        try {
            return trim((string) (DB::table('gates_settings')->where('key_name', self::MODE_KEY)->value('value') ?? '')) !== 'fixed';
        } catch (\Throwable) {
            return true;
        }
    }

    /** Today's price per 1,000 views, in naira, or null when the placement is not on sale. */
    public static function price(string $placement, ?string $day = null): ?int
    {
        $q = self::quote($placement, $day);
        return $q === null ? null : (int) $q['cpm'];
    }

    /**
     * The day's price and what it was worked out from.
     *
     * @return array{cpm:int, base:int, quality:float, demand:float, sell:?float, ctr:?float,
     *               viewability:?float, slots:int, measured:bool}|null
     */
    public static function quote(string $placement, ?string $day = null): ?array
    {
        $base = AdRateCard::base($placement);
        if ($base === null) return null;
        $day ??= date('Y-m-d');
        $key = $day . '|' . $placement . '|' . $base . '|' . (self::dynamic() ? 'd' : 'f');
        if (isset(self::$memo[$key])) return self::$memo[$key];

        if (!self::dynamic()) {
            return self::$memo[$key] = ['cpm' => $base, 'base' => $base, 'quality' => 1.0, 'demand' => 1.0,
                'sell' => null, 'ctr' => null, 'viewability' => null, 'slots' => 0, 'measured' => false];
        }

        $stored = self::stored($day, $placement);
        if ($stored !== null && (int) $stored->base_naira === $base) {
            return self::$memo[$key] = self::fromRow($stored);
        }

        $q = self::compute($placement, $base);
        self::store($day, $placement, $q, $stored !== null);
        return self::$memo[$key] = $q;
    }

    /**
     * @return array{cpm:int, base:int, quality:float, demand:float, sell:?float, ctr:?float,
     *               viewability:?float, slots:int, measured:bool}
     */
    private static function compute(string $placement, int $base): array
    {
        $m = self::measure($placement);
        $quality = self::quality($placement, $m);
        $sell = self::sellThrough($placement, $m);
        $demand = $sell === null ? 1.0 : self::demandFactor($sell);
        $raw = $base * $quality * $demand;
        $raw = max($base * self::FLOOR, min($base * self::CEIL, $raw));
        return ['cpm' => self::round($raw, $base), 'base' => $base, 'quality' => round($quality, 3), 'demand' => round($demand, 3),
                'sell' => $sell, 'ctr' => $m['ctr'], 'viewability' => $m['viewability'], 'slots' => $m['slots'],
                'measured' => $m['views'] >= self::MIN_VIEWS];
    }

    /** Whole tens under ₦1,000, whole fifties above: a price somebody can say out loud. */
    private static function round(float $raw, int $base): int
    {
        $step = $base < 1000 ? 10 : 50;
        return max($step, (int) (round($raw / $step) * $step));
    }

    /**
     * What a placement drew over the window: slots drawn for readers, views seen, clicks.
     * Yesterday and before only — today is half a day and would read as a slump every morning.
     *
     * @return array{slots:int, views:int, clicks:int, ctr:?float, viewability:?float, days:int}
     */
    public static function measure(?string $placement = null): array
    {
        $mk = 'm|' . date('Y-m-d') . '|' . ($placement ?? '*');
        if (isset(self::$memo[$mk])) return self::$memo[$mk];
        return self::$memo[$mk] = self::measureNow($placement);
    }

    /** @return array{slots:int, views:int, clicks:int, ctr:?float, viewability:?float, days:int} */
    private static function measureNow(?string $placement): array
    {
        $from = date('Y-m-d', time() - self::WINDOW_DAYS * 86400);
        $to   = date('Y-m-d');
        $slots = $views = $clicks = $days = 0;
        try {
            if (SchemaHas::table('gates_ad_inventory')) {
                $q = DB::table('gates_ad_inventory')->where('day', '>=', $from)->where('day', '<', $to);
                if ($placement !== null) $q->where('placement', $placement);
                $r = $q->selectRaw('COALESCE(SUM(slots),0) s, COUNT(DISTINCT day) d')->first();
                $slots = (int) ($r->s ?? 0);
                $days = (int) ($r->d ?? 0);
            }
            if (SchemaHas::table('gates_ad_stats')) {
                $q = DB::table('gates_ad_stats')->where('day', '>=', $from)->where('day', '<', $to);
                if ($placement !== null) $q->where('placement', $placement);
                $r = $q->selectRaw('COALESCE(SUM(views),0) v, COALESCE(SUM(clicks),0) c')->first();
                $views = (int) ($r->v ?? 0);
                $clicks = (int) ($r->c ?? 0);
            }
        } catch (\Throwable) {
        }
        return ['slots' => $slots, 'views' => $views, 'clicks' => $clicks, 'days' => $days,
                'ctr' => $views > 0 ? $clicks / $views : null,
                'viewability' => $slots > 0 ? min(1.0, $views / $slots) : null];
    }

    /** The click rate a placement is credited with: its own, pulled toward the platform's until it has weight. */
    public static function smoothedCtr(string $placement, ?array $m = null): float
    {
        $m ??= self::measure($placement);
        $all = self::measure(null);
        $prior = $all['ctr'] ?? self::ASSUMED_CTR;
        if ($prior <= 0) $prior = self::ASSUMED_CTR;
        return ($m['clicks'] + self::PRIOR_VIEWS * $prior) / ($m['views'] + self::PRIOR_VIEWS);
    }

    /** 1.0 until measured; then the placement's smoothed click rate against the platform's. */
    public static function quality(string $placement, ?array $m = null): float
    {
        $m ??= self::measure($placement);
        if ($m['views'] < self::MIN_VIEWS) return 1.0;
        $all = self::measure(null);
        $ref = $all['ctr'] ?? 0.0;
        if ($ref <= 0) return 1.0;
        $q = (self::smoothedCtr($placement, $m) / $ref) ** self::Q_EXP;
        return max(self::Q_MIN, min(self::Q_MAX, $q));
    }

    /** The demand multiplier for a sell-through: discounted below 30%, rising past 70%. */
    public static function demandFactor(float $sell): float
    {
        if ($sell <= 0.3) return self::D_MIN;
        if ($sell <= 0.7) return self::D_MIN + (1.0 - self::D_MIN) * ($sell - 0.3) / 0.4;
        if ($sell <= 1.0) return 1.0 + 0.25 * ($sell - 0.7) / 0.3;
        return min(self::D_MAX, 1.25 + 0.25 * min(1.0, $sell - 1.0));
    }

    /** Seen views the placement can deliver per day, from its own history; null until it has one. */
    public static function supplyDaily(string $placement, ?array $m = null): ?int
    {
        $m ??= self::measure($placement);
        if ($m['days'] < self::INVENTORY_DAYS || $m['slots'] <= 0) return null;
        $view = $m['viewability'] ?? self::ASSUMED_VIEWABILITY;
        if ($m['views'] < self::MIN_VIEWS) $view = self::ASSUMED_VIEWABILITY;
        return (int) floor($m['slots'] / $m['days'] * $view);
    }

    /** Booked demand over the horizon, against supply over the horizon. Null until supply is known. */
    public static function sellThrough(string $placement, ?array $m = null): ?float
    {
        $supply = self::supplyDaily($placement, $m);
        if ($supply === null || $supply <= 0) return null;
        return round(self::bookedViews($placement, self::HORIZON) / ($supply * self::HORIZON), 3);
    }

    /**
     * Seen views PAID campaigns still have to deliver on this placement in the next N days.
     *
     * Trials are not demand: they are free, they are served last (AdDelivery), and counting
     * them would raise the price paying advertisers are quoted for space a free campaign is
     * only borrowing. A set on several placements is assumed to spread evenly over them.
     */
    public static function bookedViews(string $placement, int $days): int
    {
        if (!AdCampaigns::ready()) return 0;
        $now = date('Y-m-d H:i:s');
        try {
            $sets = DB::table('gates_ad_sets as s')->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('c.status', AdCampaigns::ST_SUBMITTED)->where('c.trial', 0)->whereNotNull('c.paid_at')
                ->where(static fn ($q) => $q->whereNull('s.ends_at')->orWhere('s.ends_at', '>=', $now))
                ->select('s.*')->limit(500)->get()->all();
        } catch (\Throwable) {
            return 0;
        }
        $total = 0.0;
        foreach ($sets as $s) {
            $places = array_values(array_filter(explode(',', (string) $s->placements)));
            if (!in_array($placement, $places, true)) continue;
            $t = AdCampaigns::totals((int) $s->id);
            $left = 0.0;
            if ($s->view_cap !== null) {
                $left = max(0, (int) $s->view_cap - (int) $t['views']);
            } elseif ((int) $s->budget_kobo > 0) {
                $rates = json_decode((string) $s->rates, true) ?: [];
                $rate = (int) ($rates[$placement] ?? 0);
                if ($rate <= 0) continue;
                $left = max(0, (int) $s->budget_kobo - (int) $t['spent_kobo']) / $rate * 1000;
            }
            $left /= max(1, count($places));
            // The part of what is left that falls inside the horizon: a month-long campaign
            // spreads its remainder over its remaining days, and only this week's share of it
            // competes for this week's space. One with no end is assumed to want it now.
            $from = max(time(), $s->starts_at !== null ? (int) strtotime((string) $s->starts_at) : time());
            $to   = $s->ends_at !== null ? (int) strtotime((string) $s->ends_at) : $from + $days * 86400;
            if ($to <= $from) continue;
            $overlap = max(0, min($to, time() + $days * 86400) - $from);
            $total += $left * min(1.0, $overlap / ($to - $from));
        }
        return (int) round($total);
    }

    /** Premium, standard or value, by what is measured; "new" while it is not. */
    public static function tier(float $quality, bool $measured): string
    {
        if (!$measured) return 'new';
        return $quality >= 1.2 ? 'premium' : ($quality < 0.9 ? 'value' : 'standard');
    }

    public const TIERS = [
        'premium'  => ['Premium', 'People act on ads here more than anywhere else on the site.'],
        'standard' => ['Standard', 'People act on ads here about as often as the site average.'],
        'value'    => ['Value', 'Fewer people act on ads here, so it costs less per view.'],
        'new'      => ['New', 'Not enough views yet to measure; priced at its starting rate.'],
    ];

    /**
     * Every placement as an advertiser or an operator reads it: where, who, today's price
     * and why, and what it delivers. One reader for /advertise, the builder and the console.
     *
     * @return list<array<string,mixed>>
     */
    public static function card(): array
    {
        $out = [];
        foreach (AdRules::PLACEMENTS as $p => $label) {
            $m = self::measure($p);
            $q = self::quote($p);
            $measured = $m['views'] >= self::MIN_VIEWS;
            $quality = $q['quality'] ?? self::quality($p, $m);
            $ctr = self::smoothedCtr($p, $m);
            $daily = self::supplyDaily($p, $m);
            $tier = self::tier((float) $quality, $measured);
            $out[] = [
                'key' => $p, 'label' => $label, 'where' => self::POSITIONS[$p]['where'] ?? '', 'who' => self::POSITIONS[$p]['who'] ?? '',
                'price' => $q['cpm'] ?? null, 'base' => AdRateCard::base($p),
                'quality' => (float) $quality, 'demand' => (float) ($q['demand'] ?? 1.0), 'sell' => $q['sell'] ?? self::sellThrough($p, $m),
                'measured' => $measured, 'ctr_pct' => $measured ? round(100 * $ctr, 2) : null,
                'seen_pct' => $m['slots'] > 0 && $m['views'] > 0 ? (int) round(100 * min(1, $m['views'] / $m['slots'])) : null,
                'daily' => $daily, 'tier' => $tier, 'tier_label' => self::TIERS[$tier][0], 'tier_note' => self::TIERS[$tier][1],
                // What one visit costs at today's price and the click rate credited: the
                // number an advertiser who wants visits actually compares.
                'per_visit_kobo' => isset($q['cpm']) && $ctr > 0 ? (int) ceil($q['cpm'] * 100 / 1000 / $ctr) : null,
            ];
        }
        usort($out, static fn ($a, $b) => [$b['price'] !== null, $b['quality']] <=> [$a['price'] !== null, $a['quality']]);
        return $out;
    }

    // ── the business side ───────────────────────────────────────────────────

    /**
     * Advertising income: earned this month (seen views × the rate each set was booked at,
     * capped at its budget), the month's projection at this pace, what paid campaigns still
     * have booked, and the operator's target with the gap to it.
     *
     * @return array{earned_kobo:int, projected_kobo:int, booked_kobo:int, target_kobo:?int, gap_kobo:?int, on_track:?bool}
     */
    public static function revenue(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $first = substr($today, 0, 8) . '01';
        $earned = $booked = 0;
        if (AdCampaigns::ready() && SchemaHas::table('gates_ad_stats')) {
            try {
                $sets = DB::table('gates_ad_sets as s')->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                    ->where('c.trial', 0)->whereNotNull('c.paid_at')->select('s.*', 'c.status')->limit(2000)->get()->all();
            } catch (\Throwable) {
                $sets = [];
            }
            foreach ($sets as $s) {
                $rates = json_decode((string) $s->rates, true) ?: [];
                $t = AdCampaigns::totals((int) $s->id);
                $before = 0;
                foreach (DB::table('gates_ad_stats as st')->join('gates_ads as a', 'a.id', '=', 'st.ad_id')
                             ->where('a.ad_set_id', $s->id)->where('st.day', '<', $first)->groupBy('st.placement')
                             ->selectRaw('st.placement p, SUM(st.views) v')->get() as $r) {
                    $before += AdRateCard::cost((int) $r->v, (int) ($rates[(string) $r->p] ?? 0));
                }
                $cap = (int) $s->budget_kobo > 0 ? (int) $s->budget_kobo : PHP_INT_MAX;
                $earned += max(0, (int) $t['spent_kobo'] - min($before, $cap));
                if ((string) $s->status === AdCampaigns::ST_SUBMITTED && (int) $s->budget_kobo > 0
                    && ($s->ends_at === null || (string) $s->ends_at >= date('Y-m-d H:i:s'))) {
                    $booked += max(0, (int) $s->budget_kobo - (int) $t['spent_kobo']);
                }
            }
        }
        $dayN = (int) substr($today, 8, 2);
        $len = (int) date('t', (int) strtotime($today));
        $projected = $dayN > 0 ? (int) round($earned / $dayN * $len) : $earned;
        $target = null;
        try {
            $n = (int) (DB::table('gates_settings')->where('key_name', self::TARGET_KEY)->value('value') ?? 0);
            $target = $n > 0 ? $n * 100 : null;
        } catch (\Throwable) {
        }
        return ['earned_kobo' => $earned, 'projected_kobo' => $projected, 'booked_kobo' => $booked, 'target_kobo' => $target,
                'gap_kobo' => $target !== null ? max(0, $target - $projected) : null,
                'on_track' => $target !== null ? $projected >= $target : null];
    }

    /**
     * What an operator should consider, worked out from the figures above. Each line names
     * the number it rests on, so it can be checked rather than trusted.
     *
     * @param list<array<string,mixed>>|null $card
     * @return list<string>
     */
    public static function advice(?array $card = null, ?array $rev = null): array
    {
        $card ??= self::card();
        $rev ??= self::revenue();
        $out = [];
        $bases = array_values(array_filter(array_map(static fn ($c) => $c['base'], $card)));
        sort($bases);
        $median = $bases !== [] ? $bases[intdiv(count($bases), 2)] : null;
        foreach ($card as $c) {
            if ($c['base'] === null) {
                if ($c['measured']) $out[] = sprintf('%s is not on sale, and people act on ads there %s× the site average. Give it a base price.', $c['label'], number_format($c['quality'], 2));
                continue;
            }
            if ($c['sell'] !== null && $c['sell'] >= 0.9) {
                $out[] = sprintf('%s is %d%% booked for the next %d days, so its price is rising. If that lasts, raise its base.', $c['label'], (int) round(100 * $c['sell']), self::HORIZON);
            } elseif ($c['sell'] !== null && $c['sell'] <= 0.2) {
                $out[] = sprintf('%s is %d%% booked for the next %d days and is selling at a discount. It has room for more campaigns.', $c['label'], (int) round(100 * $c['sell']), self::HORIZON);
            }
            if ($c['measured'] && $c['quality'] >= 1.3 && $median !== null && $c['base'] <= $median) {
                $out[] = sprintf('%s performs %s× the site average but its base is at or below the middle of your prices. It is worth more.', $c['label'], number_format($c['quality'], 2));
            }
        }
        if ($rev['target_kobo'] !== null && $rev['on_track'] === false) {
            $out[] = sprintf('At this pace the month comes to %s against a target of %s: %s short.',
                AdRateCard::format($rev['projected_kobo']), AdRateCard::format($rev['target_kobo']), AdRateCard::format((int) $rev['gap_kobo']));
        }
        return $out;
    }

    // ── storage ─────────────────────────────────────────────────────────────

    private static function stored(string $day, string $placement): ?object
    {
        if (!SchemaHas::table('gates_ad_prices')) return null;
        try {
            return DB::table('gates_ad_prices')->where('day', $day)->where('placement', $placement)->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function store(string $day, string $placement, array $q, bool $exists): void
    {
        if (!SchemaHas::table('gates_ad_prices')) return;
        $row = ['cpm_naira' => $q['cpm'], 'base_naira' => $q['base'],
                'quality_x1000' => (int) round($q['quality'] * 1000), 'demand_x1000' => (int) round($q['demand'] * 1000),
                // Stored one above the value, so 0 can mean "not measured" and a measured
                // zero (a week nobody booked, a placement nobody tapped) survives the trip.
                'sell_bps' => $q['sell'] === null ? 0 : 1 + (int) round($q['sell'] * 10000),
                'ctr_bp' => $q['ctr'] === null ? 0 : 1 + (int) round($q['ctr'] * 10000),
                'view_bps' => $q['viewability'] === null ? 0 : 1 + (int) round($q['viewability'] * 10000),
                'slots' => $q['slots'], 'measured' => $q['measured'] ? 1 : 0];
        try {
            if ($exists) {
                DB::table('gates_ad_prices')->where('day', $day)->where('placement', $placement)->update($row);
            } else {
                DB::table('gates_ad_prices')->insertOrIgnore($row + ['day' => $day, 'placement' => $placement]);
            }
        } catch (\Throwable) {
        }
    }

    private static function fromRow(object $r): array
    {
        return ['cpm' => (int) $r->cpm_naira, 'base' => (int) $r->base_naira,
                'quality' => (int) $r->quality_x1000 / 1000, 'demand' => (int) $r->demand_x1000 / 1000,
                'sell' => (int) $r->sell_bps > 0 ? ((int) $r->sell_bps - 1) / 10000 : null,
                'ctr' => (int) $r->ctr_bp > 0 ? ((int) $r->ctr_bp - 1) / 10000 : null,
                'viewability' => (int) $r->view_bps > 0 ? ((int) $r->view_bps - 1) / 10000 : null,
                'slots' => (int) $r->slots, 'measured' => (int) $r->measured === 1];
    }
}
