<?php
/**
 * A record of every time we thanked somebody, so we only do it once.
 *
 * ── WHY A TABLE AND NOT A FLAG ───────────────────────────────────────────────
 *
 * Two moments deserve a message to a supporter: the one just after they backed
 * somebody, and the one where that person wins. Both are triggered by machinery
 * that RE-RUNS BY DESIGN — a mint can be retried by the reconciler, a webhook can
 * be replayed, and winner promotion re-enters `CycleMaterialiser` every time the
 * scheduler wakes up on a cycle that has reached its results.
 *
 * The failure that produces is not a wasted email. It is the same person being
 * congratulated four times for the same win, which turns the single warmest
 * message this platform sends into something that reads like a malfunction, in
 * their inbox, about the nominee they care about.
 *
 * A flag on the donation would cover the first case and not the second: a winner
 * celebration is addressed to a PERSON about a NOMINEE, and the person may have
 * backed them across five separate orders. So the key is (kind, nominee,
 * recipient) — one congratulations per supporter per nominee per occasion,
 * enforced by the database rather than by whoever remembers to check.
 *
 * ── AND THE RECIPIENT IS A HASH ──────────────────────────────────────────────
 *
 * Never the address. This table exists to answer "have we already written to this
 * person about this", which a hash answers exactly as well, and it means the
 * honours ledger is not a second copy of the mailing list sitting beside the first
 * — the platform already declines to store a plain address against a vote and
 * there is no reason to start here.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_supporter_honours')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_supporter_honours (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            nominee_id INTEGER NOT NULL,
            recipient_hash TEXT NOT NULL,
            donation_id INTEGER NULL,
            delivered INTEGER NOT NULL DEFAULT 0,
            detail TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_supporter_honours (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            -- 'thanks'  sent just after a contribution was delivered
            -- 'victory' sent when the nominee they backed won or placed
            kind ENUM('thanks','victory') NOT NULL,
            nominee_id BIGINT UNSIGNED NOT NULL,
            -- sha256(lower(trim(email))). Never the address itself.
            recipient_hash CHAR(64) NOT NULL,
            -- Which contribution prompted a 'thanks'. NULL for 'victory', which is
            -- about a person and a nominee rather than about one order.
            donation_id BIGINT UNSIGNED NULL,
            -- Whether the mail actually left. Recorded rather than assumed, so
            -- 'we wrote to 400 people' can be checked instead of believed.
            delivered TINYINT(1) NOT NULL DEFAULT 0,
            detail VARCHAR(300) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            -- THE MUTEX. Claimed by INSERT before the message is composed, so two
            -- concurrent runs cannot both decide they are the one sending it.
            UNIQUE KEY uq_honour (kind, nominee_id, recipient_hash),
            KEY idx_honour_nominee (nominee_id, kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_supporter_honours created\n";
} else {
    echo "  = gates_supporter_honours already present\n";
}

if ($sqlite) {
    foreach ([
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_honour ON gates_supporter_honours(kind, nominee_id, recipient_hash)',
        'CREATE INDEX IF NOT EXISTS idx_honour_nominee ON gates_supporter_honours(nominee_id, kind)',
    ] as $sql) {
        try { DB::statement($sql); } catch (\Throwable $e) { echo '  ! ' . $e->getMessage() . "\n"; }
    }
}

echo "supporter honours OK\n";
