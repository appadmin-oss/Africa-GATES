<?php
/**
 * Vendor stands — phase 1 of docs/VENDOR-STANDS-SPEC.md.
 *
 * ── A VENDOR IS AN ORGANISATION, NOT A NEW KIND OF PARTY ─────────────────────
 *
 * `gates_partner_orgs` gains a `kind` rather than this migration creating a second table of
 * businesses. A donation partner and a vendor need the same five things — an account in
 * their own registered name, a subaccount created without storing the account number, a
 * dashboard scoped to their own rows, documents with expiries, and a vetting state machine
 * where suspension is distinct from rejection. Building a parallel `gates_vendors` would
 * mean writing all five again and then maintaining two of each.
 *
 * What differs is the DOCUMENTS, and that is a rule, not a schema: SCUML registration is
 * mandatory for an NGO collecting donations and irrelevant to somebody selling jewellery,
 * while public liability insurance is the reverse. {@see PartnerOrg::requiredDocuments()}
 * branches on `kind`; the tables do not.
 *
 * ── THE CALL IS LOCKED, AND THAT IS THE WHOLE FAIRNESS MECHANISM ─────────────
 *
 * `gates_stand_calls.locked_at` is the timestamp the criteria stopped being editable. §5.1
 * of the specification says criteria, quotas, prices and the closing date are published
 * before applications open and cannot change afterwards — because a rule that can be edited
 * once you know who applied is not a rule.
 *
 * So the criteria are COPIED onto the call as JSON at lock time rather than referenced live.
 * Referencing them would mean an applicant's rejection could be justified by a criterion
 * written after they applied, and nothing in the record would show it.
 *
 * ── AND A QUOTA IS A PUBLISHED NUMBER, NOT A PREFERENCE ──────────────────────
 *
 * `gates_stand_types.quota` is how many of that stand type exist, and it is published with
 * the call. It is what stops twelve jewellery stalls and no food without anybody applying a
 * private hand to the scale — the constraint is the number everyone can see. §10.1 of the
 * specification works through why unfilled places in one category do not migrate to another.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$pdo    = DB::connection()->getPdo();

// ── 1 · an organisation is a donation partner or a vendor ────────────────────
try {
    if (!DB::schema()->hasColumn('gates_partner_orgs', 'kind')) {
        $pdo->exec("ALTER TABLE gates_partner_orgs ADD COLUMN kind "
                 . ($sqlite ? "TEXT NOT NULL DEFAULT 'partner'"
                            : "VARCHAR(16) NOT NULL DEFAULT 'partner'"));
    }
} catch (\Throwable $e) {
    echo "*** could not add gates_partner_orgs.kind: {$e->getMessage()}\n";
}

// ── 2 · stand types: what a vendor can apply FOR ─────────────────────────────
//
// Deliberately not ticket tiers. A tier is bought by whoever pays first; a stand type is
// applied for and allocated. Sharing the table would mean every ticket query had to learn to
// exclude stands, which is the kind of coupling that produces "why is this stand on sale".
$types = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_stand_types (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         event_id INTEGER NOT NULL,
         slug TEXT NOT NULL,
         name TEXT NOT NULL,
         category TEXT NOT NULL DEFAULT 'general',
         description TEXT,
         price_naira INTEGER NOT NULL DEFAULT 0,
         deposit_naira INTEGER NOT NULL DEFAULT 0,
         quota INTEGER NOT NULL DEFAULT 0,
         includes_power INTEGER NOT NULL DEFAULT 0,
         step_free INTEGER NOT NULL DEFAULT 0,
         sort_order INTEGER NOT NULL DEFAULT 0,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_stand_types (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         event_id BIGINT UNSIGNED NOT NULL,
         slug VARCHAR(80) NOT NULL,
         name VARCHAR(160) NOT NULL,
         category VARCHAR(60) NOT NULL DEFAULT 'general',
         description TEXT,
         price_naira INT UNSIGNED NOT NULL DEFAULT 0,
         deposit_naira INT UNSIGNED NOT NULL DEFAULT 0,
         quota INT UNSIGNED NOT NULL DEFAULT 0,
         includes_power TINYINT(1) NOT NULL DEFAULT 0,
         step_free TINYINT(1) NOT NULL DEFAULT 0,
         sort_order INT NOT NULL DEFAULT 0,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         UNIQUE KEY uq_stand_type_slug (event_id, slug),
         KEY idx_stand_type_event (event_id, category)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// ── 3 · the call: one application window per event ───────────────────────────
$calls = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_stand_calls (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         event_id INTEGER NOT NULL,
         status TEXT NOT NULL DEFAULT 'draft',
         intro TEXT,
         criteria_json TEXT,
         opens_at TEXT,
         closes_at TEXT,
         locked_at TEXT,
         locked_by INTEGER,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TEXT
       )"
    : "CREATE TABLE IF NOT EXISTS gates_stand_calls (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         event_id BIGINT UNSIGNED NOT NULL,
         status VARCHAR(16) NOT NULL DEFAULT 'draft',
         intro TEXT,
         criteria_json TEXT,
         opens_at TIMESTAMP NULL DEFAULT NULL,
         closes_at TIMESTAMP NULL DEFAULT NULL,
         locked_at TIMESTAMP NULL DEFAULT NULL,
         locked_by BIGINT UNSIGNED DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_call_event (event_id),
         KEY idx_call_status (status, closes_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// ── 4 · applications ─────────────────────────────────────────────────────────
//
// `completed_at` is separate from `submitted_at` because §5.4 breaks ties on the earliest
// COMPLETE application, not the earliest submission — otherwise the fastest incomplete form
// beats the careful one. It is stamped when the last required document lands.
//
// `eligibility` and `decision` are two columns rather than one status, because §3 makes them
// two stages with different characters: eligibility is objective and explainable in one
// sentence, selection is judgement and is the only thing needing a recorded rationale.
// Collapsing them is what makes a rejection feel arbitrary.
$apps = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_stand_applications (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         call_id INTEGER NOT NULL,
         event_id INTEGER NOT NULL,
         org_id INTEGER NOT NULL,
         stand_type_id INTEGER NOT NULL,
         what_they_sell TEXT,
         needs_power INTEGER NOT NULL DEFAULT 0,
         needs_step_free INTEGER NOT NULL DEFAULT 0,
         eligibility TEXT NOT NULL DEFAULT 'unchecked',
         eligibility_note TEXT,
         decision TEXT NOT NULL DEFAULT 'pending',
         decision_reason TEXT,
         decided_by INTEGER,
         decided_at TEXT,
         offer_expires_at TEXT,
         payment_ref TEXT,
         submitted_at TEXT,
         completed_at TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
       )"
    : "CREATE TABLE IF NOT EXISTS gates_stand_applications (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         call_id BIGINT UNSIGNED NOT NULL,
         event_id BIGINT UNSIGNED NOT NULL,
         org_id BIGINT UNSIGNED NOT NULL,
         stand_type_id BIGINT UNSIGNED NOT NULL,
         what_they_sell TEXT,
         needs_power TINYINT(1) NOT NULL DEFAULT 0,
         needs_step_free TINYINT(1) NOT NULL DEFAULT 0,
         eligibility VARCHAR(16) NOT NULL DEFAULT 'unchecked',
         eligibility_note VARCHAR(400) DEFAULT NULL,
         decision VARCHAR(16) NOT NULL DEFAULT 'pending',
         decision_reason VARCHAR(400) DEFAULT NULL,
         decided_by BIGINT UNSIGNED DEFAULT NULL,
         decided_at TIMESTAMP NULL DEFAULT NULL,
         offer_expires_at TIMESTAMP NULL DEFAULT NULL,
         payment_ref VARCHAR(200) DEFAULT NULL,
         submitted_at TIMESTAMP NULL DEFAULT NULL,
         completed_at TIMESTAMP NULL DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         UNIQUE KEY uq_app_once (call_id, org_id, stand_type_id),
         KEY idx_app_call (call_id, decision),
         KEY idx_app_org (org_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

foreach ([$types, $calls, $apps] as $sql) {
    $pdo->exec($sql);
}

// SQLite cannot declare composite UNIQUE inline the way the MySQL branch does above.
if ($sqlite) {
    foreach ([
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_stand_type_slug ON gates_stand_types (event_id, slug)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_call_event ON gates_stand_calls (event_id)',
        // One application per organisation per stand type per call. A vendor may apply for
        // two DIFFERENT stand types, which is legitimate — they may want a food pitch or a
        // craft pitch and will take either — but not twice for the same one.
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_app_once ON gates_stand_applications (call_id, org_id, stand_type_id)',
    ] as $ix) {
        try { $pdo->exec($ix); } catch (\Throwable $e) { echo "*** $ix: {$e->getMessage()}\n"; }
    }
}

foreach (['gates_stand_types', 'gates_stand_calls', 'gates_stand_applications'] as $t) {
    echo DB::schema()->hasTable($t) ? "$t OK\n" : "*** $t FAILED ***\n";
}
echo DB::schema()->hasColumn('gates_partner_orgs', 'kind') ? "gates_partner_orgs.kind OK\n" : "*** kind FAILED ***\n";
