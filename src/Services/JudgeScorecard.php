<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What the judges actually wrote down about one nominee.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SCREEN THAT DID NOT EXIST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This platform could not show anybody a score. Every screen built on
 * `gates_judge_criteria_scores` showed arithmetic OVER the marks and never a mark:
 *
 *   • the result release  — a Cultural Power Index, and the judge half of it
 *   • the judging audit   — a panel average, a lean, a criterion's mean and range, the
 *                           spread between the highest and lowest judge
 *   • integrity           — a bias score across groups
 *
 * All of that is second-order. An organiser asked "what did the panel give her" could
 * quote a weighted average to two decimal places and could not name one number a judge
 * had written. A nominee asking the same question could be told 8.35 and nothing else.
 * The rows had been collected since the day the table shipped and no screen rendered one.
 *
 * That is the shape §23 of the index names: the question is not *is anything reading it*
 * — six services read it — but **can the only reader answer the question the data was
 * collected for?** Marks are collected so a panel's judgement can be shown. Nothing could
 * show it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT IS NOT A SELECT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Because a screen that read the table would disagree with the result, and look broken
 * while being right about the rows. Three rules sit between a stored mark and the average
 * that decides an award, and each silently drops marks: a recused judge, a removed judge,
 * and a scorecard that misses one criterion. A nominee can have twenty marks on record
 * and a panel of two.
 *
 * So this decorates {@see NomineeScoringService::panelDetailFor()} — the scorer's own
 * single traversal — with names, labels and timestamps. The number at the bottom of this
 * screen is the number in the index because it is literally the same call, not because
 * two pieces of arithmetic were checked against each other once.
 */
final class JudgeScorecard
{
    /**
     * Every mark on record for one nominee, and what became of each.
     *
     * @return array{
     *   nominee: ?object,
     *   category: ?object,
     *   cycle: ?object,
     *   criteria: list<array{id:int, label:string, weight:int, share:float}>,
     *   judges: list<array<string,mixed>>,
     *   panel: array{average:?float, counted:int, quorum:int, eligible:bool,
     *                ignored:int, points:?int, weight:float},
     *   changes: list<array<string,mixed>>
     * }
     */
    public static function forNominee(int $nomineeId, ?NomineeScoringService $scoring = null): array
    {
        $nominee = DB::table('gates_nominees')->where('id', $nomineeId)->first();

        $blank = ['nominee' => $nominee, 'category' => null, 'cycle' => null,
                  'criteria' => [], 'judges' => [],
                  'panel' => ['average' => null, 'counted' => 0, 'quorum' => 0,
                              'eligible' => false, 'ignored' => 0, 'points' => null,
                              'weight' => 0.0],
                  'changes' => []];
        if (!$nominee) return $blank;

        $category = DB::table('gates_award_categories')
            ->where('id', (int) $nominee->category_id)->first();
        $cycle = $category
            ? DB::table('gates_award_cycles')->where('id', (int) $category->cycle_id)->first()
            : null;

        $scoring ??= new NomineeScoringService();
        $detail   = $scoring->panelDetailFor([$nomineeId])[$nomineeId] ?? null;
        if (!$detail) {
            return ['nominee' => $nominee, 'category' => $category, 'cycle' => $cycle]
                 + $blank;
        }

        $programmeId = (int) $detail['programme_id'];
        $rules       = new RuleEngine();
        $weights     = $rules->weights($programmeId ?: null, $cycle->id ?? null);
        $quorum      = (int) ($rules->effective($programmeId ?: null, $cycle->id ?? null)['min_judges_per_nominee']
                              ?? RuleEngine::DEFAULTS['min_judges_per_nominee']);

        // ── THE RUBRIC, IN BALLOT ORDER ──────────────────────────────────────
        //
        // Ordered as the judge saw it rather than by id, so a reader comparing two
        // scorecards is comparing the same column. `share` is the rubric's own resolver:
        // "worth a quarter of the mark" is the difference between a 4 that matters and
        // one that does not, and computing it here would be a second opinion about it.
        $shares = JudgeRubric::shares($programmeId ?: null);
        $criteria = [];
        foreach (JudgeRubric::effective($programmeId ?: null) as $c) {
            if ((int) $c->is_active !== 1) continue;
            $cid = (int) $c->id;
            if (!isset($detail['weights'][$cid])) continue;   // not in force for this scorer
            $criteria[] = ['id' => $cid, 'label' => (string) $c->label,
                           'weight' => (int) $detail['weights'][$cid],
                           'share' => (float) ($shares[$cid] ?? 0.0)];
        }

        $names = DB::table('gates_judges')
            ->whereIn('id', array_map('intval', array_keys($detail['judges'])))
            ->pluck('name', 'id')->all();

        // When each judge last touched this nominee's card. Read separately because
        // panelDetailFor() is about what counts, and this is about when.
        $when = [];
        foreach (DB::table('gates_judge_criteria_scores')
                     ->where('nominee_id', $nomineeId)
                     ->get(['judge_id', 'created_at', 'updated_at']) as $r) {
            $jid = (int) $r->judge_id;
            $at  = (string) ($r->updated_at ?: $r->created_at);
            if ($at !== '' && ($when[$jid] ?? '') < $at) $when[$jid] = $at;
        }

        $judges  = [];
        $ignored = 0;
        foreach ($detail['judges'] as $jid => $j) {
            $jid = (int) $jid;
            if (!$j['counts']) $ignored++;

            $judges[] = [
                'judge_id' => $jid,
                'judge'    => (string) ($names[$jid] ?? ('Judge #' . $jid)),
                // Keyed by criterion id, so the template walks the rubric and asks for a
                // mark rather than walking the marks and hoping the order matches.
                'marks'    => $j['marks'],
                'covered'  => (int) $j['covered'],
                'required' => (int) $j['required'],
                'average'  => $j['avg'] !== null ? round((float) $j['avg'], 2) : null,
                'counts'   => (bool) $j['counts'],
                'why'      => $j['why'],
                'at'       => $when[$jid] ?? null,
            ];
        }

        // Counted first, then the rest — but by AVERAGE inside each group, because the
        // question a reader brings here is who was high and who was low.
        usort($judges, static function (array $a, array $b): int {
            if ($a['counts'] !== $b['counts']) return $a['counts'] ? -1 : 1;
            return ($b['average'] ?? -1) <=> ($a['average'] ?? -1);
        });

        // ── AND THE NUMBER THE AWARD USES ────────────────────────────────────
        //
        // From the scorer, never from the rows above. The whole point of the screen is
        // that the panel average it prints is the one in the index; recomputing it here
        // from the marks it has just rendered would make the two agree by coincidence
        // until the day one of the three exclusion rules changed.
        $stats = $scoring->judgeStatsFor([$nomineeId])[$nomineeId] ?? null;
        $avg     = $stats['avg'] ?? null;
        $counted = (int) ($stats['judges'] ?? 0);
        $eligible = $counted >= $quorum;

        return [
            'nominee'  => $nominee,
            'category' => $category,
            'cycle'    => $cycle,
            'criteria' => $criteria,
            'judges'   => $judges,
            'panel'    => [
                'average'  => $avg,
                'counted'  => $counted,
                'quorum'   => $quorum,
                'eligible' => $eligible,
                'ignored'  => $ignored,
                // What this contributes to the 0–1000 index, so the screen joins up with
                // the release. Null below quorum: the judge half is scored as absent
                // there, and printing 0 would read as "the panel gave them nothing".
                'points'   => $eligible && $avg !== null
                    ? (int) round($weights['judge'] * ($avg / 10) * 1000) : null,
                'weight'   => (float) $weights['judge'],
            ],
            'changes'  => self::changes($nomineeId, $names),
        ];
    }

    /**
     * Every mark on this nominee that was set and then set again.
     *
     * On the audit screen this is a programme-wide list somebody scans. Here it is the
     * history of ONE card, which is the form the question actually takes: a nominee or
     * their nominator asking whether the mark they are appealing was always that mark.
     *
     * @param array<int,string> $names judge id => name
     * @return list<array<string,mixed>>
     */
    private static function changes(int $nomineeId, array $names): array
    {
        try {
            $rows = DB::table('gates_judge_score_log')
                ->where('nominee_id', $nomineeId)
                ->whereNotNull('old_score')
                ->orderByDesc('changed_at')->orderByDesc('id')
                ->limit(100)->get();
        } catch (\Throwable) {
            // No log table on this deployment. The marks still render.
            return [];
        }

        $criteria = [];
        try {
            $criteria = DB::table('gates_judge_criteria')->pluck('label', 'id')->all();
        } catch (\Throwable) {
            // Labels are a nicety; the change is the record.
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'judge'      => (string) ($names[(int) $r->judge_id] ?? ('Judge #' . (int) $r->judge_id)),
                'criterion'  => (string) ($criteria[(int) $r->criterion_id] ?? ''),
                'old'        => (int) $r->old_score,
                'new'        => (int) $r->new_score,
                'changed_at' => (string) $r->changed_at,
            ];
        }

        return $out;
    }
}
