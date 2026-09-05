<?php
/** Shop — gates_products + gates_orders. Idempotent; sqlite + mysql. */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

$products = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_products (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         slug TEXT NOT NULL UNIQUE,
         name TEXT NOT NULL,
         category TEXT NOT NULL DEFAULT 'Apparel',
         description TEXT,
         price_naira INTEGER NOT NULL DEFAULT 0,
         cover_path TEXT,
         tag TEXT,
         stock INTEGER,
         is_active INTEGER NOT NULL DEFAULT 1,
         sort_order INTEGER NOT NULL DEFAULT 0,
         delivery_regions TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_products (
         id INT UNSIGNED NOT NULL AUTO_INCREMENT,
         slug VARCHAR(160) NOT NULL,
         name VARCHAR(200) NOT NULL,
         category VARCHAR(80) NOT NULL DEFAULT 'Apparel',
         description TEXT,
         price_naira INT UNSIGNED NOT NULL DEFAULT 0,
         cover_path VARCHAR(400) DEFAULT NULL,
         tag VARCHAR(40) DEFAULT NULL,
         stock INT DEFAULT NULL,
         is_active TINYINT(1) NOT NULL DEFAULT 1,
         sort_order INT NOT NULL DEFAULT 0,
         delivery_regions TEXT DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         UNIQUE KEY uq_product_slug (slug),
         KEY idx_product_active (is_active, sort_order)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$orders = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_orders (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         reference TEXT NOT NULL UNIQUE,
         email TEXT NOT NULL,
         name TEXT NOT NULL,
         phone TEXT,
         address TEXT,
         items_json TEXT NOT NULL,
         subtotal_naira INTEGER NOT NULL DEFAULT 0,
         status TEXT NOT NULL DEFAULT 'pending',
         provider TEXT,
         provider_ref TEXT,
         ip_hash TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         paid_at TEXT
       )"
    : "CREATE TABLE IF NOT EXISTS gates_orders (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         reference VARCHAR(64) NOT NULL,
         email VARCHAR(190) NOT NULL,
         name VARCHAR(160) NOT NULL,
         phone VARCHAR(40) DEFAULT NULL,
         address TEXT,
         items_json TEXT NOT NULL,
         subtotal_naira INT UNSIGNED NOT NULL DEFAULT 0,
         status VARCHAR(20) NOT NULL DEFAULT 'pending',
         provider VARCHAR(30) DEFAULT NULL,
         provider_ref VARCHAR(120) DEFAULT NULL,
         ip_hash VARCHAR(64) DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         paid_at TIMESTAMP NULL DEFAULT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_order_ref (reference),
         KEY idx_order_status (status)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

DB::connection()->getPdo()->exec($products);
DB::connection()->getPdo()->exec($orders);

echo DB::schema()->hasTable('gates_products') ? "gates_products OK\n" : "*** products FAILED ***\n";
echo DB::schema()->hasTable('gates_orders')   ? "gates_orders OK\n"   : "*** orders FAILED ***\n";
