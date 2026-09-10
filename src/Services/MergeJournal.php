<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Generic, reversible reassign-and-journal engine shared by merge services.
 *
 * A "merge" folds one entity's rows into a survivor. This class does the parts
 * that are identical whatever the entity (nominee, profile, …):
 *   • reassignPlain / reassignDedup — move rows off the merged entity onto the
 *     survivor, deduping where a UNIQUE key would collide, and RECORD each move
 *     (old value) / drop (full snapshot) into a per-entity journal table.
 *   • restore — replay that journal in reverse for one merged entity: move the
 *     rows back, re-insert the dropped collisions verbatim, and consume the log.
 *   • notMerged — the tombstone-exclusion query scope, guarded so it no-ops on a
 *     pre-migration DB.
 *
 * Each merge service owns its entity-specific bits (which tables to reassign,
 * the tombstone column, and how to rebuild denormalised counters/rollups) and
 * passes its own journal table name here. ProfileMergeService uses it directly;
 * MergeService (nominees) still owns its own reassign/journal bodies, so this
 * engine can evolve without rewriting the shipped, well-tested — and destructive
 * — nominee path.
 *
 * ── BUT THE QUERY NARROWING IS SHARED, BECAUSE IT HAD DIVERGED ──────────────
 * Two independent copies of "reassign and journal" is a tolerable cost while one
 * of them is a destructive path nobody wants to rewrite. Two independent copies
 * of a WHERE clause is not: three of the four sites bound a list scope as a
 * scalar and matched only its first element, in silence. {@see applyScope()} is
 * the one clause, and both services call it.
 */
final class MergeJournal
{
    /**
     * NARROW A MERGE QUERY TO ONE POLYMORPHIC SUBJECT — THE ONE PLACE THAT DOES IT.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * FOUR SPELLINGS OF ONE CLAUSE, AND ONE OF THEM UNDER-SELECTED IN SILENCE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A scope is `[column, value]`, and the value may legitimately be a LIST: the nominee
     * merge scopes `gates_otp_tokens` to an allowlist of purposes, because rewriting every
     * token's `nominee_id` corrupted the merge journal itself — the record used to review
     * and undo a merge.
     *
     * `MergeService::reassignPlain()` grew a `whereIn` branch for that. The other three
     * sites — its own `reassignDedup()`, and BOTH of this class's — kept a bare
     * `where($col, $value)`. That is not an error and it does not throw. Passed an array,
     * Laravel's two-argument `where()` treats it as the value of an `=` comparison, PDO
     * binds it, and the driver takes the FIRST element:
     *
     *     where('purpose', ['a','b'])  →  where "purpose" = ?   (bound to 'a')
     *
     * Verified: three rows, two of them allowlisted, and the update moves ONE. So the
     * rows for every purpose after the first stay pointed at a nominee that no longer
     * exists, the journal records only what moved, and `restore()` then reports a clean
     * unmerge of a merge that was never clean. No exception anywhere.
     *
     * It is one call site away from being live: this class's own docblock invites the
     * nominee path onto this engine, and that path is the one that passes a list.
     *
     * So the clause has one implementation, and every merge query goes through it.
     *
     * @param array{0:string,1:mixed}|null $scope
     */
    public static function applyScope(object $query, ?array $scope): void
    {
        if ($scope === null || $scope === []) return;

        is_array($scope[1] ?? null)
            ? $query->whereIn($scope[0], $scope[1])
            : $query->where($scope[0], $scope[1]);
    }

    /** UPDATE $col $from→$to (optionally scoped to a polymorphic [typeCol,typeVal]), journaling each moved row's id + old value. */
    public static function reassignPlain(string $logTable, string $table, string $col, int $from, int $to, string $batch, array &$log, ?array $scope = null): void
    {
        if (!self::hasCol($table, $col)) return;
        $hasId = self::hasCol($table, 'id');
        try {
            $q = DB::table($table)->where($col, $from);
            self::applyScope($q, $scope);
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
     * exist on a $to row, snapshot then delete the $from row (it would violate
     * the UNIQUE key); reassign the rest, journaling the old value. Portable
     * (done in PHP, not a DELETE…JOIN). Requires an `id` PK on the table.
     */
    public static function reassignDedup(string $logTable, string $table, string $col, int $from, int $to, array $otherKeyCols, string $batch, array &$log, ?array $scope = null): void
    {
        if (!self::hasCol($table, $col) || !self::hasCol($table, 'id')) return;
        foreach ($otherKeyCols as $c) { if (!self::hasCol($table, $c)) return; }
        try {
            $keepQ = DB::table($table)->where($col, $to);
            self::applyScope($keepQ, $scope);
            $taken = [];
            foreach ($keepQ->get($otherKeyCols) as $r) { $taken[self::keyOf((array) $r, $otherKeyCols)] = true; }

            $fromQ = DB::table($table)->where($col, $from);
            self::applyScope($fromQ, $scope);
            foreach ($fromQ->get() as $r) {
                $row = (array) $r;
                $k   = self::keyOf($row, $otherKeyCols);
                if (isset($taken[$k])) {
                    $log[] = self::entry($batch, $to, $from, 'delete', $table, (int) ($row['id'] ?? 0), $col, (string) $from, json_encode($row));
                    DB::table($table)->where('id', $row['id'])->delete();
                } else {
                    $log[] = self::entry($batch, $to, $from, 'reassign', $table, (int) ($row['id'] ?? 0), $col, (string) $from);
                    DB::table($table)->where('id', $row['id'])->update([$col => $to]);
                    $taken[$k] = true;
                }
            }
        } catch (\Throwable) {}
    }

    /** Persist the collected journal rows into $logTable, chunked. */
    public static function write(string $logTable, array $log): void
    {
        if (!$log || !self::hasTable($logTable)) return;
        foreach (array_chunk($log, 200) as $chunk) {
            DB::table($logTable)->insert($chunk);
        }
    }

    /**
     * Reverse every journalled row for one merged entity: reassign rows back to
     * their old value and re-insert the dropped collisions. Consumes the log
     * rows. Must run inside a transaction opened by the caller. Returns the
     * number of rows moved/restored.
     */
    public static function restore(string $logTable, int $mergedId): int
    {
        if (!self::hasTable($logTable)) return 0;
        $restored = 0;
        foreach (DB::table($logTable)->where('merged_id', $mergedId)->orderBy('id')->get() as $r) {
            if ($r->op === 'delete') {
                $snap = json_decode((string) $r->snapshot, true);
                if (is_array($snap) && $snap) {
                    try { DB::table($r->tbl)->insert($snap); $restored++; }
                    catch (\Throwable) {
                        unset($snap['id']);
                        try { DB::table($r->tbl)->insert($snap); $restored++; } catch (\Throwable) {}
                    }
                }
            } else {
                if ($r->row_pk === null || $r->col === null) continue;
                try {
                    $val = $r->old_val;
                    if ($val !== null && ctype_digit((string) $val)) $val = (int) $val;
                    DB::table($r->tbl)->where('id', (int) $r->row_pk)->update([$r->col => $val]);
                    $restored++;
                } catch (\Throwable) {}
            }
        }
        DB::table($logTable)->where('merged_id', $mergedId)->delete();
        return $restored;
    }

    /**
     * Append a "not a merge tombstone" filter, guarded so it no-ops on a
     * pre-migration DB. $col may be alias-qualified, e.g. 'p.merged_into'.
     */
    public static function notMerged($query, string $table, string $col = 'merged_into')
    {
        $base = str_contains($col, '.') ? substr($col, strpos($col, '.') + 1) : $col;
        if (self::hasCol($table, $base)) $query->whereNull($col);
        return $query;
    }

    public static function token(): string
    {
        try { return bin2hex(random_bytes(16)); }
        catch (\Throwable) { return str_replace('.', '', uniqid('m', true)); }
    }

    public static function entry(string $batch, int $keepId, int $mergedId, string $op, string $table, ?int $rowPk, ?string $col, ?string $oldVal, ?string $snapshot = null): array
    {
        return [
            'batch' => $batch, 'keep_id' => $keepId, 'merged_id' => $mergedId,
            'op' => $op, 'tbl' => $table, 'row_pk' => $rowPk, 'col' => $col,
            'old_val' => $oldVal, 'snapshot' => $snapshot,
        ];
    }

    private static function keyOf(array $row, array $cols): string
    {
        return implode('|', array_map(static fn($c) => (string) ($row[$c] ?? ''), $cols));
    }

    /** @var array<string,bool> per-process memo — schema is stable within a request. */
    private static array $colMemo = [];

    public static function hasCol(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (isset(self::$colMemo[$k])) return self::$colMemo[$k];
        try { return self::$colMemo[$k] = DB::schema()->hasColumn($table, $col); }
        catch (\Throwable) { return false; }
    }

    public static function hasTable(string $table): bool
    {
        try { return DB::schema()->hasTable($table); } catch (\Throwable) { return false; }
    }
}
