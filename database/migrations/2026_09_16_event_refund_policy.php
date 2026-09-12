<?php
/**
 * A refund policy the platform can actually apply, and somewhere to record what it paid back.
 *
 * ── WHAT WAS THERE, AND WHY IT WAS NOT ENOUGH ───────────────────────────────
 *
 * `gates_site_events.refund_policy` has existed since ticketing shipped. It is FREE TEXT,
 * shown to a buyer before they pay. So an organiser writes "full refund up to 7 days before"
 * and nothing on the platform has ever known what that sentence means — exactly the shape of
 * the cooling-off promise that lived in an email while nothing enforced it.
 *
 * The prose stays: it is the human explanation and it can say things a rule cannot. What is
 * added beside it is the machine-readable version, so an attendee can be told the actual
 * figure BEFORE they commit rather than after somebody reads their email.
 *
 * ── SELF-CANCEL IS OFF UNTIL AN ORGANISER TURNS IT ON ───────────────────────
 *
 * `self_cancel` defaults to 0, and that is the whole of the backwards-compatibility story.
 * It is somebody else's event and somebody else's money: a platform that started letting
 * attendees cancel and refund themselves the day it deployed would be making a commercial
 * decision on behalf of every organiser who never opened the screen.
 *
 * ── AND WHY THE REFUND STATE IS FOUR COLUMNS ────────────────────────────────
 *
 * A gateway refund is not an event, it is a process: submitted, then processed hours later, or
 * failed. Storing only "refunded: yes/no" would make a pending refund indistinguishable from a
 * finished one, which is precisely the question an attendee emails about on day three. So the
 * status, the amount actually asked for, the gateway's own id and the settle time are separate.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── the policy, on the event ────────────────────────────────────────────────
if (!DB::schema()->hasTable('gates_site_events')) {
    echo "  = gates_site_events not present — skipped\n";
} else {
    foreach ([
        // 0 = attendees cannot cancel themselves. The default, deliberately.
        'self_cancel'         => $sqlite ? 'INTEGER' : 'TINYINT(1) NOT NULL DEFAULT 0',
        // 'none' | 'full' | 'partial'. What a cancellation inside the window pays back.
        'refund_mode'         => $sqlite ? 'TEXT' : "VARCHAR(10) NULL",
        // For 'partial'. A whole percent, 1–100.
        'refund_percent'      => $sqlite ? 'INTEGER' : 'SMALLINT UNSIGNED NULL',
        // Hours BEFORE the event start after which nothing is refunded. 0 = right up to it.
        'refund_cutoff_hours' => $sqlite ? 'INTEGER' : 'SMALLINT UNSIGNED NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_site_events', $col)) {
            $default = $col === 'self_cancel' && !$sqlite ? '' : ' DEFAULT NULL';
            DB::statement("ALTER TABLE gates_site_events ADD COLUMN {$col} {$type}"
                . ($sqlite ? ' DEFAULT NULL' : $default));
            echo "  + gates_site_events.{$col} added\n";
        } else {
            echo "  = gates_site_events.{$col} already present\n";
        }
    }
    // SQLite cannot express NOT NULL DEFAULT 0 on ADD COLUMN here, so the flag is normalised
    // after the fact. An unset flag and a 0 must mean the same thing — off.
    try { DB::table('gates_site_events')->whereNull('self_cancel')->update(['self_cancel' => 0]); }
    catch (\Throwable) {}
}

// ── what was actually paid back, on the registration ────────────────────────
if (!DB::schema()->hasTable('gates_event_registrations')) {
    echo "  = gates_event_registrations not present — skipped\n";
} else {
    foreach ([
        // '' | 'pending' | 'refunded' | 'failed' | 'none'. A refund is a process, not a flag.
        'refund_status' => $sqlite ? 'TEXT' : 'VARCHAR(12) NULL',
        // What we ASKED the gateway for. Not derived from the ticket price later: a partial
        // policy can change after somebody has cancelled under the old one.
        'refund_naira'  => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
        // The gateway's own refund id, so a disputed "I never got it" is answerable.
        'refund_ref'    => $sqlite ? 'TEXT' : 'VARCHAR(80) NULL',
        'refunded_at'   => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        // Who ended it: 'attendee' or 'organiser'. The two are different conversations.
        'cancelled_by'  => $sqlite ? 'TEXT' : 'VARCHAR(20) NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_event_registrations', $col)) {
            DB::statement("ALTER TABLE gates_event_registrations ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_event_registrations.{$col} added\n";
        } else {
            echo "  = gates_event_registrations.{$col} already present\n";
        }
    }

    if ($sqlite) {
        try {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_reg_refund ON gates_event_registrations (refund_status)');
            echo "  = idx_reg_refund ensured\n";
        } catch (\Throwable $e) { echo "  ! idx_reg_refund: " . $e->getMessage() . "\n"; }
    } else {
        try {
            DB::statement('CREATE INDEX idx_reg_refund ON gates_event_registrations (refund_status)');
            echo "  + idx_reg_refund created\n";
        } catch (\Throwable) { echo "  = idx_reg_refund already present\n"; }
    }
}

echo "event refund policy OK\n";
