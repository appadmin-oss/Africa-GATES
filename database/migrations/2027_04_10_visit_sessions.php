<?php
/**
 * Arrivals become VISITS as every analytics tool defines one, and say what happened in them.
 *
 *   gates_visits.pages         page views in the visit (1 = only the landing page)
 *   gates_visits.last_seen_at  the visit's last page view, or the conversion that ended it
 *   gates_visits.is_return     1 when this browser had an earlier visit (in the session
 *                              cookie's life, which is a week): new against returning
 *   gates_visits.visitor_hash  a daily, salted hash of IP + browser: the same person is one
 *                              visitor today and cannot be linked to tomorrow
 *
 * ── WHY ────────────────────────────────────────────────────────────────────────
 *
 * A row was written once per PHP SESSION, and the session cookie lives seven days
 * (Support\SessionStore). So somebody who came back every evening for a week was one
 * arrival, every source after the first was invisible, and a vote cast on day six was
 * credited to the link that brought them on day one. GA4, Adobe and Plausible all end a
 * visit after 30 minutes without activity; this table now does the same
 * (VisitTracker::IDLE_SECONDS).
 *
 * `is_return` is not named `returning`: RETURNING is a reserved word in MariaDB, and a
 * column that only one engine accepts is the shape of fault CLAUDE.md lists first.
 *
 * Existing rows keep pages = 1 and no hash: what they recorded cannot be recovered, and
 * the report reads them as single-page visits, which is what most of them were.
 * Idempotent + driver-aware. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
if (!$schema->hasTable('gates_visits')) {
    echo "  · gates_visits absent — nothing to do\n";
    echo "visit sessions OK\n";
    return;
}
$add = static function (string $col, string $def) use ($schema): void {
    if ($schema->hasColumn('gates_visits', $col)) return;
    DB::statement("ALTER TABLE gates_visits ADD COLUMN $col $def");
    echo "  + gates_visits.$col\n";
};
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$add('pages', $sqlite ? 'INTEGER NOT NULL DEFAULT 1' : 'INT UNSIGNED NOT NULL DEFAULT 1');
$add('last_seen_at', $sqlite ? 'TEXT NULL' : 'TIMESTAMP NULL DEFAULT NULL');
$add('is_return', $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
$add('visitor_hash', $sqlite ? 'TEXT NULL' : 'CHAR(64) NULL');
echo "visit sessions OK\n";
