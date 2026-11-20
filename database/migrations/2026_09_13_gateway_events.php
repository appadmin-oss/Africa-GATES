<?php
/**
 * Every webhook a payment gateway has sent us, and what our handler decided to do with it.
 *
 * ── THE QUESTION THIS TABLE EXISTS TO ANSWER ────────────────────────────────
 *
 * "I paid and nothing happened." From this side that has always been unanswerable. There is a
 * table for the webhooks this platform SENDS (`gates_webhook_deliveries`) and, until now,
 * nothing at all for the ones it RECEIVES — which is the direction money arrives from.
 *
 * The gateway dashboard shows its deliveries and the HTTP codes they got back. It cannot show
 * the half that was actually going wrong here: an `AFG-SHP-…` or `AFG-EVT-…` reference used to
 * be acknowledged with a 200 and discarded, because the handler only ever looked in
 * `gates_donations`. A 200 is indistinguishable from success at the far end, so Paystack's
 * dashboard showed a clean run of successful deliveries for payments this platform had thrown
 * away.
 *
 * ── AND WHY THE PAYLOAD IS NOT STORED ───────────────────────────────────────
 *
 * It carries the customer's email, their card's last four digits and their bank; it is by far
 * the largest thing in the request; and none of it is needed for the question above. Paystack
 * keeps the payload for us. What was missing was OUR DECISION, which is one short word.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_gateway_events')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_gateway_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            event TEXT NOT NULL,
            reference TEXT NULL,
            stream TEXT NULL,
            domain TEXT NULL,
            outcome TEXT NOT NULL,
            note TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_gateway_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(20) NOT NULL,
            -- The gateway's own event name, verbatim: 'charge.success', 'refund.processed',
            -- 'charge.dispute.create'. Stored unmapped so a gateway ADDING an event is
            -- visible here rather than silently becoming 'ignored' with nothing to look at.
            event VARCHAR(60) NOT NULL,
            -- Our reference, when the payload carried one. NULL for the events that do not
            -- name a transaction at all — an expiring-cards batch, a customer validation.
            reference VARCHAR(80) NULL,
            -- 'shop' | 'events' | 'votes', derived from the reference prefix. This is the
            -- column that would have made the original bug obvious in one query.
            stream VARCHAR(20) NULL,
            -- 'test' or 'live'. Paystack allows ONE webhook URL per account and both modes
            -- share it, so this is the only thing that tells a rehearsal from real money.
            domain VARCHAR(10) NULL,
            -- What the handler DID. See AfricaGates\\Services\\GatewayEventLog::OUTCOMES.
            outcome VARCHAR(20) NOT NULL,
            note VARCHAR(300) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            -- Answering \"what happened to THIS payment\" is the common read.
            KEY idx_gwev_reference (reference),
            -- And \"what has been going wrong lately\" is the other one.
            KEY idx_gwev_outcome (outcome, created_at),
            KEY idx_gwev_when (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_gateway_events created\n";
} else {
    echo "  = gates_gateway_events already present\n";
}

if ($sqlite) {
    foreach ([
        'idx_gwev_reference' => 'CREATE INDEX IF NOT EXISTS idx_gwev_reference ON gates_gateway_events (reference)',
        'idx_gwev_outcome'   => 'CREATE INDEX IF NOT EXISTS idx_gwev_outcome ON gates_gateway_events (outcome, created_at)',
        'idx_gwev_when'      => 'CREATE INDEX IF NOT EXISTS idx_gwev_when ON gates_gateway_events (created_at)',
    ] as $name => $sql) {
        try {
            DB::statement($sql);
            echo "  = {$name} ensured\n";
        } catch (\Throwable $e) {
            echo "  ! {$name}: " . $e->getMessage() . "\n";
        }
    }
}

echo "gateway events OK\n";
