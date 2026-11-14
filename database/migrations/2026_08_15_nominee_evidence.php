<?php
/**
 * The evidence a judge scores against — and the nominee's own words.
 *
 * ── THE PROBLEM THIS SOLVES ──────────────────────────────────────────────────
 *
 * A judge is asked to score Impact, Originality, Reach and Integrity out of ten, and
 * what the ballot gives them is a name, a country flag and a text box. Four numbers
 * that become 55% of a nominee's CPI are currently produced from almost nothing, which
 * means they are produced from whatever the judge already believes, or from the one
 * paragraph a nominator wrote to persuade somebody.
 *
 * Two tables, because they answer different questions.
 *
 * ── gates_nominee_evidence: WHERE DID THIS CLAIM COME FROM? ──────────────────
 *
 * `provenance` is NOT optional and has no default. The single most important fact about
 * any line in a dossier is who is asserting it, and the platform already learned this
 * the expensive way in docs/CLAIM-FAIRNESS-AND-FRAUD.md §1: the email on a nomination
 * was typed by the NOMINATOR, so it is a claim about the nominee rather than proof from
 * them. A nomination reason is the same shape — an interested party's written case,
 * often the strongest prose in the dossier — and rendering it beside a verified award
 * record with no visual difference invites a judge to score the writing.
 *
 * So the column is an enum a human must choose from, and `nominator_claim` is a
 * first-class value rather than something inferred from a blank field.
 *
 * ── gates_nominee_interviews: WHOSE WORDS, IN WHICH LANGUAGE? ────────────────
 *
 * A transcript looks like fact and is a chain of interpretations: somebody recorded,
 * somebody (or something) transcribed, and often somebody translated. Each step can put
 * words in a nominee's mouth, and a judge reading "we reached about fifty people" cannot
 * tell whether the hedge is the nominee's or the transcriber's.
 *
 * That is not hypothetical for this audience. Interviews here will be conducted in
 * Yoruba, Hausa, Igbo and Pidgin as well as English, and `translated_from` exists so a
 * judge is told plainly when they are reading a translation rather than a person. The
 * platform's own fairness rules already require plain, translatable language on the
 * claim path (§7.5); reading in the other direction deserves the same honesty.
 *
 * `transcript_source` separates human from machine because they fail differently — a
 * model mishears proper nouns and numbers, which are exactly the load-bearing facts in
 * an impact claim.
 *
 * `consent_given` because these are the nominee's words, recorded, then shown to a panel
 * that decides an award. Storing the transcript without recording that they agreed to
 * it would make this table the most sensitive thing on the platform and the least
 * accounted for.
 *
 * ── WHAT IS DELIBERATELY ABSENT ──────────────────────────────────────────────
 *
 * Nothing here records votes, rank, popularity or another judge's opinion, and
 * {@see \AfricaGates\Services\EvidenceService} refuses to carry them. A dossier that
 * mentioned a nominee's vote count would collapse the 45% community signal into the 55%
 * expert one and make the weighting a fiction.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_nominee_interviews')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_nominee_interviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nominee_id INTEGER NOT NULL,
            interviewed_at TEXT NULL,
            interviewer TEXT NULL,
            medium TEXT NOT NULL DEFAULT 'video',
            language TEXT NOT NULL DEFAULT 'en',
            translated_from TEXT NULL,
            transcript TEXT NOT NULL,
            transcript_source TEXT NOT NULL DEFAULT 'human',
            transcriber TEXT NULL,
            source_ref TEXT NULL,
            consent_given INTEGER NOT NULL DEFAULT 0,
            consent_note TEXT NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            created_by INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_nominee_interviews (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nominee_id BIGINT UNSIGNED NOT NULL,
            interviewed_at TIMESTAMP NULL DEFAULT NULL,
            interviewer VARCHAR(160) NULL,
            medium ENUM('in_person','phone','video','written') NOT NULL DEFAULT 'video',
            -- ISO-639-1 of the language the transcript IS IN.
            language VARCHAR(12) NOT NULL DEFAULT 'en',
            -- Set when the transcript was translated. NULL means the nominee's own
            -- language is what the judge is reading.
            translated_from VARCHAR(12) NULL,
            transcript MEDIUMTEXT NOT NULL,
            -- Human and machine transcripts fail differently: a model mishears proper
            -- nouns and numbers, which are the load-bearing facts in an impact claim.
            transcript_source ENUM('human','machine','hybrid') NOT NULL DEFAULT 'human',
            transcriber VARCHAR(160) NULL,
            -- Where the recording lives, so a disputed line can be checked against it.
            source_ref VARCHAR(400) NULL,
            consent_given TINYINT(1) NOT NULL DEFAULT 0,
            consent_note VARCHAR(400) NULL,
            -- draft is invisible to judges; withdrawn survives for the record but stops
            -- being evidence the moment a nominee retracts consent.
            status ENUM('draft','published','withdrawn') NOT NULL DEFAULT 'draft',
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_interview_nominee (nominee_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_nominee_interviews created\n";
} else {
    echo "  = gates_nominee_interviews already present\n";
}

if (!DB::schema()->hasTable('gates_nominee_evidence')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_nominee_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nominee_id INTEGER NOT NULL,
            kind TEXT NOT NULL DEFAULT 'note',
            title TEXT NOT NULL,
            body TEXT NULL,
            source_label TEXT NULL,
            source_url TEXT NULL,
            provenance TEXT NOT NULL,
            verified INTEGER NOT NULL DEFAULT 0,
            verified_by INTEGER NULL,
            verified_at TEXT NULL,
            interview_id INTEGER NULL,
            visible_to_judges INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_nominee_evidence (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nominee_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('nomination','interview','document','link','media','award','press','note')
                NOT NULL DEFAULT 'note',
            title VARCHAR(250) NOT NULL,
            body MEDIUMTEXT NULL,
            source_label VARCHAR(250) NULL,
            source_url VARCHAR(600) NULL,
            -- NO DEFAULT, on purpose. Whoever adds a line to a dossier must say who is
            -- asserting it; a default would let the commonest and weakest kind of
            -- evidence — an interested party's claim — arrive unlabelled and be read as
            -- established fact. See the note at the top of this file.
            provenance ENUM('nominee_supplied','nominator_claim','platform_verified',
                            'third_party','staff_note') NOT NULL,
            -- Separate from provenance: `platform_verified` says WHO checked it, this
            -- says THAT somebody did, and when.
            verified TINYINT(1) NOT NULL DEFAULT 0,
            verified_by BIGINT UNSIGNED NULL,
            verified_at TIMESTAMP NULL DEFAULT NULL,
            interview_id BIGINT UNSIGNED NULL,
            visible_to_judges TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_evidence_nominee (nominee_id, visible_to_judges, sort_order),
            KEY idx_evidence_interview (interview_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_nominee_evidence created\n";
} else {
    echo "  = gates_nominee_evidence already present\n";
}

echo "nominee evidence OK\n";
