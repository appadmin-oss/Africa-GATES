<?php
/**
 * Letting somebody answer their own support ticket without an account.
 *
 * ── THE GAP ──────────────────────────────────────────────────────────────────
 *
 * All three ticket endpoints — open, read, reply — require a signed-in member.
 * The reasoning was sound as far as it went: "a ticket is a promise to reply, and
 * a reply needs a verified address." But it locks out the two groups most likely
 * to need support:
 *
 *   • GUEST PAYERS. Paid voting takes an email and a card; no account is created.
 *     The entire unminted-vote incident population is guests. They were given the
 *     payment-repair tools and then had no way to answer the reply they got.
 *   • HELD CLAIMANTS. docs/CLAIM-FAIRNESS-AND-FRAUD.md §7.3 requires that "a human
 *     route always exists, WITHOUT AN ACCOUNT". The assisted claim path routes to
 *     a ticket — a ticket the person it was opened for cannot open.
 *
 * A support thread the requester cannot reply to is a monologue.
 *
 * ── WHY A STORED RANDOM TOKEN AND NOT A SIGNED ONE ───────────────────────────
 *
 * A self-contained HMAC token needs an application secret, and this deployment has
 * none — no APP_KEY anywhere. Adding one means a new .env value on a host with no
 * shell, and worse, a signing helper that falls back to a default when it is unset
 * would be a silent forgery hole. A random token compared by hash needs no secret
 * at all, and `gates_magic_links` already establishes exactly this pattern here.
 *
 * ── AND WHY A SEPARATE TABLE FROM gates_magic_links ──────────────────────────
 *
 * Its `purpose` enum is `admin_login | password_reset`. Widening a table whose
 * tokens grant ADMIN LOGIN so that it also holds low-value ticket links puts one
 * mistaken query between a support link and an admin session. Different blast
 * radius, different table. The duplication is a few columns; the alternative is a
 * privilege-escalation bug waiting for a careless join.
 *
 * ── WHAT IS STORED ───────────────────────────────────────────────────────────
 *
 * Only the SHA-256 of the token, so a dump of this table yields no working links —
 * the same reason password hashes exist. And the email the link was issued to, so
 * a link dies if the ticket's address is ever changed: a link is permission for one
 * person to see one conversation, not a permanent bearer key to a row id.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_ticket_links')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_ticket_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            revoked_at TEXT NULL,
            last_used_at TEXT NULL,
            uses INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_ticket_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT UNSIGNED NOT NULL,
            -- SHA-256 of the token. The token itself is never written down, so a
            -- leak of this table hands over nothing that can be used.
            token_hash CHAR(64) NOT NULL,
            -- The address it was issued to. Compared on every use, so changing a
            -- ticket's email invalidates every link already sent for it.
            email VARCHAR(255) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            revoked_at TIMESTAMP NULL DEFAULT NULL,
            last_used_at TIMESTAMP NULL DEFAULT NULL,
            uses INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ticket_token (token_hash),
            KEY idx_ticket_link (ticket_id, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_ticket_links created\n";
} else {
    echo "  = gates_ticket_links already present\n";
}

echo "ticket links OK\n";
