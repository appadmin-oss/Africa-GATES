<?php
/**
 * Closing three gaps left open when partner organisations first shipped.
 *
 * ── 1 · THE TRANSFER RECIPIENT, SO PAYOUTS STOP NEEDING A BANK ACCOUNT NUMBER ─
 *
 * The account number is deliberately not stored: it is sent to Paystack once at subaccount
 * creation and never written down. That was right, and it left transfer-mode payouts unable
 * to build a transfer recipient, which needs the number — patched at the time by reading it
 * back from an environment variable per organisation, which is a hack wearing a config file.
 *
 * The real fix is ordering. The transfer recipient is created during ONBOARDING, in the same
 * request that has the number in hand, and only its `recipient_code` is kept. After that a
 * payout needs nothing but the code, the account number is still never stored, and the
 * environment hack disappears.
 *
 * ── 2 · WHETHER A REGISTRATION NUMBER WAS CHECKED, OR MERELY TYPED IN ─────────
 *
 * There is no free public API for the CAC register — the Commission runs a search page for
 * humans, and programmatic access is third-party and paid. So a number can be `unchecked`,
 * `confirmed` by a reviewer who looked, `verified` by a configured API, or `rejected`. A
 * boolean here would collapse "we looked" into "we typed it in", and the difference is the
 * whole value of a vetting record.
 *
 * ── 3 · THE CERTIFICATES THEMSELVES, WITH EXPIRY ─────────────────────────────
 *
 * Numbers were being collected and the documents behind them were not. `gates_org_documents`
 * stores the file reference and — the part that matters operationally — an expiry, because
 * an insurance policy or a SCUML certificate that lapsed in March is the thing nobody
 * notices until the organisation is already collecting money.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$pdo    = DB::connection()->getPdo();

// ── 1 + 2 · columns on the organisation ──────────────────────────────────────
$cols = [
    // Paystack RCP_… — created at onboarding, the only handle a payout needs.
    'payout_recipient_code' => $sqlite ? 'TEXT' : 'VARCHAR(60) DEFAULT NULL',
    'cac_check'             => $sqlite ? "TEXT NOT NULL DEFAULT 'unchecked'" : "VARCHAR(16) NOT NULL DEFAULT 'unchecked'",
    'scuml_check'           => $sqlite ? "TEXT NOT NULL DEFAULT 'unchecked'" : "VARCHAR(16) NOT NULL DEFAULT 'unchecked'",
    // What the register said the name is, when anything asked it.
    'cac_registered_name'   => $sqlite ? 'TEXT' : 'VARCHAR(200) DEFAULT NULL',
    'checked_by'            => $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED DEFAULT NULL',
    'checked_at'            => $sqlite ? 'TEXT' : 'TIMESTAMP NULL DEFAULT NULL',
];
foreach ($cols as $col => $type) {
    try {
        if (!DB::schema()->hasColumn('gates_partner_orgs', $col)) {
            $pdo->exec("ALTER TABLE gates_partner_orgs ADD COLUMN $col $type");
        }
    } catch (\Throwable $e) {
        echo "*** could not add gates_partner_orgs.$col: {$e->getMessage()}\n";
    }
}

// ── 3 · the documents ────────────────────────────────────────────────────────
//
// `kind` is a short slug rather than an enum so a new document type is a code change and not
// a migration on a live table. `expires_on` is nullable because a CAC certificate does not
// expire and an insurance policy very much does.
$docs = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_org_documents (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         org_id INTEGER NOT NULL,
         kind TEXT NOT NULL,
         original_name TEXT,
         stored_path TEXT NOT NULL,
         mime TEXT,
         size_bytes INTEGER NOT NULL DEFAULT 0,
         expires_on TEXT,
         uploaded_by INTEGER,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_org_documents (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         org_id BIGINT UNSIGNED NOT NULL,
         kind VARCHAR(40) NOT NULL,
         original_name VARCHAR(255) DEFAULT NULL,
         stored_path VARCHAR(400) NOT NULL,
         mime VARCHAR(120) DEFAULT NULL,
         size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
         expires_on DATE NULL DEFAULT NULL,
         uploaded_by BIGINT UNSIGNED DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_orgdoc_org (org_id, kind),
         KEY idx_orgdoc_expiry (expires_on)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$pdo->exec($docs);

echo DB::schema()->hasTable('gates_org_documents') ? "gates_org_documents OK\n" : "*** documents FAILED ***\n";
foreach (array_keys($cols) as $c) {
    echo DB::schema()->hasColumn('gates_partner_orgs', $c) ? "gates_partner_orgs.$c OK\n" : "*** $c FAILED ***\n";
}
