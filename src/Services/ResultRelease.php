<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The scored nominees, ranked exactly as the award is decided — before it is decided.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * EVERY NUMBER THAT DECIDES AN AWARD WAS COMPUTED AND SHOWN NOWHERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see NomineeScoringService::scoreCategory()} turns votes and marks into the Cultural
 * Power Index, one row per nominee, and it is the thing that crowns winners. It had three
 * callers: the materialiser that promotes, a snapshot writer, and a console command on a
 * host with no shell. No screen. Ever.
 *
 * So the person releasing a result could see who had been crowned and could not see:
 * what any nominee scored, how the community half and the judge half divided it, who was
 * excluded for missing the judge quorum, who was excluded for not being on the published
 * shortlist, how far apart first and second were, or whether the two were separated at
 * all. They could see the outcome and none of the reasoning — which is exactly backwards
 * for the one screen somebody has to defend in public.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE RANKING LIVES HERE AND THE MATERIALISER CALLS IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The obvious build is a screen that re-does the arithmetic. That would be a SECOND
 * opinion about who wins, and this codebase has one rule about that — two readers of one
 * fact is how the halves of a feature come to disagree. Here the disagreement would be
 * between what an operator was shown before the release and what the release then did,
 * which is worse than showing nothing.
 *
 * So {@see rank()} is the ranking, and {@see CycleMaterialiser::promoteWinners()} uses it.
 * The screen is not a preview of the decision; it is the decision, drawn.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT REFUSES TO DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It does not promote, demote, announce or write anything. A screen that could crown
 * somebody by being looked at is not an audit of a release, and the promotion has to stay
 * where the phase engine can keep it idempotent.
 */
final class ResultRelease
{
    /**
     * Why a scored nominee is not in the running. Ordered by how it is applied.
     */
    public const OUT_QUORUM     = 'below the judge quorum';
    public const OUT_SHORTLIST  = 'not on the published shortlist';
    public const OUT_NO_SUPPORT = 'no organic votes and no CPI';

    /**
     * One category, fully drawn: every scored nominee, ranked, with the reason for
     * anybody who is out of the running.
     *
     * @return array{
     *   category: ?object,
     *   quorum: int,
     *   weights: array{community:float, judge:float},
     *   shortlisted: ?list<int>,
     *   rows: list<array<string,mixed>>,
     *   winner: ?array<string,mixed>,
     *   runner_up: ?array<string,mixed>,
     *   margin: ?int,
     *   dead_heat: bool,
     *   blocked: ?string
     * }
     */
    public static function category(int $categoryId, ?NomineeScoringService $scoring = null): array
    {
        $cat = DB::table('gates_award_categories')->where('id', $categoryId)->first();

        $ctx = DB::table('gates_award_categories as c')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->where('c.id', $categoryId)
            ->select('cy.id as cycle_id', 'cy.programme_id')->first();

        $rules   = new RuleEngine();
        $weights = $rules->weights($ctx->programme_id ?? null, $ctx->cycle_id ?? null);
        $quorum  = (int) ($rules->effective($ctx->programme_id ?? null, $ctx->cycle_id ?? null)['min_judges_per_nominee']
                          ?? RuleEngine::DEFAULTS['min_judges_per_nominee']);

        $empty = ['category' => $cat, 'quorum' => $quorum, 'weights' => $weights,
                  'shortlisted' => null, 'rows' => [], 'winner' => null, 'runner_up' => null,
                  'margin' => null, 'dead_heat' => false, 'blocked' => null];

        $scores = ($scoring ?? new NomineeScoringService())->scoreCategory($categoryId);
        if ($scores === []) return $empty + [];

        $shortlisted = self::shortlistedIn($categoryId);

        // The nominees themselves, for the names and the organic count the ranking turns on.
        $nominees = DB::table('gates_nominees')->whereIn('id', array_keys($scores))
            ->get()->keyBy('id');

        $rows = [];
        foreach ($scores as $nid => $s) {
            $n       = $nominees[$nid] ?? null;
            $organic = (int) ($n->organic_vote_count ?? 0);
            $cpi     = (int) ($s['cpi_score'] ?? 0);

            // Applied in the order the promotion applies them, so the reason shown is the
            // FIRST one that put them out — which is the one an operator has to answer for.
            $out = null;
            if (empty($s['eligible']))                                   $out = self::OUT_QUORUM;
            elseif ($shortlisted !== null && !in_array((int) $nid, $shortlisted, true))
                                                                          $out = self::OUT_SHORTLIST;
            elseif ($cpi <= 0 && $organic <= 0)                          $out = self::OUT_NO_SUPPORT;

            $rows[] = [
                'nominee_id'  => (int) $nid,
                'name'        => (string) ($n->name ?? 'Nominee #' . $nid),
                'status'      => (string) ($n->status ?? ''),
                'cpi'         => $cpi,
                // BOTH numbers, because they are different claims. `organic` is what the
                // ranking and the tiebreak use; `vote_count` is what the public page
                // shows, and it includes purchased support that must never move a rank.
                'organic'     => $organic,
                'vote_count'  => (int) ($s['vote_count'] ?? 0),
                'judge_score' => $s['judge_score'] ?? null,
                'judges'      => (int) ($s['judges'] ?? 0),
                'eligible'    => !empty($s['eligible']),
                'provisional' => !empty($s['provisional']),
                'on_shortlist' => $shortlisted === null ? null : in_array((int) $nid, $shortlisted, true),
                'out_reason'  => $out,
                'in_running'  => $out === null,
            ];
        }

        $running = array_values(array_filter($rows, static fn (array $r): bool => $r['in_running']));
        usort($running, self::order(...));

        // Rank only the people who could win it. A number beside somebody the quorum
        // already excluded reads as a placing they never had.
        $rank = [];
        foreach ($running as $i => $r) $rank[$r['nominee_id']] = $i + 1;
        foreach ($rows as $i => $r) $rows[$i]['rank'] = $rank[$r['nominee_id']] ?? null;

        usort($rows, static function (array $a, array $b): int {
            // In the running first, in order; everybody else after, best CPI first, so a
            // strong nominee the quorum excluded is visible rather than buried.
            if ($a['in_running'] !== $b['in_running']) return $a['in_running'] ? -1 : 1;
            if ($a['in_running']) return $a['rank'] <=> $b['rank'];
            return [$b['cpi'], $b['organic']] <=> [$a['cpi'], $a['organic']];
        });

        $winner   = $running[0] ?? null;
        $runnerUp = $running[1] ?? null;

        return [
            'category'    => $cat,
            'quorum'      => $quorum,
            'weights'     => $weights,
            'shortlisted' => $shortlisted,
            'rows'        => $rows,
            'winner'      => $winner,
            'runner_up'   => $runnerUp,
            // How much of a result this is. A one-point margin on a 0–1000 index is a
            // different thing to defend from a two-hundred-point one, and neither was
            // visible anywhere.
            'margin'      => ($winner && $runnerUp) ? $winner['cpi'] - $runnerUp['cpi'] : null,
            // The methodology could not separate them and the award went to the lower id.
            // It was written into a maintenance log with "this one needs a human" and no
            // human has a log to read on a host with no shell.
            'dead_heat'   => (bool) ($winner && $runnerUp
                                     && $winner['cpi'] === $runnerUp['cpi']
                                     && $winner['organic'] === $runnerUp['organic']),
            'blocked'     => $running === []
                ? 'No nominee here meets the judge quorum, so this category crowns nobody '
                  . 'until somebody looks at it.'
                : null,
        ];
    }

    /**
     * THE ORDER AN AWARD IS DECIDED IN. One comparator, used by the screen and the
     * promotion both.
     *
     * ── WHY THE TIEBREAK IS ORGANIC ──────────────────────────────────────────
     *
     * The platform's whole promise is that money can make a nominee look popular and can
     * never buy their Cultural Power Index. Paid and bonus votes move `vote_count` and are
     * deliberately kept out of `organic_vote_count`. This tie broke on `vote_count` once,
     * and at that single moment every guard upstream became decoration: two nominees on
     * equal CPI, and whoever had bought votes took the award.
     *
     * A real dead heat — same CPI, same organic support — falls to the lowest id so the
     * promotion stays deterministic and idempotent. That is an arbitrary decision and it
     * is reported as one rather than hidden inside a sort.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public static function order(array $a, array $b): int
    {
        return [$b['cpi'], $b['organic'], $a['nominee_id']]
           <=> [$a['cpi'], $a['organic'], $b['nominee_id']];
    }

    /**
     * Nominee ids on this category's published shortlist, or NULL when it has none.
     *
     * The null is load-bearing and is not the same as an empty list: "this category does
     * not shortlist" and "this category shortlisted nobody" are different states, and
     * collapsing them would stop a non-shortlisting programme from ever crowning anybody.
     *
     * @return list<int>|null
     */
    public static function shortlistedIn(int $categoryId): ?array
    {
        try {
            $shortlistId = (int) (DB::table('gates_shortlists')
                ->where('category_id', $categoryId)->where('status', 'published')
                ->orderByDesc('id')->value('id') ?? 0);

            if ($shortlistId < 1) return null;

            return DB::table('gates_shortlist_entries')->where('shortlist_id', $shortlistId)
                ->pluck('nominee_id')->map(static fn ($v): int => (int) $v)->all();
        } catch (\Throwable) {
            // No shortlist tables on this deployment. Treated as "does not shortlist",
            // which is the state that changes nothing rather than the one that crowns
            // nobody.
            return null;
        }
    }

    /**
     * Every category in a cycle, drawn. For the release screen.
     *
     * @return list<array<string,mixed>>
     */
    public static function forCycle(int $cycleId): array
    {
        try {
            $catIds = DB::table('gates_award_categories')->where('cycle_id', $cycleId)
                ->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        } catch (\Throwable) {
            return [];
        }

        // One scorer across the cycle: it caches criteria weights per programme, and a
        // fresh one per category would re-read the rubric for every category on the page.
        $scoring = new NomineeScoringService();

        $out = [];
        foreach ($catIds as $id) $out[] = self::category((int) $id, $scoring);

        return $out;
    }

    /**
     * What an operator needs to look at first, across a whole cycle.
     *
     * @param list<array<string,mixed>> $categories
     * @return array<string,int>
     */
    public static function attention(array $categories): array
    {
        $n = ['categories' => count($categories), 'blocked' => 0, 'dead_heats' => 0,
              'thin_margins' => 0, 'excluded' => 0, 'provisional' => 0];

        foreach ($categories as $c) {
            if ($c['blocked'] !== null)  $n['blocked']++;
            if ($c['dead_heat'])         $n['dead_heats']++;
            // A margin inside ten points of a thousand is a result that could turn on one
            // judge changing one mark, and the audit screen can say whether one did.
            if ($c['margin'] !== null && $c['margin'] <= 10) $n['thin_margins']++;

            foreach ($c['rows'] as $r) {
                if ($r['out_reason'] !== null) $n['excluded']++;
                if ($r['provisional'])         $n['provisional']++;
            }
        }

        return $n;
    }
}
