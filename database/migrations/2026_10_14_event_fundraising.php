<?php
/**
 * An appeal can belong to an event.
 *
 * ── WHAT WAS ACTUALLY MISSING ────────────────────────────────────────────────
 *
 * Events sold tickets and that was all. There was no way to say "this evening is raising
 * money for the borehole fund", no goal, no thermometer, and nothing on the event page
 * asking. Fundraising existed — {@see \AfricaGates\Services\OrgCampaign} runs a proper
 * appeal at /donate/{org}/{campaign}, with a target, a live total summed from confirmed
 * gifts and a review workflow — but nothing connected the two, so an organiser running a
 * fundraising dinner had a ticket page and an appeal page that did not know about each
 * other.
 *
 * ── WHY A COLUMN ON THE CAMPAIGN AND NOT A NEW TABLE ─────────────────────────
 *
 * Because it is the same object. An event appeal has a title, a story, a target, an
 * opening and closing date, a review status and a live total — which is the campaign,
 * exactly. A second table would be a copy of all of it plus a second donate page, a second
 * progress calculation that would drift from the first, and a second thing for the finance
 * screen to learn about.
 *
 * So an appeal optionally names an event. Everything that already works — the workflow, the
 * payment path, the refunds, the org dashboard — works unchanged, and the event page gains
 * a place to show it.
 *
 * NULLABLE, and that is the normal case: most appeals are not about an event, and most
 * events are not raising anything.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_org_campaigns')) {
    echo "  = gates_org_campaigns not present yet\n";
    return;
}

if (!DB::schema()->hasColumn('gates_org_campaigns', 'event_id')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_org_campaigns ADD COLUMN event_id INTEGER NULL'
        : 'ALTER TABLE gates_org_campaigns ADD COLUMN event_id BIGINT UNSIGNED NULL');
    echo "  + gates_org_campaigns.event_id added\n";

    try {
        DB::statement($sqlite
            ? 'CREATE INDEX IF NOT EXISTS idx_campaign_event ON gates_org_campaigns (event_id, status)'
            : 'ALTER TABLE gates_org_campaigns ADD KEY idx_campaign_event (event_id, status)');
        echo "  + idx_campaign_event\n";
    } catch (\Throwable) {
        echo "  = idx_campaign_event already present\n";
    }
} else {
    echo "  = gates_org_campaigns.event_id already present\n";
}
