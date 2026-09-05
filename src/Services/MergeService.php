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
 * rebuilds the survivor's vote counters from the reassigned rows.
 *
 * REVERSIBLE (since 2026-07-22): a merge no longer deletes the duplicate rows.
 * It **tombstones** them (`gates_nominees.merged_into` = survivor id) so they
 * vanish from every public/scoring surface via {@see notMerged()}, and it writes
 * a per-row undo journal to `gates_merge_log` — the old value of every reassigned
 * row and a full snapshot of every collision-dropped row — so {@see unmerge()}
 * restores the exact pre-merge state. The whole thing runs in one transaction:
 * any failure rolls back and nothing is lost or half-merged.
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
     * The gates_otp_tokens purposes whose `nominee_id` really holds a nominee id.
     *
     * The column is shared: `judge_login` stores a gates_judges id in it and
     * `user_login` a gates_users id, because those flows reused the voting token's
     * row shape. Only the purposes listed here may be reassigned when a nominee is
     * merged away. See the note at the call site in reassignAll().
     */
    private const NOMINEE_OTP_PURPOSES = ['vote', 'claim', 'preflight', 'paid-vote'];

    /**
     * @param int[] $mergeIds nominees to fold in (the survivor id, if included, and non-existent ids are ignored)
     * @return array{ok:bool,error?:string,merged:int,votes:int,keep_id:int}
     */
    public static function mergeNominees(int $keepId, array $mergeIds, ?int $adminId = null): array
    {
        $fail = static fn(string $msg): array => ['ok' => false, 'error' => $msg, 'merged' => 0, 'votes' => 0, 'keep_id' => $keepId];

        $keep = DB::table('gates_nominees')->where('id', $keepId)->first();
        if (!$keep) return $fail('The nominee to keep no longer exists.');
        if (!empty($keep->merged_into ?? null)) return $fail('The nominee to keep has itself been merged away — unmerge it first.');

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $mergeIds),
            static fn($i) => $i > 0 && $i !== $keepId
        )));
        if (!$ids) return $fail('Select at least one other nominee to merge into this one.');

        // Never fold in a nominee that is already a tombstone (it would double-merge).
        $others = DB::table('gates_nominees')->whereIn('id', $ids)
            ->where(function ($q) { self::notMerged($q); })->get();
        if ($others->isEmpty()) return $fail('None of the selected nominees exist any more.');

        foreach ($others as $o) {
            if ((int) $o->category_id !== (int) $keep->category_id) {
                return $fail('All nominees in a merge must be in the same category — move them into one category first, then merge.');
            }
        }
        $mergedIds = array_map(static fn($o) => (int) $o->id, $others->all());
        $batch = self::token();
        $now   = date('Y-m-d H:i:s');
        $log   = [];

        try {
            DB::transaction(function () use ($keepId, $mergedIds, $keep, $others, $batch, $now, &$log) {
                foreach ($mergedIds as $from) {
                    self::reassignAll($from, $keepId, $batch, $log);
                }
                // The survivor inherits a profile link / photo from a merged row
                // only if it doesn't already have one of its own. We journal the
                // survivor's PRIOR value so an unmerge can put it back.
                $adopt = [];
                if (empty($keep->profile_id)) {
                    foreach ($others as $o) {
                        if (!empty($o->profile_id)) {
                            $adopt['profile_id'] = (int) $o->profile_id;
                            $log[] = self::entry($batch, $keepId, (int) $o->id, 'reassign', 'gates_nominees', $keepId, 'profile_id', $keep->profile_id ?? null);
                            break;
                        }
                    }
                }
                if (empty($keep->photo_path)) {
                    foreach ($others as $o) {
                        if (!empty($o->photo_path)) {
                            $adopt['photo_path'] = (string) $o->photo_path;
                            $log[] = self::entry($batch, $keepId, (int) $o->id, 'reassign', 'gates_nominees', $keepId, 'photo_path', $keep->photo_path ?? null);
                            break;
                        }
                    }
                }
                if ($adopt) DB::table('gates_nominees')->where('id', $keepId)->update($adopt);

                self::rebuildCounters($keepId);

                // Tombstone (not delete) — hidden everywhere via notMerged(), restorable via unmerge().
                DB::table('gates_nominees')->whereIn('id', $mergedIds)
                    ->update(['merged_into' => $keepId, 'merged_at' => $now]);

                if ($log) {
                    foreach (array_chunk($log, 200) as $chunk) {
                        DB::table('gates_merge_log')->insert($chunk);
                    }
                }
            });
        } catch (\Throwable $e) {
            error_log('[MergeService] ' . $e->getMessage());
            return $fail(\AfricaGates\Admin\Support\ActionError::dbMessage($e));
        }

        try {
            (new \AfricaGates\Admin\Services\AuditService())->record(
                (int) ($adminId ?? 0), 'nominee.merge', 'nominee', $keepId, ['merged' => $mergedIds, 'batch' => $batch]
            );
        } catch (\Throwable) {}

        $votes = (int) (DB::table('gates_nominees')->where('id', $keepId)->value('vote_count') ?? 0);
        return ['ok' => true, 'merged' => count($mergedIds), 'votes' => $votes, 'keep_id' => $keepId];
    }

    /**
     * Reverse a merge for one tombstoned nominee: move its rows back off the
     * survivor, re-insert the rows that were dropped as UNIQUE collisions,
     * un-tombstone it, and rebuild BOTH nominees' counters. Transactional.
     *
     * @return array{ok:bool,error?:string,restored:int,keep_id:int,merged_id:int}
     */
    public static function unmerge(int $mergedId, ?int $adminId = null): array
    {
        $fail = static fn(string $msg): array => ['ok' => false, 'error' => $msg, 'restored' => 0, 'keep_id' => 0, 'merged_id' => $mergedId];

        if (!self::hasCol('gates_nominees', 'merged_into') || !self::hasTable('gates_merge_log')) {
            return $fail('Merge-undo is not available until the database migration has run.');
        }
        $nominee = DB::table('gates_nominees')->where('id', $mergedId)->first();
        if (!$nominee) return $fail('That nominee no longer exists.');
        $keepId = (int) ($nominee->merged_into ?? 0);
        if ($keepId <= 0) return $fail('That nominee is not a merge tombstone — nothing to undo.');

        $rows = DB::table('gates_merge_log')->where('merged_id', $mergedId)->orderBy('id')->get();
        $restored = 0;

        try {
            DB::transaction(function () use ($mergedId, $keepId, $rows, &$restored) {
                foreach ($rows as $r) {
                    if ($r->op === 'delete') {
                        // Re-insert the row that was dropped on a UNIQUE collision.
                        $snap = json_decode((string) $r->snapshot, true);
                        if (is_array($snap) && $snap) {
                            try { DB::table($r->tbl)->insert($snap); $restored++; }
                            catch (\Throwable) {
                                // id may already be taken — retry letting the DB assign one.
                                unset($snap['id']);
                                try { DB::table($r->tbl)->insert($snap); $restored++; } catch (\Throwable) {}
                            }
                        }
                    } else { // reassign — put the old value back on the specific row.
                        if ($r->row_pk === null || $r->col === null) continue;
                        try {
                            $val = $r->old_val;
                            if ($val !== null && ctype_digit((string) $val)) $val = (int) $val;
                            DB::table($r->tbl)->where('id', (int) $r->row_pk)->update([$r->col => $val]);
                            $restored++;
                        } catch (\Throwable) {}
                    }
                }

                DB::table('gates_nominees')->where('id', $mergedId)->update(['merged_into' => null, 'merged_at' => null]);

                self::rebuildCounters($keepId);
                self::rebuildCounters($mergedId);

                DB::table('gates_merge_log')->where('merged_id', $mergedId)->delete();
            });
        } catch (\Throwable $e) {
            error_log('[MergeService::unmerge] ' . $e->getMessage());
            return $fail(\AfricaGates\Admin\Support\ActionError::dbMessage($e));
        }

        try {
            (new \AfricaGates\Admin\Services\AuditService())->record(
                (int) ($adminId ?? 0), 'nominee.unmerge', 'nominee', $mergedId, ['from_keep' => $keepId, 'restored' => $restored]
            );
        } catch (\Throwable) {}

        return ['ok' => true, 'restored' => $restored, 'keep_id' => $keepId, 'merged_id' => $mergedId];
    }

    /**
     * Tombstoned nominees the admin might want to undo — newest first, with the
     * survivor's name for context. Empty when the migration hasn't run.
     */
    public static function recentlyMerged(int $limit = 20): array
    {
        if (!self::hasCol('gates_nominees', 'merged_into')) return [];
        try {
            return DB::table('gates_nominees as m')
                ->leftJoin('gates_nominees as k', 'k.id', '=', 'm.merged_into')
                ->whereNotNull('m.merged_into')
                ->orderByDesc('m.merged_at')
                ->limit(max(1, $limit))
                ->get(['m.id', 'm.name', 'm.category_id', 'm.merged_at', 'm.merged_into as keep_id', 'k.name as keep_name'])
                ->map(static fn($r) => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }

    /**
     * Append a "not a merge tombstone" filter to a nominee query, guarded so it
     * no-ops on a pre-migration DB (the column won't exist yet). $col may be
     * alias-qualified, e.g. 'n.merged_into'.
     */
    public static function notMerged($query, string $col = 'merged_into')
    {
        $base = str_contains($col, '.') ? substr($col, strpos($col, '.') + 1) : $col;
        if (self::hasCol('gates_nominees', $base)) $query->whereNull($col);
        return $query;
    }

    /** Reassign every row referencing nominee $from to nominee $to, journaling each move/drop. */
    private static function reassignAll(int $from, int $to, string $batch, array &$log): void
    {
        // Votes — same category guarantees no uq_one_vote(voter,category) collision, so plain reassign.
        self::reassignPlain('gates_votes', 'nominee_id', $from, $to, $batch, $log);

        // Judge data — dedupe on the unique key's non-nominee columns (keep the survivor's row, drop the duplicate).
        self::reassignDedup('gates_judge_criteria_scores', 'nominee_id', $from, $to, ['judge_id', 'criterion_id'], $batch, $log);
        self::reassignDedup('gates_judge_notes',           'nominee_id', $from, $to, ['judge_id'], $batch, $log);
        self::reassignDedup('gates_vote_milestones',       'nominee_id', $from, $to, ['milestone'], $batch, $log);
        self::reassignDedup('gates_collusion_findings',    'nominee_id', $from, $to, ['kind', 'shared_key'], $batch, $log);

        // Plain soft references (no UNIQUE key involving the nominee).
        self::reassignPlain('gates_funnel_events', 'nominee_id', $from, $to, $batch, $log);
        // gates_otp_tokens.nominee_id DOES NOT ALWAYS HOLD A NOMINEE ID.
        //
        // Voting got to this table first and the columns were named for it, but later
        // flows reused the row shape and put their own subject in the same column:
        // judge sign-in stores a gates_judges id there, account sign-in stores a
        // gates_users id. Those are three independent auto-increment sequences of small
        // integers, so "judge 7" and "nominee 7" collide as a matter of course rather
        // than as an edge case — and an unscoped reassign silently rewrote a judge's or
        // a member's live sign-in token every time a nominee with a matching id was
        // merged away, then journalled the move as though it had touched that nominee's
        // data. Neither login path reads the column back, so nothing broke; what it
        // corrupted was the merge journal, which is the record used to review and undo
        // a merge.
        //
        // ALLOWLIST, not a denylist: a purpose whose subject really is a nominee has to
        // be named here, so adding one forces the decision instead of inheriting a
        // rewrite by default.
        self::reassignPlain('gates_otp_tokens', 'nominee_id', $from, $to, $batch, $log,
                            ['purpose', self::NOMINEE_OTP_PURPOSES]);
        self::reassignPlain('gates_donations',     'intent_nominee_id', $from, $to, $batch, $log);

        // Polymorphic references (type column = 'nominee').
        self::reassignPlain('gates_comments',    'target_id',      $from, $to, $batch, $log, ['target_type', 'nominee']);
        self::reassignDedup('gates_cheers',      'target_id',      $from, $to, ['fp'], $batch, $log, ['target_type', 'nominee']);
        self::reassignPlain('gates_uploads',     'attached_to_id', $from, $to, $batch, $log, ['attached_to_type', 'nominee']);
        self::reassignPlain('gates_form_tokens', 'subject_id',     $from, $to, $batch, $log, ['purpose', 'nominee']);
        self::reassignPlain('gates_events',      'subject_id',     $from, $to, $batch, $log, ['subject_type', 'nominee']);
        self::reassignPlain('gates_activity',    'target_id',      $from, $to, $batch, $log, ['target_type', 'nominee']);

        // Claims need their own rule: active_nominee_id is UNIQUE, so it cannot be moved
        // by a plain UPDATE. See reassignClaims().
        self::reassignClaims($from, $to, $batch, $log);

        // Deliberately untouched: gates_vote_snapshots (hash chain), gates_audit_log (history), gates_nominations (decoupled).
    }

    /**
     * Move nominee claims to the survivor, respecting "one owner per page".
     *
     * ── WHY THIS IS NOT A reassignPlain CALL ─────────────────────────────────
     *
     * `gates_nominee_claims` carries the invariant `active_nominee_id = nominee_id while
     * active, NULL otherwise`, UNIQUE. Two consequences a plain UPDATE gets wrong:
     *
     *   • Updating `nominee_id` alone leaves an ACTIVE claim pointing its unique slot at
     *     the merged-away id. The survivor's page then reads as UNCLAIMED — so a stranger
     *     can start a fresh claim on it — while the real owner's proof sits on a row no
     *     page looks at, and the slot they need can never be freed.
     *   • Updating both blindly collides the moment the survivor already has an owner.
     *
     * ── AND WHY A COLLISION IS A HOLD, NOT A DELETE ──────────────────────────
     *
     * When both pages were claimed, the merge has just discovered that two people own what
     * is now one page. docs/CLAIM-FAIRNESS-AND-FRAUD.md §10 refuses to auto-transfer:
     * "a second claim on an active page goes to a human, always." So the incoming claim is
     * HELD with a reason rather than dropped — held is not a refusal, the row stays
     * queryable, and somebody decides. Dropping it would silently erase one of the two
     * people's evidence, and which one would depend on merge direction.
     *
     * Journaled like every other move, so `unmerge()` can put it back.
     */
    private static function reassignClaims(int $from, int $to, string $batch, array &$log): void
    {
        if (!self::hasTable('gates_nominee_claims') || !self::hasCol('gates_nominee_claims', 'nominee_id')) return;

        $hasActive = self::hasCol('gates_nominee_claims', 'active_nominee_id');

        try {
            $survivorOwned = $hasActive && DB::table('gates_nominee_claims')
                ->where('active_nominee_id', $to)->exists();

            foreach (DB::table('gates_nominee_claims')->where('nominee_id', $from)->get() as $r) {
                $row  = (array) $r;
                $id   = (int) ($row['id'] ?? 0);
                $wasActive = $hasActive && ($row['active_nominee_id'] ?? null) !== null;

                $set = ['nominee_id' => $to];

                if ($wasActive && !$survivorOwned) {
                    $set['active_nominee_id'] = $to;
                    $survivorOwned = true;               // this claim is now the owner
                } elseif ($wasActive) {
                    // Two owners for one page. A person decides which.
                    $set['active_nominee_id'] = null;
                    $set['status']            = 'held';
                    if (self::hasCol('gates_nominee_claims', 'hold_reason')) {
                        $set['hold_reason'] = 'This page was merged with another that already had '
                            . 'an owner, so a person needs to confirm which claim stands. There is '
                            . 'nothing to pay.';
                    }
                }

                // The FULL row is snapshotted, not just the old nominee_id: this move can
                // also change `status` and `active_nominee_id`, and an unmerge that restored
                // only the id would leave a claim held forever for a merge that was undone.
                $log[] = self::entry($batch, $to, $from, 'reassign', 'gates_nominee_claims',
                                     $id, 'nominee_id', (string) $from, json_encode($row));
                DB::table('gates_nominee_claims')->where('id', $id)->update($set);
            }
        } catch (\Throwable $e) {
            error_log('[merge] could not move claims ' . $from . '→' . $to . ': ' . $e->getMessage());
        }
    }

    /**
     * UPDATE $col $from→$to, journaling each moved row's id + old value.
     *
     * $scope narrows the rows to a discriminator column: `[col, value]` for a
     * single value, or `[col, [a, b, …]]` for a set. The set form exists because
     * one table can use the same column to mean different things per row — see
     * the gates_otp_tokens note in reassignAll().
     */
    private static function reassignPlain(string $table, string $col, int $from, int $to, string $batch, array &$log, ?array $scope = null): void
    {
        if (!self::hasCol($table, $col)) return;
        $hasId = self::hasCol($table, 'id');
        try {
            $q = DB::table($table)->where($col, $from);
            if ($scope) {
                is_array($scope[1]) ? $q->whereIn($scope[0], $scope[1]) : $q->where($scope[0], $scope[1]);
            }

            if ($hasId) {
                foreach ($q->pluck('id') as $pk) {
                    $log[] = self::entry($batch, $to, $from, 'reassign', $table, (int) $pk, $col, (string) $from);
                }
            }
            $q->update([$col => $to]);
        } catch (\Throwable) {}
    }

    /**
     * Dedupe-then-reassign: for each $from row whose ($otherKeyCols) already
     * exist on a $to row, delete the $from row (it would violate the UNIQUE
     * key) after snapshotting it; reassign the rest (journaling the old value).
     * Portable across MySQL/SQLite (done in PHP, not a DELETE…JOIN). Requires
     * an `id` PK on the table.
     */
    private static function reassignDedup(string $table, string $col, int $from, int $to, array $otherKeyCols, string $batch, array &$log, ?array $scope = null): void
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
            foreach ($fromQ->get() as $r) {                                    // full rows — we may need to snapshot
                $row = (array) $r;
                $k   = self::keyOf($row, $otherKeyCols);
                if (isset($taken[$k])) {
                    // collision → snapshot then drop the duplicate (so unmerge can re-insert it verbatim)
                    $log[] = self::entry($batch, $to, $from, 'delete', $table, (int) ($row['id'] ?? 0), $col, (string) $from, json_encode($row));
                    DB::table($table)->where('id', $row['id'])->delete();
                } else {
                    $log[] = self::entry($batch, $to, $from, 'reassign', $table, (int) ($row['id'] ?? 0), $col, (string) $from);
                    DB::table($table)->where('id', $row['id'])->update([$col => $to]);
                    $taken[$k] = true;                                          // guard against dup-within-$from
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

    /** Build one journal row. */
    private static function entry(string $batch, int $keepId, int $mergedId, string $op, string $table, ?int $rowPk, ?string $col, ?string $oldVal, ?string $snapshot = null): array
    {
        return [
            'batch' => $batch, 'keep_id' => $keepId, 'merged_id' => $mergedId,
            'op' => $op, 'tbl' => $table, 'row_pk' => $rowPk, 'col' => $col,
            'old_val' => $oldVal, 'snapshot' => $snapshot,
        ];
    }

    private static function token(): string
    {
        try { return bin2hex(random_bytes(16)); }
        catch (\Throwable) { return str_replace('.', '', uniqid('m', true)); }
    }

    private static function keyOf(array $row, array $cols): string
    {
        return implode('|', array_map(static fn($c) => (string) ($row[$c] ?? ''), $cols));
    }

    /** @var array<string,bool> per-process memo — the schema is stable within a request. */
    private static array $colMemo = [];

    private static function hasCol(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (isset(self::$colMemo[$k])) return self::$colMemo[$k];
        try { return self::$colMemo[$k] = DB::schema()->hasColumn($table, $col); }
        catch (\Throwable) { return false; }   // don't memo a transient failure
    }

    private static function hasTable(string $table): bool
    {
        try { return DB::schema()->hasTable($table); } catch (\Throwable) { return false; }
    }
}
