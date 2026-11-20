<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * A withdrawn conflict of interest is a record, not an absence.
 *
 * `JudgeService::withdrawConflict()` DELETED the `gates_judge_coi` row. Withdrawing is
 * legitimate — a judge may have declared in error — but the delete did three things at
 * once and only one of them was intended: it re-opened scoring (intended), it silently
 * restored every mark the recusal had been holding out of the result, and it erased the
 * declaration from the judging audit, the one screen whose job is comparing what a judge
 * declared with what they then did. "Declared a conflict, scored, withdrew it" left no
 * trace anywhere — not in the audit log either, because nothing wrote one.
 *
 * `withdrawn_at` NULL means the recusal stands; a stamp means it was declared and then
 * withdrawn at that moment. Every reader that asks "is this judge recused" filters on it
 * (JudgeService::hasConflict(), NomineeScoringService::disqualifiedJudges()); the audit
 * reads both.
 *
 * Nullable, no default, so every existing row reads as a standing recusal — which is what
 * it is. Idempotent. NEVER exit/die here (include()d in a loop).
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$schema = DB::schema();
if ($schema->hasTable('gates_judge_coi') && !$schema->hasColumn('gates_judge_coi', 'withdrawn_at')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_judge_coi ADD COLUMN withdrawn_at TEXT NULL'
        : 'ALTER TABLE gates_judge_coi ADD COLUMN withdrawn_at TIMESTAMP NULL DEFAULT NULL');
    echo "  + gates_judge_coi.withdrawn_at\n";
}
