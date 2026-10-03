<?php
/**
 * What the machine found out about a registration number, in words.
 *
 * ── WHY A NOTE AND NOT ANOTHER STATE COLUMN ─────────────────────────────────
 *
 * `cac_check` already carries the VERDICT — unchecked, confirmed, verified, rejected — and
 * that column is doing its job. What it cannot carry is the reason, and the reasons that
 * matter here are not verdicts:
 *
 *   · "This number is already on file against a different organisation." That is the single
 *     most useful fraud signal available without paying anybody, and it is not a rejection —
 *     the second applicant may be the real one.
 *   · "This is a business-name registration, not an incorporated trustee." A note, per
 *     {@see RegistryCheck::cacFormat()}, because a non-profit limited by guarantee is an RC
 *     and refusing it would be wrong.
 *   · "Could not reach the register." Which is UNCHECKED and must never read as a refusal.
 *
 * Squeezing any of those into the state column would mean inventing states that are not
 * verdicts, and a reviewer reading `cac_check = 'duplicate'` learns less than one reading
 * the sentence. So the verdict stays a small closed set, and the sentence sits beside it.
 *
 * Written by the queued registry check and by nothing else, so it is always machine output
 * and a reviewer knows not to treat it as somebody's opinion.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$pdo    = DB::connection()->getPdo();

/** Add a column only if it is missing, so a re-run is a no-op on both engines. */
$add = static function (string $table, string $column, string $sqliteType, string $mysqlType)
    use ($sqlite, $pdo): void {
    try {
        if (DB::schema()->hasColumn($table, $column)) return;
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} " . ($sqlite ? $sqliteType : $mysqlType));
    } catch (\Throwable $e) {
        echo "*** could not add {$table}.{$column}: {$e->getMessage()}\n";
    }
};

$add('gates_partner_orgs', 'cac_check_note', 'TEXT', 'VARCHAR(500) DEFAULT NULL');

// When the check last ran, so a reviewer can tell a stale answer from a fresh one and the
// admin screen can offer to re-run it rather than silently showing last month's.
$add('gates_partner_orgs', 'cac_checked_at', 'TEXT', 'TIMESTAMP NULL DEFAULT NULL');

echo "  registry check note: ready\n";
