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
 * MergeService (nominees) keeps its own equivalent for now, so this engine can
 * evolve without touching the shipped, well-tested nominee path.
 */
final class MergeJournal
{
    /** UPDATE $col $from→$to (optionally scoped to a polymorphic [typeCol,typeVal]), journaling each moved row's id + old value. */
    public static function reassignPlain(string $logTable, string $table, string $col, int $from, int $to, string $batch, array &$log, ?array $scope = null): void
    {
        if (!self::hasCol($table, $col)) return;
        $hasId = self::hasCol($table, 'id');
        try {
            $q = DB::table($table)->where($col, $from);
            if ($scope) $q->where($scope[0], $scope[1]);
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
            if ($scope) $keepQ->where($scope[0], $scope[1]);
            $taken = [];
            foreach ($keepQ->get($otherKeyCols) as $r) { $taken[self::keyOf((array) $r, $otherKeyCols)] = true; }

            $fromQ = DB::table($table)->where($col, $from);
            if ($scope) $fromQ->where($scope[0], $scope[1]);
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
