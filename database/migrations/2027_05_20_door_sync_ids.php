<?php
/**
 * The offline door's scan ids, so a flush sent twice records once.
 *
 * ── THE FAULT ────────────────────────────────────────────────────────────────
 *
 * door.twig gives every queued scan a client id and says why: "so a flush that half-succeeds
 * or is retried after a timeout cannot record the same person twice". DoorController::sync()
 * read the id and echoed it back, and nothing ever compared it. A single-seat ticket hid
 * that — the second admission is refused as "already in" — but a four-seat ticket scanned
 * for two of its party admits two MORE when the same flush is resent after its response was
 * lost on a bad line, which is exactly the network a gate flushes on. DoorOfflineTest's
 * replay case used a one-seat ticket, so it could not see it. (AUDIT-AND-PLAN EV-15.)
 *
 * ── THE FIX ──────────────────────────────────────────────────────────────────
 *
 * One row per (pass, client id), claimed by the UNIQUE key before the scan is applied. The
 * loser of a race, or a replay, reads the verdict the first one recorded and answers with
 * it, so the gate retires the item without a second admission.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_door_sync_ids')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_door_sync_ids (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pass_id INTEGER NOT NULL,
            client_id TEXT NOT NULL,
            verdict TEXT NULL,
            title TEXT NULL,
            name TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_door_sync_ids (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pass_id BIGINT UNSIGNED NOT NULL,
            client_id VARCHAR(40) NOT NULL,
            -- VARCHAR, not ENUM: the verdicts are checkIn()'s words and may grow.
            verdict VARCHAR(20) NULL,
            title VARCHAR(160) NULL,
            name VARCHAR(160) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_door_sync_ids created\n";
} else {
    echo "  = gates_door_sync_ids already present\n";
}

echo SchemaIndex::ensure('gates_door_sync_ids', 'uq_door_sync_client', ['pass_id', 'client_id'], true) . "\n";
echo SchemaIndex::ensure('gates_door_sync_ids', 'idx_door_sync_created', ['created_at']) . "\n";

echo "door sync ids OK\n";
