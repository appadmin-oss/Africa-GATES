<?php
/**
 * THE BOT SEAT: columns for a participant that is not a person.
 *
 * ── WHAT THIS UNBLOCKS ───────────────────────────────────────────────────────
 *
 * {@see \AfricaGates\Services\InterviewLive} says it plainly, and has been right until
 * now: "the AI has no voice in the room. Occupying a participant seat and putting audio
 * into a Meet call needs a persistent media process; an extension has neither, and this
 * host has neither."
 *
 * The second half of that is still true — this is PHP-FPM on cPanel and it will never
 * hold a WebRTC session. What changes is that the media process no longer has to live
 * here. Attendee (github.com/attendee-labs/attendee) is an open-source bot that joins a
 * Meet, Zoom or Teams call, records it, transcribes it, and — the part that matters —
 * exposes an HTTP endpoint that plays audio INTO the call. It runs on its own host and
 * this platform talks to it the same way it talks to Paystack: an API key and a URL.
 *
 * So the columns below are the sitting's half of that conversation. Which bot was sent,
 * where it got to, and what it is allowed to say when it arrives.
 *
 * ── WHY voice_mode IS THREE VALUES AND NOT A BOOLEAN ─────────────────────────
 *
 * A switch would have to pick a meaning for "on", and the two candidates are not the
 * same feature:
 *
 *   'off'      The bot records and transcribes; it never speaks. This replaces the
 *              caption-scraping extension and nothing else. It is the default, and it
 *              is what an operator gets if they do not think about this field at all.
 *
 *   'assisted' The panel's console shows the next question; a human clicks, and the bot
 *              reads it aloud. Every utterance has a person behind it. The AI chooses
 *              what to suggest; it does not choose to speak.
 *
 *   'auto'     The bot asks and follows up on its own, driven by the transcript arriving
 *              from the call.
 *
 * Those carry genuinely different risk. An award interview feeds 55% of a nominee's CPI
 * through expert judgement, and 'auto' means a model conducted it. That may be the right
 * call for a first-round screening sitting and the wrong one for a final panel, which is
 * exactly why it is per-sitting and not a platform setting. `interview_voice_max` in
 * gates_settings caps the whole platform underneath it, so a nervous operator can allow
 * 'assisted' everywhere and 'auto' nowhere without editing rows.
 *
 * ── CONSENT IS NOT RE-INVENTED HERE ──────────────────────────────────────────
 *
 * `consent_at` already exists and {@see \AfricaGates\Services\InterviewLive::mayCapture()}
 * already refuses to keep a word without it. The bot obeys the same gate — it is not
 * dispatched to a sitting that has no consent on file, and if consent is absent when it
 * has somehow already joined, nothing it hears is stored. No new consent column, because
 * a second one would eventually disagree with the first.
 *
 * What IS new: a bot in the room is a materially different thing to consent to than a
 * human taking notes, so `bot_disclosed_at` records that the nominee was told, in the
 * invitation, that a recording bot would be present. It is stamped by the invite, not by
 * an admin ticking a box.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$pdo    = DB::connection()->getPdo();

/** Add a column only if it is missing, so a re-run is a no-op on both engines. */
$add = static function (string $table, string $column, string $sqliteType, string $mysqlType)
    use ($sqlite, $pdo): void {
    try {
        if (DB::schema()->hasColumn($table, $column)) return;
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} " . ($sqlite ? $sqliteType : $mysqlType));
    } catch (\Throwable $e) {
        echo "*** could not add {$table}.{$column}: {$e->getMessage()}\n";
    }
};

// ── which bot, and where it got to ───────────────────────────────────────────
//
// `bot_provider` is stored rather than read from the environment at display time,
// because a sitting held last month was held with whatever was configured THEN, and an
// operator debugging a missing transcript needs to know that, not today's setting.
$add('gates_interviews', 'bot_provider', 'TEXT', "VARCHAR(20) DEFAULT NULL");
$add('gates_interviews', 'bot_id',       'TEXT', 'VARCHAR(120) DEFAULT NULL');

// requested | joining | in_call | done | error | removed. Deliberately the same five
// words {@see \AfricaGates\Services\AttendeeBot::botStatus()} normalises to, so a second
// provider can be added later without a screen learning a sixth vocabulary.
$add('gates_interviews', 'bot_state', 'TEXT', "VARCHAR(20) DEFAULT NULL");

// The provider's own words when it refused or failed. Shown to the operator verbatim:
// "meeting not found" and "bot was removed by a host" need different responses, and a
// generic "could not join" tells them to try the same thing again.
$add('gates_interviews', 'bot_error', 'TEXT', 'VARCHAR(500) DEFAULT NULL');

$add('gates_interviews', 'bot_requested_at', 'TEXT', 'TIMESTAMP NULL DEFAULT NULL');
$add('gates_interviews', 'bot_joined_at',    'TEXT', 'TIMESTAMP NULL DEFAULT NULL');
$add('gates_interviews', 'bot_left_at',      'TEXT', 'TIMESTAMP NULL DEFAULT NULL');

// Where the recording landed, once the provider has finished post-processing. A URL and
// not a file: these are hours of audio and this host has a disk quota measured in
// gigabytes. It expires, which is why `bot_recording_at` sits beside it — a link fetched
// six months later will 404 and the operator should be told why rather than guessing.
$add('gates_interviews', 'bot_recording_url', 'TEXT', 'VARCHAR(1000) DEFAULT NULL');
$add('gates_interviews', 'bot_recording_at',  'TEXT', 'TIMESTAMP NULL DEFAULT NULL');

// How far through the provider's utterance list the ingest has read. Attendee returns the
// whole transcript on every poll; without a cursor each poll would re-append the entire
// conversation and the dedup in InterviewLive::append() would be doing work it was not
// written for.
$add('gates_interviews', 'bot_cursor', 'INTEGER NOT NULL DEFAULT 0', 'INT NOT NULL DEFAULT 0');

// ── what it may say ──────────────────────────────────────────────────────────
//
// Defaulting to 'off' matters more than it looks. Every sitting that already exists gets
// this value, so switching the feature on cannot retroactively give a voice to an
// interview somebody scheduled last week under different expectations.
$add('gates_interviews', 'voice_mode', "TEXT NOT NULL DEFAULT 'off'", "VARCHAR(10) NOT NULL DEFAULT 'off'");

// Stamped when the invitation that named the bot went out. Not an admin checkbox: the
// only honest evidence that a nominee was told is that the sentence was in the email we
// sent them.
$add('gates_interviews', 'bot_disclosed_at', 'TEXT', 'TIMESTAMP NULL DEFAULT NULL');

// The sweep finds sittings whose bot is due or in flight. Without this it is a table scan
// every minute for the life of the platform.
try {
    if (!$sqlite) {
        $pdo->exec('CREATE INDEX idx_gates_interviews_bot ON gates_interviews (bot_state, scheduled_at)');
    } else {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gates_interviews_bot ON gates_interviews (bot_state, scheduled_at)');
    }
} catch (\Throwable) {
    // MySQL has no CREATE INDEX IF NOT EXISTS; a duplicate-key error on re-run is the
    // expected outcome and means the index is already there.
}

echo "  interview bot: ready\n";
