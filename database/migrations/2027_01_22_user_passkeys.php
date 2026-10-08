<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * PASSKEYS — a member's device is the second way into their account.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE UNIQUE KEY IS A HASH AND NOT THE CREDENTIAL ITSELF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A credential id is up to 1023 BYTES by the specification (WebAuthn §5.8.3). Base64url
 * that and it is 1364 characters. A UNIQUE index on a VARCHAR that long under utf8mb4 is
 * 5456 bytes of key and MySQL's InnoDB limit is 3072 — so the index is REFUSED, the
 * migration throws, and because MigrateCommand does not record a file it failed on, the
 * CREATE TABLE above it (which has already implicitly committed) leaves a table with no
 * unique key while the migration re-runs and skips for ever.
 *
 * Most authenticators mint 16-64 byte ids and it would have worked on every device anybody
 * tested with. So: the full id lives in a TEXT column nothing indexes, and `credential_hash`
 * — sha256 hex, fixed 64 characters — carries the UNIQUE. The lookup on sign-in is by hash.
 *
 * ── `sign_count` IS INT UNSIGNED, NOT SMALLINT ──────────────────────────────
 * Authenticators that implement the counter increment it on every assertion and some seed
 * it from a global counter in the millions. A narrow column silently clamps, the stored
 * count then exceeds what the device reports, and the counter check reads that as a CLONED
 * authenticator — locking somebody out of their own account with a security warning.
 *
 * ── ONE ROW PER CREDENTIAL, AND THE RECORD IS STORED WHOLE ──────────────────
 * `record_json` is the library's own serialisation of the credential record (public key,
 * transports, backup bits, user handle). Storing our own reading of it would mean two
 * parsers for one format, and the one that drifts is the one that verifies signatures.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_user_passkeys')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_user_passkeys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            -- sha256 hex of the base64url credential id. The UNIQUE lives here.
            credential_hash TEXT NOT NULL,
            credential_id TEXT NOT NULL,
            -- The library's serialised CredentialRecord. One parser, never two.
            record_json TEXT NOT NULL,
            -- What the member calls this device. Theirs to write; never trusted in markup.
            label TEXT NULL,
            sign_count INTEGER NOT NULL DEFAULT 0,
            aaguid TEXT NULL,
            created_at TEXT NULL,
            last_used_at TEXT NULL
        )" : "
        CREATE TABLE gates_user_passkeys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            credential_hash CHAR(64) NOT NULL,
            credential_id TEXT NOT NULL,
            record_json MEDIUMTEXT NOT NULL,
            label VARCHAR(80) NULL,
            sign_count INT UNSIGNED NOT NULL DEFAULT 0,
            aaguid VARCHAR(64) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            last_used_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "  + gates_user_passkeys created\n";
} else {
    echo "  = gates_user_passkeys already present\n";
}

// Through SchemaIndex — the raw `CREATE INDEX IF NOT EXISTS` is SQLite syntax MySQL
// answers with a 1064, and the throw aborts the run without recording this file.
echo '  ' . SchemaIndex::ensure('gates_user_passkeys', 'uq_passkey_cred',
                                ['credential_hash'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_user_passkeys', 'idx_passkey_user',
                                ['user_id']) . "\n";
