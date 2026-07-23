<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Shared nominee scoring — the single place that turns raw votes + judge scores
 * into a CPI per nominee. Used by the snapshot service (and available to the CPI
 * recompute / winner selection) so the math agrees everywhere.
 */
class NomineeScoringService
{
    public function __construct(
        private readonly CpiService $cpi = new CpiService(),
        private readonly RuleEngine $rules = new RuleEngine(),
    ) {}

    /** Per-call cache of resolved criteria weights: [programmeId => [criterionId => weight]]. */
    private array $criteriaByProgramme = [];

    /**
     * Per-nominee scores for one category (cohort-normalised community + judges,
     * with the effective per-cycle CPI weights from the rule engine).
     * @return array<int, array{vote_count:int, judge_score:float|null, cpi_score:int}>
     */
    public function scoreCategory(int $categoryId): array
    {
        $nq = DB::table('gates_nominees')->where('category_id', $categoryId)
            ->whereIn('status', ['approved', 'winner', 'runner_up']);
        \AfricaGates\Services\MergeService::notMerged($nq);          // merge tombstones never score
        $nominees = $nq->get();
        if ($nominees->isEmpty()) return [];

        // Effective CPI weights for this category's cycle (config over defaults).
        $ctx = DB::table('gates_award_categories as c')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->where('c.id', $categoryId)
            ->select('cy.id as cycle_id', 'cy.programme_id')->first();
        $w = $this->rules->weights($ctx->programme_id ?? null, $ctx->cycle_id ?? null);

        // Community CPI is normalised over ORGANIC votes only — purchased "bonus"
        // votes (folded into vote_count for display) must never move the cohort
        // max or any nominee's community share, or money could buy rank.
        $cohortMax = max(1, (int) $nominees->max('organic_vote_count'));
        $quorum = (int) ($this->rules->effective($ctx->programme_id ?? null, $ctx->cycle_id ?? null)['min_judges_per_nominee']
            ?? RuleEngine::DEFAULTS['min_judges_per_nominee']);
        $stats = $this->judgeStatsFor($nominees->pluck('id')->all());

        $out = [];
        foreach ($nominees as $n) {
            $st       = $stats[(int) $n->id] ?? null;
            $ja       = $st['avg'] ?? null;
            $judges   = $st['judges'] ?? 0;
            $eligible = $judges >= $quorum;                        // winner-eligible only at quorum
            $out[(int) $n->id] = [
                'vote_count'  => (int) $n->vote_count,            // total display support
                'judge_score' => $ja,
                'judges'      => $judges,                          // COMPLETE scorecards only
                'eligible'    => $eligible,
                // Judges only move the CPI once the per-cycle quorum of COMPLETE
                // scorecards is met (README methodology). Below quorum the judge
                // component is withheld (community-only) so one early scorecard
                // can't swing a nominee's displayed rank.
                'cpi_score'   => $this->cpi->nomineeScore((int) $n->organic_vote_count, $cohortMax, $eligible ? $ja : null, $w['community'], $w['judge']),
            ];
        }
        return $out;
    }

    /**
     * Weighted judge average (0–10) per nominee, across COMPLETE scorecards only.
     * @param int[] $nomineeIds
     * @return array<int,float>
     */
    public function judgeAveragesFor(array $nomineeIds): array
    {
        $out = [];
        foreach ($this->judgeStatsFor($nomineeIds) as $id => $st) {
            $out[$id] = $st['avg'];
        }
        return $out;
    }

    /**
     * Per-nominee judge stats counting ONLY complete scorecards — a judge whose
     * scores cover EVERY active criterion. Partial scorecards are ignored, so a
     * judge cannot be counted toward quorum (or sway the average) by scoring just
     * one criterion.
     *
     * @param int[] $nomineeIds
     * @return array<int, array{avg: float, judges: int}>
     */
    public function judgeStatsFor(array $nomineeIds): array
    {
        $out = [];
        foreach ($this->judgePanelsFor($nomineeIds) as $nomId => $byJudge) {
            if (!$byJudge) continue;
            $out[$nomId] = [
                'avg'    => round(array_sum($byJudge) / count($byJudge), 2),
                'judges' => count($byJudge),
            ];
        }
        return $out;
    }

    /**
     * Per-nominee, per-judge weighted average (0–10), counting ONLY complete
     * scorecards (every active criterion scored) — the same definition quorum
     * and the CPI use. This is the raw panel spread the leaderboard hides behind
     * a single average; {@see \AfricaGates\Services\JudgeAnomalyService} uses it
     * to spot a judge who is a statistical outlier vs. the rest of the panel.
     *
     * @param int[] $nomineeIds
     * @return array<int, array<int,float>> nomineeId => [judgeId => weightedAvg]
     */
    public function judgePanelsFor(array $nomineeIds): array
    {
        if (!$nomineeIds) return [];

        // Resolve each nominee's programme so "complete" is measured against the
        // SAME programme-scoped criteria set the ballot renders + saveScore()
        // enforces (JudgeService::criteria) — NOT the raw global list. When a
        // programme has no specific override the resolved set == the globals, so
        // this is behaviour-preserving today and correct once overrides exist.
        $programmeOf = [];
        foreach (
            DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->whereIn('n.id', $nomineeIds)
                ->select('n.id', 'cy.programme_id')->get() as $r
        ) {
            $programmeOf[(int) $r->id] = (int) ($r->programme_id ?? 0);
        }

        $tree = [];
        foreach (DB::table('gates_judge_criteria_scores')->whereIn('nominee_id', $nomineeIds)->get() as $s) {
            $tree[(int) $s->nominee_id][(int) $s->judge_id][(int) $s->criterion_id] = (int) $s->score;
        }
        $out = [];
        foreach ($tree as $nomId => $byJudge) {
            $weights   = $this->criteriaWeights($programmeOf[(int) $nomId] ?? 0);
            $activeIds = array_keys($weights);
            $required  = count($activeIds);
            if ($required === 0) continue;

            $perJudge = [];
            foreach ($byJudge as $judgeId => $scoresByCrit) {
                $ws = 0; $wt = 0; $covered = 0;
                foreach ($activeIds as $cid) {
                    if (!array_key_exists($cid, $scoresByCrit)) continue;
                    $covered++;
                    $w = $weights[$cid];
                    $ws += $scoresByCrit[$cid] * $w;
                    $wt += $w;
                }
                // Only a COMPLETE scorecard (every required criterion scored) counts.
                if ($covered === $required && $wt > 0) {
                    $perJudge[(int) $judgeId] = $ws / $wt;
                }
            }
            if ($perJudge) $out[(int) $nomId] = $perJudge;
        }
        return $out;
    }

    /**
     * Resolve the required criteria weights [criterionId => weight] for a
     * programme, mirroring JudgeService::criteria: active rows scoped to the
     * programme OR global (programme_id NULL), deduped by slug preferring the
     * programme-specific row. Cached per call.
     *
     * @return array<int,int>
     */
    private function criteriaWeights(int $programmeId): array
    {
        if (isset($this->criteriaByProgramme[$programmeId])) {
            return $this->criteriaByProgramme[$programmeId];
        }
        $rows = DB::table('gates_judge_criteria')
            ->where('is_active', 1)
            ->where(function ($q) use ($programmeId) {
                $q->where('programme_id', $programmeId)->orWhereNull('programme_id');
            })
            ->orderBy('sort_order')->get();
        $bySlug = [];
        foreach ($rows as $r) {
            $slug = (string) $r->slug;
            if (!isset($bySlug[$slug]) || $r->programme_id) $bySlug[$slug] = $r;
        }
        $weights = [];
        foreach ($bySlug as $r) {
            $weights[(int) $r->id] = (int) $r->weight ?: 25;
        }
        return $this->criteriaByProgramme[$programmeId] = $weights;
    }
}
