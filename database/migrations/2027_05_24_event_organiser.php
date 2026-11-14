<?php
/**
 * Whose event it is: Africa GATES (NULL) or a partner organisation.
 *
 * ── THE FAULT ────────────────────────────────────────────────────────────────
 *
 * `gates_site_events` had no organiser at all, and every ticket settles to the one `events`
 * destination (PaymentDestination). That is right while Africa GATES is the only organiser.
 * Since leads and owners became member accounts precisely so outside organisations can run
 * events, staff could already set one up for a partner and sell its tickets — and the money
 * would land in Africa GATES's account: the platform holding a client's funds, which the plan
 * rules out until licensing advice (D29). (AUDIT-AND-PLAN EV-18, D40.)
 *
 * ── THE FIX ──────────────────────────────────────────────────────────────────
 *
 * An organiser on every event (NULL = Africa GATES, so every existing event is unchanged), and
 * EventTicketService::paidSalesAllowed(): a paid ticket for a partner's event is refused until
 * per-organiser settlement exists (stage E2). Free registration is unaffected.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_site_events') && !DB::schema()->hasColumn('gates_site_events', 'organiser_org_id')) {
    DB::statement('ALTER TABLE gates_site_events ADD COLUMN organiser_org_id ' . ($sqlite ? 'INTEGER' : 'BIGINT UNSIGNED') . ' NULL DEFAULT NULL');
    echo "  + gates_site_events.organiser_org_id added\n";
} else {
    echo "  = gates_site_events.organiser_org_id already present\n";
}
echo SchemaIndex::ensure('gates_site_events', 'idx_site_events_organiser', ['organiser_org_id']) . "\n";

echo "event organiser OK\n";
