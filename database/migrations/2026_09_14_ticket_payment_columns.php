<?php
/**
 * The columns an event ticket needed before its payment could be treated like every other one.
 *
 * ── WHY THESE THREE ─────────────────────────────────────────────────────────
 *
 * `gates_donations` and `gates_orders` both got `gateway_txn_id` / `gateway_ref` when the
 * platform learned to find a payment by the number on the buyer's own Paystack receipt
 * (2026_08_26). `gates_event_registrations` did not — so a ticket buyer could paste their
 * reference, their Paystack transaction id, or their bank's number, and all three found
 * nothing on every lookup surface on the platform.
 *
 * `notified_at` is the claim that lets three racing callers send one email. The browser
 * callback, the gateway webhook and the reconciliation sweep can all now confirm a ticket, and
 * they routinely race — that is the design, not a flaw. Without a claim, a buyer whose payment
 * confirmed twice got told twice.
 *
 * ── AND WHY NOT A UNIQUE INDEX ON THE GATEWAY IDS ───────────────────────────
 *
 * Same reasoning as 2026_08_26: a gateway transaction id is unique per gateway, not across
 * them, and a re-attempted charge on some providers reuses a reference. A unique constraint
 * would turn a gateway's own retry into a failed confirmation on our side — the worst possible
 * place to find out the assumption was wrong.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_event_registrations')) {
    echo "  = gates_event_registrations not present — skipped\n";
} else {
    foreach ([
        'gateway_txn_id' => $sqlite ? 'TEXT' : 'VARCHAR(64) NULL',
        'gateway_ref'    => $sqlite ? 'TEXT' : 'VARCHAR(80) NULL',
        'notified_at'    => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_event_registrations', $col)) {
            DB::statement("ALTER TABLE gates_event_registrations ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_event_registrations.{$col} added\n";
        } else {
            echo "  = gates_event_registrations.{$col} already present\n";
        }
    }

    // Every ticket confirmed BEFORE this shipped was announced by the browser callback, which
    // was the only path that could confirm one at all. Stamping them keeps the queue from
    // re-sending a year of ticket emails the first time the sweep runs.
    try {
        $n = DB::table('gates_event_registrations')
            ->where('status', 'confirmed')->whereNull('notified_at')
            ->update(['notified_at' => DB::raw($sqlite ? "COALESCE(confirmed_at, created_at)"
                                                       : "COALESCE(confirmed_at, created_at)")]);
        echo "  = {$n} existing confirmed registration(s) marked as already notified\n";
    } catch (\Throwable $e) {
        echo "  ! could not backfill notified_at: " . $e->getMessage() . "\n";
    }
}

// The shop's receipt claim, for exactly the same reason: the webhook can now confirm an order,
// so the callback and the sweep are no longer the only senders.
if (!DB::schema()->hasTable('gates_orders')) {
    echo "  = gates_orders not present — skipped\n";
} else {
    foreach ([
        'receipt_sent_at' => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        'refunded_at'     => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        'refund_note'     => $sqlite ? 'TEXT' : 'VARCHAR(300) NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_orders', $col)) {
            DB::statement("ALTER TABLE gates_orders ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_orders.{$col} added\n";
        } else {
            echo "  = gates_orders.{$col} already present\n";
        }
    }

    try {
        $n = DB::table('gates_orders')
            ->where('status', 'paid')->whereNull('receipt_sent_at')
            ->update(['receipt_sent_at' => DB::raw("COALESCE(paid_at, created_at)")]);
        echo "  = {$n} existing paid order(s) marked as already receipted\n";
    } catch (\Throwable $e) {
        echo "  ! could not backfill receipt_sent_at: " . $e->getMessage() . "\n";
    }
}

echo "ticket payment columns OK\n";
