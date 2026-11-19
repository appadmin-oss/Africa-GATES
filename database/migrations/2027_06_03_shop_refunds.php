<?php
/**
 * SHOP REFUNDS FROM THE CONSOLE, AND AN ORDER'S OWN HISTORY (shop upgrade phase 1, 18 Nov 2026).
 *
 * ── WHY A TABLE OF REFUNDS AND NOT A COLUMN ON THE ORDER ─────────────────────
 *
 * A refund is asynchronous and can be partial. Paystack answers `POST /refund` with "pending",
 * then sends `refund.pending` → `refund.processing` → `refund.processed` (or `refund.failed`)
 * over hours or days, and a buyer can be given back the delivery fee today and one shirt next
 * week. `gates_orders.refunded_at` can hold none of that: it is one moment, for one whole
 * refund. So each refund is a row, with its own state, and the order carries only the SUM of
 * the ones that settled (`refunded_naira`) — the number every report needs, kept beside the
 * charge so "total refunds never exceed the charge" is a comparison of two columns on one row.
 *
 * `provider_refund_id` is the gateway's id for the refund. It is what a webhook names, so it is
 * how a repeated delivery is recognised (UNIQUE per provider; NULL for a refund recorded by
 * hand, which both drivers allow any number of).
 *
 * ── AND WHY AN ORDER NEEDS A HISTORY ─────────────────────────────────────────
 *
 * "Who cancelled this, and was the money sent back?" had no answer: the audit log records an
 * action against an id, and nothing on the order screen read it. `gates_shop_order_events` is
 * the order's own timeline — refunds asked for and settled, cancellations, delivery moves,
 * pickups, and the staff notes an operator types — one row each, with who and when.
 *
 * Additive, idempotent, driver-aware; indexes through SchemaIndex. NEVER exit/die.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$big  = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!DB::schema()->hasTable('gates_shop_refunds')) {
    // `status` is a VARCHAR, never an ENUM: a value outside an ENUM is `Data truncated` on
    // MySQL and nothing at all on SQLite (CLAUDE.md, the stack). ShopRefunds::STATES is the one
    // list. `restock_json` is what goes back on the shelf WHEN the refund settles — not before,
    // because a refund the gateway refuses must not have put units back that never came home.
    DB::statement("CREATE TABLE gates_shop_refunds (id $id, order_id $big NOT NULL,
        amount_naira INT NOT NULL DEFAULT 0, method VARCHAR(12) NOT NULL DEFAULT 'gateway',
        status VARCHAR(12) NOT NULL DEFAULT 'pending', gateway_status VARCHAR(20) NULL,
        provider VARCHAR(30) NULL, provider_refund_id VARCHAR(80) NULL,
        reason VARCHAR(300) NULL, restock_json TEXT NULL, restocked TINYINT NOT NULL DEFAULT 0,
        failure VARCHAR(300) NULL, admin_id $big NULL,
        created_at DATETIME NOT NULL, updated_at DATETIME NULL, processed_at DATETIME NULL)$tail");
    echo "  + gates_shop_refunds\n";
} else {
    echo "  = gates_shop_refunds already present\n";
}
echo SchemaIndex::ensure('gates_shop_refunds', 'idx_shop_refund_order', ['order_id', 'status']) . "\n";
echo SchemaIndex::ensure('gates_shop_refunds', 'uq_shop_refund_gateway', ['provider', 'provider_refund_id'], true) . "\n";

if (!DB::schema()->hasTable('gates_shop_order_events')) {
    DB::statement("CREATE TABLE gates_shop_order_events (id $id, order_id $big NOT NULL,
        kind VARCHAR(20) NOT NULL, body VARCHAR(1000) NULL, admin_id $big NULL,
        created_at DATETIME NOT NULL)$tail");
    echo "  + gates_shop_order_events\n";
} else {
    echo "  = gates_shop_order_events already present\n";
}
echo SchemaIndex::ensure('gates_shop_order_events', 'idx_shop_event_order', ['order_id', 'id']) . "\n";

if (DB::schema()->hasTable('gates_orders')) {
    foreach ([
        // The settled refunds, summed. NULL on every order that predates this file reads as 0.
        'refunded_naira' => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
        // When a pending order was called off by a person (status 'cancelled'), and why.
        'cancelled_at'   => $sqlite ? 'TEXT' : 'DATETIME NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_orders', $col)) {
            DB::statement("ALTER TABLE gates_orders ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_orders.{$col}\n";
        } else {
            echo "  = gates_orders.{$col} already present\n";
        }
    }
}
echo "shop refunds OK\n";
