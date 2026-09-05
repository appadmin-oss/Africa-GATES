<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Resolves the EFFECTIVE ruleset for a (programme, cycle), layering DB overrides
 * (gates_rule_sets) over code defaults: global → programme → cycle, last wins.
 *
 * This is what turns hardcoded policy (CPI weights, tiers, fraud thresholds) into
 * per-cycle configuration. Callers that pass no scope, or run before any override
 * exists, get exactly the historical defaults — so behaviour is unchanged until a
 * rule is deliberately set.
 */
class RuleEngine
{
    /** Code defaults — the single source of truth when no override exists. */
    public const DEFAULTS = [
        'community_weight' => 0.45,
        'judge_weight'     => 0.55,
        'fraud_block'      => 80,
        'fraud_flag'       => 60,
        'fraud_monitor'    => 30,
        'max_paid_weight_pct' => 50,   // bonus-vote ceiling, as % of a nominee's NON-BONUS votes
        'min_judges_per_nominee' => 2, // COMPLETE judge scorecards required to be winner-eligible

        // ── How steep the index is ───────────────────────────────────────────
        //
        // Both halves were linear and the index did not discriminate: on a real
        // four-nominee category, last place — six per cent of the leader's votes, a
        // 7.6 panel mark — scored 414 of 1000, which is `gold` on the published ladder.
        //
        // `community_curve` is the exponent on a nominee's share of the category leader.
        // 1.0 is the old linear behaviour; 2.0 means half the leader's support is worth a
        // quarter of the weight.
        //
        // `judge_floor` is the mark below which the judge half is worth nothing — a
        // statement about the scale rather than a pass mark. Panels do not award below
        // about five, so treating 0–5 as live range handed every judged nominee a third of
        // the judge weight for free. `judge_curve` is the exponent above it.
        //
        // Settings rather than constants because the right steepness is a judgement about
        // this award, and the operator who has to defend a number to a nominee should own
        // it. See CpiService::nomineeScore() for the arithmetic and the worked example.
        'community_curve' => 2.0,
        // The category-leader vote count at which the community half pays in full. Below
        // it the whole category's community weight is discounted, because the half was
        // purely relative and paid the leader of a category with 89 votes exactly what it
        // paid the leader of one with 1,955. Set to 1 for the old behaviour.
        'community_full_credit_votes' => 1000,
        // WHAT THE COMMUNITY HALF IS A SHARE OF: 'relative' (a nominee's share of their
        // own category's leader) or 'absolute' (their own turnout against the mark above).
        //
        // Relative is what decides a category; absolute is what can be compared across
        // them. Under relative, a 19-vote category LEADER out-scored a 691-vote nominee
        // who was 35% of a big field — both figures correct, neither comparable to the
        // other, and the cross-category overall winner is drawn from exactly that
        // comparison.
        //
        // DEFAULTED TO RELATIVE, and that is not indecision. Results on this platform are
        // published and printed onto physical awards; a cycle that has announced its
        // standings must keep them to the digit. Per-cycle, so a later cycle opts in
        // without moving a released one. See CpiService::basis().
        'community_basis' => CpiService::BASIS_RELATIVE,
        'judge_floor'     => 5.0,
        'judge_curve'     => 1.5,

        // ── Community return ─────────────────────────────────────────────────
        //
        // A nominee's share of what supporters contributed in their name, in basis
        // points (5000 = 50%). Editable per cycle from Settings → Community return;
        // this is only what applies when nobody has set one.
        //
        // NOTE FOR ANYONE CHANGING THIS: an override row in gates_rule_sets BEATS
        // this value. On an installation where somebody has already saved the
        // Community return card, editing the constant changes nothing — the card
        // has to be saved again. `/integrity` publishes whichever one is in force,
        // so the page is the way to check which happened.
        'community_return_bps' => 5000,

        // Qualification: how much QUALIFYING SUPPORT, counted in votes, a nominee
        // must gather before they begin earning.
        'community_return_vote_threshold' => 250,

        // …and the reason a threshold in votes is not a formality. NO SINGLE
        // SUPPORTER MAY SUPPLY MORE THAN THIS PERCENTAGE OF IT. At 10, one person's
        // votes count toward qualification only up to a tenth of the threshold, no
        // matter how many they bought — so crossing the line needs at least ten
        // different verified people and cannot be arranged by one person with a card.
        //
        // Within that ceiling, paying more still counts for more: somebody who bought
        // twenty-five votes carries twenty-five of them, not one. That is the point of
        // capping rather than counting heads — generosity is rewarded, concentration
        // is not.
        'community_return_supporter_cap_pct' => 10,
    ];

    /** @return array<string,mixed> merged ruleset */
    public function effective(?int $programmeId = null, ?int $cycleId = null): array
    {
        $rules = self::DEFAULTS;
        foreach ($this->layers($programmeId, $cycleId) as $override) {
            $rules = array_merge($rules, $override);
        }
        return $rules;
    }

    /** Community/judge split for a scope (normalised so the two sum to 1). */
    public function weights(?int $programmeId = null, ?int $cycleId = null): array
    {
        $r = $this->effective($programmeId, $cycleId);
        $cw = (float) ($r['community_weight'] ?? self::DEFAULTS['community_weight']);
        $jw = (float) ($r['judge_weight'] ?? self::DEFAULTS['judge_weight']);
        $sum = $cw + $jw;
        if ($sum <= 0) { return ['community' => 0.45, 'judge' => 0.55]; }
        return ['community' => $cw / $sum, 'judge' => $jw / $sum];
    }

    /** Persist (upsert) an override for a scope. */
    public function set(string $scope, ?int $scopeId, array $rules): void
    {
        DB::table('gates_rule_sets')->updateOrInsert(
            ['scope' => $scope, 'scope_id' => $scopeId],
            ['rules' => json_encode($rules), 'updated_at' => \Illuminate\Support\Carbon::now()->toDateTimeString()]
        );
    }

    /** Override layers in precedence order (global first, cycle last). */
    private function layers(?int $programmeId, ?int $cycleId): array
    {
        try {
            $q = DB::table('gates_rule_sets')->where(function ($w) use ($programmeId, $cycleId) {
                $w->where('scope', 'global');
                if ($programmeId !== null) $w->orWhere(fn ($x) => $x->where('scope', 'programme')->where('scope_id', $programmeId));
                if ($cycleId !== null)     $w->orWhere(fn ($x) => $x->where('scope', 'cycle')->where('scope_id', $cycleId));
            })->get();
        } catch (\Throwable $e) {
            return []; // table absent (pre-migration) → defaults only
        }

        $rank = ['global' => 0, 'programme' => 1, 'cycle' => 2];
        $rows = $q->sort(fn ($a, $b) => ($rank[$a->scope] ?? 0) <=> ($rank[$b->scope] ?? 0))->values();

        $out = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->rules, true);
            if (is_array($decoded)) $out[] = $decoded;
        }
        return $out;
    }
}
