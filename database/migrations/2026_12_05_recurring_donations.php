<?php
/**
 * Monthly giving, recorded where the platform can see it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO TABLES, AND WHY NEITHER IS OPTIONAL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_donation_plans` maps an amount to the gateway's own plan code. Paystack will
 * happily create a second plan for the same amount every time it is asked, and each one
 * bills independently — so without a stored mapping, a month of checkouts leaves a spread
 * of near-identical plans and no way to answer "how many people give ₦5,000 a month".
 *
 * `gates_donation_subscriptions` is the record of a standing arrangement, and the two
 * columns that look like plumbing are the whole point:
 *
 *   · `subscription_code` and `email_token` are what Paystack requires TOGETHER to stop a
 *     subscription. Without both stored, a donor asking to cancel has to be sent to the
 *     gateway's own email flow, and a donor who cannot easily stop giving is a chargeback
 *     and a complaint rather than a lapsed supporter.
 *   · `status` moves on webhooks, never on our own guess. A subscription this platform
 *     believes is live and the gateway has already disabled is the shape of fault that
 *     shows up as "you charged me after I cancelled".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE COLUMN ON THE DONATION ROW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_donations.subscription_id` ties each monthly charge back to the arrangement that
 * produced it. Every recurring charge after the first arrives with a reference the GATEWAY
 * generated, not ours — so without this the second month's gift is a donation row with no
 * relationship to the first, and "how much has this supporter given" cannot be answered at
 * all.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_donation_plans')) {
    DB::statement($sqlite ? <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_donation_plans (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          provider TEXT NOT NULL DEFAULT 'paystack',
          amount_naira INTEGER NOT NULL,
          interval_name TEXT NOT NULL DEFAULT 'monthly',
          plan_code TEXT NOT NULL,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
SQL : <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_donation_plans (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          provider VARCHAR(20) NOT NULL DEFAULT 'paystack',
          /* INT, not the TINYINT this project has been bitten by: a monthly gift can
             legitimately be six figures of naira. */
          amount_naira INT UNSIGNED NOT NULL,
          interval_name VARCHAR(20) NOT NULL DEFAULT 'monthly',
          plan_code VARCHAR(64) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // One plan per provider/amount/interval, enforced rather than hoped for. This is the
    // index that stops a month of checkouts minting a spread of duplicate plans.
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_donation_plan
                   ON gates_donation_plans (provider, amount_naira, interval_name)');
    echo "  + gates_donation_plans created\n";
} else {
    echo "  = gates_donation_plans present\n";
}

if (!DB::schema()->hasTable('gates_donation_subscriptions')) {
    DB::statement($sqlite ? <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_donation_subscriptions (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          provider TEXT NOT NULL DEFAULT 'paystack',
          donor_email TEXT NOT NULL,
          donor_name TEXT NOT NULL DEFAULT '',
          amount_naira INTEGER NOT NULL,
          interval_name TEXT NOT NULL DEFAULT 'monthly',
          plan_code TEXT NOT NULL DEFAULT '',
          subscription_code TEXT NOT NULL DEFAULT '',
          email_token TEXT NOT NULL DEFAULT '',
          customer_code TEXT NOT NULL DEFAULT '',
          status TEXT NOT NULL DEFAULT 'pending',
          first_ref TEXT NOT NULL DEFAULT '',
          manage_token TEXT NOT NULL DEFAULT '',
          charges INTEGER NOT NULL DEFAULT 0,
          last_charge_at TEXT,
          next_charge_at TEXT,
          cancelled_at TEXT,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
SQL : <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_donation_subscriptions (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          provider VARCHAR(20) NOT NULL DEFAULT 'paystack',
          donor_email VARCHAR(190) NOT NULL,
          donor_name VARCHAR(120) NOT NULL DEFAULT '',
          amount_naira INT UNSIGNED NOT NULL,
          interval_name VARCHAR(20) NOT NULL DEFAULT 'monthly',
          plan_code VARCHAR(64) NOT NULL DEFAULT '',
          /* The pair Paystack requires together to stop a subscription. A donor who
             cannot easily stop giving is a chargeback, not a lapsed supporter. */
          subscription_code VARCHAR(64) NOT NULL DEFAULT '',
          email_token VARCHAR(64) NOT NULL DEFAULT '',
          customer_code VARCHAR(64) NOT NULL DEFAULT '',
          /* VARCHAR and not ENUM. A value outside an ENUM is `Data truncated` on MySQL and
             silently accepted on SQLite, and this project has shipped that divergence
             twice — see gates_event_invites.audience. */
          status VARCHAR(20) NOT NULL DEFAULT 'pending',
          first_ref VARCHAR(64) NOT NULL DEFAULT '',
          /* The donor's own stop link, on the row rather than derived — the same shape as
             the shop's back-in-stock unsubscribe. Stored means revocable; an HMAC over the
             email would be valid forever and could not be withdrawn. */
          manage_token VARCHAR(32) NOT NULL DEFAULT '',
          charges INT UNSIGNED NOT NULL DEFAULT 0,
          last_charge_at TIMESTAMP NULL DEFAULT NULL,
          next_charge_at TIMESTAMP NULL DEFAULT NULL,
          cancelled_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // The webhook arrives knowing the subscription code and nothing else of ours, so this
    // is the lookup on the hot path of every recurring charge.
    DB::statement('CREATE INDEX IF NOT EXISTS idx_donsub_code
                   ON gates_donation_subscriptions (subscription_code)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_donsub_email
                   ON gates_donation_subscriptions (donor_email)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_donsub_ref
                   ON gates_donation_subscriptions (first_ref)');
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_donsub_manage
                   ON gates_donation_subscriptions (manage_token)');
    echo "  + gates_donation_subscriptions created\n";
} else {
    echo "  = gates_donation_subscriptions present\n";
}

if (DB::schema()->hasTable('gates_donations')
    && !DB::schema()->hasColumn('gates_donations', 'subscription_id')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_donations ADD COLUMN subscription_id INTEGER NULL DEFAULT NULL'
        : 'ALTER TABLE gates_donations ADD COLUMN subscription_id INT UNSIGNED NULL DEFAULT NULL');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_donations_subscription
                   ON gates_donations (subscription_id)');
    echo "  + gates_donations.subscription_id added\n";
} else {
    echo "  = gates_donations.subscription_id present or table absent\n";
}
