<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\AfricaRegion;
use AfricaGates\Support\Maintenance;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * `/leaderboard` — THE CULTURAL POWER INDEX, AS A BOARD (Phase 6 · Leaderboard.dc.html · §8.19).
 *
 * Everything on the page comes from here, and every figure is one the platform holds:
 *
 *  · WHO RANKS: approved registry profiles, not merged away, with a computed score above zero —
 *    "only verified profiles rank" is the DC's own sentence, and `status = approved` is the
 *    human review it describes.
 *  · THE NUMBER: `gates_profiles.cpi_score`, 0–1000, exactly as the recompute wrote it and as
 *    every other screen prints it. The DC draws 86.4; dividing by ten would print a second
 *    scale for one index (docs/handoff/PHASE-6.md, D-L1).
 *  · THE TIER: `cpi_tier`, under the ladder's own names and thresholds, read from
 *    {@see CpiService::TIERS} — never typed into the legend.
 *  · MOVEMENT: rank now against rank at the previous recompute, from `gates_cpi_history`
 *    (a row is written only when a score moves, so "the score in force before the last run"
 *    is the latest row older than that run). No earlier standing is said as such, never as
 *    "no change".
 *  · COMMUNITY · JUDGES: the two halves of the number, only where they ARE the number — a
 *    judged profile whose latest captured nominee score equals its CPI. A profile scored on
 *    profile strength, or still waiting on a panel, says that instead: a split bar beside a
 *    baseline score is the fault `2026_12_04_profile_cpi_basis.php` exists to stop.
 *  · CADENCE: {@see Maintenance::CPI_EVERY_HOURS}, the schedule's own constant.
 *
 * Ties share a place (1, 2, 2, 4). The DC's "ties are decided by organic votes" describes a
 * nominee standing; a registry profile has no tally of its own to break one with.
 */
final class Leaderboard
{
    /** Rows drawn below the podium before "Show 20 more" (§8.19). */
    public const FIRST = 40;
    public const MORE  = 20;

    /**
     * `gates_profiles.region` (an ENUM) → the words. The SAME five keys and words as every
     * derived region ({@see AfricaRegion}), so `?region=east` means one thing site-wide.
     */
    public const REGIONS = AfricaRegion::LABELS;

    /** Tier → swatch token. The tier's NAME is always beside it (colour is never alone). */
    public const TIER_SWATCH = [
        'diamond' => 'gold', 'platinum' => 'green', 'gold' => 'green-light',
        'silver' => 'line-2', 'bronze' => 'line', 'unranked' => 'line-3',
    ];

    /**
     * @param array{q?:string,cat?:string,region?:string,n?:int} $view
     * @return array<string,mixed>
     */
    public static function build(array $view = []): array
    {
        $q      = mb_substr(trim((string) ($view['q'] ?? '')), 0, 80);
        $cat    = mb_substr(trim((string) ($view['cat'] ?? '')), 0, 100);
        $region = strtolower(trim((string) ($view['region'] ?? '')));
        if (!isset(self::REGIONS[$region])) $region = '';
        $n      = max(self::FIRST, min(2000, (int) ($view['n'] ?? self::FIRST)));

        $all = self::ranked();
        $filtered = $q !== '' || $cat !== '' || $region !== '';

        $match = static function (array $r) use ($q, $cat, $region): bool {
            if ($cat !== '' && mb_strtolower($r['cat']) !== mb_strtolower($cat)) return false;
            if ($region !== '' && $r['region'] !== $region) return false;
            if ($q !== '' && !str_contains(mb_strtolower($r['name']), mb_strtolower($q))) return false;
            return true;
        };

        $list   = $filtered ? array_values(array_filter($all, $match)) : array_slice($all, 3);
        $rows   = array_slice($list, 0, $n);
        $podium = array_slice($all, 0, 3);

        $cats = [];
        foreach ($all as $r) if ($r['cat'] !== '') $cats[$r['cat']] = ($cats[$r['cat']] ?? 0) + 1;
        arsort($cats);

        $weights = (new RuleEngine())->weights();
        $last    = self::lastComputed();

        return [
            'total'    => count($all),
            'empty'    => $all === [],
            'podium'   => $podium,
            'rows'     => $rows,
            'shown'    => $filtered ? count($rows) : min(count($all), count($rows) + count($podium)),
            'matched'  => count($list),
            'more'     => count($list) > count($rows) ? min(self::MORE, count($list) - count($rows)) : 0,
            'next_n'   => $n + self::MORE,
            'filtered' => $filtered,
            'view'     => ['q' => $q, 'cat' => $cat, 'region' => $region, 'n' => $n],
            'cats'     => array_slice(array_keys($cats), 0, 12),
            'regions'  => self::REGIONS,
            'tiers'    => self::tiers(),
            'weights'  => ['community' => (int) round($weights['community'] * 100),
                           'judge'     => (int) round($weights['judge'] * 100)],
            'every'    => Maintenance::CPI_EVERY_HOURS,
            'updated'  => $last,
            'updated_ago' => $last !== null ? self::ago($last) : null,
            'next_in'  => self::nextIn($last),
            'live'     => self::liveYear(),
            'opens'    => $all === [] ? self::votingOpens() : null,
        ];
    }

    /**
     * Every ranked profile, best first, with rank, tier, movement and split — the whole board,
     * cached briefly because it is read whole on every filter.
     *
     * @return list<array<string,mixed>>
     */
    public static function ranked(): array
    {
        try {
            $q = DB::table('gates_profiles')->where('status', 'approved')->where('cpi_score', '>', 0);
            ProfileMergeService::notMerged($q);
            $cols = ['id', 'slug', 'display_name', 'category', 'country_code', 'region', 'avatar_path',
                     'cpi_score', 'cpi_tier'];
            if (SchemaHas::column('gates_profiles', 'cpi_basis')) $cols[] = 'cpi_basis';
            $rows = $q->orderByDesc('cpi_score')->orderBy('display_name')->get($cols)->all();
        } catch (\Throwable) {
            return [];
        }
        if ($rows === []) return [];

        $prev  = self::previousScores(array_map(static fn ($r) => (int) $r->id, $rows));
        $split = self::splits($rows);

        // Ranks now, competition style.
        $out = [];
        $rank = 0; $last = null;
        foreach ($rows as $i => $r) {
            $s = (int) $r->cpi_score;
            if ($s !== $last) { $rank = $i + 1; $last = $s; }
            $out[] = ['r' => $r, 'rank' => $rank];
        }
        // Ranks then, over the profiles that had a score then.
        $was = array_filter($prev, static fn ($v) => $v > 0);
        arsort($was);
        $prevRank = []; $k = 0; $lastV = null; $pos = 0;
        foreach ($was as $pid => $v) { $k++; if ($v !== $lastV) { $pos = $k; $lastV = $v; } $prevRank[$pid] = $pos; }

        $tierNames = self::tierNames();
        $list = [];
        foreach ($out as $o) {
            $r = $o['r']; $id = (int) $r->id; $rank = $o['rank'];
            $tier = (string) ($r->cpi_tier ?? 'unranked');
            $move = isset($prevRank[$id]) ? $prevRank[$id] - $rank : null;
            $sp   = $split[$id] ?? null;
            $basis = (string) ($r->cpi_basis ?? 'judged');
            $list[] = [
                'id'      => $id,
                'rank'    => $rank,
                'slug'    => (string) $r->slug,
                'url'     => '/registry/' . rawurlencode((string) $r->slug),
                'name'    => (string) $r->display_name,
                'cat'     => (string) ($r->category ?? ''),
                'cc'      => strtoupper((string) ($r->country_code ?? '')),
                'region'  => (string) ($r->region ?? ''),
                'photo'   => (string) ($r->avatar_path ?? ''),
                'cpi'     => (int) $r->cpi_score,
                'tier'    => $tier,
                'tier_name' => $tierNames[$tier] ?? ucfirst($tier),
                'move'    => $move,
                'basis'   => $basis,
                'split'   => $sp,
                'aria'    => self::aria($rank, (string) $r->display_name, (string) ($r->category ?? ''),
                                        (int) $r->cpi_score, $tierNames[$tier] ?? $tier, $move),
            ];
        }
        return $list;
    }

    /** The ladder for the legend, from CpiService::TIERS — names, swatches and the band each covers. */
    public static function tiers(): array
    {
        $out = []; $ceil = null; $names = self::tierNames();
        foreach (CpiService::TIERS as [$name, $min]) {
            if ($name === 'unranked') break;
            $out[] = [
                'key'    => $name,
                'name'   => $names[$name],
                'swatch' => self::TIER_SWATCH[$name] ?? 'line',
                'range'  => $ceil === null
                    ? Translator::t('%n% and above', ['%n%' => (string) $min])
                    : $min . '–' . ($ceil - 1),
            ];
            $ceil = $min;
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function tierNames(): array
    {
        $n = [];
        foreach (CpiService::TIERS as [$name]) $n[$name] = ucfirst($name);
        return $n;
    }

    /** The words a screen reader hears for a row, movement included (§8.19). */
    private static function aria(int $rank, string $name, string $cat, int $cpi, string $tier, ?int $move): string
    {
        $m = $move === null ? Translator::t('no earlier standing')
            : ($move > 0 ? Translator::t('up %n% since the last update', ['%n%' => (string) $move])
            : ($move < 0 ? Translator::t('down %n% since the last update', ['%n%' => (string) -$move])
            : Translator::t('no change since the last update')));
        return Translator::t('Rank %rank%: %name%', ['%rank%' => (string) $rank, '%name%' => $name])
            . ($cat !== '' ? ', ' . $cat : '')
            . ', ' . Translator::t('CPI %n%', ['%n%' => (string) $cpi])
            . ', ' . Translator::t('%tier% tier', ['%tier%' => $tier])
            . ', ' . $m;
    }

    /**
     * Each profile's score in force BEFORE the latest recompute. History is written only on a
     * move, so that is the newest row older than the run — the run being the newest row, less
     * a margin for one run's own duration.
     *
     * @param list<int> $ids
     * @return array<int,int>
     */
    private static function previousScores(array $ids): array
    {
        if ($ids === [] || !SchemaHas::table('gates_cpi_history')) return [];
        try {
            $latest = DB::table('gates_cpi_history')->max('computed_at');
            if (!$latest) return [];
            $cut = date('Y-m-d H:i:s', strtotime((string) $latest) - 1800);
            $rows = DB::table('gates_cpi_history')
                ->whereIn('profile_id', $ids)->where('computed_at', '<', $cut)
                ->orderBy('computed_at')->orderBy('id')
                ->get(['profile_id', 'cpi_score']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) $out[(int) $r->profile_id] = (int) $r->cpi_score; // last wins
        return $out;
    }

    /**
     * The community and judge points behind a JUDGED profile's number — only when the latest
     * captured score of one of its nominees IS that number.
     *
     * @param list<object> $rows
     * @return array<int,array{community:int,judge:int,cw:int,jw:int}>
     */
    private static function splits(array $rows): array
    {
        $judged = [];
        foreach ($rows as $r) if ((string) ($r->cpi_basis ?? 'judged') === 'judged') $judged[(int) $r->id] = (int) $r->cpi_score;
        if ($judged === [] || !SchemaHas::column('gates_vote_snapshots', 'community_points')) return [];
        try {
            $latest = DB::table('gates_vote_snapshots as s')
                ->join('gates_nominees as n', 'n.id', '=', 's.nominee_id')
                ->whereIn('n.profile_id', array_keys($judged))
                ->whereNotNull('s.community_points')
                ->groupBy('s.nominee_id')
                ->selectRaw('MAX(s.id) as id')->pluck('id')->all();
            if ($latest === []) return [];
            $snaps = DB::table('gates_vote_snapshots as s')
                ->join('gates_nominees as n', 'n.id', '=', 's.nominee_id')
                ->whereIn('s.id', array_map('intval', $latest))
                ->get(['n.profile_id', 's.cpi_score', 's.community_points', 's.judge_points']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($snaps as $s) {
            $pid = (int) $s->profile_id;
            if (isset($out[$pid]) || ($judged[$pid] ?? null) !== (int) $s->cpi_score) continue;
            $c = (int) $s->community_points; $j = (int) $s->judge_points; $t = max(1, $c + $j);
            $out[$pid] = ['community' => $c, 'judge' => $j,
                          'cw' => (int) round($c / $t * 100), 'jw' => 100 - (int) round($c / $t * 100)];
        }
        return $out;
    }

    private static function lastComputed(): ?string
    {
        try {
            $v = DB::table('gates_profiles')->where('status', 'approved')->max('cpi_last_computed');
            return $v ? (string) $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** "2 hours ago", in words a person reads. */
    private static function ago(string $at): string
    {
        $s = max(0, time() - (int) strtotime($at));
        if ($s < 3600) {
            $m = max(1, intdiv($s, 60));
            return $m === 1 ? Translator::t('a minute ago') : Translator::t('%n% minutes ago', ['%n%' => (string) $m]);
        }
        if ($s < 86400) {
            $h = intdiv($s, 3600);
            return $h === 1 ? Translator::t('an hour ago') : Translator::t('%n% hours ago', ['%n%' => (string) $h]);
        }
        $d = intdiv($s, 86400);
        return $d === 1 ? Translator::t('yesterday') : Translator::t('%n% days ago', ['%n%' => (string) $d]);
    }

    /**
     * Hours until the next scheduled recompute — or null when the last one is more than two
     * cycles old, because then the schedule has visibly not been keeping its promise and the
     * page must not make the promise again.
     */
    private static function nextIn(?string $last): ?int
    {
        if ($last === null) return null;
        $every = Maintenance::CPI_EVERY_HOURS;
        if (time() - (int) strtotime($last) > 2 * $every * 3600) return null;
        $h = (int) date('G');
        $next = ($every - ($h % $every)) % $every;
        return $next === 0 ? $every : $next;
    }

    /** The year of a live award (voting now), or null. Sandbox excluded through the programme. */
    private static function liveYear(): ?int
    {
        try {
            $y = DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)->where('c.status', 'voting')->max('c.year');
            return $y ? (int) $y : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** When voting next opens on a live award — the empty state's date. Null when nobody has set one. */
    private static function votingOpens(): ?string
    {
        try {
            $d = DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)->whereNotNull('c.voting_open')
                ->where('c.voting_open', '>', date('Y-m-d H:i:s'))
                ->min('c.voting_open');
            return $d ? (string) $d : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
