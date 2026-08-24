<?php
/**
 * An organisation designs its own donation page.
 *
 * ── WHAT WAS ACTUALLY MISSING ────────────────────────────────────────────────
 *
 * A partner's appeal page carried their NAME and nothing else that was theirs. Same
 * typeface, same colour, same three paragraphs of Africa GATES copy around the form, and no
 * logo anywhere on it. A supporter who followed a link from that organisation's own
 * WhatsApp group arrived somewhere that looked like a platform, not like the people they
 * were being asked to trust with money — which is the single thing a donation page has to
 * get right.
 *
 * We provide the donation service, the settlement, the receipting and the refunds. What the
 * page LOOKS like belongs to whoever is asking for the money.
 *
 * ── WHY ONE JSON COLUMN AND NOT EIGHT ────────────────────────────────────────
 *
 * Everything in here is read exactly once, per page, for one organisation already loaded by
 * id or slug. Nothing filters or sorts on a logo path or an accent colour, so eight columns
 * would buy eight migrations of index-free storage and a wider row on every query that
 * touches this table for a completely different reason.
 *
 * {@see \AfricaGates\Services\OrgBrand} is the only reader and the only writer, and it
 * validates on the way in — an accent that fails contrast against white is refused rather
 * than stored, because a donation page nobody can read is not a design choice somebody made
 * for themselves, it is a page that stops working.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_partner_orgs')) {
    echo "  = gates_partner_orgs not present yet\n";
    return;
}

if (!DB::schema()->hasColumn('gates_partner_orgs', 'brand_json')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_partner_orgs ADD COLUMN brand_json TEXT NULL'
        : 'ALTER TABLE gates_partner_orgs ADD COLUMN brand_json TEXT NULL');
    echo "  + gates_partner_orgs.brand_json added\n";
} else {
    echo "  = gates_partner_orgs.brand_json already present\n";
}
