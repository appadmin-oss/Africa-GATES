<?php
/**
 * The trade a vendor says they are in, recorded on their own application.
 *
 * ── WHY THE STAND TYPE'S CATEGORY WAS NOT ALREADY THIS ───────────────────────
 *
 * `gates_stand_types.category` is the ORGANISER'S bucket: it is what a quota is set
 * against, and the quota is the whole fairness mechanism for stands — §10.1 exists so a
 * market does not end up with twelve jewellery stalls and no food. Every stand type
 * carries one, so until now a vendor's trade was whatever the pitch they picked happened
 * to be filed under.
 *
 * That is a real answer to a different question. An organiser who publishes one "3m
 * corner pitch" type under `general` learns nothing about who applied for it; two
 * vendors picking the same pitch because it is the right SIZE are recorded as the same
 * trade. And where several types share a category, the applicant had no way to say which
 * of them describes their goods.
 *
 * So this is the vendor's own declaration, stored beside the pitch they want rather than
 * inferred from it. The quota still counts stand types, which is unchanged and must stay
 * that way: a number every applicant can see before they apply cannot be recomputed from
 * what applicants later claim about themselves.
 *
 * ── AND WHY IT IS NULLABLE WITH NO DEFAULT TRADE ─────────────────────────────
 *
 * Applications already in the table were submitted on a form that never asked. Filling
 * them with 'general' would be inventing a claim on somebody's behalf, on the row a panel
 * scores. NULL reads as "not asked", which is what actually happened, and the screens
 * show the stand type's category for those rows as they always did.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_stand_applications')) {
    echo "  · gates_stand_applications is not here yet\n";
    return;
}

if (DB::schema()->hasColumn('gates_stand_applications', 'category')) {
    echo "  · gates_stand_applications.category already exists\n";
    return;
}

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// VARCHAR(60) matches gates_stand_types.category exactly. A slug that fits one table and
// is truncated by the other is a category that stops matching itself.
DB::statement($sqlite
    ? "ALTER TABLE gates_stand_applications ADD COLUMN category TEXT NULL"
    : "ALTER TABLE gates_stand_applications ADD COLUMN category VARCHAR(60) NULL AFTER stand_type_id");

// Indexed with the event, because the only question anybody asks of this column is "what
// trades applied to THIS call" — a plain index on the slug would scan every event's rows.
if (!$sqlite) {
    DB::statement("ALTER TABLE gates_stand_applications ADD KEY idx_sa_event_cat (event_id, category)");
} else {
    DB::statement("CREATE INDEX IF NOT EXISTS idx_sa_event_cat ON gates_stand_applications (event_id, category)");
}

echo "  ✓ gates_stand_applications.category\n";
