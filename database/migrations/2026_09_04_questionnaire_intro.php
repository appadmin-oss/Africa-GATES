<?php
/**
 * What is expected of a nominee, said first — and a minute of them saying who they are.
 *
 * ── THE BRIEF ────────────────────────────────────────────────────────────────
 *
 * The questionnaire opened with a greeting and then a question. A nominee had no idea how
 * long it would take, what a good answer looked like, whether a half-finished draft was
 * safe, or what would happen to any of it — and the people most affected by not knowing
 * are the ones least likely to ask. So the brief is now its own stage that has to be
 * passed, and `intro_seen_at` records that it was.
 *
 * It is not a checkbox. It is the difference between an answer of two sentences and one
 * that gives a panel something to judge, and it costs the nominee twenty seconds.
 *
 * ── AND THE SPOKEN INTRODUCTION ──────────────────────────────────────────────
 *
 * Before the questions, a nominee records up to {@see \AfricaGates\Services\QuestionnaireIntro::MAX_SECONDS}
 * seconds of themselves. Not as a substitute for the written answers — as the thing a
 * dossier has never had: the person, in their own voice, unedited.
 *
 * ── THIS AUDIO IS KEPT, AND THAT IS A DEPARTURE ──────────────────────────────
 *
 * A spoken ANSWER is transcribed and the recording is thrown away — the words are what
 * the judges read, the nominee corrects them before sending, and keeping the audio would
 * be holding a file of somebody's voice for no purpose.
 *
 * An introduction is different in kind. The recording IS the artefact: a judge is meant to
 * hear it. So it is stored, it is transcribed as well so it can be read and searched, and
 * both are published to the dossier as `nominee_supplied` — the nominee's own claim, never
 * verified. The privacy notice says all of this in plain words, because "we do not keep
 * your voice" and "we keep this recording" cannot both be printed on the same page.
 *
 * Consent is the recording itself: nothing is captured until they press a button, they can
 * delete and re-record before submitting, and `intro_consent_at` stamps the moment they
 * accepted that a panel will hear it.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_nominee_submissions')) {
    echo "  = gates_nominee_submissions not present — run 2026_08_29_questionnaire first\n";
    echo "questionnaire intro OK\n";
    return;
}

foreach ([
    // They read the brief and said they were ready. The questions do not start before this.
    'intro_seen_at'      => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
    // Where the recording lives, and how long it runs. A duration is stored rather than
    // computed, because working it out means opening the file and a judge's ballot should
    // not read audio headers to draw a list.
    'intro_audio_path'   => $sqlite ? 'TEXT' : 'VARCHAR(300) NULL',
    'intro_seconds'      => $sqlite ? 'INTEGER' : 'SMALLINT UNSIGNED NULL',
    'intro_recorded_at'  => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
    // The transcript, so the introduction can be READ as well as heard — by a judge with no
    // headphones, by a panel chair skimming forty dossiers, and by the interview stage,
    // which builds its questions from text.
    'intro_transcript'   => $sqlite ? 'TEXT' : 'MEDIUMTEXT NULL',
    // 'ai' when a model transcribed it, 'none' when no key was configured and the recording
    // stands alone. Printed to the operator, never to a judge as though it were evidence.
    'intro_source'       => $sqlite ? 'TEXT' : 'VARCHAR(12) NULL',
    // The moment they accepted that a judging panel will hear this. The recording cannot
    // reach a dossier without it.
    'intro_consent_at'   => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_nominee_submissions', $col)) {
        DB::statement("ALTER TABLE gates_nominee_submissions ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_nominee_submissions.{$col} added\n";
    } else {
        echo "  = gates_nominee_submissions.{$col} already present\n";
    }
}

echo "questionnaire intro OK\n";
