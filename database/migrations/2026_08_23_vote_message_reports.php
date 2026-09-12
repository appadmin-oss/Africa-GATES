<?php
/**
 * Let a reader report a message of support.
 *
 * ── WHY THIS IS NOT gates_reports ────────────────────────────────────────────
 *
 * The community reports table already exists and does exactly this job for threads
 * and comments. It cannot do it here, for two reasons that are both in its DDL:
 *
 *   `user_id BIGINT NOT NULL` — it identifies reporters by member account, and
 *   `target_type ... CHECK(target_type IN ('thread','comment'))`.
 *
 * The account requirement is the fatal one. The reader who most needs this button is
 * a stranger who followed a WhatsApp link, saw something about a named child, and
 * has no relationship with this platform at all. Asking them to register first does
 * not protect the nominee; it just means the report never happens. (The CHECK could
 * be widened, but on SQLite that means rebuilding the table — and a widened
 * target_type would still leave every vote-message report with a fabricated
 * user_id.)
 *
 * So the count lives on the message. It is deliberately a COUNTER and not a row per
 * reporter: we are not holding the identities of anonymous reporters, and per-person
 * de-duplication is done by the rate limiter (one report per network per message),
 * which is the same mechanism the cheer button uses and the strongest thing
 * available without an account.
 *
 * ── WHAT THE COUNT IS FOR ────────────────────────────────────────────────────
 *
 * At the threshold, an approved message is pulled back to `quarantined` — off the
 * page, into the queue, in front of a person. That is the whole point: the classifier
 * cleared it, and readers who can see the nominee and the context disagreed. On this
 * platform the subject of the message is often a child, so the tie goes to taking it
 * down and looking again, not to leaving it up pending review.
 *
 * `reported_at` records the LAST report, which is what a moderator sorts a queue by.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_vote_messages')) {
    echo "  ! gates_vote_messages not present — run 2026_08_22_vote_messages first\n";
    return;
}

foreach ([
    // NOT NULL DEFAULT 0 would be the tidier declaration, but ALTER TABLE ADD COLUMN
    // on SQLite refuses a NOT NULL without a constant default on some builds, and the
    // service treats null and 0 identically. The base schemas declare it NOT NULL for
    // a fresh install; this only has to get an existing table to the same place.
    'reports'     => $sqlite ? 'INTEGER DEFAULT 0' : 'INT UNSIGNED NOT NULL DEFAULT 0',
    'reported_at' => $sqlite ? 'TEXT'              : 'TIMESTAMP NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_vote_messages', $col)) {
        DB::statement("ALTER TABLE gates_vote_messages ADD COLUMN {$col} {$type}"
            . ($col === 'reported_at' ? ' DEFAULT NULL' : ''));
        echo "  + gates_vote_messages.{$col} added\n";
    } else {
        echo "  = gates_vote_messages.{$col} already present\n";
    }
}

// A moderator opening the queue wants the reported ones first. idx_vmsg_queue is
// (status, created_at), which cannot answer that without sorting the whole set.
echo \AfricaGates\Support\SchemaIndex::ensure(
    'gates_vote_messages', 'idx_vmsg_reported', ['reports', 'reported_at']
) . "\n";

echo "vote message reports OK\n";
