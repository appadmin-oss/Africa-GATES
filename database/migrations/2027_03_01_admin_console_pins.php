<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE ADMIN'S OWN CONSOLE — pinned views, and whether the sidebar is open.
 *
 * The admin console handoff (README §2.1) pins a view to the sidebar "per user", and its
 * sidebar toggle "persists". Both are stored HERE, against the admin, rather than in the
 * browser: a pin is somebody's working set and should follow them to the laptop they
 * use at the event, and browser storage would be a new key on the cookie policy for a
 * convenience the server can hold for free.
 *
 * `href` is VARCHAR(255) with a UNIQUE per admin — a path and a query string, never a
 * full URL — so the same view cannot be pinned twice. 255 rather than longer because
 * a utf8mb4 unique key is four bytes a character and this one has to fit an index on
 * every MySQL this host might run. `admin_id` is BIGINT UNSIGNED to match
 * `gates_admins.id`; there is no FOREIGN KEY because the harness turns them off and a
 * removed admin's pins are harmless rows the next read never asks about.
 *
 * No `CREATE INDEX IF NOT EXISTS` anywhere (MySQL answers it with a 1064 and every later
 * migration stops applying): indexes go through SchemaIndex::ensure().
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_admin_pins')) {
    DB::statement($sqlite ? <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_admin_pins (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          admin_id INTEGER NOT NULL,
          href TEXT NOT NULL,
          label TEXT NOT NULL,
          created_at TEXT NOT NULL
        )
SQL : <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_admin_pins (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          admin_id BIGINT UNSIGNED NOT NULL,
          href VARCHAR(255) NOT NULL,
          label VARCHAR(120) NOT NULL,
          created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + gates_admin_pins created\n";
} else {
    echo "  = gates_admin_pins present\n";
}
echo SchemaIndex::ensure('gates_admin_pins', 'uq_admin_pin', ['admin_id', 'href'], true) . "\n";

if (!DB::schema()->hasTable('gates_admin_prefs')) {
    DB::statement($sqlite ? <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_admin_prefs (
          admin_id INTEGER NOT NULL PRIMARY KEY,
          sidebar_closed INTEGER NOT NULL DEFAULT 0,
          updated_at TEXT NULL
        )
SQL : <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_admin_prefs (
          admin_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
          sidebar_closed TINYINT(1) NOT NULL DEFAULT 0,
          updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + gates_admin_prefs created\n";
} else {
    echo "  = gates_admin_prefs present\n";
}
echo "admin console pins OK\n";
