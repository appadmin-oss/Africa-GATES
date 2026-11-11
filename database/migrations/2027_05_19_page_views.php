<?php
/**
 * How many people have seen a page (Services\PageViews; owner, 11 Nov 2026: "have the
 * numbers of views shown … put an icon there … promote your business", "make it smart").
 *
 *   gates_page_views      one row per page ("event:12", "post:5", "thread:9"): the total
 *                         and when it last moved. UNIQUE page_key.
 *   gates_page_view_days  the same, per day, so a page can say "this week" and "trending".
 *                         UNIQUE (page_key, day).
 *   gates_page_view_seen  who has been counted on a page TODAY, as VisitTracker's
 *                         daily-salted visitor hash (unreadable tomorrow, when the salt is
 *                         gone) and the network's, for the per-connection ceiling. UNIQUE
 *                         (page_key, day, who) is the "once a day" rule; rows older than
 *                         yesterday are swept by PageViews itself.
 *
 * Nothing in any of them identifies a person. Indexes through SchemaIndex (CLAUDE.md: never
 * a raw CREATE INDEX IF NOT EXISTS). Idempotent + driver-aware. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$tables = [
    'gates_page_views' => $sqlite
        ? 'id INTEGER PRIMARY KEY AUTOINCREMENT, page_key TEXT NOT NULL, views INTEGER NOT NULL DEFAULT 0, updated_at TEXT NULL'
        : 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, page_key VARCHAR(40) NOT NULL, views INT UNSIGNED NOT NULL DEFAULT 0, updated_at TIMESTAMP NULL DEFAULT NULL',
    'gates_page_view_days' => $sqlite
        ? 'id INTEGER PRIMARY KEY AUTOINCREMENT, page_key TEXT NOT NULL, day TEXT NOT NULL, views INTEGER NOT NULL DEFAULT 0'
        : 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, page_key VARCHAR(40) NOT NULL, day DATE NOT NULL, views INT UNSIGNED NOT NULL DEFAULT 0',
    'gates_page_view_seen' => $sqlite
        ? 'id INTEGER PRIMARY KEY AUTOINCREMENT, page_key TEXT NOT NULL, day TEXT NOT NULL, who TEXT NOT NULL, net TEXT NOT NULL'
        : 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, page_key VARCHAR(40) NOT NULL, day DATE NOT NULL, who CHAR(64) NOT NULL, net VARCHAR(64) NOT NULL',
];
foreach ($tables as $t => $cols) {
    if ($schema->hasTable($t)) continue;
    DB::statement("CREATE TABLE $t ($cols)" . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
    echo "  + $t\n";
}
echo SchemaIndex::ensure('gates_page_views', 'uq_pv_key', ['page_key'], true), "\n";
echo SchemaIndex::ensure('gates_page_view_days', 'uq_pvd_day', ['page_key', 'day'], true), "\n";
echo SchemaIndex::ensure('gates_page_view_seen', 'uq_pvs_who', ['page_key', 'day', 'who'], true), "\n";
echo SchemaIndex::ensure('gates_page_view_seen', 'idx_pvs_net', ['page_key', 'day', 'net'], false), "\n";
echo "page views OK\n";
