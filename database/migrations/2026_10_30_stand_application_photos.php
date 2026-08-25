<?php
/**
 * Photographs of what a vendor sells, attached to one stand application.
 *
 * ── WHY A PHOTOGRAPH IS PART OF AN APPLICATION AT ALL ────────────────────────
 *
 * Two scorers read a stand application without ever meeting the applicant, and every
 * other field on the form is a claim: the trade name, the description of what is sold,
 * the category. A photograph is the only thing on it that SHOWS what a description
 * asserts, and it is the fact a market organiser actually decides on.
 *
 * ── AND WHY IT IS COMPLETENESS AND NOT ELIGIBILITY ───────────────────────────
 *
 * Eligibility is a rule with no judgement in it — the certificates exist, are in date
 * and name you. Photographs are not that, and making them a rule would fail applications
 * from exactly the people this platform is for: somebody photographing their goods on a
 * borrowed phone, from a market with no signal, the evening before the deadline.
 *
 * So they sit on the same shelf as a certificate for COMPLETENESS, which is the §5.4
 * tiebreak, and nowhere near the eligibility gate. An application without them can be
 * submitted, is read, and can win — it simply is not complete until three are on file,
 * and where two applications are otherwise equal the complete one goes first.
 *
 * ── VISIBILITY ───────────────────────────────────────────────────────────────
 *
 * These rows belong to the vendor and to an integrity-gated admin, and to nobody else,
 * until the application's decision is `accepted`. The form says so; the controller
 * enforces it. `org_id` is denormalised onto the row for exactly that reason — the
 * dashboard scopes to the caller's own rows without a join it could forget to write.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (DB::schema()->hasTable('gates_stand_application_photos')) {
    echo "  · gates_stand_application_photos already exists\n";
    return;
}

$sqlite = DB::connection()->getDriverName() === 'sqlite';

DB::statement($sqlite
    ? "CREATE TABLE gates_stand_application_photos (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         application_id INTEGER NOT NULL,
         org_id INTEGER NOT NULL,
         path TEXT NOT NULL,
         width INTEGER NOT NULL DEFAULT 0,
         height INTEGER NOT NULL DEFAULT 0,
         bytes INTEGER NOT NULL DEFAULT 0,
         -- 0 is the cover: the one published beside the vendor's name on the stand list,
         -- and only once an offer has been accepted.
         sort_order INTEGER NOT NULL DEFAULT 0,
         created_at TEXT NOT NULL
       )"
    : "CREATE TABLE gates_stand_application_photos (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         application_id BIGINT UNSIGNED NOT NULL,
         org_id BIGINT UNSIGNED NOT NULL,
         path VARCHAR(255) NOT NULL,
         width INT UNSIGNED NOT NULL DEFAULT 0,
         height INT UNSIGNED NOT NULL DEFAULT 0,
         -- INT, not SMALLINT or MEDIUMINT. The cap is 10 MB and MEDIUMINT stops at
         -- 8,388,607 — on a host that overrides sql_mode away from strict, MySQL would
         -- have CLAMPED a 9 MB upload to that and reported success.
         bytes INT UNSIGNED NOT NULL DEFAULT 0,
         sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
         created_at TIMESTAMP NOT NULL,
         PRIMARY KEY (id),
         KEY idx_sap_app (application_id, sort_order),
         KEY idx_sap_org (org_id),
         CONSTRAINT fk_sap_app FOREIGN KEY (application_id)
           REFERENCES gates_stand_applications(id) ON DELETE CASCADE
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($sqlite) {
    DB::statement('CREATE INDEX IF NOT EXISTS idx_sap_app ON gates_stand_application_photos (application_id, sort_order)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_sap_org ON gates_stand_application_photos (org_id)');
}

echo "  + gates_stand_application_photos created\n";
