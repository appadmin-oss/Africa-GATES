<?php
/**
 * A volunteer can say a task is finished, and an accepted task is counted once (audit of the
 * volunteer tasks, 2 Nov 2026; docs/audits/2026-11-02-volunteer-tasks.md).
 *
 *   gates_vol_task_takes.handed_in_at   new, nullable: when the volunteer handed the work in.
 *                                       Until now only staff could end a take (Tasks::accept),
 *                                       so a volunteer who finished on Monday and was accepted
 *                                       on Friday was "late" for four days on Effect, and
 *                                       measured on time by the day staff got round to it.
 *                                       The take's status gains `handed_in` (VARCHAR(16); the
 *                                       column was never an ENUM, so no definition changes).
 *
 *   gates_vol_hours  UNIQUE (ref_kind, ref_id)   2027_04_12 created `idx_volhours_ref` as a
 *                                       PLAIN index under a comment saying "never counted
 *                                       twice", while Hours::fromTask() checked-then-inserted
 *                                       and Tasks::accept() had no status predicate on its
 *                                       update — two presses, two rows, twice the hours and
 *                                       twice the reward points. A REPAIR, not a corrected
 *                                       definition: every database that ran 2027_04_12 holds
 *                                       the plain index, and only this file reaches them.
 *
 * DUPLICATES ARE RESOLVED BEFORE THE KEY, keeping the EARLIEST row of each (ref_kind, ref_id).
 * A later copy is not deleted: it is detached (ref_kind/ref_id NULL, which a UNIQUE allows any
 * number of on both engines) and declined with a reason the volunteer reads on their Hours page.
 * Declining rather than deleting is what takes the copy's reward points back —
 * Rewards::sync() reverses any credited row that is no longer verified, and a deleted row would
 * simply never be looked at again, leaving the double payment in the ledger.
 *
 * Indexes through SchemaIndex (never CREATE INDEX IF NOT EXISTS). Idempotent. NEVER exit/die.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if ($schema->hasTable('gates_vol_task_takes')) {
    if (!$schema->hasColumn('gates_vol_task_takes', 'handed_in_at')) {
        DB::statement('ALTER TABLE gates_vol_task_takes ADD COLUMN handed_in_at ' . ($sqlite ? 'TEXT' : 'DATETIME') . ' NULL');
        echo "  + gates_vol_task_takes.handed_in_at\n";
    }
    echo SchemaIndex::ensure('gates_vol_task_takes', 'idx_voltake_status', ['status', 'handed_in_at']), "\n";
}

if ($schema->hasTable('gates_vol_hours')) {
    $dups = DB::table('gates_vol_hours')->whereNotNull('ref_kind')->whereNotNull('ref_id')
        ->groupBy('ref_kind', 'ref_id')->havingRaw('COUNT(*) > 1')
        ->selectRaw('ref_kind, ref_id, MIN(id) AS keep_id')->get();
    $n = 0;
    foreach ($dups as $d) {
        $n += DB::table('gates_vol_hours')->where('ref_kind', $d->ref_kind)->where('ref_id', $d->ref_id)->where('id', '!=', (int) $d->keep_id)
            ->update(['ref_kind' => null, 'ref_id' => null, 'status' => 'declined',
                      'reason' => 'Counted twice. The first record of this work stands; this copy does not count.']);
    }
    if ($n) echo "  + {$n} duplicate hours rows declined, the earliest of each kept\n";
    if (!SchemaIndex::exists('gates_vol_hours', 'uq_volhours_ref')) {
        $made = SchemaIndex::ensure('gates_vol_hours', 'uq_volhours_ref', ['ref_kind', 'ref_id'], true);
        echo $made, "\n";
        // The plain index is dropped only once the unique one stands, so a failure leaves the
        // table no worse than it was and the next run tries again.
        if (SchemaIndex::exists('gates_vol_hours', 'uq_volhours_ref')) echo SchemaIndex::drop('gates_vol_hours', 'idx_volhours_ref'), "\n";
    } else {
        echo SchemaIndex::drop('gates_vol_hours', 'idx_volhours_ref'), "\n";
    }
}
