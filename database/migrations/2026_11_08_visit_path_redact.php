<?php
/**
 * Redact credentials out of landing paths already recorded.
 *
 * ── WHY A REPAIR AND NOT JUST A FIX ──────────────────────────────────────────
 *
 * `VisitTracker` dropped the query string from a landing path because this platform puts
 * live credentials in links. It kept the PATH verbatim, and that is the other half of the
 * same URL: `/honour/AGI-K7M2QX4T` is a guest of honour's pass reference — the string the
 * support desk treats as the key to their invitation — and `/claim/dispute/<32 hex>` is a
 * live dispute token.
 *
 * The writer is fixed. That protects every arrival from now on and does nothing at all
 * for the ones already in the table, which are browsable and exportable from the admin
 * console. This codebase has paid for that distinction once already: a corrected column
 * definition that only applied to databases with no rows in them left production on the
 * old one forever (see `2026_11_06_invite_audience_widen.php`). A corrected VALUE needs
 * the same treatment.
 *
 * ── THROUGH THE SAME RESOLVER ────────────────────────────────────────────────
 *
 * {@see \AfricaGates\Services\VisitTracker::safePath()} rather than a regex copied into
 * this file. Two implementations of "what counts as a credential" is how the repair and
 * the writer come to disagree about a route added between them.
 *
 * Idempotent: redacting an already-redacted path returns it unchanged, so a second run
 * writes nothing.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Services\VisitTracker;
use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_visits')) {
    echo "  · gates_visits absent — nothing to redact\n";
    echo "visit path redact OK\n";
    return;
}

$fixed = 0;
$seen  = 0;

try {
    // In pages. A busy site's retention window is thousands of rows, and this runs inside
    // a migration batch that has a wall-clock deadline over it.
    DB::table('gates_visits')->orderBy('id')->chunk(500, function ($rows) use (&$fixed, &$seen): void {
        foreach ($rows as $r) {
            $seen++;
            $was = (string) ($r->landing_path ?? '');
            if ($was === '') continue;

            $now = VisitTracker::safePath($was);
            if ($now === $was) continue;

            DB::table('gates_visits')->where('id', (int) $r->id)->update(['landing_path' => $now]);
            $fixed++;
        }
    });
} catch (\Throwable $e) {
    echo '  *** FAILED *** could not redact landing paths: ' . $e->getMessage() . "\n";
    echo "visit path redact OK\n";
    return;
}

echo $fixed > 0
    ? "  ~ redacted {$fixed} of {$seen} landing path(s)\n"
    : "  = no landing path held a credential ({$seen} checked)\n";

echo "visit path redact OK\n";
