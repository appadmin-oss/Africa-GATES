<?php
/**
 * A colour on each ticket tier — stored as a SLOT, never as a hex.
 *
 * ── WHY THE COLUMN IS NOT `VARCHAR(7)` HOLDING `#AABBCC` ────────────────────
 *
 * The obvious column is a hex, written by a colour picker on the tier row. It is the wrong
 * one, and the reason only appears months later: the organiser changes the event's accent
 * colour, and the six tier colours chosen against the OLD accent stay exactly where they
 * were. The event is now teal and the tiers are still burgundy, and nothing on the platform
 * knows that is wrong.
 *
 * So this column holds one of six slot names — `accent`, `deep`, `soft`, `warm`, `cool`,
 * `bold` — and the hex is computed from `gates_site_events.ticket_accent` every time it is
 * read. See AfricaGates\Services\EventTierPalette. Change the event's accent and the whole
 * ladder moves with it, permanently: "colours that match the event" stops being a rule
 * somebody has to remember and becomes a property of the storage.
 *
 * The second reason is narrower and just as real. A hex ends up inside a `style` attribute,
 * so a hex column is a string that gets executed rather than displayed and has to be
 * validated on write AND on read, forever, on every path. A slot column can only ever
 * produce output the palette computed.
 *
 * ── NULL IS THE DEFAULT AND A REAL ANSWER ───────────────────────────────────
 *
 * "No colour" is not a missing value, it is the ordinary case. A ladder where every row is
 * coloured because the field had to be filled in is noisier than one where the organiser
 * marked the two tiers that matter. Every tier that exists today keeps NULL and every event
 * page looks exactly as it did before this ran.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_event_tiers')) {
    echo "  = gates_event_tiers not present — skipped\n";
} else {
    if (!DB::schema()->hasColumn('gates_event_tiers', 'colour')) {
        // 12 rather than 7: the value is a WORD, and the longest today is six characters.
        // A hex-width column would be an invitation to start storing hexes in it.
        DB::statement('ALTER TABLE gates_event_tiers ADD COLUMN colour '
            . ($sqlite ? 'TEXT' : 'VARCHAR(12) NULL') . ' DEFAULT NULL');
        echo "  + gates_event_tiers.colour added\n";
    } else {
        echo "  = gates_event_tiers.colour already present\n";
    }
}

echo "tier colour OK\n";
