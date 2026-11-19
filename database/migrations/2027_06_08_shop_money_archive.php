<?php
/**
 * A SEPARATE SHOP-MONEY PERMISSION, AND ARCHIVING PRODUCTS (shop upgrade phase 7, 18 Nov 2026).
 *
 * ── SHOP MONEY ──────────────────────────────────────────────────────────────
 *
 * Products, orders and codes all sat under the `content` section, so any content editor could
 * refund an order. Refunds, cancelling a paid order and the till now need `shop_money` as well,
 * a per-person flag granted on the admin's own account (Admin\Support\ShopMoney).
 *
 * NOBODY LOSES ACCESS. Before this migration, everybody who could write in the console AND open
 * the shop — the `superadmin`, `admin` and `editor` roles — could do all of it. Each of those
 * existing accounts is granted the flag here, once, so the day this deploys the same people can
 * do the same things; what changes is that a superadmin can now take it away from somebody, and
 * that an account created from now on starts WITHOUT it (except a superadmin, who always holds
 * it, so the console can never be left with nobody able to refund).
 *
 * The grant runs only while the column is being ADDED, so a re-run never re-grants a flag a
 * superadmin has since removed.
 *
 * ── ARCHIVE ─────────────────────────────────────────────────────────────────
 *
 * `gates_products.archived_at`: a product that has been ordered is archived, never deleted —
 * receipts, refunds and reports point at it (CLAUDE.md: a record that has been used is retired,
 * never deleted).
 *
 * Additive, idempotent, driver-aware. NEVER exit/die.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_admins') && !DB::schema()->hasColumn('gates_admins', 'shop_money')) {
    DB::statement('ALTER TABLE gates_admins ADD COLUMN shop_money ' . ($sqlite ? 'INTEGER' : 'TINYINT(1) NULL') . ' DEFAULT NULL');
    $n = DB::table('gates_admins')->whereIn('role', ['superadmin', 'admin', 'editor'])->update(['shop_money' => 1]);
    echo "  + gates_admins.shop_money (granted to {$n} existing superadmin/admin/editor account(s))\n";
} else {
    echo "  = gates_admins.shop_money already present\n";
}

if (DB::schema()->hasTable('gates_products') && !DB::schema()->hasColumn('gates_products', 'archived_at')) {
    DB::statement('ALTER TABLE gates_products ADD COLUMN archived_at ' . ($sqlite ? 'TEXT' : 'DATETIME NULL') . ' DEFAULT NULL');
    echo "  + gates_products.archived_at\n";
} else {
    echo "  = gates_products.archived_at already present\n";
}
echo "shop money + archive OK\n";
