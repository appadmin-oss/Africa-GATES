<?php
/**
 * A SITTING: the interview as an appointment, before it is a transcript.
 *
 * ── WHY A SECOND TABLE, WHEN gates_nominee_interviews ALREADY EXISTS ─────────
 *
 * That table (2026_08_15) stores a transcript as evidence: the nominee's words, the
 * language they were spoken in, who transcribed them, whether consent was given. It is
 * read by {@see \AfricaGates\Services\EvidenceService}, rendered on the judge ballot,
 * and — this is the part worth saying plainly — it has never had a single writer. No
 * admin form, no importer, no route. Every dossier on the platform reads "no interview
 * on file", and always would have, because the only way in was a manual INSERT.
 *
 * So this migration is not adding a feature beside an existing one. It is building the
 * door to a room that was furnished and sealed.
 *
 * But a sitting is genuinely a different thing from a transcript, and collapsing them
 * would be wrong in both directions:
 *
 *   - A sitting exists BEFORE any words do. It is scheduled, invited, confirmed,
 *     consented to, prepared for, and may be cancelled or no-showed. A transcript row
 *     whose `transcript` column is NOT NULL cannot represent any of that.
 *   - A transcript can exist with NO sitting: a written submission, an interview
 *     conducted elsewhere, an archive recording. Requiring an appointment to store one
 *     would block the ordinary case.
 *
 * One sitting produces at most one transcript, and `transcript_id` is where it lands.
 * Until then the sitting is the record, and the transcript table stays clean.
 *
 * ── CONSENT IS A COLUMN, NOT A CHECKBOX SOMEBODY REMEMBERS ───────────────────
 *
 * These are the nominee's own words, recorded, transcribed by a machine, then read by a
 * panel that decides an award, and kept for as long as the result can be questioned. The
 * evidence table already refuses to pretend that is casual — `consent_given` is a column
 * there. It cannot be honestly filled in by staff on the nominee's behalf, so the
 * sitting captures it from the nominee directly: they open their own link, read what
 * they are agreeing to, and press the button. `consent_at` and `consent_name` are what
 * gets copied into the transcript row later.
 *
 * A sitting with no consent can still be scheduled and still be held — people say yes in
 * the room all the time — but nothing can be PUBLISHED to the panel from it. That is the
 * enforcement point, and it is in code (InterviewService::publish), not in a convention.
 *
 * ── THE TOKEN, AND WHY IT IS THE ONLY WAY IN ─────────────────────────────────
 *
 * A nominee has no account. They cannot log in, and demanding they create one to attend
 * their own interview is how attendance drops. So the invitation carries a 32-hex token
 * which is the whole credential: it opens one sitting, confirms it, records consent, and
 * nothing else. It is not a login and it grants no read of any other nominee.
 *
 * Deliberately NOT unique-per-email or reusable across sittings: one token, one sitting,
 * so a forwarded email cannot reach a second appointment.
 *
 * ── meet_code IS SEPARATE FROM meet_url ON PURPOSE ───────────────────────────
 *
 * Google's transcript artefacts are keyed by the conference, not by the URL somebody
 * pasted, and the URL may carry query parameters, an authuser, or be a shortlink. The
 * bare `abc-defg-hij` is what matches a transcript back to a sitting, so it is extracted
 * once at save time rather than re-parsed at every comparison.
 *
 * ── WHAT THE JSON COLUMNS HOLD, AND WHY THEY ARE NOT TABLES ──────────────────
 *
 *   brief_json    the interview pack: questions, each mapped to a rubric criterion
 *   answers_json  what the panel captured live, appended in order, each stamped
 *   review_json   the post-call analysis: per criterion, with quotes from the transcript
 *
 * All three are documents belonging to exactly one sitting, always read whole, never
 * queried across sittings, and never joined. A table per would buy nothing and cost
 * three joins on the one screen that matters most — the one open during a live call.
 *
 * `review_json` in particular is NOT a score. It never writes to
 * gates_judge_criteria_scores. See {@see \AfricaGates\Services\InterviewReview}.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_interviews')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_interviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nominee_id INTEGER NOT NULL,
            category_id INTEGER NULL,
            cycle_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            scheduled_at TEXT NULL,
            duration_mins INTEGER NOT NULL DEFAULT 30,
            timezone TEXT NOT NULL DEFAULT 'Africa/Lagos',
            meet_url TEXT NULL,
            meet_code TEXT NULL,
            calendar_event_id TEXT NULL,
            panel_json TEXT NULL,
            invite_token TEXT NULL,
            invited_at TEXT NULL,
            confirmed_at TEXT NULL,
            declined_at TEXT NULL,
            reschedule_note TEXT NULL,
            consent_at TEXT NULL,
            consent_name TEXT NULL,
            consent_ip TEXT NULL,
            started_at TEXT NULL,
            ended_at TEXT NULL,
            brief_json TEXT NULL,
            brief_at TEXT NULL,
            brief_source TEXT NULL,
            answers_json TEXT NULL,
            review_json TEXT NULL,
            review_at TEXT NULL,
            review_source TEXT NULL,
            transcript_id INTEGER NULL,
            language TEXT NOT NULL DEFAULT 'en',
            outcome_note TEXT NULL,
            created_by INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_interviews (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nominee_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            cycle_id BIGINT UNSIGNED NULL,
            -- draft      created, nobody told yet
            -- invited    the nominee has the link
            -- confirmed  they said yes (consent is a separate column: yes ≠ consented)
            -- declined   they said no, or asked to move it
            -- live       the panel opened the console
            -- done       held; a transcript may or may not exist yet
            -- no_show    the appointment passed with nobody there
            -- cancelled  called off by us
            status ENUM('draft','invited','confirmed','declined','live','done','no_show','cancelled')
                NOT NULL DEFAULT 'draft',
            scheduled_at TIMESTAMP NULL DEFAULT NULL,
            duration_mins SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            -- Stored, not assumed. A judge in London and a nominee in Lagos reading the
            -- same naked timestamp is how one of them attends alone.
            timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Lagos',
            meet_url VARCHAR(400) NULL,
            -- The bare abc-defg-hij. See the note above.
            meet_code VARCHAR(40) NULL,
            calendar_event_id VARCHAR(190) NULL,
            -- Judge ids on the panel for this sitting, as JSON.
            panel_json TEXT NULL,
            -- The nominee's whole credential. 32 hex, one sitting.
            invite_token CHAR(32) NULL,
            invited_at TIMESTAMP NULL DEFAULT NULL,
            confirmed_at TIMESTAMP NULL DEFAULT NULL,
            declined_at TIMESTAMP NULL DEFAULT NULL,
            reschedule_note VARCHAR(500) NULL,
            -- Consent, captured from the nominee on their own link. Nothing reaches the
            -- panel without these three.
            consent_at TIMESTAMP NULL DEFAULT NULL,
            consent_name VARCHAR(160) NULL,
            consent_ip VARCHAR(45) NULL,
            started_at TIMESTAMP NULL DEFAULT NULL,
            ended_at TIMESTAMP NULL DEFAULT NULL,
            brief_json MEDIUMTEXT NULL,
            brief_at TIMESTAMP NULL DEFAULT NULL,
            -- 'rules' when built from the rubric and dossier alone, 'ai' when a model
            -- shaped it. Printed on the console, because a panel is entitled to know
            -- which one it is holding.
            brief_source VARCHAR(20) NULL,
            answers_json MEDIUMTEXT NULL,
            review_json MEDIUMTEXT NULL,
            review_at TIMESTAMP NULL DEFAULT NULL,
            review_source VARCHAR(20) NULL,
            -- Set once the sitting has produced a published transcript.
            transcript_id BIGINT UNSIGNED NULL,
            language VARCHAR(12) NOT NULL DEFAULT 'en',
            outcome_note VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_interview_nominee (nominee_id, status),
            KEY idx_interview_when (scheduled_at),
            KEY idx_interview_status (status, scheduled_at),
            UNIQUE KEY uq_interview_token (invite_token),
            KEY idx_interview_meet (meet_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_interviews created\n";
} else {
    echo "  = gates_interviews already present\n";
}

// SQLite gets its indexes separately; MySQL declared them inline above.
if ($sqlite) {
    foreach ([
        'idx_interview_nominee' => 'nominee_id, status',
        'idx_interview_when'    => 'scheduled_at',
        'idx_interview_meet'    => 'meet_code',
    ] as $name => $cols) {
        DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON gates_interviews ({$cols})");
    }
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_interview_token ON gates_interviews (invite_token)");
    echo "  + gates_interviews indexes ensured\n";
}

echo "interviews OK\n";
