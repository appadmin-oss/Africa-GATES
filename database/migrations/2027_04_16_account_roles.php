<?php
/**
 * One account, many roles (owner, 28 Oct 2026: "one account that can manage different roles,
 * not separate disjointed accounts"; docs/architecture/AUDIT-AND-PLAN.md §7.1, D6).
 *
 *   gates_account_links   ties a staff, organisation or judge account to the Africa GATES
 *                         account (gates_users) of the person who holds it. A person signs in
 *                         once, as themselves, and opens each role from there.
 *
 * A link is made only on the PROOF the role's own door accepts, confirmed once and recorded in
 * `via`: both signed in in one browser (`both_signed_in`), a staff or judging address equal to
 * the account's VERIFIED one (`verified_email` — those doors sign in by email), or an
 * organisation's own password (`org_password` — its door never accepts an email). One role
 * account belongs to one person (Account\Roles::join). Unlinking stamps `revoked_at`; the
 * row stays, so "who could open the console as Ade in March" stays answerable.
 *
 * Statuses are VARCHAR, never ENUM. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_account_links')) {
    DB::statement($sqlite
        ? 'CREATE TABLE gates_account_links (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL, account_id INTEGER NOT NULL,
             via TEXT NOT NULL, created_at TEXT NOT NULL, revoked_at TEXT NULL, revoked_by_kind TEXT NULL, revoked_by INTEGER NULL)'
        : 'CREATE TABLE gates_account_links (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(16) NOT NULL,
             account_id BIGINT UNSIGNED NOT NULL, via VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, revoked_at DATETIME NULL,
             revoked_by_kind VARCHAR(16) NULL, revoked_by BIGINT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    echo "  + gates_account_links\n";
}
SchemaIndex::ensure('gates_account_links', 'idx_acctlink_user', ['user_id']);
SchemaIndex::ensure('gates_account_links', 'idx_acctlink_role', ['kind', 'account_id']);
