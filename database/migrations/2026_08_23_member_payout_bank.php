<?php
/**
 * A member's own bank details, saved once instead of retyped every withdrawal.
 *
 * ── WHY THESE LIVE HERE *AND* ON THE PAYOUT ──────────────────────────────────
 *
 * `gates_referral_payouts` already snapshots the bank details of each request, and that
 * stays: it is what records where a given transfer actually went, and a single mutable
 * field would silently misdescribe every earlier payment the moment somebody changed banks.
 *
 * These columns are the DEFAULT — so a member entering a ten-digit account number for the
 * third time does not have to, and a typo in one withdrawal does not become the permanent
 * record of the others. The request copies them; it never reads them back.
 *
 * ── NO SUBACCOUNT FOR A MEMBER ───────────────────────────────────────────────
 *
 * Partner organisations get a Paystack subaccount, because donations are collected ON their
 * behalf and settle to them directly — see PartnerOrgsController. A referral balance is not
 * that: the money is already the platform's, and paying it out is an ordinary transfer to a
 * named account. So there is no subaccount here, nothing is resolved against Paystack at
 * save time, and these are three plain fields.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_users')) {
    foreach ([
        'payout_bank'           => $sqlite ? 'TEXT' : 'VARCHAR(120)',
        'payout_account_name'   => $sqlite ? 'TEXT' : 'VARCHAR(160)',
        'payout_account_number' => $sqlite ? 'TEXT' : 'VARCHAR(32)',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_users', $col)) {
            DB::statement("ALTER TABLE gates_users ADD COLUMN {$col} {$type} NULL");
            echo "  + gates_users.{$col}\n";
        }
    }
}
