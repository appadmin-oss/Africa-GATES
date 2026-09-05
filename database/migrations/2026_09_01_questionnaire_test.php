<?php
/**
 * A questionnaire an administrator can answer themselves, to see what a nominee sees.
 *
 * ── WHY THIS NEEDED A COLUMN AND NOT A CONVENTION ────────────────────────────
 *
 * Before this, the only way to find out what the questionnaire actually feels like was to
 * open one against a real nominee — which creates a live token, counts in the summary,
 * appears in the queue as somebody who has been asked, and on submit writes evidence rows
 * into that person's judging dossier. In other words: the only way to rehearse was to
 * contaminate the record you were rehearsing for. So nobody rehearses, the first person to
 * discover a confusing question is a nominee, and the operator finds out from a support
 * email.
 *
 * A naming convention ("call the test nominee ZZ Test") would not have been enough, because
 * the thing that must be prevented is not a label but a WRITE: {@see QuestionnaireService::publishEvidence()}
 * must refuse. A flag a guard can read is the only version of this that cannot be got wrong
 * by somebody in a hurry.
 *
 * ── HOW A TEST ROW SITS IN A TABLE BUILT FOR REAL ONES ───────────────────────
 *
 * `nominee_id = 0` and `cycle_id = NULL`.
 *
 * Zero because the column is NOT NULL and auto-increment ids start at one, so it can never
 * collide with a real person: every reader that joins on it simply finds nothing, which is
 * exactly the behaviour wanted — a test submission cannot attach itself to anybody.
 *
 * And cycle_id NULL because the table carries UNIQUE (nominee_id, cycle_id), and NULLs
 * compare as distinct in a unique index on both MySQL and SQLite — so an operator can keep
 * several tests side by side (one per programme, say) without the constraint that protects
 * real submissions having to be weakened for them.
 *
 * `programme_id` IS set, because the questions are per programme and testing the wrong
 * programme's questions would defeat the point.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_nominee_submissions')) {
    echo "  = gates_nominee_submissions not present — run 2026_08_29_questionnaire first\n";
    echo "questionnaire test OK\n";
    return;
}

foreach ([
    // 1 = a rehearsal. Read by publishEvidence() and invite(), which both refuse; and by
    // summary(), which counts these separately so "9 submitted" never quietly includes one.
    'is_test'    => $sqlite ? 'INTEGER' : 'TINYINT(1) NULL',
    // The made-up name the conversation greets, so a rehearsal reads like the real thing
    // rather than "Hello there" — the greeting is one of the things worth testing.
    'test_label' => $sqlite ? 'TEXT' : 'VARCHAR(120) NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_nominee_submissions', $col)) {
        DB::statement("ALTER TABLE gates_nominee_submissions ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_nominee_submissions.{$col} added\n";
    } else {
        echo "  = gates_nominee_submissions.{$col} already present\n";
    }
}

echo "questionnaire test OK\n";
