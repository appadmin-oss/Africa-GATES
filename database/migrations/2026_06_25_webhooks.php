<?php
/** Outbound webhooks — gates_webhooks + gates_webhook_deliveries. Idempotent; sqlite + mysql. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

$webhooks = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_webhooks (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         url TEXT NOT NULL,
         secret TEXT NOT NULL,
         events TEXT NOT NULL DEFAULT '*',
         description TEXT,
         is_active INTEGER NOT NULL DEFAULT 1,
         last_status INTEGER,
         last_event_at TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_webhooks (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         url VARCHAR(500) NOT NULL,
         secret VARCHAR(120) NOT NULL,
         events TEXT NOT NULL,
         description VARCHAR(200) DEFAULT NULL,
         is_active TINYINT(1) NOT NULL DEFAULT 1,
         last_status INT DEFAULT NULL,
         last_event_at TIMESTAMP NULL DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_webhook_active (is_active)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$deliveries = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_webhook_deliveries (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         webhook_id INTEGER NOT NULL,
         event TEXT NOT NULL,
         status_code INTEGER,
         ok INTEGER NOT NULL DEFAULT 0,
         error TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_webhook_deliveries (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         webhook_id BIGINT UNSIGNED NOT NULL,
         event VARCHAR(60) NOT NULL,
         status_code INT DEFAULT NULL,
         ok TINYINT(1) NOT NULL DEFAULT 0,
         error VARCHAR(300) DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_delivery_hook (webhook_id, created_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

DB::connection()->getPdo()->exec($webhooks);
DB::connection()->getPdo()->exec($deliveries);

echo DB::schema()->hasTable('gates_webhooks')            ? "gates_webhooks OK\n"            : "*** webhooks FAILED ***\n";
echo DB::schema()->hasTable('gates_webhook_deliveries')  ? "gates_webhook_deliveries OK\n"  : "*** deliveries FAILED ***\n";
