<?php
/**
 * The live interview: a second questionnaire style, and the tables it needs.
 *
 * ── WHY A SECOND STYLE RATHER THAN A REPLACEMENT ─────────────────────────────
 *
 * The guided form works. It has a rubric behind it, branching that stops the same thing being
 * asked twice, a coach beside the box, and a readiness panel. Nothing here removes it, and the
 * choice is per programme rather than platform-wide, because the two styles suit different
 * populations: a research institute filling in a funding case wants a form it can draft over
 * three sittings, and a market trader describing twenty years of work does not.
 *
 * ── AND WHY THE CHOSEN STYLE IS COPIED ONTO THE SUBMISSION ───────────────────
 *
 * `gates_nominee_submissions.style` is written when the submission is OPENED and never read
 * from the config afterwards. An administrator switching a programme mid-cycle must not change
 * the rules under somebody who is halfway through answering — they would return to a page that
 * had thrown away the shape of everything they had already said. The same rule `StandCall`
 * uses for its criteria, for the same reason.
 *
 * ── OUTCOMES, NOT QUESTIONS ──────────────────────────────────────────────────
 *
 * A conversation that walked a list of questions would be a form with extra latency. What the
 * interview is given instead is a list of OUTCOMES — the things it must come away with — each
 * mapped to a `criterion_id`. That is what lets the conversation go wherever the nominee takes
 * it while still converging, and it is why `QuestionnaireService::publishEvidence()` needs no
 * change: evidence still lands per criterion, it just arrives from a quote rather than a field.
 *
 * ── THE RECORD IS TWO LAYERS, AND THE SECOND ONE IS NOT TRUSTED ──────────────
 *
 * `transcript_json` is verbatim and append-only: what the nominee typed, unedited, which is the
 * doctrine `QuestionnaireChat` already holds. `gates_submission_outcomes` is the ledger the
 * model writes, and EVERY row carries a `quote` that the server has checked is a real substring
 * of a real nominee turn. That check is the whole defence against "have the AI write my
 * evidence for me": a model can summarise, it cannot invent a sentence and attribute it.
 *
 * `transcript_json` is a NEW column rather than a reuse of `chat_json`. The adaptive chat still
 * runs on the form style, the two shapes are different, and one column holding either would be
 * a column nothing could read without first guessing which feature wrote it.
 *
 * ── NOTHING HERE TURNS ANYTHING ON ───────────────────────────────────────────
 *
 * Every table lands empty and `style` defaults to 'form' — which is what every existing
 * submission already is. A deployment that never opens the builder sees no change at all.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1 · what an interview is trying to do, per programme ─────────────────────
//
// `programme_id NULL` is the platform default, and a programme row beats it — the same
// specific-over-global rule the questions, the criteria and the discount codes all use, so an
// operator only ever learns it once.
if (!DB::schema()->hasTable('gates_questionnaire_config')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_questionnaire_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programme_id INTEGER NULL,
            style TEXT NOT NULL DEFAULT 'form',
            brief TEXT NULL,
            greeting TEXT NULL,
            persona TEXT NULL,
            closing TEXT NULL,
            max_turns INTEGER NOT NULL DEFAULT 40,
            token_ceiling INTEGER NOT NULL DEFAULT 120000,
            kb_token_budget INTEGER NOT NULL DEFAULT 3000,
            route TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_questionnaire_config (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            programme_id BIGINT UNSIGNED NULL,
            -- 'form' = the guided questionnaire. 'interview' = the live conversation.
            style VARCHAR(12) NOT NULL DEFAULT 'form',
            -- The administrator's own instructions to the interviewer. Everything else on this
            -- row is a limit; this is the only field that says what the conversation is FOR.
            brief MEDIUMTEXT NULL,
            -- The opening line, written rather than generated. A first turn that costs an API
            -- call is a first turn that can fail, and the worst moment for this feature to be
            -- unavailable is the moment somebody opens it.
            greeting TEXT NULL,
            persona VARCHAR(120) NULL,
            -- Shown on the review screen, above the nominee's own words.
            closing TEXT NULL,
            -- Three ceilings, because an interview that cannot end is an interview nobody
            -- finishes, and an unbounded one is an unbounded bill.
            max_turns SMALLINT UNSIGNED NOT NULL DEFAULT 40,
            token_ceiling INT UNSIGNED NOT NULL DEFAULT 120000,
            -- How much of the knowledge base is allowed into the prompt. Retrieval is
            -- deliberately NOT built yet: it is the right answer only once a knowledge base
            -- outgrows this number, and building it before then is a search index nobody needs.
            kb_token_budget INT UNSIGNED NOT NULL DEFAULT 3000,
            -- A provider:model route pin, e.g. 'openai:gpt-4o-mini'. NULL takes the chain.
            route VARCHAR(80) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            -- One configuration per programme, in the database. Two rows would resolve
            -- unpredictably and the interview would change character between page loads.
            UNIQUE KEY uq_qcfg_programme (programme_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_questionnaire_config created\n";
} else {
    echo "  = gates_questionnaire_config already present\n";
}

// ── 2 · what the interviewer is allowed to know ──────────────────────────────
//
// Separate rows rather than one long text field on the config, because a knowledge base is
// edited a paragraph at a time by somebody who did not write the other paragraphs, and because
// the token budget has to drop entries in a defined order when the base outgrows the prompt.
if (!DB::schema()->hasTable('gates_questionnaire_knowledge')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_questionnaire_knowledge (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programme_id INTEGER NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_questionnaire_knowledge (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            programme_id BIGINT UNSIGNED NULL,
            title VARCHAR(160) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            -- The order entries are DROPPED in when the budget is exceeded is this one,
            -- reversed. An administrator who puts the award's own rules first keeps them.
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_qkb_programme (programme_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_questionnaire_knowledge created\n";
} else {
    echo "  = gates_questionnaire_knowledge already present\n";
}

// ── 3 · what the interview must come away with ───────────────────────────────
if (!DB::schema()->hasTable('gates_questionnaire_outcomes')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_questionnaire_outcomes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programme_id INTEGER NULL,
            slug TEXT NOT NULL,
            label TEXT NOT NULL,
            description TEXT NULL,
            criterion_id INTEGER NULL,
            evidence_kind TEXT NULL,
            is_required INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_questionnaire_outcomes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            programme_id BIGINT UNSIGNED NULL,
            -- The model names outcomes by slug in every tool call, and a slug outside the
            -- declared set is dropped rather than created. So this column is not a label: it
            -- is the vocabulary the conversation is allowed to use.
            slug VARCHAR(60) NOT NULL,
            label VARCHAR(160) NOT NULL,
            -- What 'met' actually looks like, in the administrator's words. This is the single
            -- most load-bearing field in the builder: an outcome described as 'impact' will be
            -- marked met by a sentence containing the word impact.
            description TEXT NULL,
            -- Where the evidence lands. Without it the interview produces a good transcript
            -- and nothing a judge's rubric can score.
            criterion_id BIGINT UNSIGNED NULL,
            evidence_kind VARCHAR(40) NULL,
            -- Required outcomes gate propose_complete. Optional ones are asked for and never
            -- insisted on — saying no to one has never cost anybody an award.
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_qout_slug (programme_id, slug),
            KEY idx_qout_programme (programme_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_questionnaire_outcomes created\n";
} else {
    echo "  = gates_questionnaire_outcomes already present\n";
}

// ── 4 · the ledger: what this nominee has actually evidenced ─────────────────
//
// One row per outcome per submission, and it is the ONLY thing the progress rail is derived
// from. Deriving progress from a question index instead is what makes a conversation feel like
// a form with extra steps: the rail must be able to jump by three, because one good paragraph
// often settles three outcomes at once.
if (!DB::schema()->hasTable('gates_submission_outcomes')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_submission_outcomes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            submission_id INTEGER NOT NULL,
            slug TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'unmet',
            summary TEXT NULL,
            quote TEXT NULL,
            turn_index INTEGER NULL,
            criterion_id INTEGER NULL,
            edited_by_nominee INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_submission_outcomes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            submission_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(60) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'unmet',
            -- The model's heading for this outcome. MACHINE-DERIVED, labelled as such on every
            -- screen it appears on, and never the thing a judge reads as the nominee's answer.
            summary TEXT NULL,
            -- The nominee's own words. Checked server-side to be a real substring of a real
            -- nominee turn before this row is written or updated. That one check is what stops
            -- the interview becoming a way to have a language model write somebody's evidence.
            quote TEXT NULL,
            -- Which turn the quote came from, so every claim on the review screen can jump to
            -- the place in the conversation it was made.
            turn_index INT UNSIGNED NULL,
            criterion_id BIGINT UNSIGNED NULL,
            -- Set when the nominee corrects a row on the review screen. A judge needs to know
            -- which headings a person accepted and which they rewrote.
            edited_by_nominee TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            -- One row per outcome per submission, enforced here: a model that recorded the
            -- same outcome twice would double the evidence a nominee appears to have.
            UNIQUE KEY uq_subout (submission_id, slug),
            KEY idx_subout_sub (submission_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_submission_outcomes created\n";
} else {
    echo "  = gates_submission_outcomes already present\n";
}

// ── 5 · corrections captured while rehearsing ────────────────────────────────
//
// An administrator testing the interview will find it doing something wrong — pressing too
// hard on a number, accepting a vague answer, using a word the sector does not use. The useful
// version of that discovery is a RULE appended to the brief, not a note in a document. Stored
// separately from `brief` so the builder can show which lines came from a real failure and
// switch one off without editing a paragraph around it.
if (!DB::schema()->hasTable('gates_questionnaire_rules')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_questionnaire_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programme_id INTEGER NULL,
            body TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'hand',
            note TEXT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_questionnaire_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            programme_id BIGINT UNSIGNED NULL,
            body VARCHAR(500) NOT NULL,
            -- 'rehearsal' when captured from a turn that went wrong, 'hand' when typed. The
            -- distinction is worth keeping: a rule with a failing turn behind it is one
            -- somebody can re-test, and one that was typed is an opinion.
            source VARCHAR(12) NOT NULL DEFAULT 'hand',
            note VARCHAR(500) NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_qrule_programme (programme_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_questionnaire_rules created\n";
} else {
    echo "  = gates_questionnaire_rules already present\n";
}

// ── 6 · saved rehearsals, so a fix can be proved not to break the last one ───
if (!DB::schema()->hasTable('gates_questionnaire_cases')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_questionnaire_cases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programme_id INTEGER NULL,
            title TEXT NOT NULL,
            persona TEXT NULL,
            transcript_json TEXT NULL,
            expect_json TEXT NULL,
            last_run_at TEXT NULL,
            last_result TEXT NULL,
            created_by INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_questionnaire_cases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            programme_id BIGINT UNSIGNED NULL,
            title VARCHAR(160) NOT NULL,
            -- Which difficult nominee this was: the one-word answerer, the one who talks
            -- around the question, the one with no numbers. A regression suite made only of
            -- cooperative nominees proves nothing about the population it will meet.
            persona VARCHAR(60) NULL,
            -- The nominee side of a rehearsal, replayed against the current brief.
            transcript_json MEDIUMTEXT NULL,
            -- Which outcomes this replay is expected to reach.
            expect_json TEXT NULL,
            last_run_at TIMESTAMP NULL DEFAULT NULL,
            last_result VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_qcase_programme (programme_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_questionnaire_cases created\n";
} else {
    echo "  = gates_questionnaire_cases already present\n";
}

// ── 7 · what a submission remembers about its own interview ──────────────────
if (DB::schema()->hasTable('gates_nominee_submissions')) {
    foreach ([
        // Copied from the config when the submission is OPENED, never read live. See the
        // header: a mid-cycle switch must not rewrite the rules under somebody halfway through.
        'style'           => $sqlite ? 'TEXT' : "VARCHAR(12) NULL",
        // Verbatim, append-only, and NOT `chat_json` — see the header.
        'transcript_json' => $sqlite ? 'TEXT' : 'MEDIUMTEXT NULL',
        // 'talk' | 'show' | 'review'. The conversation has no step count; the files phase does,
        // and saying so is what stops a nominee thinking the interview restarted.
        'interview_phase' => $sqlite ? 'TEXT' : "VARCHAR(10) NULL",
        // The running total the ceiling is enforced against. Two columns rather than one,
        // because input and output cost different amounts and a single number cannot be
        // priced afterwards.
        'ai_tokens_in'    => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
        'ai_tokens_out'   => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
        // When the model proposed finishing. Kept because the gap between "the AI thought it
        // had enough" and "the person pressed send" is the thing worth watching in the first
        // cycle this runs.
        'proposed_at'     => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_nominee_submissions', $col)) {
            DB::statement("ALTER TABLE gates_nominee_submissions ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_nominee_submissions.{$col} added\n";
        } else {
            echo "  = gates_nominee_submissions.{$col} already present\n";
        }
    }

    // Every row that predates this is a guided form, because that is the only thing that has
    // ever existed. Leaving them NULL would make the style a question every reader has to
    // answer with a fallback, and one of those readers would eventually answer it differently.
    $backfilled = DB::table('gates_nominee_submissions')->whereNull('style')->update(['style' => 'form']);
    if ($backfilled > 0) echo "  + {$backfilled} existing submission(s) marked style=form\n";
}

if ($sqlite) {
    foreach ([
        'idx_qkb_programme'   => 'gates_questionnaire_knowledge (programme_id, is_active, sort_order)',
        'idx_qout_programme'  => 'gates_questionnaire_outcomes (programme_id, is_active, sort_order)',
        'uq_qout_slug'        => null,
        'idx_subout_sub'      => 'gates_submission_outcomes (submission_id, status)',
        'idx_qrule_programme' => 'gates_questionnaire_rules (programme_id, is_active, sort_order)',
        'idx_qcase_programme' => 'gates_questionnaire_cases (programme_id)',
    ] as $name => $on) {
        if ($on === null) continue;
        DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$on}");
    }
    // SQLite cannot declare a UNIQUE inside CREATE TABLE and then be told about it, so the
    // two that carry meaning are made here. Both are correctness, not speed: without them a
    // duplicate outcome slug or a duplicated ledger row is a silent doubling of evidence.
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_qout_slug ON gates_questionnaire_outcomes (programme_id, slug)");
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_subout ON gates_submission_outcomes (submission_id, slug)");
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_qcfg_programme ON gates_questionnaire_config (programme_id)");
}

echo "questionnaire interview OK\n";
