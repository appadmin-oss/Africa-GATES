<?php
/**
 * Letting a group ticket arrive in more than one piece.
 *
 * ── THE CASE THIS COULD NOT HANDLE ──────────────────────────────────────────
 *
 * Ada buys four seats. Two of her party arrive at seven, two more at half past eight.
 * `checked_in_at` is one nullable timestamp, so the first scan admitted the whole ticket
 * and the second said "Already checked in — Ada Obi was admitted at 19:00", leaving the
 * steward to let two strangers past on a ticket the screen had just called used. The
 * verdict even printed "4 seats on this ticket" while having no way to admit them
 * separately, which is the system describing a problem it declined to solve.
 *
 * ── THE BACKFILL IS THE DANGEROUS PART ──────────────────────────────────────
 *
 * `EventArrivals::inTheRoom()` moves from "sum quantity where checked_in_at is set" to
 * "sum checked_in_seats". Every registration already admitted has a NULL/0 in the new
 * column, so without this backfill every past event's headcount silently becomes zero the
 * moment that reader changes — and a headcount is one of the few numbers here somebody
 * might have to show a fire officer.
 *
 * Backfilled to `quantity` rather than 1, because that is what those rows meant: before
 * this column existed, admitting a ticket admitted all of its seats. That is the only
 * honest reading, and it is not a guess.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_event_registrations')) {
    echo "  = gates_event_registrations absent — nothing to do\n";
    return;
}

if (!DB::schema()->hasColumn('gates_event_registrations', 'checked_in_seats')) {
    // SMALLINT UNSIGNED: a ticket's own `quantity` is the same width, and a seat count
    // wider than the seats it counts would be a column disagreeing with itself.
    DB::statement('ALTER TABLE gates_event_registrations ADD COLUMN checked_in_seats '
        . ($sqlite ? 'INTEGER' : 'SMALLINT UNSIGNED') . ' NOT NULL DEFAULT 0');
    echo "  + gates_event_registrations.checked_in_seats added\n";
} else {
    echo "  = gates_event_registrations.checked_in_seats already present\n";
}

// Runs on the first pass AND on any later one that finds admitted rows still at zero —
// a database restored from a backup taken between the two statements would otherwise
// carry a permanently empty headcount with nothing to notice it.
try {
    $n = DB::table('gates_event_registrations')
        ->whereNotNull('checked_in_at')
        ->where(fn ($q) => $q->whereNull('checked_in_seats')->orWhere('checked_in_seats', 0))
        ->update(['checked_in_seats' => DB::raw('CASE WHEN COALESCE(quantity, 1) < 1 THEN 1 '
                                              . 'ELSE COALESCE(quantity, 1) END')]);
    echo $n > 0
        ? "  ~ backfilled {$n} admitted ticket(s) to their full seat count\n"
        : "  = nothing to backfill\n";
} catch (\Throwable $e) {
    echo '  ! backfill skipped: ' . $e->getMessage() . "\n";
}

echo "check-in seats OK\n";
