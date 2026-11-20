<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Ensure the gates_votes indexes four earlier catch-up migrations failed to create.
 *
 * WHY THIS IS A SERVICE AND NOT JUST A MIGRATION. The migration is how it runs
 * automatically on deploy, but a migration runs EXACTLY ONCE — `MigrationRunner`
 * records it in `gates_migrations` and never returns. That is fine for the two plain
 * indexes, which always succeed, and wrong for the UNIQUE one, which legitimately
 * CANNOT be created while duplicate rows exist.
 *
 * Without a re-runnable entry point the sequence would be: deploy, migration reports
 * "1 duplicate group — resolve it", operator resolves it… and nothing ever creates
 * the constraint, because the migration is ledgered as done. That is the same shape
 * of silent gap this whole repair exists to close, so it would be a poor way to
 * close it.
 *
 * So the logic lives here, and there are two doors:
 *   - `2026_07_28_vote_index_repair.php` — automatic, on deploy.
 *   - `bin/console db:repair-indexes`    — idempotent, run any time, in particular
 *                                          after resolving duplicates.
 *
 * One implementation, so the two cannot drift.
 */
final class VoteIndexRepair
{
    /**
     * @return array{lines: list<string>, complete: bool, duplicates: int}
     *         `complete` is false when the uniqueness constraint is still absent,
     *         which is the only outcome needing a human.
     */
    public static function run(): array
    {
        if (!SchemaIndex::tableExists('gates_votes')) {
            return ['lines' => ['  = gates_votes not present — nothing to repair'], 'complete' => true, 'duplicates' => 0];
        }

        $sqlite = self::isSqlite();
        $lines  = [];

        // Plain indexes. Declared in schema.sql (device) and in NEITHER base schema
        // (donation), so the latter was missing on every MySQL install until now.
        $lines[] = SchemaIndex::ensure('gates_votes', 'idx_votes_device', ['device_hash']);
        $lines[] = SchemaIndex::ensure('gates_votes', 'idx_votes_donation', ['donation_id']);

        // The per-voter idempotency constraint — the correctness one. Without it a
        // retried vote can be counted twice.
        //
        // Its NAME differs by driver: `uq_votes_idem` in schema.sql, `idx_votes_idem`
        // in sqlite-schema.sql. Either satisfies the guarantee, so accept either and
        // never create the second — that would double the write cost of every vote
        // to enforce something already enforced.
        $canonical = $sqlite ? 'idx_votes_idem' : 'uq_votes_idem';
        foreach (['uq_votes_idem', 'idx_votes_idem'] as $name) {
            if (SchemaIndex::exists('gates_votes', $name)) {
                $lines[] = "  = per-voter idempotency constraint present as {$name}";
                return ['lines' => array_merge($lines, self::redundancyNote($sqlite)), 'complete' => true, 'duplicates' => 0];
            }
        }

        $dupes = self::duplicateGroups();
        if ($dupes > 0) {
            // Actionable, not just an error string. A bare exception message is what
            // let the original defect hide for months.
            $lines[] = "  ! {$canonical} NOT created — {$dupes} duplicate (voter_email_hash, idempotency_key) group(s) exist.";
            $lines[] = '    Until this is resolved a retried vote can be counted twice. Find them with:';
            $lines[] = '      SELECT voter_email_hash, idempotency_key, COUNT(*) c FROM gates_votes';
            $lines[] = '       WHERE idempotency_key IS NOT NULL';
            $lines[] = '       GROUP BY voter_email_hash, idempotency_key HAVING c > 1;';
            $lines[] = '    Then re-run:  bin/console db:repair-indexes';
            return ['lines' => $lines, 'complete' => false, 'duplicates' => $dupes];
        }

        $line    = SchemaIndex::ensure('gates_votes', $canonical, ['voter_email_hash', 'idempotency_key'], true);
        $lines[] = $line;

        return [
            'lines'      => array_merge($lines, self::redundancyNote($sqlite)),
            // A `!` here means the create failed for a reason the duplicate check
            // did not predict, so it is not complete either.
            'complete'   => !str_contains($line, '!'),
            'duplicates' => 0,
        ];
    }

    /**
     * READ-ONLY health check. Never issues DDL, so it is safe on a request path.
     *
     * This exists because of the pattern behind every defect in this repair: the
     * original migrations failed, printed a warning, and nobody ever read it. A fix
     * that only reports at deploy time repeats that mistake — the operator who most
     * needs to know is the one who was not watching the deploy log.
     *
     * Surfaced in the admin operational state and logged by the maintenance run, so
     * a missing uniqueness constraint keeps announcing itself until it is fixed
     * rather than waiting to be discovered by a double-counted vote.
     *
     * @return list<array{severity:string, message:string, fix:string}>
     */
    public static function warnings(): array
    {
        if (!SchemaIndex::tableExists('gates_votes')) return [];

        $out = [];

        $hasConstraint = SchemaIndex::exists('gates_votes', 'uq_votes_idem')
            || SchemaIndex::exists('gates_votes', 'idx_votes_idem');
        if (!$hasConstraint) {
            $dupes = self::duplicateGroups();
            $out[] = [
                'severity' => 'critical',
                'message'  => 'gates_votes has no per-voter idempotency constraint, so a retried vote can be '
                    . 'counted twice' . ($dupes > 0
                        ? " — and {$dupes} duplicate (voter, key) group(s) already exist, which is what is blocking it."
                        : '.'),
                'fix'      => 'bin/console db:repair-indexes',
            ];
        }

        foreach ([
            'idx_votes_donation' => 'paid-vote clawbacks scan gates_votes by donation_id',
            'idx_votes_device'   => 'fraud and collusion checks scan gates_votes by device_hash',
        ] as $index => $why) {
            if (!SchemaIndex::exists('gates_votes', $index)) {
                $out[] = [
                    'severity' => 'warning',
                    'message'  => "Index {$index} is missing — {$why}.",
                    'fix'      => 'bin/console db:repair-indexes',
                ];
            }
        }

        return $out;
    }

    /** How many (voter, key) pairs appear more than once. NULL keys excluded — multiple NULLs are legal and normal. */
    public static function duplicateGroups(): int
    {
        try {
            return (int) DB::connection()->selectOne(
                'SELECT COUNT(*) AS n FROM (
                     SELECT voter_email_hash, idempotency_key
                       FROM gates_votes
                      WHERE idempotency_key IS NOT NULL
                      GROUP BY voter_email_hash, idempotency_key
                     HAVING COUNT(*) > 1
                 ) d'
            )->n;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Report — never remove — a redundant leftover.
     *
     * On MySQL an old `idx_votes_idem` over idempotency_key ALONE can survive from
     * the pre-per-voter design. It is redundant once `uq_votes_idem` exists and costs
     * write throughput, but dropping an index this code did not create would be a
     * destructive guess about someone else's intent.
     *
     * @return list<string>
     */
    private static function redundancyNote(bool $sqlite): array
    {
        if ($sqlite) return [];
        if (!SchemaIndex::exists('gates_votes', 'idx_votes_idem')
            || !SchemaIndex::exists('gates_votes', 'uq_votes_idem')) {
            return [];
        }
        return [
            '  ~ idx_votes_idem is redundant alongside uq_votes_idem — consider:',
            '      DROP INDEX idx_votes_idem ON gates_votes;',
        ];
    }

    private static function isSqlite(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }
}
