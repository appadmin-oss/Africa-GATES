<?php
/**
 * A way to stop receiving texts.
 *
 * ── WHY THIS DID NOT EXIST, AND WHY THAT STOPPED BEING ACCEPTABLE ────────────
 *
 * Every text this platform has ever sent was a REPLY: a claim code somebody asked for, a
 * verification, an interview time they agreed to. A person who does not want those simply
 * does not ask, so `SmsService` shipped with no consent check at all and nothing was
 * obviously wrong.
 *
 * The check-in text is the first message somebody receives without having asked for that
 * particular message — they bought a ticket and walked through a door. That is the point
 * at which "no way to stop it" becomes indefensible, and it is a legal question in most of
 * the places these events happen, not only a courtesy one.
 *
 * ── HASHED, LIKE THE EMAIL ONE ───────────────────────────────────────────────
 *
 * Mirrors `gates_email_optout` deliberately: the number is stored hashed for lookup and
 * masked for a human reading the table, so the suppression list is not itself a directory
 * of everybody's phone number. An operator who needs to check one number can hash it; an
 * operator who wants the list gets nothing they did not already have.
 *
 * `phone_masked` is kept because a support desk answering "am I still getting these"
 * cannot do anything with sixty-four hex characters, and the alternative is storing the
 * number.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (DB::schema()->hasTable('gates_sms_optout')) {
    echo "  · gates_sms_optout already exists\n";
    return;
}

$sqlite = DB::connection()->getDriverName() === 'sqlite';

DB::statement($sqlite
    ? "CREATE TABLE gates_sms_optout (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         phone_hash TEXT NOT NULL,
         -- Last four digits only. Enough for a support desk to confirm which number a
         -- caller means, useless to anybody who wants the list.
         phone_masked TEXT NULL,
         -- How they got here: a STOP reply, a link, or an operator acting on a request
         -- made some other way. A suppression nobody can account for is one nobody will
         -- ever dare remove.
         source TEXT NOT NULL DEFAULT 'stop-reply',
         created_at TEXT NOT NULL
       )"
    : "CREATE TABLE gates_sms_optout (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         phone_hash CHAR(64) NOT NULL,
         phone_masked VARCHAR(12) NULL,
         source VARCHAR(60) NOT NULL DEFAULT 'stop-reply',
         created_at TIMESTAMP NOT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_sms_optout (phone_hash)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($sqlite) {
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_sms_optout ON gates_sms_optout (phone_hash)');
}

echo "  ✓ gates_sms_optout\n";
