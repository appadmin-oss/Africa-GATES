<?php
/**
 * Highlights: a business, product, service or event a member puts in front of people, free,
 * reviewed before it is shown (Services\Highlights). The first step of the market's promotions
 * (docs/architecture/AUDIT-AND-PLAN.md §10: "highlights come first — no money moves").
 *
 *   gates_highlights   one listing: whose (`user_id`), what kind, the words, an optional link
 *                      and image, where; its review state, the reason when refused, who
 *                      decided and when, and how long it stays up (`live_until`).
 *
 * Paid promotion of a highlight (a placement bought through the ledger, labelled "Promoted")
 * is the next step and gets its own table; nothing here carries money.
 *
 * VARCHAR, not ENUM (CLAUDE.md). Idempotent + driver-aware; indexes through SchemaIndex.
 * NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_highlights')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_highlights (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL,
              kind TEXT NOT NULL DEFAULT 'business',
              title TEXT NOT NULL,
              body TEXT NOT NULL,
              url TEXT NULL,
              image_path TEXT NULL,
              location TEXT NULL,
              status TEXT NOT NULL DEFAULT 'pending',
              reject_reason TEXT NULL,
              reviewed_by INTEGER NULL,
              reviewed_at TEXT NULL,
              live_until TEXT NULL,
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE gates_highlights (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT UNSIGNED NOT NULL,
              kind VARCHAR(16) NOT NULL DEFAULT 'business',
              title VARCHAR(120) NOT NULL,
              body TEXT NOT NULL,
              url VARCHAR(400) NULL,
              image_path VARCHAR(400) NULL,
              location VARCHAR(80) NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'pending',
              reject_reason VARCHAR(300) NULL,
              reviewed_by BIGINT UNSIGNED NULL,
              reviewed_at TIMESTAMP NULL,
              live_until TIMESTAMP NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_highlights\n";
}
if ($schema->hasTable('gates_highlights')) {
    echo '  ' . SchemaIndex::ensure('gates_highlights', 'idx_hl_status', ['status', 'live_until']) . "\n";
    echo '  ' . SchemaIndex::ensure('gates_highlights', 'idx_hl_user', ['user_id', 'status']) . "\n";
}
