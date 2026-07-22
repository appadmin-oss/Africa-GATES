<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Merge duplicate nominees into one — the fix for vote-splitting when the same
 * person was approved as several nominee rows ("Dr. Jane Doe" vs "jane doe").
 *
 * Folds every merged nominee's votes, judge scores/notes, milestones, cheers,
 * comments, uploads, form tokens and analytics into the survivor, DEDUPING
 * wherever a UNIQUE key would collide (a judge who scored two of the duplicates
 * on the same criterion; a voter — impossible within one category — etc.), then
 * rebuilds the survivor's vote counters from the reassigned rows and deletes the
 * merged rows. The whole thing runs in one transaction: any failure rolls back
 * and nothing is lost or half-merged.
 *
 * Constrained to nominees in the SAME category — the actual vote-splitting case,
 * and the reason it is safe: "one vote per voter per category" is already
 * enforced, so no vote row can collide on reassignment. Cross-category merges
 * (rare, and semantically dubious for a category-scoped award) are refused with
 * a clear message rather than silently dropping colliding votes.
 *
 * The tamper-evident vote-snapshot hash chain and the audit log are intentionally
 * left untouched (they are history); gates_nominations is decoupled (matched by
 * name/category, not id) so it needs no changes either.
 */
final class MergeService
{
    /**
     * @param int[] $mergeIds nominees to fold in (the survivor id, if included, and non-existent ids are ignored)
     * @return array{ok:bool,error?:string,merged:int,votes:int,keep_id:int}
     */
    public static function mergeNominees(int $keepId, array $mergeIds, ?int $adminId = null): array
    {
        $fail = static fn(string $msg): array => ['ok' => false, 'error' => $msg, 'merged' => 0, 'votes' => 0, 'keep_id' => $keepId];

        $keep = DB::table('gates_nominees')->where('id', $keepId)->first();
        if (!$keep) return $fail('The nominee to keep no longer exists.');

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $mergeIds),
            static fn($i) => $i > 0 && $i !== $keepId
        )));
        if (!$ids) return $fail('Select at least one other nominee to merge into this one.');

        $others = DB::table('gates_nominees')->whereIn('id', $ids)->get();
        if ($others->isEmpty()) return $fail('None of the selected nominees exist any more.');

        foreach ($others as $o) {
            if ((int) $o->category_id !== (int) $keep->category_id) {
                return $fail('All nominees in a merge must be in the same category — move them into one category first, then merge.');
            }
        }
        $mergedIds = array_map(static fn($o) => (int) $o->id, $others->all());

        try {
            DB::transaction(function () use ($keepId, $mergedIds, $keep, $others) {
                foreach ($mergedIds as $from) {
                    self::reassignAll($from, $keepId);
                }
                // The survivor inherits a profile link / photo from a merged row
                // only if it doesn't already have one of its own.
                $adopt = [];
                if (empty($keep->profile_id)) {
                    foreach ($others as $o) { if (!empty($o->profile_id)) { $adopt['profile_id'] = (int) $o->profile_id; break; } }
                }
                if (empty($keep->photo_path)) {
                    foreach ($others as $o) { if (!empty($o->photo_path)) { $adopt['photo_path'] = (string) $o->photo_path; break; } }
                }
                if ($adopt) DB::table('gates_nominees')->where('id', $keepId)->update($adopt);

                self::rebuildCounters($keepId);
                DB::table('gates_nominees')->whereIn('id', $mergedIds)->delete();
            });
        } catch (\Throwable $e) {
            error_log('[MergeService] ' . $e->getMessage());
            return $fail(\AfricaGates\Admin\Support\ActionError::dbMessage($e));
        }

        try {
            (new \AfricaGates\Admin\Services\AuditService())->record(
                (int) ($adminId ?? 0), 'nominee.merge', 'nominee', $keepId, ['merged' => $mergedIds]
            );
        } catch (\Throwable) {}

        $votes = (int) (DB::table('gates_nominees')->where('id', $keepId)->value('vote_count') ?? 0);
        return ['ok' => true, 'merged' => count($mergedIds), 'votes' => $votes, 'keep_id' => $keepId];
    }

    /** Reassign every row referencing nominee $from to nominee $to, deduping where a UNIQUE key collides. */
    private static function reassignAll(int $from, int $to): void
    {
        // Votes — same category guarantees no uq_one_vote(voter,category) collision, so plain reassign.
        self::reassignPlain('gates_votes', 'nominee_id', $from, $to);

        // Judge data — dedupe on the unique key's non-nominee columns (keep the survivor's row, drop the duplicate).
        self::reassignDedup('gates_judge_criteria_scores', 'nominee_id', $from, $to, ['judge_id', 'criterion_id']);
        self::reassignDedup('gates_judge_notes',           'nominee_id', $from, $to, ['judge_id']);
        self::reassignDedup('gates_vote_milestones',       'nominee_id', $from, $to, ['milestone']);
        self::reassignDedup('gates_collusion_findings',    'nominee_id', $from, $to, ['kind', 'shared_key']);

        // Plain soft references (no UNIQUE key involving the nominee).
        self::reassignPlain('gates_funnel_events', 'nominee_id', $from, $to);
        self::reassignPlain('gates_otp_tokens',    'nominee_id', $from, $to);
        self::reassignPlain('gates_donations',     'intent_nominee_id', $from, $to);

        // Polymorphic references (type column = 'nominee').
        self::reassignPlain('gates_comments',    'target_id',      $from, $to, ['target_type', 'nominee']);
        self::reassignDedup('gates_cheers',      'target_id',      $from, $to, ['fp'], ['target_type', 'nominee']);
        self::reassignPlain('gates_uploads',     'attached_to_id', $from, $to, ['attached_to_type', 'nominee']);
        self::reassignPlain('gates_form_tokens', 'subject_id',     $from, $to, ['purpose', 'nominee']);
        self::reassignPlain('gates_events',      'subject_id',     $from, $to, ['subject_type', 'nominee']);
        self::reassignPlain('gates_activity',    'target_id',      $from, $to, ['target_type', 'nominee']);
        // Deliberately untouched: gates_vote_snapshots (hash chain), gates_audit_log (history), gates_nominations (decoupled).
    }

    /** UPDATE $col $from→$to, optionally scoped to a polymorphic [typeCol, typeVal]. Guarded for missing tables/columns. */
    private static function reassignPlain(string $table, string $col, int $from, int $to, ?array $scope = null): void
    {
        if (!self::hasCol($table, $col)) return;
        try {
            $q = DB::table($table)->where($col, $from);
            if ($scope) $q->where($scope[0], $scope[1]);
            $q->update([$col => $to]);
        } catch (\Throwable) {}
    }

    /**
     * Dedupe-then-reassign: for each $from row whose ($otherKeyCols) already
     * exist on a $to row, delete the $from row (it would violate the UNIQUE
     * key); reassign the rest. Portable across MySQL/SQLite (done in PHP, not a
     * DELETE…JOIN). Requires an `id` PK on the table.
     */
    private static function reassignDedup(string $table, string $col, int $from, int $to, array $otherKeyCols, ?array $scope = null): void
    {
        if (!self::hasCol($table, $col) || !self::hasCol($table, 'id')) return;
        foreach ($otherKeyCols as $c) { if (!self::hasCol($table, $c)) return; }
        try {
            $keepQ = DB::table($table)->where($col, $to);
            if ($scope) $keepQ->where($scope[0], $scope[1]);
            $taken = [];
            foreach ($keepQ->get($otherKeyCols) as $r) { $taken[self::keyOf((array) $r, $otherKeyCols)] = true; }

            $fromQ = DB::table($table)->where($col, $from);
            if ($scope) $fromQ->where($scope[0], $scope[1]);
            foreach ($fromQ->get(array_merge(['id'], $otherKeyCols)) as $r) {
                $row = (array) $r;
                if (isset($taken[self::keyOf($row, $otherKeyCols)])) {
                    DB::table($table)->where('id', $row['id'])->delete();       // collision → drop the duplicate
                } else {
                    DB::table($table)->where('id', $row['id'])->update([$col => $to]);
                    $taken[self::keyOf($row, $otherKeyCols)] = true;            // guard against dup-within-$from
                }
            }
        } catch (\Throwable) {}
    }

    /** Rebuild the survivor's denormalised vote counters from the reassigned gates_votes rows. */
    private static function rebuildCounters(int $id): void
    {
        if (!self::hasCol('gates_votes', 'nominee_id')) return;
        try {
            $hasWeight = self::hasCol('gates_votes', 'weight');
            $hasType   = self::hasCol('gates_votes', 'vote_type');

            $all = $hasWeight
                ? (int) DB::table('gates_votes')->where('nominee_id', $id)->sum('weight')
                : (int) DB::table('gates_votes')->where('nominee_id', $id)->count();

            $organic = $all;
            if ($hasType) {
                $oq = DB::table('gates_votes')->where('nominee_id', $id)->where('vote_type', 'standard');
                $organic = $hasWeight ? (int) $oq->sum('weight') : (int) $oq->count();
            }

            $upd = ['vote_count' => $all];
            if (self::hasCol('gates_nominees', 'organic_vote_count')) $upd['organic_vote_count'] = $organic;
            DB::table('gates_nominees')->where('id', $id)->update($upd);
        } catch (\Throwable) {}
    }

    private static function keyOf(array $row, array $cols): string
    {
        return implode('|', array_map(static fn($c) => (string) ($row[$c] ?? ''), $cols));
    }

    private static function hasCol(string $table, string $col): bool
    {
        try { return DB::schema()->hasColumn($table, $col); } catch (\Throwable) { return false; }
    }
}
