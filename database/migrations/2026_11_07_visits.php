<?php
/**
 * Where the people who arrive here actually come from.
 *
 * ── THE QUESTION NOTHING COULD ANSWER ────────────────────────────────────────
 *
 * `AnalyticsService` reports what happened AFTER somebody arrived — users, votes,
 * nominations, the ballot funnel, geography off the vote rows. All of it counted from the
 * domain tables, which is the right way to count it.
 *
 * None of it can say where anybody came from. This platform's entire reach is links: a
 * nominee's share card, an event flier, a WhatsApp forward, a countdown letter, a QR on a
 * printed ticket. An organiser asking "the flier went out on Tuesday — did anything come
 * of it?" had nothing to look at, and no shell to look with.
 *
 * ── ONE ROW PER ARRIVAL, NOT PER PAGE VIEW ───────────────────────────────────
 *
 * A page-view table is the obvious shape and it is the wrong one here. On a shared host
 * with no shell it grows without bound, it needs a pruner nobody remembers to write, and
 * it answers a question nobody is asking — "which page was viewed forty thousand times"
 * is already answerable from the domain tables for every page that matters.
 *
 * What is genuinely unanswerable is attribution: how many people arrived from that
 * WhatsApp forward, and how many of them did anything. So one row per SESSION, written
 * once on the first request and updated once if it converts. A busy day is thousands of
 * rows, not millions.
 *
 * ── WHAT IS DELIBERATELY NOT STORED ──────────────────────────────────────────
 *
 * No IP address — a hash, for counting distinct arrivals and nothing else.
 *
 * No full referrer URL. Only the HOST. A referrer path carries a person's search terms,
 * the private page they were reading, sometimes a token in a query string; the host is
 * the whole of what attribution needs and none of what it does not.
 *
 * No landing QUERY STRING either, for the same reason and one sharper: this platform puts
 * live credentials in links. An invitation pass, a magic sign-in, a claim token, an
 * unsubscribe link — storing the query of a landing page would copy those into a table
 * that exists to be read by operators and exported to a spreadsheet.
 *
 * No user-agent string. A device CLASS, which is what a report shows, and which cannot be
 * combined with anything else here to re-identify a reader.
 *
 * `visit_key` is NOT the PHP session id. A session id is a credential; a table of them
 * readable from the admin console is a table of live logins.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (DB::schema()->hasTable('gates_visits')) {
    echo "  · gates_visits already exists\n";
    echo "visits OK\n";
    return;
}

$sqlite = DB::connection()->getDriverName() === 'sqlite';

DB::statement($sqlite
    ? "CREATE TABLE gates_visits (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         visit_key TEXT NOT NULL UNIQUE,
         source TEXT NOT NULL DEFAULT 'direct',
         medium TEXT NULL,
         campaign TEXT NULL,
         referrer_host TEXT NULL,
         landing_path TEXT NOT NULL,
         device TEXT NOT NULL DEFAULT 'unknown',
         country TEXT NULL,
         ip_hash TEXT NULL,
         converted_kind TEXT NULL,
         converted_at TEXT NULL,
         created_at TEXT NOT NULL
       )"
    : "CREATE TABLE gates_visits (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         -- Random per session and never the session id itself, which is a credential.
         visit_key CHAR(32) NOT NULL,
         -- 'whatsapp', 'facebook', a utm_source, or 'direct'. VARCHAR and not an ENUM:
         -- the set is whatever an organiser puts in a campaign link, and a value outside
         -- an ENUM is stored as '' under a non-strict host — which is how a whole
         -- campaign's arrivals come to be counted under no source at all.
         source VARCHAR(60) NOT NULL DEFAULT 'direct',
         medium VARCHAR(60) NULL,
         campaign VARCHAR(80) NULL,
         -- The HOST only. A referrer path carries search terms and private page paths.
         referrer_host VARCHAR(120) NULL,
         -- The PATH only, never the query: this platform puts live credentials in links.
         landing_path VARCHAR(190) NOT NULL,
         device VARCHAR(12) NOT NULL DEFAULT 'unknown',
         country CHAR(2) NULL,
         ip_hash CHAR(64) NULL,
         -- What this arrival led to, if anything. NULL is the ordinary case and means
         -- 'nothing yet', not 'nothing ever'.
         converted_kind VARCHAR(40) NULL,
         converted_at TIMESTAMP NULL DEFAULT NULL,
         created_at TIMESTAMP NOT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_visit_key (visit_key),
         -- The two reads this table exists for: a date range, and a date range grouped
         -- by where people came from.
         KEY idx_visit_created (created_at),
         KEY idx_visit_source (source, created_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($sqlite) {
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_visit_key ON gates_visits (visit_key)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_visit_created ON gates_visits (created_at)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_visit_source ON gates_visits (source, created_at)');
}

echo "  + gates_visits created\n";
echo "visits OK\n";
