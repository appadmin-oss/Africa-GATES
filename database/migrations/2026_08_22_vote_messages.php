<?php
/**
 * A voter's message of support for a nominee.
 *
 * ── WHY THIS IS NOT A COLUMN ON gates_votes ──────────────────────────────────
 *
 * The obvious build is `gates_votes.message`, and it is the wrong one for three
 * reasons that all point the same way.
 *
 * `gates_votes` is the integrity table. It is what a disputed result is replayed
 * from, it carries the row-locked uniqueness constraints the whole voting model
 * rests on, and every prior wave of work on this platform has treated it as
 * additive-only for exactly that reason. A message needs a MODERATION lifecycle —
 * pending, approved, rejected, quarantined, re-checked, edited by an admin — and
 * putting that lifecycle on the vote row conflates "is this vote real" with "is
 * this sentence publishable". Those are different questions with different owners
 * and different failure modes, and a schema that answers them in one row will
 * eventually let one of them corrupt the other.
 *
 * Second: a message is optional and most votes will not have one. A nullable TEXT
 * plus four moderation columns on the hottest table on the platform, to serve a
 * minority of rows, is the wrong place to spend row width.
 *
 * Third: a message can be withdrawn or rejected WITHOUT the vote being affected.
 * That must be true — removing an abusive sentence cannot quietly remove a
 * legitimate vote — and separate rows make it true by construction rather than by
 * remembering.
 *
 * ── THE VOTE LINK IS NULLABLE, ON PURPOSE ────────────────────────────────────
 *
 * `vote_id` is set where we have it and left NULL where we do not. A paid
 * contribution mints its votes asynchronously after the gateway confirms, so the
 * message exists before the vote row does; and the audited mint path is not being
 * taught about messages to close that gap. The nominee and the voter hash are
 * always present, which is what every read actually needs.
 *
 * ── WHAT IS STORED, AND WHAT IS NOT ──────────────────────────────────────────
 *
 * The voter's email is a SHA-256 hash, the same one `gates_votes` uses, so
 * "one message per person per nominee" is answerable without holding the person.
 * `display_name` is only ever shown when `show_name` is 1 — the same consent rule
 * the supporters list follows, because a name collected for a receipt is not a
 * name volunteered for publication.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_vote_messages')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_vote_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nominee_id INTEGER NOT NULL,
            category_id INTEGER NULL,
            vote_id INTEGER NULL,
            donation_id INTEGER NULL,
            voter_email_hash TEXT NOT NULL,
            display_name TEXT NULL,
            show_name INTEGER NOT NULL DEFAULT 0,
            body TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'free',
            status TEXT NOT NULL DEFAULT 'pending',
            mod_score REAL NULL,
            mod_reason TEXT NULL,
            moderated_by INTEGER NULL,
            moderated_at TEXT NULL,
            cheers INTEGER NOT NULL DEFAULT 0,
            share_token TEXT NULL,
            created_at TEXT NULL,
            deleted_at TEXT NULL
        )" : "
        CREATE TABLE gates_vote_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nominee_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            -- Set where we have it. NULL for a paid contribution, whose votes are
            -- minted after the gateway confirms — the message exists first.
            vote_id BIGINT UNSIGNED NULL,
            donation_id BIGINT UNSIGNED NULL,
            -- The same SHA-256 the vote table uses, so 'one message per person per
            -- nominee' is answerable without holding the person.
            voter_email_hash VARCHAR(64) NOT NULL,
            display_name VARCHAR(120) NULL,
            -- Shown ONLY when 1, exactly like the supporters list: a name collected
            -- for a receipt is not a name volunteered for publication.
            show_name TINYINT(1) NOT NULL DEFAULT 0,
            body TEXT NOT NULL,
            source ENUM('free','paid') NOT NULL DEFAULT 'free',
            -- pending  : awaiting a decision (scored but between the thresholds)
            -- approved : visible on the nominee's page
            -- rejected : never shown, kept for audit
            -- quarantined : held by the classifier for a person to look at
            status ENUM('pending','approved','rejected','quarantined') NOT NULL DEFAULT 'pending',
            mod_score DECIMAL(4,3) NULL,
            mod_reason VARCHAR(190) NULL,
            moderated_by BIGINT UNSIGNED NULL,
            moderated_at TIMESTAMP NULL DEFAULT NULL,
            cheers INT UNSIGNED NOT NULL DEFAULT 0,
            -- Opaque id for the message's own shareable permalink, so a share link
            -- cannot be walked by incrementing an integer.
            share_token CHAR(22) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_vote_messages created\n";
} else {
    echo "  = gates_vote_messages already present\n";
}

// The read that matters: a nominee's approved wall, newest first. Every public
// page hits this, so it is the one index that is not optional.
echo \AfricaGates\Support\SchemaIndex::ensure(
    'gates_vote_messages', 'idx_vmsg_wall', ['nominee_id', 'status', 'created_at']
) . "\n";

// One message per person per nominee, enforced in the database rather than by a
// SELECT-then-INSERT that races with itself under a rally.
echo \AfricaGates\Support\SchemaIndex::ensure(
    'gates_vote_messages', 'uq_vmsg_voter', ['nominee_id', 'voter_email_hash'], unique: true
) . "\n";

// Share permalinks resolve by token.
echo \AfricaGates\Support\SchemaIndex::ensure(
    'gates_vote_messages', 'uq_vmsg_token', ['share_token'], unique: true
) . "\n";

// The moderation queue reads by status and age.
echo \AfricaGates\Support\SchemaIndex::ensure(
    'gates_vote_messages', 'idx_vmsg_queue', ['status', 'created_at']
) . "\n";
