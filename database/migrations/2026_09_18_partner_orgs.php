<?php
/**
 * Partner organisations — donations collected on the platform, settled to somebody else.
 *
 * ── THE ONE DECISION THIS SCHEMA ENCODES ─────────────────────────────────────
 *
 * A donation to a partner is ONE payment that splits at the gateway: the organisation's
 * share settles into the organisation's own Paystack subaccount, which is tied to a bank
 * account in the organisation's own registered name, and the platform's share splits off at
 * source. Africa GATES never holds money that is not its own.
 *
 * That is not a preference. Collecting third-party charitable money into a platform account
 * and forwarding it later makes this platform a custodian of other people's funds, with the
 * AML, segregation and insolvency questions that follow, in exchange for nothing a split
 * cannot give. So there is no balance column on an organisation, and deliberately so: a
 * balance is what you have when you are holding somebody's money. What is stored here is a
 * SUBACCOUNT CODE — a pointer at an account that was never ours.
 *
 * ── WHAT IS DELIBERATELY NOT STORED ──────────────────────────────────────────
 *
 * The full bank account number. It is sent to Paystack once, at subaccount creation, and
 * never written down: after that the subaccount code is the only handle anything needs, and
 * a table of NGO account numbers is a liability with no corresponding use. `account_last4`
 * and the bank code are kept so a human can recognise which account they picked, which is
 * the only question anybody actually asks of it.
 *
 * `account_name_resolved` is what PAYSTACK said the account name is, captured at creation.
 * It exists to be compared against the registered name, and to be shown to a reviewer who
 * is deciding whether "ADEBAYO J" receiving donations for "Bright Futures Initiative" is a
 * sole trader using a personal account or somebody about to steal from strangers.
 *
 * ── VETTING IS A STATE MACHINE, NOT A BOOLEAN ────────────────────────────────
 *
 * draft → pending → approved → (suspended) and the terminal `rejected`. Only `approved` may
 * appear publicly or receive money, and `suspended` exists as a separate state from
 * `rejected` because the CAC can restrict an incorporated trustee's financial transactions
 * (CAMA 2020) between one week and the next: a partner can need to stop collecting TODAY
 * without their history being erased or their past donations orphaned.
 *
 * ── AND THE GATEWAY IDS ARE STRINGS OR BIGINT UNSIGNED ───────────────────────
 *
 * Paystack transaction ids became unsigned 64-bit integers in June 2022. A signed INT column
 * truncates them silently, which is the worst possible failure for a number whose entire job
 * is to match a row in somebody else's system.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$pdo    = DB::connection()->getPdo();

// ── Organisations ────────────────────────────────────────────────────────────
$orgs = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_partner_orgs (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         slug TEXT NOT NULL UNIQUE,
         name TEXT NOT NULL,
         legal_name TEXT,
         cac_number TEXT,
         scuml_number TEXT,
         description TEXT,
         contact_name TEXT,
         contact_email TEXT,
         contact_phone TEXT,
         status TEXT NOT NULL DEFAULT 'draft',
         subaccount_code TEXT,
         settlement_bank TEXT,
         account_last4 TEXT,
         account_name_resolved TEXT,
         settlement_schedule TEXT NOT NULL DEFAULT 'auto',
         platform_fee_bps INTEGER NOT NULL DEFAULT 0,
         vetted_by INTEGER,
         vetted_at TEXT,
         vetting_note TEXT,
         suspended_reason TEXT,
         suspended_at TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TEXT
       )"
    : "CREATE TABLE IF NOT EXISTS gates_partner_orgs (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         slug VARCHAR(120) NOT NULL,
         name VARCHAR(200) NOT NULL,
         legal_name VARCHAR(200) DEFAULT NULL,
         cac_number VARCHAR(60) DEFAULT NULL,
         scuml_number VARCHAR(60) DEFAULT NULL,
         description TEXT,
         contact_name VARCHAR(160) DEFAULT NULL,
         contact_email VARCHAR(190) DEFAULT NULL,
         contact_phone VARCHAR(40) DEFAULT NULL,
         status VARCHAR(20) NOT NULL DEFAULT 'draft',
         subaccount_code VARCHAR(60) DEFAULT NULL,
         settlement_bank VARCHAR(20) DEFAULT NULL,
         account_last4 VARCHAR(8) DEFAULT NULL,
         account_name_resolved VARCHAR(200) DEFAULT NULL,
         settlement_schedule VARCHAR(12) NOT NULL DEFAULT 'auto',
         platform_fee_bps INT UNSIGNED NOT NULL DEFAULT 0,
         vetted_by BIGINT UNSIGNED DEFAULT NULL,
         vetted_at TIMESTAMP NULL DEFAULT NULL,
         vetting_note TEXT,
         suspended_reason VARCHAR(255) DEFAULT NULL,
         suspended_at TIMESTAMP NULL DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_org_slug (slug),
         KEY idx_org_status (status)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// ── Who may sign in to an organisation's dashboard ───────────────────────────
//
// A separate table from gates_users on purpose. A donor account and an NGO treasurer are
// different trust domains, and joining them means one credential-stuffing incident against
// the public site reaches the screen that moves money.
$users = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_org_users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         org_id INTEGER NOT NULL,
         email TEXT NOT NULL,
         name TEXT,
         password_hash TEXT NOT NULL,
         role TEXT NOT NULL DEFAULT 'viewer',
         is_active INTEGER NOT NULL DEFAULT 1,
         last_login_at TEXT,
         failed_logins INTEGER NOT NULL DEFAULT 0,
         locked_until TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_org_users (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         org_id BIGINT UNSIGNED NOT NULL,
         email VARCHAR(190) NOT NULL,
         name VARCHAR(160) DEFAULT NULL,
         password_hash VARCHAR(255) NOT NULL,
         role VARCHAR(20) NOT NULL DEFAULT 'viewer',
         is_active TINYINT(1) NOT NULL DEFAULT 1,
         last_login_at TIMESTAMP NULL DEFAULT NULL,
         failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
         locked_until TIMESTAMP NULL DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         UNIQUE KEY uq_orguser_email (email),
         KEY idx_orguser_org (org_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// ── Payout requests ──────────────────────────────────────────────────────────
//
// The status list is Paystack's transfer state machine verbatim, plus `queued` for the
// moment between an organisation pressing the button and the gateway being told. Copying
// their vocabulary rather than inventing ours means a support conversation can quote a
// status to Paystack and have it mean the same thing on both sides.
//
// `reference` is OURS and unique: Paystack documents no client idempotency key, so the
// reference IS the idempotency mechanism. It is generated and stored BEFORE the gateway is
// called, and a retry reuses it — that is the whole defence against paying somebody twice.
$payouts = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_org_payouts (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         org_id INTEGER NOT NULL,
         reference TEXT NOT NULL UNIQUE,
         amount_naira INTEGER NOT NULL,
         status TEXT NOT NULL DEFAULT 'queued',
         recipient_code TEXT,
         transfer_code TEXT,
         gateway_transfer_id TEXT,
         gateway_message TEXT,
         requested_by INTEGER,
         requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         settled_at TEXT,
         attempts INTEGER NOT NULL DEFAULT 0,
         last_checked_at TEXT
       )"
    : "CREATE TABLE IF NOT EXISTS gates_org_payouts (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         org_id BIGINT UNSIGNED NOT NULL,
         reference VARCHAR(80) NOT NULL,
         amount_naira INT UNSIGNED NOT NULL,
         status VARCHAR(20) NOT NULL DEFAULT 'queued',
         recipient_code VARCHAR(60) DEFAULT NULL,
         transfer_code VARCHAR(60) DEFAULT NULL,
         gateway_transfer_id BIGINT UNSIGNED DEFAULT NULL,
         gateway_message VARCHAR(255) DEFAULT NULL,
         requested_by BIGINT UNSIGNED DEFAULT NULL,
         requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         settled_at TIMESTAMP NULL DEFAULT NULL,
         attempts INT UNSIGNED NOT NULL DEFAULT 0,
         last_checked_at TIMESTAMP NULL DEFAULT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_payout_ref (reference),
         KEY idx_payout_org (org_id, status),
         KEY idx_payout_open (status, last_checked_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$pdo->exec($orgs);
$pdo->exec($users);
$pdo->exec($payouts);

// ── Donations gain a recipient ───────────────────────────────────────────────
//
// NULL means Africa GATES, which is every row that already exists. Additive and nullable so
// the backfill is "do nothing" and the existing donation path is untouched until something
// actually sets it.
foreach ([
    ['recipient_org_id',   $sqlite ? 'INTEGER'      : 'BIGINT UNSIGNED NULL DEFAULT NULL'],
    ['platform_fee_naira', $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'INT UNSIGNED NOT NULL DEFAULT 0'],
] as [$col, $type]) {
    try {
        if (!DB::schema()->hasColumn('gates_donations', $col)) {
            $pdo->exec("ALTER TABLE gates_donations ADD COLUMN $col $type");
        }
    } catch (\Throwable $e) {
        echo "*** could not add gates_donations.$col: {$e->getMessage()}\n";
    }
}

try {
    if (!$sqlite) {
        $pdo->exec("CREATE INDEX idx_donation_recipient ON gates_donations (recipient_org_id, status)");
    } else {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_donation_recipient ON gates_donations (recipient_org_id, status)");
    }
} catch (\Throwable) {
    // MySQL has no CREATE INDEX IF NOT EXISTS; a second run throws and that is fine.
}

foreach (['gates_partner_orgs', 'gates_org_users', 'gates_org_payouts'] as $t) {
    echo DB::schema()->hasTable($t) ? "$t OK\n" : "*** $t FAILED ***\n";
}
foreach (['recipient_org_id', 'platform_fee_naira'] as $c) {
    echo DB::schema()->hasColumn('gates_donations', $c) ? "gates_donations.$c OK\n" : "*** $c FAILED ***\n";
}
