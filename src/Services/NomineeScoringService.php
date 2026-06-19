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

    /**
     * Per-nominee scores for one category (cohort-normalised community + judges,
     * with the effective per-cycle CPI weights from the rule engine).
     * @return array<int, array{vote_count:int, judge_score:float|null, cpi_score:int}>
     */
    public function scoreCategory(int $categoryId): array
    {
        $nominees = DB::table('gates_nominees')->where('category_id', $categoryId)
            ->whereIn('status', ['approved', 'winner', 'runner_up'])->get();
        if ($nominees->isEmpty()) return [];

        // Effective CPI weights for this category's cycle (config over defaults).
        $ctx = DB::table('gates_award_categories as c')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->where('c.id', $categoryId)
            ->select('cy.id as cycle_id', 'cy.programme_id')->first();
        $w = $this->rules->weights($ctx->programme_id ?? null, $ctx->cycle_id ?? null);

        $cohortMax = max(1, (int) $nominees->max('vote_count'));
        $judge = $this->judgeAveragesFor($nominees->pluck('id')->all());

        $out = [];
        foreach ($nominees as $n) {
            $ja = $judge[$n->id] ?? null;
            $out[(int) $n->id] = [
                'vote_count'  => (int) $n->vote_count,
                'judge_score' => $ja,
                'cpi_score'   => $this->cpi->nomineeScore((int) $n->vote_count, $cohortMax, $ja, $w['community'], $w['judge']),
            ];
        }
        return $out;
    }

    /**
     * Weighted judge average (0–10) per nominee, averaged across judges.
     * @param int[] $nomineeIds
     * @return array<int,float>
     */
    public function judgeAveragesFor(array $nomineeIds): array
    {
        if (!$nomineeIds) return [];
        $weights = [];
        foreach (DB::table('gates_judge_criteria')->where('is_active', 1)->get() as $c) {
            $weights[$c->id] = (int) $c->weight ?: 25;
        }
        $tree = [];
        foreach (DB::table('gates_judge_criteria_scores')->whereIn('nominee_id', $nomineeIds)->get() as $s) {
            $tree[$s->nominee_id][$s->judge_id][$s->criterion_id] = (int) $s->score;
        }
        $out = [];
        foreach ($tree as $nomId => $byJudge) {
            $perJudge = [];
            foreach ($byJudge as $scoresByCrit) {
                $ws = 0; $wt = 0;
                foreach ($scoresByCrit as $cid => $sc) { $w = $weights[$cid] ?? 25; $ws += $sc * $w; $wt += $w; }
                if ($wt > 0) $perJudge[] = $ws / $wt;
            }
            if ($perJudge) $out[(int) $nomId] = round(array_sum($perJudge) / count($perJudge), 2);
        }
        return $out;
    }
}
