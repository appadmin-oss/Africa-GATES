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
     * @return array<int, array{vote_count:int, cohort_max:int, judge_score:float|null, cpi_score:int}>
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
                // ── THE DENOMINATOR THE COMMUNITY HALF IS MEASURED AGAINST ───
                //
                // Returned rather than kept local, because without it NOBODY can check a
                // CPI. The community component is `organic / cohortMax`, so 2,650 votes
                // is worth 55% of the community weight or 26% of it depending entirely on
                // a number that appeared on no screen — and `ResultRelease` recomputing it
                // would be a second reader of the one fact that decides the award.
                //
                // It also carries a consequence worth seeing: the cohort is every scored
                // nominee in the category, INCLUDING one the shortlist or the quorum has
                // put out of the running. Somebody who cannot win still sets the scale
                // everybody else is measured on.
                'cohort_max'  => $cohortMax,
                'judge_score' => $ja,
                'judges'      => $judges,                          // COMPLETE scorecards only
                'eligible'    => $eligible,
                // ── A BELOW-QUORUM FIGURE IS NOT A CPI ───────────────────────
                //
                // True whenever the judge half has not been counted, which is the only
                // honest label for the number beside it. Without this flag a
                // community-only score sits in the same column as a full CPI and reads
                // as one — the figures are not comparable and nothing said so.
                'provisional' => !$eligible,
                // ── AND WHY THE JUDGE HALF IS NOT RENORMALISED AWAY ──────────
                //
                // The obvious "fix" for a below-quorum nominee is to give community the
                // full weight instead of scoring judges zero — the comment here used to
                // say the component was "withheld (community-only)", which is what
                // renormalising would mean and is NOT what this does.
                //
                // Measured, with a cohort max of 100 organic votes:
                //
                //     100 votes, judged 6.0/10 .................  780
                //     100 votes, not yet judged (as built) .....  450
                //     100 votes, not yet judged (renormalised) . 1000
                //      50 votes, judged 6.0/10 .................  555
                //
                // Renormalising puts an UNJUDGED nominee at the top of the board on
                // popularity alone, which is the single thing this platform exists to
                // prevent. Scoring the absent half as zero is the conservative direction
                // — it understates rather than overstates — and `provisional` above is
                // what stops the understatement being mistaken for a verdict.
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
     * A reduction of {@see panelDetailFor()}, which is where the rules live.
     *
     * @param int[] $nomineeIds
     * @return array<int, array<int,float>> nomineeId => [judgeId => weightedAvg]
     */
    public function judgePanelsFor(array $nomineeIds): array
    {
        $out = [];
        foreach ($this->panelDetailFor($nomineeIds) as $nomId => $d) {
            $keep = [];
            foreach ($d['judges'] as $jid => $j) {
                if ($j['counts']) $keep[(int) $jid] = (float) $j['avg'];
            }
            if ($keep) $out[(int) $nomId] = $keep;
        }

        return $out;
    }

    /**
     * EVERY mark on record for these nominees, and whether each one counts.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE PLATFORM COULD NOT SHOW ANYBODY A SCORE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `gates_judge_criteria_scores` is one row per judge per nominee per criterion, and
     * every screen built on it showed arithmetic OVER those rows — a panel average, a
     * lean against the rest of the panel, a criterion's mean and range, the spread
     * between the highest and lowest judge. Not one showed a MARK. An organiser asked
     * "what did the panel actually give her" could answer with a weighted average to two
     * decimal places and could not name a single number any judge wrote down.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AND WHY THE ANSWER IS NOT "SELECT THE ROWS"
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Because a screen that read the table would DISAGREE with the result, and look
     * broken while being right about the rows. Three rules sit between a stored mark and
     * the average that decides an award, and each one silently drops marks:
     *
     *   • a judge who recused themselves on this programme — every mark dropped, not
     *     merely no further ones accepted;
     *   • a judge taken off the panel (`is_active = 0`) — the same;
     *   • a scorecard that does not cover every active criterion — dropped whole, so a
     *     judge who marked four of five contributes nothing at all.
     *
     * A screen reproducing those rules would be a second reader of the one fact that
     * decides the award, and the first thing to drift. So this is the SINGLE traversal:
     * {@see judgePanelsFor()} reduces it to the map the scorer uses, and the scorecard
     * screen renders the same structure with the reasons attached. They cannot disagree,
     * because there is nothing to disagree with.
     *
     * Barred and incomplete cards are RETURNED, marked, rather than filtered out here.
     * A mark a judge really wrote and the platform really ignored is the thing somebody
     * appealing a result most needs to see, and silently omitting it is how a screen
     * comes to look like it is hiding something.
     *
     * @param int[] $nomineeIds
     * @return array<int, array{programme_id:int, weights:array<int,int>, judges:array<int, array{
     *     marks:array<int,int>, covered:int, required:int, avg:?float,
     *     counts:bool, why:?string}>}>
     */
    public const NOT_COUNTED_RECUSED    = 'recused';
    public const NOT_COUNTED_REMOVED    = 'removed';
    public const NOT_COUNTED_INCOMPLETE = 'incomplete';

    public function panelDetailFor(array $nomineeIds): array
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

        // ── WHOSE MARKS ARE ALLOWED TO COUNT ─────────────────────────────────
        //
        // Resolved before the tree is built, because the answer is not a property of a
        // score row: it is a property of the JUDGE, and of the programme the nominee
        // sits in. See {@see disqualifiedJudges()}.
        $barred = $this->disqualifiedJudges(array_values(array_unique($programmeOf)));

        // Every mark, including the ones that will not count. The filtering used to
        // happen HERE, which is why nothing downstream could ever explain an absence.
        $tree = [];
        foreach (DB::table('gates_judge_criteria_scores')->whereIn('nominee_id', $nomineeIds)->get() as $s) {
            $tree[(int) $s->nominee_id][(int) $s->judge_id][(int) $s->criterion_id] = (int) $s->score;
        }

        $out = [];
        foreach ($tree as $nomId => $byJudge) {
            $prog      = $programmeOf[(int) $nomId] ?? 0;
            $weights   = $this->criteriaWeights($prog);
            $activeIds = array_keys($weights);
            $required  = count($activeIds);
            if ($required === 0) continue;

            $judges = [];
            foreach ($byJudge as $judgeId => $scoresByCrit) {
                $judgeId = (int) $judgeId;
                $ws = 0; $wt = 0; $covered = 0; $marks = [];

                foreach ($activeIds as $cid) {
                    if (!array_key_exists($cid, $scoresByCrit)) continue;
                    $covered++;
                    $marks[$cid] = $scoresByCrit[$cid];
                    $w  = $weights[$cid];
                    $ws += $scoresByCrit[$cid] * $w;
                    $wt += $w;
                }

                // ── WHY A MARK DOES NOT COUNT, IN THE ORDER IT IS DECIDED ────
                //
                // Removal and recusal first, because they are facts about the JUDGE and
                // they drop a card however complete it is. A recused judge's finished
                // scorecard is not "incomplete"; saying so would name the wrong reason on
                // the screen somebody reads during an appeal.
                $why = null;
                if (isset($barred['inactive'][$judgeId]))          $why = self::NOT_COUNTED_REMOVED;
                elseif (isset($barred['coi'][$prog][$judgeId]))    $why = self::NOT_COUNTED_RECUSED;
                elseif ($covered !== $required || $wt <= 0)        $why = self::NOT_COUNTED_INCOMPLETE;

                $judges[$judgeId] = [
                    'marks'    => $marks,
                    'covered'  => $covered,
                    'required' => $required,
                    // The weighted average is computed for a complete card whatever its
                    // standing, so a screen can show what a recused judge's assessment
                    // WOULD have been — but `counts` is what the scorer reduces on, and
                    // only that.
                    'avg'      => ($covered === $required && $wt > 0) ? $ws / $wt : null,
                    'counts'   => $why === null,
                    'why'      => $why,
                ];
            }

            $out[(int) $nomId] = [
                'programme_id' => $prog,
                'weights'      => $weights,
                'judges'       => $judges,
            ];
        }

        return $out;
    }

    /**
     * Judges whose marks must not count, and why.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE TWO FAILURES THIS EXISTS BECAUSE OF
     * ══════════════════════════════════════════════════════════════════════════
     *
     * 1 · A RECUSED JUDGE WAS STILL DECIDING THE AWARD.
     *
     *     {@see \AfricaGates\Judge\Services\JudgeService::declareConflict()} writes a row
     *     to `gates_judge_coi` and does nothing else. `saveScore()` reads it and refuses
     *     FURTHER marks — but every mark already given stayed in the average and kept
     *     counting toward quorum.
     *
     *     So a judge who realised mid-cycle that they knew a nominee, and did exactly the
     *     right thing by recusing, left their assessment inside the result. Measured: two
     *     judges on 10 and 2, average 6.00; the 10 recuses; average still 6.00, still two
     *     judges. Recusal is a promise about the RESULT, not about a form being disabled.
     *
     * 2 · A JUDGE TAKEN OFF THE PANEL WAS STILL DECIDING IT TOO.
     *
     *     `is_active = 0` is how a judge is removed — resignation, misconduct, an
     *     appointment that should never have been made. It stops them signing in. It did
     *     not stop their marks counting.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AND WHY THIS IS DELIBERATELY RETROACTIVE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Dropping the marks can push a nominee back under quorum, which makes them
     * winner-INELIGIBLE, which can leave a category with no promotable nominee at all.
     * {@see \AfricaGates\Services\CycleMaterialiser::promoteWinners()} already handles
     * that case by skipping the category and logging it for manual review, which is the
     * correct conservative outcome: a category decided by a panel that has since been
     * disqualified needs a person, not a cron job.
     *
     * The alternative — honouring a recusal only from the moment it is declared — means the
     * platform publishes a result partly decided by somebody who told us they should not be
     * deciding it. That is not a defensible award.
     *
     * @param  list<int> $programmeIds programmes in play, so the COI lookup is scoped
     * @return array{inactive: array<int,true>, coi: array<int, array<int,true>>}
     */
    private function disqualifiedJudges(array $programmeIds): array
    {
        $out = ['inactive' => [], 'coi' => []];

        try {
            foreach (DB::table('gates_judges')->where('is_active', 0)->pluck('id') as $id) {
                $out['inactive'][(int) $id] = true;
            }
        } catch (\Throwable $e) {
            // A failure here must not silently ADMIT everybody — but it must also not stop
            // scoring entirely. Logged, and the COI pass below still runs.
            error_log('[scoring] could not read inactive judges: ' . $e->getMessage());
        }

        $programmeIds = array_values(array_filter(array_map('intval', $programmeIds)));
        if ($programmeIds !== []) {
            try {
                foreach (DB::table('gates_judge_coi')->whereIn('programme_id', $programmeIds)
                            ->get(['judge_id', 'programme_id']) as $r) {
                    $out['coi'][(int) $r->programme_id][(int) $r->judge_id] = true;
                }
            } catch (\Throwable $e) {
                error_log('[scoring] could not read judge conflicts: ' . $e->getMessage());
            }
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
