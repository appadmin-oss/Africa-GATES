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
    /** Flag at/above this many standard deviations from the panel mean… */
    public const Z_THRESHOLD = 2.0;
    /** …but only if the gap is also at least this many points (0–10), so a tight panel isn't over-flagged… */
    public const MIN_DEVIATION = 1.5;
    /** …or flag regardless of spread when the raw gap is this large. */
    public const ABS_DEVIATION = 3.0;

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
                ->where('c.cycle_id', $cycleId)
                ->whereIn('n.status', ['approved', 'winner', 'runner_up']);
            MergeService::notMerged($q, 'n.merged_into');
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
            $cycleIds = DB::table('gates_award_cycles')->whereIn('status', ['judging', 'results'])->pluck('id');
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
     * (judgeId => 0–10), return the outlier flags. A judge is flagged for a
     * nominee when the panel has ≥ MIN_PANEL judges and the judge's score is
     * either ≥ ABS_DEVIATION points from the panel mean, or ≥ Z_THRESHOLD
     * standard deviations away AND at least MIN_DEVIATION points away (so a very
     * tight panel doesn't flag trivial gaps).
     *
     * @param array<int, array<int,float>> $panelsByNominee nomineeId => [judgeId => avg]
     * @return list<array{judge_id:int,nominee_id:int,score:float,panel_mean:float,deviation:float,z:float,direction:string,panel:int}>
     */
    public static function detect(array $panelsByNominee, ?float $z = null, ?float $absFloor = null): array
    {
        $zt  = $z ?? self::Z_THRESHOLD;
        $abs = $absFloor ?? self::ABS_DEVIATION;
        $flags = [];

        foreach ($panelsByNominee as $nomineeId => $panel) {
            $n = count($panel);
            if ($n < self::MIN_PANEL) continue;

            $mean = array_sum($panel) / $n;
            $var  = 0.0;
            foreach ($panel as $v) { $var += ($v - $mean) ** 2; }
            $sd = sqrt($var / $n);   // population stddev

            foreach ($panel as $judgeId => $score) {
                $dev = abs($score - $mean);
                $zs  = $sd > 0 ? $dev / $sd : 0.0;
                $isOutlier = ($dev >= $abs) || ($zs >= $zt && $dev >= self::MIN_DEVIATION);
                if (!$isOutlier) continue;
                $flags[] = [
                    'judge_id'   => (int) $judgeId,
                    'nominee_id' => (int) $nomineeId,
                    'score'      => round((float) $score, 2),
                    'panel_mean' => round($mean, 2),
                    'deviation'  => round($score - $mean, 2),
                    'z'          => round($zs, 2),
                    'direction'  => $score < $mean ? 'harsh' : 'generous',
                    'panel'      => $n,
                ];
            }
        }
        return $flags;
    }
}
