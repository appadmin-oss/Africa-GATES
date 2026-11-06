<?php
/**
 * The conversation, when a nominee answers by talking rather than by filling in a form.
 *
 * ── WHY A CONVERSATION AT ALL ────────────────────────────────────────────────
 *
 * The questionnaire that shipped in 2026_08_29 renders as a page nearly five thousand pixels
 * tall: eleven questions, each with help text, then a list of works, then a submit panel.
 * That is a reasonable form and an intimidating one. The person on the other end is a teacher
 * with an hour after school, on a phone, who has never used this site — and a wall of empty
 * boxes asking about "impact" and "originality" is exactly the shape that gets abandoned.
 *
 * One question at a time, in a conversation, is a different experience of the same work. And
 * it can do the thing a form cannot: notice that an answer has no number in it and ask.
 *
 * ── WHAT IS STORED, AND WHOSE WORDS THEY ARE ─────────────────────────────────
 *
 * `chat_json` holds the turns. `answers_json` — the same column the form writes, read by the
 * same publisher — holds the answers, and it holds **the nominee's own words, verbatim**.
 *
 * That is the load-bearing decision in this whole feature. A model that rewrote a nominee's
 * halting sentence into confident prose would be putting words into a record that a judging
 * panel reads as "supplied by the nominee", in a dossier where provenance is the most
 * important column. The model chooses WHICH question was answered and whether to ask one
 * follow-up. It does not author the answer.
 *
 * `chat_mode` records how a submission was filled in, because the two are not
 * interchangeable evidence: an answer typed into a form is unprompted, and an answer given to
 * a follow-up was asked for. A judge is never shown this — it is for the operator reading a
 * submission, and for anybody asking later how a dossier was built.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_nominee_submissions')) {
    echo "  = gates_nominee_submissions not present — run 2026_08_29_questionnaire first\n";
    echo "questionnaire chat OK\n";
    return;
}

foreach ([
    // The turns: who said it, what, and when.
    'chat_json'   => $sqlite ? 'TEXT' : 'MEDIUMTEXT NULL',
    // 'form', 'chat', or 'both'. How this submission was actually filled in.
    'chat_mode'   => $sqlite ? 'TEXT' : 'VARCHAR(12) NULL',
    // Which question the conversation is on, so a nominee who closes the tab mid-answer
    // comes back to the same question rather than to the beginning.
    'chat_slug'   => $sqlite ? 'TEXT' : 'VARCHAR(60) NULL',
    // How many follow-ups have been spent on the current question. One is help; three is
    // an interrogation, and the cap is what keeps it the former.
    'chat_probes' => $sqlite ? 'INTEGER' : 'TINYINT UNSIGNED NULL',
    // 'rules' when the conversation was scripted, 'ai' when a model was answering. Printed
    // to the operator, because a panel is entitled to know which one built the record.
    'chat_source' => $sqlite ? 'TEXT' : 'VARCHAR(12) NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_nominee_submissions', $col)) {
        DB::statement("ALTER TABLE gates_nominee_submissions ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_nominee_submissions.{$col} added\n";
    } else {
        echo "  = gates_nominee_submissions.{$col} already present\n";
    }
}

echo "questionnaire chat OK\n";
