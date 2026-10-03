<?php
/**
 * Install the published rubric — four equal tests — as the global default.
 *
 * ── WHAT WAS MISSING ─────────────────────────────────────────────────────────
 *
 * `gates_judge_criteria` had NO default rows anywhere: not in either schema file, not in
 * `seed.sql`, not in any migration. The only things that ever wrote to it were the sandbox
 * seeder (into its own programme) and, from this release, the admin rubric screen.
 *
 * So a fresh installation had an empty global rubric — and
 * {@see \AfricaGates\Judge\Services\JudgeService} locks scoring when a programme has no
 * criteria. The judging panel of a new deployment could not score anybody until somebody
 * wrote four rows by hand in the database. The rubric this platform PUBLISHES is the one
 * it should install.
 *
 * ── AND WHY IT REFUSES TO OVERWRITE ──────────────────────────────────────────
 *
 * {@see \AfricaGates\Services\JudgeRubricSeeder::install()} writes nothing when a global
 * rubric already exists. Migrations run on every deploy, and an operator who has retired a
 * criterion, reweighted one or added a fifth has made a decision about criteria that
 * BALLOTS ALREADY POINT AT. A migration that reasserted the shipped four would silently undo
 * that mid-cycle, with no record and no warning — which is the same class of harm as
 * deleting a scored criterion, arriving through the back door.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Services\JudgeRubricSeeder;
use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_judge_criteria')) {
    echo "  · gates_judge_criteria does not exist yet — skipping the default rubric\n";
    return;
}

if (JudgeRubricSeeder::installed()) {
    echo "  · a rubric is already in place — left untouched\n";
    return;
}

$r = JudgeRubricSeeder::install();

echo $r['ok']
    ? '  + ' . $r['installed'] . " criteria installed (Impact, Originality, Reach, Integrity — 25 each)\n"
    : '  ! ' . $r['message'] . "\n";
