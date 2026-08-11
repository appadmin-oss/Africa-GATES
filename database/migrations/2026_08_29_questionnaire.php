<?php
/**
 * The questionnaire a nominee fills in, per award programme — and the other table that
 * shipped without a writer.
 *
 * ── WHAT IS MISSING TODAY ────────────────────────────────────────────────────
 *
 * A judge scores Impact, Originality, Reach and Integrity out of ten. Everything on their
 * ballot is written by somebody else: the nominator's paragraph, a photograph, and a
 * category. The interview stage (2026_08_27) added the nominee's spoken words. This adds
 * their WRITTEN case and their own evidence — the things only they have.
 *
 * `gates_nominee_evidence` has existed since 2026_08_15 with `provenance` including
 * `nominee_supplied` as a first-class value. Nothing has ever written a row to it. Its
 * sibling table got a writer last week; this is the other half, and it is the one that
 * matters most, because a nominee can produce a letter, a report, a photograph of the work
 * and a person who will vouch for it, and nobody has ever been able to ask them for any of it.
 *
 * ── WHY NOT THE EXISTING FORM BUILDER ────────────────────────────────────────
 *
 * `gates_forms` + `gates_form_submissions` already exist and can hold an admin-designed
 * form. They were considered and rejected for this, for one reason: a submission there lands
 * in `data_json` and stops. It reaches no nominee record, no rubric criterion and no judge.
 * A questionnaire that filled a table nobody reads would repeat exactly the failure this
 * migration exists to fix.
 *
 * So the questions here carry `criterion_id` — the part of the rubric an answer speaks to —
 * and `evidence_kind`, which decides what the answer BECOMES in the dossier. The whole point
 * is the journey from a nominee's typing to a judge's screen.
 *
 * ── PER PROGRAMME, WITH A DEFAULT ────────────────────────────────────────────
 *
 * `programme_id` NULL means "every programme". A platform running one programme should not
 * have to build a questionnaire before it can send one, so {@see \AfricaGates\Services\QuestionnaireService}
 * seeds a default set derived from the scoring criteria — and a programme that wants its own
 * questions overrides by slug, exactly as `gates_judge_criteria` already does for the rubric.
 *
 * ── ONE SUBMISSION PER NOMINEE PER CYCLE ─────────────────────────────────────
 *
 * Enforced by a unique index rather than by a check in code, for the same reason the claim
 * path leans on one: two half-finished submissions racing each other is a state no screen
 * can sensibly show, and the database is the only place that can actually prevent it.
 *
 * `answers_json` and `works_json` are documents belonging to exactly one submission, always
 * read whole, never queried across submissions. On SUBMIT they are copied out into
 * `gates_nominee_evidence` as rows, which is the queryable, judge-visible form. The JSON is
 * the working draft; the evidence rows are the published result.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_programme_questions')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_programme_questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programme_id INTEGER NULL,
            cycle_id INTEGER NULL,
            slug TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT 'textarea',
            label TEXT NOT NULL,
            help TEXT NULL,
            placeholder TEXT NULL,
            options_json TEXT NULL,
            criterion_id INTEGER NULL,
            evidence_kind TEXT NOT NULL DEFAULT 'note',
            is_required INTEGER NOT NULL DEFAULT 0,
            max_len INTEGER NOT NULL DEFAULT 1200,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_programme_questions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            -- NULL means every programme. Overridden per programme by slug, the same way
            -- gates_judge_criteria already works for the rubric.
            programme_id BIGINT UNSIGNED NULL,
            cycle_id BIGINT UNSIGNED NULL,
            slug VARCHAR(60) NOT NULL,
            kind ENUM('text','textarea','number','url','email','date','select','checkbox')
                NOT NULL DEFAULT 'textarea',
            label VARCHAR(300) NOT NULL,
            help VARCHAR(600) NULL,
            placeholder VARCHAR(300) NULL,
            options_json TEXT NULL,
            -- Which scoring criterion this answer speaks to. The reason this table exists
            -- rather than the generic form builder: an answer has to arrive somewhere a judge
            -- is already looking.
            criterion_id BIGINT UNSIGNED NULL,
            -- What the answer BECOMES in the dossier.
            evidence_kind ENUM('note','link','document','media','award','press') NOT NULL DEFAULT 'note',
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            max_len SMALLINT UNSIGNED NOT NULL DEFAULT 1200,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_pq_programme (programme_id, is_active, sort_order),
            KEY idx_pq_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_programme_questions created\n";
} else {
    echo "  = gates_programme_questions already present\n";
}

if (!DB::schema()->hasTable('gates_nominee_submissions')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_nominee_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nominee_id INTEGER NOT NULL,
            programme_id INTEGER NULL,
            cycle_id INTEGER NULL,
            invite_token TEXT NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            answers_json TEXT NULL,
            works_json TEXT NULL,
            invited_at TEXT NULL,
            reminded_at TEXT NULL,
            started_at TEXT NULL,
            submitted_at TEXT NULL,
            submitted_ip TEXT NULL,
            declared_name TEXT NULL,
            evidence_count INTEGER NOT NULL DEFAULT 0,
            review_note TEXT NULL,
            created_by INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_nominee_submissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nominee_id BIGINT UNSIGNED NOT NULL,
            programme_id BIGINT UNSIGNED NULL,
            cycle_id BIGINT UNSIGNED NULL,
            -- The nominee's whole credential. Same doctrine as the interview link: they have
            -- no account, and demanding one to answer questions about their own work is how
            -- the people this platform exists to find get shut out.
            invite_token CHAR(32) NULL,
            status ENUM('draft','submitted','withdrawn') NOT NULL DEFAULT 'draft',
            answers_json MEDIUMTEXT NULL,
            works_json MEDIUMTEXT NULL,
            invited_at TIMESTAMP NULL DEFAULT NULL,
            reminded_at TIMESTAMP NULL DEFAULT NULL,
            started_at TIMESTAMP NULL DEFAULT NULL,
            submitted_at TIMESTAMP NULL DEFAULT NULL,
            submitted_ip VARCHAR(45) NULL,
            -- Typed by the nominee when they submit. Not a signature in any legal sense; it
            -- is the record of who said these are their own words.
            declared_name VARCHAR(160) NULL,
            evidence_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            review_note VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            -- One per nominee per cycle, enforced HERE rather than in code: two
            -- half-finished submissions racing is a state no screen can show sensibly.
            UNIQUE KEY uq_sub_nominee_cycle (nominee_id, cycle_id),
            UNIQUE KEY uq_sub_token (invite_token),
            KEY idx_sub_status (status, submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_nominee_submissions created\n";
} else {
    echo "  = gates_nominee_submissions already present\n";
}

if ($sqlite) {
    foreach ([
        'idx_pq_programme'  => 'gates_programme_questions (programme_id, is_active, sort_order)',
        'idx_pq_slug'       => 'gates_programme_questions (slug)',
        'idx_sub_status'    => 'gates_nominee_submissions (status, submitted_at)',
    ] as $name => $on) {
        DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$on}");
    }
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_sub_nominee_cycle ON gates_nominee_submissions (nominee_id, cycle_id)");
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_sub_token ON gates_nominee_submissions (invite_token)");
    echo "  + questionnaire indexes ensured\n";
}

echo "questionnaire OK\n";
