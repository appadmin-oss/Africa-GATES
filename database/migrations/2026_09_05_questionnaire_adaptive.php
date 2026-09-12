<?php
/**
 * Questions that only appear when they apply, and a read on how strong an answer is.
 *
 * ── WHY BRANCHING ────────────────────────────────────────────────────────────
 *
 * Every nominee got the same eleven questions in the same order. That is fine for a form
 * and wrong for a conversation, and the cost lands unevenly:
 *
 * A nominee whose work stopped in 2019 is still asked how it is funded, in the present
 * tense. Somebody who says plainly that they have no independent referee is asked, three
 * questions later, who outside their organisation could confirm it. And somebody whose
 * answer about impact already contains "1,240 farmers across 8 states" is asked the
 * follow-up designed to extract a number they have just given.
 *
 * Each of those is a small insult and they accumulate into the same conclusion: this form
 * is not listening. A questionnaire that visibly does not read your answers is one you
 * stop giving real answers to.
 *
 * `show_if_slug` + `show_if` express the condition as data rather than as a branch in PHP,
 * so an operator designing a programme's questions can say "only ask this when they said
 * the work is still running" without a deploy.
 *
 * ── AND WHY A STRENGTH READ ──────────────────────────────────────────────────
 *
 * The readiness panel already tells a nominee, before they send, which answers a judge will
 * find thin. It does it at the END, which is the worst moment: they have finished, they are
 * ready to be done, and being sent back to four questions reads as rejection.
 *
 * `min_words` and `wants_number` let the same checks run PER QUESTION, while the answer is
 * being written, as help rather than as a verdict — "a number here would make this much
 * stronger" beside the box, not "this answer is weak" on the last screen. They are per
 * question because the right answer differs: a date wants no number of words, and an impact
 * claim without a figure is genuinely missing something.
 *
 * Nothing here scores anybody. {@see \AfricaGates\Services\QuestionnaireCoach} produces
 * prose for the nominee and never a mark for a judge.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_programme_questions')) {
    echo "  = gates_programme_questions not present — run 2026_08_29_questionnaire first\n";
    echo "questionnaire adaptive OK\n";
    return;
}

foreach ([
    // Which earlier answer this question depends on. NULL = always asked.
    'show_if_slug'  => $sqlite ? 'TEXT' : 'VARCHAR(60) NULL',
    // The condition, as a word rather than an expression: `answered`, `blank`,
    // `no_number` (ask only when the earlier answer has no figure in it), `yes`, `no`,
    // or `is:<value>` for a straight match. See QuestionnaireRules::applies().
    //
    // Deliberately NOT a general expression language. An operator typing a condition into
    // an admin form should not be able to write something that throws on a nominee's page,
    // and a vocabulary of six words covers every branch these questionnaires actually need.
    'show_if'       => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL',
    // Below this, the coach says the answer is probably too short to be useful. NULL = no
    // opinion, which is the right answer for a date or a link.
    'min_words'     => $sqlite ? 'INTEGER' : 'SMALLINT UNSIGNED NULL',
    // 1 = an answer with no figure in it is missing the thing that makes it weighable.
    'wants_number'  => $sqlite ? 'INTEGER' : 'TINYINT(1) NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_programme_questions', $col)) {
        DB::statement("ALTER TABLE gates_programme_questions ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_programme_questions.{$col} added\n";
    } else {
        echo "  = gates_programme_questions.{$col} already present\n";
    }
}

// The submission remembers which questions it was actually shown, so an operator reading it
// later can tell "not asked" from "asked and skipped" — two very different silences, and a
// dossier that conflated them would let a panel read an absence as a refusal.
if (DB::schema()->hasTable('gates_nominee_submissions')
    && !DB::schema()->hasColumn('gates_nominee_submissions', 'skipped_json')) {
    DB::statement('ALTER TABLE gates_nominee_submissions ADD COLUMN skipped_json '
        . ($sqlite ? 'TEXT' : 'MEDIUMTEXT NULL') . ' DEFAULT NULL');
    echo "  + gates_nominee_submissions.skipped_json added\n";
} else {
    echo "  = gates_nominee_submissions.skipped_json already present or table absent\n";
}

echo "questionnaire adaptive OK\n";
