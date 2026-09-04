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
     *   blocked: ?string,
     *   tie_broken_by_votes: bool,
     *   cohort_max: int,
     *   scale_set_by: ?string,
     *   scale_set_by_id: int,
     *   scale_is_out: bool
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
        // ONE read of the effective ruleset, not one per value. This used to resolve the
        // quorum here and leave the paid-vote cap to whoever needed it — and the two would
        // then be scoped separately, so a cycle-level override could move one and not the
        // other while both were being reported as this category's rules.
        $eff     = $rules->effective($ctx->programme_id ?? null, $ctx->cycle_id ?? null);
        $quorum  = (int) ($eff['min_judges_per_nominee']
                          ?? RuleEngine::DEFAULTS['min_judges_per_nominee']);
        // The ceiling on purchased votes, as a percentage of a nominee's organic support.
        // Part of the methodology a public page has to be able to state, so it travels
        // with the quorum and the weights rather than being fetched again downstream.
        $paidCap = (int) ($eff['max_paid_weight_pct'] ?? 50);
        // The category-leader vote count at which the community half pays in full. On the
        // screen so an operator can see WHY a leader's 450 came out lower — without it the
        // discount is invisible and the page looks like it has miscounted.
        $fullCredit = (int) ($eff['community_full_credit_votes']
                             ?? RuleEngine::DEFAULTS['community_full_credit_votes']);

        $empty = ['category' => $cat, 'quorum' => $quorum, 'weights' => $weights,
                  'paid_only' => PaidVoteService::freeVotingDisabled(),
                  'paid_cap_pct' => $paidCap, 'community_full_credit' => $fullCredit,
                  'shortlisted' => null, 'rows' => [], 'winner' => null, 'runner_up' => null,
                  'margin' => null, 'dead_heat' => false, 'tie_broken_by_votes' => false,
                  'blocked' => null,
                  'cohort_max' => 0, 'scale_set_by' => null, 'scale_set_by_id' => 0,
                  'scale_is_out' => false, 'community_dark' => false];

        $scores = ($scoring ?? new NomineeScoringService())->scoreCategory($categoryId);
        if ($scores === []) return $empty + [];

        $shortlisted = self::shortlistedIn($categoryId);

        // The nominees themselves, for the names and the organic count the ranking turns on.
        $nominees = DB::table('gates_nominees')->whereIn('id', array_keys($scores))
            ->get()->keyBy('id');

        $rows = [];
        foreach ($scores as $nid => $s) {
            $n         = $nominees[$nid] ?? null;
            $organic   = (int) ($n->organic_vote_count ?? 0);
            $votes     = (int) ($s['vote_count'] ?? ($n->vote_count ?? 0));
            $cpi       = (int) ($s['cpi_score'] ?? 0);
            $cohortMax = max(1, (int) ($s['cohort_max'] ?? 1));

            // Applied in the order the promotion applies them, so the reason shown is the
            // FIRST one that put them out — which is the one an operator has to answer for.
            $out = null;
            if (empty($s['eligible']))                                   $out = self::OUT_QUORUM;
            elseif ($shortlisted !== null && !in_array((int) $nid, $shortlisted, true))
                                                                          $out = self::OUT_SHORTLIST;
            elseif ($cpi <= 0 && $organic <= 0)                          $out = self::OUT_NO_SUPPORT;

            // ── THE ARITHMETIC, SPLIT SO IT CAN BE CHECKED ───────────────────
            //
            // The screen showed organic votes, a judge mark out of ten, a pair of
            // weights, and a CPI out of a thousand — and no way to get from any of them
            // to the last one. Somebody defending a result in public could read every
            // input and still not say why the number was the number.
            //
            // It is not derivable by eye, either, because the community half is scaled
            // against the COHORT MAXIMUM rather than against the votes cast: 2,650
            // organic is worth 247 points behind a leader on 4,820 and 450 points on its
            // own. The denominator is the whole explanation and it appeared nowhere.
            // THE FULL TALLY, matching the scorer. `$organic` is still carried below —
            // every public surface shows how much of a total was bought — but it decides
            // nothing now.
            // ── ASKED, NOT RECOMPUTED ────────────────────────────────────────
            //
            // These two lines were this screen's own copy of `weight × share × 1000`, with
            // the judge half taken as the remainder. It agreed with the scorer for exactly
            // as long as both were linear — and the moment a curve went on the community
            // share, this published a LINEAR half beside a curved index, so the two figures
            // under a nominee's name stopped describing the number they were printed
            // beside and the judge half absorbed the difference. Two nominees on an
            // identical 7.6 panel mark were shown 66 and 112.
            //
            // A screen whose whole job is showing how a number was reached must not compute
            // any part of it twice. {@see CpiService::split()} is the one place.
            $cPts = (int) ($s['community_points'] ?? 0);
            $jPts = (int) ($s['judge_points'] ?? ($cpi - $cPts));

            // Still reported as a percentage of the leader's support, because that is the
            // question a reader asks — but it is the RAW share, not the curved one. The
            // curve is how the share is paid; the share itself is what somebody counts.
            $share = $cohortMax > 0 ? min(1.0, $votes / $cohortMax) : 0.0;

            $rows[] = [
                'nominee_id'  => (int) $nid,
                'name'        => (string) ($n->name ?? 'Nominee #' . $nid),
                'status'      => (string) ($n->status ?? ''),
                'cpi'         => $cpi,
                'community_points' => $cPts,
                'judge_points'     => $jPts,
                // What fraction of the leader's support this is — the step between a vote
                // count and a number of points, which is the step nobody could take.
                'community_share'  => (int) round($share * 100),
                // BOTH numbers, because they are different claims. `organic` is what the
                // ranking and the tiebreak use; `vote_count` is what the public page
                // shows, and it includes purchased support that must never move a rank.
                'organic'     => $organic,
                // WHAT THE RANKING READS. Named separately from `vote_count` so the
                // comparator and the scorer cannot come to mean different things by
                // "support" — they are the same figure and this is the name it carries
                // through every ranking decision.
                'votes'       => $votes,
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
            return [$b['cpi'], $b['votes']] <=> [$a['cpi'], $a['votes']];
        });

        $winner   = $running[0] ?? null;
        $runnerUp = $running[1] ?? null;

        // ── WHOSE VOTES SET THE SCALE ────────────────────────────────────────
        //
        // The cohort maximum is the denominator of every community half in this category,
        // and it is TAKEN FROM THE SCORER rather than worked out again here. This used to
        // be a second `max()` over the rows on this page, which agreed with the scorer only
        // for as long as both happened to mean "everybody who scored" — the moment the
        // scorer narrowed its cohort to the published field, this screen went on telling an
        // operator the denominator was a nominee who was not in it. A screen whose whole
        // job is showing how a number was reached must not compute any part of it twice.
        //
        // The scale-setter is then simply whoever HOLDS that denominator. Null where nobody
        // does, which is the all-zero category: the scorer floors the denominator at 1 so
        // nothing divides by nought, and no nominee has one vote.
        $cohortMax = 0;
        foreach ($scores as $s) { $cohortMax = max(1, (int) ($s['cohort_max'] ?? 1)); break; }

        $scale = null;
        foreach ($rows as $r) {
            if ($r['votes'] === $cohortMax) { $scale = $r; break; }
        }

        // ── THE COMMUNITY HALF IS SWITCHED OFF FOR THIS WHOLE CATEGORY ───────
        //
        // Nobody in the field has a single organic vote, so every community share is 0/1 and
        // every community half is zero — the panel decides the award alone, at whatever
        // weight the rules say the community was worth.
        //
        // It is not a hypothetical. A live cycle ran with four nominees holding 1,536, 1,955,
        // 126 and 398 votes and organic counts of zero on all four, and the second-most-voted
        // nominee lost to the most-judged one with nothing on the screen saying the 45% had
        // been dropped. The operator found it by reading the numbers and calling it cheating.
        //
        // The commonest cause is not fraud, it is a STALE COUNTER: `organic_vote_count` is
        // denormalised, `gates_votes` is the ledger, and votes that arrived by any path that
        // does not maintain the counter — an import, a restore, a code path that predates the
        // column — leave the two disagreeing with nothing to notice it. Hence `recount()`.
        //
        // AND IT IS A FAULT ONLY WHERE THE COMMUNITY WAS SUPPOSED TO COUNT. A programme is
        // allowed to weight the community at zero — a juried prize with a public vote that
        // decides nothing is a legitimate configuration, and {@see RuleEngine::weights()}
        // will hand back `community: 0.0` for it. There, "nobody has an organic vote" is not
        // a dropped half, it is the rules working; warning about it would put a red caveat
        // on every category of a jury award forever, which is how an operator learns to
        // scroll past the box that matters.
        $dark = $rows !== [] && $weights['community'] > 0.0;
        foreach ($rows as $r) { if ((int) $r['votes'] > 0) { $dark = false; break; } }

        return [
            'category'    => $cat,
            'quorum'      => $quorum,
            'weights'     => $weights,
            'paid_cap_pct' => $paidCap,
            'community_full_credit' => $fullCredit,
            // True when NOT ONE nominee has an organic vote. Distinct from `cohort_max` being
            // zero, which a template could work out for itself and which does not say what
            // the consequence is.
            'community_dark' => $dark,
            'shortlisted' => $shortlisted,
            'rows'        => $rows,
            'cohort_max'     => $scale !== null ? $cohortMax : 0,
            'scale_set_by'   => $scale['name'] ?? null,
            'scale_set_by_id' => (int) ($scale['nominee_id'] ?? 0),
            // Out of the running AND holding somebody else down. A category whose only
            // nominee is below the quorum has a scale-setter who is technically "out",
            // and warning about it there is a warning about nobody — which teaches an
            // operator to skip the box on the categories where it means something.
            //
            // Narrower than it was, and better for it. The shortlist now scopes the
            // scorer's cohort, so this can no longer fire for somebody taken off the list;
            // what remains is the case the quorum leaves open on purpose — a nominee who
            // is in the field and whose panel has not finished. Below quorum is pending
            // rather than out, so they keep the scale, and the screen says whose it is.
            'scale_is_out'   => $scale !== null && !$scale['in_running'] && $running !== [],
            // ── WAS THERE A FREE VOTE TO HAVE? ───────────────────────────────
            //
            // Every surface that prints an organic count frames it as the part of a tally
            // that was NOT bought — which reads as a choice the voter made. Where
            // `paid_voting_disable_free` is set there was no choice: the free path answers
            // 403, so organic is zero for everybody and "0 of them organic" is a sentence
            // about the ballot wearing the clothes of a sentence about the nominee.
            //
            // Resolved HERE, on the drawn result, because the public page and the release
            // screen an operator signs off both read this object. Two readers of one
            // setting is how the two screens come to describe the same award differently —
            // and this one decides whether a column of zeros is an accusation or a fact.
            'paid_only'      => PaidVoteService::freeVotingDisabled(),
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
                                     && $winner['votes'] === $runnerUp['votes']),
            // ── THE INDEX TIED AND SOMETHING ELSE DECIDED IT ─────────────────
            //
            // Equal CPI with DIFFERENT organic support is not a dead heat — the comparator
            // separates them, on votes — but the screen could not say so. It reported
            // "first and second are 0 apart" beside a WINS badge and left the reader to
            // work out what broke a tie the page had just called exact.
            //
            // The tiebreak is the same measure the index uses — the full tally. Worth
            // SAYING at the one moment it decides an award, because that is the only
            // moment anybody will question it.
            'tie_broken_by_votes' => (bool) ($winner && $runnerUp
                                     && $winner['cpi'] === $runnerUp['cpi']
                                     && $winner['votes'] !== $runnerUp['votes']),
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
     * ── WHY THE TIEBREAK IS THE FULL TALLY ───────────────────────────────────
     *
     * Because that is what the index counts. This used to break on `organic_vote_count`
     * while the CPI's community half read the same column, and the pair was consistent;
     * the note here argued at length that breaking on `vote_count` would let money take an
     * award the index had tied. Both halves of that argument moved together: the community
     * half now reads the full tally, so a tiebreak on organic support would be the
     * inconsistent one — a ranking decided on every vote, separated on a subset of them,
     * with no way to explain to the nominee who lost why the two questions had different
     * answers.
     *
     * A real dead heat — same CPI, same tally — falls to the lowest id so the promotion
     * stays deterministic and idempotent. That is an arbitrary decision and it is reported
     * as one rather than hidden inside a sort.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public static function order(array $a, array $b): int
    {
        return [$b['cpi'], $b['votes'], $a['nominee_id']]
           <=> [$a['cpi'], $a['votes'], $b['nominee_id']];
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
        $n = ['categories' => count($categories), 'needs_person' => 0, 'blocked' => 0,
              'dead_heats' => 0, 'thin_margins' => 0, 'excluded' => 0, 'provisional' => 0];

        foreach ($categories as $c) {
            $dead = (bool) $c['dead_heat'];
            // ── A DEAD HEAT IS NOT ALSO A THIN MARGIN ────────────────────────
            //
            // It counted as both, because a dead heat has a margin of zero and zero is
            // inside ten. So one category appeared under two headings, and an operator
            // adding the tiles up got four things to look at in a cycle that had three.
            // A summary whose numbers overlap is worse than no summary: it cannot be
            // reconciled with the page under it, and the page under it is the evidence.
            $thin = !$dead && $c['margin'] !== null && $c['margin'] <= 10;

            if ($c['blocked'] !== null) $n['blocked']++;
            if ($dead) $n['dead_heats']++;
            // A margin inside ten points of a thousand is a result that could turn on one
            // judge changing one mark, and the audit screen can say whether one did.
            if ($thin) $n['thin_margins']++;

            // And the count that is actually the question. Three tiles asked an operator
            // to work out how many CATEGORIES were involved, which is not the same
            // addition — a category can be blocked and nothing else, or blocked with a
            // dead heat behind the block.
            if ($c['blocked'] !== null || $dead || $thin) $n['needs_person']++;

            foreach ($c['rows'] as $r) {
                if ($r['out_reason'] !== null) $n['excluded']++;
                if ($r['provisional'])         $n['provisional']++;
            }
        }

        return $n;
    }

    /**
     * The standing for the whole cycle — every nominee in it, ranked.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHAT THIS USED TO DO, AND WHY IT CHANGED
     * ══════════════════════════════════════════════════════════════════════════
     *
     * It took each category's WINNER and ranked those, on the argument that "an overall
     * award which could go to somebody who did not win their own category is not an
     * overall award, it is a second opinion."
     *
     * That argument is sound about an AWARD and wrong about a STANDING, and this produces
     * a standing. On a real cycle it gave an overall second place of 89 votes and a third
     * of 19, while this nominee did not appear at all:
     *
     *     Dr. Adegboyega Aborode   1,536 votes · 8.0/10 · CPI 533 · second in his category
     *
     * The highest panel mark in the cycle and its second-largest tally, absent from "the
     * best of the cycle" because of who else happened to enter his category. Meanwhile the
     * list it did produce was the category winners in CPI order — which the per-category
     * tables already are, so it added nothing and excluded the one thing it could have
     * said.
     *
     * A category may hold more than one place here, and that is the point: being narrowly
     * beaten in a deep field says more about a nominee than winning a field of one. Each
     * row carries `won_category`, so a screen can distinguish the two rather than reading
     * as a second set of category results that disagrees with the first.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHAT IT STILL WILL NOT DO
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Rank a nominee who is not `in_running`. Below the judge quorum there is no judge
     * half — not withheld, ZERO — so the figure is a community-only score sitting in the
     * same column as a full CPI. Ranking those together is what put a 691-vote nominee
     * with one of two scorecards above a finished result, and it is the one comparison
     * this method must never make.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AND THE THING THIS CANNOT FIX, WHICH IS WHY IT REPORTS IT
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A CPI is only half comparable across categories, and this list is the one place that
     * matters. The judge half is absolute — six out of ten is six out of ten in any field.
     * The community half under the default basis is a SHARE OF A NOMINEE'S OWN COHORT, so
     * leading a three-person category on fifty votes is a full community half and coming a
     * close second in a fifty-thousand-vote category is not.
     *
     * Ranking the whole field makes that bias MORE visible, not less: it puts a 19-vote
     * category leader in the same column as a 161-vote nominee who was eight per cent of a
     * large field, and the first out-scores the second. {@see CpiService::basis()} is the
     * setting that closes it, defaulted off because results are published and printed.
     *
     * There is no neutral denominator available. Normalising across the whole cycle just
     * inverts the bias — the award goes to whoever stands in the most popular category and
     * a niche field could never win it. Ranking on the judge half alone throws away the
     * community half entirely, on a platform whose thesis is that both count. Every option
     * here is a position, not a calculation.
     *
     * So this uses the same CPI and the same comparator — no second score invented,
     * nothing recomputed — and hands back the figures that make the bias visible rather
     * than leaving it to be found during a challenge: how big each contender's field was,
     * and how many votes their cohort's leader had. An operator who can see that the top
     * CPI came out of a three-person category can decide what to do about it. One who
     * cannot see it will publish it and find out afterwards.
     *
     * `field` is the number of nominees who were in the running in that category.
     *
     * @return array{
     *   winner: ?array<string,mixed>, runner_up: ?array<string,mixed>,
     *   contenders: list<array<string,mixed>>, margin: ?int, dead_heat: bool,
     *   thinnest_field: ?int
     * }
     */
    public static function overall(int $cycleId, ?array $categories = null): array
    {
        $none = ['winner' => null, 'runner_up' => null, 'contenders' => [],
                 'margin' => null, 'dead_heat' => false, 'thinnest_field' => null];

        // Reuses the cycle the caller already drew where there is one. `forCycle()` scores
        // every category, and a release screen computing this after rendering would run
        // the whole cycle twice.
        $cats = $categories ?? self::forCycle($cycleId);

        // ── EVERY NOMINEE IN THE CYCLE, NOT ONE PER CATEGORY ─────────────────
        //
        // This used to take each category's WINNER and rank those. On a real cycle that
        // produced an overall second place of 89 votes and a third of 19, while a nominee
        // on 1,536 votes with the highest panel mark in the cycle — second in the deepest
        // category — did not appear at all. "The best of the cycle" that excludes its
        // second-strongest nominee for the accident of who else entered their category is
        // not the best of the cycle; it is a parade of category winners, which the
        // per-category tables already are.
        //
        // A category can therefore hold more than one place here, and that is the point:
        // being narrowly beaten in a deep field says more about a nominee than winning a
        // field of one.
        //
        // `in_running` ONLY. A nominee below the judge quorum has no judge half at all —
        // not withheld, zero — so their figure is a community-only score wearing the same
        // column as a full CPI. Ranking the two together is the one comparison this file
        // must never make: it put a 691-vote nominee with one of two scorecards into a
        // list of finished results.
        $contenders = [];
        foreach ($cats as $c) {
            $field = 0;
            foreach ($c['rows'] as $r) if ($r['in_running']) $field++;

            foreach ($c['rows'] as $r) {
                if (!$r['in_running']) continue;

                $contenders[] = $r + [
                    'category'   => (string) ($c['category']->title ?? ''),
                    'category_id' => (int) ($c['category']->id ?? 0),
                    // The two figures that say whether this CPI is comparable with the one
                    // below it. Carried rather than derivable: the screen must not be the
                    // second place this is worked out.
                    'field'      => $field,
                    'cohort_max' => (int) ($c['cohort_max'] ?? 0),
                    // Whether they also took their own category. A list that mixes winners
                    // and runners-up has to say which is which, or it reads as a second
                    // set of category results that disagrees with the first.
                    'won_category' => ($c['winner']['nominee_id'] ?? null) === $r['nominee_id'],
                ];
            }
        }

        if ($contenders === []) return $none;

        // THE SAME COMPARATOR the categories were decided with. A second expression here
        // would let the overall award disagree with the awards it is drawn from, and the
        // disagreement would appear at the one moment nobody can afford it.
        usort($contenders, self::order(...));

        $winner   = $contenders[0];
        $runnerUp = $contenders[1] ?? null;

        $thinnest = null;
        foreach ($contenders as $c) {
            $thinnest = $thinnest === null ? $c['field'] : min($thinnest, $c['field']);
        }

        return [
            'winner'     => $winner,
            'runner_up'  => $runnerUp,
            'contenders' => $contenders,
            'margin'     => $runnerUp !== null ? $winner['cpi'] - $runnerUp['cpi'] : null,
            // The comparator falls back to the lower nominee id, which is deterministic and
            // is not a result. Named here for the same reason it is named per category:
            // this one needs a human, and a silent tiebreak is how it stops needing one.
            'dead_heat'  => $runnerUp !== null
                            && $winner['cpi'] === $runnerUp['cpi']
                            && $winner['votes'] === $runnerUp['votes'],
            'thinnest_field' => $thinnest,
        ];
    }
}
