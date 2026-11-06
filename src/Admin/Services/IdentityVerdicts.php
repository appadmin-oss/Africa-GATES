<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\MergeSuggestionService;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Rulings on whether two records are one person (`2027_03_28_identity_resolution.php`).
 *
 * One row per pair. Two sources, and an order between them that never inverts: a PERSON's
 * ruling replaces the AI's and is never replaced by it. The AI is asked only about pairs
 * nobody has ruled on, its answer is kept so it is asked once, and it can be overruled in
 * one press — it advises the count; it never decides a merge.
 *
 * "Not the same person" on two nominees in ONE category is also written to
 * gates_merge_dismissals, which the merge scan has always read, so a ruling made on either
 * screen holds on both.
 */
final class IdentityVerdicts
{
    public const SAME = 'same';
    public const DIFFERENT = 'different';

    public static function ready(): bool
    {
        return SchemaHas::table('gates_identity_verdicts');
    }

    /**
     * Every ruling touching these ids, by pair key, with merge-scan dismissals read as a
     * person's "different".
     *
     * @param list<int> $ids
     * @return array<string, array{verdict:string, source:string, confidence:?float, reason:?string}>
     */
    public static function forIds(string $kind, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) < 2) return [];
        $out = [];
        try {
            if (self::ready()) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    foreach (DB::table('gates_identity_verdicts')->where('kind', $kind)
                                 ->where(static fn ($q) => $q->whereIn('a_id', $chunk)->orWhereIn('b_id', $chunk))
                                 ->get(['pair_key', 'verdict', 'source', 'confidence', 'reason']) as $r) {
                        $out[(string) $r->pair_key] = ['verdict' => (string) $r->verdict, 'source' => (string) $r->source,
                            'confidence' => $r->confidence !== null ? (float) $r->confidence : null, 'reason' => $r->reason];
                    }
                }
            }
            if ($kind === 'nominee' && SchemaHas::table('gates_merge_dismissals')) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    foreach (DB::table('gates_merge_dismissals')
                                 ->where(static fn ($q) => $q->whereIn('nominee_a', $chunk)->orWhereIn('nominee_b', $chunk))
                                 ->get(['pair_key', 'reason']) as $r) {
                        $out[(string) $r->pair_key] = ['verdict' => self::DIFFERENT, 'source' => 'human', 'confidence' => null, 'reason' => $r->reason];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[identity] read: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Record a ruling on every pair among $ids. A person's ruling always lands; the AI's
     * lands only where no person has ruled. Returns how many pairs were written.
     *
     * @param list<int> $ids
     */
    public static function record(string $kind, array $ids, string $verdict, string $source = 'human',
                                  ?float $confidence = null, string $reason = '', ?int $adminId = null): int
    {
        if (!in_array($verdict, [self::SAME, self::DIFFERENT], true) || !self::ready()) return 0;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        sort($ids);
        $n = 0;
        $now = date('Y-m-d H:i:s');
        for ($x = 0; $x < count($ids); $x++) {
            for ($y = $x + 1; $y < count($ids); $y++) {
                $key = MergeSuggestionService::pairKey($ids[$x], $ids[$y]);
                try {
                    $prior = DB::table('gates_identity_verdicts')->where('kind', $kind)->where('pair_key', $key)->first(['source']);
                    if ($prior && $source === 'ai' && (string) $prior->source === 'human') continue;
                    $row = ['a_id' => $ids[$x], 'b_id' => $ids[$y], 'verdict' => $verdict, 'source' => $source,
                            'confidence' => $confidence !== null ? round(max(0.0, min(1.0, $confidence)), 3) : null,
                            'reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
                            'decided_by' => $adminId && $adminId > 0 ? $adminId : null, 'created_at' => $now];
                    $prior
                        ? DB::table('gates_identity_verdicts')->where('kind', $kind)->where('pair_key', $key)->update($row)
                        : DB::table('gates_identity_verdicts')->insert($row + ['kind' => $kind, 'pair_key' => $key]);
                    $n++;
                } catch (\Throwable $e) {
                    error_log('[identity] write: ' . $e->getMessage());
                }
            }
        }
        return $n;
    }

    /** Withdraw a person's ruling, so the pair is decided by the rules (and the AI) again. */
    public static function forget(string $kind, int $a, int $b): bool
    {
        if (!self::ready()) return false;
        try {
            return DB::table('gates_identity_verdicts')->where('kind', $kind)
                ->where('pair_key', MergeSuggestionService::pairKey($a, $b))->delete() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
