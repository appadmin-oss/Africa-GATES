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
     * Per-call cache of resolved edition scales, keyed 'cycle:<id>'.
     *
     * The scale reads every category in the cycle, so a fresh one per category would make
     * drawing a release screen quadratic in the size of the edition — and every caller that
     * scores more than one category already shares a scorer instance for exactly this
     * reason ({@see ResultRelease::forCycle()}).
     */
    private array $scaleByEdition = [];

    /**
     * Per-nominee scores for one category: the community half normalised against the whole
     * EDITION's field ({@see editionScale()}), the judge half from the panel, and the
     * effective per-cycle CPI weights from the rule engine.
     *
     * @return array<int, array{vote_count:int, unique_voters:int, cohort_max:int,
     *   cohort_max_unique:int, cohort_max_by:?array{id:int,name:string,category_id:int,category_title:string},
     *   cohort_max_unique_by:?array{id:int,name:string,category_id:int,category_title:string},
     *   cohort_scope:string,
     *   reach_unmeasured:bool, judge_score:float|null, judges:int, eligible:bool,
     *   provisional:bool, cpi_score:int, community_points:int, judge_points:int}>
     */
    public function scoreCategory(int $categoryId): array
    {
        $nominees = $this->scoredIn($categoryId);
        if ($nominees === []) return [];

        // Effective CPI weights for this category's cycle (config over defaults).
        // ── THE CYCLE COMES FROM THE CATEGORY, NOT FROM THE JOIN ─────────────
        //
        // A LEFT join, and `c.cycle_id` rather than `cy.id`, because the edition is now the
        // scale every community half is measured against and a missing `gates_award_cycles`
        // row must not silently collapse it back to this one category. That row is absent
        // more often than it looks: an import that carried categories and nominees, a
        // fixture, a cycle deleted after release. The category has always known which
        // edition it belongs to — the join was only ever there for the programme id.
        $ctx = DB::table('gates_award_categories as c')
            ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->where('c.id', $categoryId)
            ->select('c.cycle_id as cycle_id', 'cy.programme_id')->first();
        $w = $this->rules->weights($ctx->programme_id ?? null, $ctx->cycle_id ?? null);
        // How steep the index is. Defaults live in RuleEngine::DEFAULTS with the reasoning;
        // an operator tunes them per programme or per cycle.
        $eff    = $this->rules->effective($ctx->programme_id ?? null, $ctx->cycle_id ?? null);
        $cCurve = (float) ($eff['community_curve'] ?? RuleEngine::DEFAULTS['community_curve']);
        $jFloor = (float) ($eff['judge_floor']     ?? RuleEngine::DEFAULTS['judge_floor']);
        $jCurve = (float) ($eff['judge_curve']     ?? RuleEngine::DEFAULTS['judge_curve']);
        $jScale = CpiService::judgeScale($eff['judge_scale'] ?? null);
        // Normalised through CpiService rather than compared here: an unrecognised value
        // has to fall back to today's behaviour, and a template or a settings form is one
        // typo away from writing one. A silent switch of scoring basis is the worst
        // possible thing for a stray string to do.
        $cBasis = CpiService::basis($eff['community_basis'] ?? null);
        // And the same for the denominator's SCOPE. Normalised for the same reason, and
        // read from the same effective ruleset as the basis so a cycle-level override
        // cannot move one and not the other while both are reported as this cycle's rules.
        $cScope = CpiService::scope($eff['community_scope'] ?? null);
        $full   = (int)   ($eff['community_full_credit_votes']
                           ?? RuleEngine::DEFAULTS['community_full_credit_votes']);

        // ══ EVERY VOTE COUNTS, WHATEVER IT COST ══════════════════════════════
        //
        // The community half reads `vote_count` — the full tally: free votes cast by a
        // verified person, votes bought in a pack, and votes awarded as a bonus against a
        // contribution, added together. It is a deliberate, operator-made change of
        // methodology, not a drift.
        //
        // It used to read `organic_vote_count` alone, and the comment here said purchased
        // votes "must never move the cohort max or any nominee's community share, or money
        // could buy rank." That is now exactly what they do, and every published surface
        // that promised otherwise has been rewritten in the same change rather than left
        // to contradict this line. A platform whose integrity page describes a rule its
        // scorer does not follow is in a worse position than one that never made the
        // promise.
        //
        // WHAT THAT MEANS, STATED PLAINLY SO NOBODY HAS TO INFER IT: purchases are not
        // capped against a nominee's genuine support — `PaidVoteService` limits the size
        // of ONE order, not how many orders a campaign places — so a sufficiently funded
        // nominee can take a category on spending alone. That consequence was put to the
        // operator with the alternatives and this is the option they chose.
        //
        // `organic_vote_count` is still maintained, still returned, and still shown beside
        // the total on every public surface, so a reader can always see how much of a
        // tally was bought. It simply no longer decides anything.
        //
        // ══ THE DENOMINATOR IS THE WHOLE EDITION, NOT THE CATEGORY ═══════════
        //
        // Both community terms are shares of a maximum, and the maximum is now the largest
        // held by ANY nominee in this cycle of this programme — not the largest in this
        // category. {@see editionScale()} is where it is worked out, once per edition, and
        // the reasoning for the change is written there rather than repeated here.
        //
        // The short version, because it decides every number below: a per-category
        // denominator normalises each category to its own leader, so leading a field is
        // worth the full community half however small that field was — 89 votes and 1,955
        // voting identically — and the overall award is then decided between figures that
        // are not comparable. Per edition, a share means the same thing in every category,
        // and the overall ranking is an addition of like with like.
        //
        // ── WHAT IS IN THE SCALE, AND WHY IT IS NOT SIMPLY EVERY NOMINEE ─────
        //
        // The FIELD of each category — the published shortlist where there is one, every
        // scored nominee where there is not. Unchanged in kind from what this used to do
        // per category, and for the same reason: a nominee who cannot win must not decide
        // what the people who can are worth. Widening the scale to the whole entry list
        // would let a popular non-finalist in ANOTHER category hold down a finalist here,
        // which is the original fault with a longer reach.
        //
        // Deliberately NOT narrowed by the judge quorum, for the reason set out below.
        // Resolved before the scale, which needs it to say whether the nominee holding the
        // denominator has been judged to quorum — see editionScale().
        $quorum = (int) ($eff['min_judges_per_nominee']
                         ?? RuleEngine::DEFAULTS['min_judges_per_nominee']);

        $scale = $this->editionScale((int) ($ctx->cycle_id ?? 0), $categoryId, $cScope, $quorum);

        // ── AND WHY THE QUORUM IS NOT APPLIED TO IT ──────────────────────────
        //
        // Being below quorum is PENDING, not out: a panel may still finish. Shrinking the
        // denominator for it would move every published score in the edition each time a
        // scorecard was completed — more movement, not less — and it runs perversely: an
        // unjudged popular nominee would be dropped from the scale, inflating everybody's
        // community share, and then rejoin it when they were judged and take it all back.
        // It would also let a judging fact decide a voting denominator, which is not
        // something anybody could explain to a nominee who lost by it.
        //
        // A published shortlist is the opposite: an explicit, dated, final decision that
        // these are the people in contention. That is a scale worth measuring against.

        // The denominator moves with the numerator. Scaling a total against an organic
        // maximum would let a nominee exceed 100% of the cohort and hand them more than
        // the whole community weight — the two have to be the same measure or the share
        // is not a share.
        $cohortMax = max(1, (int) $scale['max_votes']);

        // NOT floored to one. Zero is a meaningful answer here — "no vote rows anywhere in
        // this edition to count people from" — and CpiService::reachPart() needs to be able
        // to tell it apart from "everybody has nobody". Flooring it here would erase that
        // distinction before the scorer ever saw it.
        $cohortMaxUnique = (int) $scale['max_unique'];

        // Counted from the vote rows themselves rather than read off a column — see
        // {@see VoterReach} for why a stored counter and a DISTINCT count are both wrong
        // here, and why one buyer's ten orders are one person. `rows` travels with
        // `people` so a tally with nothing behind it can be told from a tally that belongs
        // to nobody; see `reach_unmeasured` below.
        $reach = VoterReach::detailFor(array_map(
            static fn (object $n): int => (int) $n->id, $nominees));
        $stats = $this->judgeStatsFor(array_map(
            static fn (object $n): int => (int) $n->id, $nominees));

        $out = [];
        foreach ($nominees as $n) {
            $st       = $stats[(int) $n->id] ?? null;
            $ja       = $st['avg'] ?? null;
            $judges   = $st['judges'] ?? 0;
            $eligible = $judges >= $quorum;                        // winner-eligible only at quorum

            $d      = $reach[(int) $n->id] ?? ['people' => 0, 'rows' => 0];
            $unique = (int) $d['people'];

            // ── A TALLY WITH NOTHING BEHIND IT IS NOT A REACH OF ZERO ────────
            //
            // The scale is the whole edition now, so `cohortMaxUnique` stays above zero as
            // long as ONE category anywhere in the cycle has vote rows — and
            // CpiService::reachPart()'s fallback, which used to catch a rowless category
            // whole, no longer fires for it. Every nominee in that category would be scored
            // at people = 0 and lose seventy per cent of the community half for a
            // data-migration reason, with nothing on any screen to say so.
            //
            // This is the flag that stops it being silent. It is raised for a nominee whose
            // tally says there is support while `gates_votes` holds not one row for them:
            // an import from before this platform kept rows, a restore, a seeded fixture.
            // Zero rows and zero votes is not it — that nominee has no support and no
            // reach, which is a measurement. Rows that all belong to nobody is not it
            // either — every vote was an operator's grant, and zero reach is the intended
            // answer there ({@see VoterReach::detailFor()}).
            //
            // The nominee is still scored at zero people, deliberately, which is the same
            // choice the judge half makes for a panel that has not finished: understate
            // rather than overstate, and flag it. Paying the whole community half on the
            // tally instead would hand an unbacked number the edition — exactly the thing
            // the edition-wide scale exists to prevent — and it would do it to the benefit
            // of the one nominee nobody can check.
            $unmeasured = $cohortMaxUnique > 0
                          && (int) $d['rows'] === 0
                          && (int) $n->vote_count > 0;

            $split = CpiService::split(
                CpiService::communityPart((int) $n->vote_count, $cohortMax, $cCurve, $full,
                                          $cBasis, $unique, $cohortMaxUnique),
                CpiService::judgePart($eligible ? $ja : null, $jFloor, $jCurve, $jScale),
                $w['community'], $w['judge']);
            $out[(int) $n->id] = [
                'vote_count'  => (int) $n->vote_count,            // total display support
                // ── AND THE OTHER HALF OF THE WORKING ────────────────────────
                //
                // Published for the same reason `cohort_max` is: under the reach basis
                // seventy per cent of the community half is decided by these two numbers,
                // and a figure that appears on no screen is a figure nobody can check. A
                // nominee is owed both terms of their own score.
                'unique_voters'     => $unique,
                'cohort_max_unique' => $cohortMaxUnique,
                // TRUE where this nominee's support could not be counted in people at all
                // — see the note above the flag. An operator has to be able to find these,
                // because the fix is a data one and nothing else on the screen looks wrong.
                'reach_unmeasured'  => $unmeasured,
                // ── THE DENOMINATOR THE COMMUNITY HALF IS MEASURED AGAINST ───
                //
                // Returned rather than kept local, because without it NOBODY can check a
                // CPI. The community component is `votes / cohortMax`, so 2,650 votes
                // is worth 55% of the community weight or 26% of it depending entirely on
                // a number that appeared on no screen — and `ResultRelease` recomputing it
                // would be a second reader of the one fact that decides the award.
                //
                // The cohort is the whole EDITION's field: the published shortlist of each
                // category in this cycle where there is one, every scored nominee where
                // there is not. It is deliberately not narrowed by the quorum — below
                // quorum is pending, not out, and see the note above for why letting a
                // judging fact move a voting denominator is worse than the thing it would
                // fix.
                'cohort_max'  => $cohortMax,
                // ── AND WHO HOLDS IT, BECAUSE THEY ARE USUALLY SOMEBODY ELSE ─
                //
                // The scale-setter used to be findable on the screen: the denominator was
                // this category's own maximum, so a row on the page held it. Edition-wide
                // they are most often in ANOTHER category, and a release screen that
                // scanned its own rows for the number would simply fail to find it and
                // report the scale as nobody's. Named here, once, by the same pass that
                // computed the number.
                'cohort_max_by'        => $scale['votes_by'],
                // Whether the yardstick's holder is still in the running in their own
                // category. See `editionScale()` — distinct from the quorum warning.
                'cohort_max_by_listed' => (bool) ($scale['votes_by_listed'] ?? true),
                'cohort_max_unique_by' => $scale['unique_by'],
                // 'edition' normally; 'category' only where the cycle could not be
                // resolved at all, which is a broken row rather than a configuration.
                'cohort_scope'         => (string) $scale['scope'],
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
                // Measured, with a cohort max of 100 votes:
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
                // The FULL tally, and the same figure `cohort_max` is drawn from.
                // The curve settings travel with the weights. Read once above from the same
                // effective ruleset, so a cycle-level override cannot move one and not the
                // other while both are reported as this category's rules.
                //
                // ── AND THE TWO HALVES COME FROM HERE, NOT FROM THE SCREEN ───
                //
                // `ResultRelease` used to work the community half out again from its own
                // copy of `weight × share × 1000` and take the judge half as what was left.
                // That agreed with this line for exactly as long as both were linear; the
                // moment a curve went on the share, the release screen published a linear
                // community half beside a curved index and the judge half silently
                // absorbed the difference — two nominees on an identical 7.6 panel mark
                // were shown 66 and 112. Nothing outside CpiService computes a part of a
                // CPI now.
                'cpi_score'        => $split['cpi'],
                'community_points' => $split['community'],
                'judge_points'     => $split['judge'],
            ];
        }
        return $out;
    }

    /**
     * THE SCALE EVERY NOMINEE IN ONE EDITION IS MEASURED AGAINST.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY IT IS THE EDITION AND NOT THE CATEGORY
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Both terms of the community half are shares of a maximum, and that maximum used to be
     * the biggest number in the nominee's OWN category. So every category was normalised to
     * its own leader and every category's leader collected the whole community half — which
     * is the right answer to "who won this category" and a wrong answer to anything else.
     *
     * Two real rows from one released cycle, side by side, both correct under the old rule:
     *
     *     Leader of Academic Excellence   1,955 votes   community 450
     *     Leader of a thin category          89 votes   community 450
     *
     * Identical figures for support differing by a factor of twenty-two. Inside their own
     * categories neither is wrong. Put them in one column — which {@see ResultRelease::overall()}
     * must do, because an overall standing is the whole cycle ranked — and the second one is
     * being paid for a field, not for support. The operator's word for it was "cheating",
     * and that is the right word for a number that does not move when the thing it measures
     * changes by twenty-two times.
     *
     * Per edition, 1,955 is 1.00 and 89 is 0.046, everywhere they appear, and a CPI carries
     * the same meaning in every category of the cycle. That is the whole change.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THE EDITION AND NOT THE PROGRAMME'S WHOLE HISTORY
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A programme spans years; a cycle is one running of it, and it belongs to exactly one
     * programme. Taking the maximum across every cycle a programme has ever held would do
     * two things that cannot be defended:
     *
     *   · IT WOULD MOVE PUBLISHED RESULTS. 2024's standings were announced against 2024's
     *     numbers. A big tally arriving in 2026 would re-scale 2024 the next time anything
     *     recomputed it, and a nominee's published score would change years after the fact.
     *   · IT WOULD COMPARE DIFFERENT ELECTORATES. A cycle with ten thousand voters and a
     *     cycle with two hundred are not one scale; ranking the second against the first
     *     measures how much the platform grew, not who was backed.
     *
     * The overall award is decided per cycle ({@see ResultRelease::overall()}), so the cycle
     * is the widest scale on which every number being compared was collected under the same
     * conditions. That is the scale.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHAT IT COSTS, MEASURED, BECAUSE IT IS NOT FREE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * In a category whose whole field is small against the edition, every community half is
     * small — so the differences between its nominees are small too, and the 550 the panel
     * carries decides that category almost on its own. On the fixture in
     * `OverallWholeFieldTest`: the thin category's leader falls from 890 to 460, and the gap
     * to the nominee below them narrows to a few points of community credit.
     *
     * That is a real change to what winning a small category means and it is the intended
     * one: the community half measures public backing, and where there was little public
     * backing it should pay little. A category with genuine depth is unaffected — it is
     * measured against the edition's best, which is what "unaffected" has to mean once the
     * figures are comparable at all.
     *
     * ── AND THE ONE REASON IT IS STILL A SETTING ────────────────────────────
     *
     * `community_scope = category` puts the denominator back where it was, for the same
     * reason `community_basis` and `judge_scale` keep their older forms: results on this
     * platform are published and printed onto physical awards, and a cycle that announced
     * its standings has to be able to reproduce them to the digit. It is not offered as an
     * alternative rule — the note above says why it is not one — and reproducing an old
     * cycle means setting all three.
     *
     * @param  int    $cycleId       the edition. Zero where it cannot be resolved.
     * @param  int    $forCategoryId the fallback scale, and the whole scale under
     *                               `community_scope = category`.
     * @param  string $scope         {@see CpiService::scope()}.
     * @return array{max_votes:int, max_unique:int, scope:string,
     *               votes_by:?array{id:int,name:string,category_id:int,category_title:string,eligible:?bool},
     *               unique_by:?array{id:int,name:string,category_id:int,category_title:string,eligible:?bool}}
     */
    public function editionScale(int $cycleId, int $forCategoryId = 0,
                                 string $scope = CpiService::SCOPE_EDITION,
                                 int $quorum = 0): array
    {
        $wide = CpiService::scope($scope) === CpiService::SCOPE_EDITION && $cycleId > 0;

        $key = $wide ? 'cycle:' . $cycleId : 'category:' . $forCategoryId;
        if (isset($this->scaleByEdition[$key])) return $this->scaleByEdition[$key];

        $catIds = [];
        if ($wide) {
            try {
                $catIds = array_map('intval', DB::table('gates_award_categories')
                    ->where('cycle_id', $cycleId)->pluck('id')->all());
            } catch (\Throwable $e) {
                // A scale this cannot read must not stop a release. Falling back to the one
                // category is the old behaviour, which is wrong in the way this method
                // exists to fix and is not wrong in a way that crowns nobody.
                error_log('[scoring] could not list the edition: ' . $e->getMessage());
            }
        }
        if ($catIds === []) $catIds = array_values(array_filter([$forCategoryId]));

        // ── THE DENOMINATOR IS THE EDITION'S, AND THAT MEANS EVERY SCORED ENTRY ──
        //
        // The specified rule is "Highest Total Votes in award programme edition", twice —
        // once for each term. This used to narrow to each category's published SHORTLIST,
        // and the narrowing is what produced the fault reported against a live cycle: a
        // finalist on 500 votes from 500 supporters took the whole 450 while a
        // non-finalist in the same edition sat on 2,000. Every figure on the row was
        // internally consistent, and a full community half sat beside a backer count
        // plainly smaller than the biggest tally anybody could see.
        //
        // The narrowing was inherited from `relative`, where the denominator was the
        // nominee's OWN category leader and a huge non-finalist could compress three
        // finalists to four points apart on a thousand-point index. That argument does not
        // survive the move to an edition-wide scale: narrowing to the shortlist
        // reintroduces the exact fault the edition-wide scale exists to kill, one level
        // down — every shortlisted leader collects a full 450, just as every CATEGORY
        // leader used to.
        //
        // The compression it was defending against is real and is accepted. Under `ideal`
        // one denominator cancels out of every comparison, so the ORDER never depends on
        // it; what changes is how much community credit an edition hands out in total, and
        // an edition with one runaway tally handing out less of it is the honest answer
        // rather than a flattering one. `cohort_outside_max` is retired with the narrowing
        // that made it necessary.
        //
        // `scoredIn()` is the same definition the scorer uses: approved, not withdrawn,
        // not a merge tombstone. A nominee who does not score cannot set a scale.
        /** @var array<int,object> $field every nominee whose votes set the scale */
        $field = [];
        foreach ($catIds as $cid) {
            foreach ($this->scoredIn($cid) as $n) $field[(int) $n->id] = $n;
        }

        $maxVotes = 0; $votesBy = null;
        foreach ($field as $n) {
            $v = (int) $n->vote_count;
            // Strictly greater, so a tie leaves the FIRST holder named rather than the last.
            // Arbitrary either way; stable is what makes the screen reproducible.
            if ($v > $maxVotes) { $maxVotes = $v; $votesBy = $n; }
        }

        // ── AND WHETHER THE YARDSTICK BELONGS TO SOMEBODY WHO CANNOT WIN ─────
        //
        // Now that the scale is the whole edition, the largest tally may well be held by a
        // nominee left off their own category's published shortlist. That is the rule as
        // published — they are in the edition — but it is not obvious from any row, and an
        // operator looking at community halves that all seem low is owed the reason.
        //
        // Distinct from `scale_is_out`, which is about the judge QUORUM: below quorum is
        // pending and the denominator can still move, whereas this one is settled. Two
        // different facts, and a screen that conflated them would tell an operator to wait
        // for a panel that has nothing to do with it.
        $votesByListed = true;
        if ($votesBy !== null) {
            $listed = ResultRelease::shortlistedIn((int) $votesBy->category_id);
            $votesByListed = $listed === null
                || $listed === []
                || in_array((int) $votesBy->id, $listed, true);
        }

        $reach     = VoterReach::forNominees(array_keys($field));
        $maxUnique = 0; $uniqueBy = null;
        foreach ($field as $id => $n) {
            $u = (int) ($reach[$id] ?? 0);
            if ($u > $maxUnique) { $maxUnique = $u; $uniqueBy = $n; }
        }

        // ── AND WHETHER THE PANEL HAS FINISHED WITH THEM ─────────────────────
        //
        // A nominee below the judge quorum still sets the scale — below quorum is PENDING,
        // not out, and dropping them would move every published score the moment their
        // panel finished and then hand it all back. The release screen names that case, so
        // an operator knows the denominator can still move before they release.
        //
        // Resolved HERE and not on the drawn category, because the setter is usually in a
        // DIFFERENT category and a screen can only see its own rows. That is exactly how
        // this warning came to fire for a strictly smaller set of cases than the risk it
        // describes: edition-wide the denominator moving changes every community half in
        // the CYCLE, and the check was still asking about one category's rows.
        $standing = [];
        $ask = array_values(array_unique(array_filter([
            (int) ($votesBy->id ?? 0), (int) ($uniqueBy->id ?? 0),
        ])));
        if ($ask !== [] && $quorum > 0) {
            foreach ($this->judgeStatsFor($ask) as $nid => $st) {
                $standing[(int) $nid] = (int) ($st['judges'] ?? 0) >= $quorum;
            }
            foreach ($ask as $nid) $standing[$nid] ??= false;   // no marks at all is below it
        }

        // The scale-setter's CATEGORY, named. They are usually not in the category being
        // drawn, so "measured against 1,955 votes — Ajayi's" leaves an operator hunting
        // through the cycle for whose those are. One query for the two of them.
        $titles = [];
        $need = array_values(array_unique(array_filter([
            (int) ($votesBy->category_id ?? 0), (int) ($uniqueBy->category_id ?? 0),
        ])));
        if ($need !== []) {
            try {
                foreach (DB::table('gates_award_categories')->whereIn('id', $need)
                            ->get(['id', 'title']) as $c) {
                    $titles[(int) $c->id] = (string) ($c->title ?? '');
                }
            } catch (\Throwable) {
                // A missing title costs a sentence on one screen. It must not cost a score.
            }
        }

        $who = static fn (?object $n): ?array => $n === null ? null : [
            'id'             => (int) $n->id,
            'name'           => (string) ($n->name ?? ''),
            'category_id'    => (int) ($n->category_id ?? 0),
            'category_title' => $titles[(int) ($n->category_id ?? 0)] ?? '',
            // NULL where it was not asked — a caller with no quorum to hand over gets
            // "unknown" rather than a confident `false`, because a screen that says the
            // panel has not finished when nobody looked is worse than one that says
            // nothing.
            'eligible'       => $standing[(int) $n->id] ?? null,
        ];

        return $this->scaleByEdition[$key] = [
            'max_votes'  => $maxVotes,
            'max_unique' => $maxUnique,
            'votes_by'   => $who($votesBy),
            'unique_by'  => $who($uniqueBy),
            // False when the yardstick's holder is off their own category's published
            // shortlist: they set the scale and cannot win. True where there is no
            // shortlist, which is the case that changes nothing.
            'votes_by_listed' => $votesByListed,
            // The scope in force, which is 'category' both where an operator asked for it
            // and where the cycle could not be resolved at all. The second is a broken row
            // rather than a configuration, and the left join above is what makes it rare.
            'scope'      => $wide ? CpiService::SCOPE_EDITION : CpiService::SCOPE_CATEGORY,
        ];
    }

    /**
     * Every nominee in a category whose score counts. One definition, because the scorer
     * and the scale both have to mean the same thing by it: a merge tombstone never scores,
     * and a withdrawn or rejected entry is not in the cycle.
     *
     * @return list<object>
     */
    private function scoredIn(int $categoryId): array
    {
        $q = DB::table('gates_nominees')->where('category_id', $categoryId)
            ->whereIn('status', ['approved', 'winner', 'runner_up']);
        MergeService::notMerged($q);                       // merge tombstones never score

        return $q->get()->all();
    }

    // ── `fieldIn()` IS GONE, AND THE NARROWING WITH IT ───────────────────────
    //
    // It returned each category's published shortlist and was the ONLY caller-side
    // narrowing of the scale. Its argument was inherited from `relative`, where the
    // denominator was the nominee's own category leader: a 5,000-vote non-finalist could
    // compress three finalists on 500, 400 and 300 to four points apart on a
    // thousand-point index, and the panel then decided the final alone.
    //
    // Under an edition-wide `ideal` that argument inverts. Narrowing to the shortlist
    // reintroduces the very fault the edition-wide scale exists to kill, one level down:
    // every shortlisted leader takes a full 450 exactly as every CATEGORY leader used to.
    // And it contradicts the rule as specified — "Highest Total Votes in award programme
    // edition" — which is what a nominee is told their score means.
    //
    // Named here rather than deleted silently, because a future reader looking for the
    // shortlist in the scale will find this instead of re-deriving it.

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
