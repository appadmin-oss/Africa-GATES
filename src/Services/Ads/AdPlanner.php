<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

/**
 * From a target to a plan: "I want 300 visits in two weeks" → where, how many views, what it
 * costs, and whether the site can deliver it.
 *
 * Owner, 3 Nov 2026: "make it achieve targets". An advertiser thinks in results, and a rate
 * card answers in views; the gap between the two is the question every first-time advertiser
 * asks and cannot work out ("how much do I need to spend to get people?"). So the planner
 * works backwards from the result, on the site's own measurements:
 *
 *   · the click rate each placement is credited with (AdMarket::smoothedCtr — its own,
 *     pulled toward the site's until it has weight; an assumed 0.8% before anything is
 *     measured, and the plan says so);
 *   · what each placement can still deliver over the days asked for (its daily supply,
 *     less what paid campaigns have already booked, and never more than SHARE_MAX of it:
 *     one advertiser does not get a whole page);
 *   · today's price.
 *
 * It fills the target from the cheapest placement PER RESULT first — cost per visit for a
 * traffic goal, cost per view for an awareness goal — and moves to the next when one runs
 * out of room. That is the order that reaches the goal for the least money; it is not the
 * order that spends the most, and that is deliberate: a plan an advertiser can check and that
 * delivers is what brings them back with a bigger one.
 */
final class AdPlanner
{
    public const GOALS = ['visits' => 'Visits to your page', 'views' => 'Views of your ad'];

    /**
     * @param list<string>|null $placements null = every placement on sale
     * @return array{goal:string, target:int, days:int, rows:list<array<string,mixed>>, views:int, results:int,
     *               cost_kobo:int, feasible:bool, short:int, days_needed:?int, assumed:bool, unknown_supply:bool}
     */
    public static function plan(string $goal, int $target, int $days, ?array $placements = null): array
    {
        $goal = isset(self::GOALS[$goal]) ? $goal : 'visits';
        $target = max(1, min(10_000_000, $target));
        $days = max(1, min(AdRules::MAX_DAYS, $days));
        $placements = $placements === null ? array_keys(AdRules::PLACEMENTS) : AdRules::cleanPlacements($placements);

        $opts = [];
        $assumed = false;
        $unknown = false;
        foreach ($placements as $p) {
            $price = AdMarket::price($p);
            if ($price === null) continue;
            $m = AdMarket::measure($p);
            if ($m['views'] < AdMarket::MIN_VIEWS) $assumed = true;
            $ctr = AdMarket::smoothedCtr($p, $m);
            $daily = AdMarket::supplyDaily($p, $m);
            if ($daily === null) {
                $unknown = true;
                $room = null;
            } else {
                $free = max(0, $daily * $days - AdMarket::bookedViews($p, $days));
                $room = (int) floor(min($free, $daily * $days * AdMarket::SHARE_MAX));
            }
            $perView = $price * 100 / 1000;                     // kobo per seen view
            $opts[] = ['key' => $p, 'label' => AdRules::PLACEMENTS[$p], 'cpm' => $price, 'ctr' => $ctr, 'room' => $room,
                       'per_result' => $goal === 'views' ? $perView : $perView / max(1e-6, $ctr)];
        }
        usort($opts, static fn ($a, $b) => $a['per_result'] <=> $b['per_result']);

        $need = $target;
        $rows = [];
        $views = $results = $cost = 0;
        foreach ($opts as $o) {
            if ($need <= 0) break;
            $resultsPerView = $goal === 'views' ? 1.0 : $o['ctr'];
            $wantViews = (int) ceil($need / $resultsPerView);
            $v = $o['room'] === null ? $wantViews : min($wantViews, $o['room']);
            if ($v <= 0) continue;
            $r = $goal === 'views' ? $v : (int) floor($v * $o['ctr'] + 1e-9);
            $k = AdRateCard::cost($v, $o['cpm'] * 100);
            $rows[] = ['key' => $o['key'], 'label' => $o['label'], 'views' => $v, 'results' => $r, 'cost_kobo' => $k,
                       'cpm' => $o['cpm'], 'ctr_pct' => round(100 * $o['ctr'], 2), 'per_result_kobo' => (int) ceil($o['per_result'])];
            $views += $v; $results += $r; $cost += $k;
            $need -= $r;
            // An unknown supply takes the whole remainder at the cheapest place, and says so;
            // splitting by a guess would read as a measurement.
            if ($o['room'] === null) $need = 0;
        }

        $short = max(0, $target - $results);
        $daysNeeded = null;
        if ($short > 0 && $results > 0) {
            $daysNeeded = min(AdRules::MAX_DAYS, (int) ceil($days * $target / $results));
        }
        return ['goal' => $goal, 'target' => $target, 'days' => $days, 'rows' => $rows, 'views' => $views,
                'results' => $goal === 'views' ? $views : $results, 'cost_kobo' => $cost,
                'feasible' => $short === 0 && $rows !== [], 'short' => $short, 'days_needed' => $daysNeeded,
                'assumed' => $assumed, 'unknown_supply' => $unknown];
    }
}
