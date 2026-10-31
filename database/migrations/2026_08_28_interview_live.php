<?php
/**
 * Live capture from inside the Meet call — the columns a browser extension writes to.
 *
 * ── WHY THIS EXISTS AT ALL ───────────────────────────────────────────────────
 *
 * The interview stage (2026_08_27) can only get a transcript two ways: somebody pastes
 * one, or Google is asked for the one IT made. The second needs transcription switched on
 * during the call, and that is a paid Workspace feature. So on a free Google account there
 * is exactly one route, and it is a person retyping a forty-minute conversation.
 *
 * Meet's LIVE CAPTIONS are available to everybody, free accounts included. They are
 * rendered into the page, which means a browser extension running in the interviewer's own
 * tab can read them as they appear and post them here. That is what these columns are for.
 *
 * ── WHAT IT STILL CANNOT DO ──────────────────────────────────────────────────
 *
 * Give the AI a voice in the room. An extension can read the call and write to our screen;
 * it cannot occupy a participant seat or put audio into the meeting — that needs Google's
 * Meet Media API and a persistent media process, which this host does not have. So the
 * model's follow-up question appears on the interviewer's screen and a human says it. The
 * ears and the brain are automated; the mouth is still borrowed.
 *
 * ── live_token: WHY NOT THE ADMIN SESSION ────────────────────────────────────
 *
 * The extension's request originates from a Meet tab, on Google's origin, to ours. An
 * admin session cookie set SameSite=Lax — which is what protects every other admin POST on
 * this platform — is deliberately not sent on that request, and loosening it to None to
 * make this work would weaken every form in the console to buy one feature.
 *
 * So live capture carries its own credential: a token scoped to ONE sitting, which does
 * exactly three things — read that sitting's question pack, append caption lines to it, and
 * close it. It cannot read another interview, cannot see a nominee's contact details, and
 * cannot reach anything else in the admin console. It is rotatable per sitting and expires
 * with the sitting's usefulness.
 *
 * ── live_json IS A BUFFER, NOT A TRANSCRIPT ──────────────────────────────────
 *
 * Caption lines arrive out of order, get revised mid-sentence as the recogniser changes its
 * mind, and repeat. They are accumulated here and only become a transcript when somebody
 * closes the sitting and the buffer is assembled — at which point it lands in
 * `gates_nominee_interviews` as `machine`, with the same consent gate as every other route.
 * Nothing here bypasses that: a sitting with no consent captures nothing at all, and the
 * extension is told why.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_interviews')) {
    echo "  = gates_interviews not present — run 2026_08_27_interviews first\n";
    echo "interview live OK\n";
    return;
}

foreach ([
    // 32 hex, one sitting. See the note above.
    'live_token'  => $sqlite ? 'TEXT' : 'CHAR(32) NULL',
    // The caption buffer: speaker, text, seen-at, as JSON.
    'live_json'   => $sqlite ? 'TEXT' : 'MEDIUMTEXT NULL',
    // When the last caption line arrived. The interview screen prints this, because
    // "the extension is running" and "the extension is being ignored by Google's
    // markup" look identical from the server otherwise.
    'live_at'     => $sqlite ? 'TEXT' : 'TIMESTAMP NULL DEFAULT NULL',
    // 'captions' when scraped live, '' when nothing has arrived. Kept separate from
    // the transcript's own `transcript_source` because this records HOW it reached us,
    // not who wrote it down.
    'live_source' => $sqlite ? 'TEXT' : 'VARCHAR(20) NULL',
    // Small bookkeeping for the live session — currently when a follow-up was last
    // asked for, per question.
    //
    // Here rather than in the settings table, which was the first attempt: a
    // per-question cooldown key would have written rows like `fu:12:crit-impact` into
    // gates_admin_settings, i.e. into the screen an operator opens to configure the
    // platform. Bookkeeping belongs on the row it books.
    'live_meta'   => $sqlite ? 'TEXT' : 'TEXT NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_interviews', $col)) {
        DB::statement("ALTER TABLE gates_interviews ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_interviews.{$col} added\n";
    } else {
        echo "  = gates_interviews.{$col} already present\n";
    }
}

// Looked up on every caption batch, so it is indexed. NOT unique: a rotated token leaves
// the old value on no row at all, and uniqueness would only matter if two sittings could
// share one, which minting from random_bytes already prevents.
echo \AfricaGates\Support\SchemaIndex::ensure('gates_interviews', 'idx_interview_live', ['live_token']) . "\n";

echo "interview live OK\n";
