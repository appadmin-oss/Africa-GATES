<?php
/**
 * What a vendor actually sells, as rows rather than as a paragraph.
 *
 * ── WHAT WAS THERE BEFORE ────────────────────────────────────────────────────
 *
 * `gates_stand_applications.what_they_sell` — one free-text field, up to two thousand
 * characters, written once at application time. It is the right field for "tell us about
 * your trade" and the wrong one for everything that came after it:
 *
 *   · An ORGANISER allocating a hall against published category quotas has to read forty
 *     paragraphs and decide, by eye, which of them is "food" — the decision the quota rule
 *     is supposed to make objectively. §5 of the vendor specification is only as good as
 *     the data it is applied to.
 *   · A VISITOR planning a market day cannot see what will be on sale at all.
 *   · The vendor cannot correct a price, add a line, or say "sold out" without going back
 *     to an application form that is deliberately frozen after submission.
 *
 * ── ONE TABLE, BOUND TO THE ORG AND NOT TO THE APPLICATION ───────────────────
 *
 * A catalogue belongs to the BUSINESS, not to one event. A trader who applies to three
 * market days sells the same jollof at all three, and a catalogue per application would ask
 * them to type it three times and let the three disagree.
 *
 * The stand application still carries `what_they_sell`, and still should: it is the
 * vendor's own description of their trade at the moment they applied, which is part of the
 * record the allocation was made against. The catalogue is what they sell NOW.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_vendor_items')) {
    echo "  = gates_vendor_items already present\n";
    return;
}

DB::statement($sqlite
    ? "CREATE TABLE gates_vendor_items (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         org_id INTEGER NOT NULL,
         name TEXT NOT NULL,
         category TEXT,
         -- NULLABLE, and that is the normal case for a market stall: a trader who has not
         -- decided a price yet must not be forced to publish a wrong one, and a column that
         -- defaults to 0 would print 'Free' beside a bag of rice.
         price_naira INTEGER,
         price_note TEXT,
         description TEXT,
         photo_path TEXT,
         is_available INTEGER NOT NULL DEFAULT 1,
         sort_order INTEGER NOT NULL DEFAULT 0,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TEXT
       )"
    : "CREATE TABLE gates_vendor_items (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         org_id BIGINT UNSIGNED NOT NULL,
         name VARCHAR(160) NOT NULL,
         category VARCHAR(40) DEFAULT NULL,
         price_naira INT UNSIGNED DEFAULT NULL,
         price_note VARCHAR(80) DEFAULT NULL,
         description VARCHAR(600) DEFAULT NULL,
         photo_path VARCHAR(255) DEFAULT NULL,
         is_available TINYINT(1) NOT NULL DEFAULT 1,
         -- SMALLINT and not TINYINT. A TINYINT UNSIGNED sort_order maxes out at 255 and
         -- MySQL in strict mode rejects the write rather than clamping it — which is exactly
         -- how the demo seeder took the whole sandbox down with a 500.
         sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT NULL,
         PRIMARY KEY (id),
         KEY idx_item_org (org_id, sort_order),
         KEY idx_item_cat (category)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($sqlite) {
    DB::statement('CREATE INDEX IF NOT EXISTS idx_item_org ON gates_vendor_items (org_id, sort_order)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_item_cat ON gates_vendor_items (category)');
}

echo "  + gates_vendor_items created\n";
