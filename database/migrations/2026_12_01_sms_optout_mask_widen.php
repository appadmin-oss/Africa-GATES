<?php
/**
 * Let a Nigerian number opt out of SMS. It never could.
 *
 * ── THE BUG ──────────────────────────────────────────────────────────────────
 *
 * `gates_sms_optout.phone_masked` shipped as `VARCHAR(12)`. The value written into it
 * comes from {@see \AfricaGates\Support\Phone::mask()}, which builds
 *
 *     '+' . first-3-digits . up-to-8-stars . last-3-digits
 *
 * — so its longest output is FIFTEEN characters, and its output for any E.164 number of
 * thirteen digits or more is at least fourteen. A Nigerian mobile in E.164 is exactly
 * thirteen digits: `+2348012345678` masks to `+234*******678`, which is fourteen.
 *
 * The connection runs in strict mode, so MySQL refuses the INSERT outright.
 * {@see \AfricaGates\Services\SmsOptOut::record()} catches the exception and returns
 * false, the webhook still answers Twilio with 204, and the person is told nothing.
 *
 * So on production, on the platform's home market: **replying STOP did nothing.** The
 * suppression was never recorded, the next campaign texted them again, and every screen
 * and every log agreed that the reply had been handled. This is the failure mode the
 * whole MySQL/SQLite divergence keeps producing — SQLite declares the column TEXT, takes
 * the fourteen characters happily, and the suite has always been green.
 *
 * There is a legal dimension to this one as well as a rude one.
 *
 * ── THE REPAIR ───────────────────────────────────────────────────────────────
 *
 * VARCHAR(12) → VARCHAR(16): fifteen is the longest mask this function can produce, and
 * the sixteenth is there so a country code that grows by a digit is not a second one of
 * these migrations.
 *
 * No data is rewritten. Every row that made it in was short enough to fit, so widening
 * the column cannot change a stored value — the missing rows are missing, and nothing
 * here can invent them. Anybody who sent STOP before this landed has to send it again,
 * which is stated plainly rather than papered over: there is no record of them to repair.
 *
 * SQLite is untouched. Its column is TEXT and always accepted the value; rebuilding the
 * table to change nothing would risk the rows for no gain.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();

if (!$schema->hasTable('gates_sms_optout')) {
    echo "gates_sms_optout absent — skipped\n";
    echo "sms optout mask widen OK\n";
    return;
}

if (DB::connection()->getDriverName() === 'sqlite') {
    // TEXT already holds it. See the note above on why this is not "fixed" here too.
    echo "sqlite: phone_masked is TEXT — nothing to widen\n";
    echo "sms optout mask widen OK\n";
    return;
}

$width = 0;
try {
    $row = DB::selectOne(
        'SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['gates_sms_optout', 'phone_masked']
    );
    $width = (int) ($row->n ?? 0);
} catch (\Throwable $e) {
    echo "  ! could not read phone_masked width: " . $e->getMessage() . "\n";
}

if ($width >= 16) {
    echo "  = phone_masked already VARCHAR({$width})\n";
} else {
    try {
        DB::statement('ALTER TABLE gates_sms_optout MODIFY phone_masked VARCHAR(16) NULL');
        echo "  + phone_masked widened to VARCHAR(16) — STOP now works for 13-digit numbers\n";
    } catch (\Throwable $e) {
        echo "  ! phone_masked widen failed: " . $e->getMessage() . "\n";
    }
}

echo "sms optout mask widen OK\n";
