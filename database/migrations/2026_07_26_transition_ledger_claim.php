<?php
/**
 * Turn gates_cycle_transitions from an audit LOG into an idempotency LEDGER.
 *
 * Adds UNIQUE (cycle_id, to_status) plus boundary_at / observed_at / notify,
 * and BACKFILLS a suppressed row for every phase each existing cycle has
 * already passed through.
 *
 * WHY THE UNIQUE KEY. The table only had a non-unique KEY on cycle_id, so
 * nothing stopped a phase's side effects (winner promotion, congratulations
 * mail, webhooks) firing twice. That matters because CronGuard::acquire()
 * deliberately fails OPEN — it returns true when it cannot take the lock, so
 * concurrent runs are possible by design. With the unique key the INSERT
 * itself becomes the claim: exactly one caller inserts, everyone else conflicts
 * and does nothing. Same primitive this codebase already uses for vote
 * idempotency (2026_06_15_idempotency_unique.php), and it works identically on
 * MySQL and SQLite.
 *
 * WHY boundary_at AND observed_at. A computed phase changes with the passage of
 * time, so the transition is not a write and leaves no natural audit trail.
 * Recording both the DECLARED boundary (the date that caused it) and when the
 * system first NOTICED restores the trail — and is strictly better than the old
 * one, which could not distinguish "closed on time" from "closed on time but
 * nobody looked for three weeks".
 *
 * WHY THE BACKFILL IS NOT OPTIONAL. The first run after this deploys would
 * otherwise treat every historical transition as brand new and unclaimed — and
 * fire its side effects. A platform with past cycles would email every previous
 * winner again. Backfilling with notify = 0 BEFORE the advancer ever runs is
 * what makes the deploy safe; it must happen in the same migration, not after.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_cycle_transitions')) {
    echo "no gates_cycle_transitions — skip\n";
    return;
}

// ── 1. Columns ───────────────────────────────────────────────────────────────
$cols = [
    'boundary_at' => $sqlite ? 'TEXT'      : 'DATETIME NULL DEFAULT NULL',
    'observed_at' => $sqlite ? 'TEXT'      : 'DATETIME NULL DEFAULT NULL',
    'notify'      => $sqlite ? 'INTEGER NOT NULL DEFAULT 1' : 'TINYINT(1) NOT NULL DEFAULT 1',
];
foreach ($cols as $col => $type) {
    if ($schema->hasColumn('gates_cycle_transitions', $col)) {
        echo "  = gates_cycle_transitions.$col already present\n";
        continue;
    }
    try {
        DB::statement("ALTER TABLE gates_cycle_transitions ADD COLUMN $col $type");
        echo "  + gates_cycle_transitions.$col added\n";
    } catch (\Throwable $e) {
        echo "  ! $col skipped: " . $e->getMessage() . "\n";
    }
}

// ── 2. Backfill BEFORE the unique key, so duplicates can be collapsed ────────
// Every cycle has already passed through every phase below its current one.
// Record those as claimed-and-suppressed so the advancer never re-fires them.
$PHASES = ['upcoming', 'nominations', 'shortlisting', 'voting', 'judging', 'results', 'archived'];
$ORD    = array_flip($PHASES);
$now    = date('Y-m-d H:i:s');
$added  = 0;

try {
    foreach (DB::table('gates_award_cycles')->get() as $cy) {
        $curOrd = $ORD[(string) $cy->status] ?? 0;
        if ($curOrd === 0) continue;

        $existing = DB::table('gates_cycle_transitions')
            ->where('cycle_id', $cy->id)->pluck('to_status')->all();

        for ($o = 1; $o <= $curOrd; $o++) {
            $to = $PHASES[$o];
            if (in_array($to, $existing, true)) continue;
            try {
                DB::table('gates_cycle_transitions')->insert([
                    'cycle_id'    => $cy->id,
                    'from_status' => $PHASES[$o - 1],
                    'to_status'   => $to,
                    'reason'      => 'backfill: pre-existing phase, notifications suppressed',
                    'actor'       => 'migration',
                    'notify'      => 0,
                    'observed_at' => $now,
                    'created_at'  => $now,
                ]);
                $added++;
            } catch (\Throwable $e) { /* a duplicate is exactly what we want to skip */ }
        }
    }
    echo "  + backfilled $added historical transition(s) with notify = 0\n";
} catch (\Throwable $e) {
    echo '  ! backfill skipped: ' . $e->getMessage() . "\n";
}

// ── 3. Collapse any pre-existing duplicates, then add the UNIQUE key ─────────
try {
    $dupes = DB::table('gates_cycle_transitions')
        ->selectRaw('cycle_id, to_status, MIN(id) as keep_id, COUNT(*) as n')
        ->groupBy('cycle_id', 'to_status')
        ->havingRaw('COUNT(*) > 1')->get();
    $removed = 0;
    foreach ($dupes as $d) {
        $removed += (int) DB::table('gates_cycle_transitions')
            ->where('cycle_id', $d->cycle_id)->where('to_status', $d->to_status)
            ->where('id', '>', $d->keep_id)->delete();
    }
    if ($removed) echo "  + collapsed $removed duplicate transition row(s), keeping the earliest\n";
} catch (\Throwable $e) {
    echo '  ! duplicate collapse skipped: ' . $e->getMessage() . "\n";
}

try {
    if ($sqlite) {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_cyctrans_phase ON gates_cycle_transitions(cycle_id, to_status)');
    } else {
        $has = DB::select("SHOW INDEX FROM gates_cycle_transitions WHERE Key_name = 'uq_cyctrans_phase'");
        if (!$has) {
            DB::statement('ALTER TABLE gates_cycle_transitions ADD UNIQUE KEY uq_cyctrans_phase (cycle_id, to_status)');
        }
    }
    echo "  + uq_cyctrans_phase unique index in place\n";
} catch (\Throwable $e) {
    echo '  *** FAILED *** unique index: ' . $e->getMessage() . "\n";
}

echo "transition ledger claim migration OK\n";
