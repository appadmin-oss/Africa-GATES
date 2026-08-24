<?php
/**
 * External guests on an interview sitting.
 *
 * ── WHAT COULD NOT BE DONE ───────────────────────────────────────────────────
 *
 * The Google event's attendee list was built from ONE source: `array_column($d['panel'],
 * 'email')` — the judges assigned to the sitting, who are rows in `gates_judges`. So the
 * only way to put somebody in the meeting was to appoint them to the judging panel, which
 * is an integrity decision with a published consequence (they appear on "Meet the Judges"
 * and their scores count) and is a wildly disproportionate way to let an interpreter, a
 * note-taker, a programme officer or the nominee's own support person into a call.
 *
 * There was no field anywhere to type an email address into.
 *
 * ── AND ON "CO-HOST" SPECIFICALLY ────────────────────────────────────────────
 *
 * Worth being exact, because it is the word the request arrives in. Google Calendar's
 * Events resource has NO co-host field — co-host is a Meet concept, assignable through the
 * Meet REST API's `spaces.members` (role COHOST) or by the host inside the call, and the
 * former needs Google Workspace plus a scope this integration does not hold.
 *
 * What IS reachable from an event, and what people actually want when they ask, is two
 * things: the guest is INVITED (so Meet admits them straight in rather than making them
 * knock, and they get the invitation and the reminders), and `guestsCanModify` lets them
 * change the event itself. That is what this stores and what the Apps Script now sets.
 * Promoting somebody to a true Meet co-host is still one click by the host inside the call,
 * and the screen says so rather than implying otherwise.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_interviews')) {
    echo "  · gates_interviews does not exist yet — skipping guests\n";
    return;
}

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasColumn('gates_interviews', 'guests_json')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_interviews ADD COLUMN guests_json TEXT NULL'
        : 'ALTER TABLE gates_interviews ADD COLUMN guests_json TEXT NULL');
    echo "  + gates_interviews.guests_json\n";
}

// Whether those guests may edit the calendar event. Off by default: an invitation is the
// part everybody needs, and edit rights are the part only some people should have — and a
// default that hands them out is the wrong way round for a column nobody has set yet.
if (!DB::schema()->hasColumn('gates_interviews', 'guests_can_edit')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_interviews ADD COLUMN guests_can_edit INTEGER NOT NULL DEFAULT 0'
        : 'ALTER TABLE gates_interviews ADD COLUMN guests_can_edit TINYINT(1) NOT NULL DEFAULT 0');
    echo "  + gates_interviews.guests_can_edit\n";
}
