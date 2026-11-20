<?php
/**
 * Searches that found nothing — counted, so somebody can see them.
 *
 * Owner, 10 Nov 2026, on Discover ("Optimize. Improve. Research"). The one number every
 * study of site search agrees matters is how often a search finds nothing, and this platform
 * could not answer it: nothing recorded a search at all. A person who typed a nominee's name
 * wrong, or looked for a category under a word the site does not use, simply left.
 *
 * AGGREGATE AND ANONYMOUS. One row per query (folded: lower case, accents removed), with a
 * count and when it was last seen. Never who, never an IP, never a session — and a query that
 * looks like an email address or a phone number is not stored at all (SearchMisses), because
 * people paste exactly those into a search box.
 *
 * Read by the admin Analytics page ("Searches that found nothing"); see AnalyticsService.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_search_misses')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_search_misses (id INTEGER PRIMARY KEY AUTOINCREMENT, query TEXT NOT NULL, tab TEXT NOT NULL DEFAULT 'all',
           hits INTEGER NOT NULL DEFAULT 1, first_at TEXT NOT NULL, last_at TEXT NOT NULL)"
        : "CREATE TABLE gates_search_misses (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, query VARCHAR(80) NOT NULL,
           tab VARCHAR(16) NOT NULL DEFAULT 'all', hits INT UNSIGNED NOT NULL DEFAULT 1,
           first_at TIMESTAMP NULL DEFAULT NULL, last_at TIMESTAMP NULL DEFAULT NULL)
           ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_search_misses\n";
} else {
    echo "  = gates_search_misses already present\n";
}
echo SchemaIndex::ensure('gates_search_misses', 'uq_search_miss_query', ['query', 'tab'], true) . "\n";
echo SchemaIndex::ensure('gates_search_misses', 'idx_search_miss_last', ['last_at']) . "\n";

echo "search misses OK\n";
