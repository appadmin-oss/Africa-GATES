<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * A DONOR'S VOLUNTARY GIFT TO AFRICA GATES, RECORDED APART FROM THE FEE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A SEPARATE COLUMN WHEN `platform_fee_naira` ALREADY EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * They are two different things and only one of them is the donor's decision.
 *
 *   · `platform_fee_naira` is the agreed cut — `platform_fee_bps` applied to the gift. The
 *     organisation knows the rate before they raise a naira, and it comes OUT of the gift.
 *   · `platform_tip_naira` is money a donor chose to ADD, on top of what they gave the
 *     organisation, because they wanted to fund the platform too. It never reduces what
 *     the organisation receives.
 *
 * Folding the second into the first would make a partner's dashboard read "we paid Africa
 * GATES ₦4,500 in fees" when ₦4,000 of it was a gift from a donor that cost them nothing —
 * which is a false statement about somebody else's money on the screen they check it on.
 *
 * ── AND WHY THE FEE COLUMN STILL CARRIES BOTH ────────────────────────────────
 *
 * `amount_naira - platform_fee_naira` is what an organisation is owed, and it is read in
 * five places. Excluding the tip from the fee column would make every one of them hand the
 * organisation the donor's gift to us. So the fee column stays "everything in this payment
 * that is not the organisation's", the tip column says how much of that was voluntary, and
 * no existing reader changes meaning. The dashboard can then state the two separately
 * without any of them being wrong in the meantime.
 *
 * Nullable with no default, because a row written before this existed had no tip and
 * `NULL` says that more honestly than a zero somebody might read as "they declined".
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_donations')) {
    echo "  = gates_donations absent — nothing to add\n";
    return;
}

if (DB::schema()->hasColumn('gates_donations', 'platform_tip_naira')) {
    echo "  = gates_donations.platform_tip_naira already present\n";
    return;
}

DB::statement($sqlite
    ? 'ALTER TABLE gates_donations ADD COLUMN platform_tip_naira INTEGER NULL'
    : 'ALTER TABLE gates_donations ADD COLUMN platform_tip_naira INT UNSIGNED NULL');

echo "  + gates_donations.platform_tip_naira added\n";
