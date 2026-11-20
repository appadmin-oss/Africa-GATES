<?php
/**
 * When a payment actually confirmed, and how hard we have tried to give it back.
 *
 * ── `confirmed_at` ───────────────────────────────────────────────────────────
 *
 * The platform has never recorded the moment money arrived. It records when a
 * checkout STARTED (`created_at`) and derives everything else from a status
 * column, so "when did this confirm" has been unanswerable — by the finance page,
 * by support, and, worse, by the code that decides refunds.
 *
 * {@see RefundService} documents its grace window as "do not refund a payment that
 * CONFIRMED within the last hour", and then measures it on `created_at`, because
 * that is the only timestamp there was. Those are different questions. An order
 * created at 23:00 and confirmed at 23:59 got one minute of grace, not sixty.
 *
 * ── `refund_attempts` / `refund_retry_after` ─────────────────────────────────
 *
 * A gateway that REFUSES a refund releases the claim, on purpose: a definite
 * refusal is the one outcome where we know for certain no money moved, so trying
 * again is safe. But "safe to retry" was implemented as "retried on the very next
 * sweep", and maintenance ticks every fourteen minutes — so a refusal became about
 * a hundred gateway calls and a hundred identical admin emails a day, each one
 * saying the refund would not be retried automatically.
 *
 * Retrying is still right. The commonest refusal here is an insufficient merchant
 * settlement balance, which clears on its own within a day. What was missing is
 * the pace: these two columns turn an immediate loop into 1h → 6h → 24h and then
 * a stop, with a person told once at each end rather than a hundred times in
 * between.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

foreach ([
    'confirmed_at'       => $sqlite ? 'TEXT'    : 'TIMESTAMP NULL',
    'refund_attempts'    => $sqlite ? 'INTEGER' : 'INT UNSIGNED NOT NULL',
    'refund_retry_after' => $sqlite ? 'TEXT'    : 'TIMESTAMP NULL',
] as $col => $type) {
    if (DB::schema()->hasColumn('gates_donations', $col)) {
        echo "  = gates_donations.{$col} already present\n";
        continue;
    }
    $default = $col === 'refund_attempts' ? 'DEFAULT 0' : 'DEFAULT NULL';
    DB::statement("ALTER TABLE gates_donations ADD COLUMN {$col} {$type} {$default}");
    echo "  + gates_donations.{$col} added\n";
}

/*
 * Backfill `confirmed_at` for orders that confirmed before the column existed.
 *
 * `created_at` is the only evidence available for those rows, and it is a LOWER
 * bound: a payment cannot confirm before its checkout started. Using it makes the
 * grace window behave exactly as it did before this migration for historical rows
 * — no worse — while every new confirmation gets the real moment.
 *
 * Deliberately NOT left null: null would read as "never confirmed" to anything
 * that checks the column, and a confirmed order that looks unconfirmed is how a
 * receipt stops being sent.
 */
try {
    $n = DB::table('gates_donations')
        ->where('status', 'confirmed')
        ->whereNull('confirmed_at')
        ->update(['confirmed_at' => DB::raw('created_at')]);
    echo "  = confirmed_at backfilled from created_at on {$n} historical order(s)\n";
} catch (\Throwable $e) {
    echo "  ! backfill skipped: " . $e->getMessage() . "\n";
}

// The sweep asks for "owed, unclaimed, and not in a backoff window" every tick.
try {
    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_donation_refund_retry
                       ON gates_donations(refund_state, refund_retry_after)');
    } else {
        $has = DB::select("SHOW INDEX FROM gates_donations WHERE Key_name = 'idx_donation_refund_retry'");
        if (!$has) {
            DB::statement('CREATE INDEX idx_donation_refund_retry
                           ON gates_donations(refund_state, refund_retry_after)');
        }
    }
    echo "  = idx_donation_refund_retry ready\n";
} catch (\Throwable $e) {
    echo "  ! index skipped: " . $e->getMessage() . "\n";
}

echo "refund pacing OK\n";
