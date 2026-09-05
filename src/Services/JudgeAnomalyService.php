<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Advisory judge-score anomaly detection — the expert-panel counterpart of
 * {@see CollusionService} (which watches community votes).
 *
 * Community votes have fraud + collusion detection; a lone judge's scorecard had
 * none, yet a single biased or lobbied judge can swing a category (the judge
 * component is 55% of CPI). This flags, per nominee, any judge whose complete
 * scorecard is a statistical OUTLIER versus the rest of the panel — far harsher
 * or far more generous than consensus — and rolls the flags up per judge so a
 * pattern (one judge consistently out of step across many nominees) is visible.
 *
 * ADVISORY ONLY: it never changes a score, a rank, or a result — it surfaces
 * flags for a human to review, exactly like nominee triage and collusion. Pure
 * statistics ({@see detect()}) so the maths is unit-tested independently of the
 * DB. Uses the SAME "complete scorecard" definition as scoring/quorum via
 * {@see NomineeScoringService::judgeAveragesFor()}.
 */
final class JudgeAnomalyService
{
    /** A panel needs at least this many complete scorecards before an outlier is meaningful. */
    public const MIN_PANEL = 3;
    /** Flag at/above this many standard deviations from the REST of the panel… */
    public const Z_THRESHOLD = 2.0;
    /** …but only if the gap is also at least this many points (0–10), so a tight panel isn't over-flagged… */
    public const MIN_DEVIATION = 1.5;
    /** …or flag regardless of spread when the raw gap is this large. */
    public const ABS_DEVIATION = 3.0;

    /**
     * The smallest spread a panel is credited with.
     *
     * Three judges whose weighted averages land on exactly the same quarter-point —
     * ordinary with four criteria and integer marks — have a spread of zero, and every
     * gap from them would then be infinitely many sigmas. The floor is DERIVED, not
     * picked: at MIN_DEVIATION / Z_THRESHOLD, a panel in perfect agreement flags at
     * exactly MIN_DEVIATION and never below it, so the sigma test can never be more
     * eager than the points test it exists to qualify.
     */
    public const MIN_PANEL_SPREAD = self::MIN_DEVIATION / self::Z_THRESHOLD;

    public function __construct(private readonly NomineeScoringService $scoring = new NomineeScoringService()) {}

    /**
     * Scan one cycle. Returns per-(judge,nominee) outlier flags plus a per-judge
     * rollup, with display names attached.
     *
     * @return array{flags: list<array{judge_id:int,judge:string,nominee_id:int,nominee:string,score:float,panel_mean:float,deviation:float,z:float,direction:string,panel:int}>, judges: list<array{judge_id:int,judge:string,flags:int,harsh:int,generous:int}>, nominees_scanned:int}
     */
    public function forCycle(int $cycleId): array
    {
        $ids = [];
        try {
            $q = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->where('c.cycle_id', $cycleId)
                ->whereIn('n.status', ['approved', 'winner', 'runner_up']);
            MergeService::notMerged($q, 'n.merged_into');
            // A rehearsal is not evidence about anybody. The practice cycle carries
            // `status = 'judging'` so a real judge can sit a practice ballot, and the marks
            // they leave there are real rows against demo nominees — which would otherwise
            // name a real person as an outlier on a panel that was explicitly told nothing
            // it did would count.
            DemoSeeder::notSandbox($q, 'cy.programme_id');
            $ids = $q->pluck('n.id')->map(fn($i) => (int) $i)->all();
        } catch (\Throwable) {}
        if (!$ids) return ['flags' => [], 'judges' => [], 'nominees_scanned' => 0];

        $panels = $this->scoring->judgePanelsFor($ids);
        $flags  = self::detect($panels);

        // Attach names for display.
        $judgeIds   = array_values(array_unique(array_column($flags, 'judge_id')));
        $nomineeIds = array_values(array_unique(array_column($flags, 'nominee_id')));
        $judgeNames   = $judgeIds   ? DB::table('gates_judges')->whereIn('id', $judgeIds)->pluck('name', 'id')->all() : [];
        $nomineeNames = $nomineeIds ? DB::table('gates_nominees')->whereIn('id', $nomineeIds)->pluck('name', 'id')->all() : [];

        foreach ($flags as &$f) {
            $f['judge']   = (string) ($judgeNames[$f['judge_id']] ?? ('Judge #' . $f['judge_id']));
            $f['nominee'] = (string) ($nomineeNames[$f['nominee_id']] ?? ('Nominee #' . $f['nominee_id']));
        }
        unset($f);

        // Per-judge rollup — a judge flagged on many nominees is the real signal.
        $roll = [];
        foreach ($flags as $f) {
            $jid = $f['judge_id'];
            $roll[$jid] ??= ['judge_id' => $jid, 'judge' => $f['judge'], 'flags' => 0, 'harsh' => 0, 'generous' => 0];
            $roll[$jid]['flags']++;
            $roll[$jid][$f['direction'] === 'harsh' ? 'harsh' : 'generous']++;
        }
        usort($roll, fn($a, $b) => $b['flags'] <=> $a['flags']);

        return ['flags' => $flags, 'judges' => array_values($roll), 'nominees_scanned' => count($ids)];
    }

    /**
     * Scan every cycle currently being judged or just decided (where scorecards
     * exist), combining flags and re-rolling the per-judge tally across them.
     * Returns the same shape as {@see forCycle()}.
     */
    public function scanActive(): array
    {
        $flags = []; $scanned = 0;
        try {
            $q = DB::table('gates_award_cycles')->whereIn('status', ['judging', 'results']);
            DemoSeeder::notSandbox($q, 'programme_id');
            $cycleIds = $q->pluck('id');
            foreach ($cycleIds as $cid) {
                $r = $this->forCycle((int) $cid);
                $flags = array_merge($flags, $r['flags']);
                $scanned += $r['nominees_scanned'];
            }
        } catch (\Throwable) {}

        $roll = [];
        foreach ($flags as $f) {
            $jid = $f['judge_id'];
            $roll[$jid] ??= ['judge_id' => $jid, 'judge' => $f['judge'], 'flags' => 0, 'harsh' => 0, 'generous' => 0];
            $roll[$jid]['flags']++;
            $roll[$jid][$f['direction'] === 'harsh' ? 'harsh' : 'generous']++;
        }
        usort($roll, fn($a, $b) => $b['flags'] <=> $a['flags']);

        return ['flags' => $flags, 'judges' => array_values($roll), 'nominees_scanned' => $scanned];
    }

    /**
     * Pure statistics: given each nominee's panel of complete-scorecard averages
     * (judgeId => 0–10), return the outlier flags. A judge is flagged for a nominee
     * when the panel has ≥ MIN_PANEL judges and the judge's score is either
     * ≥ ABS_DEVIATION points from the panel's centre, or ≥ Z_THRESHOLD spreads away
     * AND at least MIN_DEVIATION points away (so a very tight panel doesn't flag
     * trivial gaps).
     *
     * ── WHY MEDIAN AND MAD AND NOT MEAN AND STANDARD DEVIATION ───────────────
     *
     * This measured each judge against the mean and population standard deviation of a
     * panel that INCLUDED them, and the sigma test was mathematically dead as a result.
     * The largest z any one of n values can reach that way is √(n−1): 1.41 on a panel of
     * three, 1.73 on four, and exactly 2.0 on five only in the degenerate case where the
     * other four agree to the decimal. Z_THRESHOLD is 2.0 and `min_judges_per_nominee`
     * defaults to 2, so on every panel this platform actually convenes the branch could
     * not fire. Only the blunt ABS_DEVIATION ≥ 3.0 points test was ever running, and a
     * judge 2.5 points out of step with a panel otherwise agreeing to a tenth went unseen.
     *
     * The cause is that a lone dissenter drags the mean toward themselves — by a third on
     * a panel of three — while simultaneously inflating the spread they are measured
     * against. Both effects shrink z. Comparing against the OTHER judges fixes that and
     * breaks something worse: on [9, 9, 3], each 9 is measured against a mean of 6 and
     * gets flagged too, so one dissenting judge makes the whole panel look anomalous.
     *
     * The median and the median absolute deviation have neither problem, which is what
     * robust statistics are for: a single extreme value cannot move the centre a panel is
     * judged against, and it cannot inflate the scale either. On [9, 9, 3] the centre is
     * 9, the two agreeing judges sit exactly on it, and only the 3 is flagged.
     *
     * `panel_centre` and `deviation` are the median and the gap from it. `panel` is the
     * full count, which is what a reader needs to know how much weight the flag deserves.
     *
     * @param array<int, array<int,float>> $panelsByNominee nomineeId => [judgeId => avg]
     * @return list<array{judge_id:int,nominee_id:int,score:float,panel_centre:float,deviation:float,z:float,direction:string,panel:int}>
     */
    public static function detect(array $panelsByNominee, ?float $z = null, ?float $absFloor = null): array
    {
        $zt  = $z ?? self::Z_THRESHOLD;
        $abs = $absFloor ?? self::ABS_DEVIATION;
        $flags = [];

        foreach ($panelsByNominee as $nomineeId => $panel) {
            $n = count($panel);
            if ($n < self::MIN_PANEL) continue;

            $centre = self::median(array_values($panel));

            // Median absolute deviation — the robust counterpart of the standard
            // deviation, and floored for the same reason a spread of zero cannot be
            // divided by.
            $spread = max(
                self::median(array_map(static fn(float $v): float => abs($v - $centre), array_values($panel))),
                self::MIN_PANEL_SPREAD
            );

            foreach ($panel as $judgeId => $score) {
                $dev = abs($score - $centre);
                $zs  = $dev / $spread;

                $isOutlier = ($dev >= $abs) || ($zs >= $zt && $dev >= self::MIN_DEVIATION);
                if (!$isOutlier) continue;
                $flags[] = [
                    'judge_id'     => (int) $judgeId,
                    'nominee_id'   => (int) $nomineeId,
                    'score'        => round((float) $score, 2),
                    'panel_centre' => round($centre, 2),
                    'deviation'    => round($score - $centre, 2),
                    'z'            => round($zs, 2),
                    'direction'    => $score < $centre ? 'harsh' : 'generous',
                    'panel'        => $n,
                ];
            }
        }
        return $flags;
    }

    /** @param list<float> $v */
    private static function median(array $v): float
    {
        sort($v);
        $n = count($v);
        if ($n === 0) return 0.0;
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? (float) $v[$mid] : ((float) $v[$mid - 1] + (float) $v[$mid]) / 2;
    }
}
