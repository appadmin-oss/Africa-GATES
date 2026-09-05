<?php
/**
 * Voice on the questionnaire: two counters, so the spend is visible.
 *
 * ── WHY COUNT AT ALL ─────────────────────────────────────────────────────────
 *
 * ElevenLabs bills per character spoken and per minute transcribed, and the person paying
 * for it is an awards operator on a small plan, not a company with a cloud budget. A
 * feature that quietly consumes somebody's quota and reports nothing is the kind of thing
 * they discover when it stops working — mid-cycle, with nominees on the page.
 *
 * So each submission carries its own two numbers:
 *
 *   • voice_chars — characters actually SENT to text-to-speech. Cache hits add nothing,
 *     which is the point: the number answers "what did this cost", not "how many times did
 *     somebody press play".
 *   • voice_calls — spoken answers transcribed. This one has no cache and cannot have one
 *     (every recording is new audio), so it is also the cap: past
 *     VoiceService-enforced limits the microphone stops and the typing box does not.
 *
 * Together they are what the operator screen shows, and what a support conversation about
 * "why has voice stopped" can be answered from instead of guessed at.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_nominee_submissions')) {
    echo "  = gates_nominee_submissions not present — run 2026_08_29_questionnaire first\n";
    echo "questionnaire voice OK\n";
    return;
}

foreach ([
    // Characters sent to text-to-speech. Cache hits are free and do not count.
    'voice_chars' => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
    // Spoken answers transcribed. Also the cap — see QuestionnaireVoice::MAX_CALLS.
    'voice_calls' => $sqlite ? 'INTEGER' : 'SMALLINT UNSIGNED NULL',
    // Whether this nominee actually used voice, so the operator reading a submission knows
    // the answers were spoken and then confirmed rather than typed from the start. It is
    // provenance, in a record where provenance is the column that matters most.
    'voice_used'  => $sqlite ? 'INTEGER' : 'TINYINT(1) NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_nominee_submissions', $col)) {
        DB::statement("ALTER TABLE gates_nominee_submissions ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_nominee_submissions.{$col} added\n";
    } else {
        echo "  = gates_nominee_submissions.{$col} already present\n";
    }
}

echo "questionnaire voice OK\n";
