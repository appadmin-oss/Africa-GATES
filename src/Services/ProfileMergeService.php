<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Merge duplicate registry PROFILES into one — the profile-level counterpart of
 * {@see MergeService} (which merges nominees). The same real problem: one person
 * registered twice ("Ada Obi" / "ada obi") splits their linked nominees, CPI
 * history, follows, cheers and comments across two profile rows, so neither
 * shows the person's full standing.
 *
 * Reassigns every profile reference onto the survivor — linked nominees, CPI
 * history, and the polymorphic community rows targeting the profile (comments,
 * cheers, follows, activity) — deduping where a UNIQUE key would collide, then
 * TOMBSTONES the merged profiles (merged_into = survivor) instead of deleting
 * them. Every move/drop is journalled to gates_profile_merge_log so {@see
 * unmerge()} restores the pre-merge state exactly. One transaction; any failure
 * rolls back.
 *
 * The survivor's profile CPI (mean of its now-larger set of linked nominees)
 * refreshes on the next CPI recompute — the same deferral the nominee merge
 * uses for rankings. Unlike nominees there is no category constraint: a person
 * is one profile regardless of category.
 */
final class ProfileMergeService
{
    private const LOG = 'gates_profile_merge_log';

    /**
     * @param int[] $mergeIds profiles to fold into $keepId
     * @return array{ok:bool,error?:string,merged:int,keep_id:int}
     */
    public static function mergeProfiles(int $keepId, array $mergeIds, ?int $adminId = null): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'merged' => 0, 'keep_id' => $keepId];

        $keep = DB::table('gates_profiles')->where('id', $keepId)->first();
        if (!$keep) return $fail('The profile to keep no longer exists.');
        if (!empty($keep->merged_into ?? null)) return $fail('The profile to keep has itself been merged away — unmerge it first.');

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $mergeIds),
            static fn($i) => $i > 0 && $i !== $keepId
        )));
        if (!$ids) return $fail('Select at least one other profile to merge into this one.');

        $others = DB::table('gates_profiles')->whereIn('id', $ids)
            ->where(fn($q) => MergeJournal::notMerged($q, 'gates_profiles'))->pluck('id')
            ->map(fn($i) => (int) $i)->all();
        if (!$others) return $fail('None of the selected profiles exist any more.');

        $batch = MergeJournal::token();
        $now   = date('Y-m-d H:i:s');
        $log   = [];

        try {
            DB::transaction(function () use ($keepId, $others, $batch, $now, &$log) {
                foreach ($others as $from) {
                    self::reassignAll($from, $keepId, $batch, $log);
                }
                DB::table('gates_profiles')->whereIn('id', $others)
                    ->update(['merged_into' => $keepId, 'merged_at' => $now]);
                MergeJournal::write(self::LOG, $log);
            });
        } catch (\Throwable $e) {
            error_log('[ProfileMergeService] ' . $e->getMessage());
            return $fail(\AfricaGates\Admin\Support\ActionError::dbMessage($e));
        }

        try {
            (new \AfricaGates\Admin\Services\AuditService())->record(
                (int) ($adminId ?? 0), 'profile.merge', 'profile', $keepId, ['merged' => $others, 'batch' => $batch]
            );
        } catch (\Throwable) {}

        return ['ok' => true, 'merged' => count($others), 'keep_id' => $keepId];
    }

    /**
     * Undo a profile merge: restore a tombstoned profile and move its rows back
     * off the survivor (re-inserting dropped collisions). The survivor's CPI
     * refreshes on the next recompute.
     *
     * @return array{ok:bool,error?:string,restored:int,keep_id:int,merged_id:int}
     */
    public static function unmerge(int $mergedId, ?int $adminId = null): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'restored' => 0, 'keep_id' => 0, 'merged_id' => $mergedId];

        if (!MergeJournal::hasCol('gates_profiles', 'merged_into') || !MergeJournal::hasTable(self::LOG)) {
            return $fail('Profile merge-undo is not available until the database migration has run.');
        }
        $p = DB::table('gates_profiles')->where('id', $mergedId)->first();
        if (!$p) return $fail('That profile no longer exists.');
        $keepId = (int) ($p->merged_into ?? 0);
        if ($keepId <= 0) return $fail('That profile is not a merge tombstone — nothing to undo.');

        $restored = 0;
        try {
            DB::transaction(function () use ($mergedId, &$restored) {
                $restored = MergeJournal::restore(self::LOG, $mergedId);
                DB::table('gates_profiles')->where('id', $mergedId)->update(['merged_into' => null, 'merged_at' => null]);
            });
        } catch (\Throwable $e) {
            error_log('[ProfileMergeService::unmerge] ' . $e->getMessage());
            return $fail(\AfricaGates\Admin\Support\ActionError::dbMessage($e));
        }

        try {
            (new \AfricaGates\Admin\Services\AuditService())->record(
                (int) ($adminId ?? 0), 'profile.unmerge', 'profile', $mergedId, ['from_keep' => $keepId, 'restored' => $restored]
            );
        } catch (\Throwable) {}

        return ['ok' => true, 'restored' => $restored, 'keep_id' => $keepId, 'merged_id' => $mergedId];
    }

    /** Tombstoned profiles the admin might want to undo — newest first, with the survivor's name. */
    public static function recentlyMerged(int $limit = 20): array
    {
        if (!MergeJournal::hasCol('gates_profiles', 'merged_into')) return [];
        try {
            return DB::table('gates_profiles as m')
                ->leftJoin('gates_profiles as k', 'k.id', '=', 'm.merged_into')
                ->whereNotNull('m.merged_into')
                ->orderByDesc('m.merged_at')->limit(max(1, $limit))
                ->get(['m.id', 'm.display_name as name', 'm.merged_at', 'm.merged_into as keep_id', 'k.display_name as keep_name'])
                ->map(static fn($r) => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }

    /** Exclude profile tombstones from a query. Delegates to the shared, guarded scope. */
    public static function notMerged($query, string $col = 'merged_into')
    {
        return MergeJournal::notMerged($query, 'gates_profiles', $col);
    }

    /** Move every row referencing profile $from onto profile $to, journaling each. */
    private static function reassignAll(int $from, int $to, string $batch, array &$log): void
    {
        // Direct FK references.
        MergeJournal::reassignPlain(self::LOG, 'gates_nominees',    'profile_id', $from, $to, $batch, $log);
        MergeJournal::reassignPlain(self::LOG, 'gates_cpi_history', 'profile_id', $from, $to, $batch, $log);

        // Polymorphic community rows targeting the profile (target_type = 'profile').
        MergeJournal::reassignPlain(self::LOG, 'gates_comments', 'target_id', $from, $to, $batch, $log, ['target_type', 'profile']);
        MergeJournal::reassignDedup(self::LOG, 'gates_cheers',   'target_id', $from, $to, ['fp'],      $batch, $log, ['target_type', 'profile']);
        MergeJournal::reassignDedup(self::LOG, 'gates_follows',  'target_id', $from, $to, ['user_id'], $batch, $log, ['target_type', 'profile']);
        MergeJournal::reassignPlain(self::LOG, 'gates_activity', 'target_id', $from, $to, $batch, $log, ['target_type', 'profile']);
        // Untouched: gates_users (linked by email, not id), gates_audit_log (history).
    }
}
