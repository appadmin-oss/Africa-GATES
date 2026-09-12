<?php
/**
 * One link, one successor: UNIQUE (prev_hash) on gates_vote_snapshots.
 *
 * ── THE FAILURE THIS PREVENTS ────────────────────────────────────────────────
 *
 * gates_vote_snapshots is the platform's tamper-evident record of how standings
 * moved: each row's hash is sha256(prev_hash | payload), so altering, deleting or
 * reordering any historical row breaks every link after it. That property is only
 * worth anything if the chain is a LINE. Two rows sharing a prev_hash make it a
 * tree, and verify() — which walks one line — reports the whole archive from the
 * fork onward as tampered with.
 *
 * The old capture() read the tail hash once, outside any transaction, then
 * appended. So two overlapping runs both read the same link and both extended it.
 * This is not a remote possibility on this platform: CronGuard::acquire() fails
 * OPEN on purpose, its flock means nothing across two app servers, and
 * CycleMaterialiser's own docblock records that "two schedulers CAN overlap by
 * design". An operator running the task by hand during a scheduled tick is enough.
 *
 * What makes a fork the worst case rather than an inconvenience: nothing is lost
 * and nothing is wrong — every row is still an honest reading — but the alarm it
 * raises is permanent, is indistinguishable from real tampering, and can only be
 * cleared by rewriting history, which is the precise act the chain exists to make
 * impossible. Prevention is the only available fix, so it is enforced by the
 * database rather than by the care of the calling code.
 *
 * ── WHY A UNIQUE INDEX RATHER THAN JUST A TRANSACTION ────────────────────────
 *
 * capture() now also takes the tail FOR UPDATE inside a transaction, which is the
 * right thing on MySQL. It compiles to nothing on SQLite, and locks held by one
 * process say nothing about another host. The index holds everywhere and for every
 * writer, present and future: the second run's INSERT fails, its transaction rolls
 * back, and the run reports an error. Losing one six-hourly capture costs nothing.
 * A permanently unverifiable chain costs the thing the table is for.
 *
 * ── NULLs AND THE GENESIS ROW ────────────────────────────────────────────────
 *
 * Rows captured before 2026_06_14 added prev_hash have NULL there. Both MySQL and
 * SQLite treat each NULL in a unique index as distinct, so those legacy rows do not
 * collide with each other and no data has to be touched. The genesis row of an
 * actual chain stores '' (empty string, not NULL), and the index correctly permits
 * exactly one of those: a chain has one beginning.
 *
 * ── IF DUPLICATES ALREADY EXIST ──────────────────────────────────────────────
 *
 * The index is NOT forced. A fork that already happened is history, and history is
 * what this table refuses to edit — deleting the "extra" branch to make the
 * constraint fit would be tampering committed by the anti-tampering migration.
 * Instead it reports loudly and leaves the rows alone; the operator can see exactly
 * where the archive diverged.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_vote_snapshots')) {
    echo "no gates_vote_snapshots — skip\n";
    return;
}
if (!$schema->hasColumn('gates_vote_snapshots', 'prev_hash')) {
    echo "  ! gates_vote_snapshots.prev_hash absent — run 2026_06_14_snapshot_prev_hash first\n";
    return;
}

// ── Is the chain already forked? ─────────────────────────────────────────────
// NULL is excluded: those are pre-chain rows, not branches.
$forks = [];
try {
    $forks = DB::table('gates_vote_snapshots')
        ->selectRaw('prev_hash, COUNT(*) as n, MIN(id) as first_id')
        ->whereNotNull('prev_hash')
        ->groupBy('prev_hash')
        ->havingRaw('COUNT(*) > 1')
        ->get()->all();
} catch (\Throwable $e) {
    echo '  ! fork check skipped: ' . $e->getMessage() . "\n";
}

if ($forks) {
    echo "  *** THE SNAPSHOT CHAIN IS ALREADY FORKED *** " . count($forks) . " link(s) have more than one\n";
    echo "      successor, earliest at row id " . (int) ($forks[0]->first_id ?? 0) . ". Almost certainly two\n";
    echo "      concurrent captures, not tampering — but this migration will NOT delete rows to\n";
    echo "      make the constraint fit, because editing this table is the one thing it exists to\n";
    echo "      forbid. The unique index is left off; `bin/console standings:verify` will show you\n";
    echo "      where the record stops being a single line.\n";
    echo "snapshot chain unique index SKIPPED (pre-existing fork)\n";
    return;
}

// ── Add the index ────────────────────────────────────────────────────────────
try {
    if ($sqlite) {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_snap_prev ON gates_vote_snapshots(prev_hash)');
    } else {
        $has = DB::select("SHOW INDEX FROM gates_vote_snapshots WHERE Key_name = 'uq_snap_prev'");
        if (!$has) {
            DB::statement('ALTER TABLE gates_vote_snapshots ADD UNIQUE KEY uq_snap_prev (prev_hash)');
        }
    }
    echo "  + uq_snap_prev unique index in place — the chain can no longer fork\n";
} catch (\Throwable $e) {
    echo '  *** FAILED *** unique index: ' . $e->getMessage() . "\n";
}

echo "snapshot chain unique index OK\n";
