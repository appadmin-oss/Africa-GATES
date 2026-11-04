<?php
/**
 * "Bring your people": a voter's link for one nominee, and the people who voted through it
 * (Services\VoteRecruit).
 *
 *   gates_vote_recruits          one link per voter per nominee: the voter's hash (the ledger's
 *                                own VoteService::voterHash, so a person here is the person the
 *                                scoring counts), the nominee, a short public code.
 *   gates_vote_recruit_credits   each person who voted through it, once (UNIQUE recruit+voter):
 *                                the new voter's hash and what their vote was (a vote id, or a
 *                                paid order's reference). The count of these rows is "you
 *                                brought N people", and nothing more is ever shown.
 *   gates_vote_recruit_pending   a paid order started from a link, waiting for the gateway:
 *                                the order's reference and the code. Credited when the votes
 *                                are minted, never at checkout, because an abandoned checkout
 *                                brought nobody.
 *
 * Nothing here touches a tally or a score. It counts people a supporter brought, for the
 * supporter's own page and the nominee's — the scoring has its own, stricter count.
 *
 * VARCHAR, not ENUM (CLAUDE.md). Idempotent + driver-aware; indexes through SchemaIndex.
 * NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_vote_recruits')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_vote_recruits (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              code TEXT NOT NULL,
              email_hash TEXT NOT NULL,
              nominee_id INTEGER NOT NULL,
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE gates_vote_recruits (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              code VARCHAR(16) NOT NULL,
              email_hash CHAR(64) NOT NULL,
              nominee_id BIGINT UNSIGNED NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_vote_recruits\n";
}
if ($schema->hasTable('gates_vote_recruits')) {
    echo '  ' . SchemaIndex::ensure('gates_vote_recruits', 'uq_vrec_code', ['code'], true) . "\n";
    echo '  ' . SchemaIndex::ensure('gates_vote_recruits', 'uq_vrec_voter', ['email_hash', 'nominee_id'], true) . "\n";
}

if (!$schema->hasTable('gates_vote_recruit_credits')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_vote_recruit_credits (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              recruit_id INTEGER NOT NULL,
              voter_hash TEXT NOT NULL,
              vote_ref TEXT NOT NULL DEFAULT '',
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE gates_vote_recruit_credits (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              recruit_id BIGINT UNSIGNED NOT NULL,
              voter_hash CHAR(64) NOT NULL,
              vote_ref VARCHAR(64) NOT NULL DEFAULT '',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_vote_recruit_credits\n";
}
if ($schema->hasTable('gates_vote_recruit_credits')) {
    echo '  ' . SchemaIndex::ensure('gates_vote_recruit_credits', 'uq_vrc_once', ['recruit_id', 'voter_hash'], true) . "\n";
}

if (!$schema->hasTable('gates_vote_recruit_pending')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_vote_recruit_pending (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              payment_ref TEXT NOT NULL,
              code TEXT NOT NULL,
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE gates_vote_recruit_pending (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              payment_ref VARCHAR(64) NOT NULL,
              code VARCHAR(16) NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_vote_recruit_pending\n";
}
if ($schema->hasTable('gates_vote_recruit_pending')) {
    echo '  ' . SchemaIndex::ensure('gates_vote_recruit_pending', 'uq_vrp_ref', ['payment_ref'], true) . "\n";
}
