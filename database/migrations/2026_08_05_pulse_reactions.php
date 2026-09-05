<?php
/**
 * Four reactions instead of one cheer, and reposts.
 *
 * ── WHY A `kind` COLUMN AND NOT FOUR TABLES ──────────────────────────────────
 *
 * `gates_cheers` already carries the exact key this needs: UNIQUE on
 * (target_type, target_id, fp) — one row per person per thing. Adding a kind to
 * that row keeps the rule "one reaction each, changeable", which is what every
 * feed that has ever shipped reactions settled on and what the counters already
 * assume.
 *
 * Four tables, or a kind INSIDE the unique key, would both give one person four
 * simultaneous reactions on the same post. That is not a richer feature, it is a
 * broken one: the counts stop summing to the number of people, and "1.2k
 * reactions" stops meaning anything.
 *
 * ── AND WHY THE DEFAULT IS 'cheer' ───────────────────────────────────────────
 *
 * Every existing row was a cheer. Backfilling them as anything else would
 * rewrite what people actually did, and a NULL kind would mean every read has to
 * cope with a fifth, nameless reaction forever.
 *
 * ── REPOSTS ──────────────────────────────────────────────────────────────────
 *
 * `gates_reposts` and `toggleRepost()` have both existed since 2026_06_29 —
 * members-only, one per member per thread, which is right and stays. What has
 * never existed is a line of your own to go with it, and a bare repost is a
 * bookmark with extra steps. That is the column added below.
 *
 * `gates_threads.repost_count` is rebuilt at the end so it starts TRUE rather
 * than merely zero: it has been maintained by toggleRepost all along, but any
 * database that predates that path, or that had rows removed by hand, has been
 * carrying a number nobody has ever checked.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── reactions ────────────────────────────────────────────────────────────────
if (DB::schema()->hasTable('gates_cheers') && !DB::schema()->hasColumn('gates_cheers', 'kind')) {
    DB::statement("ALTER TABLE gates_cheers ADD COLUMN kind "
        . ($sqlite ? "TEXT NOT NULL DEFAULT 'cheer'" : "VARCHAR(12) NOT NULL DEFAULT 'cheer'"));
    echo "  + gates_cheers.kind added\n";
} else {
    echo "  = gates_cheers.kind already present\n";
}

// Counting reactions BY KIND on a feed page is the one query this feature adds
// to the hot path — the rail shows a breakdown, not just a total.
try {
    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cheers_kind ON gates_cheers(target_type, target_id, kind)');
    } else {
        $has = DB::select("SHOW INDEX FROM gates_cheers WHERE Key_name = 'idx_cheers_kind'");
        if (!$has) DB::statement('CREATE INDEX idx_cheers_kind ON gates_cheers(target_type, target_id, kind)');
    }
    echo "  = idx_cheers_kind ready\n";
} catch (\Throwable $e) {
    echo "  ! reaction index skipped: " . $e->getMessage() . "\n";
}

// ── reposts ──────────────────────────────────────────────────────────────────
// The TABLE already exists (2026_06_29_community_social) keyed on
// (user_id, thread_id) — reposting has always been members-only, which is right.
// What it has never had is the thing that makes a repost worth making: a line of
// your own. A bare repost is a bookmark with extra steps.
if (DB::schema()->hasTable('gates_reposts') && !DB::schema()->hasColumn('gates_reposts', 'comment')) {
    DB::statement('ALTER TABLE gates_reposts ADD COLUMN comment '
        . ($sqlite ? 'TEXT' : 'VARCHAR(500)') . ' NULL DEFAULT NULL');
    echo "  + gates_reposts.comment added\n";
} else {
    echo "  = gates_reposts.comment already present\n";
}

// The counter that has been sitting at zero since the community schema shipped.
// Rebuilt from the table so it starts true rather than merely zero.
try {
    if ($sqlite) {
        DB::statement('UPDATE gates_threads SET repost_count =
            (SELECT COUNT(*) FROM gates_reposts r WHERE r.thread_id = gates_threads.id)');
    } else {
        DB::statement('UPDATE gates_threads t LEFT JOIN
            (SELECT thread_id, COUNT(*) c FROM gates_reposts GROUP BY thread_id) r
            ON r.thread_id = t.id SET t.repost_count = COALESCE(r.c, 0)');
    }
    echo "  = repost_count rebuilt\n";
} catch (\Throwable $e) {
    echo "  ! repost_count rebuild skipped: " . $e->getMessage() . "\n";
}

echo "pulse reactions OK\n";
