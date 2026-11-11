<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

/**
 * What a campaign's own figures say to do next, and whether it is spending on pace.
 *
 * Owner, 11 Nov 2026: "improve the ads and ads management". Every large self-serve platform
 * puts the same two things on a running campaign, because an advertiser reading a table of
 * views and clicks cannot tell what it means for them:
 *
 *   · PACING (Meta, Google and LinkedIn all show it): is the budget being spent at the rate
 *     the dates imply? A campaign that will finish with most of its budget unspent has
 *     reached fewer people than its owner planned for, and they should hear it while there
 *     are days left, not from the refund.
 *   · RECOMMENDATIONS (Meta's, Google's): the one or two changes the figures point at —
 *     an ad people have stopped tapping, a second version to test, a place that is not
 *     working for this ad.
 *
 * Every piece of advice here is read off THIS campaign's measured rows, compared with what
 * the site measures for ads like it, and is withheld until there are enough views for the
 * comparison to mean something. Nothing is a guess presented as a finding, and nothing
 * recommends spending more: the honest advice for an underspending campaign is that the
 * rest comes back, not that it should buy a bigger package.
 *
 * Read-only. It changes nothing about delivery or price; it is words beside a table.
 */
final class AdAdvice
{
    /** Views an ad needs before its click rate is compared with anybody else's. */
    public const MIN_VIEWS = 1000;
    /** Below this share of the comparison click rate, an ad is flagged as not being tapped. */
    public const WEAK_SHARE = 0.5;
    /** Views a place needs, for this campaign, before it is compared with its others. */
    public const PLACE_MIN_VIEWS = 500;
    /** Behind pace means spending at less than this share of the rate the dates imply. */
    public const BEHIND = 0.6;
    /** Pacing is not judged until this share of the campaign's time has passed. */
    public const PACE_AFTER = 0.2;
    /** At most this many pieces of advice: a list of seven is a list nobody acts on. */
    public const MAX = 3;

    /**
     * Pacing for a paid campaign that is running, or null when it does not apply (a trial,
     * our own, not started, finished, or too early to tell).
     *
     * @param array<string,mixed> $c AdCampaigns::summary() or detail()
     * @return array{key:string, label:string, elapsed_pct:int, spent_pct:int, projected_kobo:int, returned_kobo:int}|null
     */
    public static function pace(array $c, ?int $now = null): ?array
    {
        $now ??= time();
        $budget = (int) ($c['budget_kobo'] ?? 0);
        if ($budget <= 0 || ($c['delivery'] ?? '') !== 'active' || empty($c['starts_at']) || empty($c['ends_at'])) return null;
        $from = strtotime((string) $c['starts_at']);
        $to = strtotime((string) $c['ends_at']);
        if ($from === false || $to === false || $to <= $from) return null;
        $elapsed = max(0.0, min(1.0, ($now - $from) / ($to - $from)));
        if ($elapsed < self::PACE_AFTER) return null;
        $spent = (int) ($c['spent_kobo'] ?? 0);
        $spentShare = min(1.0, $spent / $budget);
        $projected = (int) min($budget, round($spent / max(1e-6, $elapsed)));
        $behind = $spentShare < self::BEHIND * $elapsed;
        return ['key' => $behind ? 'behind' : 'on_track', 'label' => $behind ? 'Spending slower than planned' : 'On track',
                'elapsed_pct' => (int) round(100 * $elapsed), 'spent_pct' => (int) round(100 * $spentShare),
                'projected_kobo' => $projected, 'returned_kobo' => max(0, $budget - $projected)];
    }

    /**
     * The advice for one campaign, most important first.
     *
     * @param array<string,mixed> $c AdCampaigns::detail(), which carries `ads`, `places`, `benchmark`, `can_add`
     * @return list<array{key:string, text:string, action:?string}>
     */
    public static function for(array $c, ?int $now = null): array
    {
        if (in_array((string) ($c['delivery'] ?? ''), ['cancelled', 'completed', 'unpaid', 'not_approved'], true)) return [];
        $out = [];
        $running = in_array((string) ($c['delivery'] ?? ''), ['active', 'scheduled', 'paused'], true);
        $approved = array_values(array_filter((array) ($c['ads'] ?? []), static fn ($a) => ($a['review'] ?? '') === 'approved'));

        // 1. An ad people have stopped tapping. The comparison is ads like it: the platform's
        //    benchmark (other campaigns, last 30 days) when there is one, otherwise the site's
        //    own measured click rate. Withheld until the ad has MIN_VIEWS.
        $ref = isset($c['benchmark']) && $c['benchmark'] !== null ? (float) $c['benchmark'] / 100 : null;
        if ($ref === null) {
            $all = AdMarket::measure(null);
            $ref = $all['views'] >= AdMarket::MIN_VIEWS ? $all['ctr'] : null;
        }
        if ($ref !== null && $ref > 0) {
            foreach ($approved as $a) {
                $v = (int) ($a['views'] ?? 0);
                if ($v < self::MIN_VIEWS) continue;
                $ctr = (int) ($a['clicks'] ?? 0) / $v;
                if ($ctr < self::WEAK_SHARE * $ref) {
                    $out[] = ['key' => 'weak_ad', 'action' => !empty($c['can_add']) ? 'add' : null,
                              'text' => sprintf('“%s” is drawing fewer than half the taps of ads like it (%s%% against %s%%). A new image usually changes this most: add a version, and the one people tap more will be shown more.',
                                  (string) ($a['headline'] ?? ''), self::pct($ctr), self::pct($ref))];
                    break;
                }
            }
        }

        // 2. Pacing.
        $p = self::pace($c, $now);
        if ($p !== null && $p['key'] === 'behind') {
            $out[] = ['key' => 'pace', 'action' => null,
                      'text' => sprintf('It is spending slower than its dates allow: %d%% of the budget in %d%% of the time. At this rate about %s will come back to you when it ends. The places you chose have fewer readers than the budget can buy; next time, choose more places or fewer days.',
                          $p['spent_pct'], $p['elapsed_pct'], AdRateCard::format($p['returned_kobo']))];
        }

        // 3. The leading ad's own make-up (AdStrength), only when it is weak.
        $lead = null;
        foreach ($approved as $a) if ((int) ($a['id'] ?? 0) === (int) ($c['leader'] ?? 0)) $lead = $a;
        $lead ??= $approved[0] ?? null;
        $s = $lead['strength'] ?? null;
        if (is_array($s) && in_array($s['level'] ?? '', ['poor', 'average'], true) && ($s['tips'] ?? []) !== []) {
            $out[] = ['key' => 'strength', 'action' => !empty($c['can_add']) ? 'add' : null,
                      'text' => sprintf('Ad strength: %s. %s', (string) $s['label'], (string) $s['tips'][0])];
        }

        // 4. Only one version running: test a second (Meta's A/B test, Google's "add more
        //    assets"). The rotation favours the better one by itself (AdCampaigns::leader).
        //    Not when (1) has already said the same thing for a reason.
        $said = in_array('weak_ad', array_column($out, 'key'), true);
        if (!$said && $running && count($approved) === 1 && !empty($c['can_add'])) {
            $out[] = ['key' => 'test', 'action' => 'add',
                      'text' => sprintf('Try a second version, with a different image or headline. Once each has %s views, the one people tap more is shown %d%% of the time, so the better ad does more of the work.',
                          number_format(AdCampaigns::TEST_VIEWS), AdCampaigns::LEAD_SHARE)];
        }

        // 5. A place that is not working for this ad, against its best place.
        $places = array_values(array_filter((array) ($c['places'] ?? []), static fn ($x) => (int) $x['views'] >= self::PLACE_MIN_VIEWS));
        if (count($places) >= 2) {
            usort($places, static fn ($a, $b) => ($b['clicks'] / $b['views']) <=> ($a['clicks'] / $a['views']));
            $best = $places[0];
            $worst = $places[count($places) - 1];
            $bc = $best['clicks'] / $best['views'];
            $wc = $worst['clicks'] / $worst['views'];
            if ($bc > 0 && $wc < self::WEAK_SHARE * $bc) {
                $out[] = ['key' => 'place', 'action' => null,
                          'text' => sprintf('%s draws far fewer taps for this ad than %s (%s%% against %s%%). Next time, leave it out, or use an image made for the people who read it.',
                              AdRules::PLACEMENTS[$worst['key']] ?? $worst['key'], AdRules::PLACEMENTS[$best['key']] ?? $best['key'], self::pct($wc), self::pct($bc))];
            }
        }
        $out = array_slice($out, 0, self::MAX);
        // One way to act is offered once: three "Add a version" links read as three things to do.
        $offered = false;
        foreach ($out as &$a) {
            if ($a['action'] === 'add') {
                if ($offered) $a['action'] = null;
                $offered = true;
            }
        }
        unset($a);
        return $out;
    }

    private static function pct(float $r): string
    {
        return rtrim(rtrim(number_format(100 * $r, 2), '0'), '.');
    }
}
