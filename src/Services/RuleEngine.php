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
        'max_paid_weight_pct' => 50,   // bonus-vote ceiling, as % of a nominee's ORGANIC votes
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
