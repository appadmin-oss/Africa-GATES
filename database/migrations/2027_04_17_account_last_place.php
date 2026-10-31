<?php
/**
 * Sign-in takes you to your work (owner, 29 Oct 2026: one account, roles switched from the
 * account menu, no roles page).
 *
 *   gates_users.last_place   the role this account last switched to — member, volunteer,
 *                            admin, org or judge. A sign-in with nowhere else to go lands
 *                            there (Account\Roles::landing), as Slack and Shopify do, instead
 *                            of on a page that asks which hat you are wearing today. Read only
 *                            as a preference: a role that has since been removed or switched
 *                            off is skipped, never trusted.
 *
 * VARCHAR, never ENUM. Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if ($schema->hasTable('gates_users') && !$schema->hasColumn('gates_users', 'last_place')) {
    DB::statement('ALTER TABLE gates_users ADD COLUMN last_place ' . ($sqlite ? 'TEXT NULL' : 'VARCHAR(16) NULL DEFAULT NULL'));
    echo "  + gates_users.last_place\n";
}
