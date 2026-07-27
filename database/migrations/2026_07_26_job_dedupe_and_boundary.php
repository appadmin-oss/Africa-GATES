<?php
/**
 * Two additions the computed-phase design needs to be operable.
 *
 * 1. gates_jobs.dedupe_key + UNIQUE. The queue is the outbox for phase side
 *    effects, and the transactional-outbox pattern guarantees AT-LEAST-once
 *    delivery, never exactly-once: a relay that publishes and then crashes
 *    before marking the row done will republish. Without a dedupe key that
 *    means duplicate congratulations emails. The key lets a caller say "there
 *    is only ever one of these" — e.g. phase:{cycle}:{to_status}:{effect}.
 *    NULL stays unconstrained (MySQL and SQLite both allow many NULLs in a
 *    unique index), so every existing caller is unaffected.
 *
 * 2. gates_award_cycles.next_boundary_at + index. A computed phase cannot be
 *    indexed — MySQL and SQLite both reject non-deterministic expressions like
 *    NOW() in generated columns and functional indexes, so "which cycles are in
 *    voting?" is not expressible as one sargable predicate. That makes the
 *    materialised `status` column load-bearing for every report and admin list,
 *    and it means drift needs detecting. Storing the NEXT boundary each cycle is
 *    waiting on turns "which cycles need attention?" into an indexed range scan
 *    (`WHERE next_boundary_at <= ?`), so a divergence sweep costs one query and
 *    does not depend on someone happening to vote.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1. gates_jobs.dedupe_key ────────────────────────────────────────────────
if ($schema->hasTable('gates_jobs')) {
    if (!$schema->hasColumn('gates_jobs', 'dedupe_key')) {
        try {
            DB::statement('ALTER TABLE gates_jobs ADD COLUMN dedupe_key '
                . ($sqlite ? 'TEXT' : 'VARCHAR(191) NULL DEFAULT NULL'));
            echo "  + gates_jobs.dedupe_key added\n";
        } catch (\Throwable $e) {
            echo '  ! dedupe_key skipped: ' . $e->getMessage() . "\n";
        }
    } else {
        echo "  = gates_jobs.dedupe_key already present\n";
    }

    try {
        if ($sqlite) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_jobs_dedupe ON gates_jobs(dedupe_key)');
        } else {
            $has = DB::select("SHOW INDEX FROM gates_jobs WHERE Key_name = 'uq_jobs_dedupe'");
            if (!$has) {
                DB::statement('ALTER TABLE gates_jobs ADD UNIQUE KEY uq_jobs_dedupe (dedupe_key)');
            }
        }
        echo "  + uq_jobs_dedupe unique index in place\n";
    } catch (\Throwable $e) {
        echo '  ! uq_jobs_dedupe skipped: ' . $e->getMessage() . "\n";
    }
}

// ── 2. gates_award_cycles.next_boundary_at ──────────────────────────────────
if ($schema->hasTable('gates_award_cycles')) {
    if (!$schema->hasColumn('gates_award_cycles', 'next_boundary_at')) {
        try {
            DB::statement('ALTER TABLE gates_award_cycles ADD COLUMN next_boundary_at '
                . ($sqlite ? 'TEXT' : 'DATETIME NULL DEFAULT NULL'));
            echo "  + gates_award_cycles.next_boundary_at added\n";
        } catch (\Throwable $e) {
            echo '  ! next_boundary_at skipped: ' . $e->getMessage() . "\n";
        }
    } else {
        echo "  = gates_award_cycles.next_boundary_at already present\n";
    }

    try {
        if ($sqlite) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_cycles_next_boundary ON gates_award_cycles(next_boundary_at)');
        } else {
            $has = DB::select("SHOW INDEX FROM gates_award_cycles WHERE Key_name = 'idx_cycles_next_boundary'");
            if (!$has) {
                DB::statement('ALTER TABLE gates_award_cycles ADD INDEX idx_cycles_next_boundary (next_boundary_at)');
            }
        }
        echo "  + idx_cycles_next_boundary in place\n";
    } catch (\Throwable $e) {
        echo '  ! idx_cycles_next_boundary skipped: ' . $e->getMessage() . "\n";
    }

    // Seed it so the sweep is useful from the first run, rather than only after
    // the materialiser has next touched every cycle.
    try {
        $n = 0;
        foreach (DB::table('gates_award_cycles')->get() as $cy) {
            $at = \AfricaGates\Services\CyclePolicy::nextBoundaryFor($cy);
            DB::table('gates_award_cycles')->where('id', $cy->id)->update(['next_boundary_at' => $at]);
            $n++;
        }
        echo "  + seeded next_boundary_at on $n cycle(s)\n";
    } catch (\Throwable $e) {
        echo '  ! seeding skipped: ' . $e->getMessage() . "\n";
    }
}

echo "job dedupe + next boundary migration OK\n";
